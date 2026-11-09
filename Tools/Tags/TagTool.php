<?php

namespace Modules\McpForFreescout\Tools\Tags;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\Tags;
use Modules\Tags\Entities\Tag;

/**
 * Base class of the tools that work with the tags of the Tags module. They are
 * offered only while that module is active.
 */
abstract class TagTool extends Tool
{
    /**
     * Most tag names one add_tags or remove_tags call may carry.
     */
    const MAX_NAMES = 20;

    public function isAvailable(User $user): bool
    {
        return CompanionModules::isActive(CompanionModules::TAGS);
    }

    /**
     * @throws ToolError When the user may not manage tags.
     */
    protected function assertCanManageTags(User $user): void
    {
        if (!Tag::canUserEditTags($user)) {
            throw new ToolError('You are not allowed to manage tags.');
        }
    }

    /**
     * @throws ToolError When the tag does not exist.
     */
    protected function findTag(int $tagId): Tag
    {
        $tag = Tags::find($tagId);
        if (!$tag) {
            throw new ToolError('Tag not found.');
        }

        return $tag;
    }

    /**
     * Check a tag name that is going to be stored: required, short enough and unused.
     *
     * @param Tag|null $current The tag being renamed, which may keep its name.
     *
     * @return string The normalised name.
     *
     * @throws ToolError
     */
    protected function validName(string $name, Tag $current = null): string
    {
        $name = Tags::normalizedName($name);

        $taken = Tag::where('name', $name);
        if ($current) {
            $taken->where('id', '!=', $current->id);
        }
        if ($taken->exists()) {
            throw new ToolError('A tag with this name already exists.');
        }

        return $name;
    }

    /**
     * Read the colour argument as a colour value of the module.
     *
     * @return int|null The colour value, or null when the argument is absent.
     *
     * @throws ToolError When the colour is not a known colour name.
     */
    protected function colorArgument(array $arguments): ?int
    {
        $color = $this->optionalString($arguments, 'color');

        return $color === null ? null : Tags::colorValue($color);
    }

    /**
     * Read the required list of tag names of add_tags and remove_tags.
     *
     * @return string[] The normalised names without duplicates, in the order given.
     *
     * @throws ToolError When the list is missing, empty, too long or holds a blank or too long name.
     */
    protected function namesArgument(array $arguments): array
    {
        $names = $this->optionalStringList($arguments, 'names');
        if ($names === null) {
            throw new ToolError('Argument "names" is required.');
        }
        if (!$names) {
            throw new ToolError('Give at least one tag name.');
        }
        if (count($names) > self::MAX_NAMES) {
            throw new ToolError('Argument "names" must hold at most '.self::MAX_NAMES.' tag names.');
        }

        return array_values(array_unique(array_map([Tags::class, 'normalizedName'], $names)));
    }

    /**
     * The schema of the "names" argument of add_tags and remove_tags.
     */
    protected function namesSchema(string $description): array
    {
        return [
            'type'        => 'array',
            'items'       => ['type' => 'string', 'maxLength' => Tags::MAX_NAME_LENGTH],
            'minItems'    => 1,
            'maxItems'    => self::MAX_NAMES,
            'description' => $description,
        ];
    }

    /**
     * The schema of the "color" argument.
     */
    protected function colorSchema(): array
    {
        return ['type' => 'string', 'enum' => Tags::COLOR_NAMES, 'description' => 'Tag colour.'];
    }
}
