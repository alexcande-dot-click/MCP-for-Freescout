<?php

namespace Modules\McpForFreescout\Support;

use Modules\McpForFreescout\Mcp\ToolError;

/**
 * Downloads files with cURL, guarded against server-side request forgery.
 *
 * - Only http and https links without credentials are accepted.
 * - The host and every address it resolves to are checked with the core SSRF
 *   check (\Helper::sanitizeRemoteUrl()); private, loopback and metadata
 *   addresses are refused unless the administrator whitelisted them.
 * - The connection is pinned to the checked addresses, so a second DNS answer
 *   cannot point the download elsewhere.
 * - Redirects are never followed: the agent is asked for the final link.
 * - The download stops as soon as it exceeds the size limit.
 */
class CurlRemoteFetcher implements RemoteFetcher
{
    /** Time limit of a download, in seconds. */
    const TIMEOUT = 30;

    public function fetch(string $url, int $maxBytes): array
    {
        [$target, $resolve, $pinnedIp] = $this->target($url);

        $bytes = '';
        $tooLarge = false;
        $ch = curl_init();
        \Helper::setCurlDefaultOptions($ch);
        curl_setopt_array($ch, [
            CURLOPT_URL            => $target,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPGET        => true,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$bytes, &$tooLarge, $maxBytes) {
                if (strlen($bytes) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    // Any length other than the chunk's aborts the transfer.
                    return 0;
                }
                $bytes .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($resolve) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        // A proxy resolves the host itself, so the pin cannot apply with one.
        if ($pinnedIp !== null && ($ok !== false || $status > 0) && !config('app.proxy')) {
            $this->assertConnectedTo($pinnedIp, $primaryIp);
        }
        if ($status > 0) {
            $this->assertStatus($status);
        }
        if ($tooLarge) {
            throw new ToolError('The file is larger than '.$maxBytes.' bytes.');
        }
        if ($ok === false) {
            throw new ToolError('The link could not be downloaded: '.$error);
        }

        return [
            'bytes'     => $bytes,
            'mime_type' => is_string($contentType) && $contentType !== '' ? $contentType : null,
        ];
    }

    /**
     * Refuse every answer but a success. A redirect is not followed, because its
     * target has not been checked.
     *
     * @throws ToolError
     */
    protected function assertStatus(int $status): void
    {
        if ($status >= 300 && $status < 400) {
            throw new ToolError('The link redirects; give the final URL.');
        }
        if ($status < 200 || $status >= 300) {
            throw new ToolError('The link answered with HTTP status '.$status.'.');
        }
    }

    /**
     * Check a link and rebuild it from its parsed parts, so cURL connects to the
     * very host that was checked.
     *
     * @return array [string $url, string[] $resolve, ?string $pinnedIp] The link to download,
     *               the CURLOPT_RESOLVE entries that pin its host to the checked addresses and
     *               the address the connection must go to.
     *
     * @throws ToolError When the link is not allowed.
     */
    protected function target(string $url): array
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ToolError('Only http and https links can be downloaded.');
        }

        // Whitespace, control characters, backslashes and credentials are parsed
        // differently by different URL parsers; such links are refused outright.
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new ToolError('The link is not a valid http or https URL.');
        }

        // "example.com." and "example.com" are the same host, but cURL and the checks below
        // could treat the names differently; only the form without the dot is used.
        $host = rtrim(strtolower($parts['host']), '.');
        if ($host === '') {
            throw new ToolError('The link is not a valid http or https URL.');
        }
        if (preg_match('/[^\x21-\x7e]/', $host)) {
            // An international name is checked and pinned in the ASCII form cURL uses.
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if (!is_string($ascii) || $ascii === '') {
                throw new ToolError('The link is not a valid http or https URL.');
            }
            $host = strtolower($ascii);
        }
        if (self::isNumericHost($host) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Shorthand, octal, hexadecimal or integer forms of an IPv4 address.
            throw new ToolError('The link is not a valid http or https URL.');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $target = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$port : '')
            .($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');

        try {
            $safe = \Helper::sanitizeRemoteUrl($target, true, false);
        } catch (\Exception $e) {
            $safe = '';
        }
        if ($safe === '' || $safe === null) {
            throw new ToolError('The link points to a local or private address and cannot be downloaded.');
        }

        $bareHost = trim($host, '[]');
        if (filter_var($bareHost, FILTER_VALIDATE_IP)) {
            // An address needs no resolution.
            return [$target, [], $bareHost];
        }

        [$entry, $ip] = $this->pin($bareHost, $port, (array) (\Helper::$last_request['ips'][$bareHost] ?? []));

        return [$target, [$entry], $ip];
    }

    /**
     * Choose the address a host is pinned to among the addresses the core check resolved
     * and approved, an IPv4 address first. One address per entry keeps older cURL versions
     * working.
     *
     * @param string[] $ips
     *
     * @return array [string $entry, string $ip] The CURLOPT_RESOLVE entry and the bare address.
     *
     * @throws ToolError When no valid address is known for the host.
     */
    protected function pin(string $host, int $port, array $ips): array
    {
        $ips = array_values(array_filter($ips, function ($ip) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP);
        }));
        if (!$ips) {
            throw new ToolError('The host of the link could not be resolved.');
        }
        $ipv4 = array_values(array_filter($ips, function ($ip) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        }));
        $ip = $ipv4 ? $ipv4[0] : $ips[0];

        return [$host.':'.$port.':'.($ipv4 ? $ip : '['.$ip.']'), $ip];
    }

    /**
     * Refuse a download whose connection did not go to the pinned address, which happens
     * when cURL does not apply CURLOPT_RESOLVE to the host.
     *
     * @param string|null $pinned    The address the host was pinned to; null when there is no pin.
     * @param mixed       $primaryIp CURLINFO_PRIMARY_IP of the transfer.
     *
     * @throws ToolError
     */
    protected function assertConnectedTo(?string $pinned, $primaryIp): void
    {
        if ($pinned === null) {
            return;
        }

        $expected = @inet_pton($pinned);
        $actual = is_string($primaryIp) && $primaryIp !== '' ? @inet_pton($primaryIp) : false;
        if ($expected === false || $actual === false || $expected !== $actual) {
            throw new ToolError('The connection did not go to the address that was checked; nothing was downloaded.');
        }
    }

    /**
     * Whether every label of a host is a number, as in 127.1, 0x7f.1 or 2130706433.
     */
    private static function isNumericHost(string $host): bool
    {
        return (bool) preg_match('/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+))*\.?$/i', $host);
    }
}
