<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\Option;
use Illuminate\Http\Request;
use Modules\McpForFreescout\Tests\TestCase;

class SettingsTest extends TestCase
{
    const KEY = 'mcpforfreescout.max_attachment_size';

    protected function tearDown(): void
    {
        unset(Option::$cache[self::KEY]);

        parent::tearDown();
    }

    private function beforeSave(array $settings, string $section = MCP_FOR_FREESCOUT_MODULE): array
    {
        $request = Request::create('/settings/'.$section, 'POST', ['settings' => $settings]);

        return \Eventy::filter('settings.before_save', $request, $section, array_keys($settings))->input('settings');
    }

    public function test_section_settings_hold_the_limit_in_bytes()
    {
        Option::set(self::KEY, 5242880);
        unset(Option::$cache[self::KEY]);

        $settings = \Eventy::filter('settings.section_settings', [], MCP_FOR_FREESCOUT_MODULE);

        $this->assertSame(5242880, $settings[self::KEY]);
        $this->assertArrayHasKey('mcpforfreescout.enabled', $settings);
    }

    public function test_section_settings_fall_back_to_the_default_limit()
    {
        Option::remove(self::KEY);
        unset(Option::$cache[self::KEY]);

        $settings = \Eventy::filter('settings.section_settings', [], MCP_FOR_FREESCOUT_MODULE);

        $this->assertSame(10485760, $settings[self::KEY]);
    }

    public function test_saved_megabytes_are_stored_as_bytes()
    {
        $this->assertSame(5242880, $this->beforeSave([self::KEY => '5'])[self::KEY]);
        $this->assertSame(1572864, $this->beforeSave([self::KEY => '1.5'])[self::KEY]);
    }

    public function test_limit_has_a_minimum_of_one_megabyte()
    {
        $this->assertSame(1048576, $this->beforeSave([self::KEY => '0'])[self::KEY]);
        $this->assertSame(1048576, $this->beforeSave([self::KEY => '0.2'])[self::KEY]);
        $this->assertSame(1048576, $this->beforeSave([self::KEY => '-4'])[self::KEY]);
    }

    public function test_empty_or_invalid_limit_gives_the_default()
    {
        $this->assertSame(10485760, $this->beforeSave([self::KEY => ''])[self::KEY]);
        $this->assertSame(10485760, $this->beforeSave([self::KEY => 'abc'])[self::KEY]);
    }

    public function test_other_sections_and_fields_are_left_alone()
    {
        $this->assertSame('7', $this->beforeSave([self::KEY => '7'], 'general')[self::KEY]);

        $settings = $this->beforeSave(['mcpforfreescout.enabled' => '1', self::KEY => '2']);
        $this->assertSame('1', $settings['mcpforfreescout.enabled']);
        $this->assertSame(2097152, $settings[self::KEY]);

        $this->assertSame(['mcpforfreescout.enabled' => '1'], $this->beforeSave(['mcpforfreescout.enabled' => '1']));
    }

    public function test_settings_view_shows_the_limit_in_megabytes()
    {
        $html = view('mcpforfreescout::settings', ['settings' => [
            'mcpforfreescout.enabled' => true,
            self::KEY                 => 5242880,
        ]])->render();

        $this->assertStringContainsString('name="settings[mcpforfreescout.max_attachment_size]"', $html);
        $this->assertStringContainsString('value="5"', $html);
        $this->assertStringContainsString('min="1"', $html);
    }
}
