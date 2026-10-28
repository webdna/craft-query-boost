<?php

namespace webdna\queryboost;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\controllers\MigrateController;
use craft\elements\db\ElementQuery;
use webdna\queryboost\indexes\Report;
use webdna\queryboost\indexes\Schema;
use webdna\queryboost\indexes\Sync;
use webdna\queryboost\models\Settings;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\console\Controller as ConsoleController;
use yii\console\ExitCode;
use yii\log\Logger;

/**
 * Query Boost: Craft 6's element query speed-ups for Craft 5.
 *
 * @method Settings getSettings()
 */
class QueryBoost extends Plugin
{
    public string $schemaVersion = '1.0.0';

    /**
     * Each element table's column indexes, read once per table per request for BR-14's guard: a
     * `SHOW CREATE TABLE` per element query would cost more than the rewrite saves (B3).
     *
     * @var array<string, list<list<string>>>
     */
    private static array $columnIndexes = [];

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'webdna\\queryboost\\console\\controllers';

            // The deploy hook (BR-10, BR-13): attached on console requests only, and acting only
            // after `migrate/all` has succeeded, so `craft up` applies the list before anything
            // else and no web request ever runs DDL.
            Event::on(MigrateController::class, ConsoleController::EVENT_AFTER_ACTION, function(ActionEvent $event) {
                if ($this->shouldApplyAfter($event) && $event->sender instanceof ConsoleController) {
                    $this->applyIndexes($event->sender, changesOnly: true);
                }
            });
        }

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_PREPARE, function(Event $event) {
            /** @var ElementQuery<array-key,ElementInterface> $query */
            $query = $event->sender;
            if ($query->subQuery === null) {
                return;
            }

            $settings = $this->getSettings();
            QueryRewriter::apply(
                $query->subQuery,
                $settings->tiebreaker,
                $settings->straightJoin && Craft::$app->getDb()->getIsMysql(),
                fn(string $table): array => self::$columnIndexes[$table] ??= (new Schema())->columnIndexes($table),
            );
        });
    }

    /**
     * Whether a finished console action is the one the deploy hook applies the list after (BR-10):
     * `migrate/all`, exit 0, on a console request, with this plugin installed and enabled. A failed
     * migration step, another action, a web request or a disabled plugin means no apply.
     */
    public function shouldApplyAfter(ActionEvent $event): bool
    {
        $action = $event->action;

        return $action->controller instanceof MigrateController
            && $action->id === 'all'
            && $event->result === ExitCode::OK
            && Craft::$app->getRequest()->getIsConsoleRequest()
            && $this->isInstalled
            && Craft::$app->getPlugins()->isPluginEnabled($this->id);
    }

    /**
     * Install applies the declared list together with the built-ins the install migration has
     * just created (BR-12), every line printed when a console command is doing the installing.
     */
    protected function afterInstall(): void
    {
        $console = Craft::$app->controller;
        $this->applyIndexes($console instanceof ConsoleController ? $console : null, changesOnly: false);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * The apply step for the deploy hook and install: the settings list applied, each line logged
     * under `query-boost` (as a warning when anything was refused) and, when a console controller
     * is running, printed with the plugin's name in front. The hook prints the changes and
     * refusals with the summary; install prints every line, so a fresh site sees the built-ins and
     * its own list together.
     */
    private function applyIndexes(?ConsoleController $console, bool $changesOnly): void
    {
        $plan = (new Sync(new Schema()))->apply($this->getSettings()->indexes);
        $report = new Report($plan, applied: true);
        $level = $plan->refusedCount() > 0 ? Logger::LEVEL_WARNING : Logger::LEVEL_INFO;
        $name = $this->name ?? $this->handle;

        foreach ($changesOnly ? $report->changeLines() : $report->lines() as $line) {
            Craft::getLogger()->log($line, $level, 'query-boost');
            $console?->stdout("$name: $line\n");
        }
    }
}
