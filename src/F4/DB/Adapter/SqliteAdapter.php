<?php

declare(strict_types=1);

namespace F4\DB\Adapter;

use Closure,
    DateTimeInterface,
    InvalidArgumentException,
    SQLite3,
    SQLite3Exception,
    SQLite3Result,
    SQLite3Stmt,
    Throwable;
use F4\Config;
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
use F4\DB\PreparedStatement;

use function
    bin2hex,
    count,
    is_bool,
    is_float,
    is_int,
    is_string,
    sprintf,
    str_contains,
    strtolower,
    str_replace;

/**
 * SQLite adapter backed by PHP's native SQLite3 extension.
 *
 * Result conversion can be customized either by passing a converter callback
 * to the constructor or by extending the adapter and overriding
 * convertResultValue(). The callback receives the value, output column name,
 * zero-based column index, and SQLite storage-class constant.
 */
class SqliteAdapter implements AdapterInterface
{
    private const int SQLITE_CONSTRAINT_PRIMARY_KEY = 1555;
    private const int SQLITE_CONSTRAINT_UNIQUE = 2067;
    private const int SQLITE_RANGE = 25;

    protected SQLite3 $connection {
        get => $this->connection ?? ($this->connection = $this->connect(
            connectionString: $this->connectionString,
            connectionFlags: $this->connectionFlags,
        ));
    }
    protected string $connectionString;
    protected int $connectionFlags;
    private readonly ?Closure $resultConverter;

    /**
     * @param null|callable(mixed, string, int, int): mixed $resultConverter
     */
    public function __construct(
        ?string $connectionString = null,
        int $connectionFlags = 0,
        ?callable $resultConverter = null,
    ) {
        $this->connectionString = $connectionString ?? Config::DB_NAME;
        if ($this->connectionString === '') {
            throw new InvalidArgumentException('SQLite database filename cannot be empty; use ":memory:" for an in-memory database');
        }
        $this->connectionFlags = $connectionFlags ?: (SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $this->resultConverter = $resultConverter === null
            ? null
            : Closure::fromCallable($resultConverter);
    }

    public function connect(string $connectionString, int $connectionFlags = 0): SQLite3
    {
        try {
            $connection = new SQLite3(
                filename: $connectionString,
                flags: $connectionFlags ?: (SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE),
            );
            $this->configureConnection($connection);
            return $connection;
        } catch (Throwable $exception) {
            throw new DatabaseException(
                message: sprintf('Failed to open SQLite database: %s', $exception->getMessage()),
                code: 500,
                previous: $exception,
            );
        }
    }

    /**
     * Override to apply application-specific connection pragmas.
     */
    protected function configureConnection(SQLite3 $connection): void
    {
        $connection->enableExceptions(true);
        $connection->enableExtendedResultCodes(true);
        $connection->busyTimeout(5000);
        $connection->exec('PRAGMA foreign_keys = ON');
    }

    public function execute(PreparedStatement $statement, ?int $stopAfter = null): array
    {
        $sqliteStatement = null;
        $result = null;

        try {
            $sqliteStatement = $this->connection->prepare($statement->query);
            if (!$sqliteStatement instanceof SQLite3Stmt) {
                throw new DatabaseException('Failed to prepare SQLite statement');
            }
            if ($sqliteStatement->paramCount() !== count($statement->parameters)) {
                throw new ParameterMismatchException(sprintf(
                    'Expected %d parameters, received %d',
                    $sqliteStatement->paramCount(),
                    count($statement->parameters),
                ));
            }
            foreach ($statement->parameters as $index => $parameter) {
                if (!$sqliteStatement->bindValue(
                    param: $index + 1,
                    value: $this->normalizeParameter($parameter),
                    type: $this->getParameterType($parameter),
                )) {
                    throw new ParameterMismatchException(sprintf('Failed to bind SQLite parameter %d', $index + 1));
                }
            }

            $result = $sqliteStatement->execute();
            if (!$result instanceof SQLite3Result) {
                return [];
            }
            // SQLite3 returns a result object for command statements too. Calling
            // fetchArray() on a zero-column result can execute the command again.
            if ($result->numColumns() === 0) {
                return [];
            }

            $rows = [];
            while (
                ($stopAfter === null || count($rows) < $stopAfter)
                && ($row = $this->fetchRow($result)) !== false
            ) {
                $rows[] = $row;
            }
            return $rows;
        } catch (SQLite3Exception $exception) {
            $code = $this->connection->lastExtendedErrorCode();
            throw $this->convertErrorToException(
                code: $code ?: $exception->getCode(),
                message: $exception->getMessage(),
                previous: $exception,
            );
        } finally {
            if ($result instanceof SQLite3Result) {
                $result->finalize();
            }
            if ($sqliteStatement instanceof SQLite3Stmt) {
                $sqliteStatement->close();
            }
        }
    }

    public function enumerateParameters(int $index): string
    {
        return '?';
    }

    /**
     * Override to normalize additional PHP value types before binding.
     */
    protected function normalizeParameter(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? 1 : 0,
            $value === null, is_int($value), is_float($value), is_string($value) => $value,
            default => throw new InvalidArgumentException('Unsupported SQLite parameter type'),
        };
    }

