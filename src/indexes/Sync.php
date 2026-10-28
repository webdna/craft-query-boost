<?php

namespace webdna\queryboost\indexes;

use InvalidArgumentException;
use yii\db\Exception as DbException;

/**
 * The apply step (spec §1 "Apply"): the declared list against the database. `plan()` decides, in
 * BR-6's order — every entry validated, the owned indexes read once, each entry given its state in
 * list order, then the drops — and changes nothing. `apply()` carries the plan out, one statement
 * per change, and records what the database refused.
 */
final class Sync
{
    /** The refusal reason for an entry whose table the database does not have (BR-7, TN-3). */
    public const REASON_TABLE_NOT_FOUND = 'table not found';

    /** The refusal reason for an entry naming a column its table does not have, followed by `: <column>` (BR-7, TS-4). */
    public const REASON_COLUMN_NOT_FOUND = 'column not found';

    public function __construct(
        private readonly Schema $schema,
    ) {
    }

    /**
     * What an apply would do for this list: the built-ins first (BR-3), then each site entry.
     *
     * @param array<array-key, mixed> $entries The settings file's `indexes` list, as Craft merged it
     */
    public function plan(array $entries): Plan
    {
        $owned = $this->schema->ownedIndexes();
        $lines = [];
        /** @var array<string, true> $declared every name a declared entry derives, built-ins included */
        $declared = [];

        foreach (Declaration::builtIns() as $declaration) {
            $lines[] = $this->line($declaration, null, true, $owned, $declared);
        }

        foreach ($entries as $entry) {
            try {
                $declaration = Declaration::fromEntry($entry);
            } catch (InvalidArgumentException $e) {
                $lines[] = new PlanEntry(Plan::REFUSED, null, $entry, false, reason: $e->getMessage());
                continue;
            }

            $lines[] = $this->line($declaration, $entry, false, $owned, $declared);
        }

        $drops = [];
        foreach ($owned as $name => $index) {
            if (!isset($declared[$name])) {
                $drops[] = $index;
            }
        }

        return new Plan($lines, $drops);
    }

    /**
     * The plan, carried out (BR-6): each entry to create is created in list order, then each owned
     * index nothing derives is dropped. A statement the database refuses is caught and recorded —
     * the entry becomes refused with the database's own message, the drop goes on the plan's
     * `refusedDrops` — and the next statement still runs (BR-7).
     *
     * @param array<array-key, mixed> $entries The settings file's `indexes` list, as Craft merged it
     */
    public function apply(array $entries): Plan
    {
        $plan = $this->plan($entries);

        $lines = [];
        foreach ($plan->entries as $entry) {
            if ($entry->state === Plan::CREATE && $entry->declaration !== null) {
                try {
                    $this->schema->createIndex($entry->declaration);
                } catch (DbException $e) {
                    $entry = new PlanEntry(Plan::REFUSED, $entry->declaration, $entry->source, $entry->builtIn, reason: self::reason($e));
                }
            }
            $lines[] = $entry;
        }

        $drops = [];
        $refusedDrops = [];
        foreach ($plan->drops as $index) {
            try {
                $this->schema->dropIndex($index);
                $drops[] = $index;
            } catch (DbException $e) {
                $refusedDrops[$index->name] = self::reason($e);
            }
        }

        return new Plan($lines, $drops, $refusedDrops);
    }

    /**
     * The database's own message on one line: the driver's text when PDO carries it, else the
     * first line of the exception's (Yii puts the statement on a second line).
     */
    private static function reason(DbException $e): string
    {
        $driverMessage = $e->errorInfo[2] ?? null;
        if (is_string($driverMessage) && $driverMessage !== '') {
            return $driverMessage;
        }

        $firstLine = strtok($e->getMessage(), "\n");

        return $firstLine === false ? $e->getMessage() : $firstLine;
    }

    /**
     * One parsed entry's state (BR-4, BR-6): a repeat of an earlier name is served by the first; a
     * missing table or column is refused before any lookup; the owned name present wins over the
     * column lookup; a column entry some other index serves is served; an expression is served by
     * nothing but its own name; everything else is created.
     *
     * @param array<string, OwnedIndex> $owned
     * @param array<string, true> $declared Updated with this entry's name
     */
    private function line(Declaration $declaration, mixed $source, bool $builtIn, array $owned, array &$declared): PlanEntry
    {
        $name = $declaration->name();

        if (isset($declared[$name])) {
            return new PlanEntry(Plan::SERVED, $declaration, $source, $builtIn, servedBy: $name);
        }
        $declared[$name] = true;

        if (!$this->schema->tableExists($declaration->prefixedTable())) {
            return new PlanEntry(Plan::REFUSED, $declaration, $source, $builtIn, reason: self::REASON_TABLE_NOT_FOUND);
        }

        foreach ($declaration->columns as $column) {
            if (!$this->schema->hasColumn($declaration->prefixedTable(), $column)) {
                return new PlanEntry(Plan::REFUSED, $declaration, $source, $builtIn, reason: self::REASON_COLUMN_NOT_FOUND . ': ' . $column);
            }
        }

        if (isset($owned[$name])) {
            return new PlanEntry(Plan::PRESENT, $declaration, $source, $builtIn);
        }

        if (!$declaration->isExpression()) {
            $servedBy = $this->schema->findIndex($declaration->prefixedTable(), $declaration->columns);
            if ($servedBy !== null) {
                return new PlanEntry(Plan::SERVED, $declaration, $source, $builtIn, servedBy: $servedBy);
            }
        }

        return new PlanEntry(Plan::CREATE, $declaration, $source, $builtIn);
    }
}
