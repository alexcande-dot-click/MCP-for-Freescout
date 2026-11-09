<?php

namespace Modules\McpForFreescout\Tests\Unit;

use App\ActivityLog;
use Modules\McpForFreescout\Mcp\McpResponse;
use Modules\McpForFreescout\Mcp\Protocol;
use Modules\McpForFreescout\Mcp\Server;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tests\Unit\Support\AlphaEchoTool;
use Modules\McpForFreescout\Tests\Unit\Support\EchoTool;
use Modules\McpForFreescout\Tests\Unit\Support\MutatingEchoTool;
use Modules\McpForFreescout\Tests\Unit\Support\UnavailableEchoTool;

class ServerTest extends TestCase
{
    const MODERN = '2026-07-28';
    const META_KEY = 'io.modelcontextprotocol/protocolVersion';

    private $user;
    private $server;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = new ToolRegistry();
        $registry->register(EchoTool::class);
        $registry->register(AlphaEchoTool::class);
        $registry->register(UnavailableEchoTool::class);

        $this->user = Fixtures::user();
        $this->server = new Server($registry);
    }

    public function test_parse_error()
    {
        $response = $this->server->handle([], '{not json', $this->user);

        $this->assertSame(400, $response->status());
        $this->assertSame(Protocol::PARSE_ERROR, $response->body()['error']['code']);
        $this->assertNull($response->body()['id']);
    }

    public function test_batch_rejected()
    {
        $batch = json_encode([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]);

        $response = $this->server->handle([], $batch, $this->user);

        $this->assertSame(400, $response->status());
        $this->assertSame(Protocol::INVALID_REQUEST, $response->body()['error']['code']);
    }

    public function test_invalid_request_echoes_id_when_present()
    {
        $response = $this->server->handle([], json_encode(['jsonrpc' => '1.0', 'id' => 'abc', 'method' => 'ping']), $this->user);

        $this->assertSame(400, $response->status());
        $this->assertSame(Protocol::INVALID_REQUEST, $response->body()['error']['code']);
        $this->assertSame('abc', $response->body()['id']);

        $response = $this->server->handle([], json_encode(['jsonrpc' => '2.0', 'id' => 7]), $this->user);
        $this->assertSame(Protocol::INVALID_REQUEST, $response->body()['error']['code']);
        $this->assertSame(7, $response->body()['id']);
    }

    public function test_notification_returns_202()
    {
        foreach (['notifications/initialized', 'notifications/whatever'] as $method) {
            $response = $this->server->handle([], json_encode(['jsonrpc' => '2.0', 'method' => $method]), $this->user);

            $this->assertSame(202, $response->status());
            $this->assertNull($response->body());
        }
    }

    public function test_legacy_initialize_negotiates_version()
    {
        $response = $this->initialize('2025-06-18');
        $result = $response->body()['result'];

        $this->assertSame(200, $response->status());
        $this->assertSame(1, $response->body()['id']);
        $this->assertSame('2025-06-18', $result['protocolVersion']);
        $this->assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        $this->assertSame(['name' => 'mcp-for-freescout', 'version' => Protocol::serverVersion()], $result['serverInfo']);
        $this->assertSame(Protocol::instructions(), $result['instructions']);
        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertArrayNotHasKey('_meta', $result);

        $this->assertSame('2025-11-25', $this->initialize('2024-11-05')->body()['result']['protocolVersion']);
    }

    public function test_instructions_state_the_safety_model()
    {
        $instructions = Protocol::instructions();

        $this->assertStringContainsString('drafts', $instructions);
        $this->assertStringContainsString('send_draft', $instructions);
        $this->assertStringContainsString('trash', $instructions);
        $this->assertStringContainsString('permanently deleted', $instructions);
        $this->assertStringContainsString('user', $instructions);
    }

    public function test_server_version_comes_from_module_json()
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/module.json'), true);

        $this->assertSame($manifest['version'], Protocol::serverVersion());
    }

    public function test_missing_version_header_is_header_mismatch()
    {
        $response = $this->server->handle([], $this->request('tools/list'), $this->user);

        $this->assertSame(400, $response->status());
        $this->assertSame(Protocol::HEADER_MISMATCH, $response->body()['error']['code']);
        $this->assertSame('Missing MCP-Protocol-Version header', $response->body()['error']['message']);
        $this->assertSame(1, $response->body()['id']);
    }

    public function test_unsupported_version_lists_supported()
    {
        $response = $this->server->handle(
            ['mcp-protocol-version' => '2024-11-05'],
            $this->request('tools/list'),
            $this->user
        );

        $error = $response->body()['error'];
        $this->assertSame(400, $response->status());
        $this->assertSame(Protocol::UNSUPPORTED_PROTOCOL_VERSION, $error['code']);
        $this->assertContains('2026-07-28', $error['data']['supported']);
        $this->assertContains('2025-11-25', $error['data']['supported']);
        $this->assertContains('2025-06-18', $error['data']['supported']);
        $this->assertSame('2024-11-05', $error['data']['requested']);
    }

    public function test_legacy_mode_needs_no_meta_and_has_no_modern_fields()
    {
        $response = $this->server->handle(
            ['mcp-protocol-version' => '2025-11-25'],
            $this->request('tools/list'),
            $this->user
        );

        $result = $response->body()['result'];
        $this->assertSame(200, $response->status());
        $this->assertSame(['alpha', 'echo'], array_column($result['tools'], 'name'));
        foreach (['resultType', '_meta', 'ttlMs', 'cacheScope'] as $key) {
            $this->assertArrayNotHasKey($key, $result);
        }
    }

    public function test_legacy_ping_returns_empty_object()
    {
        $response = $this->server->handle(
            ['mcp-protocol-version' => '2025-11-25'],
            $this->request('ping'),
            $this->user
        );

        $this->assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', json_encode($response->body()));
    }

    public function test_modern_meta_version_mismatch()
    {
        $headers = $this->modernHeaders('tools/list');

        foreach ([
            ['_meta' => [self::META_KEY => '2025-11-25']],
            ['_meta' => []],
            [],
        ] as $params) {
            $response = $this->server->handle($headers, $this->request('tools/list', $params), $this->user);

            $this->assertSame(400, $response->status());
            $this->assertSame(Protocol::HEADER_MISMATCH, $response->body()['error']['code']);
        }

        $response = $this->server->handle(
            ['mcp-method' => 'tools/call'] + $headers,
            $this->request('tools/list', $this->meta()),
            $this->user
        );
        $this->assertSame(400, $response->status());
        $this->assertSame(Protocol::HEADER_MISMATCH, $response->body()['error']['code']);
    }

    public function test_modern_mcp_name_mismatch_and_base64_decoding()
    {
        $params = $this->meta(['name' => 'echo', 'arguments' => ['text' => 'hi']]);
        $body = $this->request('tools/call', $params);

        $mismatch = $this->server->handle($this->modernHeaders('tools/call', 'other'), $body, $this->user);
        $this->assertSame(400, $mismatch->status());
        $this->assertSame(Protocol::HEADER_MISMATCH, $mismatch->body()['error']['code']);

        $missing = $this->server->handle($this->modernHeaders('tools/call'), $body, $this->user);
        $this->assertSame(Protocol::HEADER_MISMATCH, $missing->body()['error']['code']);

        $plain = $this->server->handle($this->modernHeaders('tools/call', 'echo'), $body, $this->user);
        $this->assertSame(200, $plain->status());
        $this->assertArrayHasKey('result', $plain->body());

        $encoded = $this->server->handle(
            $this->modernHeaders('tools/call', '=?base64?'.base64_encode('echo').'?='),
            $body,
            $this->user
        );
        $this->assertSame(200, $encoded->status());
        $this->assertArrayHasKey('result', $encoded->body());

        $broken = $this->server->handle($this->modernHeaders('tools/call', '=?base64?%%%?='), $body, $this->user);
        $this->assertSame(Protocol::HEADER_MISMATCH, $broken->body()['error']['code']);
    }

    public function test_mcp_name_header_is_not_required_for_other_methods()
    {
        $response = $this->server->handle(
            $this->modernHeaders('tools/list'),
            $this->request('tools/list', $this->meta()),
            $this->user
        );

        $this->assertSame(200, $response->status());
    }

    public function test_server_discover()
    {
        $response = $this->server->handle(
            $this->modernHeaders('server/discover'),
            $this->request('server/discover', $this->meta()),
            $this->user
        );

        $result = $response->body()['result'];
        $this->assertSame(200, $response->status());
        $this->assertSame(['2026-07-28', '2025-11-25', '2025-06-18'], $result['supportedVersions']);
        $this->assertSame('{}', json_encode($result['capabilities']['tools']));
        $this->assertSame(Protocol::instructions(), $result['instructions']);
        $this->assertSame(3600000, $result['ttlMs']);
        $this->assertSame('public', $result['cacheScope']);
        $this->assertSame('complete', $result['resultType']);
    }

    public function test_server_discover_is_unknown_in_legacy_mode()
    {
        $response = $this->server->handle(
            ['mcp-protocol-version' => '2025-11-25'],
            $this->request('server/discover'),
            $this->user
        );

        $this->assertSame(200, $response->status());
        $this->assertSame(Protocol::METHOD_NOT_FOUND, $response->body()['error']['code']);
    }

    public function test_tools_list_modern_has_cache_fields_and_sorted()
    {
        $response = $this->server->handle(
            $this->modernHeaders('tools/list'),
            $this->request('tools/list', $this->meta()),
            $this->user
        );

        $result = $response->body()['result'];
        $this->assertSame(['alpha', 'echo'], array_column($result['tools'], 'name'));
        $this->assertSame(300000, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(
            ['name' => 'mcp-for-freescout', 'version' => Protocol::serverVersion()],
            $result['_meta']['io.modelcontextprotocol/serverInfo']
        );
        $this->assertSame(['name', 'description', 'inputSchema', 'annotations'], array_keys($result['tools'][1]));
        $this->assertSame(['readOnlyHint' => true], $result['tools'][1]['annotations']);
    }

    public function test_tools_call_success_returns_structured_content()
    {
        $response = $this->callTool('echo', ['text' => 'héllo']);

        $result = $response->body()['result'];
        $this->assertSame(200, $response->status());
        $this->assertSame(['text' => 'héllo'], $result['structuredContent']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame('{"text":"héllo"}', $result['content'][0]['text']);
        $this->assertArrayNotHasKey('isError', $result);
        $this->assertArrayNotHasKey('resultType', $result);
    }

    public function test_tools_call_modern_result_is_decorated()
    {
        $params = $this->meta(['name' => 'echo', 'arguments' => ['text' => 'hi']]);

        $response = $this->server->handle(
            $this->modernHeaders('tools/call', 'echo'),
            $this->request('tools/call', $params),
            $this->user
        );

        $result = $response->body()['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(['text' => 'hi'], $result['structuredContent']);
        $this->assertArrayHasKey('io.modelcontextprotocol/serverInfo', $result['_meta']);
    }

    public function test_tools_call_tool_error_is_result_not_jsonrpc_error()
    {
        $response = $this->callTool('echo', ['fail' => 'Conversation not found']);

        $this->assertSame(200, $response->status());
        $this->assertArrayNotHasKey('error', $response->body());
        $this->assertSame(
            [
                'content' => [['type' => 'text', 'text' => 'Conversation not found']],
                'isError' => true,
            ],
            $response->body()['result']
        );
    }

    public function test_mutating_call_is_audited_and_read_call_is_not()
    {
        $registry = new ToolRegistry();
        $registry->register(EchoTool::class);
        $registry->register(MutatingEchoTool::class);
        $server = new Server($registry);
        $call = function (string $name, array $arguments) use ($server) {
            return $server->handle(
                ['mcp-protocol-version' => '2025-11-25'],
                $this->request('tools/call', ['name' => $name, 'arguments' => $arguments]),
                $this->user,
                'fsmcp_ab12'
            );
        };
        $entries = function () {
            return ActivityLog::inLog('mcpforfreescout')->orderBy('id')->get();
        };

        $call('echo', ['conversation_id' => 5]);
        $call('mutate', ['fail' => 'Conversation not found', 'conversation_id' => 5]);
        $this->assertCount(0, $entries());

        $call('mutate', [
            'conversation_id' => 5,
            'mailbox_id' => '3',
            'tag_ids' => [1, '2'],
            'draft_id' => 'not-a-number',
            'body' => 'Dear Jane, the secret is 42',
            'subject' => 'Private subject',
            'to' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);

        $logged = $entries();
        $this->assertCount(1, $logged);
        $this->assertSame('mutate', $logged[0]->description);
        $this->assertSame($this->user->id, $logged[0]->properties['user_id']);
        $this->assertSame('fsmcp_ab12', $logged[0]->properties['token_prefix']);
        $this->assertSame(
            ['conversation_id' => 5, 'mailbox_id' => 3, 'tag_ids' => [1, 2]],
            $logged[0]->properties['arguments']
        );
        $properties = strtolower(json_encode($logged[0]->properties));
        $this->assertStringNotContainsString('secret', $properties);
        $this->assertStringNotContainsString('jane', $properties);
        $this->assertStringNotContainsString('private', $properties);
    }

    public function test_unexpected_exception_is_a_generic_internal_error()
    {
        $response = $this->callTool('echo', ['crash' => true]);

        $error = $response->body()['error'];
        $this->assertSame(500, $response->status());
        $this->assertSame(Protocol::INTERNAL_ERROR, $error['code']);
        $this->assertStringNotContainsString('secret', $error['message']);
        $this->assertSame(1, $response->body()['id']);
    }

    public function test_a_result_that_cannot_be_encoded_is_a_generic_internal_error_and_still_audited()
    {
        $registry = new ToolRegistry();
        $registry->register(MutatingEchoTool::class);
        $server = new Server($registry);

        $response = $server->handle(
            ['mcp-protocol-version' => '2025-11-25'],
            $this->request('tools/call', ['name' => 'mutate', 'arguments' => ['binary' => true, 'conversation_id' => 9]]),
            $this->user,
            'fsmcp_ab12'
        );

        $this->assertSame(500, $response->status());
        $this->assertSame(Protocol::INTERNAL_ERROR, $response->body()['error']['code']);
        $this->assertSame('Internal server error.', $response->body()['error']['message']);
        $this->assertArrayNotHasKey('result', $response->body());
        $this->assertSame(1, $response->body()['id']);
        $this->assertCount(1, ActivityLog::inLog('mcpforfreescout')->get(), 'The tool had acted, so the call is on record.');
    }

    public function test_tools_call_validates_params()
    {
        $invalid = [
            [],
            ['name' => ''],
            ['name' => 42],
            ['name' => 'echo', 'arguments' => 'text'],
            ['name' => 'echo', 'arguments' => ['a', 'b']],
            ['name' => 'nope'],
        ];

        foreach ($invalid as $params) {
            $response = $this->server->handle(
                ['mcp-protocol-version' => '2025-11-25'],
                $this->request('tools/call', $params),
                $this->user
            );

            $this->assertSame(Protocol::INVALID_PARAMS, $response->body()['error']['code'], json_encode($params));
        }
    }

    public function test_tools_call_arguments_default_to_empty()
    {
        $response = $this->callTool('echo', null);

        $result = $response->body()['result'];
        $this->assertSame('{}', json_encode($result['structuredContent']));
        $this->assertSame('{}', $result['content'][0]['text']);
    }

    public function test_list_shaped_tool_result_is_an_internal_error()
    {
        $response = $this->callTool('echo', ['list' => true]);

        $this->assertSame(500, $response->status());
        $this->assertSame(Protocol::INTERNAL_ERROR, $response->body()['error']['code']);
        $this->assertArrayNotHasKey('result', $response->body());
    }

    public function test_unknown_method_is_404_in_modern_mode()
    {
        $modern = $this->server->handle(
            $this->modernHeaders('resources/list'),
            $this->request('resources/list', $this->meta()),
            $this->user
        );
        $this->assertSame(404, $modern->status());
        $this->assertSame(Protocol::METHOD_NOT_FOUND, $modern->body()['error']['code']);

        $legacy = $this->server->handle(
            ['mcp-protocol-version' => '2025-11-25'],
            $this->request('resources/list'),
            $this->user
        );
        $this->assertSame(200, $legacy->status());
        $this->assertSame(Protocol::METHOD_NOT_FOUND, $legacy->body()['error']['code']);
    }

    public function test_ping_is_unknown_in_modern_mode()
    {
        $response = $this->server->handle(
            $this->modernHeaders('ping'),
            $this->request('ping', $this->meta()),
            $this->user
        );

        $this->assertSame(404, $response->status());
    }

    public function test_unavailable_tool_hidden_and_not_callable()
    {
        $listed = $this->server->handle(
            ['mcp-protocol-version' => '2025-11-25'],
            $this->request('tools/list'),
            $this->user
        );
        $this->assertNotContains('unavailable', array_column($listed->body()['result']['tools'], 'name'));

        $response = $this->callTool('unavailable', []);
        $this->assertSame(Protocol::INVALID_PARAMS, $response->body()['error']['code']);
    }

    public function test_responses_are_json()
    {
        $response = $this->initialize('2025-11-25');
        $this->assertSame('application/json', $response->headers()['Content-Type']);
    }

    public function test_registry_rejects_classes_that_are_not_tools()
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ToolRegistry())->register(\stdClass::class);
    }

    public function test_registry_rejects_duplicate_names()
    {
        $registry = new ToolRegistry();
        $registry->register(EchoTool::class);

        $this->expectException(\InvalidArgumentException::class);

        $registry->register(EchoTool::class);
    }

    public function test_registry_find_respects_availability()
    {
        $registry = new ToolRegistry();
        $registry->register(EchoTool::class);
        $registry->register(UnavailableEchoTool::class);

        $this->assertInstanceOf(EchoTool::class, $registry->find('echo', $this->user));
        $this->assertNull($registry->find('unavailable', $this->user));
        $this->assertNull($registry->find('missing', $this->user));
    }

    public function test_provider_builds_registry_from_the_tools_filter()
    {
        $callback = function (array $classes) {
            $classes[] = EchoTool::class;

            return $classes;
        };
        \Eventy::addFilter('mcpforfreescout.tools', $callback);

        try {
            $registry = app()->make(ToolRegistry::class);
        } finally {
            \Eventy::removeFilter('mcpforfreescout.tools', $callback);
            app()->forgetInstance(ToolRegistry::class);
        }

        $this->assertNotNull($registry->find('echo', $this->user));
    }

    public function test_a_tool_class_offered_twice_through_the_filter_is_registered_once()
    {
        $callback = function (array $classes) {
            return array_merge($classes, [EchoTool::class, EchoTool::class]);
        };
        \Eventy::addFilter('mcpforfreescout.tools', $callback);

        try {
            $registry = app()->make(ToolRegistry::class);
        } finally {
            \Eventy::removeFilter('mcpforfreescout.tools', $callback);
            app()->forgetInstance(ToolRegistry::class);
        }

        $this->assertNotNull($registry->find('echo', $this->user));
    }

    private function initialize(string $version): McpResponse
    {
        return $this->server->handle([], $this->request('initialize', [
            'protocolVersion' => $version,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]), $this->user);
    }

    /**
     * Call a tool in legacy mode.
     */
    private function callTool(string $name, ?array $arguments): McpResponse
    {
        $params = ['name' => $name];
        if ($arguments !== null) {
            $params['arguments'] = $arguments;
        }

        return $this->server->handle(
            ['mcp-protocol-version' => '2025-11-25'],
            $this->request('tools/call', $params),
            $this->user
        );
    }

    private function request(string $method, array $params = null): string
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method];
        if ($params !== null) {
            $message['params'] = $params;
        }

        return json_encode($message);
    }

    private function modernHeaders(string $method, string $name = null): array
    {
        $headers = ['mcp-protocol-version' => self::MODERN, 'mcp-method' => $method];
        if ($name !== null) {
            $headers['mcp-name'] = $name;
        }

        return $headers;
    }

    private function meta(array $params = []): array
    {
        return ['_meta' => [self::META_KEY => self::MODERN]] + $params;
    }
}
