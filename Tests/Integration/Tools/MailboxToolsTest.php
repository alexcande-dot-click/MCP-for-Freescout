<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\User;
use Modules\McpForFreescout\Mcp\Server;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Mailboxes\GetMailboxTool;
use Modules\McpForFreescout\Tools\Mailboxes\ListMailboxesTool;

class MailboxToolsTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    public function test_list_mailboxes_returns_only_accessible()
    {
        $user = Fixtures::user();
        $mine = Fixtures::mailbox(['name' => 'Mine'], [$user]);
        $theirs = Fixtures::mailbox(['name' => 'Theirs'], [Fixtures::user()]);

        $result = (new ListMailboxesTool())->handle([], $user);

        $ids = array_column($result['mailboxes'], 'id');
        $this->assertSame([(int) $mine->id], $ids);
        $this->assertNotContains((int) $theirs->id, $ids);
    }

    public function test_admin_lists_every_mailbox()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox();

        $result = (new ListMailboxesTool())->handle([], $admin);

        $this->assertContains((int) $mailbox->id, array_column($result['mailboxes'], 'id'));
    }

    public function test_from_aliases_empty_when_aliases_reply_disabled()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox(['aliases' => 'sales@example.test (Sales)', 'aliases_reply' => false], [$user]);

        $result = (new GetMailboxTool())->handle(['mailbox_id' => $mailbox->id], $user);

        $this->assertSame([], $result['from_aliases']);
    }

    public function test_from_aliases_listed_when_enabled()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox(['aliases' => 'sales@example.test (Sales)', 'aliases_reply' => true], [$user]);

        $result = (new GetMailboxTool())->handle(['mailbox_id' => $mailbox->id], $user);

        $this->assertContains('sales@example.test', $result['from_aliases']);
        $this->assertContains($mailbox->email, $result['from_aliases']);
    }

    public function test_get_mailbox_shape_and_permissions()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox(['name' => 'Support', 'signature' => '<p>Regards</p>'], [$user]);

        $result = null;
        $this->withCompanions(false, function () use (&$result, $mailbox, $user) {
            $result = (new GetMailboxTool())->handle(['mailbox_id' => (string) $mailbox->id], $user);
        });

        $this->assertSame((int) $mailbox->id, $result['id']);
        $this->assertSame('Support', $result['name']);
        $this->assertSame($mailbox->email, $result['email']);
        $this->assertSame('<p>Regards</p>', $result['signature']);
        $this->assertSame(['update_settings' => false, 'update_signature' => false], $result['permissions']);
        $this->assertArrayNotHasKey('custom_signatures', $result);

        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $adminResult = (new GetMailboxTool())->handle(['mailbox_id' => $mailbox->id], $admin);
        $this->assertSame(['update_settings' => true, 'update_signature' => true], $adminResult['permissions']);
    }

    public function test_get_mailbox_denies_inaccessible()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [Fixtures::user()]);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Mailbox not found or not accessible.');

        (new GetMailboxTool())->handle(['mailbox_id' => $mailbox->id], $user);
    }

    public function test_get_mailbox_requires_mailbox_id()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('mailbox_id');

        (new GetMailboxTool())->handle([], Fixtures::user());
    }

    public function test_tools_are_registered_and_listed_by_the_server()
    {
        $user = Fixtures::user();

        $response = (new Server(app(ToolRegistry::class)))->handle(
            ['mcp-protocol-version' => '2025-11-25'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
            $user
        );

        $names = array_column($response->body()['result']['tools'], 'name');
        foreach (['list_mailboxes', 'get_mailbox', 'list_users', 'search_customers', 'get_customer'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertStringContainsString('"properties":{}', json_encode($response->body()['result']['tools']));
    }
}
