<?php

namespace webdna\queryboost\tests\integration;

use craft\db\Connection;
use craft\db\Table;
use craft\helpers\Db;
use craft\test\console\ConsoleTest;
use webdna\queryboost\indexes\Declaration;
use webdna\queryboost\indexes\OwnedIndex;
use webdna\queryboost\indexes\Plan;
use webdna\queryboost\indexes\PlanEntry;
use webdna\queryboost\indexes\Schema;
use webdna\queryboost\indexes\Sync;
use webdna\queryboost\QueryBoost;
use yii\console\ExitCode;

/**
 * The apply step against the compose MySQL — TS-1 to TS-5 in miniature (spec §7, integration): it
 * creates, is idempotent, drops on strike, refuses and carries on — and the command that runs it by
 * hand (BR-11). DDL auto-commits, so the test transaction undoes none of it: every index a test
 * creates is dropped in its `finally`.
 */
class ApplyTest extends ConsoleTest
{
    private const SITE_ENTRY = ['table' => 'elements_sites', 'columns' => ['siteId', 'enabled']];
    private const SITE_NAME = 'qb_elements_sites_siteid_enabled';
    private const NO_COLUMN_ENTRY = ['table' => 'entries', 'columns' => ['noSuchColumn']];
    private const NO_TABLE_ENTRY = ['table' => 'entrees', 'columns' => ['sectionId']];
    /** MySQL refuses a TEXT column in a key with no length: a refusal only the database can give. */
    private const TEXT_COLUMN_ENTRY = ['table' => 'elements_sites', 'columns' => ['content']];
    private const EXPRESSION_ENTRY = ['table' => 'elements_sites', 'expression' => 'CAST(content->>\'$."abc"\' AS CHAR(255))'];
    /** Six columns whose derived name passes sixty characters, so the hashed form is used (TN-7). */
    private const LONG_ENTRY = ['table' => 'elements_sites', 'columns' => ['elementId', 'siteId', 'title', 'slug', 'enabled', 'dateCreated']];
    private const DEVELOPER_INDEX = 'dev_sites';
    private const GHOST_INDEX = 'qb_elements_sites_aghost';

    // Apply creates and is idempotent (BR-6, BR-8)
    // -------------------------------------------------------------------------

