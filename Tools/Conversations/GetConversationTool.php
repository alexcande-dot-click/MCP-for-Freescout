<?php

namespace Modules\McpForFreescout\Tools\Conversations;

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
        return 'Get one conversation with its messages and notes, oldest first, including files (attachments and embedded images) with download links, '
            .'and for sent replies the delivery status and the first open (`opened_at` comes from a tracking image, so it is not proof of reading). '
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
            ->with(['attachments', 'embeds'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        foreach ($records as $thread) {
            if (Lookup::threadVisible($thread, $user, $includeDrafts)) {
                $threads[] = Presenter::thread($thread);
            }
        }

        return array_merge(Presenter::conversationSummary($conversation, $user), ['threads' => $threads]);
    }
}
