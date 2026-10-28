# Query Boost Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## Unreleased
### Added
- Initial release: Craft 6's `entries (sectionId, postDate)` and `elements (canonicalId, dateCreated)` indexes ([craftcms/cms#19579](https://github.com/craftcms/cms/pull/19579)), the element-table sort tiebreaker ([craftcms/cms#18716](https://github.com/craftcms/cms/pull/18716)), the optional `STRAIGHT_JOIN` join order, and the `query-boost/compare` command.
- An `indexes` list in `config/query-boost.php`: each entry a table and either its columns or one expression, with any other key kept for the reader. An entry is created under a derived `qb_` name after every successful `migrate/all` (so on `craft up`) and on install, and dropped when struck from the list; an entry an existing index already serves, under any name, creates nothing; a refused entry — wrong shape, unknown table or column, or a definition the database rejects — is reported with its reason, retried on the next apply, and never fails the deploy. Only indexes named `qb_…` are ever dropped.
- `query-boost/apply [--dry-run]`: the same step by hand. The dry run reports every entry's state and every drop it would make and changes nothing; both forms exit non-zero when an entry was refused.
- The sort tiebreaker is rewritten only when an index on the element table can serve the sort; a query no index serves is left byte-identical to Craft's, and `query-boost/compare` marks it `=`.
- Uninstalling drops every index named `qb_…`, read from the database's catalogue: the built-ins and the site's declared indexes alike.
- A test harness: ECS, PHPStan and Codeception unit and integration suites in Docker, on PHP 8.2 and 8.4.

Measured on 28 October 2026 with `query-boost/compare`, inside database snapshots; every row on every site returned the same elements in every mode. Median times with the tiebreaker off, then on:
- turf5 (MySQL 8.4; 74 rows): the 41,000-entry reviews section, default order 60.4 ms to 1.3 ms, page 2 45.2 ms to 2.4 ms, live only 105.4 ms to 3.5 ms; its `count()` 41.1 ms to 41.0 ms, having no sort to serve.
- Heathfield (MySQL 8.0; 149 rows): the `Address` default order, sorted on `elements.dateCreated` with no element-table index, is left alone (`=`) at 6.2 ms to 5.7 ms, where an earlier rewrite without the index-aware guard had made it 136 ms; the articles section's default order 5.4 ms to 2.3 ms.
- craft5 (Craft 5.11.5, MySQL 8.0; 59 rows): every row under 2 ms either way.
