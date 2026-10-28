<?php

namespace webdna\queryboost\tests\unit;

use Codeception\Test\Unit;
use LogicException;
use webdna\queryboost\indexes\Declaration;
use webdna\queryboost\indexes\OwnedIndex;
use webdna\queryboost\indexes\Plan;
use webdna\queryboost\indexes\PlanEntry;
use webdna\queryboost\indexes\Schema;
use webdna\queryboost\indexes\Sync;

/**
 * The plan: the declared list against a recorded picture of the database (spec §7, "the plan given
 * a declared list and a recorded set of existing indexes"). Every entry gets a state (BR-4), the
 * list is read in order with duplicates served by the first (BR-6), and only owned indexes nobody
 * derives are dropped (BR-9). Nothing here touches the database: the schema is a recording.
 */
class PlanTest extends Unit
{
    private const BUILT_IN_ENTRIES = 'qb_entries_sectionid_postdate';
    private const BUILT_IN_ELEMENTS = 'qb_elements_canonicalid_datecreated';
    private const SITE_NAME = 'qb_elements_sites_siteid_enabled';
    private const SITE_ENTRY = ['table' => 'elements_sites', 'columns' => ['siteId', 'enabled']];
    private const EXPRESSION_ENTRY = ['table' => 'elements_sites', 'expression' => 'CAST((content->>\'$."a1b2c3d4"\') AS CHAR(255))'];

    // The empty state and the built-ins (BR-3)
    // -------------------------------------------------------------------------

    public function testTheEmptyStateIsTheTwoBuiltInsPresentAndNothingElse(): void
    {
        $plan = $this->sync(owned: $this->builtInsOwned())->plan([]);

        $this->assertSame([Plan::PRESENT, Plan::PRESENT], $this->states($plan));
        $this->assertSame([self::BUILT_IN_ENTRIES, self::BUILT_IN_ELEMENTS], $this->names($plan));
        $this->assertSame([true, true], array_map(fn(PlanEntry $e) => $e->builtIn, $plan->entries));
        $this->assertSame([], $plan->drops);
        $this->assertSame(0, $plan->refusedCount());
        $this->assertFalse($plan->hasChanges());
    }

    public function testTheBuiltInsAreDeclaredAheadOfTheSiteListAndCreatedOnAFreshDatabase(): void
    {
        $plan = $this->sync()->plan([self::SITE_ENTRY]);

        $this->assertSame([Plan::CREATE, Plan::CREATE, Plan::CREATE], $this->states($plan));
        $this->assertSame([self::BUILT_IN_ENTRIES, self::BUILT_IN_ELEMENTS, self::SITE_NAME], $this->names($plan));
        $this->assertSame([true, true, false], array_map(fn(PlanEntry $e) => $e->builtIn, $plan->entries));
        $this->assertTrue($plan->hasChanges());
    }

    // One entry's state (BR-4)
    // -------------------------------------------------------------------------

    public function testADeclaredEntryNoIndexServesIsCreatedUnderItsDerivedName(): void
    {
        $plan = $this->sync(owned: $this->builtInsOwned())->plan([self::SITE_ENTRY]);

        $entry = $plan->entries[2];
        $this->assertSame(Plan::CREATE, $entry->state);
        $this->assertSame(self::SITE_NAME, $entry->name());
        $this->assertSame('elements_sites (siteId, enabled)', $entry->declaration?->definition());
        $this->assertSame(self::SITE_ENTRY, $entry->source);
        $this->assertNull($entry->servedBy);
        $this->assertNull($entry->reason);
    }

    public function testAnEntryServedByAnIndexUnderAnotherNameCreatesNothingAndNamesIt(): void
    {
        $sync = $this->sync(
            owned: $this->builtInsOwned(),
            serving: ['{{%elements_sites}} siteId,enabled' => 'idx_sites_enabled'],
        );

        $plan = $sync->plan([self::SITE_ENTRY]);

        $this->assertSame(Plan::SERVED, $plan->entries[2]->state);
        $this->assertSame('idx_sites_enabled', $plan->entries[2]->servedBy);
        $this->assertFalse($plan->hasChanges());
    }

