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
