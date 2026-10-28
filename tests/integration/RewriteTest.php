<?php

namespace webdna\queryboost\tests\integration;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\elements\User;
use webdna\queryboost\QueryBoost;

/**
 * The plugin's listener end to end on the compose MySQL, TS-8 step 2 in the harness: the SQL of an
 * element query with the tiebreaker on against the same query with it off. A sort the built-in
 * `(sectionId, postDate)` serves is rewritten; a sort no index on the element table serves is left
 * byte-identical to Craft's (BR-14, AC-8). The index list is the plugin's own read of the test
 * schema, cached once per table, so this is the one place `QueryBoost`'s closure runs.
 *
 * Craft 5 appends no tiebreaker to an explicit `orderBy()` — only its default orders carry one — so
 * the unserved sort names `elements.id` itself, or the first guard would refuse it before BR-14 is
 * reached.
 */
class RewriteTest extends Unit
{
    /**
     * `withStructure(false)`: with no section named, Craft joins the structure and leads the sort
     * with `structureelements.lft`, which the first guard refuses in either mode.
     */
    public function testTheDefaultEntryOrderIsRewrittenOntoTheEntriesTable(): void
    {
        [$off, $on] = $this->sqlOffAndOn(
            fn(): string => Entry::find()->status(null)->withStructure(false)->limit(10)->createCommand()->getRawSql(),
        );

        $this->assertStringContainsString('ORDER BY `entries`.`postDate` DESC, `elements`.`id` DESC', $off);
        $this->assertStringContainsString('ORDER BY `entries`.`postDate` DESC, `entries`.`id` DESC', $on);
    }

    public function testAUserSortNoIndexServesIsLeftByteIdentical(): void
    {
        [$off, $on] = $this->sqlOffAndOn(
            fn(): string => User::find()->status(null)->orderBy(['users.lastName' => SORT_ASC, 'elements.id' => SORT_ASC])->limit(10)->createCommand()->getRawSql(),
        );

        $this->assertStringContainsString('ORDER BY `users`.`lastName`, `elements`.`id`', $off);
        $this->assertSame($off, $on);
    }

    /**
     * The raw SQL of a freshly built query with the tiebreaker off, then on, the setting restored
     * afterwards; the straight join stays as the test settings have it.
     *
     * @param callable(): string $make Builds the query afresh and returns its raw SQL
     * @return array{string, string}
     */
    private function sqlOffAndOn(callable $make): array
    {
        $settings = QueryBoost::getInstance()->getSettings();
        $original = $settings->tiebreaker;

        try {
            $settings->tiebreaker = false;
            $off = $make();
            $settings->tiebreaker = true;
            $on = $make();
        } finally {
            $settings->tiebreaker = $original;
        }

        return [$off, $on];
    }
}
