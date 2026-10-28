<?php

namespace webdna\queryboost\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Connection;
use craft\db\Table;
use craft\helpers\Db;
use webdna\queryboost\indexes\Schema;
use webdna\queryboost\migrations\Install;
use webdna\queryboost\QueryBoost;

/**
 * Install and uninstall (BR-12, TS-6 in miniature): the migration's `safeUp` creates the built-ins,
 * the plugin's install hook then applies the declared list with them, and `safeDown` drops every
 * `qb_` index the catalogue lists — and nothing under another name (BR-9, TN-13). DDL auto-commits,
 * so each test puts the built-ins back in its `finally`; PluginTest after this one relies on them.
 */
class InstallTest extends Unit
{
    private const SITE_ENTRY = ['table' => 'elements_sites', 'columns' => ['siteId', 'enabled']];
    private const SITE_NAME = 'qb_elements_sites_siteid_enabled';
    /** A `qb_` index no entry derives — a developer's, or a struck entry's (TN-8). */
    private const GHOST_INDEX = 'qb_elements_sites_aghost';
    private const DEVELOPER_INDEX = 'dev_sites';

    // Uninstall (BR-12)
    // -------------------------------------------------------------------------

    public function testUninstallDropsEveryOwnedIndexTheCatalogueLists(): void
    {
        try {
            $this->db()->createCommand()->createIndex(self::GHOST_INDEX, Table::ELEMENTS_SITES, ['siteId'])->execute();
            $this->assertCount(3, $this->owned());

            $this->assertTrue((new Install())->safeDown());

            $this->assertSame([], $this->owned());
            foreach (Install::INDEXES as [$table, $columns]) {
                $this->assertNull($this->indexOn($table, $columns));
            }
        } finally {
            $this->dropQuietly(self::GHOST_INDEX, Table::ELEMENTS_SITES);
            (new Install())->safeUp();
        }
    }

    /** BR-9, TN-13: an index under another name is Craft's or a developer's, never ours to drop. */
    public function testUninstallLeavesAnIndexItDoesNotOwn(): void
    {
        try {
            $this->db()->createCommand()->createIndex(self::DEVELOPER_INDEX, Table::ELEMENTS_SITES, ['siteId', 'enabled'])->execute();

            (new Install())->safeDown();

            $this->assertSame(self::DEVELOPER_INDEX, $this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));
        } finally {
            $this->dropQuietly(self::DEVELOPER_INDEX, Table::ELEMENTS_SITES);
            (new Install())->safeUp();
        }
    }

    // Install (BR-3, BR-12)
    // -------------------------------------------------------------------------

    public function testInstallCreatesTheBuiltIns(): void
    {
        try {
            (new Install())->safeDown();
            $this->assertSame([], $this->owned());

            $this->assertTrue((new Install())->safeUp());

            $this->assertEqualsCanonicalizing(array_keys(Install::INDEXES), array_keys($this->owned()));
        } finally {
            (new Install())->safeUp();
        }
    }

    /** TS-6 step 2: the built-ins and the site's entry together. */
    public function testInstallAppliesTheDeclaredListWithTheBuiltIns(): void
    {
        try {
            (new Install())->safeDown();
            $this->withSettings([self::SITE_ENTRY]);

            (new Install())->safeUp();
            $this->afterInstall();

            $this->assertEqualsCanonicalizing(
                [...array_keys(Install::INDEXES), self::SITE_NAME],
                array_keys($this->owned()),
            );
        } finally {
            $this->withSettings([]);
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
            (new Install())->safeUp();
        }
    }

    /**
     * TS-6 step 2 as a real site runs it: Craft merges a plugin's settings file into its settings only
     * once the plugin is installed (`Plugins::createPlugin()` reads the file only when it has stored
     * plugin info), so at install the model holds the defaults and the declared list is in the file
     * alone. The install hook reads the file itself, or a reinstall — and a deploy that installs the
     * plugin through project config — brings back the built-ins only (BR-12).
     */
    public function testInstallReadsTheDeclaredListFromTheSettingsFile(): void
    {
        $file = Craft::$app->getConfig()->getConfigFilePath(QueryBoost::getInstance()->handle);
        $this->assertFileDoesNotExist($file, 'the test app carries no settings file of its own');

        try {
            (new Install())->safeDown();
            $this->withSettings([]);
            file_put_contents($file, '<?php return ' . var_export(['indexes' => [self::SITE_ENTRY]], true) . ';');

            (new Install())->safeUp();
            $this->afterInstall();

            $this->assertEqualsCanonicalizing(
                [...array_keys(Install::INDEXES), self::SITE_NAME],
                array_keys($this->owned()),
            );
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            $this->withSettings([]);
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
            (new Install())->safeUp();
        }
    }

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The plugin's install hook, protected on Craft's base class and called by `Plugin::install()`
     * after the migration — invoked here the same way, on the installed instance.
     */
    private function afterInstall(): void
    {
        $plugin = QueryBoost::getInstance();
        (new \ReflectionMethod($plugin, 'afterInstall'))->invoke($plugin);
    }

    /**
     * @param array<array-key, mixed> $entries
     */
    private function withSettings(array $entries): void
    {
        QueryBoost::getInstance()->getSettings()->indexes = $entries;
    }

    /**
     * @return array<string, \webdna\queryboost\indexes\OwnedIndex>
     */
    private function owned(): array
    {
        return (new Schema($this->db()))->ownedIndexes();
    }

    /**
     * @param list<string> $columns
     */
    private function indexOn(string $table, array $columns): ?string
    {
        return Db::findIndex($table, $columns, false, $this->db());
    }

    private function dropQuietly(string $name, string $table): void
    {
        try {
            $this->db()->createCommand()->dropIndex($name, $table)->execute();
        } catch (\yii\db\Exception) {
            // Not there: the test never created it, or already dropped it.
        }
    }

    private function db(): Connection
    {
        return Craft::$app->getDb();
    }
}
