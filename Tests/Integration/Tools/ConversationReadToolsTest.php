<?php

namespace Modules\McpForFreescout\Tests\Integration\Tools;

use App\Conversation;
use App\Folder;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Conversations\GetConversationTool;
use Modules\McpForFreescout\Tools\Conversations\ListConversationsTool;
use Modules\McpForFreescout\Tools\Conversations\SearchConversationsTool;

class ConversationReadToolsTest extends TestCase
{
    public function test_list_hides_inaccessible_mailboxes()
    {
        $user = Fixtures::user();
        $mine = Fixtures::mailbox([], [$user]);
        $other = Fixtures::mailbox([], [Fixtures::user()]);
        $visible = Fixtures::conversation($mine, Fixtures::customer());
        $hidden = Fixtures::conversation($other, Fixtures::customer());

        $result = (new ListConversationsTool())->handle([], $user);

        $ids = array_column($result['conversations'], 'id');
        $this->assertContains((int) $visible->id, $ids);
        $this->assertNotContains((int) $hidden->id, $ids);

        try {
            (new ListConversationsTool())->handle(['mailbox_id' => $other->id], $user);
            $this->fail('A ToolError was expected for a mailbox the user cannot access.');
        } catch (ToolError $e) {
            $this->assertSame('Mailbox not found or not accessible.', $e->getMessage());
        }
    }

    public function test_list_omits_deleted_conversations_and_drafts_by_default()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $published = Fixtures::conversation($mailbox, Fixtures::customer());
        $deleted = Fixtures::conversation($mailbox, Fixtures::customer(), ['state' => Conversation::STATE_DELETED]);
        $draft = Fixtures::conversation($mailbox, Fixtures::customer(), ['state' => Conversation::STATE_DRAFT]);

        $ids = array_column((new ListConversationsTool())->handle(['mailbox_id' => $mailbox->id], $user)['conversations'], 'id');

