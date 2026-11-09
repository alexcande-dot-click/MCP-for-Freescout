<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\Mailbox;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Mailboxes\UpdateMailboxTool;

class UpdateMailboxToolTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    /**
     * A non-admin user whose manage rights on the mailbox are limited to $access
     * (Mailbox::ACCESS_PERM_* values).
     */
    private function manager(Mailbox $mailbox, array $access): User
    {
        $user = Fixtures::user();
        $mailbox->users()->sync([$user->id => ['access' => json_encode($access)]]);

        return User::find($user->id);
    }

    private function assertRejected(UpdateMailboxTool $tool, array $arguments, User $user, string $message)
    {
        try {
            $tool->handle($arguments, $user);
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_admin_updates_signature_and_aliases()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox(['name' => 'Old name', 'signature' => '<p>Old</p>']);

        $result = (new UpdateMailboxTool())->handle([
            'mailbox_id'    => $mailbox->id,
            'name'          => 'Support',
            'aliases'       => 'Sales@Example.test (Sales team), help@example.test',
            'aliases_reply' => true,
            'signature'     => '<p>Kind regards,<br>Support</p>',
        ], $admin);

        $this->assertSame('Support', $result['name']);
        $this->assertSame('<p>Kind regards,<br>Support</p>', $result['signature']);
        $this->assertSame([$mailbox->email, 'sales@example.test', 'help@example.test'], $result['from_aliases']);

        $fresh = Mailbox::find($mailbox->id);
        $this->assertSame('Support', $fresh->name);
        $this->assertTrue((bool) $fresh->aliases_reply);
        $this->assertSame('sales@example.test (Sales team), help@example.test', $fresh->aliases);
        $this->assertSame('<p>Kind regards,<br>Support</p>', $fresh->signature);
    }

    public function test_aliases_with_names_round_trip_through_core_parsing()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox();

        (new UpdateMailboxTool())->handle([
            'mailbox_id'    => $mailbox->id,
            'aliases'       => "sales@example.test (Sales),\n billing@example.test(Billing Dept)",
            'aliases_reply' => true,
        ], $admin);

        $aliases = Mailbox::find($mailbox->id)->getAliases(false);
        $this->assertSame(['sales@example.test' => 'Sales', 'billing@example.test' => 'Billing Dept'], $aliases);
    }

    public function test_empty_aliases_clear_the_list()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox(['aliases' => 'sales@example.test']);

        (new UpdateMailboxTool())->handle(['mailbox_id' => $mailbox->id, 'aliases' => ' '], $admin);

        $this->assertSame([], Mailbox::find($mailbox->id)->getAliases(false));
    }

    public function test_user_without_permission_cannot_update_signature()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox(['signature' => '<p>Keep</p>'], [$user]);

        $this->assertRejected(
            new UpdateMailboxTool(),
            ['mailbox_id' => $mailbox->id, 'signature' => 'Hacked'],
            User::find($user->id),
            'not allowed to change the signature'
        );

        $this->assertSame('<p>Keep</p>', Mailbox::find($mailbox->id)->signature);
    }

    public function test_user_without_permission_cannot_update_settings()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox(['aliases' => 'a@example.test'], [$user]);

        $this->assertRejected(
            new UpdateMailboxTool(),
            ['mailbox_id' => $mailbox->id, 'aliases' => 'b@example.test'],
            User::find($user->id),
            'not allowed to change the settings'
        );

        $this->assertSame('a@example.test', Mailbox::find($mailbox->id)->aliases);
    }

    public function test_denied_group_blocks_the_whole_call_without_partial_write()
    {
        $mailbox = Fixtures::mailbox(['aliases' => 'a@example.test', 'signature' => '<p>Keep</p>']);
        $signatureOnly = $this->manager($mailbox, [Mailbox::ACCESS_PERM_SIGNATURE]);

        $this->assertRejected(
            new UpdateMailboxTool(),
            ['mailbox_id' => $mailbox->id, 'signature' => '<p>New</p>', 'aliases' => 'b@example.test'],
            $signatureOnly,
            'not allowed to change the settings'
        );

        $fresh = Mailbox::find($mailbox->id);
        $this->assertSame('<p>Keep</p>', $fresh->signature);
        $this->assertSame('a@example.test', $fresh->aliases);
    }

    public function test_signature_permission_alone_allows_a_signature_update()
    {
        $mailbox = Fixtures::mailbox();
        $signatureOnly = $this->manager($mailbox, [Mailbox::ACCESS_PERM_SIGNATURE]);

        $result = (new UpdateMailboxTool())->handle(['mailbox_id' => $mailbox->id, 'signature' => 'Thanks'], $signatureOnly);

        $this->assertSame('Thanks', $result['signature']);
        $this->assertSame('Thanks', Mailbox::find($mailbox->id)->signature);
    }

    public function test_manager_with_edit_rights_updates_aliases_but_not_the_name()
    {
        $mailbox = Fixtures::mailbox(['name' => 'Fixed']);
        $manager = $this->manager($mailbox, [Mailbox::ACCESS_PERM_EDIT]);
        $tool = new UpdateMailboxTool();

        $tool->handle(['mailbox_id' => $mailbox->id, 'aliases' => 'a@example.test', 'aliases_reply' => true], $manager);
        $this->assertSame('a@example.test', Mailbox::find($mailbox->id)->aliases);

        $this->assertRejected($tool, ['mailbox_id' => $mailbox->id, 'name' => 'Renamed'], $manager, 'administrators');
        $this->assertSame('Fixed', Mailbox::find($mailbox->id)->name);
    }

    public function test_invalid_alias_rejected()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox(['aliases' => 'ok@example.test']);
        $tool = new UpdateMailboxTool();

        foreach (['not-an-email', 'a@example.test, nope', 'a@example.test (Open', 'a@example.test (A, B)', 'a b@example.test'] as $aliases) {
            $this->assertRejected($tool, ['mailbox_id' => $mailbox->id, 'aliases' => $aliases], $admin, 'aliases');
        }

        $this->assertSame('ok@example.test', Mailbox::find($mailbox->id)->aliases);
    }

    public function test_name_is_validated()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox(['name' => 'Keep']);
        $tool = new UpdateMailboxTool();

        $this->assertRejected($tool, ['mailbox_id' => $mailbox->id, 'name' => str_repeat('x', 41)], $admin, '40');
        $this->assertRejected($tool, ['mailbox_id' => $mailbox->id, 'name' => '   '], $admin, 'name');

        $tool->handle(['mailbox_id' => $mailbox->id, 'name' => str_repeat('é', 40)], $admin);
        $this->assertSame(str_repeat('é', 40), Mailbox::find($mailbox->id)->name);
    }

    public function test_at_least_one_field_is_required()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox();

        $this->assertRejected(new UpdateMailboxTool(), ['mailbox_id' => $mailbox->id], $admin, 'at least one');
    }

    public function test_inaccessible_mailbox_is_hidden()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [Fixtures::user()]);

        $this->assertRejected(
            new UpdateMailboxTool(),
            ['mailbox_id' => $mailbox->id, 'signature' => 'x'],
            $user,
            'Mailbox not found or not accessible.'
        );
    }

    public function test_signature_is_sanitised_like_core()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox();

        $result = (new UpdateMailboxTool())->handle([
            'mailbox_id' => $mailbox->id,
            'signature'  => '<p onclick="steal()">Hi</p><script>alert(1)</script><iframe src="http://evil.test"></iframe>',
        ], $admin);

        $this->assertStringContainsString('Hi', $result['signature']);
        $this->assertStringNotContainsString('<script', $result['signature']);
        $this->assertStringNotContainsString('alert(1)', $result['signature']);
        $this->assertStringNotContainsString('onclick', $result['signature']);
        $this->assertStringNotContainsString('iframe', $result['signature']);
        $this->assertSame($result['signature'], Mailbox::find($mailbox->id)->signature);
    }

    public function test_plain_text_signature_becomes_html()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox();

        $result = (new UpdateMailboxTool())->handle([
            'mailbox_id' => $mailbox->id,
            'signature'  => "Best,\nAT&T Support",
        ], $admin);

        $this->assertStringContainsString('AT&amp;T Support', $result['signature']);
        $this->assertStringContainsString('<br', $result['signature']);
    }

    public function test_empty_signature_clears_it()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox(['signature' => '<p>Old</p>']);

        $result = (new UpdateMailboxTool())->handle(['mailbox_id' => $mailbox->id, 'signature' => ''], $admin);

        $this->assertNull($result['signature']);
        $this->assertEmpty(Mailbox::find($mailbox->id)->signature);
    }

    public function test_companion_hook_never_fires_and_updated_event_does()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox();
        $beforeSave = 0;
        $updated = [];
        \Eventy::addAction('mailbox.settings_before_save', function () use (&$beforeSave) {
            $beforeSave++;
        }, 20, 2);
        \Eventy::addAction('mcpforfreescout.mailbox_updated', function ($mailbox, $user) use (&$updated) {
            $updated[] = [$mailbox->id, $user->id];
        }, 20, 2);

        (new UpdateMailboxTool())->handle(['mailbox_id' => $mailbox->id, 'signature' => 'Hi'], $admin);

        $this->assertSame(0, $beforeSave);
        $this->assertSame([[$mailbox->id, $admin->id]], $updated);
    }

    public function test_definition_annotations()
    {
        $definition = (new UpdateMailboxTool())->definition();

        $this->assertSame('update_mailbox', $definition['name']);
        $this->assertSame(
            ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
            $definition['annotations']
        );
        $this->assertSame(['mailbox_id'], $definition['inputSchema']['required']);
    }

    public function test_tool_is_registered()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);

        $this->assertNotNull(app(ToolRegistry::class)->find('update_mailbox', $admin));
    }
}
