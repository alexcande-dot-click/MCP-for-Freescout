<?php

namespace Modules\McpForFreescout\Tests\Unit\Support;

use App\User;

/**
 * Test double: a tool that is never available.
 */
class UnavailableEchoTool extends EchoTool
{
    public function name(): string
    {
        return 'unavailable';
    }

    public function isAvailable(User $user): bool
    {
        return false;
    }
}
