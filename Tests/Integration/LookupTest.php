<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class LookupTest extends TestCase
{
    public function test_mailbox_found_for_member_and_admin()
    {
        $member = Fixtures::user();
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $mailbox = Fixtures::mailbox([], [$member]);

        $this->assertSame((int) $mailbox->id, (int) Lookup::mailbox($mailbox->id, $member)->id);
        $this->assertSame((int) $mailbox->id, (int) Lookup::mailbox($mailbox->id, $admin)->id);
    }

    public function test_mailbox_missing_or_inaccessible()
    {
        $mailbox = Fixtures::mailbox([], [Fixtures::user()]);

        foreach ([$mailbox->id, 999999999] as $id) {
            try {
                Lookup::mailbox($id, Fixtures::user());
                $this->fail('A ToolError was expected.');
            } catch (ToolError $e) {
                $this->assertSame('Mailbox not found or not accessible.', $e->getMessage());
            }
        }
    }

    public function test_conversation_found_for_member()
    {
        $member = Fixtures::user();
        $conversation = Fixtures::conversation(Fixtures::mailbox([], [$member]), Fixtures::customer());

        $this->assertSame((int) $conversation->id, (int) Lookup::conversation($conversation->id, $member)->id);
    }

    public function test_conversation_missing_or_inaccessible()
    {
        $conversation = Fixtures::conversation(Fixtures::mailbox([], [Fixtures::user()]), Fixtures::customer());

        foreach ([$conversation->id, 999999999] as $id) {
            try {
                Lookup::conversation($id, Fixtures::user());
                $this->fail('A ToolError was expected.');
            } catch (ToolError $e) {
                $this->assertSame('Conversation not found or not accessible.', $e->getMessage());
            }
        }
    }
}
