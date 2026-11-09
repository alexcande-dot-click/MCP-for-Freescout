<?php

namespace Modules\McpForFreescout\Tools\Tags;

use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Presenter;
use Modules\McpForFreescout\Support\Tags;

class UpdateTagTool extends TagTool
{
    public function name(): string
    {
        return 'update_tag';
    }

    public function description(): string
    {
        return 'Rename a tag and/or change its colour. Give at least one of name and color. The tag stays on its conversations. '
            .'Needs the permission to manage tags. Returns the updated tag.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'tag_id' => ['type' => 'integer', 'description' => 'Tag id, from list_tags.'],
                'name'   => ['type' => 'string', 'maxLength' => Tags::MAX_NAME_LENGTH, 'description' => 'New name; unique ignoring case.'],
                'color'  => $this->colorSchema(),
            ],
            'required'             => ['tag_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $this->assertCanManageTags($user);

        $tag = $this->findTag($this->requireInt($arguments, 'tag_id'));
        $name = $this->optionalString($arguments, 'name');
        $color = $this->colorArgument($arguments);
        if ($name === null && $color === null) {
            throw new ToolError('Give at least one of name or color.');
        }

        if ($name !== null) {
            $tag->name = $this->validName($name, $tag);
        }
        if ($color !== null) {
            $tag->setColor($color);
        }
        $tag->save();

        return Presenter::tag($tag);
    }
}
