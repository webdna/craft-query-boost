<?php

namespace webdna\queryboost\indexes;

use InvalidArgumentException;

/**
 * The apply step (spec §1 "Apply"): the declared list against the database. `plan()` decides, in
 * BR-6's order — every entry validated, the owned indexes read once, each entry given its state in
 * list order, then the drops — and changes nothing.
 */
final class Sync
{
    /** The refusal reason for an entry whose table the database does not have (BR-7, TN-3). */
    public const REASON_TABLE_NOT_FOUND = 'table not found';

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
     * One parsed entry's state (BR-4, BR-6): a repeat of an earlier name is served by the first; a
     * missing table is refused before any lookup; the owned name present wins over the column
     * lookup; a column entry some other index serves is served; an expression is served by nothing
     * but its own name; everything else is created.
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
