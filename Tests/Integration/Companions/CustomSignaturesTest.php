<?php

namespace Modules\McpForFreescout\Tests\Integration\Companions;

use App\Mailbox;
use App\Thread;
use App\User;
use Modules\CustomSignatures\Entities\CustomSignature;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Drafts\ReplyToConversationTool;
use Modules\McpForFreescout\Tools\Drafts\SendDraftTool;
use Modules\McpForFreescout\Tools\Drafts\UpdateDraftTool;
use Modules\McpForFreescout\Tools\Mailboxes\GetMailboxTool;
use Modules\McpForFreescout\Tools\Mailboxes\UpdateMailboxTool;
use Modules\McpForFreescout\Tools\Signatures\CreateSignatureTool;
use Modules\McpForFreescout\Tools\Signatures\DeleteSignatureTool;
use Modules\McpForFreescout\Tools\Signatures\ListSignaturesTool;
use Modules\McpForFreescout\Tools\Signatures\UpdateSignatureTool;

class CustomSignaturesTest extends TestCase
{
    private const TOOLS = ['list_signatures', 'create_signature', 'update_signature', 'delete_signature'];

    private $admin;
    private $user;
    private $mailbox;
    private $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireModule(CompanionModules::CUSTOM_SIGNATURES);

        $this->admin = Fixtures::user([], User::ROLE_ADMIN);
        $this->user = Fixtures::user();
        $this->mailbox = Fixtures::mailbox([], [$this->user]);
        $this->conversation = Fixtures::conversation($this->mailbox, Fixtures::customer('customer@example.test'));
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    /**
     * A user whose manage rights on the mailbox are limited to $access (Mailbox::ACCESS_PERM_* values).
     */
    private function manager(array $access): User
    {
        $manager = Fixtures::user();
        $this->mailbox->users()->attach($manager->id, ['access' => json_encode($access)]);

        return User::find($manager->id);
    }

    private function signature(string $name = 'Sales', string $text = '<p>Sales team</p>', Mailbox $mailbox = null): CustomSignature
    {
        $signature = new CustomSignature();
        $signature->mailbox_id = ($mailbox ?: $this->mailbox)->id;
        $signature->name = $name;
        $signature->text = $text;
        $signature->save();

        return $signature;
    }

