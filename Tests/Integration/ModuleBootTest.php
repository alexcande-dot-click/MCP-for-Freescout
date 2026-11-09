<?php

namespace Modules\McpForFreescout\Tests\Integration;

use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class ModuleBootTest extends TestCase
{
    public function test_module_is_active_and_provider_loaded()
    {
        $this->assertTrue(\Module::isActive('mcpforfreescout'));
        $this->assertTrue(defined('MCP_FOR_FREESCOUT_MODULE'));
        $this->assertTrue(config('mcpforfreescout.options.enabled.default') === true);
    }

    public function test_fixtures_create_accessible_mailbox()
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);

        $this->assertTrue($user->can('view', $mailbox));
    }
}
