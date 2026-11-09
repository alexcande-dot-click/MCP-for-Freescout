<?php

namespace Modules\McpForFreescout\Tests;

use Illuminate\Contracts\Console\Kernel;
use Modules\McpForFreescout\Support\CompanionModules;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base class for tests that run against a booted FreeScout application.
 *
 * Every test runs inside a database transaction that is rolled back afterwards,
 * so tests leave the development database untouched.
 */
abstract class TestCase extends BaseTestCase
{
    private static $freescout;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootFreescout();
        \DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        // Logging out without a user makes FreeScout's logout listener fail.
        if (\Auth::check()) {
            \Auth::logout();
        }
        \DB::rollBack();

        parent::tearDown();
    }

    /**
     * Skip the test unless a companion module is really installed and active.
     *
     * The activation filter is not consulted: the test needs the module's code and tables.
     */
    protected function requireModule(string $alias): void
    {
        self::bootFreescout();

        if (!\Module::isActive($alias)) {
            $this->markTestSkipped('The '.$alias.' module is not active.');
        }
    }

    /**
     * Run a callback with companion modules forced on or off through the
     * `mcpforfreescout.companion_active` filter.
     *
     * @param bool          $active   The state to force.
     * @param callable      $callback The code to run.
     * @param string[]|null $aliases  The modules to force; all companion modules by default.
     */
    protected function withCompanions(bool $active, callable $callback, array $aliases = null): void
    {
        $aliases = $aliases ?: [
            CompanionModules::CUSTOM_SIGNATURES,
            CompanionModules::SAVED_REPLIES,
            CompanionModules::TAGS,
            CompanionModules::SEND_LATER,
        ];

        $filter = function ($current, $alias) use ($active, $aliases) {
            return in_array($alias, $aliases, true) ? $active : $current;
        };
        \Eventy::addFilter('mcpforfreescout.companion_active', $filter, 20, 2);
        try {
            $callback();
        } finally {
            \Eventy::removeFilter('mcpforfreescout.companion_active', $filter, 20);
        }
    }

    /**
     * Boot FreeScout once per process using the console kernel.
     */
    private static function bootFreescout()
    {
        if (self::$freescout !== null) {
            return;
        }

        $app = require getenv('FREESCOUT_PATH').'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        self::$freescout = $app;
    }
}
