<?php

declare(strict_types=1);

namespace F4\Tests\DB\Adapter;

use F4\DB\Adapter\PostgresqlAdapter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PostgresqlAdapterConnectionStringTest extends TestCase
{
    public function testBuildsDefaultConnectionStringFromConfig(): void
    {
        $adapter = new TestablePostgresqlAdapter();

        $this->assertSame(
            "host='localhost' port='5432' dbname='' user='' password=''",
            $adapter->exposeConnectionString(),
        );
    }

    public function testEscapesMetacharactersWithoutChangingMultibyteCharacters(): void
    {
        $this->assertSame(
            "host='db.例.test' port='5432' dbname='данные\\'📦' user='用戶\\\\admin' password='päss\\'\\\\秘密🙂'",
            TestablePostgresqlAdapter::exposeBuildConnectionString(
                'db.例.test',
                '5432',
                "данные'📦",
                '用戶\\admin',
                "päss'\\秘密🙂",
                'UTF8',
            ),
        );
    }

    public function testEscapesUnixSocketPathAndOmitsPort(): void
    {
        $this->assertSame(
            "host='/var/run/postgresql\\'primary' dbname='app' user='user' password='secret'",
            TestablePostgresqlAdapter::exposeBuildConnectionString(
                "/var/run/postgresql'primary",
                '5432',
                'app',
                'user',
                'secret',
                'UTF8',
            ),
        );
    }

    public function testDoesNotEscapeBackslashByteInsideShiftJisCharacter(): void
    {
        // The Shift-JIS representation of 表 ends in byte 0x5C, the ASCII backslash byte.
        $multibyteCharacter = mb_convert_encoding('表', 'SJIS', 'UTF-8');

        $this->assertSame(
            $multibyteCharacter . '\\\\' . "\\'",
            TestablePostgresqlAdapter::exposeEscapeConnectionStringValue(
                $multibyteCharacter . "\\'",
                'SJIS',
            ),
        );
    }

    public function testRejectsInvalidConfiguredEncoding(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TestablePostgresqlAdapter::exposeEscapeConnectionStringValue("invalid_\xFF", 'UTF8');
    }
}

final class TestablePostgresqlAdapter extends PostgresqlAdapter
{
    public function exposeConnectionString(): ?string
    {
        return $this->connectionString;
    }

    public static function exposeBuildConnectionString(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
        string $encoding,
    ): string {
        return parent::buildConnectionString($host, $port, $database, $username, $password, $encoding);
    }

    public static function exposeEscapeConnectionStringValue(string $value, string $encoding): string
    {
        return parent::escapeConnectionStringValue($value, $encoding);
    }
}
