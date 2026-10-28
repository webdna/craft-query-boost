<?php

namespace webdna\queryboost\tests\unit;

use Codeception\Test\Unit;
use craft\db\Query;
use craft\db\Table;
use webdna\queryboost\QueryRewriter;

/**
 * BR-14, the index-aware guard on the tiebreaker. On top of the first guard — every sort column on
 * the element table — some index on that table must list the sort's columns other than the
 * tiebreaker, in the sort's order, as a contiguous run anywhere in the index. Without one the
 * subquery is left exactly as Craft built it, so its SQL is byte-identical (AC-8, TS-8 step 2).
 *
 * The rewriter is handed the table's column indexes as recorded lists, each index its columns in
 * order, so nothing here reads a database (spec §7).
 */
class GuardTest extends Unit
{
    /** Craft's `users` table as its index reader lists it: nothing on `lastName` (TS-8 step 2). */
    private const USERS_INDEXES = [['active'], ['locked'], ['pending'], ['suspended'], ['verificationCode'], ['email'], ['username']];

    /** The built-in `qb_entries_sectionid_postdate` beside two of Craft's own single-column indexes. */
    private const ENTRIES_INDEXES = [['sectionId'], ['typeId'], ['sectionId', 'postDate']];

    // Which sorts an index serves
    // -------------------------------------------------------------------------

    public function testTheSortStaysWhenNoIndexListsItsColumns(): void
    {
        $orderBy = ['users.lastName' => SORT_ASC, 'elements.id' => SORT_ASC];
        $query = $this->subQuery('users', Table::USERS, $orderBy);

        $alias = QueryRewriter::apply($query, true, false, fn(): array => self::USERS_INDEXES);

        $this->assertSame('users', $alias);
        $this->assertSame($orderBy, $query->orderBy);
        $this->assertSame(['elements' => Table::ELEMENTS], $query->from);
        $this->assertSame([self::elementSitesJoin(), self::elementTableJoin('users', Table::USERS)], $query->join);
    }

