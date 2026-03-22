<?php declare(strict_types=1);

namespace Kreblu\Core\Hooks;

/**
 * Hook Manager
 *
 * Provides the action and filter system that plugins and themes use
 * to extend Kreblu. Works identically to WordPress hooks conceptually:
 *
 * - Actions: run code at a specific point (fire and forget)
 * - Filters: modify a value as it passes through a chain of callbacks
 *
 * Both support priority ordering (lower number = runs first, default 10).
 *
 * WordPress developers: os_add_action/os_add_filter/os_do_action/os_apply_filters
 * in the global helpers call through to this class.
 */
final class HookManager
{
	/**
	 * Registered hooks.
	 * Structure: [hook_name => [priority => [callbacks]]]
	 *
	 * @var array<string, array<int, array<int, callable>>>
	 */
	private array $hooks = [];

	/**
	 * Tracks how many times each action has been fired.
	 *
	 * @var array<string, int>
	 */
	private array $actionCounts = [];

	/**
	 * Tracks which hook is currently being executed (for nested hook detection).
	 *
	 * @var array<int, string>
	 */
	private array $currentHooks = [];

	// ---------------------------------------------------------------
	// Registration
	// ---------------------------------------------------------------

	/**
	 * Register a callback for an action hook.
	 *
	 * Actions are "fire and forget" — the callback's return value is ignored.
	 *
	 * @param string $hook The hook name
	 * @param callable $callback The function to call
	 * @param int $priority Lower number = runs first. Default 10.
	 */
	public function addAction(string $hook, callable $callback, int $priority = 10): void
	{
		$this->hooks[$hook][$priority][] = $callback;
	}

	/**
	 * Register a callback for a filter hook.
	 *
	 * Filters receive a value, modify it, and return it.
	 * Internally stored the same way as actions — the difference
	 * is in how they're executed (doAction vs applyFilters).
	 *
	 * @param string $hook The hook name
	 * @param callable $callback The function to call. Must return the filtered value.
	 * @param int $priority Lower number = runs first. Default 10.
	 */
	public function addFilter(string $hook, callable $callback, int $priority = 10): void
	{
		$this->hooks[$hook][$priority][] = $callback;
	}

	/**
	 * Remove a previously registered action callback.
	 *
	 * The callback and priority must match exactly what was registered.
	 *
	 * @return bool True if the callback was found and removed
	 */
	public function removeAction(string $hook, callable $callback, int $priority = 10): bool
	{
		return $this->removeHook($hook, $callback, $priority);
	}

	/**
	 * Remove a previously registered filter callback.
	 *
	 * @return bool True if the callback was found and removed
	 */
	public function removeFilter(string $hook, callable $callback, int $priority = 10): bool
	{
		return $this->removeHook($hook, $callback, $priority);
	}

	/**
	 * Remove all callbacks for a specific hook, or all hooks entirely.
	 *
	 * @param string|null $hook Specific hook to clear, or null to clear everything
	 * @param int|null $priority Specific priority to clear, or null for all priorities
	 */
	public function removeAll(?string $hook = null, ?int $priority = null): void
	{
		if ($hook === null) {
			$this->hooks = [];
			return;
		}

		if ($priority === null) {
			unset($this->hooks[$hook]);
			return;
		}

		unset($this->hooks[$hook][$priority]);
	}

	// ---------------------------------------------------------------
	// Execution
	// ---------------------------------------------------------------

	/**
	 * Fire an action hook.
	 *
	 * Calls all registered callbacks for this hook in priority order.
	 * Return values from callbacks are ignored.
	 *
	 * @param string $hook The hook name
	 * @param mixed ...$args Arguments passed to each callback
	 */
	public function doAction(string $hook, mixed ...$args): void
	{
		$this->actionCounts[$hook] = ($this->actionCounts[$hook] ?? 0) + 1;

		if (!isset($this->hooks[$hook])) {
			return;
		}

		$this->currentHooks[] = $hook;

		$callbacks = $this->getSortedCallbacks($hook);

		foreach ($callbacks as $callback) {
			$callback(...$args);
		}

		array_pop($this->currentHooks);
	}

	/**
	 * Apply filters to a value.
	 *
	 * Passes the value through all registered filter callbacks in priority order.
	 * Each callback receives the value (possibly modified by previous callbacks)
	 * as its first argument, plus any additional args.
	 *
	 * @param string $hook The hook name
	 * @param mixed $value The value to filter
	 * @param mixed ...$args Additional arguments passed to each callback
	 * @return mixed The filtered value
	 */
	public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
	{
		if (!isset($this->hooks[$hook])) {
			return $value;
		}

		$this->currentHooks[] = $hook;

		$callbacks = $this->getSortedCallbacks($hook);

		foreach ($callbacks as $callback) {
			$value = $callback($value, ...$args);
		}

		array_pop($this->currentHooks);

		return $value;
	}

	// ---------------------------------------------------------------
	// Inspection
	// ---------------------------------------------------------------

	/**
	 * Check if any callbacks are registered for a hook.
	 */
	public function hasHook(string $hook): bool
	{
		return isset($this->hooks[$hook]) && !empty($this->hooks[$hook]);
	}

	/**
	 * Get the number of callbacks registered for a hook.
	 */
	public function hookCount(string $hook): int
	{
		if (!isset($this->hooks[$hook])) {
			return 0;
		}

		$count = 0;
		foreach ($this->hooks[$hook] as $callbacks) {
			$count += count($callbacks);
		}
		return $count;
	}

	/**
	 * Get how many times an action has been fired.
	 */
	public function didAction(string $hook): int
	{
		return $this->actionCounts[$hook] ?? 0;
	}

	/**
	 * Get the hook currently being executed, or null if none.
	 */
	public function currentHook(): ?string
	{
		$count = count($this->currentHooks);
		return $count > 0 ? $this->currentHooks[$count - 1] : null;
	}

	/**
	 * Check if a specific hook is currently being executed.
	 */
	public function doingHook(?string $hook = null): bool
	{
		if ($hook === null) {
			return !empty($this->currentHooks);
		}
		return in_array($hook, $this->currentHooks, true);
	}

	// ---------------------------------------------------------------
	// Internal
	// ---------------------------------------------------------------

	/**
	 * Get all callbacks for a hook sorted by priority (ascending).
	 *
	 * @return array<int, callable>
	 */
	private function getSortedCallbacks(string $hook): array
	{
		$priorities = $this->hooks[$hook];
		ksort($priorities, SORT_NUMERIC);

		$sorted = [];
		foreach ($priorities as $callbacks) {
			foreach ($callbacks as $callback) {
				$sorted[] = $callback;
			}
		}

		return $sorted;
	}

	/**
	 * Remove a specific callback from a hook at a given priority.
	 *
	 * @return bool True if found and removed
	 */
	private function removeHook(string $hook, callable $callback, int $priority): bool
	{
		if (!isset($this->hooks[$hook][$priority])) {
			return false;
		}

		foreach ($this->hooks[$hook][$priority] as $index => $registered) {
			if ($registered === $callback) {
				unset($this->hooks[$hook][$priority][$index]);

				// Re-index the array
				$this->hooks[$hook][$priority] = array_values($this->hooks[$hook][$priority]);

				// Clean up empty priority levels
				if (empty($this->hooks[$hook][$priority])) {
					unset($this->hooks[$hook][$priority]);
				}

				// Clean up empty hooks
				if (empty($this->hooks[$hook])) {
					unset($this->hooks[$hook]);
				}

				return true;
			}
		}

		return false;
	}
}
