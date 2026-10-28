<?php

namespace webdna\queryboost\tests\integration;

use Codeception\Stub;
use Codeception\Test\Unit;
use Craft;
use craft\console\controllers\MigrateController;
use craft\console\Request;
use craft\db\Connection;
use craft\db\Table;
use craft\helpers\Db;
use craft\services\Plugins;
use webdna\queryboost\QueryBoost;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;
use yii\console\ExitCode;

/**
 * The deploy hook (BR-10, BR-13): the list is applied once, after `migrate/all` finishes with exit
 * 0, on a console request, by an installed and enabled plugin — and otherwise not. The condition is
 * a method called here with a constructed event (spec §7, test hooks: TN-9, TN-10, TN-15); the
 * end-to-end case runs Craft's real `migrate/all` action with nothing pending, which raises the real
 * after-action event, and finds the index afterwards.
 *
 * The harness boots a web Application whose request still answers "console" (the CLI SAPI), so
 * `migrate` cannot be resolved by route as `query-boost/apply` can; the controller is built here.
 */
class HookTest extends Unit
{
    private const SITE_ENTRY = ['table' => 'elements_sites', 'columns' => ['siteId', 'enabled']];
    private const SITE_NAME = 'qb_elements_sites_siteid_enabled';

    public function testMigrateAllAppliesTheDeclaredListWhenItSucceeds(): void
    {
        $printed = '';

        try {
            $this->markCraftMigrationsApplied();
            $this->withSettings([self::SITE_ENTRY]);
            $this->assertNull($this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));

            $exit = $this->migrateController($printed)->runAction('all');

            $this->assertSame(ExitCode::OK, $exit, "migrate/all printed:\n$printed");
            $this->assertSame(self::SITE_NAME, $this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));
            $this->assertStringContainsString(
                "Query Boost: created qb_elements_sites_siteid_enabled on elements_sites (siteId, enabled)\n",
                $printed,
            );
        } finally {
            $this->withSettings([]);
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    public function testTheListenerIsAttachedToTheMigrateControllersAfterAction(): void
    {
        $this->assertTrue(Event::hasHandlers(MigrateController::class, Controller::EVENT_AFTER_ACTION));
    }

    // The condition (BR-10)
    // -------------------------------------------------------------------------

    public function testTheConditionHoldsForASuccessfulMigrateAll(): void
    {
        $this->assertTrue($this->plugin()->shouldApplyAfter($this->event('all', ExitCode::OK)));
    }

    /** TN-9: a failed migration step means no apply. */
    public function testTheConditionFailsWhenMigrateAllFailed(): void
    {
        $this->assertFalse($this->plugin()->shouldApplyAfter($this->event('all', ExitCode::UNSPECIFIED_ERROR)));
    }

    public function testTheConditionFailsForAnyOtherMigrateAction(): void
    {
        $this->assertFalse($this->plugin()->shouldApplyAfter($this->event('up', ExitCode::OK)));
    }

    /** TN-15: installed but disabled. */
    public function testTheConditionFailsWhenThePluginIsDisabled(): void
    {
        $plugins = Craft::$app->getPlugins();

        try {
            Craft::$app->set('plugins', Stub::make(Plugins::class, ['isPluginEnabled' => false]));

            $this->assertFalse($this->plugin()->shouldApplyAfter($this->event('all', ExitCode::OK)));
        } finally {
            Craft::$app->set('plugins', $plugins);
        }
    }

    /** TN-10: nothing on a web request, even if the event reached the plugin. */
    public function testTheConditionFailsOffTheConsole(): void
    {
        $request = Craft::$app->getRequest();

        try {
            Craft::$app->set('request', Stub::make(Request::class, ['getIsConsoleRequest' => false]));

            $this->assertFalse($this->plugin()->shouldApplyAfter($this->event('all', ExitCode::OK)));
        } finally {
            Craft::$app->set('request', $request);
        }
    }

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Craft's own migrate controller, non-interactive and without a backup, its output collected
     * into `$printed` instead of the terminal.
     */
    private function migrateController(string &$printed): MigrateController
    {
        $controller = Stub::construct(MigrateController::class, ['migrate', Craft::$app], [
            'stdout' => function(string $string) use (&$printed): int {
                $printed .= $string;

                return strlen($string);
            },
        ]);
        $controller->interactive = false;
        $controller->noBackup = true;

        return $controller;
    }

    /**
     * The harness builds Craft's schema without recording its migration history, so `migrate/all`
     * would see every Craft migration as new and try to run it; a real site's history is complete.
     * Written once (the DDL the hook then runs commits it) and harmless to the other tests.
     */
    private function markCraftMigrationsApplied(): void
    {
        $migrator = Craft::$app->getMigrator();
        foreach ($migrator->getNewMigrations() as $name) {
            $migrator->addMigrationHistory($name);
        }
    }

    /**
     * The event Yii raises after a `migrate/<action>` action returns `$result`.
     */
    private function event(string $actionId, int $result): ActionEvent
    {
        $controller = new MigrateController('migrate', Craft::$app);
        $event = new ActionEvent($controller->createAction($actionId));
        $event->result = $result;

        return $event;
    }

    private function plugin(): QueryBoost
    {
        return QueryBoost::getInstance();
    }

    /**
     * @param array<array-key, mixed> $entries
     */
    private function withSettings(array $entries): void
    {
        $this->plugin()->getSettings()->indexes = $entries;
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
            // Not there: the hook never ran, or already dropped.
        }
    }

    private function db(): Connection
    {
        return Craft::$app->getDb();
    }
}
