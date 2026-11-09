<?php

namespace Modules\McpForFreescout\Tools\SendLater;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\SendLater;

/**
 * Turns a message scheduled with Send Later back into a draft. It is offered
 * only while that module is active.
 */
class CancelScheduledSendTool extends Tool
{
    public function name(): string
    {
        return 'cancel_scheduled_send';
    }

    public function description(): string
    {
        return 'Cancel a scheduled send: the message that send_draft scheduled with send_at is not sent and becomes a draft again, '
            .'which can be changed with update_draft and then sent or rescheduled with send_draft. '
            .'Fails when the thread is not a scheduled message, for example when it has already been sent. Requires the Send Later module and the database queue driver of FreeScout.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'thread_id' => ['type' => 'integer', 'description' => 'Id of the scheduled message (the thread_id returned by send_draft, or the id of a thread with a scheduled_at).'],
            ],
            'required'             => ['thread_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function isAvailable(User $user): bool
    {
        return CompanionModules::isActive(CompanionModules::SEND_LATER);
    }

    public function handle(array $arguments, User $user): array
    {
        $thread = $this->findScheduledMessage($this->requireInt($arguments, 'thread_id'), $user);

        CoreActions::moduleAjax($user, SendLater::CONTROLLER, ['action' => 'cancel', 'thread_id' => $thread->id]);

        $draft = Thread::find($thread->id);
        if ((int) $draft->state !== Thread::STATE_DRAFT) {
            throw new ToolError('The Send Later module did not turn the message back into a draft.');
        }

        return [
            'draft_id'        => (int) $draft->id,
            'conversation_id' => (int) $draft->conversation_id,
            'state'           => 'draft',
        ];
    }

    /**
     * Missing and inaccessible threads give the same answer, so ids cannot be probed.
     *
     * @throws ToolError When the thread is not a scheduled message of a conversation the user may update.
     */
    private function findScheduledMessage(int $threadId, User $user): Thread
    {
        if (!CompanionModules::isActive(CompanionModules::SEND_LATER)) {
            throw new ToolError('Cancelling a scheduled send requires the Send Later module.');
        }
        SendLater::assertQueueCanBeControlled();

        $thread = Thread::find($threadId);
        $conversation = $thread ? Conversation::find($thread->conversation_id) : null;
        if (!$thread || !$conversation || !$user->can('view', $conversation)) {
            throw new ToolError('Scheduled message not found or not accessible.');
        }
        if (!$user->can('update', $conversation)) {
            throw new ToolError('You are not allowed to change this message.');
        }
        if (!SendLater::isScheduled($thread)) {
            throw new ToolError('Thread '.$thread->id.' is not a scheduled message.');
        }

        return $thread;
    }
}
