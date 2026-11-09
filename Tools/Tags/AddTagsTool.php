<?php

namespace Modules\McpForFreescout\Tools\Tags;

use App\User;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Tags;
use Modules\Tags\Entities\Tag;

class AddTagsTool extends TagTool
{
    public function name(): string
    {
        return 'add_tags';
    }

    public function description(): string
    {
        return 'Add tags to a conversation. A name that matches no tag yet creates the tag, as the tag field in FreeScout does; '
            .'names are case-insensitive and stored in lower case. Tags the conversation has already are left as they are. '
            .'Returns the tags of the conversation after the change.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Conversation id, from list_conversations or search_conversations.'],
                'names'           => $this->namesSchema('Names of the tags to add. Existing tags are listed by list_tags.'),
            ],
            'required'             => ['conversation_id', 'names'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversationId = $this->requireInt($arguments, 'conversation_id');
        $names = $this->namesArgument($arguments);

        $conversation = Lookup::conversation($conversationId, $user);

        foreach ($names as $name) {
            $tag = Tags::findByName($name);
            // The module counts every attach, so a tag already on the conversation must not be attached again.
            if (!$tag || !Tags::isAttached($tag, $conversation)) {
                Tag::attachByName($name, $conversation->id);
            }
        }

        return ['conversation_id' => (int) $conversation->id, 'tags' => Tags::namesOf($conversation)];
    }
}
