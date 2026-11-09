<?php

namespace Modules\McpForFreescout\Tools\Drafts;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CoreActions;

class DiscardDraftTool extends DraftTool
{
    public function name(): string
    {
        return 'discard_draft';
    }

    public function description(): string
    {
        return 'Delete an unsent draft made with reply_to_conversation or create_conversation. Nothing is sent. '
            .'Discarding the draft of a new conversation deletes that conversation too. Published messages are never touched.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'draft_id' => ['type' => 'integer', 'description' => 'Draft id, as returned by reply_to_conversation or create_conversation.'],
            ],
            'required'             => ['draft_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $draft = $this->findDraft($this->requireInt($arguments, 'draft_id'), $user);
        $draftId = (int) $draft->id;
        $conversationId = (int) $draft->conversation_id;

        // Core deletes the whole conversation of a new-conversation draft; it must hold nothing but drafts.
        $conversation = $draft->conversation;
        if ((int) $conversation->state === Conversation::STATE_DRAFT && $this->hasPublishedThreads($conversation)) {
            throw new ToolError('This draft belongs to a conversation that already has sent or received messages; discarding it would delete them. Nothing was changed.');
        }

        CoreActions::conversationAjax($user, [
            'action'    => 'discard_draft',
            'thread_id' => $draftId,
        ]);

        return [
            'discarded'       => true,
            'draft_id'        => $draftId,
            'conversation_id' => $conversationId,
        ];
    }

    private function hasPublishedThreads(Conversation $conversation): bool
    {
        return $conversation->threads()
            ->where(function ($query) {
                $query->where('state', '!=', Thread::STATE_DRAFT)->orWhereNull('state');
            })
            ->exists();
    }
}
