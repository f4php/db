<?php

declare(strict_types=1);

namespace F4\Tests\DB\Adapter;

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use F4\DB\Adapter\PostgresqlAdapter;

/**
 * Pins PostgresqlAdapter::getEscapedIdentifier as connectionless, encoding-validated identifier
 * quoting (audit #2 / #12). Every case here runs WITHOUT a database connection: the adapter is
 * constructed with an unreachable host and must still quote/validate purely in PHP.
 *
 * Config::DB_CHARSET is 'UTF8' under the test config (tests/F4/Config.php).
 */
final class PostgresqlAdapterIdentifierTest extends TestCase
{
    private function adapter(): PostgresqlAdapter
    {
        // Bogus connection string; if any code path tried to connect, these tests would fail/error.
        return new PostgresqlAdapter('host=127.0.0.1 port=1 dbname=none user=none password=none');
    }

    public function testQuotesSimpleIdentifierWithoutConnecting(): void
    {
        $this->assertSame('"col"', $this->adapter()->getEscapedIdentifier('col'));
    }

    public function testDoublesEmbeddedDoubleQuotes(): void
    {
        // Equivalent to pg_escape_identifier: wrap in "..." and double any embedded ".
        $this->assertSame('"table""name"', $this->adapter()->getEscapedIdentifier('table"name'));
    }

    public function testLeavesBackslashUntouched(): void
    {
        // Backslashes are not special inside a double-quoted identifier.
        $this->assertSame('"foo\\bar"', $this->adapter()->getEscapedIdentifier('foo\\bar'));
    }

    public function testQuotesMultibyteIdentifier(): void
    {
        $this->assertSame('"é中"', $this->adapter()->getEscapedIdentifier('é中'));
    }

    public function testRejectsEmptyIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->getEscapedIdentifier('');
    }

    public function testRejectsNulByte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->getEscapedIdentifier("a\0b");
    }

    public function testRejectsInvalidEncoding(): void
    {
        // Invalid UTF-8 under DB_CHARSET=UTF8 is rejected, mirroring libpq's client-encoding check.
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->getEscapedIdentifier("col_\xFF\xFE");
    }
}
