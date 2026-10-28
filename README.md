# Query Boost

Craft 6's element query speed-ups, for Craft 5.

Craft 6 rebuilt element queries ([craftcms/cms#18716](https://github.com/craftcms/cms/pull/18716))
and added two composite indexes ([craftcms/cms#19579](https://github.com/craftcms/cms/pull/19579)).
On a section with 500,000 entries, Craft 5 takes 1–2 seconds to list a page of them; with this
plugin it takes a few milliseconds. Results are identical: the plugin changes how MySQL or
PostgreSQL finds the rows, never which rows or in what order.

The plugin also carries a site's own indexes: the ones a performance run proved on that site,
declared in its settings file and created on every deploy ([Declaring indexes](#declaring-indexes)).

## What it does

1. **Adds Craft 6's composite indexes** on install: `entries (sectionId, postDate)` and
   `elements (canonicalId, dateCreated)`, the two that
   [craftcms/cms#19579](https://github.com/craftcms/cms/pull/19579) (8 September 2026) added to
   Craft 6's install migration. Each is created only if no index on those columns exists, and
   uninstalling drops only the indexes the plugin created. The entries index alone speeds up the
   common template query `craft.entries.section('news').orderBy('postDate desc').limit(10)`: on a
   section of 41,000 entries, from 24ms to 3ms.
2. **Rewrites the sort tiebreaker.** Craft 5 sorts entries by `postDate DESC, elements.id DESC`.
   Because the tiebreaker is on a different table, the database cannot read the order from an index,
   so it sorts every row in the section to return the first page. The plugin rewrites the
   tiebreaker to the element table's own `id` (`entries.id`, `commerce_products.id`, …), as Craft 6
   does since [craftcms/cms#18716](https://github.com/craftcms/cms/pull/18716). The two columns are
   always equal, so the order cannot change. This, with the index, is where the speed-up comes
   from. It is rewritten only when every other sort column is on the element table *and* an index
   on that table can serve the sort: some index must list the sort's columns, in the sort's order,
   as a contiguous run. A sort led by an `elements` column, such as an address's `dateCreated`, is
   already served by an `elements` index, and moving its tiebreaker would force a full sort; a sort
   no index serves gains nothing from the rewrite. In both cases the query is left exactly as
   Craft wrote it. The index check reads the schema at most once per table per request.
3. **Optionally forces the join order** (`straightJoin`, MySQL and MariaDB only, off by default).
   Craft 6 starts from the element table and `STRAIGHT_JOIN`s `elements`, so the optimizer cannot
   choose to read `elements` first. The plugin does so only when the query is unsorted or sorted
   entirely on the element table. MySQL 8.4 already picks that order, and forcing it can slow a
   deep offset, so turn it on only where `query-boost/compare` shows it helps.
4. **Creates the indexes you declare.** A list in the settings file, applied after Craft's
   migrations on every deploy and dropped when struck from the list. The file is the one record of
   what the site carries and why. See [Declaring indexes](#declaring-indexes).

The query rewrites work through `ElementQuery::EVENT_AFTER_PREPARE` and touch only element queries
whose subquery joins an element table (entries, users, assets, Commerce products, …). The indexes
are created and dropped only on install and uninstall, on deploy and by the apply command; no
ordinary web request runs index DDL.

## Requirements

Craft CMS 5.0 or later, PHP 8.2 or later.

## Installation

```bash
composer require webdna/query-boost
php craft plugin/install query-boost
```

Installing creates the two built-in indexes and any the settings file declares and, from the
console, prints a line for each.

## Configuration

Create `config/query-boost.php`:

```php
<?php

return [
    // Rewrite the elements.id sort tiebreaker to the element table's id.
    'tiebreaker' => true,
    // MySQL/MariaDB only: start from the element table and STRAIGHT_JOIN elements.
    'straightJoin' => false,
    // The indexes a performance run proved on this site. Each entry is a table and either its
    // columns, in index order, or one expression. Created on the next deploy, dropped when struck.
    'indexes' => [
        [
            'table' => 'elements_sites',
            'columns' => ['siteId', 'enabled'],
            // Anything else on an entry is for the reader; the plugin ignores it.
            'measured' => '87 ms to 14 ms, 590,322 rows read to 12,867',
            'source' => 'craft-perf query audit, 28 Oct 2026',
        ],
    ],
];
```

It is an ordinary Craft plugin settings file, so a `'*'` block with per-environment blocks beside
it works as in every other: an index declared only under `'production'` is created on production
and nowhere else. The list is read from this file alone, never from project config or the control
panel.

### Declaring indexes

**An entry** is an array with `table` and exactly one of `columns` or `expression`:

| Key | Value |
|---|---|
| `table` | Craft's table name without the prefix, as `craft\db\Table` spells it: `entries`, `elements_sites`, `commerce_products` |
| `columns` | The column names, in index order |
| `expression` | One indexable expression, exactly as MySQL prints it in a query plan. Craft's field cast `CAST((content->>'$."<field uid>"') AS CHAR(255))` is the common one. MySQL and PostgreSQL |
| anything else | Ignored by the plugin and kept for the reader: the figures, the finding, the date |

An entry of any other shape — a plain string, no table, both or neither of `columns` and
`expression`, an empty list — is refused as a whole and nothing of it is applied.

**The index's name is derived, never declared.** It is `qb_` + the table + `_` + the columns
lower-cased and joined with `_`, so the entry above becomes `qb_elements_sites_siteid_enabled`, and
the two built-ins are `qb_entries_sectionid_postdate` and `qb_elements_canonicalid_datecreated`. An
expression is named `qb_` + the table + `_x` + ten hex characters of the SHA-1 of the expression
with its whitespace collapsed; a column name that would pass 60 characters becomes `qb_` + the
table + `_h` + ten hex characters of the SHA-1 of the definition, so no name reaches MySQL's limit
of 64 or PostgreSQL's of 63. The same entry always derives the same name. The `qb_` prefix is how
the plugin knows its own indexes: it creates and drops only names that carry it, and a `name` key
on an entry is ignored. Any index named `qb_…` is treated as the plugin's, whoever created it, and
may be dropped on the next apply: give an index of your own another name.

**Served.** When an index on the same columns in the same order already exists under any name,
nothing is created for the entry and the output names the index that serves it. The two built-ins
are always declared ahead of your list, so an entry for `entries (sectionId, postDate)` is reported
as served by `qb_entries_sectionid_postdate`; an entry that appears twice is created once and the
second reported as served by the first. An expression entry is served only by the plugin's own
index of its derived name, because Craft's index lookup cannot see expression indexes.

**Struck.** Remove an entry, the `indexes` key or the whole file, and the next apply drops the
index the plugin created for it. An index on the same columns under another name is never touched,
however redundant, and the built-ins cannot be struck: only uninstalling removes them.

**Creation is a plain `CREATE INDEX`:** non-unique, with no `ALGORITHM` or `LOCK` clause, on
`{{%table}}` so the site's table prefix is honoured. InnoDB builds a secondary index online, so
reads continue, but it takes the time the database takes on that table: declare an index on a
very large table in the same window the site would run any other migration. The plugin never
creates a unique index and never alters an existing one.

### On deploy

The plugin applies the list once, straight after Craft's `migrate/all` action completes
successfully. `craft up` runs that action first, so a deploy that runs `craft up` — or
`migrate/all` itself — picks the list up with no change to the deploy. A failed migration step
means no apply. The hook is attached only for console requests, and only while the plugin is
installed and enabled. A host whose deploy runs neither `craft up` nor `migrate/all` applies the
list only when its deploy calls `php craft query-boost/apply`.

The deploy output gains one line per change or refusal, prefixed with the plugin's name, and a
summary. Declaring the entry above and deploying prints:

```
Query Boost: created qb_elements_sites_siteid_enabled on elements_sites (siteId, enabled)
Query Boost: 2 present, 1 created.
```

A deploy with nothing to do, or nothing declared, prints the summary alone:

```
Query Boost: 2 present, nothing to create or drop; no site indexes are declared.
```

The same lines are written to Craft's log under `query-boost`.

**A refused entry never fails the deploy.** Its line names the entry and the reason — the shape
rule it broke, `table not found`, `column not found: <column>`, or the database's own message, such
as a `TEXT` column indexed without a key length — and every other entry is still applied. The
deploy's exit code is Craft's own. The entry is reported again on every deploy until it is fixed
or struck.

```
Query Boost: refused entries (noSuchColumn): column not found: noSuchColumn
Query Boost: created qb_elements_sites_siteid_enabled on elements_sites (siteId, enabled)
Query Boost: 2 present, 1 created, 1 refused.
```

### By hand, and the dry run

```bash
php craft query-boost/apply --dry-run
php craft query-boost/apply
```

The dry run reports every declared entry's state — present, to be created, served by a named
existing index, or refused with the reason — and every `qb_` index it would drop, changes nothing
and exits 0, unless an entry is refused. Run it on any copy of the site to see what the next
deploy will do:

```
present qb_entries_sectionid_postdate on entries (sectionId, postDate)
present qb_elements_canonicalid_datecreated on elements (canonicalId, dateCreated)
would create qb_elements_sites_siteid_enabled on elements_sites (siteId, enabled)
2 present, 1 to create.
```

Without the flag the command makes the same changes a deploy would. Both forms exit non-zero when
any entry was refused, so a mistake in the file fails a hand run or a CI step while a deploy
carries on.

Applying is idempotent: the entries are validated, the plugin's own indexes read once from the
database, each declared-and-unserved entry created in list order, then each `qb_` index that
neither a built-in nor a declared entry derives is dropped. A second apply on an unchanged list
changes nothing.

### Uninstalling

Uninstalling drops every index whose name starts with `qb_`, on every table, read from the
database's own catalogue rather than from the file: the two built-ins, the site's declared
indexes, and any `qb_` index left by an entry struck since the last apply. Reinstalling creates
the built-ins and the declared list together.

## Checking it on a site

```bash
php craft query-boost/compare [--reps=5] [--limit=100]
```

This runs every section (default order, page 2, live entries only, `count()`) and every element type
with the rewrites off, with the tiebreaker, and (on MySQL) with the forced join order. For each
query it reports whether all modes returned the same elements, and the median time of each. A mode
marked `=` produced SQL identical to the rewrites being off — a query the plugin left alone, such
as a sort no index serves — so its time difference is noise. It exits non-zero if any query
differed.

## Upgrading to Craft 6

Craft 6 does the query work itself and carries the two built-in indexes in its own schema.
Uninstall the plugin before upgrading: that drops every index it created, the built-ins and your
site's. The settings file still lists the site's, so one that is still wanted can be written as a
project migration of Craft 6's own.
