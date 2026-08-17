<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use DateTimeImmutable;
use F4\DB;
use F4\DB\Adapter\PostgresqlAdapter;
use F4\DB\Fragment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DateTimeParameterTest extends TestCase
{
    public function testAcceptsAndRendersDateTimeInterfaceValuesConsistently(): void
    {
        $date = new DateTimeImmutable('2026-08-17 12:34:56.123456+03:00');
        $query = DB::raw([
            'SELECT {#}, {#,...#}' => [$date, [$date, $date]],
        ]);

        $preparedStatement = $query->getPreparedStatement();
        $this->assertSame('SELECT $1, $2,$3', $preparedStatement->query);
        $this->assertContainsOnlyInstancesOf(DateTimeImmutable::class, $preparedStatement->parameters);
        $this->assertSame(
            "SELECT '2026-08-17T12:34:56.123456+03:00', '2026-08-17T12:34:56.123456+03:00','2026-08-17T12:34:56.123456+03:00'",
            $query->asSQL(),
        );
    }

    public function testPostgresqlExecutionNormalizesDateTimeInterface(): void
    {
        $date = new DateTimeImmutable('2026-08-17 12:34:56.654321-04:30');

        $this->assertSame(
            '2026-08-17T12:34:56.654321-04:30',
            TestablePostgresqlDateTimeAdapter::exposeNormalizeParameter($date),
        );
    }

    public function testRejectsUnsupportedScalarPlaceholderObject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Fragment('{#}', [new stdClass()]);
    }

    public function testRejectsUnsupportedObjectInsideCommaPlaceholder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Fragment('{#,...#}', [[new stdClass()]]);
    }
}

final class TestablePostgresqlDateTimeAdapter extends PostgresqlAdapter
{
    public static function exposeNormalizeParameter(mixed $parameter): mixed
    {
        return parent::normalizeParameter($parameter);
    }
}
