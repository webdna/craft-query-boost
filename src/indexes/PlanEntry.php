<?php

namespace webdna\queryboost\indexes;

/**
 * One declared entry's line in a plan: its state (a `Plan` constant), the declaration it parsed to,
 * and either the index that serves it or the reason it was refused. The command and the hook render
 * these; tests assert on them (spec §7, test hooks).
 */
final class PlanEntry
{
    /**
     * @param string $state One of `Plan::PRESENT`, `Plan::CREATE`, `Plan::SERVED`, `Plan::REFUSED`
     * @param Declaration|null $declaration The parsed entry; null when its shape was refused
     * @param mixed $source The entry as the settings file gave it; null for a built-in
     * @param bool $builtIn Whether this is one of the two indexes the plugin always declares (BR-3)
     * @param string|null $servedBy The index that serves the entry, for `Plan::SERVED`
     * @param string|null $reason Why the entry was refused, for `Plan::REFUSED`
     */
    public function __construct(
        public readonly string $state,
        public readonly ?Declaration $declaration,
        public readonly mixed $source,
        public readonly bool $builtIn,
        public readonly ?string $servedBy = null,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * The derived index name, or null when the entry never parsed.
     */
    public function name(): ?string
    {
        return $this->declaration?->name();
    }
}
