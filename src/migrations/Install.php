<?php

namespace webdna\queryboost\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use webdna\queryboost\indexes\Schema;
use webdna\queryboost\indexes\Sync;
use yii\db\Exception as DbException;

/**
 * Install creates the built-in indexes through the same apply step as the deploy hook and the
 * `query-boost/apply` command; uninstall drops every index the plugin owns (BR-3, BR-12).
 *
 * Ownership is the `qb_` prefix. A built-in is created only when no index on its columns exists
 * under any name, and uninstall reads the owned names from the database's own catalogue, so an
 * index Craft (or a Craft 6 upgrade) created itself is never touched, and a declared index whose
 * entry has since left the file still goes.
 */
class Install extends Migration
{
    /**
     * The two Craft 6 indexes, BR-3's list; `Declaration::builtIns()` is tied to it by a test.
     */
    public const INDEXES = [
        'qb_entries_sectionid_postdate' => [Table::ENTRIES, ['sectionId', 'postDate']],
        'qb_elements_canonicalid_datecreated' => [Table::ELEMENTS, ['canonicalId', 'dateCreated']],
    ];

    public function safeUp(): bool
    {
        // The built-ins alone: the plugin's install hook then applies the declared list with them.
        (new Sync($this->schema()))->apply([]);

        return true;
    }

    public function safeDown(): bool
    {
        $schema = $this->schema();
        foreach ($schema->ownedIndexes() as $index) {
            try {
                $schema->dropIndex($index);
            } catch (DbException $e) {
                Craft::warning("$index->name on $index->table was not dropped: {$e->getMessage()}", 'query-boost');
            }
        }

        return true;
    }

    private function schema(): Schema
    {
        return new Schema($this->db);
    }
}
