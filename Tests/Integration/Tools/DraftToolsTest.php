<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\Conversation;
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
use Modules\McpForFreescout\Tools\Drafts\UpdateDraftTool;

class DraftToolsTest extends TestCase
{
    private const ALIAS = 'sales@example.test';

    private $user;
    private $colleague;
    private $mailbox;
    private $customer;
    private $conversation;
    private $jobsAtStart;
    private $sentMessagesAtStart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Fixtures::user();
        $this->colleague = Fixtures::user();
        $this->mailbox = Fixtures::mailbox(['aliases' => self::ALIAS, 'aliases_reply' => true], [$this->user, $this->colleague]);
        $this->customer = Fixtures::customer('customer@example.test');
        $this->conversation = Fixtures::conversation($this->mailbox, $this->customer);

        $this->jobsAtStart = $this->jobCount();
        $this->sentMessagesAtStart = $this->publishedMessageCount();
    }

    /**
     * The central safety rule, checked after every test of this class: draft tools
     * never queue a job and never publish a message.
     */
    protected function assertPostConditions(): void
    {
        $this->assertSame($this->jobsAtStart, $this->jobCount(), 'A draft tool queued a job.');
        $this->assertSame($this->sentMessagesAtStart, $this->publishedMessageCount(), 'A draft tool published a message.');

        parent::assertPostConditions();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    public function test_reply_creates_draft_and_queues_nothing()
    {
        $jobs = $this->jobCount();

        $result = (new ReplyToConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => "Hello,\nwe are on it.",
            'from_alias'      => self::ALIAS,
            'cc'              => ['Colleague@Example.test'],
        ], $this->user);

        $this->assertSame('draft', $result['state']);
        $this->assertSame((int) $this->conversation->id, $result['conversation_id']);

        $draft = Thread::find($result['draft_id']);
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->state);
        $this->assertSame(Thread::TYPE_MESSAGE, (int) $draft->type);
        $this->assertSame(self::ALIAS, $draft->from);
        $this->assertSame(['customer@example.test'], $draft->getToArray());
        $this->assertSame(['colleague@example.test'], $draft->getCcArray());
        $this->assertSame("Hello,<br />\nwe are on it.", $draft->body);
        $this->assertSame((int) $this->user->id, (int) $draft->created_by_user_id);

        $this->assertSame($jobs, $this->jobCount());
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $this->conversation->fresh()->state);
        $this->assertSame(1, $this->publishedThreads($this->conversation));
    }

    public function test_reply_goes_to_an_explicit_single_recipient()
    {
        $result = (new ReplyToConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Hi',
            'to'              => ['Other@example.test'],
        ], $this->user);

        $this->assertIsDraft($result['draft_id']);
        $this->assertSame(['other@example.test'], Thread::find($result['draft_id'])->getToArray());

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('exactly one address');
        (new ReplyToConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Hi',
            'to'              => ['a@example.test', 'b@example.test'],
        ], $this->user);
    }

    public function test_reply_refuses_trashed_and_draft_conversations()
    {
        $this->conversation->forceFill(['state' => Conversation::STATE_DELETED])->save();

        try {
            (new ReplyToConversationTool())->handle(['conversation_id' => $this->conversation->id, 'body' => 'Hi'], $this->user);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('restore_conversation', $e->getMessage());
        }

        $this->conversation->forceFill(['state' => Conversation::STATE_DRAFT])->save();

        $this->expectException(ToolError::class);
        (new ReplyToConversationTool())->handle(['conversation_id' => $this->conversation->id, 'body' => 'Hi'], $this->user);
    }

    public function test_reply_rejects_invalid_recipients()
    {
        foreach (['not-an-email', 'Jo <jo@example.test>', ''] as $bad) {
            try {
                (new ReplyToConversationTool())->handle([
                    'conversation_id' => $this->conversation->id,
                    'body'            => 'Hi',
                    'cc'              => [$bad],
                ], $this->user);
                $this->fail('Expected a ToolError for "'.$bad.'".');
            } catch (ToolError $e) {
                $this->assertStringContainsString('invalid email address', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->draftCount());
    }

    public function test_create_conversation_creates_draft_conversation()
    {
        $jobs = $this->jobCount();

        $result = (new CreateConversationTool())->handle([
            'mailbox_id' => $this->mailbox->id,
            'to'         => ['new@example.test', 'second@example.test'],
            'cc'         => ['copy@example.test'],
            'subject'    => '  Welcome  ',
            'body'       => 'Welcome aboard',
            'from_alias' => self::ALIAS,
        ], $this->user);

        $this->assertSame('draft', $result['state']);

        $conversation = Conversation::find($result['conversation_id']);
        $this->assertSame(Conversation::STATE_DRAFT, (int) $conversation->state);
        $this->assertSame('Welcome', $conversation->subject);
        $this->assertSame((int) $this->mailbox->id, (int) $conversation->mailbox_id);
        $this->assertSame(Conversation::TYPE_EMAIL, (int) $conversation->type);

        $draft = Thread::find($result['draft_id']);
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->state);
        $this->assertSame(['new@example.test', 'second@example.test'], $draft->getToArray());
        $this->assertSame(['copy@example.test'], $draft->getCcArray());
        $this->assertSame(self::ALIAS, $draft->from);
        $this->assertSame('Welcome aboard', $draft->body);
        $this->assertSame(1, Thread::where('conversation_id', $conversation->id)->count());

        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_create_conversation_validates_arguments()
    {
        $valid = ['mailbox_id' => $this->mailbox->id, 'to' => ['a@example.test'], 'subject' => 'S', 'body' => 'B'];
        $cases = [
            'to is required'           => [array_diff_key($valid, ['to' => 1]), 'Argument "to" is required'],
            'to must not be empty'     => [array_merge($valid, ['to' => []]), 'at least one email'],
            'subject is required'      => [array_diff_key($valid, ['subject' => 1]), 'Argument "subject" is required'],
            'subject is limited'       => [array_merge($valid, ['subject' => str_repeat('a', 999)]), 'at most 998'],
            'body is required'         => [array_diff_key($valid, ['body' => 1]), 'Argument "body" is required'],
        ];

        foreach ($cases as $name => list($arguments, $message)) {
            try {
                (new CreateConversationTool())->handle($arguments, $this->user);
                $this->fail('Expected a ToolError: '.$name);
            } catch (ToolError $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $name);
            }
        }
        $this->assertSame(0, Conversation::where('state', Conversation::STATE_DRAFT)->count());
    }

    public function test_rejects_unknown_from_alias()
    {
        $threads = Thread::count();

        try {
            (new ReplyToConversationTool())->handle([
                'conversation_id' => $this->conversation->id,
                'body'            => 'Hi',
                'from_alias'      => 'stranger@example.test',
            ], $this->user);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('from_alias must be one of', $e->getMessage());
            $this->assertStringContainsString(self::ALIAS, $e->getMessage());
        }

        // A listed alias is refused as well once replying from aliases is switched off.
        $this->mailbox->forceFill(['aliases_reply' => false])->save();
        try {
            (new CreateConversationTool())->handle([
                'mailbox_id' => $this->mailbox->id,
                'to'         => ['a@example.test'],
                'subject'    => 'S',
                'body'       => 'B',
                'from_alias' => self::ALIAS,
            ], $this->user);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('disabled for this mailbox', $e->getMessage());
        }

        $this->assertSame($threads, Thread::count());
    }

    public function test_mailbox_address_as_from_alias_uses_the_default_sender()
    {
        $result = (new ReplyToConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Hi',
            'from_alias'      => strtoupper($this->mailbox->email),
        ], $this->user);

        $this->assertNull(Thread::find($result['draft_id'])->from);
        $this->assertIsDraft($result['draft_id']);
    }

    public function test_cannot_draft_in_inaccessible_conversation()
    {
        $outsider = Fixtures::user();
        $threads = Thread::count();

        try {
            (new ReplyToConversationTool())->handle(['conversation_id' => $this->conversation->id, 'body' => 'Hi'], $outsider);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertSame('Conversation not found or not accessible.', $e->getMessage());
        }

        try {
            (new CreateConversationTool())->handle([
                'mailbox_id' => $this->mailbox->id,
                'to'         => ['a@example.test'],
                'subject'    => 'S',
                'body'       => 'B',
            ], $outsider);
            $this->fail('Expected a ToolError.');
        } catch (ToolError $e) {
            $this->assertSame('Mailbox not found or not accessible.', $e->getMessage());
        }

        $draft = $this->draftReply();
        foreach ([new UpdateDraftTool(), new DiscardDraftTool()] as $tool) {
            try {
                $tool->handle(['draft_id' => $draft->id, 'body' => 'Changed'], $outsider);
                $this->fail('Expected a ToolError.');
            } catch (ToolError $e) {
                $this->assertSame('Draft not found or not accessible.', $e->getMessage());
            }
        }

        $this->assertSame($threads + 1, Thread::count());
        $this->assertSame('Original', strip_tags($draft->fresh()->body));
    }

    public function test_choices_are_stored_in_module_meta()
    {
        $result = (new ReplyToConversationTool())->handle([
            'conversation_id'     => $this->conversation->id,
            'body'                => 'Hi',
            'status_after_send'   => 'closed',
            'assignee_after_send' => $this->colleague->id,
        ], $this->user);

        $expected = ['status' => Conversation::STATUS_CLOSED, 'assignee_id' => (int) $this->colleague->id];
        $this->assertSame($expected, $result['draft_choices']);
        $this->assertIsDraft($result['draft_id']);

        $draft = Thread::find($result['draft_id']);
        $this->assertSame($expected, DraftChoices::get($draft));
        $this->assertSame($expected, $draft->getMeta(DraftChoices::META_KEY));
        // Nothing is applied to the conversation before the draft is sent.
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $this->conversation->fresh()->status);
        $this->assertNull($this->conversation->fresh()->user_id);

        $result = (new CreateConversationTool())->handle([
            'mailbox_id'          => $this->mailbox->id,
            'to'                  => ['a@example.test'],
            'subject'             => 'S',
            'body'                => 'B',
            'assignee_after_send' => 0,
        ], $this->user);
        $this->assertSame(['assignee_id' => 0], DraftChoices::get(Thread::find($result['draft_id'])));
        $this->assertIsDraft($result['draft_id']);
    }

    public function test_draft_choices_merge_remove_and_clear()
    {
        $draft = $this->draftReply();
        $this->assertSame([], DraftChoices::get($draft));
        $this->assertNull($draft->fresh()->getMeta(DraftChoices::META_KEY));

        DraftChoices::store($draft, ['signature_id' => 3, 'status' => Conversation::STATUS_PENDING, 'unknown' => 1]);
        DraftChoices::store($draft, ['status' => Conversation::STATUS_CLOSED, 'saved_reply_id' => 5]);
        $this->assertSame(
            ['signature_id' => 3, 'status' => Conversation::STATUS_CLOSED, 'saved_reply_id' => 5],
            DraftChoices::get($draft->fresh())
        );

        DraftChoices::store($draft, ['signature_id' => null]);
        $this->assertSame(['status' => Conversation::STATUS_CLOSED, 'saved_reply_id' => 5], DraftChoices::get($draft->fresh()));

        DraftChoices::clear($draft);
        $this->assertSame([], DraftChoices::get($draft->fresh()));
        $this->assertNull($draft->fresh()->getMeta(DraftChoices::META_KEY));
    }

    public function test_update_draft_clears_each_stored_choice_with_null()
    {
        $all = ['signature_id' => 3, 'status' => Conversation::STATUS_PENDING, 'assignee_id' => (int) $this->colleague->id, 'saved_reply_id' => 5];
        $draft = $this->draftReply(['body' => 'Keep this text']);
        DraftChoices::store($draft, $all);

        $arguments = ['signature_id' => 'signature_id', 'status' => 'status_after_send', 'assignee_id' => 'assignee_after_send', 'saved_reply_id' => 'saved_reply_id'];
        $expected = $all;
        foreach ($arguments as $choice => $argument) {
            unset($expected[$choice]);

            $result = (new UpdateDraftTool())->handle(['draft_id' => $draft->id, $argument => null], $this->user);

            $this->assertSame($expected, (array) $result['draft_choices'], $argument);
            $this->assertSame($expected, DraftChoices::get(Thread::find($draft->id)), $argument);
        }

        $this->assertSame('Keep this text', trim(Thread::find($draft->id)->body));
        $this->assertNull(Thread::find($draft->id)->getMeta(DraftChoices::META_KEY));
    }

    public function test_update_draft_clearing_works_alongside_other_changes_and_unassign_zero_is_not_clearing()
    {
        $draft = $this->draftReply(['status_after_send' => 'closed', 'assignee_after_send' => 0]);

        $result = (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'status_after_send' => null, 'body' => 'New text'], $this->user);

        $this->assertSame(['assignee_id' => 0], $result['draft_choices']);
        $this->assertSame('New text', trim(Thread::find($draft->id)->body));
    }

    public function test_a_choice_can_be_cleared_while_its_companion_module_is_inactive()
    {
        $draft = $this->draftReply();
        DraftChoices::store($draft, ['signature_id' => 3, 'saved_reply_id' => 5, 'status' => Conversation::STATUS_CLOSED]);

        $this->withCompanions(false, function () use ($draft) {
            (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'signature_id' => null], $this->user);
            (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'saved_reply_id' => null], $this->user);
        });

        $this->assertSame(['status' => Conversation::STATUS_CLOSED], DraftChoices::get(Thread::find($draft->id)));
    }

    public function test_null_means_not_given_when_drafting()
    {
        $result = (new ReplyToConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Hi',
            'signature_id'    => null,
            'status_after_send' => null,
        ], $this->user);

        $this->assertSame([], DraftChoices::get(Thread::find($result['draft_id'])));
        $this->assertEquals(new \stdClass(), $result['draft_choices']);
    }

    public function test_only_update_draft_offers_null_for_the_choices()
    {
        $nullable = function ($tool) {
            $properties = $tool->inputSchema()['properties'];

            return [
                $properties['signature_id']['type'],
                $properties['status_after_send']['type'],
                $properties['assignee_after_send']['type'],
                $properties['saved_reply_id']['type'],
            ];
        };

        $this->assertSame([['integer', 'null'], ['string', 'null'], ['integer', 'null'], ['integer', 'null']], $nullable(new UpdateDraftTool()));
        $this->assertSame(['integer', 'string', 'integer', 'integer'], $nullable(new ReplyToConversationTool()));
    }

    public function test_rejects_invalid_choices()
    {
        $disabled = Fixtures::user(['status' => User::STATUS_DISABLED]);
        $this->mailbox->users()->attach($disabled->id);
        $outsider = Fixtures::user();
        $base = ['conversation_id' => $this->conversation->id, 'body' => 'Hi'];

        $cases = [
            'status'              => [['status_after_send' => 'spam'], 'status_after_send" must be one of: active, pending, closed'],
            'assignee not found'  => [['assignee_after_send' => 999999], 'assignee_after_send'],
            'assignee no access'  => [['assignee_after_send' => $outsider->id], 'assignee_after_send'],
            'assignee disabled'   => [['assignee_after_send' => $disabled->id], 'assignee_after_send'],
            'assignee negative'   => [['assignee_after_send' => -1], 'assignee_after_send'],
        ];

        foreach ($cases as $name => list($extra, $message)) {
            try {
                (new ReplyToConversationTool())->handle(array_merge($base, $extra), $this->user);
                $this->fail('Expected a ToolError: '.$name);
            } catch (ToolError $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $name);
            }
        }
        $this->assertSame(0, $this->draftCount());
    }

    public function test_companion_choices_need_their_module()
    {
        $this->withCompanions(false, function () {
            foreach (['signature_id' => 'Custom Mailbox Signatures module', 'saved_reply_id' => 'Saved Replies module'] as $key => $message) {
                try {
                    (new ReplyToConversationTool())->handle([
                        'conversation_id' => $this->conversation->id,
                        'body'            => 'Hi',
                        $key              => 1,
                    ], $this->user);
                    $this->fail('Expected a ToolError for '.$key);
                } catch (ToolError $e) {
                    $this->assertSame($key.' requires the '.$message.'.', $e->getMessage());
                }
            }
        });
        $this->assertSame(0, $this->draftCount());
    }

    public function test_update_draft_changes_body_and_merges_choices()
    {
        $jobs = $this->jobCount();
        $draft = $this->draftReply(['status_after_send' => 'pending', 'cc' => ['copy@example.test'], 'from_alias' => self::ALIAS]);
        $this->assertSame(['status' => Conversation::STATUS_PENDING], DraftChoices::get($draft));

        $result = (new UpdateDraftTool())->handle([
            'draft_id'            => $draft->id,
            'body'                => 'Updated text',
            'assignee_after_send' => $this->colleague->id,
        ], $this->user);

        $this->assertSame((int) $draft->id, $result['draft_id']);
        $this->assertSame('draft', $result['state']);

        $fresh = $draft->fresh();
        $this->assertSame(Thread::STATE_DRAFT, (int) $fresh->state);
        $this->assertSame('Updated text', $fresh->body);
        // What was not given stays as it was.
        $this->assertSame(['customer@example.test'], $fresh->getToArray());
        $this->assertSame(['copy@example.test'], $fresh->getCcArray());
        $this->assertSame(self::ALIAS, $fresh->from);
        $this->assertSame(
            ['status' => Conversation::STATUS_PENDING, 'assignee_id' => (int) $this->colleague->id],
            DraftChoices::get($fresh)
        );

        // Only the choices change: the text is kept.
        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'status_after_send' => 'closed', 'cc' => []], $this->user);
        $fresh = $draft->fresh();
        $this->assertSame('Updated text', $fresh->body);
        $this->assertSame([], $fresh->getCcArray());
        $this->assertSame(Conversation::STATUS_CLOSED, DraftChoices::get($fresh)['status']);

        $this->assertSame(1, $this->draftCount());
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_update_draft_of_a_new_conversation_can_change_the_subject_and_recipients()
    {
        $created = (new CreateConversationTool())->handle([
            'mailbox_id' => $this->mailbox->id,
            'to'         => ['a@example.test'],
            'subject'    => 'Old subject',
            'body'       => 'Old body',
        ], $this->user);

        (new UpdateDraftTool())->handle([
            'draft_id' => $created['draft_id'],
            'subject'  => 'New subject',
            'to'       => ['b@example.test', 'c@example.test'],
        ], $this->user);

        $conversation = Conversation::find($created['conversation_id']);
        $draft = Thread::find($created['draft_id']);
        $this->assertSame(Conversation::STATE_DRAFT, (int) $conversation->state);
        $this->assertSame('New subject', $conversation->subject);
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->state);
        $this->assertSame(['b@example.test', 'c@example.test'], $draft->getToArray());
        $this->assertSame('Old body', $draft->body);
        $this->assertSame(1, Thread::where('conversation_id', $conversation->id)->count());
        $this->assertIsDraft($created['draft_id']);
    }

    public function test_update_draft_refuses_subject_on_a_reply_and_empty_updates()
    {
        $draft = $this->draftReply();

        foreach ([[['subject' => 'New'], 'only be changed on the draft of a new conversation'], [[], 'Give at least one of']] as list($extra, $message)) {
            try {
                (new UpdateDraftTool())->handle(array_merge(['draft_id' => $draft->id], $extra), $this->user);
                $this->fail('Expected a ToolError.');
            } catch (ToolError $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertIsDraft($draft->id);
    }

    public function test_reply_copies_the_conversation_cc_like_the_reply_form()
    {
        $this->conversation->setCc([
            'copy@example.test',
            $this->mailbox->email,
            self::ALIAS,
            'customer@example.test',
        ]);
        $this->conversation->save();

        $result = (new ReplyToConversationTool())->handle(['conversation_id' => $this->conversation->id, 'body' => 'Hi'], $this->user);

        $this->assertIsDraft($result['draft_id']);
        $this->assertSame(['copy@example.test'], Thread::find($result['draft_id'])->getCcArray());
    }

    public function test_reply_adds_a_cc_person_who_wrote_last()
    {
        $other = Fixtures::customer('copy@example.test');
        $thread = new Thread();
        $thread->forceFill([
            'conversation_id'        => $this->conversation->id,
            'type'                   => Thread::TYPE_CUSTOMER,
            'state'                  => Thread::STATE_PUBLISHED,
            'body'                   => 'Me too',
            'from'                   => 'copy@example.test',
            'customer_id'            => $other->id,
            'created_by_customer_id' => $other->id,
            'source_via'             => Thread::PERSON_CUSTOMER,
            'source_type'            => Thread::SOURCE_TYPE_EMAIL,
            'created_at'             => date('Y-m-d H:i:s', time() + 60),
        ]);
        $thread->save();

        $result = (new ReplyToConversationTool())->handle(['conversation_id' => $this->conversation->id, 'body' => 'Hi'], $this->user);

        $this->assertSame(['copy@example.test'], Thread::find($result['draft_id'])->getCcArray());
    }

    public function test_explicit_cc_replaces_the_default_and_an_empty_list_clears_it()
    {
        $this->conversation->setCc(['copy@example.test']);
        $this->conversation->save();

        $replaced = (new ReplyToConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => 'One',
            'cc'              => ['other@example.test'],
        ], $this->user);
        $cleared = (new ReplyToConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Two',
            'cc'              => [],
        ], $this->user);

        $this->assertSame(['other@example.test'], Thread::find($replaced['draft_id'])->getCcArray());
        $this->assertSame([], Thread::find($cleared['draft_id'])->getCcArray());
    }

    public function test_update_draft_rejects_published_thread()
    {
        $published = Thread::where('conversation_id', $this->conversation->id)->first();
        $before = $published->body;

        foreach ([new UpdateDraftTool(), new DiscardDraftTool()] as $tool) {
            try {
                $tool->handle(['draft_id' => $published->id, 'body' => 'Changed'], $this->user);
                $this->fail('Expected a ToolError.');
            } catch (ToolError $e) {
                $this->assertStringContainsString('is not a draft', $e->getMessage());
            }
        }

        $this->assertSame($before, $published->fresh()->body);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $published->fresh()->state);
    }

    public function test_draft_tools_refuse_forward_drafts()
    {
        $forward = $this->draftReply();
        $forward->forceFill(['subtype' => Thread::SUBTYPE_FORWARD])->save();

        foreach ([new UpdateDraftTool(), new DiscardDraftTool()] as $tool) {
            try {
                $tool->handle(['draft_id' => $forward->id, 'body' => 'Changed'], $this->user);
                $this->fail('Expected a ToolError.');
            } catch (ToolError $e) {
                $this->assertSame('Forward drafts must be edited and sent from the FreeScout UI.', $e->getMessage());
            }
        }

        $this->assertSame('Original', strip_tags($forward->fresh()->body));
    }

    public function test_discard_draft_removes_it()
    {
        $jobs = $this->jobCount();
        $draft = $this->draftReply();

        $result = (new DiscardDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame(['discarded' => true, 'draft_id' => (int) $draft->id, 'conversation_id' => (int) $this->conversation->id], $result);
        $this->assertNull(Thread::find($draft->id));
        // The conversation and its published thread are kept.
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $this->conversation->fresh()->state);
        $this->assertSame(1, $this->publishedThreads($this->conversation));
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_discarding_the_draft_of_a_new_conversation_removes_the_conversation()
    {
        $created = (new CreateConversationTool())->handle([
            'mailbox_id' => $this->mailbox->id,
            'to'         => ['a@example.test'],
            'subject'    => 'S',
            'body'       => 'B',
        ], $this->user);

        (new DiscardDraftTool())->handle(['draft_id' => $created['draft_id']], $this->user);

        $this->assertNull(Thread::find($created['draft_id']));
        $this->assertNull(Conversation::find($created['conversation_id']));
    }

    public function test_discarding_a_new_conversation_draft_that_holds_published_messages_is_refused()
    {
        $created = (new CreateConversationTool())->handle([
            'mailbox_id' => $this->mailbox->id,
            'to'         => ['a@example.test'],
            'subject'    => 'S',
            'body'       => 'B',
        ], $this->user);
        // A message that ended up in the draft conversation, for example by a merge.
        $published = new Thread();
        $published->forceFill([
            'conversation_id' => $created['conversation_id'],
            'type'            => Thread::TYPE_CUSTOMER,
            'state'           => Thread::STATE_PUBLISHED,
            'body'            => 'Earlier message',
            'customer_id'     => $this->customer->id,
            'source_via'      => Thread::PERSON_CUSTOMER,
            'source_type'     => Thread::SOURCE_TYPE_EMAIL,
        ]);
        $published->save();

        try {
            (new DiscardDraftTool())->handle(['draft_id' => $created['draft_id']], $this->user);
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('would delete them', $e->getMessage());
        }

        $this->assertNotNull(Thread::find($created['draft_id']));
        $this->assertNotNull(Thread::find($published->id));
        $this->assertNotNull(Conversation::find($created['conversation_id']));
    }

    public function test_tools_declare_that_they_only_write_drafts()
    {
        $tools = [new ReplyToConversationTool(), new CreateConversationTool(), new UpdateDraftTool(), new DiscardDraftTool()];

        foreach ($tools as $tool) {
            $annotations = $tool->annotations();
            $this->assertFalse($annotations['readOnlyHint'], $tool->name());
            $this->assertSame($tool instanceof DiscardDraftTool, $annotations['destructiveHint'], $tool->name());
            $this->assertStringContainsStringIgnoringCase('nothing is sent', $tool->description());
        }
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

    private function jobCount(): int
    {
        return \DB::table('jobs')->count();
    }

    private function publishedMessageCount(): int
    {
        return Thread::where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)->count();
    }

    private function assertIsDraft(int $threadId): void
    {
        $thread = Thread::find($threadId);
        $this->assertNotNull($thread, 'The draft is missing.');
        $this->assertSame(Thread::STATE_DRAFT, (int) $thread->state);
        $this->assertSame(Thread::TYPE_MESSAGE, (int) $thread->type);
    }

    private function draftCount(): int
    {
        return Thread::where('state', Thread::STATE_DRAFT)->count();
    }

    private function publishedThreads(Conversation $conversation): int
    {
        return Thread::where('conversation_id', $conversation->id)->where('state', Thread::STATE_PUBLISHED)->count();
    }
}
