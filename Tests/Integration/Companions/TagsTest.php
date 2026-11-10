<?php

namespace Modules\McpForFreescout\Tests\Integration\Companions;

use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\Presenter;
use Modules\McpForFreescout\Support\Tags;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Conversations\ListConversationsTool;
use Modules\McpForFreescout\Tools\Tags\AddTagsTool;
use Modules\McpForFreescout\Tools\Tags\CreateTagTool;
use Modules\McpForFreescout\Tools\Tags\DeleteTagTool;
use Modules\McpForFreescout\Tools\Tags\ListTagsTool;
use Modules\McpForFreescout\Tools\Tags\RemoveTagsTool;
use Modules\McpForFreescout\Tools\Tags\UpdateTagTool;
use Modules\Tags\Entities\ConversationTag;
use Modules\Tags\Entities\Tag;

class TagsTest extends TestCase
{
    private const TOOLS = ['list_tags', 'add_tags', 'remove_tags', 'create_tag', 'update_tag', 'delete_tag'];

    private const CONVERSATION_NOT_FOUND = 'Conversation not found or not accessible.';

    private const DENIED = 'You are not allowed to manage tags.';

    private $admin;
    private $user;
    private $mailbox;
    private $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireModule(CompanionModules::TAGS);

        $this->admin = Fixtures::user([], User::ROLE_ADMIN);
        $this->user = Fixtures::user(['first_name' => 'Agent']);
        $this->mailbox = Fixtures::mailbox([], [$this->user]);
        $this->conversation = Fixtures::conversation($this->mailbox, Fixtures::customer());
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    private function tag(string $name, int $color = 0, int $counter = 0): Tag
    {
        $tag = new Tag();
        $tag->name = $name;
        $tag->color = $color;
        $tag->counter = $counter;
        $tag->save();

        return $tag;
    }

    private function counter(string $name): ?int
    {
        $tag = Tag::where('name', $name)->first();

        return $tag ? (int) $tag->counter : null;
    }

    private function conversationTagRows(int $conversationId): int
    {
        return ConversationTag::where('conversation_id', $conversationId)->count();
    }

