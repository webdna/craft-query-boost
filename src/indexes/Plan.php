<?php

namespace webdna\queryboost\indexes;

/**
 * What an apply would do: one line per declared entry, built-ins first, in list order, then the
 * owned indexes nothing derives any more. Built by `Sync::plan()`, carried out by `Sync::apply()`,
 * rendered by the console command and the deploy hook.
 */
final class Plan
{
    /** The owned index of the entry's derived name exists; nothing to do (TN-14: by name alone). */
    public const PRESENT = 'present';

    /** No index serves the entry; it is created under its derived name. */
    public const CREATE = 'create';

    /** An index on the same columns in the same order already exists under another name (BR-4). */
    public const SERVED = 'served';

    /** The entry cannot be applied: its shape, its table or the database refused it (BR-7). */
    public const REFUSED = 'refused';

    /**
     * @param list<PlanEntry> $entries Every declared entry, the built-ins first, in list order
     * @param list<OwnedIndex> $drops Every owned index no built-in and no declared entry derives (BR-9)
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $drops,
    ) {
    }

    /**
     * How many entries were refused; the console command's exit code is non-zero when this is not 0.
     */
    public function refusedCount(): int
    {
        return count(array_filter($this->entries, fn(PlanEntry $entry) => $entry->state === self::REFUSED));
    }

    /**
     * Whether an apply would run any DDL: a create or a drop. False is BR-6's "changes nothing".
     */
    public function hasChanges(): bool
    {
        if ($this->drops !== []) {
            return true;
        }

        foreach ($this->entries as $entry) {
            if ($entry->state === self::CREATE) {
                return true;
            }
        }

        return false;
    }
}