        $this->assertSame([(int) $published->id], $ids);
        $this->assertNotContains((int) $deleted->id, $ids);
        $this->assertNotContains((int) $draft->id, $ids);
    }

    public function test_list_filters_by_status_and_assignee_me()
    {
        $user = Fixtures::user();
        $colleague = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user, $colleague]);
        $myActive = Fixtures::conversation($mailbox, Fixtures::customer(), ['user_id' => $user->id]);
        $myClosed = Fixtures::conversation($mailbox, Fixtures::customer(), ['user_id' => $user->id, 'status' => Conversation::STATUS_CLOSED]);
        $theirs = Fixtures::conversation($mailbox, Fixtures::customer(), ['user_id' => $colleague->id]);
        $nobodys = Fixtures::conversation($mailbox, Fixtures::customer());
        $tool = new ListConversationsTool();

        $ids = function (array $arguments) use ($tool, $user, $mailbox) {
            $result = $tool->handle(array_merge(['mailbox_id' => $mailbox->id], $arguments), $user);

            return array_column($result['conversations'], 'id');
        };

        $this->assertEqualsCanonicalizing([(int) $myActive->id, (int) $myClosed->id], $ids(['assignee' => 'me']));
        $this->assertSame([(int) $myClosed->id], $ids(['assignee' => 'me', 'status' => 'closed']));
        $this->assertSame([(int) $theirs->id], $ids(['assignee' => $colleague->id]));
        $this->assertSame([(int) $theirs->id], $ids(['assignee' => (string) $colleague->id]));
        $this->assertSame([(int) $nobodys->id], $ids(['assignee' => 'none']));
        $this->assertEqualsCanonicalizing(
            [(int) $myActive->id, (int) $theirs->id, (int) $nobodys->id],
            $ids(['status' => 'active'])
        );
    }

    public function test_list_rejects_unknown_status_folder_and_assignee()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $tool = new ListConversationsTool();

        foreach ([
            ['status' => 'bogus'],
            ['mailbox_id' => $mailbox->id, 'folder' => 'bogus'],
            ['assignee' => 'someone'],
        ] as $arguments) {
            try {
                $tool->handle($arguments, $user);
                $this->fail('A ToolError was expected for '.json_encode($arguments));
            } catch (ToolError $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_list_orders_by_last_reply_descending()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $old = Fixtures::conversation($mailbox, Fixtures::customer(), ['last_reply_at' => '2026-01-01 10:00:00']);
        $new = Fixtures::conversation($mailbox, Fixtures::customer(), ['last_reply_at' => '2026-02-01 10:00:00']);

        $ids = array_column((new ListConversationsTool())->handle(['mailbox_id' => $mailbox->id], $user)['conversations'], 'id');

        $this->assertSame([(int) $new->id, (int) $old->id], $ids);
    }

    public function test_list_by_folder()
    {
        $user = Fixtures::user();
        $colleague = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user, $colleague]);
        $unassigned = Fixtures::conversation($mailbox, Fixtures::customer());
        $mine = Fixtures::conversation($mailbox, Fixtures::customer(), ['user_id' => $user->id]);
        $theirs = Fixtures::conversation($mailbox, Fixtures::customer(), ['user_id' => $colleague->id]);
        $closed = Fixtures::conversation($mailbox, Fixtures::customer(), ['status' => Conversation::STATUS_CLOSED]);
        $spam = Fixtures::conversation($mailbox, Fixtures::customer(), ['status' => Conversation::STATUS_SPAM]);
        $deleted = Fixtures::conversation($mailbox, Fixtures::customer(), ['state' => Conversation::STATE_DELETED]);
        $starred = Fixtures::conversation($mailbox, Fixtures::customer());
        $starred->addToFolder(Folder::TYPE_STARRED, $user->id);
        $tool = new ListConversationsTool();

        $ids = function (string $folder) use ($tool, $user, $mailbox) {
            return array_column($tool->handle(['mailbox_id' => $mailbox->id, 'folder' => $folder], $user)['conversations'], 'id');
        };

        $this->assertContains((int) $unassigned->id, $ids('unassigned'));
        $this->assertNotContains((int) $mine->id, $ids('unassigned'));
        $this->assertSame([(int) $mine->id], $ids('mine'));
        $this->assertSame([(int) $theirs->id], $ids('assigned'));
        $this->assertSame([(int) $closed->id], $ids('closed'));
        $this->assertSame([(int) $spam->id], $ids('spam'));
        $this->assertSame([(int) $deleted->id], $ids('deleted'));
        $this->assertSame([(int) $starred->id], $ids('starred'));
    }

    public function test_drafts_folder_lists_only_the_callers_drafts()
    {
        $user = Fixtures::user();
        $colleague = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user, $colleague]);
        $mine = Fixtures::conversation($mailbox, Fixtures::customer());
        $theirs = Fixtures::conversation($mailbox, Fixtures::customer());
        $this->addThread($mine, ['type' => Thread::TYPE_MESSAGE, 'state' => Thread::STATE_DRAFT, 'created_by_user_id' => $user->id]);
        $this->addThread($theirs, ['type' => Thread::TYPE_MESSAGE, 'state' => Thread::STATE_DRAFT, 'created_by_user_id' => $colleague->id]);
        $mine->addToFolder(Folder::TYPE_DRAFTS);
        $theirs->addToFolder(Folder::TYPE_DRAFTS);

        $result = (new ListConversationsTool())->handle(['mailbox_id' => $mailbox->id, 'folder' => 'drafts'], $user);

        $this->assertSame([(int) $mine->id], array_column($result['conversations'], 'id'));
    }

    public function test_folder_requires_a_mailbox()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"mailbox_id"');

        (new ListConversationsTool())->handle(['folder' => 'mine'], Fixtures::user());
    }

    public function test_only_assigned_users_see_only_their_conversations()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $user->permissions = [User::PERM_ONLY_ASSIGNED_TICKETS => true];
        $user->save();
        $this->assertTrue($user->canSeeOnlyAssignedConversations());
        $mine = Fixtures::conversation($mailbox, Fixtures::customer(), ['user_id' => $user->id]);
        $other = Fixtures::conversation($mailbox, Fixtures::customer());

        $ids = array_column((new ListConversationsTool())->handle([], $user)['conversations'], 'id');

        $this->assertSame([(int) $mine->id], $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_pagination_bounds()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        for ($i = 1; $i <= 5; $i++) {
            Fixtures::conversation($mailbox, Fixtures::customer(), ['last_reply_at' => '2026-01-0'.$i.' 10:00:00']);
        }
        $tool = new ListConversationsTool();

        $first = $tool->handle(['mailbox_id' => $mailbox->id, 'per_page' => 2], $user);
        $this->assertSame(1, $first['page']);
        $this->assertSame(2, $first['per_page']);
        $this->assertSame(5, $first['total']);
        $this->assertCount(2, $first['conversations']);

        $last = $tool->handle(['mailbox_id' => $mailbox->id, 'per_page' => 2, 'page' => 3], $user);
        $this->assertCount(1, $last['conversations']);

        $beyond = $tool->handle(['mailbox_id' => $mailbox->id, 'per_page' => 2, 'page' => 9], $user);
        $this->assertSame([], $beyond['conversations']);
        $this->assertSame(5, $beyond['total']);

        $clamped = $tool->handle(['mailbox_id' => $mailbox->id, 'per_page' => 5000, 'page' => -4], $user);
        $this->assertSame(100, $clamped['per_page']);
        $this->assertSame(1, $clamped['page']);

        $default = $tool->handle(['mailbox_id' => $mailbox->id], $user);
        $this->assertSame(25, $default['per_page']);
    }

    public function test_tag_filter_rejected_when_tags_inactive()
    {
        $this->withCompanions(false, function () {
            try {
                (new ListConversationsTool())->handle(['tag' => 'vip'], Fixtures::user());
                $this->fail('A ToolError was expected.');
            } catch (ToolError $e) {
                $this->assertSame('The tag filter requires the Tags module.', $e->getMessage());
            }
        });
    }

    public function test_tag_names_are_normalised_like_the_tags_module()
    {
        $this->assertSame('vip', ListConversationsTool::normalizeTagName('VIP'));
        $this->assertSame('vip customer', ListConversationsTool::normalizeTagName("  Vip Customer\u{00A0}"));
        $this->assertSame('übergröße', ListConversationsTool::normalizeTagName('ÜberGröße'));
        $this->assertSame('', ListConversationsTool::normalizeTagName(" \t "));
    }

    public function test_search_finds_by_subject_within_accessible_mailboxes()
    {
        $user = Fixtures::user();
        $mine = Fixtures::mailbox([], [$user]);
        $other = Fixtures::mailbox([], [Fixtures::user()]);
        $found = Fixtures::conversation($mine, Fixtures::customer(), ['subject' => 'Quokkaprinter jammed']);
        $unrelated = Fixtures::conversation($mine, Fixtures::customer(), ['subject' => 'Something else']);
        $hidden = Fixtures::conversation($other, Fixtures::customer(), ['subject' => 'Quokkaprinter hidden']);
        $tool = new SearchConversationsTool();

        $result = $tool->handle(['query' => 'quokkaprinter'], $user);

        $this->assertSame([(int) $found->id], array_column($result['conversations'], 'id'));
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(25, $result['per_page']);
        $this->assertNotContains((int) $unrelated->id, array_column($result['conversations'], 'id'));
        $this->assertNotContains((int) $hidden->id, array_column($result['conversations'], 'id'));

        try {
            $tool->handle(['query' => 'quokkaprinter', 'mailbox_id' => $other->id], $user);
            $this->fail('A ToolError was expected for a mailbox the user cannot access.');
        } catch (ToolError $e) {
            $this->assertSame('Mailbox not found or not accessible.', $e->getMessage());
        }
    }

    public function test_search_counts_conversations_not_matching_threads()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $conversation = Fixtures::conversation($mailbox, Fixtures::customer(), ['subject' => 'Zebramatch']);
        $this->addThread($conversation, ['body' => 'zebramatch again']);
        $this->addThread($conversation, ['body' => 'and zebramatch once more']);

        $result = (new SearchConversationsTool())->handle(['query' => 'zebramatch', 'per_page' => 1], $user);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['conversations']);
    }

    public function test_search_skips_drafts_and_deleted_and_applies_status_filter()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $active = Fixtures::conversation($mailbox, Fixtures::customer(), ['subject' => 'Gnu report']);
        $closed = Fixtures::conversation($mailbox, Fixtures::customer(), ['subject' => 'Gnu report', 'status' => Conversation::STATUS_CLOSED]);
        Fixtures::conversation($mailbox, Fixtures::customer(), ['subject' => 'Gnu report', 'state' => Conversation::STATE_DELETED]);
        Fixtures::conversation($mailbox, Fixtures::customer(), ['subject' => 'Gnu report', 'state' => Conversation::STATE_DRAFT]);
        $tool = new SearchConversationsTool();

        $all = array_column($tool->handle(['query' => 'gnu report', 'mailbox_id' => $mailbox->id], $user)['conversations'], 'id');
        $onlyClosed = array_column($tool->handle(['query' => 'gnu report', 'status' => 'closed'], $user)['conversations'], 'id');

        $this->assertEqualsCanonicalizing([(int) $active->id, (int) $closed->id], $all);
        $this->assertSame([(int) $closed->id], $onlyClosed);
    }

    public function test_search_requires_a_query()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"query"');

        (new SearchConversationsTool())->handle([], Fixtures::user());
    }

    public function test_get_conversation_returns_threads_and_attachment_metadata()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $conversation = Fixtures::conversation($mailbox, Fixtures::customer('asker@example.test'), ['subject' => 'Where is my order']);
        $first = Thread::where('conversation_id', $conversation->id)->first();
        $first->created_at = '2026-01-01 10:00:00';
        $first->save();
        $reply = $this->addThread($conversation, [
            'type'               => Thread::TYPE_MESSAGE,
            'body'               => '<p>On its way.</p>',
            'created_by_user_id' => $user->id,
            'created_at'         => '2026-01-02 10:00:00',
        ]);
        \DB::table('attachments')->insert([
            'thread_id' => $reply->id, 'file_name' => 'label.pdf', 'mime_type' => 'application/pdf',
            'type' => 3, 'size' => 2048, 'embedded' => false, 'file_dir' => '1/2',
        ]);

        $result = (new GetConversationTool())->handle(['conversation_id' => $conversation->id], $user);

        $this->assertSame((int) $conversation->id, $result['id']);
        $this->assertSame('Where is my order', $result['subject']);
        $this->assertSame('asker@example.test', $result['customer']['email']);
        $this->assertSame([(int) $first->id, (int) $reply->id], array_column($result['threads'], 'id'));
        $this->assertSame('customer', $result['threads'][0]['type']);
        $this->assertSame('message', $result['threads'][1]['type']);
        $this->assertStringContainsString('On its way.', $result['threads'][1]['body_text']);
        $this->assertSame([], $result['threads'][0]['attachments']);
        $this->assertCount(1, $result['threads'][1]['attachments']);
        $this->assertSame('label.pdf', $result['threads'][1]['attachments'][0]['file_name']);
        $this->assertSame('application/pdf', $result['threads'][1]['attachments'][0]['mime_type']);
        $this->assertSame(2048, $result['threads'][1]['attachments'][0]['size']);
    }

    public function test_get_conversation_presents_status_change_lines()
    {
        $user = Fixtures::user();
        $conversation = Fixtures::conversation(Fixtures::mailbox([], [$user]), Fixtures::customer());
        $line = $this->addThread($conversation, [
            'type'               => Thread::TYPE_LINEITEM,
            'body'               => null,
            'created_by_user_id' => $user->id,
        ]);

        $result = (new GetConversationTool())->handle(['conversation_id' => $conversation->id], $user);

        $presented = $result['threads'][1];
        $this->assertSame((int) $line->id, $presented['id']);
        $this->assertSame('lineitem', $presented['type']);
        $this->assertSame('', $presented['body_text']);
    }

    public function test_get_conversation_hides_other_users_drafts_and_hidden_threads()
    {
        $user = Fixtures::user();
        $colleague = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user, $colleague]);
        $conversation = Fixtures::conversation($mailbox, Fixtures::customer());
        $published = Thread::where('conversation_id', $conversation->id)->first();
        $ownDraft = $this->addThread($conversation, ['type' => Thread::TYPE_MESSAGE, 'state' => Thread::STATE_DRAFT, 'created_by_user_id' => $user->id]);
        $this->addThread($conversation, ['type' => Thread::TYPE_MESSAGE, 'state' => Thread::STATE_DRAFT, 'created_by_user_id' => $colleague->id]);
        $this->addThread($conversation, ['state' => Thread::STATE_HIDDEN]);
        $tool = new GetConversationTool();

        $withDrafts = $tool->handle(['conversation_id' => $conversation->id], $user);
        $withoutDrafts = $tool->handle(['conversation_id' => $conversation->id, 'include_drafts' => false], $user);

        $this->assertSame([(int) $published->id, (int) $ownDraft->id], array_column($withDrafts['threads'], 'id'));
        $this->assertSame([(int) $published->id], array_column($withoutDrafts['threads'], 'id'));
    }

    public function test_get_conversation_denies_inaccessible()
    {
        $conversation = Fixtures::conversation(Fixtures::mailbox([], [Fixtures::user()]), Fixtures::customer());

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Conversation not found or not accessible.');

        (new GetConversationTool())->handle(['conversation_id' => $conversation->id], Fixtures::user());
    }

    public function test_get_conversation_unknown_id()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Conversation not found or not accessible.');

        (new GetConversationTool())->handle(['conversation_id' => 999999999], Fixtures::user());
    }

    public function test_admin_lists_every_mailbox()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $conversation = Fixtures::conversation(Fixtures::mailbox([], [Fixtures::user()]), Fixtures::customer());

        $ids = array_column((new ListConversationsTool())->handle(['per_page' => 100], $admin)['conversations'], 'id');

        $this->assertContains((int) $conversation->id, $ids);
    }

    private function addThread(Conversation $conversation, array $attrs = []): Thread
    {
        $thread = new Thread();
        $thread->forceFill(array_merge([
            'conversation_id' => $conversation->id,
            'type'            => Thread::TYPE_CUSTOMER,
            'status'          => $conversation->status,
            'state'           => Thread::STATE_PUBLISHED,
            'body'            => 'Another message.',
            'source_via'      => Thread::PERSON_CUSTOMER,
            'source_type'     => Thread::SOURCE_TYPE_EMAIL,
        ], $attrs));
        $thread->save();

        return $thread;
    }
}