    /**
     * Override together with normalizeParameter() to bind additional PHP types.
     */
    protected function getParameterType(mixed $value): int
    {
        return match (true) {
            $value === null => SQLITE3_NULL,
            is_bool($value), is_int($value) => SQLITE3_INTEGER,
            is_float($value) => SQLITE3_FLOAT,
            is_string($value), $value instanceof DateTimeInterface => SQLITE3_TEXT,
            default => throw new InvalidArgumentException('Unsupported SQLite parameter type'),
        };
    }

    /**
     * Fetch a row while retaining the metadata needed by convertResultValue().
     */
    protected function fetchRow(SQLite3Result $result): array|false
    {
        $values = $result->fetchArray(SQLITE3_NUM);
        if ($values === false) {
            return false;
        }

        $row = [];
        for ($index = 0; $index < $result->numColumns(); $index++) {
            $columnName = $result->columnName($index);
            $row[$columnName] = $this->convertResultValue(
                value: $values[$index],
                columnName: $columnName,
                columnIndex: $index,
                sqliteType: $result->columnType($index),
            );
        }
        return $row;
    }

    /**
     * Override for adapter-wide result conversion. Output aliases are preferable
     * mapping keys because SQLite exposes storage classes rather than dependable
     * application-level declared types.
     */
    protected function convertResultValue(
        mixed $value,
        string $columnName,
        int $columnIndex,
        int $sqliteType,
    ): mixed {
        return $this->resultConverter === null
            ? $value
            : ($this->resultConverter)($value, $columnName, $columnIndex, $sqliteType);
    }

    protected function convertErrorToException(
        int $code,
        string $message,
        ?Throwable $previous = null,
    ): Throwable {
        $normalizedMessage = strtolower($message);
        return match (true) {
            $code === self::SQLITE_CONSTRAINT_PRIMARY_KEY,
            $code === self::SQLITE_CONSTRAINT_UNIQUE => new DuplicateRecordException(message: $message, previous: $previous),
            $code === self::SQLITE_RANGE => new ParameterMismatchException(message: $message, previous: $previous),
            str_contains($normalizedMessage, 'no such table') => new UnknownTableException(message: $message, previous: $previous),
            str_contains($normalizedMessage, 'no such column'),
            str_contains($normalizedMessage, 'has no column named') => new UnknownColumnException(message: $message, previous: $previous),
            str_contains($normalizedMessage, 'no such function'),
            str_contains($normalizedMessage, 'wrong number of arguments to function') => new UnknownFunctionException(message: $message, previous: $previous),
            str_contains($normalizedMessage, 'table') && str_contains($normalizedMessage, 'already exists') => new DuplicateTableException(message: $message, previous: $previous),
            str_contains($normalizedMessage, 'duplicate column name') => new DuplicateColumnException(message: $message, previous: $previous),
            str_contains($normalizedMessage, 'syntax error'),
            str_contains($normalizedMessage, 'incomplete input'),
            str_contains($normalizedMessage, 'unrecognized token') => new SyntaxErrorException(message: $message, previous: $previous),
            default => new DatabaseException(
                message: sprintf('SQLite error %d: %s', $code, $message),
                code: 500,
                previous: $previous,
            ),
        };
    }

    public function getEscapedValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => $this->quoteString($value->format('Y-m-d H:i:s')),
            is_string($value) => $this->quoteString($value),
            default => throw new InvalidArgumentException('Unsupported SQLite parameter type'),
        };
    }

    public function getEscapedBinary(string $value): string
    {
        return sprintf("X'%s'", bin2hex($value));
    }

    public function getEscapedIdentifier(string $identifier): string
    {
        if ($identifier === '') {
            throw new InvalidArgumentException('Cannot quote an empty SQL identifier');
        }
        if (str_contains($identifier, "\0")) {
            throw new InvalidArgumentException('SQL identifier contains a NUL byte');
        }
        return sprintf('"%s"', str_replace('"', '""', $identifier));
    }

    protected function quoteString(string $value): string
    {
        if (str_contains($value, "\0")) {
            return sprintf("CAST(X'%s' AS TEXT)", bin2hex($value));
        }
        return sprintf("'%s'", str_replace("'", "''", $value));
    }
}
