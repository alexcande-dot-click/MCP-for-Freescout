<?php

namespace Modules\McpForFreescout\Tests\Unit\Support;

/**
 * Test double: an echo tool that declares itself as changing data.
 */
class MutatingEchoTool extends EchoTool
{
    public function name(): string
    {
        return 'mutate';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false];
    }
}