    /**
     * The built-in `(sectionId, postDate)` serves a bare `postDate` sort: the run may start anywhere
     * in the index, and InnoDB ends every secondary index with the primary key, so the tiebreaker
     * needs no column of its own.
     */
    public function testTheSortIsRewrittenWhenAnIndexListsItsColumns(): void
    {
        $query = $this->entriesSubQuery(['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC]);

        QueryRewriter::apply($query, true, false, fn(): array => self::ENTRIES_INDEXES);

        $this->assertSame(['entries.postDate' => SORT_DESC, 'entries.id' => SORT_DESC], $query->orderBy);
    }

    public function testSeveralSortColumnsAreServedByAnIndexListingThemTogether(): void
    {
        $query = $this->entriesSubQuery(['entries.sectionId' => SORT_ASC, 'entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC]);

        QueryRewriter::apply($query, true, false, fn(): array => self::ENTRIES_INDEXES);

        $this->assertSame(['entries.sectionId' => SORT_ASC, 'entries.postDate' => SORT_DESC, 'entries.id' => SORT_DESC], $query->orderBy);
    }

    public function testTheColumnsMustAppearInTheSortsOrder(): void
    {
        $orderBy = ['entries.postDate' => SORT_DESC, 'entries.sectionId' => SORT_ASC, 'elements.id' => SORT_DESC];
        $query = $this->entriesSubQuery($orderBy);

        QueryRewriter::apply($query, true, false, fn(): array => self::ENTRIES_INDEXES);

        $this->assertSame($orderBy, $query->orderBy);
    }

    public function testTheRunMustBeContiguous(): void
    {
        $orderBy = ['entries.sectionId' => SORT_ASC, 'entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = $this->entriesSubQuery($orderBy);

        QueryRewriter::apply($query, true, false, fn(): array => [['sectionId', 'typeId', 'postDate']]);

        $this->assertSame($orderBy, $query->orderBy);
    }

    public function testSingleColumnIndexesDoNotCombineToServeASort(): void
    {
        $orderBy = ['entries.sectionId' => SORT_ASC, 'entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = $this->entriesSubQuery($orderBy);

        QueryRewriter::apply($query, true, false, fn(): array => [['sectionId'], ['postDate']]);

        $this->assertSame($orderBy, $query->orderBy);
    }

    public function testATableWithNoIndexesServesNothing(): void
    {
        $orderBy = ['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = $this->entriesSubQuery($orderBy);

        QueryRewriter::apply($query, true, false, fn(): array => []);

        $this->assertSame($orderBy, $query->orderBy);
    }

    /**
     * With no serving index the straight join waits too, because the sort still ends on
     * `elements.id`: the whole subquery is Craft's, byte for byte.
     */
    public function testStraightJoinWaitsWhenNoIndexServesTheTiebreaker(): void
    {
        $orderBy = ['users.lastName' => SORT_ASC, 'elements.id' => SORT_ASC];
        $query = $this->subQuery('users', Table::USERS, $orderBy);

        QueryRewriter::apply($query, true, true, fn(): array => self::USERS_INDEXES);

        $this->assertSame($orderBy, $query->orderBy);
        $this->assertSame(['elements' => Table::ELEMENTS], $query->from);
        $this->assertSame([self::elementSitesJoin(), self::elementTableJoin('users', Table::USERS)], $query->join);
    }

    // When the index list is read
    // -------------------------------------------------------------------------

    public function testTheIndexesAreReadOnceForThePrefixedElementTable(): void
    {
        $asked = [];
        $query = $this->subQuery('users', Table::USERS, ['users.lastName' => SORT_ASC, 'elements.id' => SORT_ASC]);

        QueryRewriter::apply($query, true, false, function(string $table) use (&$asked): array {
            $asked[] = $table;

            return self::USERS_INDEXES;
        });

        $this->assertSame([Table::USERS], $asked);
    }

    /** Heathfield's Address query: the first guard refuses it, and the schema is never asked. */
    public function testTheIndexesAreNotReadForASortTheFirstGuardRefuses(): void
    {
        $orderBy = ['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = $this->subQuery('addresses', Table::ADDRESSES, $orderBy);

        QueryRewriter::apply($query, true, false, $this->neverAsked());

        $this->assertSame($orderBy, $query->orderBy);
    }

    public function testTheIndexesAreNotReadWithTheTiebreakerOff(): void
    {
        $orderBy = ['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = $this->entriesSubQuery($orderBy);

        QueryRewriter::apply($query, false, true, $this->neverAsked());

        $this->assertSame($orderBy, $query->orderBy);
    }

    // Fixtures
    // -------------------------------------------------------------------------

    private function neverAsked(): callable
    {
        return fn(string $table): array => $this->fail("The index list was read for $table");
    }

    /**
     * @param array<string,int> $orderBy
     * @return Query<array-key,mixed>
     */
    private function entriesSubQuery(array $orderBy): Query
    {
        return $this->subQuery('entries', Table::ENTRIES, $orderBy);
    }

    /**
     * Craft 5's element subquery: `prepare()`'s FROM and `elements_sites` join, then
     * `joinElementTable()`'s join on the element table.
     *
     * @param array<string,int> $orderBy
     * @return Query<array-key,mixed>
     */
    private function subQuery(string $alias, string $table, array $orderBy): Query
    {
        return (new Query())
            ->select(['elementsId' => 'elements.id', 'elementsSitesId' => 'elements_sites.id'])
            ->from(['elements' => Table::ELEMENTS])
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]]')
            ->innerJoin([$alias => $table], "[[$alias.id]] = [[elements.id]]")
            ->orderBy($orderBy);
    }

    /**
     * @return array{string, array<string,string>, string}
     */
    private static function elementSitesJoin(): array
    {
        return ['INNER JOIN', ['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]]'];
    }

    /**
     * @return array{string, array<string,string>, string}
     */
    private static function elementTableJoin(string $alias, string $table): array
    {
        return ['INNER JOIN', [$alias => $table], "[[$alias.id]] = [[elements.id]]"];
    }
}
