# Project profile — Query Boost (Craft CMS 5 plugin)

**This file is referenced from the front matter of every spec in `docs/specs/`.** It carries the
project-specific half of a spec's build contract, so individual specs can stay about their feature.

> **Agents: read this whole file before writing code.** On 28 Oct 2026 the repo is one commit
> (`bc2970b`, 26 Oct, `main`, no remote) of five source files, a README and a changelog, with **no tests,
> no static analysis, no CI and no docs folder** until this spec's first task. Every Craft entry in Traps
> was read in Craft 5.11.5.1's source (`~/Projects/lll/vendor/craftcms/cms/src`) on 28 Oct 2026; every
> client-site entry comes from the 26 Oct test installs on Heathfield, Roper Rhodes and turf5.

---

## 1. Stack

| Layer | Choice |
|---|---|
| Package | `webdna/query-boost`, type `craft-plugin`, handle **`query-boost`** (keep — it names the config file and the project-config key on every site) |
| Namespace | `webdna\queryboost\` → `src/` |
| Plugin class | `src/QueryBoost.php`, `schemaVersion` `1.0.0`; no CP settings, no CP section |
| CMS | Craft CMS `^5.0`; Craft 6 does this itself and the README says uninstall before upgrading |
| Language | PHP `^8.2` |
| Databases | MySQL 8 (the three evidence sites), MariaDB (accepted by the `straightJoin` guard, otherwise untested), PostgreSQL (claimed by the README, untested) |
| Tests | Codeception 5 with Craft's `craft\test\Craft` module, in docker compose (added by the spec's first task, the shape of `~/Projects/plugins/auditor`) |
| Static analysis | PHPStan 2 via `craftcms/phpstan`, level 6, no baseline (same task) |
| Coding standard | ECS via `craftcms/ecs` (same task) |
| Licence | MIT. Release channel decided 28 Oct 2026: GitHub `webdna/craft-query-boost` plus Packagist, free — the addresses `composer.json` already names |
| Settings | `config/query-boost.php` only, read by Craft's plugin settings merge; there is no stored settings UI |

## 2. Commands

**The host has no PHP** (`php not found`). Until the harness task lands, nothing runs on the host.
After it, every PHP command runs in the containers `docker-compose.yml` defines (PHP CLI 8.2 or 8.4
plus MySQL 8.0 on tmpfs), exactly as auditor does:

```bash
docker compose up -d mysql
docker compose run --rm php composer install
docker compose run --rm php composer check        # ecs + phpstan + codeception
docker compose run --rm php composer analyse      # phpstan
docker compose run --rm php composer test:unit
docker compose run --rm php composer test:integration
```

**Real-app testing uses the craft5 sandbox**: DDEV at `~/projects/craft5` (lowercase `projects`), Craft
5.11.x. Mount this repo by adding
`${HOME}/Projects/plugins/query-boost:/var/www/html/plugins/query-boost:cached` to
`.ddev/docker-compose.store-plugins.yaml`, `ddev restart`, `ddev composer require 'webdna/query-boost:*@dev'`,
`ddev exec php craft plugin/install query-boost`. The plugin's own proofs are its console commands:

```bash
ddev exec php craft query-boost/compare [--reps=5] [--limit=100]   # identical results, rewrites off and on
ddev exec php craft query-boost/apply --dry-run                     # what the declared list would change
```

**Client sites** are the real evidence: turf5 (`~/projects/turf5`, branch `feature/performance`, already
requires `webdna/query-boost ^1.0@dev` through its `plugins/*` path repository, commit `874b4001`),
Heathfield and Roper Rhodes (`~/projects/heathfield`, `~/projects/roperrhodes`). See Traps before
touching any of them.

## 3. Where things go

| What | Where |
|---|---|
| Plugin class, event wiring | `src/QueryBoost.php` |
| The query rewrite | `src/QueryRewriter.php` — pure functions over a prepared `craft\db\Query`, no Craft app access |
| Settings model | `src/models/Settings.php` — one public property per config key |
| Index work (declaration, naming, sync) | `src/indexes/` (new) |
| Console commands | `src/console/controllers/<Name>Controller.php`, one per command |
| Migrations | `src/migrations/Install.php` only — the plugin has no upgrade migrations yet; a schema change adds a dated one and bumps `schemaVersion` |
| Tests | `tests/unit/`, `tests/integration/`, `tests/_craft/` (Craft's test app config), `tests/Support/` |
| Specs | `docs/specs/YYYY-MM-DD-<slug>.md` — **the spec is the plan; there is no `docs/plans/`** |
| User documentation | `README.md` — the only documentation a site developer reads; a config key that is not in it does not exist |
| Changelog | `CHANGELOG.md`, under `## Unreleased` until Sam tags |

Placement rule: anything that reads the database schema or runs DDL lives under `src/indexes/`; the
rewriter stays free of both so it can be unit-tested with a bare `Query`.

## 4. Verification

```bash
# 1. Everything (after the harness task)
docker compose run --rm php composer check
# expect: ECS "No errors found", PHPStan "[OK] No errors", Codeception "OK (N tests, M assertions)" for each suite

# 2. Composer metadata is valid for Packagist
docker compose run --rm php composer validate --strict --no-plugins
# expect: "./composer.json is valid"

# 3. The rewrites change no result on a real site (craft5, inside a snapshot)
cd ~/projects/craft5 && ddev exec php craft query-boost/compare
# expect: last line "Every query returned the same elements in every mode." and exit 0

# 4. The declared list is in step with the schema (craft5)
cd ~/projects/craft5 && ddev exec php craft query-boost/apply --dry-run
# expect: every declared entry reported as present or already served, nothing to create or drop, exit 0
```

## 5. Traps

### The rewrite

- **The tiebreaker rewrite is narrow on purpose.** The first version rewrote every sort, and Heathfield's
  Address query (`elements.dateCreated, elements.id`) went from 11 ms to 136 ms: moving the tiebreaker
  off `elements` split an index-served sort across two tables. `QueryRewriter::sortsOnlyOn()` is the
  guard; a change to it is proved with `query-boost/compare` on Heathfield, not by reasoning.
- **`straightJoin` reorders inside Craft 5's subquery and did nothing on MySQL 8.4.** Craft 6's gain
  is the subquery removed and `STRAIGHT_JOIN` onto `elements` (PR #18716: 250 ms with the subquery,
  570 ms without it and no straight join, 5 ms with it, on 500k entries). Do not expect the setting to
  pay until that reshape is built; the README says measure first.
- **The two shipped indexes come from craftcms/cms PR #19579 (8 Sep 2026), not #18716**, and Craft 6
  has no upgrade migration for them yet. Craft 6 adds no other index MySQL lacks: `elements_owners
  (ownerId)` is implicit through its foreign key; `elements_sites`, `structureelements`, `relations` and
  `searchindex` are unchanged.

### Craft 5 and indexes (5.11.5.1, read 28 Oct 2026)

- **`migrate/all` runs only the Craft track, each installed plugin's own track and the content
  track** (`console/controllers/MigrateController.php:289-318`). A track registered through
  `MigrateController::EVENT_REGISTER_MIGRATOR` is resolved only when named on `migrate/up --track=`
  (`:467-503`). `craft up` runs `migrate/all` with `noContent`, then `project-config/apply`, then the
  content track (`UpController.php:54-100`). Anything that must happen on every deploy without a
  per-site change hooks one of those, or lives in the plugin's own track.
- **Craft's MySQL `findIndexes()` cannot see an expression index.** It parses `SHOW CREATE TABLE`
  with `KEY name (cols)` where the column group excludes parentheses (`db/mysql/Schema.php:302`), so an
  index on `(CAST(... AS CHAR(255)))` is skipped, and `Db::findIndex()` (`helpers/Db.php:1837`) built on
  it returns null for one that exists. Read `information_schema.STATISTICS` (`EXPRESSION` is set where
  `COLUMN_NAME` is null) for anything that must know expression indexes.
- **`Db::findIndex()` matches by exact ordered column list and uniqueness, under any name.** That is
  what lets `Install.php` create nothing when Craft 6's own index, or a developer's, already covers the
  columns — and it means an index on the same columns in another order is not a match.
- **Plugin settings are the stored settings with `config/query-boost.php` merged over them**
  (`services/Plugins.php:936`, `Config::getConfigFromFile`), set on the model with `setAttributes(…,
  false)`. Environment keys (`'*'`, `'production'`) work as in any Craft config file. A key missing from
  the Settings model is silently dropped.
- **Identifier limits:** MySQL 64 characters, PostgreSQL 63. A generated index name past that is
  refused by the server, not truncated.
- **MariaDB has no functional (expression) indexes**; MySQL has them from 8.0.13, below Craft 5's
  floor, so on MySQL they are always available. PostgreSQL has expression indexes with the same
  `((expr))` syntax but the plugin has never been run on it.
- **`craft up` runs plugin migrations in Craft's plugin order, not the database's handle order**
  (verified 2 Oct 2026 on a Commerce 4 fixture). A hook that runs after `migrate/all` sidesteps the
  question; a migration that needs another plugin's schema does not.

### Testing on a client project

- **Take a `ddev snapshot` first and finish with `ddev snapshot restore`.** Index creation is DDL;
  nothing else undoes it.
- **Never `git checkout config/project` + `project-config/apply` on a client site.** Client databases
  drift from their YAML; on 26 Oct that apply uninstalled Heathfield's commerce-opayo (restored from
  the snapshot).
- **Composer removal of a path package leaves a dangling `vendor/webdna/<pkg>` symlink and a stale
  `vendor/craftcms/plugins.php` entry.** Check both after uninstalling from a client site.
- **A front-end crawl creates a Commerce cart per request** on Heathfield and turf5. Count carts before
  and after.
- **Run `ddev` from the lowercase `~/projects/<site>`** for Heathfield, turf5 and craft5. From
  `~/Projects` a restart stops the project and then refuses to start it, and a restore refuses on case.
- **Never commit in craft5**; it carries other sessions' uncommitted work. Say what changed in the
  handover.

## 6. Working conventions

- **Commit, never push.** Sam pushes and tags; the GitHub repository and the Packagist listing are
  Sam's to create. Releases are tags; `composer.json` carries no `version`.
- One spec task per session: when a §8 task is committed, verified and ticked, update the
  build-progress memory and end with a copy-paste prompt for a fresh session.
- `main` is the only branch. CHANGELOG entries go under `## Unreleased` until Sam tags.
- The plugin changes **how** the database finds rows, never **which** rows: every change to the
  rewriter or the index list is proved by identical results (`query-boost/compare`) before its timing
  is reported.