    /**
     * A user who may manage tags and has access to the test mailbox.
     */
    private function tagManager(): User
    {
        $manager = Fixtures::user();
        $this->mailbox->users()->attach($manager->id);
        $manager = User::find($manager->id);
        $manager->addPermission(User::PERM_EDIT_TAGS);

        return $manager;
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

    public function test_add_and_remove_tags_updates_counters()
    {
        $other = Fixtures::conversation($this->mailbox, Fixtures::customer());

        $added = (new AddTagsTool())->handle([
            'conversation_id' => $this->conversation->id,
            'names'           => ['  VIP ', 'Billing'],
        ], $this->user);
        $this->assertSame(['conversation_id' => (int) $this->conversation->id, 'tags' => ['billing', 'vip']], $added);
        $this->assertSame(1, $this->counter('vip'));
        $this->assertSame(1, $this->counter('billing'));

        (new AddTagsTool())->handle(['conversation_id' => $other->id, 'names' => ['vip']], $this->user);
        $this->assertSame(2, $this->counter('vip'));

        // Adding a tag the conversation has already changes nothing, including the counter.
        $again = (new AddTagsTool())->handle([
            'conversation_id' => $this->conversation->id,
            'names'           => ['VIP', 'vip', 'urgent'],
        ], $this->user);
        $this->assertSame(['billing', 'urgent', 'vip'], $again['tags']);
        $this->assertSame(2, $this->counter('vip'));
        $this->assertSame(1, $this->counter('urgent'));

        $removed = (new RemoveTagsTool())->handle([
            'conversation_id' => $this->conversation->id,
            'names'           => ['Vip', 'billing', 'never-attached'],
        ], $this->user);
        $this->assertSame(['conversation_id' => (int) $this->conversation->id, 'tags' => ['urgent']], $removed);
        $this->assertSame(1, $this->counter('vip'));
        $this->assertSame(['vip'], (new AddTagsTool())->handle(['conversation_id' => $other->id, 'names' => ['vip']], $this->user)['tags']);
        $this->assertSame(1, $this->counter('vip'));

        // The module drops a tag whose last conversation lets go of it.
        (new RemoveTagsTool())->handle(['conversation_id' => $other->id, 'names' => ['vip']], $this->user);
        $this->assertNull($this->counter('vip'));
        $this->assertSame(1, $this->conversationTagRows($this->conversation->id));
        $this->assertSame(0, $this->conversationTagRows($other->id));
        $this->assertNull($this->counter('never-attached'));
    }

    public function test_add_and_remove_validate_names()
    {
        $id = $this->conversation->id;
        foreach ([AddTagsTool::class, RemoveTagsTool::class] as $class) {
            $this->assertRejected(function () use ($class, $id) {
                (new $class())->handle(['conversation_id' => $id], $this->user);
            }, 'Argument "names" is required.');
            $this->assertRejected(function () use ($class, $id) {
                (new $class())->handle(['conversation_id' => $id, 'names' => []], $this->user);
            }, 'Give at least one tag name.');
            $this->assertRejected(function () use ($class, $id) {
                (new $class())->handle(['conversation_id' => $id, 'names' => ['ok', " \u{00A0} "]], $this->user);
            }, 'Tag names must not be empty.');
            $this->assertRejected(function () use ($class, $id) {
                (new $class())->handle(['conversation_id' => $id, 'names' => [str_repeat('a', 192)]], $this->user);
            }, 'at most 191 characters');
            $this->assertRejected(function () use ($class, $id) {
                (new $class())->handle(['conversation_id' => $id, 'names' => 'vip'], $this->user);
            }, 'must be a list of strings');
        }

        $twenty = array_map(function ($i) {
            return 'tag'.$i;
        }, range(1, 20));
        $this->assertCount(20, (new AddTagsTool())->handle(['conversation_id' => $id, 'names' => $twenty], $this->user)['tags']);

        $twentyOne = array_merge($twenty, ['tag21']);
        $this->assertRejected(function () use ($id, $twentyOne) {
            (new AddTagsTool())->handle(['conversation_id' => $id, 'names' => $twentyOne], $this->user);
        }, 'at most 20');
        $this->assertNull($this->counter('tag21'));
    }

    public function test_tagging_an_inaccessible_conversation_is_rejected()
    {
        $foreign = Fixtures::conversation(Fixtures::mailbox([], [Fixtures::user()]), Fixtures::customer());
        $this->tag('vip', 0, 1);
        ConversationTag::insert(['conversation_id' => $foreign->id, 'tag_id' => Tag::where('name', 'vip')->value('id')]);

        foreach ([$foreign->id, 999999999] as $id) {
            $this->assertRejected(function () use ($id) {
                (new AddTagsTool())->handle(['conversation_id' => $id, 'names' => ['vip', 'secret']], $this->user);
            }, self::CONVERSATION_NOT_FOUND);
            $this->assertRejected(function () use ($id) {
                (new RemoveTagsTool())->handle(['conversation_id' => $id, 'names' => ['vip']], $this->user);
            }, self::CONVERSATION_NOT_FOUND);
        }
        $this->assertNull($this->counter('secret'));
        $this->assertSame(1, $this->counter('vip'));
        $this->assertSame(1, $this->conversationTagRows($foreign->id));
    }

    public function test_tagging_follows_the_modules_rule_for_users_without_tag_rights()
    {
        $this->assertFalse(Tag::canUserEditTags($this->user));
        $this->tag('existing', 0, 0);

        // The Tags module lets everybody who can open a conversation tag it, creating tags by name.
        $result = (new AddTagsTool())->handle(['conversation_id' => $this->conversation->id, 'names' => ['existing', 'brand new']], $this->user);

        $this->assertSame(['brand new', 'existing'], $result['tags']);
        $this->assertSame(1, $this->counter('existing'));
        $this->assertSame(1, $this->counter('brand new'));
    }

    public function test_tag_admin_requires_permission()
    {
        $tag = $this->tag('billing', Tag::COLOR_BLUE, 3);
        $user = $this->user;

        $this->assertRejected(function () use ($user) {
            (new CreateTagTool())->handle(['name' => 'new'], $user);
        }, self::DENIED);
        $this->assertRejected(function () use ($tag, $user) {
            (new UpdateTagTool())->handle(['tag_id' => $tag->id, 'name' => 'renamed'], $user);
        }, self::DENIED);
        $this->assertRejected(function () use ($tag, $user) {
            (new DeleteTagTool())->handle(['tag_id' => $tag->id], $user);
        }, self::DENIED);
        $this->assertNull($this->counter('new'));
        $this->assertSame(3, $this->counter('billing'));
        $this->assertNotNull(Tag::find($tag->id));

        $manager = $this->tagManager();
        $created = (new CreateTagTool())->handle(['name' => 'Support', 'color' => 'green'], $manager);
        $this->assertSame(['id', 'name', 'color', 'counter'], array_keys($created));
        $this->assertSame('support', $created['name']);
        $this->assertSame('green', $created['color']);
        $this->assertSame(0, $created['counter']);

        $updated = (new UpdateTagTool())->handle(['tag_id' => $created['id'], 'name' => 'Helpdesk', 'color' => 'lavender'], $manager);
        $this->assertSame(['id' => $created['id'], 'name' => 'helpdesk', 'color' => 'lavender', 'counter' => 0], $updated);
        $this->assertSame(Tag::COLOR_LAVENDER, (int) Tag::find($created['id'])->color);

        // Name and colour can be changed one by one.
        $recoloured = (new UpdateTagTool())->handle(['tag_id' => $created['id'], 'color' => 'red'], $manager);
        $this->assertSame('helpdesk', $recoloured['name']);
        $this->assertSame('red', $recoloured['color']);
        $renamed = (new UpdateTagTool())->handle(['tag_id' => $created['id'], 'name' => 'Desk'], $manager);
        $this->assertSame(['desk', 'red'], [$renamed['name'], $renamed['color']]);

        $deleted = (new DeleteTagTool())->handle(['tag_id' => $created['id']], $manager);
        $this->assertSame(['deleted' => true, 'tag_id' => $created['id']], $deleted);
        $this->assertNull(Tag::find($created['id']));

        // Administrators manage tags without the permission.
        $this->assertSame('admin made', (new CreateTagTool())->handle(['name' => 'Admin Made'], $this->admin)['name']);
    }

    public function test_create_and_update_validate_name_and_color()
    {
        $manager = $this->tagManager();
        $existing = $this->tag('vip', Tag::COLOR_RED, 2);
        $other = $this->tag('other');

        $this->assertRejected(function () use ($manager) {
            (new CreateTagTool())->handle(['name' => 'VIP'], $manager);
        }, 'A tag with this name already exists.');
        $this->assertRejected(function () use ($manager) {
            (new CreateTagTool())->handle(['name' => str_repeat('a', 192)], $manager);
        }, 'at most 191 characters');
        $this->assertRejected(function () use ($manager) {
            (new CreateTagTool())->handle(['name' => 'x', 'color' => 'purple'], $manager);
        }, 'Argument "color" must be one of: default, green, blue, orange, violet, red, brown, yellow, pink, lime, turquoise, lavender.');
        $this->assertNull($this->counter('x'));

        $this->assertRejected(function () use ($manager, $other) {
            (new UpdateTagTool())->handle(['tag_id' => $other->id, 'name' => ' Vip '], $manager);
        }, 'A tag with this name already exists.');
        $this->assertRejected(function () use ($manager, $other) {
            (new UpdateTagTool())->handle(['tag_id' => $other->id], $manager);
        }, 'Give at least one of name or color.');
        $this->assertRejected(function () use ($manager, $other) {
            (new UpdateTagTool())->handle(['tag_id' => $other->id, 'color' => 'nope'], $manager);
        }, 'Argument "color" must be one of');
        $this->assertRejected(function () use ($manager) {
            (new UpdateTagTool())->handle(['tag_id' => 999999999, 'name' => 'x'], $manager);
        }, 'Tag not found.');
        $this->assertRejected(function () use ($manager) {
            (new DeleteTagTool())->handle(['tag_id' => 999999999], $manager);
        }, 'Tag not found.');

        // A tag keeps its own name when it is updated.
        $same = (new UpdateTagTool())->handle(['tag_id' => $existing->id, 'name' => 'VIP', 'color' => 'red'], $manager);
        $this->assertSame(['vip', 'red', 2], [$same['name'], $same['color'], $same['counter']]);
    }

    public function test_every_module_colour_has_a_name()
    {
        $values = [];
        foreach (Tags::COLOR_NAMES as $name) {
            $values[] = Tags::colorValue($name);
        }
        $this->assertSame(Tag::COLOR_DEFAULT, Tags::colorValue('default'));
        $this->assertSame(Tag::COLOR_TURQUOISE, Tags::colorValue('turquoise'));

        $moduleColors = Tag::$colors;
        sort($moduleColors);
        sort($values);
        $this->assertSame($moduleColors, $values);
    }

    public function test_delete_tag_removes_it_from_its_conversations()
    {
        $manager = $this->tagManager();
        $other = Fixtures::conversation($this->mailbox, Fixtures::customer());
        foreach ([$this->conversation, $other] as $conversation) {
            (new AddTagsTool())->handle(['conversation_id' => $conversation->id, 'names' => ['vip', 'keep']], $manager);
        }
        $tagId = Tag::where('name', 'vip')->value('id');
        $this->assertSame(2, ConversationTag::where('tag_id', $tagId)->count());

        (new DeleteTagTool())->handle(['tag_id' => $tagId], $manager);

        $this->assertSame(0, ConversationTag::where('tag_id', $tagId)->count());
        $this->assertNull($this->counter('vip'));
        $this->assertSame(2, $this->counter('keep'));
        $this->assertSame(['keep'], Presenter::conversationSummary($this->conversation->fresh(), $this->user)['tags']);
    }

    public function test_list_tags()
    {
        $this->tag('lt-zebra', Tag::COLOR_PINK, 4);
        $this->tag('lt-alpha', Tag::COLOR_DEFAULT, 0);
        $this->tag('lt-mid_100%', Tag::COLOR_LIME, 1);
        $this->tag('lt-beta', Tag::COLOR_TURQUOISE, 2);
        $this->tag('unrelated');
        $list = function (array $arguments) {
            return array_column((new ListTagsTool())->handle($arguments, $this->user)['tags'], 'name');
        };

        $result = (new ListTagsTool())->handle(['query' => 'lt-'], $this->user);
        $this->assertSame(['tags'], array_keys($result));
        $this->assertSame(['lt-alpha', 'lt-beta', 'lt-mid_100%', 'lt-zebra'], array_column($result['tags'], 'name'));
        $this->assertSame(
            ['id' => (int) Tag::where('name', 'lt-beta')->value('id'), 'name' => 'lt-beta', 'color' => 'turquoise', 'counter' => 2],
            $result['tags'][1]
        );
        $this->assertSame(['default', 'turquoise', 'lime', 'pink'], array_column($result['tags'], 'color'));
        $this->assertContains('unrelated', $list([]));

        // Case-insensitive "contains" search, as in the module's tag autocomplete; wildcards match literally.
        $this->assertSame(['lt-alpha'], $list(['query' => ' LT-ALPH ']));
        $this->assertSame(['lt-beta'], $list(['query' => 'beta']));
        $this->assertSame(['lt-mid_100%'], $list(['query' => 'mid_100%']));
        $this->assertSame([], $list(['query' => 'lt-%a']));
        $this->assertSame([], $list(['query' => 'lt-_eta']));
        $this->assertSame([], $list(['query' => 'no-such-tag']));
    }

    public function test_list_tags_is_capped()
    {
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = ['name' => sprintf('cap-%03d', $i), 'color' => 0, 'counter' => 0];
        }
        Tag::insert($rows);

        $tags = (new ListTagsTool())->handle(['query' => 'cap-'], $this->user)['tags'];

        $this->assertCount(200, $tags);
        $this->assertSame('cap-000', $tags[0]['name']);
        $this->assertSame('cap-199', $tags[199]['name']);
    }

