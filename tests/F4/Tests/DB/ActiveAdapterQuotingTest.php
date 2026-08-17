<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use PHPUnit\Framework\TestCase;
use F4\DB;

/**
 * Proves audit finding #2 is fixed: identifiers are quoted by the query's ACTIVE adapter at render time,
 * so useAdapter() after construction is honored and quoting is not frozen at reference-construction time.
 */
final class ActiveAdapterQuotingTest extends TestCase
{
    public function testActiveAdapterIsUsedAtRender(): void
    {
        // Same query object, re-rendered under a different adapter, re-quotes identifiers.
        $query = DB::select()->from('t')->where(['t.col' => 5])->groupBy('gcol');

        $this->assertSame(
            'SELECT * FROM "t" WHERE "t"."col" = $1 GROUP BY ("gcol")',
            $query->getPreparedStatement()->query,
        );

        $query->useAdapter(new BracketMockAdapter());

        // Bracket quoting now applies everywhere, including the Parenthesize-backed GROUP BY.
        $this->assertSame(
            'SELECT * FROM [t] WHERE [t].[col] = $1 GROUP BY ([gcol])',
            $query->getPreparedStatement()->query,
        );
    }

    public function testSelectAliasAndCompositeUseActiveAdapter(): void
    {
        $query = DB::select(['t.fieldA', 't.fieldB b'])->from('t')->useAdapter(new BracketMockAdapter());
        $this->assertSame(
            'SELECT [t].[fieldA], [t].[fieldB] AS [b] FROM [t]',
            $query->getPreparedStatement()->query,
        );
    }

    public function testNestedSubqueryQuotesUnderOuterActiveAdapter(): void
    {
        // The inner subquery is rendered while unpacking {#::#}, threading the outer active adapter.
        $query = DB::select()->from('t')->where(['col' => DB::select('x')->from('inner')])
            ->useAdapter(new BracketMockAdapter());
        $this->assertSame(
            'SELECT * FROM [t] WHERE [col] = (SELECT [x] FROM [inner])',
            $query->getPreparedStatement()->query,
        );
    }
}
