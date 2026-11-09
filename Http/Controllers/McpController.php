<?php

namespace Modules\McpForFreescout\Http\Controllers;

use App\Option;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\McpForFreescout\Mcp\JsonRpcError;
use Modules\McpForFreescout\Mcp\Protocol;
use Modules\McpForFreescout\Mcp\Server;
use Modules\McpForFreescout\Mcp\TokenAuthenticator;

/**
 * The MCP endpoint (Streamable HTTP transport): authenticates the bearer token
 * and hands the request to the JSON-RPC server.
 */
class McpController extends Controller
{
    /**
     * Answer for a response that cannot be encoded as JSON, already encoded.
     */
    const ENCODING_FAILURE_BODY = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal server error."}}';

    /**
     * @var Server
     */
    private $server;

    /**
     * @var TokenAuthenticator
     */
    private $authenticator;

    public function __construct(Server $server, TokenAuthenticator $authenticator)
    {
        $this->server = $server;
        $this->authenticator = $authenticator;
    }

    /**
     * Answer one MCP request.
     *
     * Checks run in a fixed order so that nothing is revealed to unauthenticated
     * callers beyond what is needed: endpoint switch, origin, method, token.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function handle(Request $request): Response
    {
        if (!Option::get('mcpforfreescout.enabled')) {
            return $this->error(503, Protocol::INVALID_REQUEST, 'MCP endpoint is disabled');
        }

        if (!$this->isOriginAllowed($request)) {
            return $this->error(403, Protocol::INVALID_REQUEST, 'Origin not allowed');
        }

        if (!$request->isMethod('POST')) {
            return $this->error(405, Protocol::INVALID_REQUEST, 'Method not allowed, use POST', ['Allow' => 'POST']);
        }

        $user = $this->authenticator->authenticate($request->header('Authorization'), $request->ip());
        if ($user === null) {
            return $this->error(401, Protocol::UNAUTHORIZED, 'Unauthorized', [
                'WWW-Authenticate' => 'Bearer realm="FreeScout MCP"',
            ]);
        }

        \Auth::setUser($user);
        app()->setLocale($user->getLocale());

        $result = $this->server->handle(
            $this->flattenHeaders($request),
            (string) $request->getContent(),
            $user,
            (string) $this->authenticator->lastToken()->token_prefix
        );

        if ($result->body() === null) {
            return response('', $result->status(), $result->headers());
        }

        return $this->json($result->body(), $result->status(), $result->headers());
    }

    /**
     * Whether the request may proceed given its Origin header.
     *
     * Browser-originated requests must come from the application's own origin
     * (scheme, host and port); this blocks DNS rebinding and cross-site calls.
     * Requests without an Origin header (non-browser clients) are allowed.
     */
    private function isOriginAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null) {
            return true;
        }

        $requested = $this->normalizeOrigin($origin);

        return $requested !== null && $requested === $this->normalizeOrigin((string) config('app.url'));
    }

    /**
     * Reduce a URL to "scheme://host:port" with default ports made explicit.
     */
    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host']).':'.$port;
    }

    /**
     * Request headers as lower-cased name => first value.
     */
    private function flattenHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) reset($values);
        }

        return $headers;
    }

    /**
     * Build a JSON-RPC error response with a null id.
     */
    private function error(int $status, int $code, string $message, array $headers = []): Response
    {
        $error = new JsonRpcError($code, $message);

        return $this->json($error->toArray(null), $status, $headers);
    }

    /**
     * Encode a document as-is (objects stay objects) and wrap it in a JSON response.
     */
    private function json(array $document, int $status, array $headers = []): Response
    {
        $content = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($content === false) {
            \Log::error('[MCP] A response could not be encoded as JSON ('.json_last_error_msg().').');

            return response(self::ENCODING_FAILURE_BODY, 500, ['Content-Type' => 'application/json']);
        }

        return response($content, $status, array_merge(['Content-Type' => 'application/json'], $headers));
    }
}
