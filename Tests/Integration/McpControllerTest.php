<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\Option;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\McpForFreescout\Entities\McpToken;
use Modules\McpForFreescout\Http\Controllers\McpController;
use Modules\McpForFreescout\Mcp\McpResponse;
use Modules\McpForFreescout\Mcp\Protocol;
use Modules\McpForFreescout\Mcp\Server;
use Modules\McpForFreescout\Mcp\TokenAuthenticator;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class McpControllerTest extends TestCase
{
    const MODERN = '2026-07-28';

    protected function setUp(): void
    {
        parent::setUp();

        self::resetOptionCache();
    }

    protected function tearDown(): void
    {
        self::resetOptionCache();
        app()->setLocale(config('app.locale'));
        // Resolving the controller builds the singleton registry; do not leak it into other tests.
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    public function test_get_and_delete_return_405()
    {
        foreach (['GET', 'DELETE'] as $method) {
            $response = $this->call($this->request($method));
            $json = $this->json($response);

            $this->assertSame(405, $response->getStatusCode(), $method);
            $this->assertSame('POST', $response->headers->get('Allow'), $method);
            $this->assertSame(Protocol::INVALID_REQUEST, $json['error']['code'], $method);
            $this->assertNull($json['id'], $method);
        }
    }

    public function test_missing_token_returns_401_with_www_authenticate()
    {
        $response = $this->call($this->request('POST', $this->discoverBody()));
        $json = $this->json($response);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer realm="FreeScout MCP"', $response->headers->get('WWW-Authenticate'));
        $this->assertSame('2.0', $json['jsonrpc']);
        $this->assertNull($json['id']);
        $this->assertSame(-32001, $json['error']['code']);
        $this->assertSame('Unauthorized', $json['error']['message']);
    }

    public function test_unknown_token_returns_401()
    {
        $response = $this->call($this->request('POST', $this->discoverBody(), ['HTTP_AUTHORIZATION' => 'Bearer fsmcp_nope']));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_foreign_origin_returns_403()
    {
        $token = $this->issueToken();

        $response = $this->call($this->request('POST', $this->discoverBody(), $this->modernServer($token, [
            'HTTP_ORIGIN' => 'http://evil.example',
        ])));
        $json = $this->json($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(Protocol::INVALID_REQUEST, $json['error']['code']);
        $this->assertNull($json['id']);
    }

    public function test_origin_with_other_port_or_scheme_returns_403()
    {
        $token = $this->issueToken();
        $appUrl = parse_url(config('app.url'));
        $port = isset($appUrl['port']) ? $appUrl['port'] : 80;
        $otherPort = 'http://'.$appUrl['host'].':9';
        $otherScheme = 'https://'.$appUrl['host'].':'.$port;

        foreach ([$otherPort, $otherScheme] as $origin) {
            $response = $this->call($this->request('POST', $this->discoverBody(), $this->modernServer($token, [
                'HTTP_ORIGIN' => $origin,
            ])));

            $this->assertSame(403, $response->getStatusCode(), $origin);
        }
    }

    public function test_same_origin_allowed()
    {
        $token = $this->issueToken();

        $response = $this->call($this->request('POST', $this->discoverBody(), $this->modernServer($token, [
            'HTTP_ORIGIN' => config('app.url'),
        ])));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_disabled_option_returns_503()
    {
        $token = $this->issueToken();
        Option::set('mcpforfreescout.enabled', false);
        self::resetOptionCache();

        $response = $this->call($this->request('POST', $this->discoverBody(), $this->modernServer($token)));
        $json = $this->json($response);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(Protocol::INVALID_REQUEST, $json['error']['code']);
        $this->assertSame('MCP endpoint is disabled', $json['error']['message']);
        $this->assertNull($json['id']);
    }

    public function test_valid_request_dispatches_to_server()
    {
        $token = $this->issueToken();

        $response = $this->call($this->request('POST', $this->discoverBody(), $this->modernServer($token)));
        $json = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(1, $json['id']);
        $this->assertSame(['2026-07-28', '2025-11-25', '2025-06-18'], $json['result']['supportedVersions']);
        $this->assertStringContainsString('"tools":{}', $response->getContent());
    }

    public function test_server_error_status_is_passed_through()
    {
        $token = $this->issueToken();

        $response = $this->call($this->request('POST', '{not json', $this->modernServer($token)));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(Protocol::PARSE_ERROR, $this->json($response)['error']['code']);
    }

    public function test_notification_returns_empty_202()
    {
        $token = $this->issueToken();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $response = $this->call($this->request('POST', $body, $this->modernServer($token)));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function test_token_owner_becomes_the_authenticated_user()
    {
        $user = Fixtures::user(['locale' => 'de']);
        list(, $plaintext) = McpToken::issue($user, 'Agent');

        $this->call($this->request('POST', $this->discoverBody(), $this->modernServer($plaintext)));

        $this->assertSame((int) $user->id, (int) \Auth::id());
        $this->assertSame('de', app()->getLocale());
    }

    public function test_a_response_that_cannot_be_encoded_is_a_fixed_internal_error()
    {
        $server = new class(new ToolRegistry()) extends Server {
            public function handle(array $headers, string $rawBody, User $user, string $tokenPrefix = ''): McpResponse
            {
                return new McpResponse(200, ['result' => "broken \xFF text"], ['Content-Type' => 'application/json']);
            }
        };
        $controller = new McpController($server, app(TokenAuthenticator::class));
        Option::set('mcpforfreescout.enabled', true);
        self::resetOptionCache();
        $plaintext = $this->issueToken();

        $response = $controller->handle($this->request('POST', $this->discoverBody(), $this->modernServer($plaintext)));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => Protocol::INTERNAL_ERROR, 'message' => 'Internal server error.']],
            $this->json($response)
        );
    }

    private function call(Request $request): Response
    {
        return app(McpController::class)->handle($request);
    }

    private function request(string $method, string $body = '', array $server = []): Request
    {
        return Request::create('/mcp', $method, [], [], [], $server, $body);
    }

    private function issueToken(): string
    {
        list(, $plaintext) = McpToken::issue(Fixtures::user(), 'Agent');

        return $plaintext;
    }

    private function modernServer(string $plaintext, array $extra = []): array
    {
        return array_merge([
            'HTTP_AUTHORIZATION'        => 'Bearer '.$plaintext,
            'HTTP_MCP_PROTOCOL_VERSION' => self::MODERN,
            'HTTP_MCP_METHOD'           => 'server/discover',
            'CONTENT_TYPE'              => 'application/json',
        ], $extra);
    }

    private function discoverBody(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'server/discover',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => self::MODERN]],
        ]);
    }

    private function json(Response $response): array
    {
        return json_decode($response->getContent(), true);
    }

    /**
     * FreeScout caches options in a private static array; clear it so that
     * option changes made within a test are seen by the code under test.
     */
    private static function resetOptionCache()
    {
        $cache = new \ReflectionProperty(Option::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }
}
