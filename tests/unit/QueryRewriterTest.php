<?php

namespace webdna\queryboost\tests\unit;

use Codeception\Test\Unit;
use craft\db\Query;
use craft\db\Table;
use webdna\queryboost\QueryRewriter;

/**
 * The rewriter over a bare `craft\db\Query` shaped as Craft 5's `ElementQuery::prepare()` shapes its
 * subquery: `FROM elements JOIN elements_sites JOIN <element table>`, the element table joined on
 * `[[<alias>.id]] = [[elements.id]]`, the sort ending in an `elements.id` tiebreaker.
 *
 * The behaviour before the index-aware guard, pinned (spec §7, the first test in the harness) and
 * kept by handing the rewriter a list in which an index serves every sort used here; which sorts an
 * index serves is `GuardTest`'s. Nothing here touches the database.
 */
class QueryRewriterTest extends Unit
{
    /** The element table's column indexes: the built-in beside Craft's own `typeId` index */
    private const INDEXES = [['sectionId', 'postDate'], ['typeId']];

    private const ELEMENT_SITES_JOIN = [
        'INNER JOIN',
        ['elements_sites' => Table::ELEMENTS_SITES],
        '[[elements_sites.elementId]] = [[elements.id]]',
    ];

    private const ENTRIES_JOIN = [
        'INNER JOIN',
        ['entries' => Table::ENTRIES],
        '[[entries.id]] = [[elements.id]]',
    ];

    private const STRUCTURE_JOIN = [
        'LEFT JOIN',
        ['structureelements' => Table::STRUCTUREELEMENTS],
        '[[structureelements.elementId]] = [[elements.id]]',
    ];

    // The tiebreaker
    // -------------------------------------------------------------------------

