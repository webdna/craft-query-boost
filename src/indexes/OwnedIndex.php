<?php

namespace webdna\queryboost\indexes;

/**
 * One index the database catalogue lists under the plugin's `qb_` prefix: ours, whoever made it
 * (spec §1 "Owned", Appendix A's assumption on TN-8). What the plan keeps for a drop and what the
 * apply step hands to `DROP INDEX`.
 */
final class OwnedIndex
{
    /**
     * @param string $name The index name
     * @param string $table The table as the catalogue spells it, prefix included, ready to quote
     * @param list<string> $columns The column parts in index order; empty for an expression index
     * @param string|null $expression The expression part as the catalogue prints it (not as the entry
     * wrote it: MySQL normalises the text); null for a column index
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly array $columns,
        public readonly ?string $expression,
    ) {
    }
}
