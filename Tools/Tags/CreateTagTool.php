<?php

namespace Modules\McpForFreescout\Tools\Tags;

use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Presenter;
use Modules\McpForFreescout\Support\Tags;
use Modules\Tags\Entities\Tag;

class CreateTagTool extends TagTool
{
    public function name(): string
    {
        return 'create_tag';
    }

    public function description(): string
    {
        return 'Create a tag that is on no conversation yet. The name is stored in lower case and must not exist already. '
            .'Needs the permission to manage tags. Returns the new tag.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'name'  => ['type' => 'string', 'maxLength' => Tags::MAX_NAME_LENGTH, 'description' => 'Tag name; unique ignoring case.'],
                'color' => $this->colorSchema(),
            ],
            'required'             => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function handle(array $arguments, User $user): array
    {
        $this->assertCanManageTags($user);

        $name = $this->validName($this->requireString($arguments, 'name'));
        $color = $this->colorArgument($arguments);

        $tag = Tag::getOrCreate(['name' => $name], ['color' => $color ?? Tag::COLOR_DEFAULT]);
        if (!$tag->wasRecentlyCreated) {
            throw new ToolError('A tag with this name already exists.');
        }

        return Presenter::tag($tag->fresh());
    }
}
