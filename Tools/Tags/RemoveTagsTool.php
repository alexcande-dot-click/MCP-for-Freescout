<?php

namespace Modules\McpForFreescout\Tools\Tags;

use App\User;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Tags;
use Modules\Tags\Entities\Tag;

class RemoveTagsTool extends TagTool
{
    public function name(): string
    {
        return 'remove_tags';
    }

    public function description(): string
    {
        return 'Remove tags from a conversation. Names are case-insensitive; tags the conversation does not have are ignored. '
            .'A tag that no conversation carries any more may be deleted by the Tags module. '
            .'Returns the tags of the conversation after the change.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Conversation id, from list_conversations or search_conversations.'],
                'names'           => $this->namesSchema('Names of the tags to remove.'),
            ],
            'required'             => ['conversation_id', 'names'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversationId = $this->requireInt($arguments, 'conversation_id');
        $names = $this->namesArgument($arguments);

        $conversation = Lookup::conversation($conversationId, $user);

        foreach ($names as $name) {
            $tag = Tags::findByName($name);
            // The module lowers the counter on every detach, so only tags the conversation has are detached.
            if ($tag && Tags::isAttached($tag, $conversation)) {
                Tag::detachByName($name, $conversation->id);
            }
        }

        return ['conversation_id' => (int) $conversation->id, 'tags' => Tags::namesOf($conversation)];
    }
}
