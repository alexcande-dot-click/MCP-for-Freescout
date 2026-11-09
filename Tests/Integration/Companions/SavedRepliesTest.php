<?php

namespace Modules\McpForFreescout\Tests\Integration\Companions;

use App\Attachment;
use App\Conversation;
use App\Mailbox;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Drafts\CreateConversationTool;
use Modules\McpForFreescout\Tools\Drafts\ReplyToConversationTool;
use Modules\McpForFreescout\Tools\Drafts\SendDraftTool;
use Modules\McpForFreescout\Tools\Drafts\UpdateDraftTool;
use Modules\McpForFreescout\Tools\SavedReplies\CreateSavedReplyTool;
use Modules\McpForFreescout\Tools\SavedReplies\DeleteSavedReplyTool;
use Modules\McpForFreescout\Tools\SavedReplies\GetSavedReplyTool;
use Modules\McpForFreescout\Tools\SavedReplies\ListSavedRepliesTool;
use Modules\McpForFreescout\Tools\SavedReplies\UpdateSavedReplyTool;
use Modules\SavedReplies\Entities\SavedReply;

class SavedRepliesTest extends TestCase
{
    private const TOOLS = ['list_saved_replies', 'get_saved_reply', 'create_saved_reply', 'update_saved_reply', 'delete_saved_reply'];

    private const NOT_FOUND = 'Saved reply not found or not accessible.';

    private $admin;
    private $user;
    private $mailbox;
    private $customer;
    private $conversation;
    private $jobsAtStart;
    private $publishedAtStart;
    private $sendsOnPurpose = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireModule(CompanionModules::SAVED_REPLIES);

        $this->admin = Fixtures::user([], User::ROLE_ADMIN);
        $this->user = Fixtures::user(['first_name' => 'Agent']);
        $this->mailbox = Fixtures::mailbox([], [$this->user]);
        $this->customer = Fixtures::customer('customer@example.test');
        $this->conversation = Fixtures::conversation($this->mailbox, $this->customer);

