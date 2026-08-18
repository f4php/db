<?php

declare(strict_types=1);

namespace F4\Tests\DB\Adapter;

use F4\DB\Adapter\PostgresqlAdapter;
use F4\DB\Exception\InvalidResultValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresqlAdapterTypeCastingTest extends TestCase
{
    public function testCastsPostgresqlFloatingPointTypeNames(): void
    {
        $adapter = new TestablePostgresqlTypeCastingAdapter();

        foreach (['real', 'double precision', 'float4', 'float8'] as $type) {
            $this->assertSame(1.25, $adapter->castResultValue('1.25', $type));
            $this->assertSame(
                [1.25, [2.5]],
                $adapter->castResultValue(['1.25', ['2.5']], $type),
            );
        }
    }

    public function testCastsExpectedPostgresqlBooleanValues(): void
    {
        $adapter = new TestablePostgresqlTypeCastingAdapter();

        $this->assertTrue($adapter->castResultValue('t', 'boolean'));
        $this->assertFalse($adapter->castResultValue('f', 'bool'));
        $this->assertNull($adapter->castResultValue(null, 'boolean'));
        $this->assertSame(
            [true, [false, null]],
            $adapter->castResultValue(['t', ['f', null]], 'boolean'),
        );
    }

    #[DataProvider('unexpectedBooleanValues')]
    public function testRejectsUnexpectedPostgresqlBooleanValue(mixed $value): void
    {
        $this->expectException(InvalidResultValueException::class);
        $this->expectExceptionMessage('Unexpected PostgreSQL boolean representation');

        (new TestablePostgresqlTypeCastingAdapter())->castResultValue($value, 'boolean');
    }

    public static function unexpectedBooleanValues(): iterable
    {
        yield 'numeric true string' => ['1'];
        yield 'numeric false string' => ['0'];
        yield 'empty string' => [''];
        yield 'native boolean' => [true];
    }

    public function testRejectsUnexpectedNestedPostgresqlBooleanValue(): void
    {
        $this->expectException(InvalidResultValueException::class);

        (new TestablePostgresqlTypeCastingAdapter())->castResultValue(['t', ['unexpected']], 'bool');
    }

    public function testParsesAndCastsIntegerArrayColumn(): void
    {
        $adapter = new TestablePostgresqlTypeCastingAdapter();

        $this->assertSame([1, 2, 3], $adapter->castResultValue('{1,2,3}', '_int4'));
    }

    public function testParsesAndCastsBooleanArrayColumnWithNull(): void
    {
        $adapter = new TestablePostgresqlTypeCastingAdapter();

        $this->assertSame([true, false, null], $adapter->castResultValue('{t,f,NULL}', '_bool'));
    }

    public function testParsesAndCastsFloatArrayColumn(): void
    {
        $adapter = new TestablePostgresqlTypeCastingAdapter();

        $this->assertSame([1.25, 2.5], $adapter->castResultValue('{1.25,2.5}', '_float8'));
    }

    public function testParsesTextArrayColumnPreservingDelimiters(): void
    {
        $adapter = new TestablePostgresqlTypeCastingAdapter();

        $this->assertSame(['a,b', 'c'], $adapter->castResultValue('{"a,b",c}', '_text'));
    }

    public function testParsesNestedIntegerArrayColumn(): void
    {
        $adapter = new TestablePostgresqlTypeCastingAdapter();

        $this->assertSame([[1, 2], [3, 4]], $adapter->castResultValue('{{1,2},{3,4}}', '_int4'));
    }

    public function testParsesBoxArrayColumnWithSemicolonDelimiter(): void
    {
        $adapter = new TestablePostgresqlTypeCastingAdapter();

        // box[] uses ';' as its element delimiter; the commas inside each box are literal.
        $this->assertSame(
            ['(1,1),(0,0)', '(2,2),(1,1)'],
            $adapter->castResultValue('{(1,1),(0,0);(2,2),(1,1)}', '_box'),
        );
    }

    public function testWrapsMalformedArrayLiteralAsInvalidResultValue(): void
    {
        $this->expectException(InvalidResultValueException::class);
        $this->expectExceptionMessage('Malformed PostgreSQL array literal');

        (new TestablePostgresqlTypeCastingAdapter())->castResultValue('{1,2', '_int4');
    }
}

final class TestablePostgresqlTypeCastingAdapter extends PostgresqlAdapter
{
    public function castResultValue(mixed $value, string $type): mixed
    {
        return $this->castType($value, $type);
    }
}
