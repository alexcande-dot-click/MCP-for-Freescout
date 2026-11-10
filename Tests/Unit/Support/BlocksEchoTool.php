<?php

namespace Modules\McpForFreescout\Tests\Unit\Support;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolResult;

/**
 * Test double: returns a ToolResult carrying an image block and a resource block
 * after the text block. Passing `plain` makes it return a plain array instead.
 */
class BlocksEchoTool extends Tool
{
    public function name(): string
    {
        return 'blocks_echo';
    }

    public function description(): string
    {
        return 'Returns a result with extra content blocks.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['plain' => ['type' => 'boolean']]];
    }

    public function handle(array $arguments, User $user)
    {
        if (!empty($arguments['plain'])) {
            return ['a' => 1];
        }

        return new ToolResult(['a' => 1], [
            ToolResult::image('AAAA', 'image/png'),
            ToolResult::resource('freescout://attachment/7', 'application/pdf', 'BBBB'),
        ]);
    }
}
