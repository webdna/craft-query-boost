# Query Boost

Craft 6's element query speed-ups, for Craft 5.

Craft 6 rebuilt element queries ([craftcms/cms#18716](https://github.com/craftcms/cms/pull/18716)) and
changed two indexes. On a section with 500,000 entries, Craft 5 takes 1–2 seconds to list a page of
them; with this plugin it takes a few milliseconds. Results are identical: the plugin changes how
MySQL or PostgreSQL finds the rows, never which rows or in what order.

## What it does

1. **Adds Craft 6's composite indexes** on install: `entries (sectionId, postDate)` and
   `elements (canonicalId, dateCreated)`. Each is created only if no index on those columns exists,
   and uninstalling drops only the indexes the plugin created. The entries index alone speeds up the
   common template query `craft.entries.section('news').orderBy('postDate desc').limit(10)`: on a
   section of 41,000 entries, from 24ms to 3ms.
2. **Rewrites the sort tiebreaker.** Craft 5 sorts entries by `postDate DESC, elements.id DESC`.
   Because the tiebreaker is on a different table, the database cannot read the order from an index,
   so it sorts every row in the section to return the first page. The plugin rewrites the
   tiebreaker to the element table's own `id` (`entries.id`, `commerce_products.id`, …), as Craft 6
   does. The two columns are always equal, so the order cannot change. This, with the index, is
   where the speed-up comes from. It is rewritten only when every other sort column is on the
   element table: a sort led by an `elements` column, such as an address's `dateCreated`, is already
   served by an `elements` index, and moving its tiebreaker would force a full sort.
3. **Optionally forces the join order** (`straightJoin`, MySQL and MariaDB only, off by default).
   Craft 6 starts from the element table and `STRAIGHT_JOIN`s `elements`, so the optimizer cannot
   choose to read `elements` first. The plugin does so only when the query is unsorted or sorted
   entirely on the element table. MySQL 8.4 already picks that order, and forcing it can slow a
   deep offset, so turn it on only where `query-boost/compare` shows it helps.

It works through `ElementQuery::EVENT_AFTER_PREPARE` and touches only element queries whose subquery
joins an element table (entries, users, assets, Commerce products, …).

## Requirements

Craft CMS 5.0 or later, PHP 8.2 or later.

## Installation

```bash
composer require webdna/query-boost
php craft plugin/install query-boost
```

## Configuration

Create `config/query-boost.php`:

```php
<?php

return [
    // Rewrite the elements.id sort tiebreaker to the element table's id.
    'tiebreaker' => true,
    // MySQL/MariaDB only: start from the element table and STRAIGHT_JOIN elements.
    'straightJoin' => false,
];
```

## Checking it on a site

```bash
php craft query-boost/compare [--reps=5] [--limit=100]
```

This runs every section (default order, page 2, live entries only, `count()`) and every element type
with the rewrites off, with the tiebreaker, and (on MySQL) with the forced join order. For each
query it reports whether all modes returned the same elements, and the median time of each. It exits
non-zero if any query differed.

## Upgrading to Craft 6

Craft 6 does all of this itself. Uninstall the plugin before upgrading: that drops its two indexes,
and Craft 6's schema provides its own.
