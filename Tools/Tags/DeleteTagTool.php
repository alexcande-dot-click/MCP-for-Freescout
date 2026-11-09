<?php

namespace Modules\McpForFreescout\Tools\Tags;

use App\User;
use Modules\Tags\Entities\ConversationTag;

class DeleteTagTool extends TagTool
{
    public function name(): string
    {
        return 'delete_tag';
    }

    public function description(): string
    {
        return 'Permanently delete a tag and remove it from all conversations that carry it. '
            .'Needs the permission to manage tags.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'tag_id' => ['type' => 'integer', 'description' => 'Tag id, from list_tags.'],
            ],
            'required'             => ['tag_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $this->assertCanManageTags($user);

        $tag = $this->findTag($this->requireInt($arguments, 'tag_id'));

        \DB::transaction(function () use ($tag) {
            ConversationTag::where('tag_id', $tag->id)->delete();
            $tag->delete();
        });

        return ['deleted' => true, 'tag_id' => (int) $tag->id];
    }
}
