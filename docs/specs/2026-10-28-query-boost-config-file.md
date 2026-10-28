---
spec: Query Boost — the config file and its index list
slug: query-boost-config-file
status: draft
version: 0.1
date: 2026-10-28
author: Samuel Birch
client: webdna (internal; every audited client site is affected)
approver: Sam Birch
profile: _PROFILE.query-boost.md
related: ["~/Projects/claudeskills/.claude/worktrees/craft-perf-spec/docs/specs/_scope/craft-perf-next.md — the scope note whose Query Boost half this is", "~/Projects/claudeskills/.claude/worktrees/craft-perf-spec/docs/specs/2026-10-26-craft-perf.md — the skill whose /craft-perf query-boost subcommand will write this file (its §2 Later)", "~/Projects/claudeskills/.claude/worktrees/craft-perf-spec/docs/research/2026-10-28-craft-perf-coverage.md — the 28 Oct review this came from"]
---

# Query Boost — the config file and its index list

> **Status:** draft - **Version:** 0.1 - **Profile:** `_PROFILE.query-boost.md`
> A site's developer can declare, in one file that deploys with the code, the database indexes a performance run proved on that site, and the plugin creates them on every deploy and removes them when they are struck out.

---

## 1. How it works

Today a performance run proves that an index speeds a site's query, and the proof stops there: the developer re-types the index as a migration, by hand, one site at a time. After this ships, the developer writes the index into Query Boost's settings file as one short entry and commits it. On the next deploy the plugin creates the index, under a name that marks it as the plugin's. Strike the entry out and the next deploy drops the index. Nothing is applied by hand, nothing is applied by the run that proved it, and the file is the one record of what this site carries and why: each entry can carry the figures that justified it, which the plugin ignores and a reader does not.

The approach rests on one idea: **the plugin is the only copy of the mechanism, and the file is the only copy of the decision.** The plugin already ships two indexes of its own and already marks what it creates with a name prefix, so it can tell its own indexes from Craft's and a developer's without keeping a register. The site's list is applied the same way: an entry whose columns some index already covers, under any name, creates nothing; an entry nobody covers is created under the plugin's name; an index under the plugin's name that no entry (and no built-in) claims any more is dropped. The deploy applies the list because every deploy already runs Craft's migrations, and the plugin acts straight after them. A developer who wants to see what a deploy would do runs the same step as a dry run.

Two alternatives were rejected on 28 Oct. A performance module generated into each site, carrying the rewriter and the site's indexes, because a copy of the rewriter in every client repository is fixed one site at a time and a regenerated module overwrites what the site's developer changed. And one migration file per accepted index, because a list the plugin applies is a single tailored record a developer can read in one place and strike from, where a drawer of migrations is neither. A third option was considered while writing this: declaring the list to Craft as a migration track of its own. Craft 5 runs only its own, each plugin's and the content track on a deploy; a track of ours would be run only when named, so it would have needed a change to every site's deploy.

The same release narrows the plugin's existing sort rewrite. Today it rewrites a sort's tiebreaker whenever the whole sort is on one table; after this it also asks whether that table has an index that can serve the sort, and leaves a query alone when none can. A query the plugin cannot speed up then runs exactly as Craft wrote it, which keeps the comparison command's "unchanged" marker honest. And the README is corrected to name the pull request the two built-in indexes actually came from.

**Vocabulary**

| Term | Means |
|---|---|
| Declared index | One entry in the settings file's list: a table and its columns, or a table and one expression |
| Built-in | One of the two indexes the plugin has always created on install; always treated as declared |
| Owned | An index whose name starts with the plugin's prefix. The plugin creates, and may drop, only owned indexes |
| Served | A declared index whose columns an existing index already covers, under any name. Nothing is created for it |
| Struck | An entry removed from the list, or the whole file removed. Its owned index is dropped on the next apply |
| Refused | An entry the plugin cannot apply: wrong shape, unknown table, or a definition the database rejects. Reported, retried on the next apply, never fatal to a deploy |
| Apply | The one step that compares the list with the database and makes the changes; run by the deploy hook, by the console command, and on install |
| Dry run | The apply step reporting what it would change and changing nothing |

---

## 2. Scope

**In scope**

- A new index list in the plugin's settings file, each entry a table and either a list of columns or one expression; anything else on an entry is for the reader and ignored. Per-environment blocks work as in every Craft settings file
- The apply step: create what is declared and not served, drop what is owned and no longer declared, report what is refused, and a dry run of the same
- The console command that runs it by hand, with the dry run
- The deploy hook: the apply step runs after Craft's own migration step on every deploy, so a site needs no change to its deploy to pick the list up
- Install applies the built-ins and the list together; uninstall drops every owned index
- The index-aware guard on the tiebreaker rewrite
- The README: the new key, the deploy and uninstall behaviour, the hand-run command, and the two built-ins credited to craftcms/cms pull request #19579
- A test harness for the plugin (static analysis, coding standard, unit and integration suites in containers), which the plugin has never had

**Out of scope** - *each with the reason, so nobody re-proposes it in review*

| Not doing | Why |
|---|---|
| Unique indexes, or any index that changes what a query can return | The plugin changes how rows are found, never which rows; a unique index is a constraint, and a failed one blocks a save |
| A control-panel screen for the list | The list must deploy with the code and differ per environment where it must; a screen would store it in the database, per environment, with no history |
| Writing the file from a performance run | That is the craft-perf skill's own subcommand, specified with the craft-perf increment; this spec fixes the file's shape it writes to |
| Dropping an index the plugin did not create, however redundant | Ownership is the name prefix; an index under another name is somebody else's decision |
| Applying anything on a web request, or from the control panel | Index creation is DDL on a live table; it belongs to the deploy and the console |
| Failing a deploy over a refused entry | A mistyped column would block a release that has nothing to do with it; the entry is reported every deploy until fixed or struck |
| Production server tuning of any kind | Nothing in the plugin's reach; see the craft-perf scope note |

**Later** - *deliberately deferred, not refused*

