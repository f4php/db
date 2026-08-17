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
}

final class TestablePostgresqlTypeCastingAdapter extends PostgresqlAdapter
{
    public function castResultValue(mixed $value, string $type): mixed
    {
        return $this->castType($value, $type);
    }
}
