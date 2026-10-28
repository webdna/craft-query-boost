<?php

namespace webdna\queryboost;

use craft\db\Query;
use craft\db\Table;

/**
 * Rewrites a prepared Craft 5 element subquery the way Craft 6 builds element queries.
 *
 * Craft 5's subquery is `FROM elements JOIN elements_sites JOIN <element table>`, sorted with an
 * `elements.id` tiebreaker. The element table is the one `ElementQuery::joinElementTable()` joins on
 * `[[<alias>.id]] = [[elements.id]]` (`entries`, `users`, `commerce_products`, …).
 */
class QueryRewriter
{
    /**
     * Rewrites the subquery in place. Returns the element table alias it found, or null if the
     * subquery has none and was left alone.
     *
     * @param Query<array-key,mixed> $subQuery
     */
    public static function apply(Query $subQuery, bool $tiebreaker, bool $straightJoin): ?string
    {
        if (!is_array($subQuery->from) || ($subQuery->from['elements'] ?? null) !== Table::ELEMENTS) {
            return null;
        }

        $found = self::findElementTableJoin($subQuery);
        if ($found === null) {
            return null;
        }
        [$index, $alias, $table] = $found;

        if ($tiebreaker && self::sortsOnlyOn($subQuery, $alias)) {
            $orderBy = [];
            foreach ($subQuery->orderBy as $column => $direction) {
                $orderBy[$column === 'elements.id' ? "$alias.id" : $column] = $direction;
            }
            $subQuery->orderBy = $orderBy;
        }

        // Starting from the element table only pays when that table can also serve the sort
        if ($straightJoin && self::sortIsOn($subQuery, $alias)) {
            unset($subQuery->join[$index]);
            $subQuery->from = $table;
            $subQuery->join = array_merge(
                [['STRAIGHT_JOIN', ['elements' => Table::ELEMENTS], "[[elements.id]] = [[$alias.id]]"]],
                array_values($subQuery->join),
            );
        }

        return $alias;
    }

    /**
     * Whether the sort has an `elements.id` tiebreaker and every other column is on the element
     * table. Only then does the rewrite turn a two-table sort into one an index can serve; a sort led
     * by an `elements` column (an address's `dateCreated`) is already served by `elements`' indexes,
     * and moving its tiebreaker off `elements` would force a filesort.
     *
     * @param Query<array-key,mixed> $subQuery
     */
    private static function sortsOnlyOn(Query $subQuery, string $alias): bool
    {
        if (!is_array($subQuery->orderBy) || !array_key_exists('elements.id', $subQuery->orderBy)) {
            return false;
        }

        foreach (array_keys($subQuery->orderBy) as $column) {
            if ($column !== 'elements.id' && !str_starts_with((string)$column, "$alias.")) {
                return false;
            }
        }

        return count($subQuery->orderBy) > 1;
    }

    /**
     * Whether the query is unsorted, or sorted only by columns on the element table.
     *
     * @param Query<array-key,mixed> $subQuery
     */
    private static function sortIsOn(Query $subQuery, string $alias): bool
    {
        foreach (array_keys($subQuery->orderBy ?? []) as $column) {
            if (!str_starts_with((string)$column, "$alias.")) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Query<array-key,mixed> $subQuery
     * @return array{int|string, string, array<string,string>}|null The join's key, its alias, and its table
     */
    private static function findElementTableJoin(Query $subQuery): ?array
    {
        foreach ($subQuery->join ?? [] as $index => $join) {
            [$type, $table, $on] = $join + [2 => ''];
            if (
                $type === 'INNER JOIN' &&
                is_array($table) &&
                is_string($on) &&
                preg_match('/^\[\[(\w+)\.id\]\] = \[\[elements\.id\]\]$/', $on, $match) &&
                isset($table[$match[1]])
            ) {
                return [$index, $match[1], $table];
            }
        }

        return null;
    }
}
