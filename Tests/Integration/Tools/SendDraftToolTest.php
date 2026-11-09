<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Drafts\CreateConversationTool;
use Modules\McpForFreescout\Tools\Drafts\DiscardDraftTool;
use Modules\McpForFreescout\Tools\Drafts\ReplyToConversationTool;
use Modules\McpForFreescout\Tools\Drafts\SendDraftTool;
use Modules\McpForFreescout\Tools\Drafts\UpdateDraftTool;

class SendDraftToolTest extends TestCase
{
    private const ALIAS = 'sales@example.test';

    private $user;
    private $colleague;
    private $mailbox;
    private $customer;
    private $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Fixtures::user();
        $this->colleague = Fixtures::user();
        $this->mailbox = Fixtures::mailbox(['aliases' => self::ALIAS, 'aliases_reply' => true], [$this->user, $this->colleague]);
        $this->customer = Fixtures::customer('customer@example.test');
        $this->conversation = Fixtures::conversation($this->mailbox, $this->customer);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    public function test_send_publishes_draft_and_queues_email()
    {
        $draft = $this->draftReply(['from_alias' => self::ALIAS]);
        $emails = $this->customerEmailJobs();
        $published = $this->publishedMessageCount();

        $result = (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame((int) $draft->id, $result['thread_id']);
        $this->assertSame((int) $this->conversation->id, $result['conversation_id']);
        $this->assertSame('published', $result['state']);
        $this->assertSame('pending', $result['status'], 'The mailbox default (pending after a reply) applies.');

        $sent = Thread::find($draft->id);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $sent->state);
        $this->assertSame(Thread::TYPE_MESSAGE, (int) $sent->type);
        $this->assertSame(self::ALIAS, $sent->from);
        $this->assertSame(['customer@example.test'], $sent->getToArray());
        $this->assertSame('Original', strip_tags($sent->body));
        $this->assertSame($published + 1, $this->publishedMessageCount());
        $this->assertGreaterThanOrEqual($emails + 1, $this->customerEmailJobs());
    }

