<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use PHPUnit\Framework\TestCase;
use F4\DB;

/**
 * Proves identifiers and parameters are rendered by the query's ACTIVE adapter, so useAdapter()
 * after construction updates both quoting and placeholder enumeration.
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
            'SELECT * FROM [t] WHERE [t].[col] = ? GROUP BY ([gcol])',
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

    public function testActiveAdapterEnumeratesNestedAndExpandedParameters(): void
    {
        $query = DB::select()
            ->from('outer')
            ->where([
                'status' => ['active', 'pending'],
                'child_id' => DB::select('id')
                    ->from('inner')
                    ->where(['enabled' => true]),
            ])
            ->useAdapter(new BracketMockAdapter());

        $preparedStatement = $query->getPreparedStatement();

        $this->assertSame(
            'SELECT * FROM [outer] WHERE [status] IN (?,?) AND [child_id] = (SELECT [id] FROM [inner] WHERE [enabled] = ?)',
            $preparedStatement->query,
        );
        $this->assertSame(['active', 'pending', true], $preparedStatement->parameters);
    }

    public function testExplicitEnumeratorOverridesActiveAdapterEnumerator(): void
    {
        $query = DB::select()
            ->from('t')
            ->where(['a' => 1, 'b' => [2, 3]])
            ->useAdapter(new BracketMockAdapter());

        $preparedStatement = $query->getPreparedStatement(
            static fn (int $index): string => ":parameter_{$index}",
        );

        $this->assertSame(
            'SELECT * FROM [t] WHERE [a] = :parameter_1 AND [b] IN (:parameter_2,:parameter_3)',
            $preparedStatement->query,
        );
        $this->assertSame([1, 2, 3], $preparedStatement->parameters);
    }
}