    public function test_list_conversations_filters_by_tag()
    {
        $tagged = Fixtures::conversation($this->mailbox, Fixtures::customer(), ['subject' => 'Tagged']);
        $plain = Fixtures::conversation($this->mailbox, Fixtures::customer(), ['subject' => 'Plain']);
        $otherTag = Fixtures::conversation($this->mailbox, Fixtures::customer(), ['subject' => 'Other tag']);
        (new AddTagsTool())->handle(['conversation_id' => $tagged->id, 'names' => ['vip']], $this->user);
        (new AddTagsTool())->handle(['conversation_id' => $otherTag->id, 'names' => ['billing']], $this->user);

        $list = new ListConversationsTool();
        foreach (['vip', 'VIP', '  Vip '] as $filter) {
            $found = $list->handle(['mailbox_id' => $this->mailbox->id, 'tag' => $filter], $this->user);
            $this->assertSame([(int) $tagged->id], array_column($found['conversations'], 'id'), $filter);
            $this->assertSame(['vip'], $found['conversations'][0]['tags']);
        }

        $this->assertSame([], $list->handle(['mailbox_id' => $this->mailbox->id, 'tag' => 'unknown'], $this->user)['conversations']);
        $all = $list->handle(['mailbox_id' => $this->mailbox->id], $this->user);
        $this->assertContains((int) $plain->id, array_column($all['conversations'], 'id'));
    }

