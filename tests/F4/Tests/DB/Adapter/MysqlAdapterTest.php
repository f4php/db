<?php

declare(strict_types=1);

namespace F4\Tests\DB\Adapter;

use DateTimeImmutable;
use F4\DB\Adapter\MysqlAdapter;
use F4\DB\Exception\{
    DuplicateColumnException,
    DuplicateRecordException,
    DuplicateTableException,
    Exception as DatabaseException,
    ParameterMismatchException,
    SyntaxErrorException,
    UnknownColumnException,
    UnknownFunctionException,
    UnknownTableException,
};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

#[RequiresPhpExtension('mysqli')]
final class MysqlAdapterTest extends TestCase
{
    public function testConstructionIsLazyAndUsesPositionalParameters(): void
    {
        $adapter = new TestableMysqlAdapter(
            "host='127.0.0.1' port='1' dbname='database' user='user' password='password'",
        );

        $this->assertSame('?', $adapter->enumerateParameters(42));
    }

    public function testBuildsDefaultConnectionStringFromConfig(): void
    {
        $adapter = new TestableMysqlAdapter();

        $this->assertSame(
            "host='localhost' port='5432' dbname='' user='' password=''",
            $adapter->exposeConnectionString(),
        );
    }

    public function testParsesConnectionString(): void
    {
        $adapter = new TestableMysqlAdapter();

        $this->assertSame(
            [
                'host' => 'db.example.com',
                'port' => 3307,
                'database' => 'application',
                'username' => 'app user',
                'password' => 'p@ss:word',
                'charset' => 'utf8mb4',
                'socket' => '/tmp/mysql.sock',
            ],
            $adapter->exposeConnectionOptions(
                "host='db.example.com' port='3307' dbname='application' "
                . "user='app user' password='p@ss:word' charset='utf8mb4' "
                . "socket='/tmp/mysql.sock'",
            ),
        );
    }

    public function testGeneratedConnectionStringRoundTripsEscapedMultibyteValues(): void
    {
        $adapter = new TestableMysqlAdapter();
        $connectionString = TestableMysqlAdapter::exposeBuildConnectionString(
            'db.例.test',
            '3306',
            "данные'📦",
            '用戶\\admin',
            "päss'\\秘密🙂",
            'UTF8',
        );

        $this->assertSame(
            "host='db.例.test' port='3306' dbname='данные\\'📦' user='用戶\\\\admin' password='päss\\'\\\\秘密🙂'",
            $connectionString,
        );
        $this->assertSame(
            [
                'host' => 'db.例.test',
                'port' => 3306,
                'database' => "данные'📦",
                'username' => '用戶\\admin',
                'password' => "päss'\\秘密🙂",
                'charset' => 'UTF8',
                'socket' => null,
            ],
            $adapter->exposeConnectionOptions($connectionString),
        );
    }

    public function testParsesEscapedDoubleQuotedValue(): void
    {
        $adapter = new TestableMysqlAdapter();

        $options = $adapter->exposeConnectionOptions(
            'host="db.example.com" password="say \\"hello\\" at C:\\\\data"',
        );

        $this->assertSame('say "hello" at C:\\data', $options['password']);
    }