    private function draftReply(array $extra = []): Thread
    {
        $result = (new ReplyToConversationTool())->handle(array_merge([
            'conversation_id' => $this->conversation->id,
            'body'            => 'Original',
        ], $extra), $this->user);

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

    private function jobCount(): int
    {
        return (int) \DB::table('jobs')->count();
    }

    public function test_signature_crud_respects_permission()
    {
        $mailboxId = $this->mailbox->id;
        $existing = $this->signature();

        // Viewing the mailbox is enough to read the signatures.
        $list = (new ListSignaturesTool())->handle(['mailbox_id' => $mailboxId], $this->user);
        $this->assertSame(
            ['mailbox_id' => (int) $mailboxId, 'signatures' => [['id' => (int) $existing->id, 'name' => 'Sales', 'text' => '<p>Sales team</p>']]],
            $list
        );

        // A user without the signature permission cannot change them.
        $denied = 'You are not allowed to change the signatures of this mailbox.';
        $this->assertRejected(function () use ($mailboxId) {
            (new CreateSignatureTool())->handle(['mailbox_id' => $mailboxId, 'name' => 'New', 'text' => 'Text'], $this->user);
        }, $denied);
        $this->assertRejected(function () use ($existing) {
            (new UpdateSignatureTool())->handle(['signature_id' => $existing->id, 'name' => 'Renamed'], $this->user);
        }, $denied);
        $this->assertRejected(function () use ($existing) {
            (new DeleteSignatureTool())->handle(['signature_id' => $existing->id], $this->user);
        }, $denied);
        $this->assertSame('Sales', CustomSignature::find($existing->id)->name);
        $this->assertSame(1, CustomSignature::where('mailbox_id', $mailboxId)->count());

        // A user with the signature permission can.
        $manager = $this->manager([Mailbox::ACCESS_PERM_SIGNATURE]);
        $created = (new CreateSignatureTool())->handle(['mailbox_id' => $mailboxId, 'name' => 'Support', 'text' => '<p>Support</p>'], $manager);
        $this->assertSame(['id', 'mailbox_id', 'name', 'text'], array_keys($created));
        $this->assertSame((int) $mailboxId, $created['mailbox_id']);
        $this->assertSame('Support', $created['name']);

        $updated = (new UpdateSignatureTool())->handle(['signature_id' => $created['id'], 'name' => 'Helpdesk'], $manager);
        $this->assertSame(['id' => $created['id'], 'mailbox_id' => (int) $mailboxId, 'name' => 'Helpdesk', 'text' => '<p>Support</p>'], $updated);

        $deleted = (new DeleteSignatureTool())->handle(['signature_id' => $created['id']], $manager);
        $this->assertSame(['deleted' => true, 'signature_id' => $created['id']], $deleted);
        $this->assertNull(CustomSignature::find($created['id']));

        // An administrator can too.
        $adminCreated = (new CreateSignatureTool())->handle(['mailbox_id' => $mailboxId, 'name' => 'Admin', 'text' => 'Text'], $this->admin);
        $this->assertSame('Admin', CustomSignature::find($adminCreated['id'])->name);
    }

    public function test_signatures_of_inaccessible_mailboxes_are_not_revealed()
    {
        $foreign = Fixtures::mailbox([], [Fixtures::user()]);
        $signature = $this->signature('Foreign', 'Text', $foreign);

        $this->assertRejected(function () use ($foreign) {
            (new ListSignaturesTool())->handle(['mailbox_id' => $foreign->id], $this->user);
        }, 'Mailbox not found or not accessible.');
        $this->assertRejected(function () use ($foreign) {
            (new CreateSignatureTool())->handle(['mailbox_id' => $foreign->id, 'name' => 'x', 'text' => 'y'], $this->user);
        }, 'Mailbox not found or not accessible.');
        foreach ([new UpdateSignatureTool(), new DeleteSignatureTool()] as $tool) {
            $this->assertRejected(function () use ($tool, $signature) {
                $tool->handle(['signature_id' => $signature->id, 'name' => 'x'], $this->user);
            }, 'Signature not found or not accessible.');
        }
        $this->assertRejected(function () {
            (new DeleteSignatureTool())->handle(['signature_id' => 999999999], $this->admin);
        }, 'Signature not found or not accessible.');
    }

    public function test_signature_input_is_validated_and_sanitised()
    {
        $create = function (array $arguments) {
            return (new CreateSignatureTool())->handle(['mailbox_id' => $this->mailbox->id] + $arguments, $this->admin);
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
        $this->assertSame(0, CustomSignature::where('mailbox_id', $this->mailbox->id)->count());

        $name = str_repeat('é', 75);
        $plain = $create(['name' => $name, 'text' => "Line 1\nA & B"]);
        $this->assertSame($name, $plain['name']);
        $this->assertSame('Line 1<br>A &amp; B', preg_replace('/<br>\s*/', '<br>', $plain['text']));

        $html = $create(['name' => 'Html', 'text' => '<p onclick="x()">Hi</p><script>alert(1)</script>']);
        $this->assertStringNotContainsString('script', $html['text']);
        $this->assertStringNotContainsString('onclick', $html['text']);
        $this->assertStringContainsString('Hi', $html['text']);

        $updated = (new UpdateSignatureTool())->handle(['signature_id' => $html['id'], 'text' => '<p>Clean</p><script>x</script>'], $this->admin);
        $this->assertSame('<p>Clean</p>', $updated['text']);
        $this->assertSame('Html', $updated['name']);

        $this->assertRejected(function () use ($html) {
            (new UpdateSignatureTool())->handle(['signature_id' => $html['id']], $this->admin);
        }, 'at least one');
        $this->assertRejected(function () use ($html) {
            (new UpdateSignatureTool())->handle(['signature_id' => $html['id'], 'name' => ''], $this->admin);
        }, 'name');
    }

    public function test_signature_tools_hidden_when_module_inactive()
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

    public function test_signature_tool_annotations()
    {
        $annotations = function ($tool) {
            return $tool->annotations();
        };

        $this->assertTrue($annotations(new ListSignaturesTool())['readOnlyHint']);
        foreach ([new CreateSignatureTool(), new UpdateSignatureTool()] as $tool) {
            $this->assertFalse($annotations($tool)['readOnlyHint']);
            $this->assertFalse($annotations($tool)['destructiveHint']);
        }
        $this->assertTrue($annotations(new UpdateSignatureTool())['idempotentHint']);
        $this->assertFalse($annotations(new DeleteSignatureTool())['readOnlyHint']);
        $this->assertTrue($annotations(new DeleteSignatureTool())['destructiveHint']);
    }

    public function test_get_mailbox_lists_the_signatures_when_the_module_is_active()
    {
        $signature = $this->signature();

        $result = (new GetMailboxTool())->handle(['mailbox_id' => $this->mailbox->id], $this->user);

        $this->assertSame([['id' => (int) $signature->id, 'name' => 'Sales']], $result['custom_signatures']);
    }

    public function test_send_draft_applies_chosen_signature()
    {
        $signature = $this->signature();
        $draft = $this->draftReply(['signature_id' => $signature->id]);

        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $sent = Thread::find($draft->id);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $sent->state);
        $this->assertSame((int) $signature->id, (int) $sent->getMeta('cs.signature_id'));
    }

    public function test_send_draft_without_signature_choice_stores_none()
    {
        $this->signature();
        $draft = $this->draftReply();

        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertEmpty(Thread::find($draft->id)->getMeta('cs.signature_id'));
    }

    public function test_signature_from_other_mailbox_rejected()
    {
        $foreign = $this->signature('Foreign', 'Text', Fixtures::mailbox());
        $before = Thread::where('state', Thread::STATE_DRAFT)->count();

        foreach ([$foreign->id, 999999999] as $signatureId) {
            $this->assertRejected(function () use ($signatureId) {
                $this->draftReply(['signature_id' => $signatureId]);
            }, 'signature_id does not belong to this mailbox.');
        }

        $draft = $this->draftReply();
        $this->assertRejected(function () use ($draft, $foreign) {
            (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'signature_id' => $foreign->id], $this->user);
        }, 'signature_id does not belong to this mailbox.');

        $this->assertSame($before + 1, Thread::where('state', Thread::STATE_DRAFT)->count());
    }

