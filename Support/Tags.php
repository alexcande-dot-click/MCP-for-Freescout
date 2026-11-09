<?php

namespace Modules\McpForFreescout\Support;

use App\Conversation;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\Tags\Entities\Tag;

/**
 * Access to the tags of the Tags module, following the rules of the module's
 * own controller: names are normalised and unique, colours are numbered.
 *
 * Every method needs the module to be active; callers check
 * CompanionModules::isActive(CompanionModules::TAGS) first.
 */
final class Tags
{
    /**
     * Colour names as offered to the model, in the order of the module's colour constants.
     */
    const COLOR_NAMES = ['default', 'green', 'blue', 'orange', 'violet', 'red', 'brown', 'yellow', 'pink', 'lime', 'turquoise', 'lavender'];

    /**
     * Longest tag name the module's table can store.
     */
    const MAX_NAME_LENGTH = 191;

    /**
     * Find a tag by id.
     */
    public static function find(int $id): ?Tag
    {
        return Tag::find($id);
    }

    /**
     * Find a tag by its name, which is normalised first.
     */
    public static function findByName(string $name): ?Tag
    {
        return Tag::where('name', Tag::normalizeName($name))->first();
    }

    /**
     * The module's colour value of a colour name.
     *
     * @throws ToolError When the name is not one of COLOR_NAMES.
     */
    public static function colorValue(string $name): int
    {
        $values = [
            'default'   => Tag::COLOR_DEFAULT,
            'green'     => Tag::COLOR_GREEN,
            'blue'      => Tag::COLOR_BLUE,
            'orange'    => Tag::COLOR_ORANGE,
            'violet'    => Tag::COLOR_VIOLET,
            'red'       => Tag::COLOR_RED,
            'brown'     => Tag::COLOR_BROWN,
            'yellow'    => Tag::COLOR_YELLOW,
            'pink'      => Tag::COLOR_PINK,
            'lime'      => Tag::COLOR_LIME,
            'turquoise' => Tag::COLOR_TURQUOISE,
            'lavender'  => Tag::COLOR_LAVENDER,
        ];
        if (!isset($values[$name])) {
            throw new ToolError('Argument "color" must be one of: '.implode(', ', self::COLOR_NAMES).'.');
        }

        return $values[$name];
    }

    /**
     * The name of the colour of a tag.
     */
    public static function colorName(Tag $tag): string
    {
        foreach (self::COLOR_NAMES as $name) {
            if (self::colorValue($name) === $tag->getColor()) {
                return $name;
            }
        }

        return 'default';
    }

    /**
     * Normalise a tag name as the module stores it.
     *
     * @throws ToolError When nothing is left of the name or it is too long for the table.
     */
    public static function normalizedName(string $name): string
    {
        $name = Tag::normalizeName($name);
        if ($name === '') {
            throw new ToolError('Tag names must not be empty.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ToolError('Tag names must be at most '.self::MAX_NAME_LENGTH.' characters.');
        }

        return $name;
    }

    /**
     * The names of the tags of a conversation, in alphabetical order.
     *
     * @return string[]
     */
    public static function namesOf(Conversation $conversation): array
    {
        return array_map('strval', \DB::table('conversation_tag')
            ->join('tags', 'tags.id', '=', 'conversation_tag.tag_id')
            ->where('conversation_tag.conversation_id', $conversation->id)
            ->orderBy('tags.name')
            ->pluck('tags.name')
            ->all());
    }

    /**
     * Whether a conversation carries a tag.
     */
    public static function isAttached(Tag $tag, Conversation $conversation): bool
    {
        return \DB::table('conversation_tag')
            ->where('tag_id', $tag->id)
            ->where('conversation_id', $conversation->id)
            ->exists();
    }
}