    public function test_send_keeps_current_status_and_assignee_without_choices()
    {
        $this->mailbox->forceFill(['ticket_status' => Mailbox::TICKET_STATUS_KEEP_CURRENT])->save();
        $this->conversation->forceFill(['status' => Conversation::STATUS_CLOSED, 'user_id' => $this->colleague->id])->save();

        $result = (new SendDraftTool())->handle(['draft_id' => $this->draftReply()->id], $this->user);

        $this->assertSame('closed', $result['status']);
        $conversation = $this->conversation->fresh();
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->status);
        $this->assertSame((int) $this->colleague->id, (int) $conversation->user_id);
    }

    public function test_send_uses_the_mailbox_status_after_reply()
    {
        $this->mailbox->forceFill(['ticket_status' => Mailbox::TICKET_STATUS_ACTIVE])->save();

        $result = (new SendDraftTool())->handle(['draft_id' => $this->draftReply()->id], $this->user);

        $this->assertSame('active', $result['status']);
    }

    /**
     * What the reply form preselects in "Assign to" for each mailbox setting.
     *
     * Columns: mailbox setting, current assignee ('none', 'sender', 'colleague'), expected assignee.
     */
    public function assigneeDefaults(): array
    {
        return [
            'anyone, unassigned'               => [Mailbox::TICKET_ASSIGNEE_ANYONE, 'none', 'none'],
            'anyone, assigned elsewhere'       => [Mailbox::TICKET_ASSIGNEE_ANYONE, 'colleague', 'none'],
            'replying, unassigned'             => [Mailbox::TICKET_ASSIGNEE_REPLYING, 'none', 'sender'],
            'replying, assigned elsewhere'     => [Mailbox::TICKET_ASSIGNEE_REPLYING, 'colleague', 'sender'],
            'replying unassigned, unassigned'  => [Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED, 'none', 'sender'],
            'replying unassigned, assigned'    => [Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED, 'colleague', 'colleague'],
            'keep current, unassigned'         => [Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT, 'none', 'none'],
            'keep current, assigned'           => [Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT, 'colleague', 'colleague'],
            'keep current, assigned to sender' => [Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT, 'sender', 'sender'],
        ];
    }

    /**
     * @dataProvider assigneeDefaults
     */
    public function test_send_assigns_like_the_reply_form_by_default(int $setting, string $current, string $expected)
    {
        $users = ['none' => null, 'sender' => (int) $this->user->id, 'colleague' => (int) $this->colleague->id];
        $this->mailbox->forceFill(['ticket_assignee' => $setting])->save();
        $this->conversation->forceFill(['user_id' => $users[$current]])->save();

        (new SendDraftTool())->handle(['draft_id' => $this->draftReply()->id], $this->user);

        $assignee = $this->conversation->fresh()->user_id;
        $this->assertSame($users[$expected], $assignee === null ? null : (int) $assignee);
    }

    public function test_send_unassigns_when_the_current_assignee_cannot_be_selected()
    {
        $this->mailbox->forceFill(['ticket_assignee' => Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT])->save();
        $this->conversation->forceFill(['user_id' => $this->colleague->id])->save();
        $this->mailbox->users()->detach($this->colleague->id);

        (new SendDraftTool())->handle(['draft_id' => $this->draftReply()->id], $this->user);

        $this->assertNull($this->conversation->fresh()->user_id);
    }

    public function test_an_assignee_choice_wins_over_the_mailbox_setting()
    {
        $this->mailbox->forceFill(['ticket_assignee' => Mailbox::TICKET_ASSIGNEE_ANYONE])->save();

        (new SendDraftTool())->handle(['draft_id' => $this->draftReply(['assignee_after_send' => $this->colleague->id])->id], $this->user);

        $this->assertSame((int) $this->colleague->id, (int) $this->conversation->fresh()->user_id);
    }

    public function test_send_does_not_keep_spam_as_the_default_status()
    {
        $this->mailbox->forceFill(['ticket_status' => Mailbox::TICKET_STATUS_KEEP_CURRENT])->save();
        $this->conversation->forceFill(['status' => Conversation::STATUS_SPAM])->save();

        $result = (new SendDraftTool())->handle(['draft_id' => $this->draftReply()->id], $this->user);

        $this->assertSame('active', $result['status']);
    }

    public function test_send_applies_status_and_assignee_choices()
    {
        $draft = $this->draftReply(['status_after_send' => 'closed', 'assignee_after_send' => $this->colleague->id]);

        $result = (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame('closed', $result['status']);
        $conversation = $this->conversation->fresh();
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->status);
        $this->assertSame((int) $this->colleague->id, (int) $conversation->user_id);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) Thread::find($draft->id)->state);
    }

    public function test_send_can_unassign_the_conversation()
    {
        $this->conversation->forceFill(['user_id' => $this->colleague->id])->save();
        $draft = $this->draftReply(['assignee_after_send' => 0]);

        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertNull($this->conversation->fresh()->user_id);
    }

    public function test_send_clears_choices_meta()
    {
        $draft = $this->draftReply(['status_after_send' => 'active', 'assignee_after_send' => $this->colleague->id]);
        $this->assertNotEmpty(DraftChoices::get($draft));

        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame([], DraftChoices::get(Thread::find($draft->id)));
    }

    public function test_core_send_events_clear_the_choices()
    {
        $draft = $this->draftReply(['status_after_send' => 'closed']);
        \Eventy::action('conversation.user_replied', $this->conversation, $draft);
        $this->assertSame([], DraftChoices::get(Thread::find($draft->id)));

        $draft = $this->draftReply(['status_after_send' => 'closed']);
        \Eventy::action('conversation.created_by_user', $this->conversation, $draft);
        $this->assertSame([], DraftChoices::get(Thread::find($draft->id)));
    }

    public function test_send_new_conversation_draft()
    {
        $created = (new CreateConversationTool())->handle([
            'mailbox_id'        => $this->mailbox->id,
            'to'                => ['new@example.test'],
            'cc'                => ['copy@example.test'],
            'subject'           => 'Welcome',
            'body'              => 'Welcome aboard',
            'from_alias'        => self::ALIAS,
            'status_after_send' => 'closed',
            'assignee_after_send' => $this->colleague->id,
        ], $this->user);
        $emails = $this->customerEmailJobs();

        $result = (new SendDraftTool())->handle(['draft_id' => $created['draft_id']], $this->user);

        $this->assertSame($created['draft_id'], $result['thread_id']);
        $this->assertSame($created['conversation_id'], $result['conversation_id']);
        $this->assertSame('published', $result['state']);
        $this->assertSame('closed', $result['status']);

        $conversation = Conversation::find($created['conversation_id']);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $conversation->state);
        $this->assertSame('Welcome', $conversation->subject);
        $this->assertSame('new@example.test', $conversation->customer_email);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->status);
        $this->assertSame((int) $this->colleague->id, (int) $conversation->user_id);

        $thread = Thread::find($created['draft_id']);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $thread->state);
        $this->assertSame(self::ALIAS, $thread->from);
        $this->assertSame(['new@example.test'], $thread->getToArray());
        $this->assertSame(['copy@example.test'], $thread->getCcArray());
        $this->assertSame([], DraftChoices::get($thread));
        $this->assertGreaterThanOrEqual($emails + 1, $this->customerEmailJobs());
    }

    public function test_send_new_conversation_draft_with_several_recipients()
    {
        $created = (new CreateConversationTool())->handle([
            'mailbox_id' => $this->mailbox->id,
            'to'         => ['first@example.test', 'second@example.test'],
            'subject'    => 'Hello both',
            'body'       => 'Hi',
        ], $this->user);

        (new SendDraftTool())->handle(['draft_id' => $created['draft_id']], $this->user);

        $thread = Thread::find($created['draft_id']);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $thread->state);
        $this->assertSame(['first@example.test', 'second@example.test'], $thread->getToArray());
        $this->assertSame(1, Thread::where('conversation_id', $created['conversation_id'])->count());
    }

    public function test_send_keeps_the_attachments_of_the_draft()
    {
        $draft = $this->draftReply();
        $file = $this->attachment($draft, false);
        $embedded = $this->attachment($draft, true);

        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame(Thread::STATE_PUBLISHED, (int) Thread::find($draft->id)->state);
        $this->assertSame((int) $draft->id, (int) \DB::table('attachments')->where('id', $file)->value('thread_id'));
        $this->assertSame((int) $draft->id, (int) \DB::table('attachments')->where('id', $embedded)->value('thread_id'));
        $this->assertTrue((bool) Thread::find($draft->id)->has_attachments);
    }

    public function test_send_draft_refuses_non_draft_threads()
    {
        $draft = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
        $publishedMessage = $draft->id;

        $customerThread = Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_CUSTOMER)->first();
        $note = new Thread();
        $note->forceFill([
            'conversation_id'    => $this->conversation->id,
            'type'               => Thread::TYPE_NOTE,
            'state'              => Thread::STATE_PUBLISHED,
            'status'             => Conversation::STATUS_ACTIVE,
            'body'               => 'Internal',
            'created_by_user_id' => $this->user->id,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
        ])->save();
        $discarded = $this->draftReply();
        (new DiscardDraftTool())->handle(['draft_id' => $discarded->id], $this->user);
        $forward = $this->draftReply();
        $forward->forceFill(['subtype' => Thread::SUBTYPE_FORWARD])->save();

        $jobs = $this->jobCount();
        $published = $this->publishedMessageCount();

        $cases = [
            'published message' => [$publishedMessage, 'is not a draft'],
            'customer thread'   => [$customerThread->id, 'is not a draft'],
            'note'              => [$note->id, 'is not a draft'],
            'discarded draft'   => [$discarded->id, 'Draft not found or not accessible.'],
            'unknown id'        => [999999999, 'Draft not found or not accessible.'],
            'forward draft'     => [$forward->id, 'Forward drafts must be edited and sent from the FreeScout UI.'],
        ];
        foreach ($cases as $name => list($id, $message)) {
            try {
                (new SendDraftTool())->handle(['draft_id' => $id], $this->user);
                $this->fail('Expected a ToolError: '.$name);
            } catch (ToolError $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $name);
            }
        }

        $this->assertSame($jobs, $this->jobCount());
        $this->assertSame($published, $this->publishedMessageCount());
        $this->assertSame(Thread::STATE_DRAFT, (int) $forward->fresh()->state);
    }

    public function test_send_refuses_inaccessible_conversation()
    {
        $draft = $this->draftReply();
        $outsider = Fixtures::user();
        $jobs = $this->jobCount();

        try {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $outsider);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertSame('Draft not found or not accessible.', $e->getMessage());
        }

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_send_refuses_a_trashed_conversation()
    {
        $draft = $this->draftReply();
        $this->conversation->forceFill(['state' => Conversation::STATE_DELETED])->save();
        $jobs = $this->jobCount();

        try {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertSame('The conversation is in the trash. Restore it with restore_conversation before sending.', $e->getMessage());
        }

        $this->assertSame(Conversation::STATE_DELETED, (int) $this->conversation->fresh()->state);
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_send_refuses_the_drafts_of_conversations_that_are_not_email()
    {
        foreach ([Conversation::TYPE_CHAT, Conversation::TYPE_PHONE, Conversation::TYPE_CUSTOM] as $type) {
            $this->conversation->forceFill(['type' => Conversation::TYPE_EMAIL])->save();
            $draft = $this->draftReply();
            $this->conversation->forceFill(['type' => $type])->save();
            $jobs = $this->jobCount();
            $published = $this->publishedMessageCount();

            try {
                (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
                $this->fail('Expected a ToolError for type '.$type);
            } catch (ToolError $e) {
                $this->assertStringContainsString('Only drafts of email conversations can be sent', $e->getMessage());
            }

            $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state, 'type '.$type);
            $this->assertSame($jobs, $this->jobCount(), 'type '.$type);
            $this->assertSame($published, $this->publishedMessageCount(), 'type '.$type);
        }
    }

    public function test_send_revalidates_the_stored_choices()
    {
        $disabled = Fixtures::user();
        $this->mailbox->users()->attach($disabled->id);

        $cases = [
            'assignee disabled'    => [['assignee_id' => $disabled->id], function () use ($disabled) {
                $disabled->forceFill(['status' => User::STATUS_DISABLED])->save();
            }, 'assignee_after_send'],
            'assignee lost access' => [['assignee_id' => $this->colleague->id], function () {
                $this->mailbox->users()->detach($this->colleague->id);
            }, 'assignee_after_send'],
            'assignee unknown'     => [['assignee_id' => 999999999], function () {
            }, 'assignee_after_send'],
            'status is spam'       => [['status' => Conversation::STATUS_SPAM], function () {
            }, 'status_after_send'],
            'status is unknown'    => [['status' => 77], function () {
            }, 'status_after_send'],
        ];

        foreach ($cases as $name => list($choices, $change, $message)) {
            $draft = $this->draftReply();
            DraftChoices::store($draft, $choices);
            $change();
            $jobs = $this->jobCount();

            try {
                (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
                $this->fail('Expected a ToolError: '.$name);
            } catch (ToolError $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $name);
                $this->assertStringContainsString('update_draft', $e->getMessage(), $name);
            }

            $this->assertSame($jobs, $this->jobCount(), $name);
            $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state, $name);
        }
    }

    public function test_send_refuses_a_sender_alias_the_mailbox_no_longer_allows()
    {
        $draft = $this->draftReply(['from_alias' => self::ALIAS]);
        $this->mailbox->forceFill(['aliases' => 'other@example.test'])->save();
        $jobs = $this->jobCount();

        try {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertStringContainsString(self::ALIAS, $e->getMessage());
            $this->assertStringContainsString('from_alias in update_draft', $e->getMessage());
        }

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());

        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'from_alias' => 'other@example.test'], $this->user);
        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
        $this->assertSame('other@example.test', $draft->fresh()->from);
    }

    public function test_send_accepts_the_mailbox_address_as_sender()
    {
        $draft = $this->draftReply();
        $draft->forceFill(['from' => $this->mailbox->email])->save();

        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame(Thread::STATE_PUBLISHED, (int) $draft->fresh()->state);
    }

    public function test_a_corrected_choice_can_be_sent()
    {
        $draft = $this->draftReply();
        DraftChoices::store($draft, ['assignee_id' => $this->colleague->id]);
        $this->mailbox->users()->detach($this->colleague->id);

        try {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('update_draft', $e->getMessage());
        }

        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'assignee_after_send' => 0], $this->user);
        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame(Thread::STATE_PUBLISHED, (int) $draft->fresh()->state);
    }

    public function test_scheduling_needs_the_send_later_module()
    {
        $draft = $this->draftReply();
        $jobs = $this->jobCount();

        $this->withCompanions(false, function () use ($draft) {
            try {
                (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => '2030-01-01T10:00:00Z'], $this->user);
                $this->fail('Expected a ToolError.');
            } catch (ToolError $e) {
                $this->assertSame('Scheduling requires the Send Later module.', $e->getMessage());
            }
        });

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_an_empty_draft_is_not_sent()
    {
        $draft = $this->draftReply();
        $draft->forceFill(['body' => ''])->save();
        $jobs = $this->jobCount();

        try {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertStringContainsStringIgnoringCase('body', $e->getMessage());
        }

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_tool_declares_that_it_sends_email()
    {
        $tool = new SendDraftTool();

        $this->assertSame('send_draft', $tool->name());
        $this->assertSame(
            ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => true],
            $tool->annotations()
        );
        $this->assertStringContainsStringIgnoringCase('real email', $tool->description());
        $this->assertStringContainsString('FreeScout', $tool->description());
        $this->assertSame(['draft_id'], $tool->inputSchema()['required']);
    }

    public function test_tool_is_registered()
    {
        $this->assertInstanceOf(SendDraftTool::class, app(ToolRegistry::class)->find('send_draft', $this->user));
    }

    /**
     * Create a draft reply to the fixture conversation.
     */
    private function draftReply(array $extra = []): Thread
    {
        $result = (new ReplyToConversationTool())->handle(array_merge([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Original',
        ], $extra), $this->user);

        return Thread::find($result['draft_id']);
    }

    /**
     * Add an attachment row to a draft; the file itself is not needed.
     */
    private function attachment(Thread $draft, bool $embedded): int
    {
        return (int) \DB::table('attachments')->insertGetId([
            'thread_id'  => $draft->id,
            'user_id'    => $this->user->id,
            'file_dir'   => 'x/y/z',
            'file_name'  => $embedded ? 'image.png' : 'report.pdf',
            'mime_type'  => $embedded ? 'image/png' : 'application/pdf',
            'type'       => 2,
            'size'       => 10,
            'embedded'   => $embedded ? 1 : 0,
        ]);
    }

    private function jobCount(): int
    {
        return \DB::table('jobs')->count();
    }

    private function customerEmailJobs(): int
    {
        return \DB::table('jobs')->where('payload', 'like', '%SendReplyToCustomer%')->count();
    }

    private function publishedMessageCount(): int
    {
        return Thread::where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)->count();
    }
}
