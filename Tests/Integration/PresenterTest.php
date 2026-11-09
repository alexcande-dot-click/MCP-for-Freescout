<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Support\Presenter;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class PresenterTest extends TestCase
{
    public function test_user()
    {
        $admin = Fixtures::user(['first_name' => 'Ada', 'last_name' => 'Admin'], User::ROLE_ADMIN);

        $this->assertSame([
            'id'         => (int) $admin->id,
            'first_name' => 'Ada',
            'last_name'  => 'Admin',
            'email'      => $admin->email,
            'role'       => 'admin',
        ], Presenter::user($admin));
        $this->assertSame('user', Presenter::user(Fixtures::user())['role']);
    }

    public function test_conversation_summary()
    {
        $user = Fixtures::user();
        $customer = Fixtures::customer('summary@example.test');
        $conversation = Fixtures::conversation(Fixtures::mailbox([], [$user]), $customer, [
            'subject'       => 'Printer on fire',
            'status'        => Conversation::STATUS_PENDING,
            'user_id'       => $user->id,
            'last_reply_at' => '2026-03-04 05:06:07',
        ]);

        $summary = null;
        $this->withCompanions(false, function () use (&$summary, $conversation) {
            $summary = Presenter::conversationSummary($conversation->fresh());
        });

        $this->assertSame((int) $conversation->id, $summary['id']);
        $this->assertSame((int) $conversation->number, $summary['number']);
        $this->assertSame((int) $conversation->mailbox_id, $summary['mailbox_id']);
        $this->assertSame('Printer on fire', $summary['subject']);
        $this->assertSame('pending', $summary['status']);
        $this->assertSame('published', $summary['state']);
        $this->assertSame((int) $user->id, $summary['assignee_id']);
        $this->assertSame(
            ['id' => (int) $customer->id, 'name' => 'Test Customer', 'email' => 'summary@example.test'],
            $summary['customer']
        );
        $this->assertSame('Hello, I need some help.', $summary['preview']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $summary['last_reply_at']);
        $this->assertArrayNotHasKey('tags', $summary);
        $this->assertArrayNotHasKey('scheduled', $summary);
    }

    public function test_conversation_summary_dates_are_utc()
    {
        
        $conversation = Fixtures::conversation(Fixtures::mailbox(), Fixtures::customer(), ['last_reply_at' => '2026-03-04 05:06:07']);
        $conversation = $conversation->fresh();

        $expected = gmdate('Y-m-d\TH:i:s\Z', $conversation->last_reply_at->getTimestamp());

        $this->assertSame($expected, Presenter::conversationSummary($conversation)['last_reply_at']);
    }

    public function test_unassigned_conversation_without_reply_date()
    {
        $conversation = Fixtures::conversation(Fixtures::mailbox(), Fixtures::customer());
        \DB::table('conversations')->where('id', $conversation->id)->update(['last_reply_at' => null]);

        $summary = Presenter::conversationSummary($conversation->fresh());

        $this->assertNull($summary['assignee_id']);
        $this->assertNull($summary['last_reply_at']);
    }

    public function test_thread()
    {
        $customer = Fixtures::customer('sender@example.test');
        $conversation = Fixtures::conversation(Fixtures::mailbox(), $customer);
        $thread = Thread::where('conversation_id', $conversation->id)->first();
        $thread->body = '<p>Hello <b>there</b></p>';
        $thread->to = json_encode(['support@example.test']);
        $thread->cc = json_encode(['cc@example.test']);
        $thread->save();
        \DB::table('attachments')->insert([
            'thread_id' => $thread->id, 'file_name' => 'log.txt', 'mime_type' => 'text/plain',
            'type' => 0, 'size' => 12, 'embedded' => false, 'file_dir' => '1/2',
        ]);
        \DB::table('attachments')->insert([
            'thread_id' => $thread->id, 'file_name' => 'inline.png', 'mime_type' => 'image/png',
            'type' => 0, 'size' => 99, 'embedded' => true, 'file_dir' => '1/2',
        ]);

        $presented = Presenter::thread($thread->fresh());

        $this->assertSame((int) $thread->id, $presented['id']);
        $this->assertSame('customer', $presented['type']);
        $this->assertSame('published', $presented['state']);
        $this->assertSame('sender@example.test', $presented['from']);
        $this->assertSame(['support@example.test'], $presented['to']);
        $this->assertSame(['cc@example.test'], $presented['cc']);
        $this->assertSame([], $presented['bcc']);
        $this->assertStringContainsStringIgnoringCase('hello there', $presented['body_text']);
        $this->assertStringNotContainsString('<', $presented['body_text']);
        $this->assertSame((int) $customer->id, $presented['created_by']);
        $this->assertSame('customer', $presented['created_by_type']);
        $this->assertMatchesRegularExpression('/Z$/', $presented['created_at']);
        $this->assertCount(1, $presented['attachments']);
        $this->assertSame('log.txt', $presented['attachments'][0]['file_name']);
        $this->assertSame('text/plain', $presented['attachments'][0]['mime_type']);
        $this->assertSame(12, $presented['attachments'][0]['size']);
        $this->assertArrayNotHasKey('draft_choices', $presented);
    }

    public function test_thread_created_by_a_user_and_draft_state()
    {
        $user = Fixtures::user();
        $conversation = Fixtures::conversation(Fixtures::mailbox([], [$user]), Fixtures::customer());
        $thread = Thread::where('conversation_id', $conversation->id)->first();
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->state = Thread::STATE_DRAFT;
        $thread->created_by_user_id = $user->id;
        $thread->created_by_customer_id = null;
        $thread->save();

        $presented = Presenter::thread($thread->fresh());

        $this->assertSame('message', $presented['type']);
        $this->assertSame('draft', $presented['state']);
        $this->assertSame((int) $user->id, $presented['created_by']);
        $this->assertSame('user', $presented['created_by_type']);
        $this->assertEquals(new \stdClass(), $presented['draft_choices']);
        $this->assertSame('{}', json_encode($presented['draft_choices']));

        DraftChoices::store($thread, ['status' => Conversation::STATUS_CLOSED, 'assignee_id' => 0]);

        $this->assertSame(
            ['status' => Conversation::STATUS_CLOSED, 'assignee_id' => 0],
            Presenter::thread($thread->fresh())['draft_choices']
        );
    }

    public function test_invalid_utf8_never_breaks_json_encoding()
    {
        $broken = "Caf\xC3\x28 \xFF";
        $customer = Fixtures::customer();
        $customer->first_name = $broken;
        $customer->save();
        $conversation = Fixtures::conversation(Fixtures::mailbox(), $customer, ['subject' => $broken, 'preview' => $broken]);
        $thread = Thread::where('conversation_id', $conversation->id)->first();
        $thread->body = '<p>'.$broken.'</p>';
        $thread->from = $broken;
        $thread->to = json_encode(['ok@example.test']);
        $thread->save();

        $presented = [
            'customer'     => Presenter::customer($customer->fresh()),
            'summary'      => Presenter::conversationSummary($conversation->fresh()),
            'thread'       => Presenter::thread($thread->fresh()),
        ];

        $this->assertNotFalse(json_encode($presented), json_last_error_msg());
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_companion_modules_follow_the_activation_filter()
    {
        $this->assertFalse(CompanionModules::isActive('notinstalled'));
        $baseline = CompanionModules::isActive(CompanionModules::SEND_LATER);

        $callback = function ($active, $alias) {
            return $alias === 'notinstalled' ? true : $active;
        };
        \Eventy::addFilter('mcpforfreescout.companion_active', $callback, 20, 2);
        try {
            $this->assertTrue(CompanionModules::isActive('notinstalled'));
            $this->assertSame($baseline, CompanionModules::isActive(CompanionModules::SEND_LATER));
        } finally {
            \Eventy::removeFilter('mcpforfreescout.companion_active', $callback, 20);
        }
    }
}
