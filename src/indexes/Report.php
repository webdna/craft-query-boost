<?php

namespace webdna\queryboost\indexes;

/**
 * The words for a plan, for the console command and the deploy hook to print and log: one line per
 * entry and per drop naming the index, its table and the definition, a refusal with its reason, and
 * a summary (BR-7, BR-10, BR-11). An applied plan says what was done; a plan alone, what would be.
 */
final class Report
{
    /**
     * @param bool $applied Whether the plan was carried out (`Sync::apply()`) or only read (`Sync::plan()`)
     */
    public function __construct(
        private readonly Plan $plan,
        private readonly bool $applied,
    ) {
    }

    /**
     * Every line in order: the entries, the drops, the drops the database refused, then the summary.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->plan->entries as $entry) {
            $lines[] = $this->entryLine($entry);
        }
        foreach ($this->plan->drops as $index) {
            $lines[] = $this->dropLine($index);
        }
        foreach ($this->plan->refusedDrops as $name => $reason) {
            $lines[] = $this->refusedDropLine($name, $reason);
        }
        $lines[] = $this->summary();

        return $lines;
    }

    /**
     * One declared entry: its state first, then the index and the definition, or the reason. An
     * entry whose shape was refused never parsed, so it is shown as the file gave it.
     */
    public function entryLine(PlanEntry $entry): string
    {
        $declaration = $entry->declaration;
        if ($declaration === null) {
            $source = json_encode($entry->source, JSON_UNESCAPED_SLASHES);

            return sprintf('refused %s: %s', $source === false ? '?' : $source, (string)$entry->reason);
        }

        return match ($entry->state) {
            Plan::PRESENT => sprintf('present %s on %s', $declaration->name(), $declaration->definition()),
            Plan::CREATE => sprintf('%s %s on %s', $this->applied ? 'created' : 'would create', $declaration->name(), $declaration->definition()),
            Plan::SERVED => sprintf('served %s by %s', $declaration->definition(), (string)$entry->servedBy),
            default => sprintf('refused %s: %s', $declaration->definition(), (string)$entry->reason),
        };
    }

    public function dropLine(OwnedIndex $index): string
    {
        return sprintf('%s %s on %s', $this->applied ? 'dropped' : 'would drop', $index->name, $index->table);
    }

    public function refusedDropLine(string $name, string $reason): string
    {
        return sprintf('not dropped %s: %s', $name, $reason);
    }

    /**
     * The counts, zero ones left out; "nothing to create or drop" when no DDL ran or would; and
     * "no site indexes are declared" when the list held only the built-ins (BR-10).
     */
    public function summary(): string
    {
        $states = [Plan::PRESENT => 0, Plan::SERVED => 0, Plan::CREATE => 0, Plan::REFUSED => 0];
        $siteEntries = 0;
        foreach ($this->plan->entries as $entry) {
            $states[$entry->state] = ($states[$entry->state] ?? 0) + 1;
            $siteEntries += $entry->builtIn ? 0 : 1;
        }

        $counts = [
            'present' => $states[Plan::PRESENT],
            'served' => $states[Plan::SERVED],
            $this->applied ? 'created' : 'to create' => $states[Plan::CREATE],
            $this->applied ? 'dropped' : 'to drop' => count($this->plan->drops),
            'refused' => $states[Plan::REFUSED],
            'not dropped' => count($this->plan->refusedDrops),
        ];

        $parts = [];
        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $parts[] = "$count $label";
            }
        }
        if (!$this->plan->hasChanges() && $this->plan->refusedDrops === []) {
            $parts[] = 'nothing to create or drop';
        }

        $summary = implode(', ', $parts);
        if ($siteEntries === 0) {
            $summary .= '; no site indexes are declared';
        }

        return $summary . '.';
    }
}
