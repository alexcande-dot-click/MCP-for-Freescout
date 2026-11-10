<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\User;
use Modules\McpForFreescout\Mcp\Server;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class ModuleBootTest extends TestCase
{
    public function test_module_is_active_and_provider_loaded()
    {
        $this->assertTrue(\Module::isActive('mcpforfreescout'));
        $this->assertTrue(defined('MCP_FOR_FREESCOUT_MODULE'));
        $this->assertTrue(config('mcpforfreescout.options.enabled.default') === true);
    }

    public function test_fixtures_create_accessible_mailbox()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);

        $this->assertTrue($user->can('view', $mailbox));
    }

    public function test_registry_lists_38_tools_with_companions()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);

        $names = [];
        $this->withCompanions(true, function () use (&$names, $admin) {
            $response = (new Server(app(ToolRegistry::class)))->handle(
                ['mcp-protocol-version' => '2025-11-25'],
                json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
                $admin
            );
            $names = array_column($response->body()['result']['tools'], 'name');
        });

        $this->assertCount(38, $names);
        $this->assertSame($names, array_values(array_unique($names)));
        foreach (['get_attachment', 'add_attachments', 'remove_attachment', 'star_conversation'] as $name) {
            $this->assertContains($name, $names);
        }
    }
}
