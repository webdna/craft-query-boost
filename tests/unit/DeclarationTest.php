<?php

namespace webdna\queryboost\tests\unit;

use Codeception\Test\Unit;
use craft\db\Table;
use InvalidArgumentException;
use webdna\queryboost\indexes\Declaration;
use webdna\queryboost\migrations\Install;
use webdna\queryboost\models\Settings;
use webdna\queryboost\QueryBoost;

/**
 * One entry of the `indexes` list (spec §4): its shape (BR-2), the two built-ins that always come
 * first (BR-3) and the name derived from it (BR-5). Nothing here touches the database.
 */
class DeclarationTest extends Unit
{
    private const EXPRESSION = 'CAST((content->>\'$."a1b2c3d4"\') AS CHAR(255))';

    // The settings key (BR-1)
    // -------------------------------------------------------------------------

    public function testSettingsDeclareNoIndexesByDefault(): void
    {
        $this->assertSame([], (new Settings())->indexes);
    }

    public function testSettingsTakeTheListAsCraftsMergeHandsItOver(): void
    {
        $entry = ['table' => 'elements_sites', 'columns' => ['siteId', 'enabled']];

        $settings = new Settings(['indexes' => [$entry]]);

        $this->assertSame([$entry], $settings->indexes);
    }

    public function testThePluginReadsTheKeyFromItsSettings(): void
    {
        $settings = QueryBoost::getInstance()->getSettings();

        $this->assertInstanceOf(Settings::class, $settings);
        $this->assertSame([], $settings->indexes);
    }

    // A valid entry (BR-2)
    // -------------------------------------------------------------------------

    public function testAColumnsEntryIsAccepted(): void
    {
        $declaration = Declaration::fromEntry(['table' => 'entries', 'columns' => ['sectionId', 'postDate']]);

        $this->assertSame('entries', $declaration->table);
        $this->assertSame(['sectionId', 'postDate'], $declaration->columns);
        $this->assertNull($declaration->expression);
        $this->assertFalse($declaration->isExpression());
    }

    public function testAnExpressionEntryIsAccepted(): void
    {
        $declaration = Declaration::fromEntry(['table' => 'elements_sites', 'expression' => self::EXPRESSION]);

        $this->assertSame('elements_sites', $declaration->table);
        $this->assertSame(self::EXPRESSION, $declaration->expression);
        $this->assertSame([], $declaration->columns);
        $this->assertTrue($declaration->isExpression());
    }

    /**
     * The craft-perf run's provenance keys, and a `name` the plugin will not honour (§4).
     */
    public function testAnyOtherKeyIsIgnored(): void
    {
        $declaration = Declaration::fromEntry([
            'table' => 'entries',
            'columns' => ['sectionId', 'postDate'],
            'name' => 'my_own_name',
            'measured' => ['before' => 65.0, 'after' => 1.6],
            'source' => 'craft-perf db 2026-10-27',
        ]);

        $this->assertSame('qb_entries_sectionid_postdate', $declaration->name());
    }

    public function testTheTableIsAddressedWithTheSitesPrefix(): void
    {
        $declaration = Declaration::fromEntry(['table' => 'entries', 'columns' => ['sectionId']]);

        $this->assertSame(Table::ENTRIES, $declaration->prefixedTable());
    }

    public function testTheDefinitionReadsAsTheTableAndItsColumns(): void
    {
        $columns = Declaration::fromEntry(['table' => 'entries', 'columns' => ['sectionId', 'postDate']]);
        $expression = Declaration::fromEntry(['table' => 'elements_sites', 'expression' => self::EXPRESSION]);

        $this->assertSame('entries (sectionId, postDate)', $columns->definition());
        $this->assertSame('elements_sites (' . self::EXPRESSION . ')', $expression->definition());
    }

    // A refused entry (BR-2, TN-1, TN-2)
    // -------------------------------------------------------------------------

    public function testAStringEntryIsRefused(): void
    {
        $this->assertRefused(Declaration::REASON_NOT_AN_ARRAY, 'entries (sectionId, postDate)');
        $this->assertSame('an entry is an array with table and columns or expression', Declaration::REASON_NOT_AN_ARRAY);
    }

    public function testAMissingTableIsRefused(): void
    {
        $this->assertRefused(Declaration::REASON_TABLE, ['columns' => ['sectionId']]);
        $this->assertRefused(Declaration::REASON_TABLE, ['table' => '', 'columns' => ['sectionId']]);
        $this->assertRefused(Declaration::REASON_TABLE, ['table' => ['entries'], 'columns' => ['sectionId']]);
    }

    public function testBothColumnsAndExpressionIsRefused(): void
    {
        $this->assertRefused(Declaration::REASON_ONE_DEFINITION, [
            'table' => 'entries',
            'columns' => ['sectionId'],
            'expression' => self::EXPRESSION,
        ]);
    }