    public function testApplyCreatesADeclaredEntryUnderItsDerivedName(): void
    {
        try {
            $plan = $this->apply([self::SITE_ENTRY]);

            $this->assertSame([Plan::PRESENT, Plan::PRESENT, Plan::CREATE], $this->states($plan));
            $this->assertSame(self::SITE_NAME, $plan->entries[2]->name());
            $this->assertSame(self::SITE_NAME, $this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));
            $this->assertSame([], $plan->drops);
            $this->assertSame([], $plan->refusedDrops);
            $this->assertSame(0, $plan->refusedCount());
        } finally {
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    public function testASecondApplyOnTheSameListChangesNothing(): void
    {
        try {
            $this->apply([self::SITE_ENTRY]);
            $plan = $this->apply([self::SITE_ENTRY]);

            $this->assertSame([Plan::PRESENT, Plan::PRESENT, Plan::PRESENT], $this->states($plan));
            $this->assertSame([], $plan->drops);
            $this->assertFalse($plan->hasChanges());
        } finally {
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    public function testThePlanAloneChangesNothing(): void
    {
        $plan = (new Sync(new Schema($this->db())))->plan([self::SITE_ENTRY]);

        $this->assertSame(Plan::CREATE, $plan->entries[2]->state);
        $this->assertNull($this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));
    }

    public function testAnExpressionEntryIsCreatedAndFoundPresentNext(): void
    {
        $name = Declaration::fromEntry(self::EXPRESSION_ENTRY)->name();

        try {
            $plan = $this->apply([self::EXPRESSION_ENTRY]);

            $this->assertSame(Plan::CREATE, $plan->entries[2]->state);
            $this->assertSame($name, $plan->entries[2]->name());
            $this->assertArrayHasKey($name, (new Schema($this->db()))->ownedIndexes());

            $this->assertSame(Plan::PRESENT, $this->apply([self::EXPRESSION_ENTRY])->entries[2]->state);
        } finally {
            $this->dropQuietly($name, Table::ELEMENTS_SITES);
        }
    }

    public function testALongDefinitionIsCreatedUnderItsHashedNameAndFoundPresentNext(): void
    {
        $name = Declaration::fromEntry(self::LONG_ENTRY)->name();
        $this->assertStringStartsWith('qb_elements_sites_h', $name);

        try {
            $plan = $this->apply([self::LONG_ENTRY]);

            $this->assertSame(Plan::CREATE, $plan->entries[2]->state);
            $this->assertSame($name, $this->indexOn(Table::ELEMENTS_SITES, self::LONG_ENTRY['columns']));

            $this->assertSame(Plan::PRESENT, $this->apply([self::LONG_ENTRY])->entries[2]->state);
        } finally {
            $this->dropQuietly($name, Table::ELEMENTS_SITES);
        }
    }

    // Strike (BR-9)
    // -------------------------------------------------------------------------

    public function testAStruckEntrysIndexIsDroppedAndAnotherIndexOnItsColumnsIsLeft(): void
    {
        try {
            $this->apply([self::SITE_ENTRY]);
            $this->db()->createCommand()->createIndex(self::DEVELOPER_INDEX, Table::ELEMENTS_SITES, ['siteId', 'enabled'])->execute();

            $plan = $this->apply([]);

            $this->assertSame([self::SITE_NAME], array_map(fn(OwnedIndex $index) => $index->name, $plan->drops));
            $this->assertSame([], $plan->refusedDrops);
            $this->assertArrayNotHasKey(self::SITE_NAME, (new Schema($this->db()))->ownedIndexes());
            $this->assertSame(self::DEVELOPER_INDEX, $this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));

            // Re-declared, the entry is served by the developer's index and nothing is created (TS-2 step 3a)
            $again = $this->apply([self::SITE_ENTRY]);
            $this->assertSame(Plan::SERVED, $again->entries[2]->state);
            $this->assertSame(self::DEVELOPER_INDEX, $again->entries[2]->servedBy);
            $this->assertArrayNotHasKey(self::SITE_NAME, (new Schema($this->db()))->ownedIndexes());
        } finally {
            $this->dropQuietly(self::DEVELOPER_INDEX, Table::ELEMENTS_SITES);
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    // Refusals (BR-7)
    // -------------------------------------------------------------------------

    public function testAColumnTheTableLacksIsRefusedBeforeAnyStatementAndTheNextEntryIsStillCreated(): void
    {
        try {
            $plan = $this->apply([self::NO_COLUMN_ENTRY, self::SITE_ENTRY]);

            $this->assertSame([Plan::PRESENT, Plan::PRESENT, Plan::REFUSED, Plan::CREATE], $this->states($plan));
            $this->assertSame(Sync::REASON_COLUMN_NOT_FOUND . ': noSuchColumn', $plan->entries[2]->reason);
            $this->assertSame(1, $plan->refusedCount());
            $this->assertSame(self::SITE_NAME, $this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));

            // The dry run sees the same refusal, so the command can exit non-zero on it (TS-4 step 4)
            $dryRun = (new Sync(new Schema($this->db())))->plan([self::NO_COLUMN_ENTRY]);
            $this->assertSame(Plan::REFUSED, $dryRun->entries[2]->state);
        } finally {
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    public function testAMissingTableIsRefusedBeforeAnyStatement(): void
    {
        $plan = $this->apply([self::NO_TABLE_ENTRY]);

        $this->assertSame(Plan::REFUSED, $plan->entries[2]->state);
        $this->assertSame(Sync::REASON_TABLE_NOT_FOUND, $plan->entries[2]->reason);
    }

    public function testAStatementTheDatabaseRefusesCarriesItsOwnMessageOnOneLine(): void
    {
        $plan = $this->apply([self::TEXT_COLUMN_ENTRY, self::SITE_ENTRY]);

        try {
            $this->assertSame([Plan::PRESENT, Plan::PRESENT, Plan::REFUSED, Plan::CREATE], $this->states($plan));
            $reason = (string)$plan->entries[2]->reason;
            $this->assertStringContainsString("'content'", $reason);
            $this->assertStringNotContainsString("\n", $reason);
            $this->assertStringNotContainsString('SQLSTATE', $reason);
            $this->assertNull($this->indexOn(Table::ELEMENTS_SITES, ['content']));
            $this->assertSame(self::SITE_NAME, $this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));
        } finally {
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    public function testACreateRefusedAsADuplicateIsFoundPresentOnTheNextApply(): void
    {
        // Two deploys at once (TN-12): this one's plan was read before the other's CREATE landed.
        $stale = new class($this->db(), self::SITE_NAME) extends Schema {
            public function __construct(Connection $db, private readonly string $unseen)
            {
                parent::__construct($db);
            }

            public function ownedIndexes(): array
            {
                return array_filter(parent::ownedIndexes(), fn(OwnedIndex $index) => $index->name !== $this->unseen);
            }

            public function findIndex(string $table, array $columns): ?string
            {
                return null;
            }
        };

        try {
            $this->db()->createCommand()->createIndex(self::SITE_NAME, Table::ELEMENTS_SITES, ['siteId', 'enabled'])->execute();
            $plan = (new Sync($stale))->apply([self::SITE_ENTRY]);

            $this->assertSame(Plan::REFUSED, $plan->entries[2]->state);
            $this->assertStringContainsString('Duplicate', (string)$plan->entries[2]->reason);

            $this->assertSame(Plan::PRESENT, $this->apply([self::SITE_ENTRY])->entries[2]->state);
        } finally {
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    public function testADropTheDatabaseRefusesIsRecordedAndTheNextDropStillRuns(): void
    {
        // The catalogue names an owned index the table no longer has, ahead of a real one.
        $ghost = new OwnedIndex(self::GHOST_INDEX, $this->db()->getSchema()->getRawTableName(Table::ELEMENTS_SITES), ['siteId'], null);
        $haunted = new class($this->db(), $ghost) extends Schema {
            public function __construct(Connection $db, private readonly OwnedIndex $ghost)
            {
                parent::__construct($db);
            }

            public function ownedIndexes(): array
            {
                return [$this->ghost->name => $this->ghost] + parent::ownedIndexes();
            }
        };

        try {
            $this->apply([self::SITE_ENTRY]);
            $plan = (new Sync($haunted))->apply([]);

            $this->assertSame([self::SITE_NAME], array_map(fn(OwnedIndex $index) => $index->name, $plan->drops));
            $this->assertSame([self::GHOST_INDEX], array_keys($plan->refusedDrops));
            $this->assertStringContainsString(self::GHOST_INDEX, $plan->refusedDrops[self::GHOST_INDEX]);
            $this->assertSame(1, $plan->refusedCount());
            $this->assertNull($this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));
        } finally {
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    // The command (BR-11)
    // -------------------------------------------------------------------------

    public function testTheCommandAppliesTheSettingsListAndExitsZero(): void
    {
        try {
            $this->withSettings([self::SITE_ENTRY]);
            $this->consoleCommand('query-boost/apply', [], true)->exitCode(ExitCode::OK)->run();

            $this->assertSame(self::SITE_NAME, $this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));
        } finally {
            $this->withSettings([]);
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    public function testTheDryRunFlagLeavesTheSchemaAloneAndExitsZero(): void
    {
        try {
            $this->withSettings([self::SITE_ENTRY]);
            $this->consoleCommand('query-boost/apply', ['dryRun' => true], true)->exitCode(ExitCode::OK)->run();

            $this->assertNull($this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));
        } finally {
            $this->withSettings([]);
        }
    }

    public function testTheCommandExitsNonZeroOnARefusedEntryWithAndWithoutTheFlag(): void
    {
        try {
            $this->withSettings([self::NO_COLUMN_ENTRY, self::SITE_ENTRY]);
            $this->consoleCommand('query-boost/apply', [], true)->exitCode(ExitCode::UNSPECIFIED_ERROR)->run();
            // The refusal did not stop the next entry (BR-7)
            $this->assertSame(self::SITE_NAME, $this->indexOn(Table::ELEMENTS_SITES, ['siteId', 'enabled']));

            $this->consoleCommand('query-boost/apply', ['dryRun' => true], true)->exitCode(ExitCode::UNSPECIFIED_ERROR)->run();
        } finally {
            $this->withSettings([]);
            $this->dropQuietly(self::SITE_NAME, Table::ELEMENTS_SITES);
        }
    }

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $entries
     */
    private function apply(array $entries): Plan
    {
        return (new Sync(new Schema($this->db())))->apply($entries);
    }

    /**
     * The settings file's list, as the command reads it (BR-1): set on the plugin's settings model
     * for this test's request.
     *
     * @param array<array-key, mixed> $entries
     */
    private function withSettings(array $entries): void
    {
        QueryBoost::getInstance()->getSettings()->indexes = $entries;
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
        return \Craft::$app->getDb();
    }

    /**
     * @return list<string>
     */
    private function states(Plan $plan): array
    {
        return array_map(fn(PlanEntry $entry) => $entry->state, $plan->entries);
    }
}
