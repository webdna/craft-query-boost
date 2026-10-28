<?php

namespace webdna\queryboost\indexes;

use Craft;
use craft\db\Connection;
use craft\helpers\Db;
use yii\db\Exception as DbException;

/**
 * The database as the sync sees it. What the plan reads: the owned-index catalogue, whether a table
 * and a column exist, and Craft's served lookup (BR-4). What the apply runs: the one `CREATE INDEX`
 * and the one `DROP INDEX` (BR-8). Each is a plain method, so a unit test can hand the plan a
 * recorded picture of a database and an integration test can lie about one index.
 *
 * The catalogue is read from `information_schema.STATISTICS` (MySQL and MariaDB) or `pg_indexes`
 * (PostgreSQL), never through Craft's `findIndexes()`: Craft's MySQL reader parses
 * `SHOW CREATE TABLE` with a pattern whose column group excludes parentheses, so it cannot see an
 * expression index at all, and the plan would create the same one on every deploy (B3).
 */
class Schema
{
    private readonly ?Connection $db;

    /**
     * @param Connection|null $db The connection to read; Craft's own when null
     */
    public function __construct(?Connection $db = null)
    {
        $this->db = $db;
    }

    /**
     * Every index whose name starts with the plugin's prefix, on every table, keyed by name in
     * catalogue order.
     *
     * @return array<string, OwnedIndex>
     */
    public function ownedIndexes(): array
    {
        $db = $this->db();
        $parts = $db->getIsPgsql() ? $this->postgresParts($db) : $this->mysqlParts($db);

        $indexes = [];
        foreach ($parts as [$name, $table, $column, $expression]) {
            $indexes[$name] ??= ['table' => $table, 'columns' => [], 'expression' => null];
            if ($column !== null) {
                $indexes[$name]['columns'][] = $column;
            } elseif ($expression !== null) {
                $indexes[$name]['expression'] = $indexes[$name]['expression'] === null
                    ? $expression
                    : $indexes[$name]['expression'] . ', ' . $expression;
            }
        }

        $owned = [];
        foreach ($indexes as $name => $index) {
            $owned[$name] = new OwnedIndex($name, $index['table'], $index['columns'], $index['expression']);
        }

        return $owned;
    }

    /**
     * Whether the table exists, so an entry on one that does not is refused before any lookup (TN-3).
     *
     * @param string $table The prefixed table, `{{%entries}}`
     */
    public function tableExists(string $table): bool
    {
        return $this->db()->tableExists($table);
    }

    /**
     * Whether the table has the column, so an entry naming one it lacks is refused before any
     * statement — by the dry run too, which is what lets the command exit non-zero on it (TS-4).
     *
     * @param string $table The prefixed table, `{{%entries}}`
     */
    public function hasColumn(string $table, string $column): bool
    {
        return $this->db()->getTableSchema($table)?->getColumn($column) !== null;
    }

    /**
     * Craft's own lookup: the name of a non-unique index on exactly these columns in this order,
     * under any name, or null (BR-4). Column indexes only; it cannot see an expression index.
     *
     * @param string $table The prefixed table, `{{%entries}}`
     * @param list<string> $columns
     */
    public function findIndex(string $table, array $columns): ?string
    {
        return Db::findIndex($table, $columns, false, $this->db());
    }

    /**
     * BR-8's statement: a plain non-unique `CREATE INDEX` under the derived name, on the prefixed
     * table so the site's prefix is honoured, with no `ALGORITHM` or `LOCK` clause so the server's
     * online default applies. An expression sits in its own parentheses, the form MySQL and
     * PostgreSQL both take.
     *
     * @throws DbException when the database refuses it; its message is the entry's reason (BR-7)
     */
    public function createIndex(Declaration $declaration): void
    {
        $db = $this->db();

        if ($declaration->isExpression()) {
            $db->createCommand(sprintf(
                'CREATE INDEX %s ON %s ((%s))',
                $db->quoteTableName($declaration->name()),
                $declaration->prefixedTable(),
                $declaration->expression,
            ))->execute();

            return;
        }

        $db->createCommand()->createIndex($declaration->name(), $declaration->prefixedTable(), $declaration->columns)->execute();
    }

    /**
     * Drops an owned index by the name and table the catalogue gave it (BR-9).
     *
     * @throws DbException when the database refuses it
     */
    public function dropIndex(OwnedIndex $index): void
    {
        $this->db()->createCommand()->dropIndex($index->name, $index->table)->execute();
    }

    private function db(): Connection
    {
        return $this->db ?? Craft::$app->getDb();
    }

    /**
     * One row per index part, in index order: name, table, column or null, expression or null.
     * MariaDB's STATISTICS has no EXPRESSION column (and no expression indexes), so it is read as null.
     *
     * @return list<array{string, string, string|null, string|null}>
     */
    private function mysqlParts(Connection $db): array
    {
        $expression = $db->getIsMaria() ? 'NULL' : 'EXPRESSION';
        $rows = $db->createCommand(
            "SELECT INDEX_NAME AS idx, TABLE_NAME AS tbl, COLUMN_NAME AS col, $expression AS expr
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND LEFT(INDEX_NAME, " . strlen(Declaration::PREFIX) . ") = :prefix
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            [':prefix' => Declaration::PREFIX],
        )->queryAll();

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = [
                (string)$row['idx'],
                (string)$row['tbl'],
                $row['col'] !== null ? (string)$row['col'] : null,
                $row['expr'] !== null ? (string)$row['expr'] : null,
            ];
        }

        return $parts;
    }

    /**
     * The PostgreSQL branch, read from each index's definition in `pg_indexes`: the parenthesised
     * key list after `USING <method>`, split on its top-level commas, a bare or quoted identifier
     * being a column and anything else an expression. Written, not yet run (TN-11, Appendix A #3).
     *
     * @return list<array{string, string, string|null, string|null}>
     */
    private function postgresParts(Connection $db): array
    {
        $rows = $db->createCommand(
            'SELECT indexname AS idx, tablename AS tbl, indexdef AS def
            FROM pg_indexes
            WHERE schemaname = current_schema() AND LEFT(indexname, ' . strlen(Declaration::PREFIX) . ') = :prefix
            ORDER BY tablename, indexname',
            [':prefix' => Declaration::PREFIX],
        )->queryAll();

        $parts = [];
        foreach ($rows as $row) {
            foreach ($this->keyParts((string)$row['def']) as $part) {
                $isColumn = preg_match('/^"?([A-Za-z0-9_]+)"?$/', $part, $match) === 1;
                $parts[] = [
                    (string)$row['idx'],
                    (string)$row['tbl'],
                    $isColumn ? $match[1] : null,
                    $isColumn ? null : $part,
                ];
            }
        }

        return $parts;
    }

    /**
     * The key parts of a `CREATE INDEX … USING btree (…)` definition, split on top-level commas.
     *
     * @return list<string>
     */
    private function keyParts(string $definition): array
    {
        $open = preg_match('/\bUSING\s+\w+\s*\(/i', $definition, $match, PREG_OFFSET_CAPTURE) === 1
            ? $match[0][1] + strlen($match[0][0])
            : null;
        if ($open === null) {
            return [];
        }

        $parts = [];
        $depth = 0;
        $current = '';
        for ($i = $open, $length = strlen($definition); $i < $length; $i++) {
            $char = $definition[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
