<?php

namespace Modules\McpForFreescout\Tools\Conversations;

use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class GetConversationTool extends Tool
{
    public function name(): string
    {
        return 'get_conversation';
    }

    public function description(): string
    {
        return 'Get one conversation with its messages and notes, oldest first, including attachment names and sizes. '
            .'Your own unsent drafts are included unless include_drafts is false; other users\' drafts are never shown.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Conversation id, from list_conversations or search_conversations.'],
                'include_drafts'  => ['type' => 'boolean', 'description' => 'Include your own drafts (default true).'],
            ],
            'required'             => ['conversation_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversation = Lookup::conversation($this->requireInt($arguments, 'conversation_id'), $user);
        $includeDrafts = $this->optionalBool($arguments, 'include_drafts', true);

        $threads = [];
        $records = $conversation->threads()
            ->with('attachments')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        foreach ($records as $thread) {
            if ($this->isVisible($thread, $user, $includeDrafts)) {
                $threads[] = Presenter::thread($thread);
            }
        }

        return array_merge(Presenter::conversationSummary($conversation), ['threads' => $threads]);
    }

    /**
     * Published threads are visible to everyone with access to the conversation;
     * a draft only to its author. Hidden threads and threads held for review are not shown.
     */
    private function isVisible(Thread $thread, User $user, bool $includeDrafts): bool
    {
        if ($thread->state == Thread::STATE_PUBLISHED) {
            return true;
        }

        return $includeDrafts
            && $thread->state == Thread::STATE_DRAFT
            && (int) $thread->created_by_user_id === (int) $user->id;
    }
}
