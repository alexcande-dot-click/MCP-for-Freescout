<?php

namespace Modules\McpForFreescout\Tools\Conversations;

use App\Conversation;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class DeleteConversationTool extends Tool
{
    public function name(): string
    {
        return 'delete_conversation';
    }

    public function description(): string
    {
        return 'Move a conversation to the trash. It can be brought back with restore_conversation; '
            .'it is never deleted permanently. Requires the permission to delete conversations. Returns the conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Conversation id, from list_conversations or search_conversations.'],
            ],
            'required'             => ['conversation_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversation = Lookup::conversation($this->requireInt($arguments, 'conversation_id'), $user);
        if (!$user->can('delete', $conversation)) {
            throw new ToolError('You are not allowed to delete this conversation.');
        }

        if ((int) $conversation->state !== Conversation::STATE_DELETED) {
            CoreActions::conversationAjax($user, [
                'action'          => 'delete_conversation',
                'conversation_id' => $conversation->id,
            ]);
        }

        return Presenter::conversationSummary($conversation->fresh(), $user);
    }
}