    /**
     * TN-13: a site upgraded to Craft 6 and rolled back keeps Craft's composite indexes under
     * Craft's names. The built-ins are served by them; nothing is created and nothing is ours to drop.
     */
    public function testTheBuiltInsColumnsIndexedUnderCraftSixsOwnNamesAreServed(): void
    {
        $sync = $this->sync(serving: [
            '{{%entries}} sectionId,postDate' => 'idx_qwerty_entries_sectionId_postDate_idx',
            '{{%elements}} canonicalId,dateCreated' => 'idx_asdfgh_elements_canonicalId_dateCreated_idx',
        ]);

        $plan = $sync->plan([]);

        $this->assertSame([Plan::SERVED, Plan::SERVED], $this->states($plan));
        $this->assertSame(
            ['idx_qwerty_entries_sectionId_postDate_idx', 'idx_asdfgh_elements_canonicalId_dateCreated_idx'],
            array_map(fn(PlanEntry $e) => $e->servedBy, $plan->entries),
        );
        $this->assertSame([], $plan->drops);
        $this->assertFalse($plan->hasChanges());
    }

    /**
     * TN-14: the owned name wins over the column lookup, whatever the index under it now holds.
     */
    public function testAnOwnedIndexOfTheDerivedNameIsPresentWhateverElseServesTheColumns(): void
    {
        $sync = $this->sync(
            owned: [...$this->builtInsOwned(), new OwnedIndex(self::SITE_NAME, 'craft_elements_sites', ['siteId'], null)],
            serving: ['{{%elements_sites}} siteId,enabled' => 'idx_sites_enabled'],
        );

        $plan = $sync->plan([self::SITE_ENTRY]);

        $this->assertSame(Plan::PRESENT, $plan->entries[2]->state);
        $this->assertNull($plan->entries[2]->servedBy);
        $this->assertSame([], $plan->drops);
    }

    public function testAnExpressionIsServedOnlyByAnOwnedIndexOfItsDerivedName(): void
    {
        $name = Declaration::fromEntry(self::EXPRESSION_ENTRY)->name();

        // The recorded schema throws if the plan asks Craft's lookup about an expression (B3).
        $created = $this->sync(owned: $this->builtInsOwned())->plan([self::EXPRESSION_ENTRY]);
        $present = $this->sync(
            owned: [...$this->builtInsOwned(), new OwnedIndex($name, 'craft_elements_sites', [], 'cast(json_unquote(...) as char(255))')],
        )->plan([self::EXPRESSION_ENTRY]);

        $this->assertSame(Plan::CREATE, $created->entries[2]->state);
        $this->assertSame($name, $created->entries[2]->name());
        $this->assertSame(Plan::PRESENT, $present->entries[2]->state);
        $this->assertSame([], $present->drops);
    }

    // Order and duplicates (BR-6)
    // -------------------------------------------------------------------------

    public function testTheSameEntryTwiceIsCreatedOnceAndTheSecondServedByTheFirst(): void
    {
        $plan = $this->sync(owned: $this->builtInsOwned())->plan([self::SITE_ENTRY, self::SITE_ENTRY]);

        $this->assertSame([Plan::PRESENT, Plan::PRESENT, Plan::CREATE, Plan::SERVED], $this->states($plan));
        $this->assertSame(self::SITE_NAME, $plan->entries[3]->servedBy);
        $this->assertSame(self::SITE_NAME, $plan->entries[3]->name());
    }

    public function testAnEntryAlreadyPresentIsUnchangedOnASecondPlan(): void
    {
        $sync = $this->sync(owned: [...$this->builtInsOwned(), $this->siteOwned()]);

        $plan = $sync->plan([self::SITE_ENTRY]);

        $this->assertSame([Plan::PRESENT, Plan::PRESENT, Plan::PRESENT], $this->states($plan));
        $this->assertSame([], $plan->drops);
        $this->assertFalse($plan->hasChanges());
    }

    // Refusals (BR-7, TN-1, TN-3)
    // -------------------------------------------------------------------------

    public function testARefusedEntryIsReportedAndTheNextIsStillPlanned(): void
    {
        $plan = $this->sync(owned: $this->builtInsOwned())->plan(['entries (sectionId, postDate)', self::SITE_ENTRY]);

        $refused = $plan->entries[2];
        $this->assertSame(Plan::REFUSED, $refused->state);
        $this->assertSame(Declaration::REASON_NOT_AN_ARRAY, $refused->reason);
        $this->assertNull($refused->declaration);
        $this->assertNull($refused->name());
        $this->assertSame('entries (sectionId, postDate)', $refused->source);
        $this->assertSame(Plan::CREATE, $plan->entries[3]->state);
        $this->assertSame(1, $plan->refusedCount());
    }

