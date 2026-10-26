<?php

namespace webdna\queryboost\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\Console;
use Throwable;
use webdna\queryboost\QueryBoost;
use yii\console\ExitCode;

/**
 * Runs element queries with Query Boost's rewrites off and on, and reports whether they return the
 * same elements and how long each took.
 */
class CompareController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var int How many times each query runs per mode; the median is reported.
     */
    public int $reps = 5;

    /**
     * @var int The page size of each listing query.
     */
    public int $limit = 100;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['reps', 'limit']);
    }

    /**
     * Compares every section and element type with the rewrites off and on. Exits non-zero if any
     * query returns different elements.
     */
    public function actionIndex(): int
    {
        $settings = QueryBoost::getInstance()->getSettings();
        $original = [$settings->tiebreaker, $settings->straightJoin];

        $modes = ['off' => [false, false], 'tiebreak' => [true, false]];
        if (Craft::$app->getDb()->getIsMysql()) {
            $modes['+straight'] = [true, true];
        }

        $this->stdout(sprintf("%-52s %-5s", 'Query', ''));
        foreach (array_keys($modes) as $mode) {
            $this->stdout(sprintf(' %11s', $mode));
        }
        $this->stdout("\n");

        $diffs = 0;
        try {
            foreach ($this->cases() as $label => $make) {
                $results = [];
                $times = [];
                $sql = [];
                try {
                    foreach ($modes as $mode => [$settings->tiebreaker, $settings->straightJoin]) {
                        $sql[$mode] = $make()->createCommand()->getRawSql();
                        [$results[$mode], $times[$mode]] = $this->measure($make, str_ends_with($label, 'count()'));
                    }
                } catch (Throwable $e) {
                    $this->stdout(sprintf("%-52s %s\n", $this->truncate($label), 'ERROR: ' . $e->getMessage()), Console::FG_YELLOW);
                    continue;
                }

                $same = count(array_unique(array_map('serialize', $results))) === 1;
                $diffs += $same ? 0 : 1;
                $this->stdout(sprintf('%-52s ', $this->truncate($label)));
                $this->stdout(sprintf('%-5s', $same ? 'SAME' : 'DIFF'), $same ? Console::FG_GREEN : Console::FG_RED);
                foreach ($times as $mode => $ms) {
                    // `=` marks a mode whose SQL is identical to `off`, so its time difference is noise
                    $this->stdout(sprintf(' %8.1fms%s', $ms, $mode !== 'off' && $sql[$mode] === $sql['off'] ? '=' : ' '));
                }
                $this->stdout("\n");
            }
        } finally {
            [$settings->tiebreaker, $settings->straightJoin] = $original;
        }

        if ($diffs) {
            $this->stderr("$diffs queries returned different elements.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Every query returned the same elements in every mode.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * @return array<string,callable(): ElementQueryInterface>
     */
    private function cases(): array
    {
        $limit = $this->limit;
        $cases = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $handle = $section->handle;
            $cases["section $handle, default order"] = fn() => Entry::find()->section($handle)->status(null)->limit($limit);
            $cases["section $handle, page 2"] = fn() => Entry::find()->section($handle)->status(null)->offset($limit)->limit($limit);
            $cases["section $handle, live only"] = fn() => Entry::find()->section($handle)->limit($limit);
            $cases["section $handle, count()"] = fn() => Entry::find()->section($handle)->status(null);
        }

        foreach (Craft::$app->getElements()->getAllElementTypes() as $class) {
            $name = substr(strrchr('\\' . $class, '\\'), 1);
            $cases["$name, default order"] = fn() => $class::find()->status(null)->limit($limit);
            $cases["$name, page 2"] = fn() => $class::find()->status(null)->offset($limit)->limit($limit);
            $cases["$name, count()"] = fn() => $class::find()->status(null);
        }

        return $cases;
    }

    /**
     * @return array{mixed, float} The query's result and its median time in milliseconds
     */
    private function measure(callable $make, bool $count): array
    {
        $result = null;
        $times = [];
        for ($i = 0; $i < max(1, $this->reps); $i++) {
            $start = hrtime(true);
            $query = $make();
            $result = $count ? $query->count() : $query->ids();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);

        return [$result, $times[intdiv(count($times), 2)]];
    }

    private function truncate(string $label): string
    {
        return mb_strlen($label) > 52 ? mb_substr($label, 0, 51) . '…' : $label;
    }
}
