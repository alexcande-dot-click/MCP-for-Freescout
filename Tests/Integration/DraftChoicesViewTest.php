<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\Conversation;
use App\Thread;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class DraftChoicesViewTest extends TestCase
{
    private $agent;
    private $colleague;
    private $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Fixtures::user();
        $this->colleague = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$this->agent, $this->colleague]);
        $this->conversation = Fixtures::conversation($mailbox, Fixtures::customer());
    }

    /**
     * The FreeScout conversation page offers "Edit" on every message draft of the
     * conversation to anyone who can view it, so the embedded data covers exactly those.
     */
    public function test_partial_renders_only_viewer_visible_drafts()
    {
        $own = $this->thread(Thread::TYPE_MESSAGE, Thread::STATE_DRAFT, $this->agent->id);
        DraftChoices::store($own, ['signature_id' => 7, 'status' => Conversation::STATUS_CLOSED, 'assignee_id' => $this->colleague->id]);

        $others = $this->thread(Thread::TYPE_MESSAGE, Thread::STATE_DRAFT, $this->colleague->id);
        DraftChoices::store($others, ['assignee_id' => 0]);

        // Not editable through the draft UI: no choices, a note draft, a sent message.
        $this->thread(Thread::TYPE_MESSAGE, Thread::STATE_DRAFT, $this->agent->id);
        $noteDraft = $this->thread(Thread::TYPE_NOTE, Thread::STATE_DRAFT, $this->agent->id);
        DraftChoices::store($noteDraft, ['status' => Conversation::STATUS_ACTIVE]);
        $sent = $this->thread(Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED, $this->agent->id);
        DraftChoices::store($sent, ['status' => Conversation::STATUS_ACTIVE]);

        $choices = $this->renderedChoices();

        $this->assertEquals([
            (string) $own->id => [
                'signature_id' => 7,
                'status'       => Conversation::STATUS_CLOSED,
                'assignee_id'  => $this->colleague->id,
            ],
            (string) $others->id => ['assignee_id' => 0],
        ], $choices);
    }

    public function test_partial_renders_nothing_without_choices()
    {
        $this->thread(Thread::TYPE_MESSAGE, Thread::STATE_DRAFT, $this->agent->id);

        $this->assertSame('', trim($this->render()));
    }

    public function test_attribute_value_is_escaped()
    {
        $draft = $this->thread(Thread::TYPE_MESSAGE, Thread::STATE_DRAFT, $this->agent->id);
        DraftChoices::store($draft, ['signature_id' => 3]);

        $html = $this->render();

        $this->assertStringContainsString('id="mcpff-draft-choices"', $html);
        $this->assertStringContainsString('class="hidden"', $html);
        $this->assertStringNotContainsString("data-choices='", $html);
        $this->assertStringContainsString('data-choices="{&quot;'.$draft->id.'&quot;:{&quot;signature_id&quot;:3}}"', $html);
    }

    private function thread(int $type, int $state, int $userId): Thread
    {
        $thread = new Thread();
        $thread->forceFill([
            'conversation_id'   => $this->conversation->id,
            'type'              => $type,
            'status'            => $this->conversation->status,
            'state'             => $state,
            'body'              => 'Draft body',
            'source_via'        => Thread::PERSON_USER,
            'source_type'       => Thread::SOURCE_TYPE_WEB,
            'user_id'           => $userId,
            'created_by_user_id' => $userId,
        ]);
        $thread->save();

        return $thread;
    }

    private function render(): string
    {
        return view('mcpforfreescout::partials.draft_choices', ['conversation' => $this->conversation])->render();
    }

    private function renderedChoices(): array
    {
        $html = $this->render();
        $this->assertSame(1, preg_match('/data-choices="([^"]*)"/', $html, $match));

        return json_decode(html_entity_decode($match[1]), true);
    }
}
