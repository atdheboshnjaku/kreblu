<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Hooks;

use Kreblu\Core\Hooks\HookManager;
use Kreblu\Tests\TestCase;

/**
 * Tests for the Hook system (actions and filters).
 */
final class HookManagerTest extends TestCase
{
	private HookManager $hooks;

	protected function setUp(): void
	{
		parent::setUp();
		$this->hooks = new HookManager();
	}

	// ---------------------------------------------------------------
	// Actions
	// ---------------------------------------------------------------

	public function test_action_fires_callback(): void
	{
		$called = false;

		$this->hooks->addAction('test_hook', function () use (&$called) {
			$called = true;
		});

		$this->hooks->doAction('test_hook');
		$this->assertTrue($called);
	}

	public function test_action_receives_arguments(): void
	{
		$received = [];

		$this->hooks->addAction('test_hook', function (string $a, int $b) use (&$received) {
			$received = [$a, $b];
		});

		$this->hooks->doAction('test_hook', 'hello', 42);
		$this->assertEquals(['hello', 42], $received);
	}

	public function test_action_fires_multiple_callbacks(): void
	{
		$order = [];

		$this->hooks->addAction('test_hook', function () use (&$order) {
			$order[] = 'first';
		});

		$this->hooks->addAction('test_hook', function () use (&$order) {
			$order[] = 'second';
		});

		$this->hooks->doAction('test_hook');
		$this->assertEquals(['first', 'second'], $order);
	}

	public function test_action_respects_priority(): void
	{
		$order = [];

		$this->hooks->addAction('test_hook', function () use (&$order) {
			$order[] = 'low_priority';
		}, 20);

		$this->hooks->addAction('test_hook', function () use (&$order) {
			$order[] = 'high_priority';
		}, 5);

		$this->hooks->addAction('test_hook', function () use (&$order) {
			$order[] = 'default_priority';
		}, 10);

		$this->hooks->doAction('test_hook');
		$this->assertEquals(['high_priority', 'default_priority', 'low_priority'], $order);
	}

	public function test_action_same_priority_preserves_registration_order(): void
	{
		$order = [];

		$this->hooks->addAction('test_hook', function () use (&$order) {
			$order[] = 'first';
		}, 10);

		$this->hooks->addAction('test_hook', function () use (&$order) {
			$order[] = 'second';
		}, 10);

		$this->hooks->addAction('test_hook', function () use (&$order) {
			$order[] = 'third';
		}, 10);

		$this->hooks->doAction('test_hook');
		$this->assertEquals(['first', 'second', 'third'], $order);
	}

	public function test_action_nonexistent_hook_does_not_error(): void
	{
		// Should not throw
		$this->hooks->doAction('nonexistent_hook');
		$this->assertTrue(true);
	}

	public function test_action_count_tracked(): void
	{
		$this->assertEquals(0, $this->hooks->didAction('test_hook'));

		$this->hooks->doAction('test_hook');
		$this->assertEquals(1, $this->hooks->didAction('test_hook'));

		$this->hooks->doAction('test_hook');
		$this->assertEquals(2, $this->hooks->didAction('test_hook'));
	}

	public function test_action_count_tracks_even_without_callbacks(): void
	{
		$this->hooks->doAction('empty_hook');
		$this->assertEquals(1, $this->hooks->didAction('empty_hook'));
	}

	// ---------------------------------------------------------------
	// Filters
	// ---------------------------------------------------------------

	public function test_filter_modifies_value(): void
	{
		$this->hooks->addFilter('test_filter', function (string $value): string {
			return $value . ' world';
		});

		$result = $this->hooks->applyFilters('test_filter', 'hello');
		$this->assertEquals('hello world', $result);
	}

	public function test_filter_chains_multiple_callbacks(): void
	{
		$this->hooks->addFilter('test_filter', function (int $value): int {
			return $value + 10;
		});

		$this->hooks->addFilter('test_filter', function (int $value): int {
			return $value * 2;
		});

		$result = $this->hooks->applyFilters('test_filter', 5);
		$this->assertEquals(30, $result); // (5 + 10) * 2
	}

	public function test_filter_respects_priority(): void
	{
		// Multiply first (priority 5), then add (priority 10)
		$this->hooks->addFilter('test_filter', function (int $value): int {
			return $value + 1;
		}, 10);

		$this->hooks->addFilter('test_filter', function (int $value): int {
			return $value * 3;
		}, 5);

		$result = $this->hooks->applyFilters('test_filter', 2);
		$this->assertEquals(7, $result); // (2 * 3) + 1
	}

