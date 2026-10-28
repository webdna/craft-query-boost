<?php

namespace webdna\queryboost\indexes;

use InvalidArgumentException;

/**
 * One declared index: an entry of the `indexes` list in `config/query-boost.php` (spec §4), or one of
 * the two built-ins (BR-3). It checks the entry's shape (BR-2) and derives the index's name (BR-5).
 * Whether the index exists, is served by another or can be created is the apply step's business.
 *
 * An entry is an array with `table` and exactly one of `columns` (a non-empty list of column names)
 * or `expression` (one indexable expression). Any other key is ignored: the craft-perf run's
 * provenance, and a `name`, which is never honoured because the derived `qb_` name is how ownership
 * is known. `fromEntry()` refuses anything else with an `InvalidArgumentException` whose message is
 * one of the `REASON_*` constants.
 */
final class Declaration
{
    /** Every index the plugin owns is named under this; uninstall drops every index that carries it. */
    public const PREFIX = 'qb_';

    /**
     * The longest column-form name. Past it the hashed form keeps the name under MySQL's 64 and
     * PostgreSQL's 63 characters (BR-5).
     */
    public const MAX_COLUMN_NAME_LENGTH = 60;

    // Refusal reasons (BR-2, BR-7)
    public const REASON_NOT_AN_ARRAY = 'an entry is an array with table and columns or expression';
    public const REASON_TABLE = 'table must be a non-empty string';
    public const REASON_ONE_DEFINITION = 'an entry has columns or expression, not both and not neither';
    public const REASON_COLUMNS = 'columns must be a non-empty list of column names';
    public const REASON_EXPRESSION = 'expression must be a non-empty string';

    /**
     * @param string $table Craft's table name without the prefix, as `craft\db\Table` spells it
     * @param list<string> $columns The columns in index order; empty for an expression entry
     * @param string|null $expression The expression, as MySQL prints it in a plan; null for a columns entry
     */
    private function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly ?string $expression,
    ) {
    }

    /**
     * The indexes Craft 6 ships, always declared ahead of the site's list (BR-3), under the names
     * the install migration has given them since the first release.
     *
     * @return list<self>
     */
    public static function builtIns(): array
    {
        return [
            new self('entries', ['sectionId', 'postDate'], null),
            new self('elements', ['canonicalId', 'dateCreated'], null),
        ];
    }

    /**
     * Reads one entry of the `indexes` list.
     *
     * @throws InvalidArgumentException with a `REASON_*` message when the entry's shape is wrong (BR-2)
     */
    public static function fromEntry(mixed $entry): self
    {
        if (!is_array($entry)) {
            throw new InvalidArgumentException(self::REASON_NOT_AN_ARRAY);
        }

        $table = $entry['table'] ?? null;
        if (!is_string($table) || trim($table) === '') {
            throw new InvalidArgumentException(self::REASON_TABLE);
        }

        $hasColumns = isset($entry['columns']);
        $hasExpression = isset($entry['expression']);
        if ($hasColumns === $hasExpression) {
            throw new InvalidArgumentException(self::REASON_ONE_DEFINITION);
        }

        if ($hasExpression) {
            $expression = $entry['expression'];
            if (!is_string($expression) || trim($expression) === '') {
                throw new InvalidArgumentException(self::REASON_EXPRESSION);
            }

            return new self($table, [], $expression);
        }

        $columns = $entry['columns'];
        if (!is_array($columns) || $columns === [] || !array_is_list($columns)) {
            throw new InvalidArgumentException(self::REASON_COLUMNS);
        }
        foreach ($columns as $column) {
            if (!is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException(self::REASON_COLUMNS);
            }
        }

        return new self($table, $columns, null);
    }

    public function isExpression(): bool
    {
        return $this->expression !== null;
    }

    /**
     * The table as DDL and Craft's index lookup address it, so the site's prefix is honoured (BR-8).
     */
    public function prefixedTable(): string
    {
        return '{{%' . $this->table . '}}';
    }

    /**
     * The entry as the output lines print it: `entries (sectionId, postDate)`.
     */
    public function definition(): string
    {
        return $this->table . ' (' . ($this->expression ?? implode(', ', $this->columns)) . ')';
    }

    /**
     * The index's name, derived and never declared (BR-5): the prefix, the table and the columns
     * lower-cased and joined with `_`; for an expression, `_x` and ten hex characters of the SHA-1
     * of the expression with its whitespace collapsed; when the column form would pass sixty
     * characters, `_h` and ten hex characters of the SHA-1 of the definition.
     */
    public function name(): string
    {
        if ($this->expression !== null) {
            $collapsed = trim((string)preg_replace('/\s+/', ' ', $this->expression));

            return self::PREFIX . $this->table . '_x' . self::hash($collapsed);
        }

        $name = self::PREFIX . $this->table . '_' . strtolower(implode('_', $this->columns));
        if (strlen($name) > self::MAX_COLUMN_NAME_LENGTH) {
            return self::PREFIX . $this->table . '_h' . self::hash($this->definition());
        }

        return $name;
    }

    private static function hash(string $subject): string
    {
        return substr(sha1($subject), 0, 10);
    }
}
