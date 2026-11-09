<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\BodyFormatter;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Conversations\AddNoteTool;
use Modules\McpForFreescout\Tools\Conversations\DeleteConversationTool;
use Modules\McpForFreescout\Tools\Conversations\RestoreConversationTool;
use Modules\McpForFreescout\Tools\Conversations\UpdateConversationTool;

class ConversationWriteToolsTest extends TestCase
{
    private $user;
    private $colleague;
    private $mailbox;
    private $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Fixtures::user();
        $this->colleague = Fixtures::user();
        $this->mailbox = Fixtures::mailbox([], [$this->user, $this->colleague]);
        $this->conversation = Fixtures::conversation($this->mailbox, Fixtures::customer());
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    public function test_change_status_and_assignee_create_history_as_user()
    {
        $result = (new UpdateConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'status'          => 'closed',
            'assignee_id'     => $this->colleague->id,
        ], $this->user);

        $this->assertSame('closed', $result['status']);
        $this->assertSame((int) $this->colleague->id, $result['assignee_id']);

        $fresh = $this->conversation->fresh();
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $fresh->status);
        $this->assertSame((int) $this->colleague->id, (int) $fresh->user_id);

        $actions = Thread::where('conversation_id', $this->conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)
            ->where('created_by_user_id', $this->user->id)
            ->pluck('action_type')
            ->all();
        $this->assertEqualsCanonicalizing(
            [Thread::ACTION_TYPE_STATUS_CHANGED, Thread::ACTION_TYPE_USER_CHANGED],
            array_map('intval', $actions)
        );
    }

    public function test_update_changes_subject_and_unassigns_with_zero()
    {
        $this->conversation->forceFill(['user_id' => $this->colleague->id])->save();

        $result = (new UpdateConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'assignee_id'     => 0,
            'subject'         => '  New subject  ',
        ], $this->user);

        $this->assertNull($result['assignee_id']);
        $this->assertSame('New subject', $result['subject']);
        $this->assertNull($this->conversation->fresh()->user_id);
    }

    public function test_update_with_values_already_set_changes_nothing()
    {
        $before = Thread::where('conversation_id', $this->conversation->id)->count();

        $result = (new UpdateConversationTool())->handle([
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'assignee_id'     => 0,
        ], $this->user);

        $this->assertSame('active', $result['status']);
        $this->assertSame($before, Thread::where('conversation_id', $this->conversation->id)->count());
    }

    public function test_update_requires_at_least_one_change()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('status, assignee_id or subject');

        (new UpdateConversationTool())->handle(['conversation_id' => $this->conversation->id], $this->user);
    }

    public function test_update_rejects_unknown_status()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('active, pending, closed, spam');

        (new UpdateConversationTool())->handle(['conversation_id' => $this->conversation->id, 'status' => 'done'], $this->user);
    }

    public function test_update_rejects_assignee_without_mailbox_access_and_changes_nothing()
    {
        $outsider = Fixtures::user();

        foreach ([$outsider->id, 999999999] as $assigneeId) {
            try {
                (new UpdateConversationTool())->handle([
                    'conversation_id' => $this->conversation->id,
                    'status'          => 'closed',
                    'assignee_id'     => $assigneeId,
                ], $this->user);
                $this->fail('A ToolError was expected for an assignee without access to the mailbox.');
            } catch (ToolError $e) {
                $this->assertStringContainsString('assignee_id', $e->getMessage());
            }
        }

        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $this->conversation->fresh()->status);
    }

    public function test_update_rejects_a_disabled_user_as_assignee_and_changes_nothing()
    {
        $disabled = Fixtures::user(['status' => User::STATUS_DISABLED]);
        $this->mailbox->users()->attach($disabled->id);

        try {
            (new UpdateConversationTool())->handle([
                'conversation_id' => $this->conversation->id,
                'status'          => 'closed',
                'assignee_id'     => $disabled->id,
            ], $this->user);
            $this->fail('A ToolError was expected for a disabled assignee.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('active user', $e->getMessage());
        }

        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $this->conversation->fresh()->status);
        $this->assertNull($this->conversation->fresh()->user_id);
    }

    public function test_update_hides_inaccessible_conversation()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Conversation not found or not accessible.');

        (new UpdateConversationTool())->handle(
            ['conversation_id' => $this->conversation->id, 'status' => 'closed'],
            Fixtures::user()
        );
    }

    public function test_add_note_is_published_note_and_queues_no_customer_email()
    {
        $before = $this->customerEmailJobs();

        $result = (new AddNoteTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Called the customer back.',
        ], $this->user);

        $this->assertSame((int) $this->conversation->id, $result['conversation_id']);
        $note = Thread::find($result['thread_id']);
        $this->assertNotNull($note);
        $this->assertSame((int) $this->conversation->id, (int) $note->conversation_id);
        $this->assertSame(Thread::TYPE_NOTE, (int) $note->type);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $note->state);
        $this->assertSame((int) $this->user->id, (int) $note->created_by_user_id);
        $this->assertSame($before, $this->customerEmailJobs());

        $fresh = $this->conversation->fresh();
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $fresh->status);
        $this->assertNull($fresh->user_id);
    }

    public function test_add_note_keeps_status_and_assignee()
    {
        $this->conversation->forceFill(['user_id' => $this->colleague->id, 'status' => Conversation::STATUS_PENDING])->save();

        (new AddNoteTool())->handle(['conversation_id' => $this->conversation->id, 'body' => 'FYI'], $this->user);

        $fresh = $this->conversation->fresh();
        $this->assertSame(Conversation::STATUS_PENDING, (int) $fresh->status);
        $this->assertSame((int) $this->colleague->id, (int) $fresh->user_id);
    }

    public function test_plain_text_body_is_escaped_and_line_broken()
    {
        $result = (new AddNoteTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => "Line one 5 < 6 & more\r\nLine two",
        ], $this->user);

        $body = Thread::find($result['thread_id'])->body;
        $this->assertStringContainsString('Line one 5 &lt; 6 &amp; more<br', $body);
        $this->assertStringContainsString('Line two', $body);
        $this->assertStringNotContainsString("\r", $body);

        $this->assertSame("a &lt; b<br />\nc", BodyFormatter::toHtml("a < b\r\nc"));
        $this->assertSame('say &quot;hi&quot; &amp; &#039;bye&#039;', BodyFormatter::toHtml('say "hi" & \'bye\''));
        $this->assertSame('<p>Already <b>HTML</b></p>', BodyFormatter::toHtml('<p>Already <b>HTML</b></p>'));
    }

    public function test_add_note_refuses_trashed_and_draft_conversations()
    {
        $cases = [
            Conversation::STATE_DELETED => 'restore_conversation',
            Conversation::STATE_DRAFT   => 'draft conversation',
        ];

        foreach ($cases as $state => $expectedMessage) {
            $conversation = Fixtures::conversation($this->mailbox, Fixtures::customer(), ['state' => $state]);
            $threadsBefore = Thread::where('conversation_id', $conversation->id)->count();

            try {
                (new AddNoteTool())->handle(['conversation_id' => $conversation->id, 'body' => 'Note'], $this->user);
                $this->fail('A ToolError was expected for state '.$state.'.');
            } catch (ToolError $e) {
                $this->assertStringContainsString($expectedMessage, $e->getMessage());
            }

            $this->assertSame($state, (int) $conversation->fresh()->state);
            $this->assertSame($threadsBefore, Thread::where('conversation_id', $conversation->id)->count());
        }
    }

    public function test_add_note_requires_a_body()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('body');

        (new AddNoteTool())->handle(['conversation_id' => $this->conversation->id, 'body' => '  '], $this->user);
    }

    public function test_delete_moves_to_trash_and_restore_brings_back()
    {
        $this->user->addPermission(User::PERM_DELETE_CONVERSATIONS);

        $deleted = (new DeleteConversationTool())->handle(['conversation_id' => $this->conversation->id], $this->user);

        $this->assertSame('deleted', $deleted['state']);
        $this->assertSame(Conversation::STATE_DELETED, (int) $this->conversation->fresh()->state);
        $this->assertTrue(Thread::where('conversation_id', $this->conversation->id)
            ->where('action_type', Thread::ACTION_TYPE_DELETED_TICKET)
            ->where('created_by_user_id', $this->user->id)
            ->exists());

        $restored = (new RestoreConversationTool())->handle(['conversation_id' => $this->conversation->id], $this->user);

        $this->assertSame('published', $restored['state']);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $this->conversation->fresh()->state);
        $this->assertTrue(Thread::where('conversation_id', $this->conversation->id)
            ->where('action_type', Thread::ACTION_TYPE_RESTORE_TICKET)
            ->exists());
    }

    public function test_delete_and_restore_are_repeatable()
    {
        $this->user->addPermission(User::PERM_DELETE_CONVERSATIONS);
        $arguments = ['conversation_id' => $this->conversation->id];

        $this->assertSame('published', (new RestoreConversationTool())->handle($arguments, $this->user)['state']);
        (new DeleteConversationTool())->handle($arguments, $this->user);
        $this->assertSame('deleted', (new DeleteConversationTool())->handle($arguments, $this->user)['state']);
    }

    public function test_delete_requires_the_delete_permission()
    {
        try {
            (new DeleteConversationTool())->handle(['conversation_id' => $this->conversation->id], $this->user);
            $this->fail('A ToolError was expected for a user who may not delete conversations.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }

        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $this->conversation->fresh()->state);
    }

    public function test_delete_and_restore_hide_inaccessible_conversation()
    {
        $outsider = Fixtures::user();
        $outsider->addPermission(User::PERM_DELETE_CONVERSATIONS);

        foreach ([new DeleteConversationTool(), new RestoreConversationTool()] as $tool) {
            try {
                $tool->handle(['conversation_id' => $this->conversation->id], $outsider);
                $this->fail('A ToolError was expected.');
            } catch (ToolError $e) {
                $this->assertSame('Conversation not found or not accessible.', $e->getMessage());
            }
        }
    }

    public function test_annotations()
    {
        $this->assertSame(['readOnlyHint' => false, 'idempotentHint' => true], (new UpdateConversationTool())->annotations());
        $this->assertSame(['readOnlyHint' => false], (new AddNoteTool())->annotations());
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true], (new DeleteConversationTool())->annotations());
        $this->assertSame(
            ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
            (new RestoreConversationTool())->annotations()
        );
    }

    public function test_no_permanent_delete_or_empty_trash_tool_registered()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $names = [];
        foreach (app(ToolRegistry::class)->available($admin) as $tool) {
            $names[] = $tool->name();
        }

        foreach (['update_conversation', 'add_note', 'delete_conversation', 'restore_conversation'] as $name) {
            $this->assertContains($name, $names);
        }
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/forever|empty/', $name);
        }
    }

    private function customerEmailJobs(): int
    {
        return \DB::table('jobs')->where('payload', 'like', '%SendReplyToCustomer%')->count();
    }
}
