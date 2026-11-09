<?php

namespace Modules\McpForFreescout\Tests\Unit;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use PHPUnit\Framework\TestCase;

class ToolArgumentsTest extends TestCase
{
    private $tool;

    protected function setUp(): void
    {
        $this->tool = new class() extends Tool {
            public function name(): string
            {
                return 'arguments_probe';
            }

            public function description(): string
            {
                return 'Exposes the argument helpers.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function handle(array $arguments, User $user): array
            {
                return [];
            }

            public function call(string $helper, array $arguments, ...$extra)
            {
                return $this->$helper($arguments, ...$extra);
            }
        };
    }

    public function test_require_int_accepts_integers_and_numeric_strings()
    {
        $this->assertSame(7, $this->tool->call('requireInt', ['id' => 7], 'id'));
        $this->assertSame(7, $this->tool->call('requireInt', ['id' => '7'], 'id'));
        $this->assertSame(7, $this->tool->call('requireInt', ['id' => 7.0], 'id'));
    }

    public function test_require_int_rejects_missing_and_malformed_values()
    {
        foreach ([[], ['id' => null], ['id' => 'abc'], ['id' => '1.5'], ['id' => 1.5], ['id' => true], ['id' => [1]], ['id' => '']] as $arguments) {
            try {
                $this->tool->call('requireInt', $arguments, 'id');
                $this->fail('A ToolError was expected for '.json_encode($arguments));
            } catch (ToolError $e) {
                $this->assertStringContainsString('"id"', $e->getMessage());
            }
        }
    }

    public function test_optional_int_returns_default_when_absent()
    {
        $this->assertNull($this->tool->call('optionalInt', [], 'limit'));
        $this->assertSame(20, $this->tool->call('optionalInt', ['limit' => null], 'limit', 20));
        $this->assertSame(5, $this->tool->call('optionalInt', ['limit' => '5'], 'limit', 20));
    }

    public function test_optional_int_rejects_malformed_values()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"limit"');

        $this->tool->call('optionalInt', ['limit' => 'many'], 'limit');
    }

    public function test_optional_int_in_range_clamps_and_defaults()
    {
        $this->assertSame(25, $this->tool->call('optionalIntInRange', [], 'per_page', 25, 1, 100));
        $this->assertSame(25, $this->tool->call('optionalIntInRange', ['per_page' => null], 'per_page', 25, 1, 100));
        $this->assertSame(40, $this->tool->call('optionalIntInRange', ['per_page' => '40'], 'per_page', 25, 1, 100));
        $this->assertSame(100, $this->tool->call('optionalIntInRange', ['per_page' => 5000], 'per_page', 25, 1, 100));
        $this->assertSame(1, $this->tool->call('optionalIntInRange', ['per_page' => -3], 'per_page', 25, 1, 100));
    }

    public function test_optional_int_in_range_rejects_non_integers()
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"per_page"');

        $this->tool->call('optionalIntInRange', ['per_page' => 'lots'], 'per_page', 25, 1, 100);
    }

    public function test_require_string()
    {
        $this->assertSame('hello', $this->tool->call('requireString', ['q' => 'hello'], 'q'));
        $this->assertSame('12', $this->tool->call('requireString', ['q' => 12], 'q'));

        foreach ([[], ['q' => null], ['q' => ''], ['q' => '   '], ['q' => ['a']], ['q' => true]] as $arguments) {
            try {
                $this->tool->call('requireString', $arguments, 'q');
                $this->fail('A ToolError was expected for '.json_encode($arguments));
            } catch (ToolError $e) {
                $this->assertStringContainsString('"q"', $e->getMessage());
            }
        }
    }

    public function test_optional_string()
    {
        $this->assertNull($this->tool->call('optionalString', [], 'note'));
        $this->assertSame('x', $this->tool->call('optionalString', [], 'note', 'x'));
        $this->assertSame('', $this->tool->call('optionalString', ['note' => ''], 'note'));
        $this->assertSame('text', $this->tool->call('optionalString', ['note' => 'text'], 'note'));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"note"');
        $this->tool->call('optionalString', ['note' => ['a']], 'note');
    }

    public function test_optional_bool()
    {
        $this->assertNull($this->tool->call('optionalBool', [], 'flag'));
        $this->assertTrue($this->tool->call('optionalBool', [], 'flag', true));
        $this->assertTrue($this->tool->call('optionalBool', ['flag' => true], 'flag'));
        $this->assertFalse($this->tool->call('optionalBool', ['flag' => 'false'], 'flag'));
        $this->assertTrue($this->tool->call('optionalBool', ['flag' => 'true'], 'flag'));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"flag"');
        $this->tool->call('optionalBool', ['flag' => 'maybe'], 'flag');
    }

    public function test_optional_int_list()
    {
        $this->assertNull($this->tool->call('optionalIntList', [], 'ids'));
        $this->assertSame([], $this->tool->call('optionalIntList', ['ids' => []], 'ids'));
        $this->assertSame([1, 2, 3], $this->tool->call('optionalIntList', ['ids' => [1, '2', 3.0]], 'ids'));

        foreach ([['ids' => 5], ['ids' => [1, 'x']], ['ids' => ['a' => 1]]] as $arguments) {
            try {
                $this->tool->call('optionalIntList', $arguments, 'ids');
                $this->fail('A ToolError was expected for '.json_encode($arguments));
            } catch (ToolError $e) {
                $this->assertStringContainsString('"ids"', $e->getMessage());
            }
        }
    }

    public function test_optional_string_list()
    {
        $this->assertNull($this->tool->call('optionalStringList', [], 'cc'));
        $this->assertSame(['a@example.test', 'b'], $this->tool->call('optionalStringList', ['cc' => ['a@example.test', 'b']], 'cc'));

        foreach ([['cc' => 'a@example.test'], ['cc' => [['nested']]], ['cc' => ['k' => 'v']]] as $arguments) {
            try {
                $this->tool->call('optionalStringList', $arguments, 'cc');
                $this->fail('A ToolError was expected for '.json_encode($arguments));
            } catch (ToolError $e) {
                $this->assertStringContainsString('"cc"', $e->getMessage());
            }
        }
    }
}