    public function test_send_revalidates_the_signature()
    {
        $other = Fixtures::mailbox([], [$this->user]);
        $cases = [
            'deleted' => function (CustomSignature $signature) {
                $signature->deleteSignature();
            },
            'moved'   => function (CustomSignature $signature) use ($other) {
                $signature->mailbox_id = $other->id;
                $signature->save();
            },
        ];

        foreach ($cases as $name => $change) {
            $signature = $this->signature($name);
            $draft = $this->draftReply(['signature_id' => $signature->id]);
            $change($signature);
            $jobs = $this->jobCount();

            $this->assertRejected(function () use ($draft) {
                (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            }, 'update_draft');

            $this->assertSame($jobs, $this->jobCount(), $name);
            $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state, $name);
        }
    }

    public function test_send_refuses_a_signature_when_the_module_was_deactivated()
    {
        $draft = $this->draftReply(['signature_id' => $this->signature()->id]);
        $jobs = $this->jobCount();

        $this->withCompanions(false, function () use ($draft) {
            $this->assertRejected(function () use ($draft) {
                (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            }, 'Custom Mailbox Signatures module');
        });

        $this->assertSame($jobs, $this->jobCount());
        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
    }

    public function test_a_stale_signature_can_be_cleared_and_the_draft_sent()
    {
        $signature = $this->signature('Stale');
        $draft = $this->draftReply(['signature_id' => $signature->id]);

        // The module is switched off: sending is refused, clearing the choice is not.
        $this->withCompanions(false, function () use ($draft) {
            $this->assertRejected(function () use ($draft) {
                (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
            }, 'signature_id: null');

            $result = (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'signature_id' => null], $this->user);
            $this->assertEquals(new \stdClass(), $result['draft_choices']);

            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
        });

        $this->assertSame(Thread::STATE_PUBLISHED, (int) Thread::find($draft->id)->state);
        $this->assertEmpty(Thread::find($draft->id)->getMeta('cs.signature_id'));
    }

    public function test_a_corrected_signature_can_be_sent()
    {
        $broken = $this->signature('Broken');
        $draft = $this->draftReply(['signature_id' => $broken->id]);
        $broken->deleteSignature();
        $replacement = $this->signature('Replacement');

        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'signature_id' => $replacement->id], $this->user);
        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame((int) $replacement->id, (int) Thread::find($draft->id)->getMeta('cs.signature_id'));
    }

    public function test_update_mailbox_keeps_custom_signatures()
    {
        $signature = $this->signature();

        $result = (new UpdateMailboxTool())->handle([
            'mailbox_id' => $this->mailbox->id,
            'name'       => 'Renamed',
            'signature'  => '<p>Main signature</p>',
        ], $this->admin);

        $this->assertSame('Renamed', $result['name']);
        $this->assertSame([['id' => (int) $signature->id, 'name' => 'Sales']], $result['custom_signatures']);
        $kept = CustomSignature::find($signature->id);
        $this->assertNotNull($kept);
        $this->assertSame('<p>Sales team</p>', $kept->text);
    }
}
