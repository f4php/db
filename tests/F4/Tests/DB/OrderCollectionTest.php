<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB;
use F4\DB\Fragment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OrderCollectionTest extends TestCase
{
    public function testScalarIdentifiersAreEscaped(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy('level', 'users.name')
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM "users" ORDER BY "level", "users"."name"', $statement->query);
        $this->assertSame([], $statement->parameters);
    }

    public function testCustomSqlStringIsUsedAsIs(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy('"created_at" DESC NULLS LAST')
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM "users" ORDER BY "created_at" DESC NULLS LAST', $statement->query);
    }

    public function testAssociativeDirectionEscapesQualifiedIdentifier(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy(['users.name' => 'desc '])
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM "users" ORDER BY "users"."name" DESC', $statement->query);
    }

    public function testCustomSqlTemplateBindsScalarParameter(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy(['CASE WHEN "priority" = {#} THEN 0 ELSE 1 END' => 'high'])
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM "users" ORDER BY CASE WHEN "priority" = $1 THEN 0 ELSE 1 END', $statement->query);
        $this->assertSame(['high'], $statement->parameters);
    }

    public function testCustomSqlTemplateBindsMultipleParameters(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy(['CASE WHEN score BETWEEN {#} AND {#} THEN {#} ELSE {#} END' => [10, 20, 0, 1]])
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM "users" ORDER BY CASE WHEN score BETWEEN $1 AND $2 THEN $3 ELSE $4 END', $statement->query);
        $this->assertSame([10, 20, 0, 1], $statement->parameters);
    }

    public function testCustomSqlTemplateBindsListParameter(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy(['array_position(ARRAY[{#,...#}], status)' => ['high', 'normal']])
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM "users" ORDER BY array_position(ARRAY[$1,$2], status)', $statement->query);
        $this->assertSame(['high', 'normal'], $statement->parameters);
    }

    public function testCustomSqlTemplateSupportsSubqueryPlaceholder(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy(['({#::#}) DESC' => DB::select('rank')->from('scores')])
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM "users" ORDER BY (SELECT "rank" FROM "scores") DESC', $statement->query);
        $this->assertSame([], $statement->parameters);
    }

    public function testExplicitFragmentRemainsSupported(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy(new Fragment('RANDOM()'))
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM "users" ORDER BY RANDOM()', $statement->query);
    }

    public function testRecognizedIdentifierUsesActiveAdapter(): void
    {
        $statement = DB::select()
            ->from('users')
            ->orderBy('users.name')
            ->useAdapter(new BracketMockAdapter())
            ->getPreparedStatement();

        $this->assertSame('SELECT * FROM [users] ORDER BY [users].[name]', $statement->query);
    }

    public function testCustomSqlTemplateRequiresMatchingParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter mismatch, expected: 1, received: 0');

        DB::select()->orderBy('COALESCE(score, {#})');
    }
}
