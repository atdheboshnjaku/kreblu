<?php declare(strict_types=1);

namespace Kreblu\Core\Database;

/**
 * Query Builder
 *
 * Fluent interface for constructing SQL queries with automatic
 * parameter binding. Every value passes through prepared statements.
 *
 * Usage:
 *   $db->table('posts')
 *      ->select('id', 'title', 'status')
 *      ->where('status', '=', 'published')
 *      ->where('type', '=', 'post')
 *      ->orderBy('published_at', 'DESC')
 *      ->limit(10)
 *      ->offset(20)
 *      ->get();
 */
final class QueryBuilder
{
    /** @var string[] Columns to select */
    private array $selects = [];

    /** @var array<int, array{clause: string, params: array<int, mixed>}> WHERE conditions */
    private array $wheres = [];

    /** @var array<int, array{column: string, direction: string}> ORDER BY clauses */
    private array $orders = [];

    /** @var array<int, array{table: string, first: string, operator: string, second: string, type: string}> */
    private array $joins = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    /** @var string[] GROUP BY columns */
    private array $groups = [];

    /** @var array<int, array{clause: string, params: array<int, mixed>}> HAVING conditions */
    private array $havings = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table,
    ) {}

    // ---------------------------------------------------------------
    // SELECT
    // ---------------------------------------------------------------

    /**
     * Specify columns to select.
     *
     * Usage: ->select('id', 'title', 'status')
     * Default (no call): SELECT *
     */
    public function select(string ...$columns): self
    {
        $this->selects = $columns;
        return $this;
    }

    /**
     * Execute the SELECT query and return all results.
     *
     * @return array<int, object>
     */
    public function get(): array
    {
        [$sql, $params] = $this->buildSelect();
        return $this->connection->raw($sql, $params);
    }

    /**
     * Execute the SELECT query and return the first result, or null.
     */
    public function first(): ?object
    {
        $this->limitValue = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * Execute a COUNT query and return the count.
     */
    public function count(string $column = '*'): int
    {
        $savedSelects = $this->selects;
        $this->selects = ["COUNT({$column}) as aggregate"];

        $result = $this->first();

        $this->selects = $savedSelects;

        return (int) ($result->aggregate ?? 0);
    }

    /**
     * Check if any rows match the current conditions.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // ---------------------------------------------------------------
    // WHERE
    // ---------------------------------------------------------------

    /**
     * Add a WHERE condition.
     *
     * Usage:
     *   ->where('status', '=', 'published')
     *   ->where('views', '>', 100)
     *   ->where('author_id', '=', 5)
     *
     * Operator must be one of: =, !=, <>, <, >, <=, >=, LIKE, NOT LIKE, IN, NOT IN, IS, IS NOT
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $operator = strtoupper(trim($operator));
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];

        if (!in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid SQL operator "%s". Allowed: %s', $operator, implode(', ', $allowed))
            );
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException("Value for {$operator} must be an array.");
            }
            $placeholders = implode(', ', array_fill(0, count($value), '?'));
            $this->wheres[] = [
                'clause' => "{$column} {$operator} ({$placeholders})",
                'params' => array_values($value),
            ];
        } elseif ($value === null && ($operator === 'IS' || $operator === 'IS NOT')) {
            $this->wheres[] = [
                'clause' => "{$column} {$operator} NULL",
                'params' => [],
            ];
        } else {
            $this->wheres[] = [
                'clause' => "{$column} {$operator} ?",
                'params' => [$value],
            ];
        }

        return $this;
    }

    /**
     * Add a WHERE column IS NULL condition.
     */
    public function whereNull(string $column): self
    {
        return $this->where($column, 'IS', null);
    }

    /**
     * Add a WHERE column IS NOT NULL condition.
     */
    public function whereNotNull(string $column): self
    {
        return $this->where($column, 'IS NOT', null);
    }

    /**
     * Add a WHERE column IN (...) condition.
     *
     * @param array<int, mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->where($column, 'IN', $values);
    }

    /**
     * Add a JSON contains condition (MySQL 8.4+ JSON_CONTAINS).
     *
     * Usage: ->whereJsonContains('meta->tags', 'featured')
     */
    public function whereJsonContains(string $path, mixed $value): self
    {
        // Convert meta->tags to JSON_EXTRACT syntax: JSON_EXTRACT(meta, '$.tags')
        $parts = explode('->', $path, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException(
                "Invalid JSON path \"{$path}\". Expected format: column->key or column->key.subkey"
            );
        }

        $column = $parts[0];
        $jsonPath = '$.' . str_replace('->', '.', $parts[1]);

        $this->wheres[] = [
            'clause' => "JSON_CONTAINS({$column}, ?, '{$jsonPath}')",
            'params' => [json_encode($value)],
        ];

        return $this;
    }

    // ---------------------------------------------------------------
    // JOIN
    // ---------------------------------------------------------------

    /**
     * Add an INNER JOIN.
     *
     * Usage: ->join('kb_users', 'kb_posts.author_id', '=', 'kb_users.id')
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'table'    => $table,
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
            'type'     => 'INNER',
        ];
        return $this;
    }

    /**
     * Add a LEFT JOIN.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'table'    => $table,
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
            'type'     => 'LEFT',
        ];
        return $this;
    }

    // ---------------------------------------------------------------
    // ORDER, LIMIT, GROUP
    // ---------------------------------------------------------------

    /**
     * Add an ORDER BY clause.
     *
     * @param string $direction 'ASC' or 'DESC'
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper(trim($direction));
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException('Order direction must be ASC or DESC.');
        }
        $this->orders[] = ['column' => $column, 'direction' => $direction];
        return $this;
    }

    /**
     * Set the LIMIT.
     */
    public function limit(int $limit): self
    {
        $this->limitValue = $limit;
        return $this;
    }

    /**
     * Set the OFFSET.
     */
    public function offset(int $offset): self
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Add a GROUP BY clause.
     */
    public function groupBy(string ...$columns): self
    {
        $this->groups = $columns;
        return $this;
    }

    /**
     * Add a HAVING condition (used with GROUP BY).
     */
    public function having(string $column, string $operator, mixed $value): self
    {
        $operator = strtoupper(trim($operator));
        $this->havings[] = [
            'clause' => "{$column} {$operator} ?",
            'params' => [$value],
        ];
        return $this;
    }

    // ---------------------------------------------------------------
    // INSERT
    // ---------------------------------------------------------------

    /**
     * Insert a row and return the auto-increment ID.
     *
     * @param array<string, mixed> $data Column => value pairs
     * @return int The inserted row's ID
     */
    public function insert(array $data): int
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Insert data cannot be empty.');
        }

        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList = implode(', ', $columns);

        $sql = "INSERT INTO {$this->table} ({$columnList}) VALUES ({$placeholders})";

        $this->connection->execute($sql, array_values($data));

        return $this->connection->lastInsertId();
    }

    /**
     * Insert multiple rows at once.
     *
     * @param array<int, array<string, mixed>> $rows Array of column => value pairs
     * @return int Number of inserted rows
     */
    public function insertMany(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $columnList = implode(', ', $columns);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        $sql = "INSERT INTO {$this->table} ({$columnList}) VALUES {$allPlaceholders}";

        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        return $this->connection->execute($sql, $params);
    }

    // ---------------------------------------------------------------
    // UPDATE
    // ---------------------------------------------------------------

    /**
     * Update rows matching the current WHERE conditions.
     *
     * @param array<string, mixed> $data Column => value pairs to set
     * @return int Number of affected rows
     */
    public function update(array $data): int
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Update data cannot be empty.');
        }

        $setClauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $params[] = $value;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $setClauses);

        // Append WHERE clauses
        [$whereSql, $whereParams] = $this->buildWhereClauses();
        if ($whereSql !== '') {
            $sql .= " WHERE {$whereSql}";
            $params = array_merge($params, $whereParams);
        }

        return $this->connection->execute($sql, $params);
    }

    // ---------------------------------------------------------------
    // DELETE
    // ---------------------------------------------------------------

    /**
     * Delete rows matching the current WHERE conditions.
     *
     * @return int Number of deleted rows
     */
    public function delete(): int
    {
        $sql = "DELETE FROM {$this->table}";

        [$whereSql, $whereParams] = $this->buildWhereClauses();
        if ($whereSql !== '') {
            $sql .= " WHERE {$whereSql}";
        }

        return $this->connection->execute($sql, $whereParams);
    }

    // ---------------------------------------------------------------
    // SQL Building (private)
    // ---------------------------------------------------------------

    /**
     * Build the complete SELECT SQL and parameters.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildSelect(): array
    {
        $columns = empty($this->selects) ? '*' : implode(', ', $this->selects);
        $sql = "SELECT {$columns} FROM {$this->table}";
        $params = [];

        // JOINs
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']}"
                   . " ON {$join['first']} {$join['operator']} {$join['second']}";
        }

        // WHERE
        [$whereSql, $whereParams] = $this->buildWhereClauses();
        if ($whereSql !== '') {
            $sql .= " WHERE {$whereSql}";
            $params = array_merge($params, $whereParams);
        }

        // GROUP BY
        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        // HAVING
        if (!empty($this->havings)) {
            $havingClauses = [];
            foreach ($this->havings as $having) {
                $havingClauses[] = $having['clause'];
                $params = array_merge($params, $having['params']);
            }
            $sql .= ' HAVING ' . implode(' AND ', $havingClauses);
        }

        // ORDER BY
        if (!empty($this->orders)) {
            $orderClauses = array_map(
                fn(array $o) => "{$o['column']} {$o['direction']}",
                $this->orders
            );
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
        }

        // LIMIT
        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }

        // OFFSET
        if ($this->offsetValue !== null) {
            $sql .= " OFFSET {$this->offsetValue}";
        }

        return [$sql, $params];
    }

    /**
     * Build the WHERE clause portion and its parameters.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhereClauses(): array
    {
        if (empty($this->wheres)) {
            return ['', []];
        }

        $clauses = [];
        $params = [];

        foreach ($this->wheres as $where) {
            $clauses[] = $where['clause'];
            $params = array_merge($params, $where['params']);
        }

        return [implode(' AND ', $clauses), $params];
    }
}