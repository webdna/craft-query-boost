<?php

namespace webdna\queryboost\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use webdna\queryboost\indexes\Plan;
use webdna\queryboost\indexes\Report;
use webdna\queryboost\indexes\Schema;
use webdna\queryboost\indexes\Sync;
use webdna\queryboost\QueryBoost;
use yii\console\ExitCode;

/**
 * `query-boost/apply`: the declared index list applied by hand, or with `--dry-run` reported and
 * left alone (BR-11). One line per entry and per drop, then a summary; exit 0, or non-zero when
 * anything was refused (BR-7).
 */
class ApplyController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var bool Report every entry's state and every drop that would be made, and change nothing.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun']);
    }

    /**
     * Applies the `indexes` list from the settings file: the built-ins, then each entry in order,
     * then the drops.
     */
    public function actionIndex(): int
    {
        $entries = QueryBoost::getInstance()->getSettings()->indexes;
        $sync = new Sync(new Schema());
        $plan = $this->dryRun ? $sync->plan($entries) : $sync->apply($entries);
        $report = new Report($plan, !$this->dryRun);

        foreach ($plan->entries as $entry) {
            $this->stdout($report->entryLine($entry) . "\n", ...$this->colour($entry->state));
        }
        foreach ($plan->drops as $index) {
            $this->stdout($report->dropLine($index) . "\n", ...$this->colour(Plan::CREATE));
        }
        foreach ($plan->refusedDrops as $name => $reason) {
            $this->stdout($report->refusedDropLine($name, $reason) . "\n", ...$this->colour(Plan::REFUSED));
        }

        $refused = $plan->refusedCount() > 0;
        $this->stdout($report->summary() . "\n", $refused ? Console::FG_RED : Console::FG_GREEN);

        return $refused ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * A change in green, a refusal in red, the rest plain.
     *
     * @return list<int>
     */
    private function colour(string $state): array
    {
        return match ($state) {
            Plan::CREATE => [$this->dryRun ? Console::FG_CYAN : Console::FG_GREEN],
            Plan::REFUSED => [Console::FG_RED],
            default => [],
        };
    }
}