    public function testAnEntryOnATableTheDatabaseDoesNotHaveIsRefusedWithoutALookup(): void
    {
        // The recorded schema throws if the plan asks Craft's lookup about a table it does not have.
        $plan = $this->sync(owned: $this->builtInsOwned())->plan([['table' => 'entrees', 'columns' => ['sectionId']]]);

        $refused = $plan->entries[2];
        $this->assertSame(Plan::REFUSED, $refused->state);
        $this->assertSame(Sync::REASON_TABLE_NOT_FOUND, $refused->reason);
        $this->assertSame('entrees (sectionId)', $refused->declaration?->definition());
        $this->assertSame(1, $plan->refusedCount());
        $this->assertFalse($plan->hasChanges());
    }

    // Drops (BR-9)
    // -------------------------------------------------------------------------

    public function testAnOwnedIndexNoEntryDerivesIsDroppedAndTheBuiltInsNever(): void
    {
        $site = $this->siteOwned();
        $stray = new OwnedIndex('qb_commerce_orders_x0123456789', 'craft_commerce_orders', [], 'cast(...)');
        $sync = $this->sync(owned: [...$this->builtInsOwned(), $site, $stray]);

        $plan = $sync->plan([]);

        $this->assertSame([Plan::PRESENT, Plan::PRESENT], $this->states($plan));
        $this->assertSame([$site, $stray], $plan->drops);
        $this->assertTrue($plan->hasChanges());
    }

    public function testADeclaredEntryKeepsItsOwnedIndexOutOfTheDrops(): void
    {
        $sync = $this->sync(owned: [...$this->builtInsOwned(), $this->siteOwned()]);

        $plan = $sync->plan([self::SITE_ENTRY]);

        $this->assertSame([], $plan->drops);
    }

    public function testAStruckEntryServedUnderAnotherNameLeavesThatIndexAlone(): void
    {
        $sync = $this->sync(
            owned: $this->builtInsOwned(),
            serving: ['{{%elements_sites}} siteId,enabled' => 'idx_sites_enabled'],
        );

        $plan = $sync->plan([]);

        $this->assertSame([], $plan->drops);
        $this->assertFalse($plan->hasChanges());
    }

    // The recording
    // -------------------------------------------------------------------------

    /**
     * @param list<OwnedIndex> $owned Every `qb_` index the catalogue would list, in catalogue order
     * @param list<string> $tables The prefixed tables that exist
     * @param array<string, string> $serving Craft's lookup, `'{{%table}} col,col' => 'index name'`
     */
    private function sync(array $owned = [], array $tables = ['{{%entries}}', '{{%elements}}', '{{%elements_sites}}'], array $serving = []): Sync
    {
        $schema = new class($owned, $tables, $serving) extends Schema {
            /**
             * @param list<OwnedIndex> $owned
             * @param list<string> $tables
             * @param array<string, string> $serving
             */
            public function __construct(
                private readonly array $owned,
                private readonly array $tables,
                private readonly array $serving,
            ) {
                parent::__construct();
            }

            public function ownedIndexes(): array
            {
                $byName = [];
                foreach ($this->owned as $index) {
                    $byName[$index->name] = $index;
                }

                return $byName;
            }

            public function tableExists(string $table): bool
            {
                return in_array($table, $this->tables, true);
            }

            public function findIndex(string $table, array $columns): ?string
            {
                if ($columns === []) {
                    throw new LogicException("Craft's lookup was asked about an expression on $table");
                }
                if (!$this->tableExists($table)) {
                    throw new LogicException("Craft's lookup was asked about $table, which does not exist");
                }

                return $this->serving[$table . ' ' . implode(',', $columns)] ?? null;
            }
        };

        return new Sync($schema);
    }

    /**
     * @return list<OwnedIndex>
     */
    private function builtInsOwned(): array
    {
        return [
            new OwnedIndex(self::BUILT_IN_ENTRIES, 'craft_entries', ['sectionId', 'postDate'], null),
            new OwnedIndex(self::BUILT_IN_ELEMENTS, 'craft_elements', ['canonicalId', 'dateCreated'], null),
        ];
    }

    private function siteOwned(): OwnedIndex
    {
        return new OwnedIndex(self::SITE_NAME, 'craft_elements_sites', ['siteId', 'enabled'], null);
    }

    /**
     * @return list<string>
     */
    private function states(Plan $plan): array
    {
        return array_map(fn(PlanEntry $e) => $e->state, $plan->entries);
    }

    /**
     * @return list<string|null>
     */
    private function names(Plan $plan): array
    {
        return array_map(fn(PlanEntry $e) => $e->name(), $plan->entries);
    }
}
