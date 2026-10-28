<?php

namespace webdna\queryboost\tests\unit;

use Codeception\Test\Unit;
use webdna\queryboost\indexes\Declaration;
use webdna\queryboost\indexes\OwnedIndex;
use webdna\queryboost\indexes\Plan;
use webdna\queryboost\indexes\PlanEntry;
use webdna\queryboost\indexes\Report;

/**
 * The words the command and the deploy hook print for a plan (BR-7, BR-10, BR-11): one line per
 * entry and per drop naming the index, the table and the definition, a refusal's reason, and a
 * summary. An applied plan says what was done, a plan alone what would be.
 */
class ReportTest extends Unit
{
    private const SITE_ENTRY = ['table' => 'elements_sites', 'columns' => ['siteId', 'enabled']];

    public function testAnAppliedPlanSaysWhatItDidLineByLine(): void
    {
        $site = Declaration::fromEntry(self::SITE_ENTRY);
        $plan = new Plan(
            [
                new PlanEntry(Plan::PRESENT, $this->builtIn(), null, true),
                new PlanEntry(Plan::CREATE, $site, self::SITE_ENTRY, false),
                new PlanEntry(Plan::SERVED, $site, self::SITE_ENTRY, false, servedBy: 'dev_sites'),
                new PlanEntry(Plan::REFUSED, Declaration::fromEntry(['table' => 'entries', 'columns' => ['noSuchColumn']]), [], false, reason: 'column not found: noSuchColumn'),
                new PlanEntry(Plan::REFUSED, null, 'entries (sectionId, postDate)', false, reason: Declaration::REASON_NOT_AN_ARRAY),
            ],
            [new OwnedIndex('qb_entries_x0123456789', 'craft_entries', [], 'cast(…)')],
            ['qb_elements_sites_aghost' => "Can't DROP 'qb_elements_sites_aghost'; check that column/key exists"],
        );

        $this->assertSame([
            'present qb_entries_sectionid_postdate on entries (sectionId, postDate)',
            'created qb_elements_sites_siteid_enabled on elements_sites (siteId, enabled)',
            'served elements_sites (siteId, enabled) by dev_sites',
            'refused entries (noSuchColumn): column not found: noSuchColumn',
            'refused "entries (sectionId, postDate)": ' . Declaration::REASON_NOT_AN_ARRAY,
            'dropped qb_entries_x0123456789 on craft_entries',
            "not dropped qb_elements_sites_aghost: Can't DROP 'qb_elements_sites_aghost'; check that column/key exists",
            '1 present, 1 served, 1 created, 1 dropped, 2 refused, 1 not dropped.',
        ], (new Report($plan, applied: true))->lines());
    }

    public function testAPlanAloneSaysWhatItWouldDo(): void
    {
        $plan = new Plan(
            [
                new PlanEntry(Plan::PRESENT, $this->builtIn(), null, true),
                new PlanEntry(Plan::CREATE, Declaration::fromEntry(self::SITE_ENTRY), self::SITE_ENTRY, false),
            ],
            [new OwnedIndex('qb_entries_x0123456789', 'craft_entries', [], 'cast(…)')],
        );

        $this->assertSame([
            'present qb_entries_sectionid_postdate on entries (sectionId, postDate)',
            'would create qb_elements_sites_siteid_enabled on elements_sites (siteId, enabled)',
            'would drop qb_entries_x0123456789 on craft_entries',
            '1 present, 1 to create, 1 to drop.',
        ], (new Report($plan, applied: false))->lines());
    }

    public function testTheSummarySaysWhenThereIsNothingToDoAndWhenNoSiteIndexIsDeclared(): void
    {
        $plan = new Plan([
            new PlanEntry(Plan::PRESENT, $this->builtIn(), null, true),
            new PlanEntry(Plan::PRESENT, Declaration::builtIns()[1], null, true),
        ], []);

        $this->assertSame(
            '2 present, nothing to create or drop; no site indexes are declared.',
            (new Report($plan, applied: true))->summary(),
        );
    }

    private function builtIn(): Declaration
    {
        return Declaration::builtIns()[0];
    }
}
