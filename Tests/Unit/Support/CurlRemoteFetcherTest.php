<?php

namespace Modules\McpForFreescout\Tests\Unit\Support;

use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CurlRemoteFetcher;
use Modules\McpForFreescout\Support\RemoteFetcher;
use Modules\McpForFreescout\Tests\TestCase;

/**
 * The checks run before any connection is made, so these tests need no network.
 * FreeScout is booted because the core SSRF check reads its configuration.
 */
class CurlRemoteFetcherTest extends TestCase
{
    public function test_is_the_bound_fetcher()
    {
        $this->assertInstanceOf(CurlRemoteFetcher::class, app(RemoteFetcher::class));
    }

    public function test_refuses_non_http_schemes()
    {
        foreach (['file:///etc/passwd', 'ftp://x', 'gopher://example.com/', 'php://filter/resource=/etc/passwd'] as $url) {
            $this->assertRefused($url, 'Only http and https links can be downloaded.');
        }
    }

    public function test_refuses_internal_hosts()
    {
        $urls = [
            'http://127.0.0.1/',
            'http://10.0.0.1/',
            'http://169.254.169.254/latest',
            'http://192.168.1.1:8080/files',
            'https://[::1]/',
        ];

        foreach ($urls as $url) {
            $this->assertRefused($url, 'The link points to a local or private address and cannot be downloaded.');
        }
    }

    public function test_refuses_credentials_and_ambiguous_characters()
    {
        $urls = [
            'http://user:pass@example.com/',
            'http://127.0.0.1\\@example.com/',
            'http://example.com/a b',
            "http://example.com/\n",
            'http://127.1/',
            'http://0x7f000001/',
            'http://2130706433/',
            'http://0177.0.0.1/',
        ];

        foreach ($urls as $url) {
            $this->assertRefused($url, 'The link is not a valid http or https URL.');
        }
    }

    public function test_redirect_status_is_refused()
    {
        $fetcher = new StatusProbe();

        foreach ([301, 302, 303, 307, 308] as $status) {
            try {
                $fetcher->check($status);
                $this->fail('A ToolError was expected for status '.$status.'.');
            } catch (ToolError $e) {
                $this->assertSame('The link redirects; give the final URL.', $e->getMessage());
            }
        }
    }

    public function test_error_status_is_refused_and_success_accepted()
    {
        $fetcher = new StatusProbe();

        try {
            $fetcher->check(404);
            $this->fail('A ToolError was expected for status 404.');
        } catch (ToolError $e) {
            $this->assertSame('The link answered with HTTP status 404.', $e->getMessage());
        }

        $fetcher->check(200);
        $fetcher->check(206);
        $this->addToAssertionCount(1);
    }

    public function test_public_address_is_rebuilt_without_fragment()
    {
        $this->assertSame(
            ['http://8.8.8.8:8080/files/a.pdf?x=1', [], '8.8.8.8'],
            (new StatusProbe())->targetOf('HTTP://8.8.8.8:8080/files/a.pdf?x=1#page=2')
        );
    }

    public function test_trailing_dot_is_removed_from_the_host()
    {
        $this->assertSame(
            ['http://8.8.8.8/a.pdf', [], '8.8.8.8'],
            (new StatusProbe())->targetOf('http://8.8.8.8./a.pdf')
        );
        $this->assertRefused('http://127.0.0.1./', 'The link points to a local or private address and cannot be downloaded.');
        $this->assertRefused('http://./', 'The link is not a valid http or https URL.');
    }

    public function test_pin_prefers_an_ipv4_address()
    {
        $this->assertSame(
            ['files.example.com:443:203.0.113.7', '203.0.113.7'],
            (new StatusProbe())->pinOf('files.example.com', 443, ['2001:db8::1', '203.0.113.7'])
        );
    }

    public function test_pin_brackets_an_ipv6_address()
    {
        $this->assertSame(
            ['files.example.com:8443:[2001:db8::1]', '2001:db8::1'],
            (new StatusProbe())->pinOf('files.example.com', 8443, ['2001:db8::1'])
        );
    }

    public function test_pin_refuses_an_unresolved_host()
    {
        foreach ([[], ['not-an-ip']] as $ips) {
            try {
                (new StatusProbe())->pinOf('files.example.com', 443, $ips);
                $this->fail('A ToolError was expected.');
            } catch (ToolError $e) {
                $this->assertSame('The host of the link could not be resolved.', $e->getMessage());
            }
        }
    }

    public function test_connection_to_another_address_is_refused()
    {
        $probe = new StatusProbe();

        // The same address in another notation, and no check without a pin.
        $probe->connected('2001:db8::1', '2001:0db8:0:0:0:0:0:1');
        $probe->connected('203.0.113.7', '203.0.113.7');
        $probe->connected(null, '10.0.0.1');
        $this->addToAssertionCount(1);

        foreach ([['203.0.113.7', '10.0.0.1'], ['203.0.113.7', ''], ['203.0.113.7', false]] as [$pinned, $primary]) {
            try {
                $probe->connected($pinned, $primary);
                $this->fail('A ToolError was expected for '.var_export($primary, true).'.');
            } catch (ToolError $e) {
                $this->assertSame('The connection did not go to the address that was checked; nothing was downloaded.', $e->getMessage());
            }
        }
    }

    private function assertRefused(string $url, string $message): void
    {
        try {
            (new CurlRemoteFetcher())->fetch($url, 1024);
            $this->fail('A ToolError was expected for '.$url.'.');
        } catch (ToolError $e) {
            $this->assertSame($message, $e->getMessage(), $url);
        }
    }
}

/**
 * Exposes the status, link, pin and connection checks of the fetcher.
 */
class StatusProbe extends CurlRemoteFetcher
{
    public function check(int $status): void
    {
        $this->assertStatus($status);
    }

    public function targetOf(string $url): array
    {
        return $this->target($url);
    }

    public function pinOf(string $host, int $port, array $ips): array
    {
        return $this->pin($host, $port, $ips);
    }

    public function connected(?string $pinned, $primaryIp): void
    {
        $this->assertConnectedTo($pinned, $primaryIp);
    }
}
