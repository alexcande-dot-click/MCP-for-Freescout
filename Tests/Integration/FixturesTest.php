<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class FixturesTest extends TestCase
{
    public function test_conversation_is_published_with_one_customer_thread()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $customer = Fixtures::customer('jane@example.test');

        $conversation = Fixtures::conversation($mailbox, $customer, ['subject' => 'Printer is on fire']);

        $fresh = Conversation::find($conversation->id);
        $this->assertSame('Printer is on fire', $fresh->subject);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $fresh->state);
        $this->assertSame($mailbox->id, (int) $fresh->mailbox_id);
        $this->assertSame($customer->id, (int) $fresh->customer_id);
        $this->assertNotEmpty($fresh->folder_id);

        $threads = $fresh->threads()->get();
        $this->assertCount(1, $threads);
        $this->assertSame(Thread::TYPE_CUSTOMER, (int) $threads[0]->type);
        $this->assertTrue($user->can('view', $fresh));
    }

    public function test_admin_role_is_applied()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);

        $this->assertTrue(User::find($admin->id)->isAdmin());
    }
}
