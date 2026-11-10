<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\Attachment;
use App\Conversation;
use App\Option;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolResult;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\Attachments;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Attachments\AddAttachmentsTool;
use Modules\McpForFreescout\Tools\Attachments\GetAttachmentTool;
use Modules\McpForFreescout\Tools\Attachments\RemoveAttachmentTool;
use Modules\McpForFreescout\Tools\Drafts\ReplyToConversationTool;
use Modules\McpForFreescout\Tools\Drafts\UpdateDraftTool;

class AttachmentToolsTest extends TestCase
{
    const LIMIT_OPTION = 'mcpforfreescout.max_attachment_size';

    /** @var Attachment[] */
    private $created = [];

    /** @var int Highest attachment id before the test, to find the files the tools created. */
    private $lastAttachmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lastAttachmentId = (int) Attachment::max('id');
    }

    protected function tearDown(): void
    {
        // The database rollback does not remove the stored files.
        if ($this->created) {
            Attachment::deleteForever(collect($this->created));
            $this->created = [];
        }
        $made = Attachment::where('id', '>', $this->lastAttachmentId)->get();
        if (count($made)) {
            Attachment::deleteForever($made);
        }

        parent::tearDown();
    }

    public function test_small_image_is_returned_as_an_image_block()
    {
        [$user, $conversation, $thread] = $this->scenario();
        $bytes = "\x89PNG\r\n\x1a\n".random_bytes(32);
        $image = $this->attach($thread, 'photo.png', 'image/png', $bytes);

        $result = (new GetAttachmentTool())->handle(['attachment_id' => $image->id], $user);

        $this->assertInstanceOf(ToolResult::class, $result);
        $structured = $result->structured();
        $this->assertSame((int) $image->id, $structured['id']);
        $this->assertSame('photo.png', $structured['file_name']);
        $this->assertSame((int) $thread->id, $structured['thread_id']);
        $this->assertSame((int) $conversation->id, $structured['conversation_id']);
        $this->assertSame($image->url(), $structured['url']);
        $blocks = $result->extraContent();
        $this->assertCount(1, $blocks);
        $this->assertSame('image', $blocks[0]['type']);
        $this->assertSame('image/png', $blocks[0]['mimeType']);
        $this->assertSame(base64_encode($bytes), $blocks[0]['data']);
    }

    public function test_image_over_the_inline_limit_is_not_inlined()
    {
        [$user, , $thread] = $this->scenario();
        $image = $this->attach($thread, 'big.jpg', 'image/jpeg', 'x');
        $image->size = 3670017;
        $image->save();

        $result = (new GetAttachmentTool())->handle(['attachment_id' => $image->id], $user);

        $this->assertSame([], $result->extraContent());
    }

    public function test_pdf_returns_link_only_unless_content_requested()
    {
        [$user, , $thread] = $this->scenario();
        $bytes = "%PDF-1.4\n".random_bytes(40);
        $pdf = $this->attach($thread, 'invoice.pdf', 'application/pdf', $bytes);
        $tool = new GetAttachmentTool();

        $linkOnly = $tool->handle(['attachment_id' => $pdf->id], $user);
        $this->assertSame([], $linkOnly->extraContent());
        $this->assertSame($pdf->url(), $linkOnly->structured()['url']);

        $withContent = $tool->handle(['attachment_id' => $pdf->id, 'include_content' => true], $user);
        $blocks = $withContent->extraContent();
        $this->assertCount(1, $blocks);
        $this->assertSame('resource', $blocks[0]['type']);
        $this->assertSame($pdf->url(), $blocks[0]['resource']['uri']);
        $this->assertSame('application/pdf', $blocks[0]['resource']['mimeType']);
        $this->assertSame(base64_encode($bytes), $blocks[0]['resource']['blob']);
    }

    public function test_image_with_include_content_returns_both_blocks()
    {
        [$user, , $thread] = $this->scenario();
        $image = $this->attach($thread, 'photo.gif', 'image/gif', 'GIF89a-data');

        $blocks = (new GetAttachmentTool())->handle(['attachment_id' => $image->id, 'include_content' => true], $user)->extraContent();

        $this->assertSame(['image', 'resource'], array_column($blocks, 'type'));
    }

    public function test_include_content_refuses_files_over_limit()
    {
        [$user, , $thread] = $this->scenario();
        $pdf = $this->attach($thread, 'large.pdf', 'application/pdf', 'twelve bytes');
        Option::set(self::LIMIT_OPTION, 10);
        unset(Option::$cache[self::LIMIT_OPTION]);

        try {
            (new GetAttachmentTool())->handle(['attachment_id' => $pdf->id, 'include_content' => true], $user);
            $this->fail('A ToolError was expected for a file over the limit.');
        } catch (ToolError $e) {
            $this->assertSame('File is larger than 10 bytes; download it from '.$pdf->url(), $e->getMessage());
        } finally {
            Option::remove(self::LIMIT_OPTION);
            unset(Option::$cache[self::LIMIT_OPTION]);
        }

        // Without include_content the same file is still described.
        $result = (new GetAttachmentTool())->handle(['attachment_id' => $pdf->id], $user);
        $this->assertSame([], $result->extraContent());
    }

    public function test_default_limit_is_ten_mebibytes()
    {
        $this->assertSame(10485760, Attachments::maxSize());
    }

    public function test_colleagues_draft_files_are_not_visible()
    {
        [$user, $conversation, , $colleague] = $this->scenario();
        $draft = $this->addThread($conversation, [
            'type'               => Thread::TYPE_MESSAGE,
            'state'              => Thread::STATE_DRAFT,
            'created_by_user_id' => $colleague->id,
        ]);
        $file = $this->attach($draft, 'secret.pdf', 'application/pdf', 'draft file');

        $this->assertNotFound($file, $user);

        $own = (new GetAttachmentTool())->handle(['attachment_id' => $file->id], $colleague);
        $this->assertSame((int) $file->id, $own->structured()['id']);
    }

    public function test_files_of_threads_held_for_review_are_not_visible()
    {
        [$user, $conversation] = $this->scenario();
        $held = $this->addThread($conversation, ['state' => Thread::STATE_REVIEW]);
        $file = $this->attach($held, 'held.pdf', 'application/pdf', 'held file');

        $this->assertNotFound($file, $user);
    }

    public function test_files_of_inaccessible_conversations_are_not_found()
    {
        [, , $thread] = $this->scenario();
        $outsider = Fixtures::user();
        $file = $this->attach($thread, 'a.pdf', 'application/pdf', 'content');

        $this->assertNotFound($file, $outsider);
    }

    public function test_saved_reply_files_are_not_found()
    {
        [$user] = $this->scenario();
        $orphan = $this->attach(null, 'canned.pdf', 'application/pdf', 'canned file');

        $this->assertNotFound($orphan, $user);
    }

    public function test_unknown_attachment_is_not_found()
    {
        [$user] = $this->scenario();

        try {
            (new GetAttachmentTool())->handle(['attachment_id' => 987654321], $user);
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertSame('Attachment not found or not accessible.', $e->getMessage());
        }
    }

    public function test_missing_stored_file_gives_tool_error()
    {
        [$user, , $thread] = $this->scenario();
        $image = $this->attach($thread, 'gone.png', 'image/png', 'png bytes');
        $pdf = $this->attach($thread, 'gone.pdf', 'application/pdf', 'pdf bytes');
        Attachment::getDisk()->delete($image->getStorageFilePath());
        Attachment::getDisk()->delete($pdf->getStorageFilePath());
        $tool = new GetAttachmentTool();

        foreach ([[$image, []], [$pdf, ['include_content' => true]]] as [$file, $extra]) {
            try {
                $tool->handle(array_merge(['attachment_id' => $file->id], $extra), $user);
                $this->fail('A ToolError was expected for a file missing on disk.');
            } catch (ToolError $e) {
                $this->assertSame('The stored file of attachment '.$file->id.' is missing.', $e->getMessage());
            }
        }

        // The link alone needs no file access.
        $this->assertSame([], $tool->handle(['attachment_id' => $pdf->id], $user)->extraContent());
    }

    public function test_the_tool_is_read_only_and_requires_the_attachment_id()
    {
        $tool = new GetAttachmentTool();

        $this->assertSame('get_attachment', $tool->name());
        $this->assertTrue($tool->annotations()['readOnlyHint']);
        $this->assertSame(['attachment_id'], $tool->inputSchema()['required']);
        $this->assertStringContainsString('without login', $tool->description());

        try {
            $tool->handle([], Fixtures::user());
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertSame('Argument "attachment_id" is required.', $e->getMessage());
        }
    }

    public function test_add_attachments_keeps_body_recipients_alias_and_choices()
    {
        [$user, $conversation] = $this->scenario();
        $draft = $this->draftReply($conversation, $user, [
            'body'              => 'Please see the files.',
            'cc'                => ['copy@example.test'],
            'from_alias'        => 'sales@example.test',
            'status_after_send' => 'closed',
            'attachments'       => [$this->file('mcp-test-one.txt', 'one')],
        ]);
        DraftChoices::store($draft, ['signature_id' => 3]);
        $to = $draft->getToArray();
        $choices = DraftChoices::get($draft->fresh());
        $this->assertSame(['signature_id' => 3, 'status' => Conversation::STATUS_CLOSED], $choices);

        $result = (new AddAttachmentsTool())->handle([
            'draft_id'    => $draft->id,
            'attachments' => [$this->file('mcp-test-two.txt', 'two')],
        ], $user);

        $this->assertSame((int) $draft->id, $result['draft_id']);
        $this->assertSame((int) $conversation->id, $result['conversation_id']);
        $this->assertSame(['mcp-test-one.txt', 'mcp-test-two.txt'], array_column($result['attachments'], 'file_name'));
        $this->assertCount(2, Attachment::where('thread_id', $draft->id)->get());

        $fresh = $draft->fresh();
        $this->assertSame(Thread::STATE_DRAFT, (int) $fresh->state);
        $this->assertTrue((bool) $fresh->has_attachments);
        $this->assertSame($draft->body, $fresh->body);
        $this->assertSame($to, $fresh->getToArray());
        $this->assertSame(['copy@example.test'], $fresh->getCcArray());
        $this->assertSame('sales@example.test', $fresh->from);
        $this->assertSame($choices, DraftChoices::get($fresh));
        $this->assertSame(1, Thread::where('conversation_id', $conversation->id)->where('state', Thread::STATE_DRAFT)->count());
    }

    public function test_add_attachments_refuses_a_colleagues_draft()
    {
        [$user, $conversation, , $colleague] = $this->scenario();
        $draft = $this->draftReply($conversation, $user);
        $files = Attachment::count();

        try {
            (new AddAttachmentsTool())->handle([
                'draft_id'    => $draft->id,
                'attachments' => [$this->file('mcp-test-late.txt', 'late')],
            ], $colleague);
            $this->fail('A colleague changed the draft of another user.');
        } catch (ToolError $e) {
            $this->assertSame('Draft not found or not accessible.', $e->getMessage());
        }

        try {
            (new RemoveAttachmentTool())->handle(['draft_id' => $draft->id, 'attachment_id' => 1], $colleague);
            $this->fail('A colleague changed the draft of another user.');
        } catch (ToolError $e) {
            $this->assertSame('Draft not found or not accessible.', $e->getMessage());
        }

        $this->assertSame($files, Attachment::count());
    }

    public function test_add_attachments_needs_files_and_an_open_draft()
    {
        [$user, $conversation] = $this->scenario();
        $draft = $this->draftReply($conversation, $user);
        $tool = new AddAttachmentsTool();

        foreach ([[], ['attachments' => []]] as $extra) {
            try {
                $tool->handle(['draft_id' => $draft->id] + $extra, $user);
                $this->fail('A call without files was accepted.');
            } catch (ToolError $e) {
                $this->assertStringContainsString('"attachments"', $e->getMessage());
            }
        }

        // A published message is not a draft.
        $published = Thread::where('conversation_id', $conversation->id)->first();
        try {
            $tool->handle(['draft_id' => $published->id, 'attachments' => [$this->file('mcp-test-x.txt', 'x')]], $user);
            $this->fail('A published thread was changed.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('not a draft', $e->getMessage());
        }

        // Saving a draft would restore a trashed conversation.
        $conversation->state = Conversation::STATE_DELETED;
        $conversation->save();
        $files = Attachment::count();
        try {
            $tool->handle(['draft_id' => $draft->id, 'attachments' => [$this->file('mcp-test-y.txt', 'y')]], $user);
            $this->fail('A draft of a trashed conversation was changed.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('in the trash', $e->getMessage());
        }
        $this->assertSame($files, Attachment::count());
        $this->assertSame(Conversation::STATE_DELETED, (int) $conversation->fresh()->state);
    }

    public function test_message_size_counts_the_files_already_on_the_draft()
    {
        config(['app.max_message_size' => 1]);
        [$user, $conversation] = $this->scenario();
        // 700000 bytes fit under 1 MB once encoded for mail (x 1.37), but a second file does not.
        $draft = $this->draftReply($conversation, $user, ['attachments' => [$this->file('mcp-test-big.bin', str_repeat('x', 700000))]]);
        $files = Attachment::count();

        try {
            (new AddAttachmentsTool())->handle([
                'draft_id'    => $draft->id,
                'attachments' => [$this->file('mcp-test-more.bin', str_repeat('y', 100000))],
            ], $user);
            $this->fail('The oversized draft was accepted.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('maximum message size of 1 MB', $e->getMessage());
        }
        // Neither the new file nor the old one changed.
        $this->assertSame($files, Attachment::count());
        $this->assertCount(1, Attachment::where('thread_id', $draft->id)->get());

        // The same applies to the text, but a change within the limit still works.
        try {
            (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'body' => str_repeat('z', 100000)], $user);
            $this->fail('The oversized draft was accepted.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('maximum message size of 1 MB', $e->getMessage());
        }
        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'body' => 'Shorter'], $user);
        $this->assertSame('Shorter', $draft->fresh()->body);
        $this->assertCount(1, Attachment::where('thread_id', $draft->id)->get());
    }

    public function test_remove_attachment_deletes_file_and_clears_flag()
    {
        [$user, $conversation] = $this->scenario();
        $draft = $this->draftReply($conversation, $user, [
            'attachments' => [$this->file('mcp-test-a.txt', 'aaa'), $this->file('mcp-test-b.txt', 'bbb')],
        ]);
        [$first, $second] = Attachment::where('thread_id', $draft->id)->orderBy('id')->get()->all();
        $firstPath = $first->getStorageFilePath();
        $secondPath = $second->getStorageFilePath();
        $this->assertTrue(Attachment::getDisk()->exists($firstPath));
        $tool = new RemoveAttachmentTool();

        $result = $tool->handle(['draft_id' => $draft->id, 'attachment_id' => $first->id], $user);

        $this->assertSame((int) $draft->id, $result['draft_id']);
        $this->assertSame((int) $conversation->id, $result['conversation_id']);
        $this->assertSame([(int) $second->id], array_column($result['attachments'], 'id'));
        $this->assertNull(Attachment::find($first->id));
        $this->assertFalse(Attachment::getDisk()->exists($firstPath));
        $this->assertTrue((bool) $draft->fresh()->has_attachments);

        $result = $tool->handle(['draft_id' => $draft->id, 'attachment_id' => $second->id], $user);

        $this->assertSame([], $result['attachments']);
        $this->assertNull(Attachment::find($second->id));
        $this->assertFalse(Attachment::getDisk()->exists($secondPath));
        $this->assertFalse((bool) $draft->fresh()->has_attachments);
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
    }

    public function test_remove_attachment_refuses_embedded_and_foreign_files()
    {
        [$user, $conversation] = $this->scenario();
        $draft = $this->draftReply($conversation, $user, ['attachments' => [$this->file('mcp-test-own.txt', 'own')]]);
        $embedded = Attachment::create('mcp-test-inline.png', 'image/png', null, 'png bytes', null, true, $draft->id);
        $this->created[] = $embedded;
        $published = Thread::where('conversation_id', $conversation->id)->first();
        $foreign = $this->attach($published, 'mcp-test-foreign.txt', 'text/plain', 'foreign');
        $loose = $this->attach(null, 'mcp-test-loose.txt', 'text/plain', 'loose');
        $tool = new RemoveAttachmentTool();

        try {
            $tool->handle(['draft_id' => $draft->id, 'attachment_id' => $embedded->id], $user);
            $this->fail('An embedded image was removed.');
        } catch (ToolError $e) {
            $this->assertSame('Embedded images are part of the text; edit the draft in FreeScout to remove it.', $e->getMessage());
        }

        foreach ([$foreign->id, $loose->id, 987654321] as $id) {
            try {
                $tool->handle(['draft_id' => $draft->id, 'attachment_id' => $id], $user);
                $this->fail('A file that is not on the draft was removed.');
            } catch (ToolError $e) {
                $this->assertSame('Attachment not found on this draft.', $e->getMessage());
            }
        }

        $this->assertNotNull(Attachment::find($embedded->id));
        $this->assertNotNull(Attachment::find($foreign->id));
        $this->assertNotNull(Attachment::find($loose->id));
        $this->assertCount(2, Attachment::where('thread_id', $draft->id)->get());
        $this->assertTrue((bool) $draft->fresh()->has_attachments);

        // A published message cannot be changed through this tool.
        try {
            $tool->handle(['draft_id' => $published->id, 'attachment_id' => $foreign->id], $user);
            $this->fail('A published thread was changed.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('not a draft', $e->getMessage());
        }
        $this->assertNotNull(Attachment::find($foreign->id));
    }

    public function test_attachment_tools_queue_nothing()
    {
        [$user, $conversation] = $this->scenario();
        $draft = $this->draftReply($conversation, $user);
        $jobs = \DB::table('jobs')->count();
        $published = Thread::where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)->count();

        $result = (new AddAttachmentsTool())->handle([
            'draft_id'    => $draft->id,
            'attachments' => [$this->file('mcp-test-q.txt', 'q')],
        ], $user);
        (new RemoveAttachmentTool())->handle(['draft_id' => $draft->id, 'attachment_id' => $result['attachments'][0]['id']], $user);

        $this->assertSame($jobs, \DB::table('jobs')->count());
        $this->assertSame($published, Thread::where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)->count());
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
    }

    public function test_draft_attachment_tools_are_declared_and_registered()
    {
        $add = new AddAttachmentsTool();
        $remove = new RemoveAttachmentTool();

        $this->assertSame('add_attachments', $add->name());
        $this->assertSame(['draft_id', 'attachments'], $add->inputSchema()['required']);
        $this->assertSame(1, $add->inputSchema()['properties']['attachments']['minItems']);
        $this->assertFalse($add->annotations()['destructiveHint']);
        $this->assertStringContainsStringIgnoringCase('nothing is sent', $add->description());

        $this->assertSame('remove_attachment', $remove->name());
        $this->assertSame(['draft_id', 'attachment_id'], $remove->inputSchema()['required']);
        $this->assertTrue($remove->annotations()['destructiveHint']);
        $this->assertFalse($remove->annotations()['readOnlyHint']);

        app()->forgetInstance(ToolRegistry::class);
        $user = Fixtures::user();
        foreach (['add_attachments', 'remove_attachment'] as $name) {
            $this->assertNotNull(app(ToolRegistry::class)->find($name, $user), $name);
        }
        app()->forgetInstance(ToolRegistry::class);
    }

    private function file(string $name, string $content): array
    {
        return ['file_name' => $name, 'content_base64' => base64_encode($content), 'mime_type' => 'text/plain'];
    }

    /**
     * A draft reply of the user to the conversation, made with the drafting tool.
     */
    private function draftReply(Conversation $conversation, User $user, array $extra = []): Thread
    {
        $result = (new ReplyToConversationTool())->handle(array_merge([
            'conversation_id' => $conversation->id,
            'body'            => 'Original',
        ], $extra), $user);

        return Thread::find($result['draft_id']);
    }

    private function assertNotFound(Attachment $file, User $user): void
    {
        try {
            (new GetAttachmentTool())->handle(['attachment_id' => $file->id, 'include_content' => true], $user);
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertSame('Attachment not found or not accessible.', $e->getMessage());
        }
    }

    /**
     * A user and a colleague sharing a mailbox, a conversation and its customer thread.
     *
     * @return array [User, Conversation, Thread, User]
     */
    private function scenario(): array
    {
        $user = Fixtures::user();
        $colleague = Fixtures::user();
        $mailbox = Fixtures::mailbox(['aliases' => 'sales@example.test', 'aliases_reply' => true], [$user, $colleague]);
        $conversation = Fixtures::conversation($mailbox, Fixtures::customer());
        $thread = Thread::where('conversation_id', $conversation->id)->first();

        return [$user, $conversation, $thread, $colleague];
    }

    private function attach(?Thread $thread, string $name, string $mime, string $content): Attachment
    {
        $attachment = Attachment::create($name, $mime, null, $content, null, false, $thread ? $thread->id : null);
        $this->created[] = $attachment;

        return $attachment;
    }

    private function addThread(Conversation $conversation, array $attrs = []): Thread
    {
        $thread = new Thread();
        $thread->forceFill(array_merge([
            'conversation_id' => $conversation->id,
            'type'            => Thread::TYPE_MESSAGE,
            'status'          => $conversation->status,
            'state'           => Thread::STATE_PUBLISHED,
            'body'            => 'A message.',
            'source_via'      => Thread::PERSON_USER,
            'source_type'     => Thread::SOURCE_TYPE_WEB,
        ], $attrs));
        $thread->save();

        return $thread;
    }
}