    public function test_summary_includes_tags()
    {
        $this->assertSame([], Presenter::conversationSummary($this->conversation->fresh(), $this->user)['tags']);

        (new AddTagsTool())->handle(['conversation_id' => $this->conversation->id, 'names' => ['Zeta', 'alpha']], $this->user);
        $summary = Presenter::conversationSummary($this->conversation->fresh(), $this->user);
        $this->assertSame(['alpha', 'zeta'], $summary['tags']);

        $this->withCompanions(false, function () {
            $this->assertArrayNotHasKey('tags', Presenter::conversationSummary($this->conversation->fresh(), $this->user));
        });
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

    public function test_annotations()
    {
        $hints = function ($tool) {
            return $tool->annotations();
        };

        $this->assertSame(['readOnlyHint' => true], $hints(new ListTagsTool()));
        $this->assertTrue($hints(new UpdateTagTool())['idempotentHint']);
        foreach ([new AddTagsTool(), new CreateTagTool()] as $tool) {
            $this->assertFalse($hints($tool)['readOnlyHint']);
            $this->assertFalse($hints($tool)['destructiveHint']);
        }
        foreach ([new RemoveTagsTool(), new DeleteTagTool()] as $tool) {
            $this->assertFalse($hints($tool)['readOnlyHint']);
            $this->assertTrue($hints($tool)['destructiveHint']);
        }
    }
}
