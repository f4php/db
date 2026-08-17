<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB;
use F4\DB\AnyConditionCollection;
use F4\DB\NoneConditionCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConditionCollectionTest extends TestCase
{
    public function testRejectsEmptyInListForIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN condition values must not be empty');

        DB::select()->where(['id' => []]);
    }

    public function testRejectsEmptyInListForQualifiedIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN condition values must not be empty');

        DB::select()->where(['table.id' => []]);
    }

    public function testRejectsEmptyInListInAnyConditionCollection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN condition values must not be empty');

        AnyConditionCollection::of(['id' => []]);
    }

    public function testRejectsEmptyInListInNoneConditionCollection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN condition values must not be empty');

        NoneConditionCollection::of(['id' => []]);
    }

    public function testAcceptsNonEmptyInList(): void
    {
        $statement = DB::select()->where(['id' => [1, 2]])->getPreparedStatement();

        $this->assertSame('SELECT * WHERE "id" IN ($1,$2)', $statement->query);
        $this->assertSame([1, 2], $statement->parameters);
    }
}