        $this->jobsAtStart = $this->jobCount();
        $this->publishedAtStart = $this->publishedMessageCount();
    }

    /**
     * Nothing in this class may queue a job or publish a message unless the test sends on purpose.
     */
    protected function assertPostConditions(): void
    {
        if (!$this->sendsOnPurpose) {
            $this->assertSame($this->jobsAtStart, $this->jobCount(), 'A tool queued a job.');
            $this->assertSame($this->publishedAtStart, $this->publishedMessageCount(), 'A tool published a message.');
        }

        parent::assertPostConditions();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);
        // The files of the attachments made here are not covered by the database rollback.
        Attachment::deleteForever(Attachment::where('file_name', 'like', 'mcp-test-%')->get());

        parent::tearDown();
    }

    private function savedReply(string $name, string $text = '<p>Text</p>', Mailbox $mailbox = null, array $attrs = []): SavedReply
    {
        $reply = new SavedReply();
        $reply->mailbox_id = ($mailbox ?: $this->mailbox)->id;
        $reply->name = $name;
        $reply->text = $text;
        $reply->user_id = $this->admin->id;
        foreach ($attrs as $key => $value) {
            $reply->$key = $value;
        }
        $reply->save();

        return $reply;
    }

    private function withAttachment(SavedReply $reply, string $content = 'file content'): Attachment
    {
        $attachment = Attachment::create('mcp-test-'.uniqid().'.txt', 'text/plain', null, $content, null);
        $reply->attachments = array_merge((array) $reply->attachments, [$attachment->id]);
        $reply->save();

        return $attachment;
    }

    /**
     * A user who may edit the saved replies of the test mailbox.
     */
    private function editor(): User
    {
        $editor = Fixtures::user();
        $this->mailbox->users()->attach($editor->id);
        $editor = User::find($editor->id);
        $editor->addPermission(User::PERM_EDIT_SAVED_REPLIES);

        return $editor;
    }

    private function draftReply(array $extra = [], User $user = null): Thread
    {
        $result = (new ReplyToConversationTool())->handle(array_merge([
            'conversation_id' => $this->conversation->id,
        ], $extra), $user ?: $this->user);

        return Thread::find($result['draft_id']);
    }

    private function assertRejected(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function assertRejectedWith(string $exception, callable $call): void
    {
        try {
            $call();
            $this->fail('A '.$exception.' was expected.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }
    }

    private function jobCount(): int
    {
        return (int) \DB::table('jobs')->count();
    }

    private function publishedMessageCount(): int
    {
        return Thread::where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)->count();
    }

    /**
     * Collect the ids of a presented tree, depth first.
     */
    private function treeIds(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = $node['id'];
            $ids = array_merge($ids, $this->treeIds($node['children']));
        }

        return $ids;
    }

    public function test_list_returns_tree_with_globals()
    {
        $category = $this->savedReply('Category', '<p>c</p>');
        $child = $this->savedReply('Child', '<p>x</p>', null, ['parent_saved_reply_id' => $category->id]);
        $this->withAttachment($child);
        $own = $this->savedReply('Own', '<p>o</p>');

        $foreign = Fixtures::mailbox([], [Fixtures::user()]);
        $global = $this->savedReply('Global', '<p>g</p>', $foreign, ['global' => true]);
        $globalChild = $this->savedReply('Under global', '<p>gc</p>', $foreign, ['parent_saved_reply_id' => $global->id]);
        $hidden = $this->savedReply('Private', '<p>p</p>', $foreign);
        $hangingGlobal = $this->savedReply('Hanging', '<p>h</p>', $foreign, ['global' => true, 'parent_saved_reply_id' => $hidden->id]);

        $result = (new ListSavedRepliesTool())->handle(['mailbox_id' => $this->mailbox->id], $this->user);

        $this->assertSame(['mailbox_id', 'saved_replies'], array_keys($result));
        $this->assertSame((int) $this->mailbox->id, $result['mailbox_id']);
        $tree = $result['saved_replies'];

        $ids = $this->treeIds($tree);
        sort($ids);
        $this->assertSame(
            [(int) $category->id, (int) $child->id, (int) $own->id, (int) $global->id, (int) $globalChild->id, (int) $hangingGlobal->id],
            $ids
        );
        $this->assertNotContains((int) $hidden->id, $ids);

        $byId = [];
        foreach ($tree as $node) {
            $byId[$node['id']] = $node;
        }
        $this->assertSame(
            ['id' => (int) $category->id, 'name' => 'Category', 'parent_id' => null, 'global' => false, 'has_attachments' => false, 'children' => [
                ['id' => (int) $child->id, 'name' => 'Child', 'parent_id' => (int) $category->id, 'global' => false, 'has_attachments' => true, 'children' => []],
            ]],
            $byId[$category->id]
        );
        $this->assertTrue($byId[$global->id]['global']);
        $this->assertSame((int) $globalChild->id, $byId[$global->id]['children'][0]['id']);
        // A global reply whose parent is not offered is shown at the top, like the module's reply form does.
        $this->assertNull($byId[$hangingGlobal->id]['parent_id']);
    }

    public function test_list_of_an_inaccessible_mailbox_is_refused()
    {
        $foreign = Fixtures::mailbox([], [Fixtures::user()]);

        $this->assertRejected(function () use ($foreign) {
            (new ListSavedRepliesTool())->handle(['mailbox_id' => $foreign->id], $this->user);
        }, 'Mailbox not found or not accessible.');
    }

    public function test_get_returns_text_and_attachments()
    {
        $reply = $this->savedReply('Greeting', '<p>Hello {%customer.firstName%}</p>');
        $attachment = $this->withAttachment($reply);

        $result = (new GetSavedReplyTool())->handle(['saved_reply_id' => $reply->id], $this->user);

        $this->assertSame([
            'id'          => (int) $reply->id,
            'name'        => 'Greeting',
            'text'        => '<p>Hello {%customer.firstName%}</p>',
            'mailbox_id'  => (int) $this->mailbox->id,
            'parent_id'   => null,
            'global'      => false,
            'attachments' => [[
                'id'        => (int) $attachment->id,
                'file_name' => $attachment->file_name,
                'mime_type' => 'text/plain',
                'size'      => (int) $attachment->size,
            ]],
        ], $result);
    }

    public function test_get_respects_mailbox_access_and_global_flag()
    {
        $foreign = Fixtures::mailbox([], [Fixtures::user()]);
        $private = $this->savedReply('Private', '<p>p</p>', $foreign);
        $global = $this->savedReply('Global', '<p>g</p>', $foreign, ['global' => true]);
        $underGlobal = $this->savedReply('Under', '<p>u</p>', $foreign, ['parent_saved_reply_id' => $global->id]);

        foreach ([$private->id, 999999999] as $id) {
            $this->assertRejected(function () use ($id) {
                (new GetSavedReplyTool())->handle(['saved_reply_id' => $id], $this->user);
            }, self::NOT_FOUND);
        }
        $this->assertSame('Global', (new GetSavedReplyTool())->handle(['saved_reply_id' => $global->id], $this->user)['name']);
        $this->assertSame('Under', (new GetSavedReplyTool())->handle(['saved_reply_id' => $underGlobal->id], $this->user)['name']);
    }

    public function test_crud_requires_permission()
    {
        $mailboxId = $this->mailbox->id;
        $existing = $this->savedReply('Existing');
        $denied = 'You are not allowed to change the saved replies of this mailbox.';

        $this->assertRejected(function () use ($mailboxId) {
            (new CreateSavedReplyTool())->handle(['mailbox_id' => $mailboxId, 'name' => 'New', 'text' => 'Text'], $this->user);
        }, $denied);
        $this->assertRejected(function () use ($existing) {
            (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $existing->id, 'name' => 'Renamed'], $this->user);
        }, $denied);
        $this->assertRejected(function () use ($existing) {
            (new DeleteSavedReplyTool())->handle(['saved_reply_id' => $existing->id], $this->user);
        }, $denied);
        $this->assertSame('Existing', SavedReply::find($existing->id)->name);
        $this->assertSame(1, SavedReply::where('mailbox_id', $mailboxId)->count());

        // The permission needs access to the mailbox as well.
        $outsider = Fixtures::user();
        $outsider->addPermission(User::PERM_EDIT_SAVED_REPLIES);
        $this->assertRejected(function () use ($mailboxId, $outsider) {
            (new CreateSavedReplyTool())->handle(['mailbox_id' => $mailboxId, 'name' => 'New', 'text' => 'Text'], $outsider);
        }, 'Mailbox not found or not accessible.');

        $editor = $this->editor();
        $created = (new CreateSavedReplyTool())->handle(['mailbox_id' => $mailboxId, 'name' => 'Support', 'text' => '<p>Support</p>'], $editor);
        $this->assertSame(['id', 'name', 'text', 'mailbox_id', 'parent_id', 'global', 'attachments'], array_keys($created));
        $this->assertSame('Support', $created['name']);
        $this->assertFalse($created['global']);
        $this->assertSame([], $created['attachments']);
        $this->assertSame((int) $editor->id, (int) SavedReply::find($created['id'])->user_id);

        $updated = (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $created['id'], 'name' => 'Helpdesk', 'global' => true], $editor);
        $this->assertSame('Helpdesk', $updated['name']);
        $this->assertSame('<p>Support</p>', $updated['text']);
        $this->assertTrue($updated['global']);

        $deleted = (new DeleteSavedReplyTool())->handle(['saved_reply_id' => $created['id']], $editor);
        $this->assertSame(['deleted' => true, 'saved_reply_id' => $created['id']], $deleted);
        $this->assertNull(SavedReply::find($created['id']));

        $adminCreated = (new CreateSavedReplyTool())->handle(['mailbox_id' => $mailboxId, 'name' => 'Admin', 'text' => 'Text'], $this->admin);
        $this->assertSame('Admin', SavedReply::find($adminCreated['id'])->name);
    }

    public function test_a_global_reply_of_another_mailbox_can_be_read_but_not_changed()
    {
        $foreign = Fixtures::mailbox([], [Fixtures::user()]);
        $global = $this->savedReply('Global', '<p>g</p>', $foreign, ['global' => true]);
        $editor = $this->editor();

        $this->assertRejected(function () use ($global, $editor) {
            (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $global->id, 'name' => 'Mine'], $editor);
        }, 'You are not allowed to change the saved replies of this mailbox.');
        $this->assertRejected(function () use ($global, $editor) {
            (new DeleteSavedReplyTool())->handle(['saved_reply_id' => $global->id], $editor);
        }, 'You are not allowed to change the saved replies of this mailbox.');
        $this->assertSame('Global', SavedReply::find($global->id)->name);
    }

    public function test_saved_replies_of_inaccessible_mailboxes_are_not_revealed()
    {
        $foreign = Fixtures::mailbox([], [Fixtures::user()]);
        $reply = $this->savedReply('Foreign', 'Text', $foreign);
        $editor = $this->editor();

        $this->assertRejected(function () use ($foreign, $editor) {
            (new CreateSavedReplyTool())->handle(['mailbox_id' => $foreign->id, 'name' => 'x', 'text' => 'y'], $editor);
        }, 'Mailbox not found or not accessible.');
        foreach ([new UpdateSavedReplyTool(), new DeleteSavedReplyTool()] as $tool) {
            $this->assertRejected(function () use ($tool, $reply, $editor) {
                $tool->handle(['saved_reply_id' => $reply->id, 'name' => 'x'], $editor);
            }, self::NOT_FOUND);
        }
    }

    public function test_input_is_validated_and_sanitised()
    {
        $create = function (array $arguments) {
            return (new CreateSavedReplyTool())->handle(['mailbox_id' => $this->mailbox->id] + $arguments, $this->admin);
        };

        $this->assertRejected(function () use ($create) {
            $create(['name' => str_repeat('n', 76), 'text' => 'Text']);
        }, 'at most 75 characters');
        $this->assertRejected(function () use ($create) {
            $create(['name' => '  ', 'text' => 'Text']);
        }, 'name');
        $this->assertRejected(function () use ($create) {
            $create(['name' => 'Name', 'text' => '']);
        }, 'text');
        $this->assertRejected(function () use ($create) {
            $create(['name' => 'Name', 'text' => '<script>alert(1)</script>']);
        }, 'text');
        $this->assertRejected(function () use ($create) {
            $create(['name' => 'Name', 'text' => str_repeat('a', 65001)]);
        }, 'at most 65000 bytes');
        $this->assertSame(0, SavedReply::where('mailbox_id', $this->mailbox->id)->count());

        $name = str_repeat('é', 75);
        $plain = $create(['name' => $name, 'text' => "Line 1\nA & B"]);
        $this->assertSame($name, $plain['name']);
        $this->assertSame('Line 1<br>A &amp; B', preg_replace('/<br>\s*/', '<br>', $plain['text']));

        $html = $create(['name' => 'Html', 'text' => '<p onclick="x()">Hi {%customer.firstName%}</p><script>alert(1)</script>']);
        $this->assertStringNotContainsString('script', $html['text']);
        $this->assertStringNotContainsString('onclick', $html['text']);
        $this->assertStringContainsString('Hi {%customer.firstName%}', $html['text']);

        $updated = (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $html['id'], 'text' => '<p>Clean</p><script>x</script>'], $this->admin);
        $this->assertSame('<p>Clean</p>', $updated['text']);
        $this->assertSame('Html', $updated['name']);

        $this->assertRejected(function () use ($html) {
            (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $html['id']], $this->admin);
        }, 'at least one');
        $this->assertRejected(function () use ($html) {
            (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $html['id'], 'name' => ''], $this->admin);
        }, 'name');
    }

    public function test_names_are_unique_per_mailbox()
    {
        $existing = $this->savedReply('Taken');
        $other = $this->savedReply('Other');

        $this->assertRejected(function () {
            (new CreateSavedReplyTool())->handle(['mailbox_id' => $this->mailbox->id, 'name' => 'Taken', 'text' => 'x'], $this->admin);
        }, 'already exists');
        $this->assertRejected(function () use ($other) {
            (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $other->id, 'name' => 'Taken'], $this->admin);
        }, 'already exists');

        // Keeping its own name, or using the name in another mailbox, is fine.
        (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $existing->id, 'name' => 'Taken', 'text' => 'New'], $this->admin);
        $elsewhere = Fixtures::mailbox();
        $created = (new CreateSavedReplyTool())->handle(['mailbox_id' => $elsewhere->id, 'name' => 'Taken', 'text' => 'x'], $this->admin);
        $this->assertSame('Taken', $created['name']);
    }

    public function test_parent_must_be_in_the_same_mailbox_and_never_create_a_cycle()
    {
        $top = $this->savedReply('Top');
        $middle = $this->savedReply('Middle', 'x', null, ['parent_saved_reply_id' => $top->id]);
        $bottom = $this->savedReply('Bottom', 'x', null, ['parent_saved_reply_id' => $middle->id]);
        $foreignReply = $this->savedReply('Foreign', 'x', Fixtures::mailbox());
        $update = function ($id, $parent) {
            return (new UpdateSavedReplyTool())->handle(['saved_reply_id' => $id, 'parent_id' => $parent], $this->admin);
        };

        $this->assertRejected(function () use ($update, $foreignReply, $top) {
            $update($top->id, $foreignReply->id);
        }, 'must be a saved reply of the same mailbox');
        $this->assertRejected(function () use ($update, $top) {
            $update($top->id, 999999999);
        }, 'must be a saved reply of the same mailbox');
        $this->assertRejected(function () use ($update, $top) {
            $update($top->id, $top->id);
        }, 'its own ancestor');
        $this->assertRejected(function () use ($update, $top, $bottom) {
            $update($top->id, $bottom->id);
        }, 'its own ancestor');
        $this->assertNull(SavedReply::find($top->id)->parent_saved_reply_id);

        $created = (new CreateSavedReplyTool())->handle(['mailbox_id' => $this->mailbox->id, 'name' => 'Leaf', 'text' => 'x', 'parent_id' => $bottom->id], $this->admin);
        $this->assertSame((int) $bottom->id, $created['parent_id']);
        $this->assertRejected(function () use ($foreignReply) {
            (new CreateSavedReplyTool())->handle(['mailbox_id' => $this->mailbox->id, 'name' => 'Bad', 'text' => 'x', 'parent_id' => $foreignReply->id], $this->admin);
        }, 'same mailbox');

        $this->assertSame((int) $top->id, $update($bottom->id, $top->id)['parent_id']);
        // Zero moves a reply to the top level.
        $this->assertNull($update($bottom->id, 0)['parent_id']);
    }

    public function test_delete_removes_attachments_and_detaches_children()
    {
        $parent = $this->savedReply('Parent');
        $child = $this->savedReply('Child', 'x', null, ['parent_saved_reply_id' => $parent->id]);
        $attachment = $this->withAttachment($parent);

        (new DeleteSavedReplyTool())->handle(['saved_reply_id' => $parent->id], $this->admin);

        $this->assertNull(SavedReply::find($parent->id));
        $this->assertNull(Attachment::find($attachment->id));
        $this->assertNull(SavedReply::find($child->id)->parent_saved_reply_id);
    }

    public function test_tools_hidden_when_module_inactive()
    {
        $registry = app(ToolRegistry::class);
        $names = function () use ($registry) {
            return array_map(function ($tool) {
                return $tool->name();
            }, $registry->available($this->admin));
        };

        $this->withCompanions(false, function () use ($names, $registry) {
            $this->assertSame([], array_intersect(self::TOOLS, $names()));
            foreach (self::TOOLS as $name) {
                $this->assertNull($registry->find($name, $this->admin), $name);
            }
        });

        $this->withCompanions(true, function () use ($names, $registry) {
            $this->assertSame([], array_diff(self::TOOLS, $names()));
            foreach (self::TOOLS as $name) {
                $this->assertNotNull($registry->find($name, $this->admin), $name);
            }
        });
    }

    public function test_tool_annotations()
    {
        foreach ([new ListSavedRepliesTool(), new GetSavedReplyTool()] as $tool) {
            $this->assertTrue($tool->annotations()['readOnlyHint']);
        }
        foreach ([new CreateSavedReplyTool(), new UpdateSavedReplyTool()] as $tool) {
            $this->assertFalse($tool->annotations()['readOnlyHint']);
            $this->assertFalse($tool->annotations()['destructiveHint']);
        }
        $this->assertTrue((new UpdateSavedReplyTool())->annotations()['idempotentHint']);
        $this->assertFalse((new DeleteSavedReplyTool())->annotations()['readOnlyHint']);
        $this->assertTrue((new DeleteSavedReplyTool())->annotations()['destructiveHint']);
    }

    public function test_draft_from_saved_reply_uses_text_and_copies_attachments()
    {
        $reply = $this->savedReply('Greeting', '<p>Hi {%customer.firstName%}, this is {%user.firstName%}.</p><script>x()</script>');
        $original = $this->withAttachment($reply, 'the file');

        $draft = $this->draftReply(['saved_reply_id' => $reply->id]);

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->state);
        $this->assertSame('<p>Hi Test, this is Agent.</p>', trim($draft->body));
        $this->assertSame(['saved_reply_id' => (int) $reply->id], DraftChoices::get($draft));

        $copies = Attachment::where('thread_id', $draft->id)->get();
        $this->assertCount(1, $copies);
        $copy = $copies->first();
        $this->assertNotSame((int) $original->id, (int) $copy->id);
        $this->assertSame($original->file_name, $copy->file_name);
        $this->assertTrue($copy->fileExists());
        $this->assertTrue($draft->fresh()->has_attachments == true);

        // The saved reply keeps its own attachment.
        $this->assertSame([(int) $original->id], array_map('intval', SavedReply::find($reply->id)->attachments));
        $this->assertNull(Attachment::find($original->id)->thread_id);
        $this->assertTrue(Attachment::find($original->id)->fileExists());
        $this->assertSame(1, Attachment::where('file_name', $original->file_name)->whereNull('thread_id')->count());
    }

    public function test_variables_are_replaced_in_a_body_taken_from_a_saved_reply()
    {
        $reply = $this->savedReply('Variables', '<p>Dear {%customer.firstName%} {%customer.lastName%}, re: {%subject%}. {%customer.company,fallback=No company%}</p>');

        $draft = $this->draftReply(['saved_reply_id' => $reply->id]);

        $this->assertSame('<p>Dear Test Customer, re: Test conversation. No company</p>', trim($draft->body));
        // The stored text keeps its variables.
        $this->assertStringContainsString('{%customer.firstName%}', SavedReply::find($reply->id)->text);
    }

    public function test_body_wins_over_the_saved_reply_text_but_attachments_are_copied()
    {
        $reply = $this->savedReply('Greeting', '<p>Saved text</p>');
        $original = $this->withAttachment($reply);

        $draft = $this->draftReply(['saved_reply_id' => $reply->id, 'body' => 'My own text']);

        $this->assertSame('My own text', $draft->body);
        $this->assertSame(['saved_reply_id' => (int) $reply->id], DraftChoices::get($draft));
        $this->assertSame([$original->file_name], Attachment::where('thread_id', $draft->id)->pluck('file_name')->all());
    }

    public function test_reply_needs_a_body_or_a_saved_reply()
    {
        $this->assertRejected(function () {
            $this->draftReply();
        }, 'Argument "body" is required.');
        $this->assertRejected(function () {
            $this->draftReply(['body' => '  ']);
        }, 'body');
    }

    public function test_new_conversation_draft_from_saved_reply()
    {
        $reply = $this->savedReply('Greeting', '<p>Hello {%customer.firstName%} / {%user.firstName%}</p>');
        $this->withAttachment($reply);

        $result = (new CreateConversationTool())->handle([
            'mailbox_id'     => $this->mailbox->id,
            'to'             => ['customer@example.test'],
            'subject'        => 'Welcome',
            'saved_reply_id' => $reply->id,
        ], $this->user);

        $draft = Thread::find($result['draft_id']);
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->state);
        $this->assertSame(Conversation::STATE_DRAFT, (int) $draft->conversation->state);
        $this->assertSame('<p>Hello Test / Agent</p>', trim($draft->body));
        $this->assertSame(1, Attachment::where('thread_id', $draft->id)->count());
        $this->assertSame(['saved_reply_id' => (int) $reply->id], $result['draft_choices']);
        // The autosave the text needs does not leave a second draft behind.
        $this->assertSame(1, Thread::where('conversation_id', $draft->conversation_id)->count());

        $withBody = (new CreateConversationTool())->handle([
            'mailbox_id'     => $this->mailbox->id,
            'to'             => ['customer@example.test'],
            'subject'        => 'Second',
            'body'           => 'Own body',
            'saved_reply_id' => $reply->id,
        ], $this->user);
        $this->assertSame('Own body', Thread::find($withBody['draft_id'])->body);
        $this->assertSame(1, Attachment::where('thread_id', $withBody['draft_id'])->count());
    }

    public function test_update_draft_applies_a_saved_reply()
    {
        $reply = $this->savedReply('Greeting', '<p>Hi {%customer.firstName%}</p>');
        $this->withAttachment($reply);
        $draft = $this->draftReply(['body' => 'First version']);

        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'saved_reply_id' => $reply->id], $this->user);

        $updated = Thread::find($draft->id);
        $this->assertSame('<p>Hi Test</p>', trim($updated->body));
        $this->assertSame(['saved_reply_id' => (int) $reply->id], DraftChoices::get($updated));
        $this->assertSame(1, Attachment::where('thread_id', $draft->id)->count());

        // With a body the body is kept and the attachments of the saved reply are added.
        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'saved_reply_id' => $reply->id, 'body' => 'Kept body'], $this->user);
        $this->assertSame('Kept body', Thread::find($draft->id)->body);
        $this->assertSame(2, Attachment::where('thread_id', $draft->id)->count());
    }

    /**
     * The attachment files on disk that were made by the tests of this class.
     */
    private function testFiles(): int
    {
        return count(array_filter(Attachment::getDisk()->allFiles(Attachment::DIRECTORY), function ($path) {
            return strpos(basename($path), 'mcp-test-') === 0;
        }));
    }

    public function test_a_core_refusal_removes_the_copied_attachments()
    {
        // Core refuses a message equal to the user's latest one in the conversation.
        $previous = new Thread();
        $previous->forceFill([
            'conversation_id'    => $this->conversation->id,
            'type'               => Thread::TYPE_MESSAGE,
            'status'             => Conversation::STATUS_ACTIVE,
            'state'              => Thread::STATE_PUBLISHED,
            'body'               => '<p>Same</p>',
            'created_by_user_id' => $this->user->id,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
        ]);
        $previous->save();
        $this->publishedAtStart++;

        $reply = $this->savedReply('Same', '<p>Same</p>');
        $this->withAttachment($reply);
        $this->withAttachment($reply);
        $attachments = Attachment::count();
        $files = $this->testFiles();

        $this->assertRejected(function () use ($reply) {
            $this->draftReply(['saved_reply_id' => $reply->id]);
        }, 'already sent this message');

        $this->assertSame($attachments, Attachment::count());
        $this->assertSame($files, $this->testFiles());
    }

    public function test_a_missing_file_of_the_saved_reply_leaves_no_copies_behind()
    {
        $reply = $this->savedReply('Broken files');
        $this->withAttachment($reply);
        $lost = $this->withAttachment($reply);
        Attachment::getDisk()->delete($lost->getStorageFilePath());
        $attachments = Attachment::count();
        $files = $this->testFiles();
        $drafts = Thread::where('state', Thread::STATE_DRAFT)->count();

        $this->assertRejected(function () use ($reply) {
            $this->draftReply(['saved_reply_id' => $reply->id]);
        }, 'could not be copied');

        $this->assertSame($attachments, Attachment::count());
        $this->assertSame($files, $this->testFiles());
        $this->assertSame($drafts, Thread::where('state', Thread::STATE_DRAFT)->count());
    }

    public function test_a_failure_after_the_first_save_of_a_new_conversation_leaves_no_draft()
    {
        $reply = $this->savedReply('Greeting', '<p>Hello {%customer.firstName%}</p>');
        $this->withAttachment($reply);
        $conversations = Conversation::count();
        $threads = Thread::count();
        $attachments = Attachment::count();

        // The text of the saved reply is built after the empty draft was saved.
        $failing = function () {
            throw new \RuntimeException('Variables are broken.');
        };
        \Eventy::addFilter('mail_vars.replace', $failing, 20, 2);
        try {
            $this->assertRejectedWith(\RuntimeException::class, function () use ($reply) {
                (new CreateConversationTool())->handle([
                    'mailbox_id'     => $this->mailbox->id,
                    'to'             => ['customer@example.test'],
                    'subject'        => 'Welcome',
                    'saved_reply_id' => $reply->id,
                ], $this->user);
            });
        } finally {
            \Eventy::removeFilter('mail_vars.replace', $failing, 20);
        }

        $this->assertSame($conversations, Conversation::count());
        $this->assertSame($threads, Thread::count());
        $this->assertSame($attachments, Attachment::count());
    }

    public function test_saved_reply_from_inaccessible_mailbox_rejected()
    {
        $foreign = Fixtures::mailbox([], [Fixtures::user()]);
        $foreignReply = $this->savedReply('Foreign', '<p>secret</p>', $foreign);
        $this->withAttachment($foreignReply);
        $attachmentsBefore = Attachment::count();
        $draftsBefore = Thread::where('state', Thread::STATE_DRAFT)->count();

        foreach ([$foreignReply->id, 999999999] as $id) {
            $this->assertRejected(function () use ($id) {
                $this->draftReply(['saved_reply_id' => $id]);
            }, self::NOT_FOUND);
            $this->assertRejected(function () use ($id) {
                (new CreateConversationTool())->handle([
                    'mailbox_id' => $this->mailbox->id, 'to' => ['a@example.test'], 'subject' => 'S', 'saved_reply_id' => $id,
                ], $this->user);
            }, self::NOT_FOUND);
        }
        $draft = $this->draftReply(['body' => 'Hi']);
        $draftsBefore++;
        $this->assertRejected(function () use ($draft, $foreignReply) {
            (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'saved_reply_id' => $foreignReply->id], $this->user);
        }, self::NOT_FOUND);

        $this->assertSame($draftsBefore, Thread::where('state', Thread::STATE_DRAFT)->count());
        $this->assertSame($attachmentsBefore, Attachment::count());
    }

    public function test_saved_reply_of_another_accessible_mailbox_needs_to_be_global()
    {
        $other = Fixtures::mailbox([], [$this->user]);
        $local = $this->savedReply('Local', '<p>local</p>', $other);
        $global = $this->savedReply('Global', '<p>global</p>', $other, ['global' => true]);
        $underGlobal = $this->savedReply('Under', '<p>under</p>', $other, ['parent_saved_reply_id' => $global->id]);

        $this->assertRejected(function () use ($local) {
            $this->draftReply(['saved_reply_id' => $local->id]);
        }, 'saved_reply_id is not available in this mailbox.');

        $this->assertSame('<p>global</p>', trim($this->draftReply(['saved_reply_id' => $global->id])->body));
        $this->assertSame('<p>under</p>', trim($this->draftReply(['saved_reply_id' => $underGlobal->id])->body));
    }

    public function test_global_reply_of_a_mailbox_the_user_cannot_see_can_be_used()
    {
        $foreign = Fixtures::mailbox([], [Fixtures::user()]);
        $global = $this->savedReply('Global', '<p>shared</p>', $foreign, ['global' => true]);
        $original = $this->withAttachment($global);

        $draft = $this->draftReply(['saved_reply_id' => $global->id]);

        $this->assertSame('<p>shared</p>', trim($draft->body));
        $this->assertSame(1, Attachment::where('thread_id', $draft->id)->count());
        $this->assertNull(Attachment::find($original->id)->thread_id);
    }

    public function test_saved_reply_choice_is_applied_on_send()
    {
        $reply = $this->savedReply('Greeting', '<p>Hello</p>');
        $draft = $this->draftReply(['saved_reply_id' => $reply->id]);

        $this->sendsOnPurpose = true;
        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $sent = Thread::find($draft->id);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $sent->state);
        $this->assertSame((int) $reply->id, (int) $sent->saved_reply_id);
    }

    public function test_send_revalidates_the_saved_reply()
    {
        $this->sendsOnPurpose = true;
        $other = Fixtures::mailbox([], [$this->user]);
        $cases = [
            'deleted' => function (SavedReply $reply) {
                $reply->deleteSavedReply();
            },
            'moved'   => function (SavedReply $reply) use ($other) {
                $reply->mailbox_id = $other->id;
                $reply->save();
            },
            'hidden'  => function (SavedReply $reply) {
                $reply->mailbox_id = Fixtures::mailbox([], [Fixtures::user()])->id;
                $reply->save();
            },
        ];

        foreach ($cases as $name => $change) {
            $reply = $this->savedReply($name);
            $draft = $this->draftReply(['saved_reply_id' => $reply->id]);
            $change($reply);
            $jobs = $this->jobCount();
            $published = $this->publishedMessageCount();

            $this->assertRejected(function () use ($draft) {
                (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            }, 'update_draft');

            $this->assertSame($jobs, $this->jobCount(), $name);
            $this->assertSame($published, $this->publishedMessageCount(), $name);
            $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state, $name);
        }
    }

    public function test_send_refuses_a_saved_reply_when_the_module_was_deactivated()
    {
        $draft = $this->draftReply(['saved_reply_id' => $this->savedReply('Greeting')->id]);
        $jobs = $this->jobCount();

        $this->withCompanions(false, function () use ($draft) {
            $this->assertRejected(function () use ($draft) {
                (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            }, 'Saved Replies module');
        });

        $this->assertSame($jobs, $this->jobCount());
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
    }

    public function test_a_deleted_saved_reply_can_be_cleared_and_the_draft_sent()
    {
        $this->sendsOnPurpose = true;
        $reply = $this->savedReply('Gone', '<p>Reply text</p>');
        $draft = $this->draftReply(['saved_reply_id' => $reply->id]);
        $reply->deleteSavedReply();

        $this->assertRejected(function () use ($draft) {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
        }, 'saved_reply_id: null');
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);

        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'saved_reply_id' => null], $this->user);
        $this->assertSame('<p>Reply text</p>', trim(Thread::find($draft->id)->body), 'The body stays as it is.');
        $this->assertSame([], DraftChoices::get(Thread::find($draft->id)));

        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $sent = Thread::find($draft->id);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $sent->state);
        $this->assertEmpty($sent->saved_reply_id);
    }

    public function test_a_corrected_saved_reply_can_be_sent()
    {
        $this->sendsOnPurpose = true;
        $broken = $this->savedReply('Broken');
        $draft = $this->draftReply(['saved_reply_id' => $broken->id]);
        $broken->deleteSavedReply();
        $replacement = $this->savedReply('Replacement', '<p>Better</p>');

        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'saved_reply_id' => $replacement->id], $this->user);
        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame((int) $replacement->id, (int) Thread::find($draft->id)->saved_reply_id);
    }
}
