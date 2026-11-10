<?php

namespace Modules\McpForFreescout\Tools\Conversations;

use App\Conversation;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class StarConversationTool extends Tool
{
    public function name(): string
    {
        return 'star_conversation';
    }

    public function description(): string
    {
        return 'Star or unstar a conversation for yourself. Stars are personal: colleagues do not see them. '
            .'Starred conversations are listed by list_conversations with folder "starred", and every conversation '
            .'has a "starred" flag. Starring a conversation that is already starred changes nothing. Returns the conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Conversation id, from list_conversations or search_conversations.'],
                'starred'         => ['type' => 'boolean', 'description' => 'true to star the conversation, false to remove the star.'],
            ],
            'required'             => ['conversation_id', 'starred'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversationId = $this->requireInt($arguments, 'conversation_id');
        $starred = $this->optionalBool($arguments, 'starred');
        if ($starred === null) {
            throw new ToolError('Argument "starred" is required.');
        }

        $conversation = Lookup::conversation($conversationId, $user);
        // The FreeScout UI stars published conversations only.
        if ((int) $conversation->state === Conversation::STATE_DRAFT) {
            throw new ToolError('A draft conversation cannot be starred.');
        }

        if ($starred !== $this->isStarred($conversation, $user)) {
            $starred ? $conversation->star($user) : $conversation->unstar($user);

            // Core ignores the call silently when the user has no personal Starred folder.
            if ($starred !== $this->isStarred($conversation, $user)) {
                throw new ToolError($starred
                    ? 'The conversation could not be starred: you have no Starred folder in this mailbox.'
                    : 'The star could not be removed from the conversation.');
            }
        }

        return Presenter::conversationSummary($conversation->fresh(), $user);
    }

    /**
     * Read fresh from the database: Conversation::isStarredByUser() caches per mailbox.
     */
    private function isStarred(Conversation $conversation, User $user): bool
    {
        return Presenter::starredAmong([$conversation->id], $user) !== [];
    }
}
