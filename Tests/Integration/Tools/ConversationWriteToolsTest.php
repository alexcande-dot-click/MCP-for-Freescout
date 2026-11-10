<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\Attachment;
use App\Conversation;
use App\Folder;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\BodyFormatter;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Conversations\AddNoteTool;
use Modules\McpForFreescout\Tools\Conversations\DeleteConversationTool;
use Modules\McpForFreescout\Tools\Conversations\GetConversationTool;
use Modules\McpForFreescout\Tools\Conversations\ListConversationsTool;
use Modules\McpForFreescout\Tools\Conversations\RestoreConversationTool;
use Modules\McpForFreescout\Tools\Conversations\StarConversationTool;
use Modules\McpForFreescout\Tools\Conversations\UpdateConversationTool;

class ConversationWriteToolsTest extends TestCase
{
    private $user;
    private $colleague;
    private $mailbox;
    private $conversation;
    private $lastAttachmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Fixtures::user();
        $this->colleague = Fixtures::user();
        $this->mailbox = Fixtures::mailbox([], [$this->user, $this->colleague]);
        $this->conversation = Fixtures::conversation($this->mailbox, Fixtures::customer());
        $this->lastAttachmentId = (int) Attachment::max('id');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);
        // The database rollback does not remove the stored files.
        $created = Attachment::where('id', '>', $this->lastAttachmentId)->get();
        if (count($created)) {
            Attachment::deleteForever($created);
        }

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

    public function test_note_with_file_is_published_with_the_file()
    {
        $before = $this->customerEmailJobs();
        $bytes = 'Call log '.random_bytes(16);

        $result = (new AddNoteTool())->handle([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Notes from the call, see the log.',
            'attachments'     => [['file_name' => 'mcp-test-call-log.txt', 'content_base64' => base64_encode($bytes), 'mime_type' => 'text/plain']],
        ], $this->user);

        $note = Thread::find($result['thread_id']);
        $this->assertSame(Thread::TYPE_NOTE, (int) $note->type);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $note->state);
        $this->assertTrue((bool) $note->has_attachments);

        $files = Attachment::where('thread_id', $note->id)->get();
        $this->assertCount(1, $files);
        $this->assertSame('mcp-test-call-log.txt', $files[0]->file_name);
        $this->assertSame($bytes, Attachment::getDisk()->get($files[0]->getStorageFilePath()));
        $this->assertFalse((bool) $files[0]->embedded);
        $this->assertTrue((bool) $this->conversation->fresh()->has_attachments);
        // Notes notify the team like in the web UI, but never the customer.
        $this->assertSame($before, $this->customerEmailJobs());
    }

    public function test_note_with_a_bad_file_is_not_added()
    {
        $notes = Thread::where('type', Thread::TYPE_NOTE)->count();
        $attachments = Attachment::count();

        try {
            (new AddNoteTool())->handle([
                'conversation_id' => $this->conversation->id,
                'body'            => 'Should not appear.',
                'attachments'     => [
                    ['file_name' => 'mcp-test-ok.txt', 'content_base64' => base64_encode('ok')],
                    ['file_name' => 'mcp-test-bad.txt', 'content_base64' => '***'],
                ],
            ], $this->user);
            $this->fail('The invalid file was accepted.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('attachments[1]', $e->getMessage());
        }

        $this->assertSame($notes, Thread::where('type', Thread::TYPE_NOTE)->count());
        $this->assertSame($attachments, Attachment::count());
    }

    public function test_failed_note_leaves_no_orphan_files()
    {
        $attachments = Attachment::count();

        // Core fails after it has read the note and before it links the files to it.
        $failing = function () {
            throw new \RuntimeException('Saving the note failed.');
        };
        \Eventy::addAction('conversation.send_reply_save', $failing, 20, 2);
        try {
            (new AddNoteTool())->handle([
                'conversation_id' => $this->conversation->id,
                'body'            => 'Never saved',
                'attachments'     => [['file_name' => 'mcp-test-orphan.txt', 'content_base64' => base64_encode('orphan')]],
            ], $this->user);
            $this->fail('The failure of core was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Saving the note failed.', $e->getMessage());
        } finally {
            \Eventy::removeAction('conversation.send_reply_save', $failing, 20);
        }

        $this->assertSame($attachments, Attachment::count());
        $this->assertSame(0, count(array_filter(Attachment::getDisk()->allFiles(Attachment::DIRECTORY), function ($path) {
            return strpos(basename($path), 'mcp-test-') === 0;
        })));
    }

    public function test_note_tool_offers_attachments()
    {
        $tool = new AddNoteTool();
        $schema = $tool->inputSchema();

        $this->assertSame('array', $schema['properties']['attachments']['type']);
        $this->assertSame(['conversation_id', 'body'], $schema['required']);
        $this->assertStringContainsString('attachments', $tool->description());
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

    public function test_star_and_unstar_for_the_caller_only()
    {
        $tool = new StarConversationTool();
        $arguments = ['conversation_id' => $this->conversation->id];
        $starredIds = function (User $user) {
            return array_column((new ListConversationsTool())->handle([
                'mailbox_id' => $this->mailbox->id,
                'folder'     => 'starred',
            ], $user)['conversations'], 'id');
        };
        $starredFor = function (User $user) use ($arguments) {
            return (new GetConversationTool())->handle($arguments, $user)['starred'];
        };

        $starred = $tool->handle($arguments + ['starred' => true], $this->user);

        $this->assertTrue($starred['starred']);
        $this->assertSame((int) $this->conversation->id, $starred['id']);
        $this->assertTrue($starredFor($this->user));
        $this->assertFalse($starredFor($this->colleague));
        $this->assertSame([(int) $this->conversation->id], $starredIds($this->user));
        $this->assertSame([], $starredIds($this->colleague));

        $unstarred = $tool->handle($arguments + ['starred' => false], $this->user);

        $this->assertFalse($unstarred['starred']);
        $this->assertFalse($starredFor($this->user));
        $this->assertSame([], $starredIds($this->user));
    }

    public function test_star_is_idempotent()
    {
        $tool = new StarConversationTool();
        $arguments = ['conversation_id' => $this->conversation->id];
        $rows = function () {
            return \DB::table('conversation_folder')
                ->join('folders', 'folders.id', '=', 'conversation_folder.folder_id')
                ->where('conversation_folder.conversation_id', $this->conversation->id)
                ->where('folders.type', Folder::TYPE_STARRED)
                ->count();
        };

        $this->assertFalse($tool->handle($arguments + ['starred' => false], $this->user)['starred']);
        $this->assertSame(0, $rows());

        $this->assertTrue($tool->handle($arguments + ['starred' => true], $this->user)['starred']);
        $this->assertTrue($tool->handle($arguments + ['starred' => true], $this->user)['starred']);
        $this->assertSame(1, $rows());

        $this->assertFalse($tool->handle($arguments + ['starred' => false], $this->user)['starred']);
        $this->assertFalse($tool->handle($arguments + ['starred' => false], $this->user)['starred']);
        $this->assertSame(0, $rows());
    }

    public function test_star_without_a_personal_starred_folder_is_an_error()
    {
        Folder::where('mailbox_id', $this->mailbox->id)
            ->where('user_id', $this->user->id)
            ->where('type', Folder::TYPE_STARRED)
            ->delete();

        try {
            (new StarConversationTool())->handle(['conversation_id' => $this->conversation->id, 'starred' => true], $this->user);
            $this->fail('A ToolError was expected without a Starred folder.');
        } catch (ToolError $e) {
            $this->assertSame('The conversation could not be starred: you have no Starred folder in this mailbox.', $e->getMessage());
        }

        $this->assertSame(0, \DB::table('conversation_folder')->where('conversation_id', $this->conversation->id)->count());
    }

    public function test_star_refuses_inaccessible_conversation()
    {
        $outsider = Fixtures::user();

        foreach ([$this->conversation->id, 999999999] as $id) {
            try {
                (new StarConversationTool())->handle(['conversation_id' => $id, 'starred' => true], $outsider);
                $this->fail('A ToolError was expected.');
            } catch (ToolError $e) {
                $this->assertSame('Conversation not found or not accessible.', $e->getMessage());
            }
        }

        $this->assertSame(0, \DB::table('conversation_folder')->where('conversation_id', $this->conversation->id)->count());
    }

    public function test_star_refuses_a_draft_conversation()
    {
        $draft = Fixtures::conversation($this->mailbox, Fixtures::customer(), ['state' => Conversation::STATE_DRAFT]);

        try {
            (new StarConversationTool())->handle(['conversation_id' => $draft->id, 'starred' => true], $this->user);
            $this->fail('A ToolError was expected for a draft conversation.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('draft conversation', $e->getMessage());
        }

        $this->assertSame(0, \DB::table('conversation_folder')->where('conversation_id', $draft->id)->count());
    }

    public function test_star_requires_the_starred_argument()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"starred"');

        (new StarConversationTool())->handle(['conversation_id' => $this->conversation->id], $this->user);
    }

    public function test_annotations()
    {
        $this->assertSame(['readOnlyHint' => false, 'idempotentHint' => true], (new UpdateConversationTool())->annotations());
        $this->assertSame(['readOnlyHint' => false], (new AddNoteTool())->annotations());
        $this->assertSame(['readOnlyHint' => false, 'idempotentHint' => true], (new StarConversationTool())->annotations());
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

        foreach (['update_conversation', 'add_note', 'delete_conversation', 'restore_conversation', 'star_conversation'] as $name) {
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
