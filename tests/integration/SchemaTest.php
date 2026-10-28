<?php

namespace webdna\queryboost\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Table;
use webdna\queryboost\indexes\Schema;
use webdna\queryboost\migrations\Install;

/**
 * The schema reader against the compose MySQL: the owned-index catalogue, the table check and
 * Craft's served lookup (BR-4), and the trap the catalogue read exists for — Craft's MySQL index
 * reader cannot see an expression index, `information_schema.STATISTICS` can (B3).
 */
class SchemaTest extends Unit
{
    private const EXPRESSION_INDEX = 'qb_elements_sites_xtest000001';

    public function testTheOwnedIndexesAreTheBuiltInsAfterInstall(): void
    {
        $owned = (new Schema(Craft::$app->getDb()))->ownedIndexes();

        // Catalogue order is by table, so `craft_elements` comes before `craft_entries`.
        $this->assertEqualsCanonicalizing(array_keys(Install::INDEXES), array_keys($owned));
        foreach (Install::INDEXES as $name => [$table, $columns]) {
            $this->assertSame($name, $owned[$name]->name);
            $this->assertSame(Craft::$app->getDb()->getSchema()->getRawTableName($table), $owned[$name]->table);
            $this->assertSame($columns, $owned[$name]->columns);
            $this->assertNull($owned[$name]->expression);
        }
    }

    public function testTableExistsHonoursThePrefixPlaceholder(): void
    {
        $schema = new Schema(Craft::$app->getDb());

        $this->assertTrue($schema->tableExists(Table::ENTRIES));
        $this->assertFalse($schema->tableExists('{{%entrees}}'));
    }

    public function testFindIndexIsCraftsLookupOnTheColumnsInOrder(): void
    {
        $schema = new Schema(Craft::$app->getDb());

        $this->assertSame('qb_entries_sectionid_postdate', $schema->findIndex(Table::ENTRIES, ['sectionId', 'postDate']));
        $this->assertNull($schema->findIndex(Table::ENTRIES, ['postDate', 'sectionId']));
    }

    /**
     * BR-14's picture of an element table: each column index as its columns in index order, under
     * no name, so the guard can look for a contiguous run.
     */
    public function testColumnIndexesListEachIndexsColumnsInOrder(): void
    {
        $indexes = (new Schema(Craft::$app->getDb()))->columnIndexes(Table::ENTRIES);

        $this->assertContains(['sectionId', 'postDate'], $indexes);
        $this->assertNotContains(['postDate', 'sectionId'], $indexes);
        foreach ($indexes as $columns) {
            $this->assertNotSame([], $columns);
        }
    }

    public function testAnExpressionIndexIsInTheCatalogueThoughCraftsReaderCannotSeeIt(): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand(
            'CREATE INDEX ' . self::EXPRESSION_INDEX . ' ON {{%elements_sites}} ((CAST(content->>\'$."abc"\' AS CHAR(255))))',
        )->execute();

        try {
            $owned = (new Schema($db))->ownedIndexes();

            $this->assertArrayHasKey(self::EXPRESSION_INDEX, $owned);
            $this->assertSame($db->getSchema()->getRawTableName(Table::ELEMENTS_SITES), $owned[self::EXPRESSION_INDEX]->table);
            $this->assertSame([], $owned[self::EXPRESSION_INDEX]->columns);
            $this->assertStringContainsString('abc', (string)$owned[self::EXPRESSION_INDEX]->expression);

            $this->assertArrayNotHasKey(self::EXPRESSION_INDEX, $db->getSchema()->findIndexes(Table::ELEMENTS_SITES));
        } finally {
            $db->createCommand()->dropIndex(self::EXPRESSION_INDEX, Table::ELEMENTS_SITES)->execute();
        }
    }
}
