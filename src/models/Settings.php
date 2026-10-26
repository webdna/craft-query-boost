<?php

namespace webdna\queryboost\models;

use craft\base\Model;

/**
 * Query Boost settings, set in `config/query-boost.php`.
 */
class Settings extends Model
{
    /**
     * @var bool Whether an element query's `elements.id` sort tiebreaker is rewritten to the element
     * table's own `id` (e.g. `entries.id`), so a sort such as `postDate DESC, id DESC` can be read
     * from an index. The two columns are always equal, so the order cannot change.
     */
    public bool $tiebreaker = true;

    /**
     * @var bool Whether, on MySQL and MariaDB, an element query's subquery starts from its element
     * table and `STRAIGHT_JOIN`s `elements`, as Craft 6 does. Off by default: MySQL 8.4 already
     * picks that order, and forcing it can slow a deep offset. Turn it on where `query-boost/compare`
     * shows the optimizer reading `elements` first.
     */
    public bool $straightJoin = false;
}
