<?php declare(strict_types=1);

namespace Kreblu\Core\Database;

/**
 * Schema and Migration Runner
 *
 * Manages database schema through versioned migration files.
 * Tracks which migrations have been applied in a kb_migrations table.
 * Runs pending migrations in filename order.
 *
 * Migration files are PHP files in kb-core/Database/migrations/ named
 * with a timestamp prefix: 0001_create_posts_table.php
 *
 * Each migration file returns an array with 'up' and 'down' keys,
 * each containing a callable that receives the Connection instance.
 */
final class Schema
{
	private const MIGRATIONS_TABLE = 'migrations';

	public function __construct(
		private readonly Connection $connection,
		private readonly string $migrationsPath,
	) {}

	/**
	 * Run all pending migrations.
	 *
	 * @return array<int, string> List of migration filenames that were executed
	 */
	public function runPending(): array
	{
		$this->ensureMigrationsTable();

		$applied = $this->getAppliedMigrations();
		$all = $this->getAvailableMigrations();
		$pending = array_diff($all, $applied);

		if (empty($pending)) {
			return [];
		}

		sort($pending);
		$executed = [];

		$batch = $this->getCurrentBatch() + 1;

		foreach ($pending as $migration) {
			$this->runMigration($migration, 'up');
			$this->recordMigrationWithBatch($migration, $batch);
			$executed[] = $migration;
		}

		return $executed;
	}

	/**
	 * Roll back the last batch of migrations.
	 *
	 * @param int $steps Number of migrations to roll back (0 = all)
	 * @return array<int, string> List of migration filenames that were rolled back
	 */
	public function rollback(int $steps = 1): array
	{
		$this->ensureMigrationsTable();

		$applied = $this->getAppliedMigrations();

		if (empty($applied)) {
			return [];
		}

		// Get the most recent N migrations
		$toRollback = $steps === 0
			? array_reverse($applied)
			: array_reverse(array_slice($applied, -$steps));

		$rolledBack = [];

		foreach ($toRollback as $migration) {
			$this->runMigration($migration, 'down');
			$this->removeMigrationRecord($migration);
			$rolledBack[] = $migration;
		}

		return $rolledBack;
	}

	/**
	 * Reset the database by rolling back all migrations.
	 *
	 * @return array<int, string> List of rolled back migrations
	 */
	public function reset(): array
	{
		return $this->rollback(0);
	}

	/**
	 * Reset and re-run all migrations.
	 *
	 * @return array<int, string> List of executed migrations
	 */
	public function refresh(): array
	{
		$this->reset();
		return $this->runPending();
	}

	/**
	 * Get the list of applied migration filenames.
	 *
	 * @return array<int, string>
	 */
	public function getAppliedMigrations(): array
	{
		$table = $this->connection->tableName(self::MIGRATIONS_TABLE);
		$results = $this->connection->raw(
			"SELECT migration FROM {$table} ORDER BY batch ASC, migration ASC"
		);

		return array_map(fn(object $row) => $row->migration, $results);
	}

	/**
	 * Get the list of available migration filenames from the migrations directory.
	 *
	 * @return array<int, string>
	 */
	public function getAvailableMigrations(): array
	{
		if (!is_dir($this->migrationsPath)) {
			return [];
		}

		$files = scandir($this->migrationsPath);
		if ($files === false) {
			return [];
		}

		$migrations = [];
		foreach ($files as $file) {
			if (str_ends_with($file, '.php') && $file !== '.' && $file !== '..') {
				$migrations[] = $file;
			}
		}

		sort($migrations);
		return $migrations;
	}

	/**
	 * Get pending migrations that have not been applied yet.
	 *
	 * @return array<int, string>
	 */
	public function getPendingMigrations(): array
	{
		$this->ensureMigrationsTable();
		$applied = $this->getAppliedMigrations();
		$all = $this->getAvailableMigrations();
		$pending = array_diff($all, $applied);
		sort($pending);
		return array_values($pending);
	}

	/**
	 * Get the current batch number.
	 */
	public function getCurrentBatch(): int
	{
		$table = $this->connection->tableName(self::MIGRATIONS_TABLE);
		$result = $this->connection->raw(
			"SELECT MAX(batch) as max_batch FROM {$table}"
		);

		return (int) ($result[0]->max_batch ?? 0);
	}

	/**
	 * Create the migrations tracking table if it doesn't exist.
	 */
	public function ensureMigrationsTable(): void
	{
		$table = $this->connection->tableName(self::MIGRATIONS_TABLE);

		$this->connection->execute("
			CREATE TABLE IF NOT EXISTS {$table} (
				id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				migration VARCHAR(255) NOT NULL,
				batch INT UNSIGNED NOT NULL,
				executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE KEY idx_migration (migration)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}

	/**
	 * Run a single migration file's up or down callable.
	 *
	 * @throws \RuntimeException If the migration file is invalid
	 */
	private function runMigration(string $filename, string $direction): void
	{
		$path = $this->migrationsPath . '/' . $filename;

		if (!file_exists($path)) {
			throw new \RuntimeException("Migration file not found: {$path}");
		}

		$migration = require $path;

		if (!is_array($migration) || !isset($migration[$direction]) || !is_callable($migration[$direction])) {
			throw new \RuntimeException(
				"Migration file {$filename} must return an array with callable 'up' and 'down' keys."
			);
		}

		$migration[$direction]($this->connection);
	}

	/**
	 * Record that a migration has been applied.
	 */
	private function recordMigrationWithBatch(string $filename, int $batch): void
	{
		$table = $this->connection->tableName(self::MIGRATIONS_TABLE);

		$this->connection->execute(
			"INSERT INTO {$table} (migration, batch) VALUES (?, ?)",
			[$filename, $batch]
		);
	}

	/**
	 * Remove a migration record (used during rollback).
	 */
	private function removeMigrationRecord(string $filename): void
	{
		$table = $this->connection->tableName(self::MIGRATIONS_TABLE);

		$this->connection->execute(
			"DELETE FROM {$table} WHERE migration = ?",
			[$filename]
		);
	}
}