    public function testRejectsTrailingDataAfterQuotedValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TestableMysqlAdapter())->exposeConnectionOptions(
            "host='localhost' password='secret'word'",
        );
    }

    public function testConnectionStringEscapingPreservesShiftJisTrailByte(): void
    {
        // The Shift-JIS representation of 表 ends in byte 0x5C, the ASCII backslash byte.
        $multibyteCharacter = mb_convert_encoding('表', 'SJIS', 'UTF-8');
        $value = $multibyteCharacter . "\\'";
        $connectionString = "password='"
            . TestableMysqlAdapter::exposeEscapeConnectionStringValue($value, 'SJIS')
            . "'";

        $this->assertSame(
            $value,
            TestableMysqlAdapter::exposeParseConnectionString($connectionString, 'SJIS')['password'],
        );
    }

    public function testEscapedUnixSocketRoundTripsWithoutPort(): void
    {
        $adapter = new TestableMysqlAdapter();
        $connectionString = TestableMysqlAdapter::exposeBuildConnectionString(
            "/var/run/mysql'sock",
            '3306',
            'app',
            'user',
            'secret',
            'UTF8',
        );

        $this->assertSame(
            "host='/var/run/mysql\\'sock' dbname='app' user='user' password='secret'",
            $connectionString,
        );
        $this->assertSame('/var/run/mysql\'sock', $adapter->exposeConnectionOptions($connectionString)['socket']);
    }

    public function testRejectsConnectionStringInvalidForConfiguredEncoding(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TestableMysqlAdapter::exposeParseConnectionString("password='invalid_\xFF'", 'UTF8');
    }

    public function testRejectsUnsupportedConnectionUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TestableMysqlAdapter())->exposeConnectionOptions('invalid connection string');
    }

    public function testNormalizesParametersAndReturnsBindingTypes(): void
    {
        $adapter = new TestableMysqlAdapter();
        $date = new DateTimeImmutable('2026-08-17 12:30:45');

        $this->assertSame(1, $adapter->exposeNormalizeParameter(true));
        $this->assertSame('2026-08-17 12:30:45', $adapter->exposeNormalizeParameter($date));
        $this->assertSame('i', $adapter->exposeParameterType(10));
        $this->assertSame('d', $adapter->exposeParameterType(10.5));
        $this->assertSame('s', $adapter->exposeParameterType(null));
        $this->assertSame('s', $adapter->exposeParameterType($date));
    }

    public function testDefaultResultConversionDecodesJson(): void
    {
        $adapter = new TestableMysqlAdapter();

        $this->assertSame(
            ['enabled' => true],
            $adapter->exposeResultConversion(
                '{"enabled":true}',
                'settings',
                0,
                MYSQLI_TYPE_JSON,
                0,
            ),
        );
    }

    public function testCustomResultConverterReceivesColumnMetadata(): void
    {
        $seen = [];
        $adapter = new TestableMysqlAdapter(resultConverter: static function (
            mixed $value,
            string $columnName,
            int $columnIndex,
            int $mysqlType,
            int $mysqlFlags,
        ) use (&$seen): mixed {
            $seen = [$columnName, $columnIndex, $mysqlType, $mysqlFlags];
            return $columnName === 'is_active' ? (bool) $value : $value;
        });

        $this->assertTrue($adapter->exposeResultConversion(
            1,
            'is_active',
            2,
            MYSQLI_TYPE_TINY,
            MYSQLI_UNSIGNED_FLAG,
        ));
        $this->assertSame(
            ['is_active', 2, MYSQLI_TYPE_TINY, MYSQLI_UNSIGNED_FLAG],
            $seen,
        );
    }

    public function testQuotesConnectionlessValuesIdentifiersAndBinary(): void
    {
        $adapter = new TestableMysqlAdapter(
            "host='127.0.0.1' port='1' dbname='database' user='user' password='password'",
        );

        $this->assertSame('NULL', $adapter->getEscapedValue(null));
        $this->assertSame('TRUE', $adapter->getEscapedValue(true));
        $this->assertSame('12.5', $adapter->getEscapedValue(12.5));
        $this->assertSame('`table``name`', $adapter->getEscapedIdentifier('table`name'));
        $this->assertSame("X'0001ff'", $adapter->getEscapedBinary("\0\1\xff"));
    }

    public function testRejectsInvalidIdentifiers(): void
    {
        $adapter = new TestableMysqlAdapter();

        try {
            $adapter->getEscapedIdentifier('');
            $this->fail('An empty identifier should be rejected');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $adapter->getEscapedIdentifier("column\0name");
    }

    public function testMapsCommonMysqlErrorCodes(): void
    {
        $adapter = new TestableMysqlAdapter();

        $this->assertInstanceOf(DuplicateRecordException::class, $adapter->exposeError(1062));
        $this->assertInstanceOf(UnknownColumnException::class, $adapter->exposeError(1054));
        $this->assertInstanceOf(UnknownTableException::class, $adapter->exposeError(1146));
        $this->assertInstanceOf(UnknownFunctionException::class, $adapter->exposeError(1305));
        $this->assertInstanceOf(DuplicateTableException::class, $adapter->exposeError(1050));
        $this->assertInstanceOf(DuplicateColumnException::class, $adapter->exposeError(1060));
        $this->assertInstanceOf(SyntaxErrorException::class, $adapter->exposeError(1064));
        $this->assertInstanceOf(ParameterMismatchException::class, $adapter->exposeError(1210));
        $this->assertInstanceOf(DatabaseException::class, $adapter->exposeError(9999));
    }
}

final class TestableMysqlAdapter extends MysqlAdapter
{
    public function exposeConnectionString(): string
    {
        return $this->connectionString;
    }

    public function exposeConnectionOptions(string $connectionString): array
    {
        return $this->resolveConnectionOptions($connectionString);
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

    /** @return array<string, string> */
    public static function exposeParseConnectionString(string $value, string $encoding): array
    {
        return parent::parseConnectionString($value, $encoding);
    }

    public function exposeNormalizeParameter(mixed $value): mixed
    {
        return $this->normalizeParameter($value);
    }

    public function exposeParameterType(mixed $value): string
    {
        return $this->getParameterType($value);
    }

    public function exposeResultConversion(
        mixed $value,
        string $columnName,
        int $columnIndex,
        int $mysqlType,
        int $mysqlFlags,
    ): mixed {
        return $this->convertResultValue($value, $columnName, $columnIndex, $mysqlType, $mysqlFlags);
    }

    public function exposeError(int $code): Throwable
    {
        return $this->convertErrorToException($code, 'Test MySQL error');
    }
}
