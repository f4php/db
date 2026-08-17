<?php

declare(strict_types=1);

namespace F4\Tests\DB\Adapter;

use F4\DB;
use F4\DB\Adapter\SqliteAdapter;
use F4\DB\Exception\{
    DuplicateColumnException,
    DuplicateRecordException,
    ParameterMismatchException,
    UnknownTableException,
};
use F4\DB\PreparedStatement;
use F4\DB\QueryBuilderInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('sqlite3')]
final class SqliteAdapterTest extends TestCase
{
    private function adapter(?callable $resultConverter = null): SqliteAdapter
    {
        return new SqliteAdapter(':memory:', resultConverter: $resultConverter);
    }

    public function testExecutesPreparedStatementsAndReturnsNativeValues(): void
    {
        $adapter = $this->adapter();
        $adapter->execute(new PreparedStatement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, score REAL)',
            [],
        ));
        $adapter->execute(new PreparedStatement(
            'INSERT INTO users (id, name, active, score) VALUES (?, ?, ?, ?)',
            [1, "D'Artagnan", true, 9.5],
        ));

        $this->assertSame(
            [['id' => 1, 'name' => "D'Artagnan", 'active' => 1, 'score' => 9.5]],
            $adapter->execute(new PreparedStatement('SELECT * FROM users', [])),
        );
    }

    public function testStopsAfterRequestedNumberOfRows(): void
    {
        $adapter = $this->adapter();
        $adapter->execute(new PreparedStatement('CREATE TABLE items (id INTEGER)', []));
        $adapter->execute(new PreparedStatement('INSERT INTO items VALUES (1), (2), (3)', []));

        $this->assertSame(
            [['id' => 1], ['id' => 2]],
            $adapter->execute(new PreparedStatement('SELECT id FROM items ORDER BY id', []), 2),
        );
    }

    public function testRejectsAndReportsFirstDuplicateResultColumnName(): void
    {
        $this->expectException(DuplicateColumnException::class);
        $this->expectExceptionMessage('Duplicate result column name "first"');

        $this->adapter()->execute(new PreparedStatement(
            'SELECT 1 AS first, 2 AS second, 3 AS first, 4 AS second',
            [],
        ));
    }

    public function testRejectsDuplicateColumnNamesForEmptyResult(): void
    {
        $this->expectException(DuplicateColumnException::class);

        $this->adapter()->execute(new PreparedStatement(
            'SELECT 1 AS id, 2 AS id WHERE 0',
            [],
        ));
    }

    public function testReturnsDistinctResultColumnAliases(): void
    {
        $this->assertSame(
            [['user_id' => 1, 'order_id' => 2]],
            $this->adapter()->execute(new PreparedStatement(
                'SELECT 1 AS user_id, 2 AS order_id',
                [],
            )),
        );
    }

    public function testAsTableRejectsDuplicateResultColumnNames(): void
    {
        $this->expectException(DuplicateColumnException::class);

        $this->duplicateResultQuery()->asTable();
    }

    public function testAsRowRejectsDuplicateResultColumnNames(): void
    {
        $this->expectException(DuplicateColumnException::class);

        $this->duplicateResultQuery()->asRow();
    }

    public function testAsValueRejectsDuplicateResultColumnNames(): void
    {
        $this->expectException(DuplicateColumnException::class);

        $this->duplicateResultQuery()->asValue();
    }

    public function testResultConverterReceivesColumnMetadata(): void
    {
        $seen = [];
        $adapter = $this->adapter(static function (
            mixed $value,
            string $columnName,
            int $columnIndex,
            int $sqliteType,
        ) use (&$seen): mixed {
            $seen[] = [$columnName, $columnIndex, $sqliteType];
            return $columnName === 'is_active' ? (bool) $value : $value;
        });

        $this->assertSame(
            [['is_active' => true]],
            $adapter->execute(new PreparedStatement('SELECT 1 AS is_active', [])),
        );
        $this->assertSame([['is_active', 0, SQLITE3_INTEGER]], $seen);
    }

    public function testSubclassCanOverrideResultConversionHook(): void
    {
        $adapter = new BooleanConvertingSqliteAdapter(':memory:');

        $this->assertSame(
            [['enabled' => true, 'label' => 'example']],
            $adapter->execute(new PreparedStatement("SELECT 1 AS enabled, 'example' AS label", [])),
        );
    }

    public function testRejectsParameterCountMismatchBeforeExecution(): void
    {
        $this->expectException(ParameterMismatchException::class);
        $this->adapter()->execute(new PreparedStatement('SELECT ?, ?', [1]));
    }

    public function testMapsUniqueConstraintViolation(): void
    {
        $adapter = $this->adapter();
        $adapter->execute(new PreparedStatement('CREATE TABLE users (email TEXT UNIQUE)', []));
        $adapter->execute(new PreparedStatement('INSERT INTO users VALUES (?)', ['user@example.com']));

        $this->expectException(DuplicateRecordException::class);
        $adapter->execute(new PreparedStatement('INSERT INTO users VALUES (?)', ['user@example.com']));
    }

    public function testMapsUnknownTableError(): void
    {
        $this->expectException(UnknownTableException::class);
        $this->adapter()->execute(new PreparedStatement('SELECT * FROM missing_table', []));
    }

    public function testQuotesValuesIdentifiersAndBinaryWithoutConnecting(): void
    {
        $adapter = $this->adapter();

        $this->assertSame("'O''Brien'", $adapter->getEscapedValue("O'Brien"));
        $this->assertSame('"table""name"', $adapter->getEscapedIdentifier('table"name'));
        $this->assertSame("X'0001ff'", $adapter->getEscapedBinary("\0\1\xff"));
        $this->assertSame("CAST(X'610062' AS TEXT)", $adapter->getEscapedValue("a\0b"));
    }

    public function testUsesPositionalParameters(): void
    {
        $this->assertSame('?', $this->adapter()->enumerateParameters(42));
    }

    private function duplicateResultQuery(): QueryBuilderInterface
    {
        return DB::raw('SELECT 1 AS id, 2 AS id')->useAdapter($this->adapter());
    }
}

final class BooleanConvertingSqliteAdapter extends SqliteAdapter
{
    protected function convertResultValue(
        mixed $value,
        string $columnName,
        int $columnIndex,
        int $sqliteType,
    ): mixed {
        return $columnName === 'enabled'
            ? (bool) $value
            : parent::convertResultValue($value, $columnName, $columnIndex, $sqliteType);
    }
}
