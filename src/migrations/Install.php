<?php

namespace webdna\queryboost\migrations;

use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Db;

/**
 * Adds the composite indexes Craft 6 ships in place of Craft 5's single-column ones.
 *
 * Each is created under a `qb_` name only if no index on those columns exists, and uninstalling
 * drops only those names, so an index Craft (or a Craft 6 upgrade) created itself is never touched.
 */
class Install extends Migration
{
    public const INDEXES = [
        'qb_entries_sectionid_postdate' => [Table::ENTRIES, ['sectionId', 'postDate']],
        'qb_elements_canonicalid_datecreated' => [Table::ELEMENTS, ['canonicalId', 'dateCreated']],
    ];

    public function safeUp(): bool
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (Db::findIndex($table, $columns, false, $this->db) === null) {
                $this->createIndex($name, $table, $columns);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (Db::findIndex($table, $columns, false, $this->db) === $name) {
                $this->dropIndex($name, $table);
            }
        }

        return true;
    }
}
