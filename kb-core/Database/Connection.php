<?php declare(strict_types=1);

namespace Kreblu\Core\Database;

/**
 * Database Connection
 *
 * PDO wrapper providing lazy connection, automatic prepared statements,
 * and a query builder factory method.
 *
 * Connection is lazy: the PDO instance is only created when the first
 * query is executed, not when the Connection object is constructed.
 *
 * All queries use prepared statements with parameter binding.
 * There is no method that accepts unescaped user input in raw SQL
 * without explicit parameter placeholders.
 *
 * Compatible with MySQL 8.4+ (caching_sha2_password) and MariaDB 10.11+.
 */
final class Connection
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $name,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $prefix = 'kb_',
        private readonly string $charset = 'utf8mb4',
        private readonly string $collation = 'utf8mb4_unicode_ci',
    ) {}

    /**
     * Get the underlying PDO instance. Creates the connection on first call.
     */
    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    /**
     * Get the table prefix.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get a prefixed table name.
     *
     * Usage: $db->tableName('posts') returns 'kb_posts'
     */
    public function tableName(string $table): string
    {
        return $this->prefix . $table;
    }

    /**
     * Start a query builder for a table.
     *
     * Usage: $db->table('posts')->where('status', '=', 'published')->get()
     * The table name is auto-prefixed: 'posts' becomes 'kb_posts'.
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $this->tableName($table));
    }

    /**
     * Execute a raw SQL query with parameter binding.
     *
     * This is the escape hatch for complex queries that the QueryBuilder
     * cannot express. Parameters are ALWAYS bound — never concatenated.
     *
     * Usage:
     *   $db->raw('SELECT * FROM kb_posts WHERE status = ? AND type = ?', ['published', 'post'])
     *   $db->raw('SELECT * FROM kb_posts WHERE id = :id', ['id' => 42])
     *
     * @param string $sql SQL with ? or :named placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     * @return array<int, object> Array of result objects
     */
    public function raw(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * Execute a raw SQL statement that does not return rows (INSERT, UPDATE, DELETE, CREATE, etc.)
     *
     * @param string $sql SQL with ? or :named placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     * @return int Number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get the last inserted auto-increment ID.
     */
    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->pdo()->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): bool
    {
        return $this->pdo()->commit();
    }

    /**
     * Roll back the current transaction.
     */
    public function rollback(): bool
    {
        return $this->pdo()->rollBack();
    }

    /**
     * Execute a callback within a transaction.
     *
     * Automatically commits on success, rolls back on exception.
     *
     * Usage:
     *   $db->transaction(function (Connection $db) {
     *       $db->table('orders')->insert([...]);
     *       $db->table('products')->where('id', '=', 5)->update(['stock' => 99]);
     *   });
     *
     * @template T
     * @param callable(Connection): T $callback
     * @return T The return value of the callback
     * @throws \Throwable Re-throws any exception after rolling back
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Check if the connection is established.
     */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Close the connection.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Establish the PDO connection.
     *
     * @throws \PDOException If the connection fails
     */
    private function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset,
        );

        $this->pdo = new \PDO($dsn, $this->user, $this->pass, [
            // Throw exceptions on errors (not silent failures)
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,

            // Return results as objects by default
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,

            // Use real prepared statements, not emulated
            // This is critical for security and for MySQL 8.4 type handling
            \PDO::ATTR_EMULATE_PREPARES   => false,

            // Return integer and float columns as native PHP types, not strings
            \PDO::ATTR_STRINGIFY_FETCHES  => false,

            // Connection timeout in seconds
            \PDO::ATTR_TIMEOUT            => 5,
        ]);

        // Set the collation (charset is already set in DSN)
        $this->pdo->exec("SET NAMES '{$this->charset}' COLLATE '{$this->collation}'");

        // Set strict SQL mode (consistent with our docker-compose MySQL config)
        $this->pdo->exec(
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        );
    }
}