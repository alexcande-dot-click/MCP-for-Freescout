<?php

namespace Modules\McpForFreescout\Tests\Unit\Support;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;

/**
 * Test double: returns its arguments. Passing `fail` makes it throw a ToolError,
 * passing `crash` makes it throw an unexpected exception, passing `list` makes it
 * return a list-shaped array, passing `binary` makes it return text that is not valid UTF-8.
 */
class EchoTool extends Tool
{
    public function name(): string
    {
        return 'echo';
    }

    public function description(): string
    {
        return 'Returns its arguments.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]];
    }

    public function handle(array $arguments, User $user): array
    {
        if (isset($arguments['fail'])) {
            throw new ToolError((string) $arguments['fail']);
        }
        if (isset($arguments['crash'])) {
            throw new \RuntimeException('secret internal detail');
        }

        if (isset($arguments['binary'])) {
            return ['text' => "broken \xFF text"];
        }

        if (isset($arguments['list'])) {
            return ['first', 'second'];
        }

        return $arguments;
    }
}