	public function test_filter_receives_extra_arguments(): void
	{
		$this->hooks->addFilter('test_filter', function (string $value, string $extra): string {
			return $value . ' ' . $extra;
		});

		$result = $this->hooks->applyFilters('test_filter', 'hello', 'world');
		$this->assertEquals('hello world', $result);
	}

	public function test_filter_returns_original_when_no_callbacks(): void
	{
		$result = $this->hooks->applyFilters('nonexistent_filter', 'original');
		$this->assertEquals('original', $result);
	}

	public function test_filter_preserves_type(): void
	{
		$this->hooks->addFilter('typed_filter', function (array $items): array {
			$items[] = 'added';
			return $items;
		});

		$result = $this->hooks->applyFilters('typed_filter', ['initial']);
		$this->assertEquals(['initial', 'added'], $result);
	}

	public function test_filter_null_value(): void
	{
		$this->hooks->addFilter('null_filter', function (?string $value): string {
			return $value ?? 'default';
		});

		$result = $this->hooks->applyFilters('null_filter', null);
		$this->assertEquals('default', $result);
	}

	// ---------------------------------------------------------------
	// Removal
	// ---------------------------------------------------------------

	public function test_remove_action(): void
	{
		$called = false;

		$callback = function () use (&$called) {
			$called = true;
		};

		$this->hooks->addAction('test_hook', $callback);
		$removed = $this->hooks->removeAction('test_hook', $callback);

		$this->assertTrue($removed);

		$this->hooks->doAction('test_hook');
		$this->assertFalse($called);
	}

	public function test_remove_filter(): void
	{
		$callback = function (string $value): string {
			return $value . ' modified';
		};

		$this->hooks->addFilter('test_filter', $callback);
		$this->hooks->removeFilter('test_filter', $callback);

		$result = $this->hooks->applyFilters('test_filter', 'original');
		$this->assertEquals('original', $result);
	}

	public function test_remove_requires_matching_priority(): void
	{
		$callback = function () {};

		$this->hooks->addAction('test_hook', $callback, 20);

		// Try to remove with wrong priority
		$removed = $this->hooks->removeAction('test_hook', $callback, 10);
		$this->assertFalse($removed);

		// Remove with correct priority
		$removed = $this->hooks->removeAction('test_hook', $callback, 20);
		$this->assertTrue($removed);
	}

	public function test_remove_nonexistent_returns_false(): void
	{
		$removed = $this->hooks->removeAction('nonexistent', function () {});
		$this->assertFalse($removed);
	}

	public function test_remove_all_for_hook(): void
	{
		$this->hooks->addAction('test_hook', function () {});
		$this->hooks->addAction('test_hook', function () {});
		$this->hooks->addAction('other_hook', function () {});

		$this->hooks->removeAll('test_hook');

		$this->assertFalse($this->hooks->hasHook('test_hook'));
		$this->assertTrue($this->hooks->hasHook('other_hook'));
	}

	public function test_remove_all_everything(): void
	{
		$this->hooks->addAction('hook_a', function () {});
		$this->hooks->addAction('hook_b', function () {});

		$this->hooks->removeAll();

		$this->assertFalse($this->hooks->hasHook('hook_a'));
		$this->assertFalse($this->hooks->hasHook('hook_b'));
	}

	public function test_remove_all_specific_priority(): void
	{
		$called = [];

		$this->hooks->addAction('test_hook', function () use (&$called) {
			$called[] = 'priority_5';
		}, 5);

		$this->hooks->addAction('test_hook', function () use (&$called) {
			$called[] = 'priority_10';
		}, 10);

		$this->hooks->removeAll('test_hook', 5);

		$this->hooks->doAction('test_hook');
		$this->assertEquals(['priority_10'], $called);
	}

	// ---------------------------------------------------------------
	// Inspection
	// ---------------------------------------------------------------

	public function test_has_hook(): void
	{
		$this->assertFalse($this->hooks->hasHook('test_hook'));

		$this->hooks->addAction('test_hook', function () {});
		$this->assertTrue($this->hooks->hasHook('test_hook'));
	}