- The type-table-first query shape of craftcms/cms pull request #18716: the subquery removed and the join order forced from the element table, which is where Craft 6's gain on large sections comes from. The plugin's forced-join-order setting today reorders inside the subquery, which is why it did nothing on MySQL 8.4. Its own spec: it rewrites the outer query and touches every consumer of a prepared element query
- A third built-in, a composite index on the element-sites table's site and enabled columns, once it is measured on Heathfield and Roper Rhodes (Appendix A #1). turf5's trial cut 590,322 rows read to 12,867 and 87 ms to 14 ms with identical results, and Craft 6 still carries the two single-column indexes; if it holds, an upstream pull request with the figures
- Automatic lazy eager loading (pull request #19637): whether a plugin can switch Craft 5's default needs a hook early enough in a query's life, checked, not assumed (Appendix A #2)
- A PostgreSQL leg of the test harness, if a Postgres client site arrives (Appendix A #3)

---

## 3. User journeys

**Who this is for**

| Group | What they get |
|---|---|
| webdna developers running a performance audit | A proved index reaches production as one committed entry, and the file says what the site carries and why |
| The developer who maintains a client site later | One place to read every index the site added, strike one, or see what a deploy will do before it runs |
| Query Boost's own maintainers | A fixed file shape the craft-perf subcommand writes to, and a harness every later change is proved against |
| Clients of audited sites (affected only) | A faster page after an ordinary deploy, with nothing new to own |
| The host (affected only) | One more step in the deploy's output, after Craft's migrations, that creates or drops an index |

**1. Declaring an index a run proved**
   1. A performance run on the developer's copy of the site recommends an index, with its before and after.
   2. The developer adds one entry to the plugin's settings file: the table and the columns, and beside them the figures and the report the run gave, as a note to the next reader.
   3. They commit the file with the fix it belongs to; nothing else in the project changes.
   4. The deploy runs Craft's migrations as it always has; straight after, the deploy output shows the plugin creating the index under its own name.
   5. The developer confirms on the deployed site with the plugin's comparison command: every query returns the same elements with the rewrites off and on.

**2. Striking an index**
   1. The developer removes the entry (or the whole file: the built-ins stay, every site-declared index goes).
   2. On the next deploy the output shows the plugin dropping the index it had created. An index under any other name on the same columns is untouched.

**3. Seeing what a deploy would do**
   1. On any copy of the site, the developer runs the apply command as a dry run.
   2. It lists each declared entry with its state: present, to be created, served by a named existing index, or refused with the reason; then every owned index it would drop. It changes nothing and exits clean, unless an entry is refused.
   3. Run without the dry-run flag it makes the same changes a deploy would, and exits non-zero if any entry was refused.

**4. A mistake in the file**
   1. An entry names a column the table does not have.
   2. The deploy still succeeds; its output names the entry and the database's reason. Every other entry is applied.
   3. Every later deploy reports it again until the entry is fixed or struck.

**5. Leaving for Craft 6**
   1. Before upgrading, the developer uninstalls the plugin, as the README has always said.
   2. Every index the plugin created goes with it, the built-ins and the site's alike. The file still lists the site's, so one that is still wanted can be written as a project migration of Craft 6's own.

**First run - the empty state.** A site with no settings file, or with no index list in it, is exactly today's plugin: the two built-ins exist and nothing else is created or dropped. The dry run says that no site indexes are declared and shows the two built-ins as present. The deploy output's one line says the same.

---

## 4. Data model

No new table. **The settings file is the storage** — `config/query-boost.php` in the site, read through Craft's ordinary plugin-settings merge, so a site-wide `'*'` block and per-environment blocks work with no code of ours.

**`indexes`** — a list; each entry an array

| Key | Type | Notes |
|---|---|---|
| `table` | string | Craft's table name without the prefix, as `craft\db\Table` spells it: `entries`, `elements_sites`, `commerce_products`. Required |
| `columns` | list of strings | Column names in index order, as the run recommended them. Required unless `expression` is given; never both |
| `expression` | string | One indexable expression, exactly as MySQL prints it in a plan — Craft's field cast `CAST((content->>'$."<uid>"') AS CHAR(255))` is the common one. MySQL and PostgreSQL only |
| anything else | any | Ignored by the plugin, kept for the reader: `measured`, `source`, `finding`, `date`, a `name` the plugin will not honour |

**Why this shape.** Arrays, not strings, so the craft-perf subcommand can add and update entries and merge with what a developer wrote, and so the figures that justified an entry can sit on the entry rather than in a comment that a re-dump loses. Table and columns are what `Db::findIndex` matches on and what a plan names, so an entry is written straight from a report with nothing translated. The index's **name is derived, never declared**: the prefix is how ownership is known, and a declared name would let a site claim an index it does not own.

**Derived, not stored:** an entry's index name, from its table and columns (or a hash of its expression), under the `qb_` prefix and within the database's identifier limit (BR-5); and the set of indexes to drop, from the database's owned indexes less the declared and built-in names (BR-9).

**On deletion.** The file or the key removed is every entry struck: the next apply drops every owned index that is not a built-in (BR-9). The plugin uninstalled drops every owned index, built-ins included (BR-12). A table dropped by a Craft or plugin migration takes its indexes with it; an entry naming a table that no longer exists is refused and reported (BR-7).

**States** — of one declared entry

| From | Event | To | Side effects |
|---|---|---|---|
| declared, not served | apply | present (owned) | `CREATE INDEX` under the derived name; one output line |
| declared, served | apply | served | Nothing created; the serving index named in the output |
| declared | apply, database refuses it | refused | Reported with the reason; the other entries are still applied; the entry is retried on the next apply |
| present (owned) | struck, then apply | dropped | `DROP INDEX`; one output line |
| present (owned) | plugin uninstalled | dropped | With every other owned index |
| served | struck | served | Nothing: the serving index was never ours |

---

## 5. Rules

| # | Rule |
|---|---|
| BR-1 | **The list is read only from the settings file**, through Craft's plugin-settings merge — or, at install, the plugin's own read of the same file with the same environment handling (BR-12) — so environment keys apply. There is no other source: not project config, not the control panel, not a run's output |
| BR-2 | **A valid entry is an array with `table` and exactly one of `columns` (a non-empty list of strings) or `expression` (a non-empty string).** Any other key on the entry is ignored. A string entry, a missing table, both or neither of columns and expression, or an empty list is refused (BR-7) and never partly applied |
| BR-3 | **The built-ins are always declared**, ahead of the site's list: `entries (sectionId, postDate)` and `elements (canonicalId, dateCreated)`, under the names they have today. The list cannot remove them; only uninstall can |
| BR-4 | **A declared index is served when an index on the same columns in the same order already exists under any name**, non-unique, by Craft's own index lookup. Nothing is created for it and the output names the serving index. A declared expression is served only by an owned index of its derived name, because Craft's lookup cannot see expression indexes |
| BR-5 | **The index name is derived**: `qb_` + table + `_` + the columns lower-cased and joined with `_`, the built-ins' pattern (`qb_entries_sectionid_postdate`). An expression's name is `qb_` + table + `_x` + the first ten hex characters of the SHA-1 of the expression with its whitespace collapsed. A column name past 60 characters becomes `qb_` + table + `_h` + the first ten hex characters of the SHA-1 of the full definition, so no name reaches MySQL's 64-character or PostgreSQL's 63-character limit. The same entry always derives the same name |
| BR-6 | **Apply is idempotent and ordered:** validate every entry; read the owned indexes once; create each declared-and-not-served entry in list order; then drop each owned index that is neither a built-in nor derived from a declared entry. An entry that appears twice is created once and reported as served by the first. A second apply on an unchanged list changes nothing |
| BR-7 | **A refused entry never stops the others or the deploy.** Its line names the entry (table and definition) and the reason: the shape rule it broke, the table or a column not found, or the database's own message. A drop the database refuses is recorded the same way and counts as refused. The apply step's result records it; the console command exits non-zero when any entry was refused; the deploy hook prints and logs it and leaves the deploy's exit code alone |
| BR-8 | **Creation is plain `CREATE INDEX`**: non-unique, no `ALGORITHM` or `LOCK` clause, so MySQL's online default applies, on `{{%table}}` so the site's prefix is honoured |
| BR-9 | **Only owned indexes are ever dropped**, and only when no built-in and no declared entry derives their name. An index on the declared columns under another name is never touched, even when its entry is struck |
| BR-10 | **The deploy hook runs the apply step once, after Craft's `migrate/all` action completes successfully**, in console requests only, when the plugin is installed and enabled. A failed migration step means no apply. The hook prints one line per change or refusal, prefixed with the plugin's name, and a one-line summary; with nothing declared it prints that no site indexes are declared |
| BR-11 | **The console command `query-boost/apply` runs the same step by hand.** `--dry-run` reports every entry's state and every drop it would make, changes nothing, and exits 0 unless an entry is refused. Without the flag it applies and exits 0, or non-zero when any entry was refused |
| BR-12 | **Install applies the built-ins and the declared list together; uninstall drops every index whose name starts with `qb_`**, on every table, read from the database's own catalogue, not from the file (the file may have changed since). Install reads the settings file itself, because Craft merges a plugin's settings file into its settings only once the plugin is installed; without that read a reinstall, or a deploy that installs the plugin through project config, brings back the built-ins alone |
| BR-13 | **Nothing in this feature runs on a web request.** The hook is attached only when the request is a console request; the apply step is reachable only from the hook, the command and install |
| BR-14 | **The tiebreaker is rewritten only when the element table has an index that can serve the sort**: besides today's rule that every sort column is on the element table, some index on that table must list the sort's columns other than the tiebreaker, in the sort's order, as a contiguous run anywhere in the index. Column indexes only, read from the schema at most once per table per request. With no such index the query is left byte-identical to Craft's |
| BR-15 | **The README is the contract a site developer reads**: it documents the `indexes` key with one worked entry, the derived name, served and struck, the deploy hook, the dry run, uninstall, and credits the two built-ins to craftcms/cms pull request #19579 (8 Sep 2026). A behaviour this spec adds that the README does not state is a defect |
| BR-16 | **Every change to the rewriter or the index list is proved by identical results before its timing is reported**: `query-boost/compare` on craft5 and on at least one client site, inside a snapshot |

**Non-functional**

- The apply step reads the schema (one catalogue query for owned indexes, one index lookup per declared entry) and runs one DDL statement per change. It runs no element query and loads no element.
- Index creation takes the time the database takes on that table. On InnoDB a secondary index builds online by default, so reads continue; the README says to declare on a very large table in the same window the site would run any other migration.
- Output and log lines carry the plugin's name and the entry's table and definition, never the expression's content beyond that definition. Logging goes to Craft's log under the plugin's handle.
- No personal data is read, stored or sent.

---

## 6. Interfaces

| Method | Path | Purpose | Auth | Returns |
|---|---|---|---|---|
| console | `query-boost/apply [--dry-run]` | Apply the declared list by hand, or report what it would change | Shell access | One line per entry and per drop, a summary line; exit 0, or non-zero when an entry was refused |
| console | `query-boost/compare [--reps] [--limit]` | Existing: identical-result proof of the rewrites | Shell access | Unchanged by this spec; the `=` marker now also marks a sort BR-14 left alone |
| console | `migrate/all` (hooked), therefore `craft up` | The deploy applies the list after Craft's migrations | Shell access | Craft's own output, then the plugin's lines (BR-10) |
| console | `plugin/install query-boost`, `plugin/uninstall query-boost` | Built-ins plus list created; every owned index dropped | Shell access | Craft's own output |
| file | `config/query-boost.php` | The `indexes` key beside `tiebreaker` and `straightJoin` | Repository access | Read on every request through Craft's settings merge; acted on only by the above |

**Screens**

| Surface | New or reuse | Notes |
|---|---|---|
| None | — | Console output and a PHP file. No control-panel surface (§2) |

**Design source.** None: the console output follows the existing compare command's plain columns, and the file follows the existing settings file's shape.

**Copy ownership.** The console lines, the deploy hook's lines, the refusal reasons and the README are written by the developer building this and reviewed by Sam before release. There is no client copy.

---

## 7. Test plan

### Client-verified criteria

| # | Given / When / Then | Proved by |
|---|---|---|
| AC-1 | Given one valid entry declared on a site, when the site deploys (`craft up`), then the index exists under its derived `qb_` name, the deploy output says it was created, and the compare command reports every query identical | TS-1 |
| AC-2 | Given that index present, when the entry is struck and the site deploys, then the index is gone, the output says it was dropped, and an index on the same columns under another name is untouched | TS-2 |
| AC-3 | Given any list, when the apply command runs as a dry run, then every entry's state and every drop are listed, nothing in the database changes, and the exit code is 0 | TS-3 |
| AC-4 | Given an entry the database refuses among valid ones, when the site deploys, then the deploy exits 0, the refused entry is named with the database's reason, and the valid entries are applied; and the hand-run command on the same file exits non-zero | TS-4 |
| AC-5 | Given an entry whose columns an existing index already covers, when applied, then nothing is created and the output names the serving index | TS-5 |
| AC-6 | Given owned indexes present, when the plugin is uninstalled, then no `qb_` index remains; when reinstalled, the built-ins and the declared list are back | TS-6 |
| AC-7 | Given no settings file, or no `indexes` key, when the site deploys or the dry run runs, then only the two built-ins exist and the output says no site indexes are declared | TS-7 |
| AC-8 | Given a query sorted on an element-table column no index serves, when it runs with the rewrite on, then its SQL is byte-identical to Craft's (the compare command marks it `=`); and a sort the built-in index serves is still rewritten | TS-8 |
| AC-9 | Given an entry under a `production` environment key only, when applied on the dev environment, then it is neither created nor listed | TS-9 |
| AC-10 | Given the README, when read, then it documents the key, the deploy, the dry run, uninstall, and names pull request #19579 as the built-ins' source | TS-10 |
| AC-11 | Given the repository, when the harness runs, then coding standard, static analysis and both suites pass in the containers | TS-11 |

### Test data and preconditions

- **craft5** (`~/projects/craft5`, DDEV, Craft 5.11.5, MySQL 8.0) with the plugin installed from a copy under its `plugins/` path repository (`rsync` of the plugin's checkout, `composer require 'webdna/query-boost:*@dev'`), because the profile's bind mount needs a sandbox restart: exists. Snapshot before the scenarios, restore after, then remove the copy, the Composer lines and the `plugins.query-boost` project-config block, so the sandbox is as it was.
- **turf5** on `feature/performance` (`~/projects/turf5`): exists, already requires the plugin; `qb_entries_sectionid_postdate` is present. The 41,000-review section is the timing fixture. Snapshot and restore.
- **Heathfield** (`~/projects/heathfield`): exists; the Address query is BR-14's regression fixture; count carts before and after any front-end request. Snapshot and restore.
- **A declared entry fixture** for craft5: `entries (sectionId, postDate)` is served by the built-in; the scenarios need an unserved one — `elements_sites (siteId, enabled)`, which exists on every Craft 5 site and has no composite index. **Does not exist in craft5 as an index** — correct, that is the point.
- **A refused-entry fixture**: `entries (noSuchColumn)`. Needs nothing.
- **The compose harness** (`docker-compose.yml`, the test app under `tests/_craft/`): **does not exist** — task 0.1 makes it (Appendix A has no row for it; it is the first task, not a question).
- **A MariaDB or PostgreSQL copy**: does not exist. TN-6 and TN-11 are not run; Appendix A #3.

### Scenarios

**TS-1 - Declare and deploy** - *covers AC-1, BR-5, BR-8, BR-10*

| # | Step | Expected |
|---|---|---|
| 1 | On craft5, snapshot; write `config/query-boost.php` with `indexes` holding `elements_sites` / `['siteId', 'enabled']` | File present |
| 2 | `ddev exec php craft up` | Craft's migration output, then a plugin line "created `qb_elements_sites_siteid_enabled` on elements_sites", exit 0 |
| 3 | `ddev exec php craft query-boost/apply --dry-run` | The entry listed as present; built-ins present; nothing to create or drop; exit 0 |
| 4 | `SHOW INDEX FROM craft_elements_sites` (or the site's prefix) | A row for `qb_elements_sites_siteid_enabled` with `siteId`, `enabled` in that order |
| 5 | `ddev exec php craft query-boost/compare` | Last line "Every query returned the same elements in every mode.", exit 0 |

*Success criterion: steps 2, 4 and 5 all hold on one run.*

**TS-2 - Strike and deploy** - *covers AC-2, BR-4, BR-9*

| # | Step | Expected |
|---|---|---|
| 1 | After TS-1, by hand `CREATE INDEX dev_sites ON craft_elements_sites (siteId, enabled)` (MySQL allows a second index on the same columns under another name) | A developer-owned index on the same columns beside ours |
| 2 | Remove the entry from the file; `craft up` | A plugin line "dropped `qb_elements_sites_siteid_enabled`", exit 0 |
| 3 | `SHOW INDEX FROM craft_elements_sites` | No `qb_elements_sites_siteid_enabled`; `dev_sites` still present |
| 3a | Re-declare the entry; `query-boost/apply --dry-run` | "served by `dev_sites`"; nothing to create |
| 4 | Delete the file entirely; `craft up` | "no site indexes are declared"; the two built-ins still present |

*Success criterion: only the owned index went; the developer's and the built-ins stayed.*

**TS-3 - Dry run changes nothing** - *covers AC-3, BR-11*

| # | Step | Expected |
|---|---|---|
| 1 | File declares `elements_sites (siteId, enabled)` (not yet created) and `entries (sectionId, postDate)` | — |
| 2 | Record `SHOW INDEX` for both tables; `query-boost/apply --dry-run` | Line 1: "would create …"; line 2: "served by `qb_entries_sectionid_postdate`"; no drops; exit 0 |
| 3 | `SHOW INDEX` for both tables again | Identical to step 2's record |

*Success criterion: the report names both states and the schema is unchanged.*

**TS-4 - A refused entry on deploy and by hand** - *covers AC-4, BR-6, BR-7, BR-11*

| # | Step | Expected |
|---|---|---|
| 1 | File declares `entries (noSuchColumn)` first, then `elements_sites (siteId, enabled)` | — |
| 2 | `craft up` | Exit 0; a line naming `entries (noSuchColumn)` refused, "column not found: noSuchColumn" (the plan reads the table's columns itself, so step 4's dry run can refuse it too); a line creating the `elements_sites` index |
| 3 | `query-boost/apply` | The same refusal line, "nothing to create" for the now-present entry, exit non-zero |
| 4 | `query-boost/apply --dry-run` | The refusal line again, exit non-zero |
| 5 | Fix the column to `dateUpdated` (a column Craft does not index; `postDate` has Craft's own index, so an entry on it is served, BR-4); `craft up` | Created under its derived name `qb_entries_dateupdated`; exit 0; no refusal |

*Success criterion: the deploy's exit code is 0 in step 2 and the command's is non-zero in steps 3 and 4.*

**TS-5 - Served by an existing index** - *covers AC-5, BR-3, BR-4*

| # | Step | Expected |
|---|---|---|
| 1 | File declares `entries (sectionId, postDate)` | — |
| 2 | `query-boost/apply` | "served by `qb_entries_sectionid_postdate`"; nothing created; exit 0 |
| 3 | Drop the built-in by hand; `query-boost/apply` | The built-in is recreated (BR-3); the site entry then reported as served by it; exactly one index on those columns |

*Success criterion: at no point are there two indexes on `(sectionId, postDate)`.*

**TS-6 - Uninstall and reinstall** - *covers AC-6, BR-12*

| # | Step | Expected |
|---|---|---|
| 1 | With TS-1's index present, `plugin/uninstall query-boost` | `SELECT … FROM information_schema.STATISTICS WHERE INDEX_NAME LIKE 'qb\_%'` returns no rows |
| 2 | `plugin/install query-boost`, the entry still in the file | The two built-ins and `qb_elements_sites_siteid_enabled` present; install output shows the three (the plugin reads the file at install, BR-12 — Craft has not merged it yet) |

*Success criterion: step 1 returns zero rows and step 2 returns three names.*

**TS-7 - The empty state** - *covers AC-7, BR-2, BR-10, BR-13*

| # | Step | Expected |
|---|---|---|
| 1 | No `config/query-boost.php`; `craft up` | One plugin line: no site indexes declared; exit 0 |
| 2 | `query-boost/apply --dry-run` | The two built-ins listed as present; "no site indexes declared"; exit 0 |
| 3 | A file with only `'tiebreaker' => true`; repeat 1–2 | Identical |

*Success criterion: the output is identical with no file and with a file that lacks the key.*

**TS-8 - The index-aware guard** - *covers AC-8, BR-14*

| # | Step | Expected |
|---|---|---|
| 1 | On craft5, `query-boost/compare` | A section case "default order" shows a different time column for `tiebreak` with no `=`: rewritten, served by the built-in |
| 2 | A case sorted on an element-table column no index serves, with the tiebreaker named (a `User` query ordered by `['users.lastName' => SORT_ASC, 'elements.id' => SORT_ASC]`, added to the compare cases for this scenario, or a throwaway script printing its `getRawSql()`). Craft 5 appends no tiebreaker to an explicit `orderBy()`, only its default orders carry one, so a bare `orderBy('lastName')` is refused by the first guard and proves nothing about BR-14. `tests/integration/RewriteTest.php` holds this step in the harness | The `tiebreak` mode's SQL equals `off`'s: the `=` marker, or identical raw SQL |
| 3 | On Heathfield, snapshot, `query-boost/compare` | Every query SAME; the Address query's time unchanged from the evidence (about 11 ms), not 136 ms |

*Success criterion: step 2's SQL is identical and step 3 reports SAME on every row.*

**TS-9 - Environment keys** - *covers AC-9, BR-1*

| # | Step | Expected |
|---|---|---|
| 1 | File: `'*' => ['tiebreaker' => true]`, `'production' => ['indexes' => [elements_sites entry]]`; craft5's environment is `dev` | — |
| 2 | `query-boost/apply --dry-run` | "no site indexes declared"; nothing to create |
| 3 | `CRAFT_ENVIRONMENT=production` for the same command — set in `.env` for the run on a site whose `bootstrap.php` loads `.env` mutably (craft5's `createUnsafeMutable` overwrites a variable given on the command line) | The entry listed as "would create" |

*Success criterion: the same file yields no entry on dev and one on production.*

**TS-10 - The README** - *covers AC-10, BR-15*

| # | Step | Expected |
|---|---|---|
| 1 | `grep -c '19579' README.md` | At least 1 |
| 2 | Read the Configuration section | A worked `indexes` entry; the words served, struck (or their plain equivalents), dry run, uninstall, and `craft up` each present |
| 3 | `grep -c '18716' README.md` | Still present, but no longer credited with the two indexes |

*Success criterion: step 1 is non-zero and a reader can declare an index from the README alone.*

**TS-11 - The harness** - *covers AC-11, BR-16*

| # | Step | Expected |
|---|---|---|
| 1 | `docker compose up -d mysql && docker compose run --rm php composer install` | Installs |
| 2 | `docker compose run --rm php composer check` | ECS "No errors found"; PHPStan "[OK] No errors"; Codeception "OK (…)" for unit and integration |
| 3 | `PHP_VERSION=8.4 docker compose run --rm php composer check` | The same on the 8.4 leg |

*Success criterion: step 2 and step 3 both end clean.*

### Negative and edge cases

| # | Condition | Expected behaviour |
|---|---|---|
| TN-1 | A string entry (`'entries (sectionId, postDate)'`) | Refused: "an entry is an array with table and columns or expression"; the rest applied (BR-2, BR-7) |
| TN-2 | An entry with both `columns` and `expression`, or neither, or `columns` empty | Refused with the shape reason; nothing partly created |
| TN-3 | A table that does not exist (`entrees`) | Refused: table not found; no DDL attempted |
| TN-4 | A column that does not exist | Refused by the plan as "column not found: <column>" before any statement, so the dry run refuses it too (TS-4 step 4). A statement only the database can refuse (a TEXT column with no key length, a duplicate name) is refused with the database's own message on one line; it failed, nothing to undo |
| TN-5 | The same entry declared twice | Created once; the second reported as served by the first (BR-6) |
| TN-6 | An expression entry on MariaDB | Refused with the server's message (MariaDB has no expression indexes). **Not run**: no MariaDB copy (Appendix A #3); the refusal path is TN-4's |
| TN-7 | A column name that makes the derived name exceed 60 characters | The hashed name form (BR-5); the index created; a second apply finds it present |
| TN-8 | A `qb_` index a developer created by hand that no entry derives | Dropped on the next apply, as documented in the README: the prefix is the plugin's |
| TN-9 | `migrate/all` fails before the hook | No apply; no plugin line (BR-10) |
| TN-10 | A web request, with changes pending | Nothing happens; no DDL on any web request (BR-13) |
| TN-11 | PostgreSQL | **Not run** (Appendix A #3). The column-index path uses Craft's cross-driver helpers; the owned-index catalogue read has a driver branch that is written but unproved |
| TN-12 | Two deploys applying the same new entry at once | The second `CREATE INDEX` fails on the duplicate name; reported as refused on that deploy; the next apply finds it present |
| TN-13 | The built-in's columns already indexed under Craft 6's own name (a site upgraded then rolled back) | Served; nothing created; uninstall drops nothing of Craft's (BR-4, BR-12) |
| TN-14 | An entry whose index exists under its derived name but with different columns (edited by hand) | Reported as present by name; the plugin never alters an existing index. The README says strike it, deploy, re-declare |
| TN-15 | The deploy hook's plugin instance is installed but disabled | No apply, no line (BR-10) |

### Automated checks

- **Unit (`tests/unit/`)**: entry validation and refusal reasons (BR-2); name derivation including the hash forms and the limit (BR-5); the plan given a declared list and a recorded set of existing indexes — create, served, drop, duplicate (BR-4, BR-6, BR-9); the guard's index matching over recorded index lists (BR-14); the rewriter on a bare `craft\db\Query` (existing behaviour, first test in the harness).
- **Integration (`tests/integration/`, Craft booted on the compose MySQL)**: apply creates, is idempotent, drops on strike, refuses an unknown column and continues (TS-1–TS-5 in miniature); install and uninstall (TS-6); the deploy hook fires after `migrate/all` and not on a failed one (TN-9); environment keys through a settings model set in the test (TS-9).
- **Manual (real sites, in snapshots)**: TS-1 step 5, TS-8 steps 1–3 and the timing columns on turf5 and Heathfield. These need real data and are the proof BR-16 demands.
- **Test hooks the build must add**: the apply step returns a result object (each entry with a state constant — `present`, `create`, `served`, `refused`, plus the drops and a refused count) that the command and the hook render; tests assert on the object, never on console text. The state constants and the refusal reasons are public constants. The hook's condition (action id, result, console request, plugin enabled) is a method the integration test can call with a constructed event.

### Regression checks

- **The compare command on craft5, turf5 and Heathfield reports SAME on every row** — BR-14 changes which queries are rewritten; a wrong match rewrites a sort it should not, and a wrong refusal silently gives back the 65 ms turf5 reviews query.
- **Heathfield's Address query stays at its evidence time** — the first guard exists because of it; BR-14 sits on top and must not reopen it.
- **turf5's reviews listing stays at about 1.6 ms** — the built-in index serves it; BR-14 must still rewrite it.
- **Install on a database that already has the built-ins' columns indexed creates nothing** — `Install.php`'s existing behaviour is reused by BR-3 and BR-4, not replaced.
- **`straightJoin` behaves as before** — untouched by this spec, but it shares the rewriter.

---

## 8. Build order

| Phase | What it delivers | Depends on |
|---|---|---|
| 0 | The harness: containers, suites, static analysis, standard; the rewriter's first unit test | - |
| 1 | Declarations: the settings key, validation, derived names | 0 |
| 2 | The apply step: schema reading, the plan, DDL, the result object; the console command with its dry run | 1 |
| 3 | The deploy hook; install and uninstall | 2 |
| 4 | The index-aware guard | 0 |
| 5 | README, changelog, release readiness; the real-site proofs | 2, 3, 4 |

Phase 4 depends only on the harness and can be built beside 1–3; it is placed after them so the real-site compare runs once, over everything. The craft-perf subcommand (its own spec) can start once phase 1 fixes the entry shape.

### Tasks

- [x] **0.1 The harness** - `docker-compose.yml`, `docker/php/Dockerfile`, `codeception.yml`, `tests/_bootstrap.php`, `tests/_craft/config/{db,general,test}.php`, `tests/unit.suite.yml`, `tests/integration.suite.yml`, `phpstan.neon`, `ecs.php`, `composer.json` (require-dev, scripts, autoload-dev), `.gitignore`; `tests/unit/QueryRewriterTest.php` covering today's tiebreaker and straight-join behaviour on a bare `craft\db\Query`. Add the craft5 mount in the handover.
      Rules: BR-16 - Verify: B5 #1, TS-11
- [x] **1.1 The settings key and the declaration** - `src/models/Settings.php` (`indexes`), `src/indexes/Declaration.php` (validation, refusal reasons as constants, derived name), `tests/unit/DeclarationTest.php`
      Rules: BR-1, BR-2, BR-3, BR-5 - Verify: B5 #1, TN-1, TN-2, TN-7
- [x] **2.1 The schema reader and the plan** - `src/indexes/Schema.php` (owned indexes from `information_schema.STATISTICS` on MySQL and `pg_indexes` on PostgreSQL; served lookup through `Db::findIndex`; the table-exists check), `src/indexes/Plan.php` (the result object and its state constants), `src/indexes/Sync.php::plan()`, `tests/unit/PlanTest.php`
      Rules: BR-4, BR-6, BR-9 - Verify: B5 #1, TN-5, TN-13
- [x] **2.2 Apply and the command** - `src/indexes/Sync.php::apply()` (create then drop, each refusal caught and recorded), `src/console/controllers/ApplyController.php` (`--dry-run`, output lines, exit codes), `tests/integration/ApplyTest.php`
      Rules: BR-6, BR-7, BR-8, BR-11, BR-13 - Verify: B5 #1, B5 #4, TS-1, TS-2, TS-3, TS-4, TS-5, TN-3, TN-4, TN-12
- [x] **3.1 The deploy hook, install and uninstall** - `src/QueryBoost.php` (the after-action listener on `craft\console\controllers\MigrateController`, console-only; `afterInstall()` applying the list), `src/migrations/Install.php` (`safeUp` through the same apply step for the built-ins; `safeDown` dropping every `qb_` index from the catalogue), `tests/integration/HookTest.php`, `tests/integration/InstallTest.php`
      Rules: BR-10, BR-12, BR-13 - Verify: B5 #1, TS-6, TS-7, TN-9, TN-10, TN-15
- [x] **4.1 The index-aware guard** - `src/QueryRewriter.php` (the guard takes the table's index list as an argument, so it stays testable without a database), `src/QueryBoost.php` (reads the list once per table per request and passes it), `src/indexes/Schema.php` (`columnIndexes()`, the one read), `tests/unit/GuardTest.php`, `tests/integration/SchemaTest.php`
      Rules: BR-14 - Verify: B5 #1, TS-8 step 2
- [x] **5.1 README and changelog** - `README.md` (the `indexes` section with a worked entry; the deploy, dry run, uninstall and Craft 6 paragraphs; "What it does" item 1 credited to pull request #19579 with #18716 kept for the query shape), `CHANGELOG.md` under Unreleased
      Rules: BR-15 - Verify: B5 #5, TS-10
- [x] **5.2 Real-site proof and release readiness** - no code was planned; the runs added the install-time settings-file read (`src/QueryBoost.php::afterInstall()`, `tests/integration/InstallTest.php`, BR-12): `query-boost/compare` and `apply --dry-run` on craft5, turf5 and Heathfield inside snapshots, the timing columns recorded in the changelog's entry; `composer validate --strict`; the spec's §7 ticked; the handover lists what Sam creates (Appendix A #4)
      Rules: BR-16 - Verify: B5 #2, B5 #3, B5 #4, TS-1, TS-8

**Task right-sizing.** 2.1 and 2.2 split where a reviewer could accept the plan and reject the DDL. 3.1 is one task because install, uninstall and the hook are three callers of one step and are proved by one integration suite.

---

# Appendix A - Open questions

| # | Question | Owner | State |
|---|---|---|---|
| 1 | Does `elements_sites (siteId, enabled)` hold on Heathfield and Roper Rhodes as it did on turf5 (590,322 rows read to 12,867, 87 ms to 14 ms, identical results)? If so it becomes a third built-in in a later release and an upstream pull request with the figures. A `/craft-perf db` run on each, read for that index's trial, answers it | Sam | Open - not blocking; decides a Later item |
| 2 | Can a plugin switch Craft 5 to automatic lazy eager loading (craftcms/cms #19637's `autoEagerLoadElements`)? Needs a hook early enough in a query's life, checked in Craft 5's `ElementQuery`, not assumed | Sam | Open - not blocking; decides a Later item |
| 3 | PostgreSQL and MariaDB are unproved for the index list: the README claims PostgreSQL for the plugin, and this spec's owned-index catalogue read has a PostgreSQL branch nobody has run (TN-11), while MariaDB refuses expression entries (TN-6). Add a PostgreSQL compose leg before release, or state in the README that the index list is proved on MySQL 8 only? | Sam | Open - blocking release |
| 4 | The GitHub repository `webdna/craft-query-boost` and its Packagist listing do not exist (decided 28 Oct: free, MIT, there). Until they do, sites install from a path repository and turf5's `^1.0@dev` requirement cannot move | Sam | Open - blocking release |

**Assumptions.**

- Every host that deploys a site runs `craft up` or `migrate/all` (Servd does). A host that runs neither applies the list only by calling `query-boost/apply`, which the README says in its deploy paragraph.
- Any index named `qb_…` belongs to the plugin, whoever made it (TN-8). The README says so; a developer who wants an index of their own names it otherwise.
- The craft-perf subcommand will write entries in §4's shape and treat any marker block it needs as PHP comments the plugin never sees. §4 is the contract; the other spec conforms to it.
- Craft 5's MySQL floor (8.0.17) is above the version that introduced expression indexes (8.0.13), so on MySQL an expression entry is never refused for the server's version. The floor is Craft's documented one, not re-read from source today.
- The harness mirrors auditor's files where it can (the Dockerfile, the compose pair, `codeception.yml`'s shape, the ECS set), so the two plugins are maintained the same way.
- Sam tests the real-site scenarios on craft5, turf5 and Heathfield and signs off; there is no client tester.

---

# Appendix B - build scaffolding

## B1. Before writing anything

Read `_PROFILE.query-boost.md`. Everything in it applies - the environment, the verification commands, and the project's rules about committing and pushing. In particular: the host has no PHP until task 0.1 lands; client sites are touched only inside a `ddev snapshot`; never commit in craft5.

## B2. Context to load

1. `src/migrations/Install.php` - the built-ins, the `qb_` prefix and the create-only-if-unserved rule this spec generalises; `INDEXES` is BR-3's list
2. `src/QueryBoost.php` - where the after-prepare listener is attached; the deploy hook and the guard's per-request index read go beside it, console-gated
3. `src/QueryRewriter.php` - `sortsOnlyOn()` is today's guard (BR-14 adds to it, never replaces it); `findElementTableJoin()` gives the alias and prefixed table the guard needs
4. `src/models/Settings.php` - one public property per key; `indexes` joins `tiebreaker` and `straightJoin`
5. `src/console/controllers/CompareController.php` - the output style and exit-code convention the apply command follows; the `=` marker BR-14 makes meaningful
6. `~/Projects/lll/vendor/craftcms/cms/src/console/controllers/MigrateController.php:289-318, 467-503` - why the hook is an after-action on `all` and not a registered track
7. `~/Projects/lll/vendor/craftcms/cms/src/console/controllers/UpController.php:54-100` - `craft up` runs `migrate/all` first, so the hook runs before `project-config/apply`; nothing here depends on project config
8. `~/Projects/lll/vendor/craftcms/cms/src/helpers/Db.php:1837-1858` and `src/db/mysql/Schema.php:295-315` - the served lookup (BR-4) and why it cannot see expression indexes
9. `~/Projects/lll/vendor/craftcms/cms/src/services/Plugins.php:936` - the settings merge that gives environment keys for free (BR-1)
10. `~/Projects/plugins/auditor/docker-compose.yml`, `docker/php/Dockerfile`, `codeception.yml`, `tests/_bootstrap.php`, `tests/_craft/config/`, `tests/integration.suite.yml`, `phpstan.neon`, `ecs.php`, `composer.json` scripts - the harness task 0.1 copies, with the namespace and handle changed
11. `~/Projects/claudeskills/.claude/worktrees/craft-perf-spec/plugins/craftcms/skills/craft-perf/scripts/craft_perf/indexes.py:285-304, 390-417` - what a run records for a proved index (`table`, key parts as backticked columns or one bracketed expression, before and after); §4's entry is this with the backticks removed
12. `~/Projects/claudeskills/.claude/worktrees/craft-perf-spec/docs/specs/_scope/craft-perf-next.md` - the decisions this spec implements and the Later items it names

## B3. Guardrails

- **Do not register a migration track for the list** - `migrate/all` never runs it, so the list would apply only where a deploy was changed to name it, and the sites that most need it would silently never get it.
- **Do not use `Db::findIndex()` to decide whether an expression index exists** - Craft's MySQL reader skips any key with parentheses, so the plugin would create the same expression index on every deploy until the server refused the duplicate name.
- **Do not drop anything whose name does not start with `qb_`, and do not alter an existing index** - ownership is the prefix; an index under another name is Craft's or a developer's, and altering one is DDL on a live table nobody declared.
- **Do not let a refused entry change the deploy's exit code, and do not let one stop the next entry** - a typo would block a release, and a developer would learn to strike the file rather than fix it.
- **Do not attach the hook on web requests, and do not run DDL from `init()` directly** - a web request would create an index under a visitor's request; the hook is attached only for console requests and acts only in the after-action of `migrate/all`.
- **Do not replace `sortsOnlyOn()` with the index check** - the first guard is what fixed Heathfield's Address query (11 ms to 136 ms); BR-14 narrows further, it never widens.
- **Do not read the schema per query in the guard** - `SHOW CREATE TABLE` once per element query would cost more than the rewrite saves; once per table per request, in a static.
- **Do not touch client sites outside a `ddev snapshot`, and never `git checkout config/project` + `project-config/apply` on one** - index DDL has no undo but the snapshot, and the apply uninstalled Heathfield's payment gateway on 26 Oct.
- **Do not commit in craft5, and run `ddev` only from the lowercase `~/projects/<site>`** - other sessions' work is in craft5's tree, and a restart from `~/Projects` stops the project and refuses to start it.
- **Do not add a `version` to `composer.json` or push** - releases are Sam's tags.

## B4. Definition of done

- [x] Every AC in section 7 passes, TS-1 to TS-11 (TS-1 to TS-9 on craft5 28 Oct 2026; TS-1, TS-6, TS-7 and TS-8 step 3 on Heathfield the same day)
- [x] Every BR in section 5 is enforced, not merely intended, BR-1 to BR-16
- [x] The negative cases in section 7 behave as specified, TN-6 and TN-11 recorded as not run with Appendix A #3 open
- [x] The regression checks in section 7 pass on craft5, turf5 and Heathfield, in snapshots, with the timings written in the changelog entry
- [x] The test hooks in section 7 exist: the result object with its state constants, the public refusal reasons, the hook's condition as a callable method
- [x] B5 below runs clean
- [x] `README.md` and `CHANGELOG.md` updated in the same commits as the behaviour they describe
- [x] Section 8's checklist ticked, Appendix A #3 and #4 handed to Sam

## B5. Verification

```bash
# 1. Standard, static analysis, both suites (from the repository root; the host has no PHP)
docker compose up -d mysql && docker compose run --rm php composer check
# expect: ECS "No errors found", PHPStan "[OK] No errors", Codeception "OK (N tests, M assertions)" twice
# and the 8.4 leg:
PHP_VERSION=8.4 docker compose run --rm php composer check
# expect: the same

# 2. Composer metadata valid for Packagist
docker compose run --rm php composer validate --strict --no-plugins
# expect: "./composer.json is valid"

# 3. Identical results with the rewrites off and on (craft5, inside a snapshot)
cd ~/projects/craft5 && ddev snapshot --name qb-spec && ddev exec php craft query-boost/compare
# expect: last line "Every query returned the same elements in every mode.", exit 0

# 4. The list in step with the schema (craft5)
cd ~/projects/craft5 && ddev exec php craft query-boost/apply --dry-run; echo "exit $?"
# expect: every declared entry present or served, no create, no drop, "exit 0"

# 5. The README credits the right pull request and documents the key
grep -c '19579' ~/Projects/plugins/query-boost/README.md
# expect: 1 or more
grep -c "'indexes'" ~/Projects/plugins/query-boost/README.md
# expect: 1 or more
```

Manual checks, each against its criterion:

| Criterion | Check |
|---|---|
| AC-1, AC-2, AC-4, AC-7 | TS-1, TS-2, TS-4, TS-7 on craft5, output lines read, `SHOW INDEX` after each |
| AC-6 | TS-6 on craft5: the `information_schema` count is 0 after uninstall and 3 names after reinstall |
| AC-8 | TS-8 on craft5 (the SQL comparison) and Heathfield (every row SAME, Address at about 11 ms) |
| AC-9 | TS-9 on craft5 with `CRAFT_ENVIRONMENT` set on the command |
| AC-10 | TS-10 read through once by a developer who has not seen this spec |

## B6. Out of bounds

- Client sites' databases outside a snapshot, and `config/project/` on any client site.
- `src/QueryRewriter.php::sortsOnlyOn()` and the `straightJoin` setting: the guard is added, nothing there is changed.
- craft5's working tree: it is never committed; turf5's `feature/performance` branch beyond installing and reading.
- The craft-perf worktree: this spec's counterpart subcommand is written there, by its own spec; nothing here edits it.
- Any index not named `qb_…`, and any `UNIQUE` index at all.

---

## Change log

| Date | Version | Change | By |
|---|---|---|---|
| 2026-10-28 | 0.1 | First draft, from the 28 Oct scope note's Query Boost half and the interview the same day (deploy hook after `migrate/all`, uninstall drops every owned index, the guard and README fix in, the rest Later; compose harness; report-and-continue on a refused entry; GitHub and Packagist release) | Samuel Birch |
| 2026-10-28 | 0.2 | Task 2.2 built. The plan checks a column entry's columns against the table itself, so an unknown column is refused by the dry run too and the command exits non-zero on it (TS-4 step 4); the database's own message is kept for what only it can refuse (BR-7, TN-4). A drop the database refuses is recorded on the plan and counts as refused (BR-7). The lines are one renderer, `indexes\Report`, for the command and the deploy hook (3.1) | Samuel Birch |
| 2026-10-28 | 0.3 | Task 3.1 built. The deploy hook prints a line per change or refusal and the summary (`Report::changeLines()`), so an unchanged deploy is the one summary line (BR-10, TS-7); install prints every line, so a fresh site sees the built-ins and its list by name (TS-6). The hook's condition is `QueryBoost::shouldApplyAfter(ActionEvent)`; the install migration's `safeUp` applies the built-ins alone and the plugin's `afterInstall()` the list with them; `safeDown` drops every owned index from the catalogue, logging one the database refuses and carrying on (BR-12). TS-6 and TS-7 on craft5 wait for the sandbox mount (task 5.2) | Samuel Birch |
| 2026-10-28 | 0.4 | Task 4.1 built. `QueryRewriter::apply()` takes a fourth argument, a callable giving the prefixed element table's column indexes as `list<list<string>>`, asked only after `sortsOnlyOn()` has passed; the guard is `indexServes()`, a contiguous-run match of the sort's non-tiebreaker columns over those lists (BR-14). The read is `indexes\Schema::columnIndexes()` over Craft's `findIndexes()`, cached by the plugin in a static per table per request (B3). The rewriter's one caller is the plugin, so the compare command is unchanged. TS-8 steps 1–3 on craft5 and Heathfield wait for the sandbox mount (task 5.2) | Samuel Birch |
| 2026-10-28 | 0.5 | Task 5.2 run. TS-6 step 2 on craft5 found install applying the built-ins alone: Craft merges a plugin's settings file only once the plugin is installed, so `afterInstall()` now reads the file itself (BR-1, BR-12; `InstallTest` +1, failed first; B5 #1 clean on both legs). Fixtures corrected from the runs: TS-4 step 5 fixes the column to `dateUpdated`, because Craft indexes `postDate` itself and that entry is served (BR-4); TS-9 step 3 sets the environment in `.env` on a site whose bootstrap loads it mutably (craft5); craft5 installs from a copy under `plugins/`, not the mount. Proved 28 Oct: every compare row SAME on craft5 (59), turf5 (74) and Heathfield (149), TS-1 to TS-9 on craft5, TS-1, TS-6, TS-7 and TS-8 step 3 on Heathfield through `migrate/all` (the hooked action; `craft up` would apply Heathfield's drifted project config, B3), no cart created; timings in the changelog | Samuel Birch |
