<?php

namespace Modules\McpForFreescout\Tests\Unit\Support;

/**
 * Test double: an available tool that sorts before EchoTool.
 */
class AlphaEchoTool extends EchoTool
{
    public function name(): string
    {
        return 'alpha';
    }
}
