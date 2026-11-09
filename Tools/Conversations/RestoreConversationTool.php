<?php

namespace Modules\McpForFreescout\Tools\Conversations;

use App\Conversation;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class RestoreConversationTool extends Tool
{
    public function name(): string
    {
        return 'restore_conversation';
    }

    public function description(): string
    {
        return 'Bring a conversation back from the trash. A conversation that is not in the trash is left as it is. '
            .'Requires the permission to delete conversations. Returns the conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Id of a deleted conversation, from list_conversations with folder "deleted".'],
            ],
            'required'             => ['conversation_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversation = Lookup::conversation($this->requireInt($arguments, 'conversation_id'), $user);
        if (!$user->can('delete', $conversation)) {
            throw new ToolError('You are not allowed to restore this conversation.');
        }

        if ((int) $conversation->state === Conversation::STATE_DELETED) {
            CoreActions::conversationAjax($user, [
                'action'          => 'restore_conversation',
                'conversation_id' => $conversation->id,
            ]);
        }

        return Presenter::conversationSummary($conversation->fresh());
    }
}