	public function test_hook_count(): void
	{
		$this->assertEquals(0, $this->hooks->hookCount('test_hook'));

		$this->hooks->addAction('test_hook', function () {});
		$this->assertEquals(1, $this->hooks->hookCount('test_hook'));

		$this->hooks->addAction('test_hook', function () {});
		$this->assertEquals(2, $this->hooks->hookCount('test_hook'));
	}

	public function test_hook_count_across_priorities(): void
	{
		$this->hooks->addAction('test_hook', function () {}, 5);
		$this->hooks->addAction('test_hook', function () {}, 10);
		$this->hooks->addAction('test_hook', function () {}, 15);

		$this->assertEquals(3, $this->hooks->hookCount('test_hook'));
	}

	public function test_current_hook_during_execution(): void
	{
		$capturedHook = null;

		$this->hooks->addAction('test_hook', function () use (&$capturedHook) {
			$capturedHook = $this->hooks->currentHook();
		});

		$this->hooks->doAction('test_hook');
		$this->assertEquals('test_hook', $capturedHook);
	}

	public function test_current_hook_null_outside_execution(): void
	{
		$this->assertNull($this->hooks->currentHook());
	}

	public function test_doing_hook(): void
	{
		$wasDoing = false;
		$wasDoingOther = false;
		$wasDoingAny = false;

		$this->hooks->addAction('test_hook', function () use (&$wasDoing, &$wasDoingOther, &$wasDoingAny) {
			$wasDoing = $this->hooks->doingHook('test_hook');
			$wasDoingOther = $this->hooks->doingHook('other_hook');
			$wasDoingAny = $this->hooks->doingHook();
		});

		$this->hooks->doAction('test_hook');

		$this->assertTrue($wasDoing);
		$this->assertFalse($wasDoingOther);
		$this->assertTrue($wasDoingAny);
	}

	public function test_not_doing_hook_outside_execution(): void
	{
		$this->assertFalse($this->hooks->doingHook());
		$this->assertFalse($this->hooks->doingHook('test_hook'));
	}

	// ---------------------------------------------------------------
	// Nested hooks
	// ---------------------------------------------------------------

	public function test_nested_actions(): void
	{
		$order = [];

		$this->hooks->addAction('outer', function () use (&$order) {
			$order[] = 'outer_start';
			$this->hooks->doAction('inner');
			$order[] = 'outer_end';
		});

		$this->hooks->addAction('inner', function () use (&$order) {
			$order[] = 'inner';
		});

		$this->hooks->doAction('outer');
		$this->assertEquals(['outer_start', 'inner', 'outer_end'], $order);
	}

	public function test_nested_hook_tracking(): void
	{
		$innerHook = null;
		$outerDuringInner = null;

		$this->hooks->addAction('outer', function () use (&$innerHook, &$outerDuringInner) {
			$this->hooks->addAction('inner', function () use (&$innerHook, &$outerDuringInner) {
				$innerHook = $this->hooks->currentHook();
				$outerDuringInner = $this->hooks->doingHook('outer');
			});

			$this->hooks->doAction('inner');
		});

		$this->hooks->doAction('outer');

		$this->assertEquals('inner', $innerHook);
		$this->assertTrue($outerDuringInner);
	}

	// ---------------------------------------------------------------
	// Edge cases
	// ---------------------------------------------------------------

	public function test_callback_added_during_execution_not_called_in_current_run(): void
	{
		$lateCalled = false;

		$this->hooks->addAction('test_hook', function () use (&$lateCalled) {
			// Add a new callback during execution
			$this->hooks->addAction('test_hook', function () use (&$lateCalled) {
				$lateCalled = true;
			});
		});

		$this->hooks->doAction('test_hook');

		// The late-added callback should NOT have been called in this run
		// because getSortedCallbacks() was already resolved
		$this->assertFalse($lateCalled);

		// But it should be called on the next run
		$this->hooks->doAction('test_hook');
		$this->assertTrue($lateCalled);
	}

	public function test_filter_with_object_value(): void
	{
		$this->hooks->addFilter('object_filter', function (object $obj): object {
			$obj->modified = true;
			return $obj;
		});

		$input = (object) ['name' => 'test', 'modified' => false];
		$result = $this->hooks->applyFilters('object_filter', $input);

		$this->assertTrue($result->modified);
		$this->assertEquals('test', $result->name);
	}
}
