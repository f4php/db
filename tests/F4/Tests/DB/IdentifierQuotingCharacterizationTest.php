<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use PHPUnit\Framework\TestCase;
use F4\DB;

/**
 * Characterization tests: pin the CURRENT rendered SQL for every identifier /
 * raw-key shape touched by the render-time-identifier-quoting refactor. These must
 * stay byte-identical after the refactor (identifiers are still quoted the same
 * way, only the timing/adapter source changes). Landed BEFORE the refactor as a
 * guard rail.
 */
final class IdentifierQuotingCharacterizationTest extends TestCase
{
    public function testWhereScalarIdentifier(): void
    {
        $ps = DB::select()->from('t')->where(['col' => 7])->getPreparedStatement();
        $this->assertSame('SELECT * FROM "t" WHERE "col" = $1', $ps->query);
        $this->assertSame([7], $ps->parameters);
    }

    public function testWhereQualifiedIdentifier(): void
    {
        $ps = DB::select()->from('t')->where(['t.col' => 7])->getPreparedStatement();
        $this->assertSame('SELECT * FROM "t" WHERE "t"."col" = $1', $ps->query);
        $this->assertSame([7], $ps->parameters);
    }

    public function testWhereRawKeyTemplate(): void
    {
        // The WHERE raw-key contract: the key is itself a raw predicate template.
        $ps = DB::select()->from('t')->where(['"fieldE" > {#}' => 7])->getPreparedStatement();
        $this->assertSame('SELECT * FROM "t" WHERE "fieldE" > $1', $ps->query);
        $this->assertSame([7], $ps->parameters);
    }

    public function testWhereInArray(): void
    {
        $ps = DB::select()->from('t')->where(['col' => [1, 2, 3]])->getPreparedStatement();
        $this->assertSame('SELECT * FROM "t" WHERE "col" IN ($1,$2,$3)', $ps->query);
        $this->assertSame([1, 2, 3], $ps->parameters);
    }

    public function testWhereIsNull(): void
    {
        $ps = DB::select()->from('t')->where(['col' => null])->getPreparedStatement();
        $this->assertSame('SELECT * FROM "t" WHERE "col" IS NULL', $ps->query);
        $this->assertSame([], $ps->parameters);
    }

    public function testWhereSubqueryValue(): void
    {
        $ps = DB::select()->from('t')->where(['col' => DB::select(1)])->getPreparedStatement();
        $this->assertSame('SELECT * FROM "t" WHERE "col" = (SELECT 1)', $ps->query);
        $this->assertSame([], $ps->parameters);
    }

    public function testAssignmentSet(): void
    {
        $ps = DB::update('t')->set(['col' => 5])->getPreparedStatement();
        $this->assertSame('UPDATE "t" SET "col" = $1', $ps->query);
        $this->assertSame([5], $ps->parameters);
    }

    public function testOrderByAssociative(): void
    {
        $ps = DB::select()->from('t')->orderBy(['col' => 'ASC'])->getPreparedStatement();
        $this->assertSame('SELECT * FROM "t" ORDER BY "col" ASC', $ps->query);
    }

    public function testGroupBy(): void
    {
        $ps = DB::select()->from('t')->groupBy('col')->getPreparedStatement();
        $this->assertSame('SELECT * FROM "t" GROUP BY ("col")', $ps->query);
    }

    public function testSelectExpressionShapes(): void
    {
        // Pins the SELECT identifier/alias/composite shapes from testSelect.
        $ps = DB::select(['t.fieldA', 't.fieldB b', 'fieldC'])->getPreparedStatement();
        $this->assertSame('SELECT "t"."fieldA", "t"."fieldB" AS "b", "fieldC"', $ps->query);
    }
}
