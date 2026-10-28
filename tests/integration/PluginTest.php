<?php

namespace webdna\queryboost\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\helpers\Db;
use webdna\queryboost\migrations\Install;
use webdna\queryboost\QueryBoost;

/**
 * The harness itself: Craft boots on the compose MySQL, installs the plugin, and the install
 * migration leaves its built-in indexes behind. Everything the later integration suites assume.
 */
class PluginTest extends Unit
{
    public function testPluginIsInstalledAndBooted(): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('query-boost');

        $this->assertInstanceOf(QueryBoost::class, $plugin);
        $this->assertSame($plugin, QueryBoost::getInstance());
    }

    public function testHandleMatchesComposerMetadata(): void
    {
        $composer = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertSame('query-boost', $composer['extra']['handle']);
        $this->assertSame(QueryBoost::class, $composer['extra']['class']);
    }

    public function testInstallCreatedTheBuiltInIndexes(): void
    {
        foreach (Install::INDEXES as $name => [$table, $columns]) {
            $this->assertSame($name, Db::findIndex($table, $columns, false), "$table has no index on " . implode(', ', $columns));
        }
    }
}
