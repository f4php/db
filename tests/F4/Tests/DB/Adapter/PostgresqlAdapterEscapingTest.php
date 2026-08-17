<?php

declare(strict_types=1);

namespace F4\Tests\DB\Adapter;

use ErrorException;
use F4\DB\Adapter\PostgresqlAdapter;
use PHPUnit\Framework\TestCase;

final class PostgresqlAdapterEscapingTest extends TestCase
{
    public function testAcceptsSuccessfullyEscapedLiteral(): void
    {
        $this->assertSame("'value'", TestablePostgresqlEscapingAdapter::exposeEscapedLiteral("'value'"));
    }

    public function testThrowsDescriptiveExceptionWhenLiteralEscapingFails(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Failed to escape PostgreSQL literal');
        $this->expectExceptionCode(500);

        TestablePostgresqlEscapingAdapter::exposeEscapedLiteral(false);
    }
}

final class TestablePostgresqlEscapingAdapter extends PostgresqlAdapter
{
    public static function exposeEscapedLiteral(string|false $escapedLiteral): string
    {
        return parent::requireEscapedLiteral($escapedLiteral);
    }
}