    public function testTiebreakerMovesOntoTheElementTable(): void
    {
        $query = $this->entriesSubQuery(['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC]);

        $alias = QueryRewriter::apply($query, true, false, self::indexes());

        $this->assertSame('entries', $alias);
        $this->assertSame(['entries.postDate' => SORT_DESC, 'entries.id' => SORT_DESC], $query->orderBy);
        $this->assertSame(['elements' => Table::ELEMENTS], $query->from);
        $this->assertSame([self::ELEMENT_SITES_JOIN, self::ENTRIES_JOIN], $query->join);
    }

    public function testTiebreakerKeepsTheDirectionOfEachColumn(): void
    {
        $query = $this->entriesSubQuery(['entries.typeId' => SORT_ASC, 'elements.id' => SORT_ASC]);

        QueryRewriter::apply($query, true, false, self::indexes());

        $this->assertSame(['entries.typeId' => SORT_ASC, 'entries.id' => SORT_ASC], $query->orderBy);
    }

    /**
     * Heathfield's Address query: led by an `elements` column, already served by `elements`' own
     * indexes. Moving the tiebreaker would split the sort across two tables (11 ms to 136 ms).
     */
    public function testTiebreakerLeavesASortLedByAnElementsColumn(): void
    {
        $orderBy = ['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = $this->subQuery('addresses', Table::ADDRESSES, $orderBy);

        $alias = QueryRewriter::apply($query, true, false, self::indexes());

        $this->assertSame('addresses', $alias);
        $this->assertSame($orderBy, $query->orderBy);
    }

    public function testTiebreakerLeavesASortThatSpansTables(): void
    {
        $orderBy = ['entries.postDate' => SORT_DESC, 'elements_sites.slug' => SORT_ASC, 'elements.id' => SORT_DESC];
        $query = $this->entriesSubQuery($orderBy);

        QueryRewriter::apply($query, true, false, self::indexes());

        $this->assertSame($orderBy, $query->orderBy);
    }

    public function testTiebreakerLeavesASortByIdAlone(): void
    {
        $query = $this->entriesSubQuery(['elements.id' => SORT_DESC]);

        QueryRewriter::apply($query, true, false, self::indexes());

        $this->assertSame(['elements.id' => SORT_DESC], $query->orderBy);
    }

    public function testTiebreakerLeavesASortWithNoTiebreaker(): void
    {
        $query = $this->entriesSubQuery(['entries.postDate' => SORT_DESC]);

        QueryRewriter::apply($query, true, false, self::indexes());

        $this->assertSame(['entries.postDate' => SORT_DESC], $query->orderBy);
    }

    public function testTiebreakerOffLeavesTheSortAlone(): void
    {
        $orderBy = ['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = $this->entriesSubQuery($orderBy);

        $alias = QueryRewriter::apply($query, false, false, self::indexes());

        $this->assertSame('entries', $alias);
        $this->assertSame($orderBy, $query->orderBy);
        $this->assertSame(['elements' => Table::ELEMENTS], $query->from);
        $this->assertSame([self::ELEMENT_SITES_JOIN, self::ENTRIES_JOIN], $query->join);
    }

    // The straight join
    // -------------------------------------------------------------------------

    public function testStraightJoinStartsFromTheElementTableAndKeepsTheOtherJoins(): void
    {
        $query = $this->entriesSubQuery(['entries.postDate' => SORT_DESC]);
        $query->join[] = self::STRUCTURE_JOIN;

        $alias = QueryRewriter::apply($query, false, true, self::indexes());

        $this->assertSame('entries', $alias);
        $this->assertSame(['entries' => Table::ENTRIES], $query->from);
        $this->assertSame([
            ['STRAIGHT_JOIN', ['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]'],
            self::ELEMENT_SITES_JOIN,
            self::STRUCTURE_JOIN,
        ], $query->join);
        $this->assertSame(['entries.postDate' => SORT_DESC], $query->orderBy);
    }

    public function testStraightJoinAppliesToAnUnsortedQuery(): void
    {
        $query = $this->entriesSubQuery(null);

        QueryRewriter::apply($query, false, true, self::indexes());

        $this->assertSame(['entries' => Table::ENTRIES], $query->from);
        $this->assertSame('STRAIGHT_JOIN', $query->join[0][0]);
    }

    /**
     * With the tiebreaker off the sort still ends on `elements.id`, so starting from the element
     * table could not serve it: the join order is left to the optimizer.
     */
    public function testStraightJoinWaitsWhileTheSortTouchesElements(): void
    {
        $query = $this->entriesSubQuery(['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC]);

        QueryRewriter::apply($query, false, true, self::indexes());

        $this->assertSame(['elements' => Table::ELEMENTS], $query->from);
        $this->assertSame([self::ELEMENT_SITES_JOIN, self::ENTRIES_JOIN], $query->join);
    }

    public function testTiebreakerThenStraightJoinRewriteTogether(): void
    {
        $query = $this->entriesSubQuery(['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC]);

        QueryRewriter::apply($query, true, true, self::indexes());

        $this->assertSame(['entries.postDate' => SORT_DESC, 'entries.id' => SORT_DESC], $query->orderBy);
        $this->assertSame(['entries' => Table::ENTRIES], $query->from);
        $this->assertSame([
            ['STRAIGHT_JOIN', ['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]'],
            self::ELEMENT_SITES_JOIN,
        ], $query->join);
    }

    // Queries the rewriter must not touch
    // -------------------------------------------------------------------------

    public function testAQueryWithNoElementTableJoinIsLeftAlone(): void
    {
        $orderBy = ['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = (new Query())
            ->from(['elements' => Table::ELEMENTS])
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]]')
            ->orderBy($orderBy);

        $alias = QueryRewriter::apply($query, true, true, self::indexes());

        $this->assertNull($alias);
        $this->assertSame($orderBy, $query->orderBy);
        $this->assertSame(['elements' => Table::ELEMENTS], $query->from);
        $this->assertSame([self::ELEMENT_SITES_JOIN], $query->join);
    }

    public function testAQueryNotStartingFromElementsIsLeftAlone(): void
    {
        $orderBy = ['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC];
        $query = (new Query())
            ->from(['entries' => Table::ENTRIES])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[entries.id]] = [[elements.id]]')
            ->orderBy($orderBy);

        $alias = QueryRewriter::apply($query, true, true, self::indexes());

        $this->assertNull($alias);
        $this->assertSame($orderBy, $query->orderBy);
        $this->assertSame(['entries' => Table::ENTRIES], $query->from);
    }

    public function testAJoinOnAnotherConditionIsNotTheElementTable(): void
    {
        $orderBy = ['relations.sortOrder' => SORT_ASC, 'elements.id' => SORT_DESC];
        $query = (new Query())
            ->from(['elements' => Table::ELEMENTS])
            ->innerJoin(['relations' => Table::RELATIONS], '[[relations.targetId]] = [[elements.id]]')
            ->orderBy($orderBy);

        $alias = QueryRewriter::apply($query, true, true, self::indexes());

        $this->assertNull($alias);
        $this->assertSame($orderBy, $query->orderBy);
    }

    // Fixtures
    // -------------------------------------------------------------------------

    private static function indexes(): callable
    {
        return fn(string $table): array => self::INDEXES;
    }

    /**
     * @param array<string,int>|null $orderBy
     * @return Query<array-key,mixed>
     */
    private function entriesSubQuery(?array $orderBy): Query
    {
        return $this->subQuery('entries', Table::ENTRIES, $orderBy);
    }

    /**
     * Craft 5's element subquery: `prepare()`'s FROM and `elements_sites` join, then
     * `joinElementTable()`'s join on the element table.
     *
     * @param array<string,int>|null $orderBy
     * @return Query<array-key,mixed>
     */
    private function subQuery(string $alias, string $table, ?array $orderBy): Query
    {
        $query = (new Query())
            ->select(['elementsId' => 'elements.id', 'elementsSitesId' => 'elements_sites.id'])
            ->from(['elements' => Table::ELEMENTS])
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]]')
            ->innerJoin([$alias => $table], "[[$alias.id]] = [[elements.id]]");

        if ($orderBy !== null) {
            $query->orderBy($orderBy);
        }

        return $query;
    }
}