    public function testNeitherColumnsNorExpressionIsRefused(): void
    {
        $this->assertRefused(Declaration::REASON_ONE_DEFINITION, ['table' => 'entries']);
        $this->assertRefused(Declaration::REASON_ONE_DEFINITION, ['table' => 'entries', 'columns' => null]);
    }

    public function testEmptyColumnsAreRefused(): void
    {
        $this->assertRefused(Declaration::REASON_COLUMNS, ['table' => 'entries', 'columns' => []]);
    }

    public function testColumnsMustBeAListOfColumnNames(): void
    {
        $this->assertRefused(Declaration::REASON_COLUMNS, ['table' => 'entries', 'columns' => 'sectionId']);
        $this->assertRefused(Declaration::REASON_COLUMNS, ['table' => 'entries', 'columns' => ['sectionId', 7]]);
        $this->assertRefused(Declaration::REASON_COLUMNS, ['table' => 'entries', 'columns' => ['sectionId', '']]);
        $this->assertRefused(Declaration::REASON_COLUMNS, ['table' => 'entries', 'columns' => ['first' => 'sectionId']]);
    }

    public function testAnEmptyExpressionIsRefused(): void
    {
        $this->assertRefused(Declaration::REASON_EXPRESSION, ['table' => 'entries', 'expression' => '']);
        $this->assertRefused(Declaration::REASON_EXPRESSION, ['table' => 'entries', 'expression' => "  \n"]);
        $this->assertRefused(Declaration::REASON_EXPRESSION, ['table' => 'entries', 'expression' => ['postDate']]);
    }

    // The built-ins (BR-3)
    // -------------------------------------------------------------------------

    public function testTheBuiltInsKeepTheNamesTheyHaveToday(): void
    {
        $names = array_map(fn(Declaration $d) => $d->name(), Declaration::builtIns());

        $this->assertSame(['qb_entries_sectionid_postdate', 'qb_elements_canonicalid_datecreated'], $names);
    }

    /**
     * The install migration's list and the built-ins are one list; this fails if either moves.
     */
    public function testTheBuiltInsAreTheInstallMigrationsIndexes(): void
    {
        foreach (Declaration::builtIns() as $declaration) {
            $this->assertSame(
                [$declaration->prefixedTable(), $declaration->columns],
                Install::INDEXES[$declaration->name()],
            );
        }

        $this->assertCount(count(Install::INDEXES), Declaration::builtIns());
    }

    // The derived name (BR-5, TN-7)
    // -------------------------------------------------------------------------

    public function testTheNameIsThePrefixTheTableAndTheColumnsLowerCased(): void
    {
        $declaration = Declaration::fromEntry(['table' => 'elements_sites', 'columns' => ['siteId', 'enabled']]);

        $this->assertSame('qb_elements_sites_siteid_enabled', $declaration->name());
    }

    public function testAnExpressionsNameHashesTheExpression(): void
    {
        $declaration = Declaration::fromEntry(['table' => 'elements_sites', 'expression' => self::EXPRESSION]);

        // The first ten hex characters of sha1 of the expression, computed outside PHP.
        $this->assertSame('qb_elements_sites_xe3c4233d4c', $declaration->name());
    }

    public function testAnExpressionsWhitespaceDoesNotChangeItsName(): void
    {
        $spaced = "  CAST((content->>'\$.\"a1b2c3d4\"')\n\t   AS   CHAR(255))  ";
        $declaration = Declaration::fromEntry(['table' => 'elements_sites', 'expression' => $spaced]);

        $this->assertSame('qb_elements_sites_xe3c4233d4c', $declaration->name());
    }

    public function testAColumnNameThatKeepsTheNameAtSixtyCharactersIsUsedAsIs(): void
    {
        $column = str_repeat('c', 49); // 'qb_entries_' is eleven characters
        $declaration = Declaration::fromEntry(['table' => 'entries', 'columns' => [$column]]);

        $this->assertSame('qb_entries_' . $column, $declaration->name());
        $this->assertSame(60, strlen($declaration->name()));
    }

    public function testAColumnNameThatTakesTheNamePastSixtyCharactersIsHashed(): void
    {
        $column = str_repeat('c', 50);
        $declaration = Declaration::fromEntry(['table' => 'entries', 'columns' => [$column]]);

        // sha1 of the full definition, 'entries (ccc…c)', computed outside PHP.
        $this->assertSame('qb_entries_he3d49e2caf', $declaration->name());
        $this->assertLessThanOrEqual(63, strlen($declaration->name()));
    }

    public function testTheSameEntryAlwaysDerivesTheSameName(): void
    {
        $entry = ['table' => 'entries', 'columns' => [str_repeat('c', 50)]];

        $this->assertSame(Declaration::fromEntry($entry)->name(), Declaration::fromEntry($entry)->name());
    }

    // -------------------------------------------------------------------------

    private function assertRefused(string $reason, mixed $entry): void
    {
        try {
            Declaration::fromEntry($entry);
        } catch (InvalidArgumentException $e) {
            $this->assertSame($reason, $e->getMessage());

            return;
        }

        $this->fail('The entry was accepted: ' . json_encode($entry));
    }
}
