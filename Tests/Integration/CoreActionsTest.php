<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\Conversation;
use App\Thread;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class CoreActionsTest extends TestCase
{
    private $user;
    private $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$this->user]);
        $this->conversation = Fixtures::conversation($mailbox, Fixtures::customer());
    }

    public function test_invokes_core_ajax_as_given_user()
    {
        $result = CoreActions::conversationAjax($this->user, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $this->conversation->id,
            'status'          => Conversation::STATUS_CLOSED,
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $this->conversation->fresh()->status);

        $lineItem = Thread::where('conversation_id', $this->conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)
            ->first();
        $this->assertNotNull($lineItem);
        $this->assertSame((int) $this->user->id, (int) $lineItem->created_by_user_id);
    }

    public function test_core_error_becomes_tool_error()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Status already set');

        CoreActions::conversationAjax($this->user, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $this->conversation->id,
            'status'          => Conversation::STATUS_ACTIVE,
        ]);
    }

    public function test_previous_request_and_user_are_restored()
    {
        $previousUser = Fixtures::user();
        \Auth::setUser($previousUser);
        $previousRequest = app('request');

        CoreActions::conversationAjax($this->user, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $this->conversation->id,
            'status'          => Conversation::STATUS_PENDING,
        ]);

        $this->assertSame($previousRequest, app('request'));
        $this->assertSame((int) $previousUser->id, (int) \Auth::user()->id);
    }

    public function test_previous_request_and_user_are_restored_after_an_error()
    {
        $previousUser = Fixtures::user();
        \Auth::setUser($previousUser);
        $previousRequest = app('request');

        try {
            CoreActions::conversationAjax($this->user, [
                'action'          => 'conversation_change_status',
                'conversation_id' => $this->conversation->id,
                'status'          => Conversation::STATUS_ACTIVE,
            ]);
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertSame($previousRequest, app('request'));
            $this->assertSame((int) $previousUser->id, (int) \Auth::user()->id);
        }
    }
}
