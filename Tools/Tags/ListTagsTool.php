<?php

namespace Modules\McpForFreescout\Tools\Tags;

use App\User;
use Modules\McpForFreescout\Support\Presenter;
use Modules\Tags\Entities\Tag;

class ListTagsTool extends TagTool
{
    /**
     * Most tags one call returns.
     */
    const LIMIT = 200;

    public function name(): string
    {
        return 'list_tags';
    }

    public function description(): string
    {
        return 'List the tags that exist, ordered by name, with their colour and the number of conversations they are on. '
            .'The optional query keeps the tags whose name contains it, ignoring case. At most '.self::LIMIT.' tags are returned. '
            .'Pass a tag name as the tag argument of list_conversations to find its conversations.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'query' => ['type' => 'string', 'description' => 'Only tags whose name contains this text, ignoring case.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $query = Tag::orderBy('name')->orderBy('id')->limit(self::LIMIT);

        $text = Tag::normalizeName((string) $this->optionalString($arguments, 'query', ''));
        if ($text !== '') {
            // Tag names are stored in lower case, like the module's own tag search expects.
            $query->where('name', 'like', '%'.addcslashes($text, '\\%_').'%');
        }

        return ['tags' => $query->get()->map(function (Tag $tag) {
            return Presenter::tag($tag);
        })->all()];
    }
}
