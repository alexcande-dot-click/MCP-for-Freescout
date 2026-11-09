<?php

namespace Modules\McpForFreescout\Mcp;

use App\User;

/**
 * Base class of every MCP tool.
 *
 * A tool is instantiated through the service container, so its constructor may
 * type-hint dependencies.
 */
abstract class Tool
{
    /**
     * Unique tool name, e.g. "list_conversations".
     */
    abstract public function name(): string;

    /**
     * Description shown to the model.
     */
    abstract public function description(): string;

    /**
     * JSON Schema of the tool arguments.
     */
    abstract public function inputSchema(): array;

    /**
     * JSON Schema of the structured result, or null if the tool does not declare one.
     */
    public function outputSchema(): ?array
    {
        return null;
    }

    /**
     * MCP tool annotations (readOnlyHint, destructiveHint, idempotentHint).
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => true];
    }

    /**
     * Whether the tool is offered to, and callable by, this user.
     */
    public function isAvailable(User $user): bool
    {
        return true;
    }

    /**
     * Execute the tool.
     *
     * @param array $arguments Arguments as sent by the client.
     * @param User  $user      The user the token belongs to.
     *
     * @return array Structured result. It is sent as a JSON object, so it must be
     *               an associative array (or empty); a list-shaped array is
     *               treated as a programming error. Wrap lists in a key.
     *
     * @throws ToolError When the call fails for a reason the model should see.
     */
    abstract public function handle(array $arguments, User $user): array;

    /**
     * The tools/list entry describing this tool.
     */
    public function definition(): array
    {
        $definition = [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
            'annotations' => $this->annotations(),
        ];

        $outputSchema = $this->outputSchema();
        if ($outputSchema !== null) {
            $definition['outputSchema'] = $outputSchema;
        }

        return $definition;
    }

    /**
     * Read a required integer argument. Numeric strings are accepted.
     *
     * @throws ToolError When the argument is missing or not an integer.
     */
    protected function requireInt(array $arguments, string $key): int
    {
        if (!$this->hasArgument($arguments, $key)) {
            throw new ToolError('Argument "'.$key.'" is required.');
        }

        return $this->intArgument($arguments[$key], $key);
    }

    /**
     * Read an optional integer argument. Numeric strings are accepted.
     *
     * @throws ToolError When the argument is present but not an integer.
     */
    protected function optionalInt(array $arguments, string $key, ?int $default = null): ?int
    {
        if (!$this->hasArgument($arguments, $key)) {
            return $default;
        }

        return $this->intArgument($arguments[$key], $key);
    }

    /**
     * Read an optional integer argument and clamp it to a range.
     *
     * @throws ToolError When the argument is present but not an integer.
     */
    protected function optionalIntInRange(array $arguments, string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, $this->optionalInt($arguments, $key, $default)));
    }

    /**
     * Read a required, non-blank string argument.
     *
     * @throws ToolError When the argument is missing, blank or not a string.
     */
    protected function requireString(array $arguments, string $key): string
    {
        if (!$this->hasArgument($arguments, $key)) {
            throw new ToolError('Argument "'.$key.'" is required.');
        }

        $value = $this->stringArgument($arguments[$key], $key);
        if (trim($value) === '') {
            throw new ToolError('Argument "'.$key.'" must not be empty.');
        }

        return $value;
    }

    /**
     * Read an optional string argument. An empty string is a valid value.
     *
     * @throws ToolError When the argument is present but not a string.
     */
    protected function optionalString(array $arguments, string $key, ?string $default = null): ?string
    {
        if (!$this->hasArgument($arguments, $key)) {
            return $default;
        }

        return $this->stringArgument($arguments[$key], $key);
    }

    /**
     * Read an optional boolean argument. The strings "true" and "false" are accepted.
     *
     * @throws ToolError When the argument is present but not a boolean.
     */
    protected function optionalBool(array $arguments, string $key, ?bool $default = null): ?bool
    {
        if (!$this->hasArgument($arguments, $key)) {
            return $default;
        }

        $value = $arguments[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }

        throw new ToolError('Argument "'.$key.'" must be a boolean.');
    }

    /**
     * Read an optional list of integers, or null when the argument is absent.
     *
     * @return int[]|null
     *
     * @throws ToolError When the argument is not a list of integers.
     */
    protected function optionalIntList(array $arguments, string $key): ?array
    {
        if (!$this->hasArgument($arguments, $key)) {
            return null;
        }

        $list = [];
        foreach ($this->listArgument($arguments[$key], $key, 'integers') as $item) {
            $parsed = $this->parseInt($item);
            if ($parsed === null) {
                throw new ToolError('Argument "'.$key.'" must be a list of integers.');
            }
            $list[] = $parsed;
        }

        return $list;
    }

    /**
     * Read an optional list of strings, or null when the argument is absent.
     *
     * @return string[]|null
     *
     * @throws ToolError When the argument is not a list of strings.
     */
    protected function optionalStringList(array $arguments, string $key): ?array
    {
        if (!$this->hasArgument($arguments, $key)) {
            return null;
        }

        $list = [];
        foreach ($this->listArgument($arguments[$key], $key, 'strings') as $item) {
            $parsed = $this->parseString($item);
            if ($parsed === null) {
                throw new ToolError('Argument "'.$key.'" must be a list of strings.');
            }
            $list[] = $parsed;
        }

        return $list;
    }

    private function hasArgument(array $arguments, string $key): bool
    {
        return isset($arguments[$key]);
    }

    private function intArgument($value, string $key): int
    {
        $parsed = $this->parseInt($value);
        if ($parsed === null) {
            throw new ToolError('Argument "'.$key.'" must be an integer.');
        }

        return $parsed;
    }

    private function stringArgument($value, string $key): string
    {
        $parsed = $this->parseString($value);
        if ($parsed === null) {
            throw new ToolError('Argument "'.$key.'" must be a string.');
        }

        return $parsed;
    }

    private function listArgument($value, string $key, string $itemType): array
    {
        if (!is_array($value) || array_values($value) !== $value) {
            throw new ToolError('Argument "'.$key.'" must be a list of '.$itemType.'.');
        }

        return $value;
    }

    private function parseInt($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 9007199254740992) {
            return (int) $value;
        }
        if (is_string($value)) {
            $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);

            return $parsed === false ? null : $parsed;
        }

        return null;
    }

    private function parseString($value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }
}
