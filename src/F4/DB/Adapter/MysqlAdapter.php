<?php

declare(strict_types=1);

namespace F4\DB\Adapter;

use Closure,
    DateTimeInterface,
    InvalidArgumentException,
    mysqli,
    mysqli_result,
    mysqli_sql_exception,
    mysqli_stmt,
    Throwable;
use F4\Config;
use F4\DB\Exception\{
    DuplicateColumnException,
    DuplicateFunctionException,
    DuplicateRecordException,
    DuplicateSchemaException,
    DuplicateTableException,
    Exception as DatabaseException,
    InvalidTableDefinitionException,
    ParameterMismatchException,
    SyntaxErrorException,
    UnknownColumnException,
    UnknownFunctionException,
    UnknownTableException,
};
use F4\DB\PreparedStatement;

use function
    array_fill,
    array_map,
    bin2hex,
    count,
    implode,
    in_array,
    is_bool,
    is_float,
    is_int,
    is_string,
    json_decode,
    mb_check_encoding,
    mb_list_encodings,
    mb_str_split,
    ord,
    sprintf,
    str_contains,
    str_replace,
    str_split,
    str_starts_with,
    strtolower,
    strtoupper,
    trim;

/**
 * MySQL adapter backed by PHP's native mysqli extension.
 *
 * Connection strings use the same key/value form as PostgresqlAdapter:
 * host='localhost' port='3306' dbname='app' user='app' password='secret'
 *
 * Result conversion can be customized either with the constructor callback or
 * by overriding convertResultValue(). The callback receives the value, output
 * column name, zero-based column index, mysqli type, and mysqli field flags.
 */
class MysqlAdapter implements AdapterInterface
{
    private const array MBSTRING_ENCODING_MAP = [
        'UTF8' => 'UTF-8',
        'UTF8MB3' => 'UTF-8',
        'UTF8MB4' => 'UTF-8',
        'LATIN1' => 'ISO-8859-1',
        'LATIN2' => 'ISO-8859-2',
        'ASCII' => 'ASCII',
        'CP1250' => 'Windows-1250',
        'CP1251' => 'Windows-1251',
        'SJIS' => 'SJIS',
        'CP932' => 'SJIS-win',
        'BIG5' => 'BIG-5',
        'GBK' => 'CP936',
        'EUCKR' => 'EUC-KR',
        'UJIS' => 'EUC-JP',
        'EUCJPMS' => 'EUC-JP',
    ];

    protected mysqli $connection {
        get => $this->connection ?? ($this->connection = $this->connect(
            connectionString: $this->connectionString,
            connectionFlags: $this->connectionFlags,
        ));
    }
    protected string $connectionString;
    protected int $connectionFlags;
    private readonly ?Closure $resultConverter;

    /**
     * @param null|callable(mixed, string, int, int, int): mixed $resultConverter
     */
    public function __construct(
        ?string $connectionString = null,
        int $connectionFlags = 0,
        ?callable $resultConverter = null,
    ) {
        $this->connectionString = match (!empty($connectionString)) {
            true => $connectionString,
            default => self::buildConnectionString(
                Config::DB_HOST,
                Config::DB_PORT,
                Config::DB_NAME,
                Config::DB_USERNAME,
                Config::DB_PASSWORD,
                Config::DB_CHARSET,
            ),
        };
        $this->connectionFlags = $connectionFlags;
        $this->resultConverter = $resultConverter === null
            ? null
            : Closure::fromCallable($resultConverter);
    }

    protected static function buildConnectionString(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
        string $encoding,
    ): string {
        $isSocket = str_starts_with(trim($host), '/');
        $host = self::escapeConnectionStringValue($host, $encoding);
        $database = self::escapeConnectionStringValue($database, $encoding);
        $username = self::escapeConnectionStringValue($username, $encoding);
        $password = self::escapeConnectionStringValue($password, $encoding);

        return match ($isSocket) {
            true => sprintf(
                "host='%s' dbname='%s' user='%s' password='%s'",
                $host,
                $database,
                $username,
                $password,
            ),
            default => sprintf(
                "host='%s' port='%s' dbname='%s' user='%s' password='%s'",
                $host,
                self::escapeConnectionStringValue($port, $encoding),
                $database,
                $username,
                $password,
            ),
        };
    }

    protected static function escapeConnectionStringValue(string $value, string $encoding): string
    {
        return implode('', array_map(
            static fn (string $character): string => match ($character) {
                '\\', "'" => '\\' . $character,
                default => $character,
            },
            self::splitConnectionStringCharacters($value, $encoding),
        ));
    }

    public function connect(string $connectionString, int $connectionFlags = 0): mysqli
    {
        $options = $this->resolveConnectionOptions($connectionString);

        try {
            $connection = mysqli_init();
            if (!$connection instanceof mysqli) {
                throw new DatabaseException('Failed to initialize mysqli', 500);
            }
            $connection->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);

            $host = Config::DB_PERSIST
                ? 'p:' . $options['host']
                : $options['host'];
            if (!$connection->real_connect(
                hostname: $host,
                username: $options['username'],
                password: $options['password'],
                database: $options['database'],
                port: $options['port'],
                socket: $options['socket'],
                flags: $connectionFlags,
            )) {
                throw new DatabaseException(
                    message: sprintf('Failed to connect to MySQL: %s', $connection->connect_error),
                    code: 500,
                );
            }

            $this->configureConnection($connection, $options['charset']);
            return $connection;
        } catch (mysqli_sql_exception $exception) {
            throw new DatabaseException(
                message: sprintf('Failed to connect to MySQL: %s', $exception->getMessage()),
                code: 500,
                previous: $exception,
            );
        }
    }

    /**
     * Override to change session settings or configure TLS before use.
     */
    protected function configureConnection(mysqli $connection, string $charset): void
    {
        $connection->set_charset($charset);
        if (Config::TIMEZONE !== '') {
            $timezone = $connection->real_escape_string(Config::TIMEZONE);
            $connection->query(sprintf("SET time_zone = '%s'", $timezone));
        }
    }

    /**
     * @return array{
     *     host: string,
     *     port: int,
     *     database: string,
     *     username: string,
     *     password: string,
     *     charset: string,
     *     socket: ?string
     * }
     */
    protected function resolveConnectionOptions(string $connectionString): array
    {
        $values = self::parseConnectionString($connectionString, Config::DB_CHARSET);

        $configuredHost = $values['host'] ?? Config::DB_HOST;
        $socket = $values['socket'] ?? (
            str_starts_with(trim($configuredHost), '/')
                ? $configuredHost
                : null
        );

        return [
            'host' => $socket === $configuredHost ? 'localhost' : $configuredHost,
            'port' => isset($values['port']) ? (int) $values['port'] : (int) Config::DB_PORT,
            'database' => $values['dbname'] ?? $values['database'] ?? Config::DB_NAME,
            'username' => $values['user'] ?? $values['username'] ?? Config::DB_USERNAME,
            'password' => $values['password'] ?? Config::DB_PASSWORD,
            'charset' => $values['charset'] ?? (Config::DB_CHARSET !== '' ? Config::DB_CHARSET : 'utf8mb4'),
            'socket' => $socket,
        ];
    }

    /** @return array<string, string> */
    protected static function parseConnectionString(string $connectionString, string $encoding): array
    {
        $characters = self::splitConnectionStringCharacters($connectionString, $encoding);
        $length = count($characters);
        $index = 0;
        $values = [];

        while (true) {
            while ($index < $length && self::isConnectionStringWhitespace($characters[$index])) {
                $index++;
            }
            if ($index === $length) {
                break;
            }

            $key = '';
            while ($index < $length && self::isConnectionStringKeyCharacter($characters[$index])) {
                $key .= $characters[$index++];
            }
            if ($key === '') {
                throw new InvalidArgumentException('Invalid MySQL connection string key');
            }

            while ($index < $length && self::isConnectionStringWhitespace($characters[$index])) {
                $index++;
            }
            if (($characters[$index] ?? null) !== '=') {
                throw new InvalidArgumentException('Invalid MySQL connection string assignment');
            }
            $index++;

            while ($index < $length && self::isConnectionStringWhitespace($characters[$index])) {
                $index++;
            }
            if ($index === $length) {
                throw new InvalidArgumentException('Missing MySQL connection string value');
            }

            $value = '';
            $quote = in_array($characters[$index], ["'", '"'], true)
                ? $characters[$index++]
                : null;
            if ($quote !== null) {
                $closed = false;
                while ($index < $length) {
                    $character = $characters[$index++];
                    if ($character === $quote) {
                        $closed = true;
                        break;
                    }
                    if (
                        $character === '\\'
                        && $index < $length
                        && in_array($characters[$index], ['\\', $quote], true)
                    ) {
                        $value .= $characters[$index++];
                        continue;
                    }
                    $value .= $character;
                }
                if (!$closed) {
                    throw new InvalidArgumentException('Unterminated MySQL connection string value');
                }
                if ($index < $length && !self::isConnectionStringWhitespace($characters[$index])) {
                    throw new InvalidArgumentException('Unexpected data after MySQL connection string value');
                }
            } else {
                while ($index < $length && !self::isConnectionStringWhitespace($characters[$index])) {
                    $value .= $characters[$index++];
                }
            }

            $values[strtolower($key)] = $value;
        }

        if ($values === []) {
            throw new InvalidArgumentException('Invalid MySQL connection string');
        }
        return $values;
    }

    /** @return list<string> */
    private static function splitConnectionStringCharacters(string $value, string $encoding): array
    {
        $mbEncoding = self::MBSTRING_ENCODING_MAP[strtoupper($encoding)] ?? null;
        if ($mbEncoding === null || !self::isSupportedMbEncoding($mbEncoding)) {
            return str_split($value);
        }
        if (!mb_check_encoding($value, $mbEncoding)) {
            throw new InvalidArgumentException(sprintf('MySQL connection value is not valid %s', $encoding));
        }
        return mb_str_split($value, 1, $mbEncoding);
    }

    private static function isConnectionStringWhitespace(string $character): bool
    {
        return in_array($character, [" ", "\t", "\r", "\n", "\v", "\f"], true);
    }

    private static function isConnectionStringKeyCharacter(string $character): bool
    {
        $byte = ord($character);
        return $character === '_'
            || ($byte >= 65 && $byte <= 90)
            || ($byte >= 97 && $byte <= 122);
    }

    private static function isSupportedMbEncoding(string $encoding): bool
    {
        static $supported = null;
        $supported ??= array_map(strtoupper(...), mb_list_encodings());
        return in_array(strtoupper($encoding), $supported, true);
    }

    public function execute(PreparedStatement $statement, ?int $stopAfter = null): array
    {
        $mysqlStatement = null;
        $metadata = null;

        try {
            $mysqlStatement = $this->connection->prepare($statement->query);
            if (!$mysqlStatement instanceof mysqli_stmt) {
                throw $this->convertErrorToException(
                    code: $this->connection->errno,
                    message: $this->connection->error,
                );
            }
            if ($mysqlStatement->param_count !== count($statement->parameters)) {
                throw new ParameterMismatchException(sprintf(
                    'Expected %d parameters, received %d',
                    $mysqlStatement->param_count,
                    count($statement->parameters),
                ));
            }

            $this->bindParameters($mysqlStatement, $statement->parameters);
            if (!$mysqlStatement->execute()) {
                throw $this->convertErrorToException(
                    code: $mysqlStatement->errno,
                    message: $mysqlStatement->error,
                );
            }

            $metadata = $mysqlStatement->result_metadata();
            if (!$metadata instanceof mysqli_result) {
                return [];
            }

            $fields = $metadata->fetch_fields();
            $values = array_fill(0, count($fields), null);
            $valueReferences = [];
            foreach ($values as &$value) {
                $valueReferences[] = &$value;
            }
            unset($value);
            $mysqlStatement->bind_result(...$valueReferences);

            $rows = [];
            while (
                ($stopAfter === null || count($rows) < $stopAfter)
                && $mysqlStatement->fetch() === true
            ) {
                $row = [];
                foreach ($fields as $index => $field) {
                    $row[$field->name] = $this->convertResultValue(
                        value: $values[$index],
                        columnName: $field->name,
                        columnIndex: $index,
                        mysqlType: $field->type,
                        mysqlFlags: $field->flags,
                    );
                }
                $rows[] = $row;
            }
            return $rows;
        } catch (mysqli_sql_exception $exception) {
            throw $this->convertErrorToException(
                code: $exception->getCode(),
                message: $exception->getMessage(),
                previous: $exception,
            );
        } finally {
            if ($metadata instanceof mysqli_result) {
                $metadata->free();
            }
            if ($mysqlStatement instanceof mysqli_stmt) {
                $mysqlStatement->close();
            }
        }
    }

    public function enumerateParameters(int $index): string
    {
        return '?';
    }

    /**
     * Override to support custom PHP parameter values or streamed blobs.
     */
    protected function bindParameters(mysqli_stmt $statement, array $parameters): void
    {
        if ($parameters === []) {
            return;
        }

        $types = '';
        $normalizedParameters = [];
        foreach ($parameters as $parameter) {
            $types .= $this->getParameterType($parameter);
            $normalizedParameters[] = $this->normalizeParameter($parameter);
        }

        $references = [];
        foreach ($normalizedParameters as &$parameter) {
            $references[] = &$parameter;
        }
        unset($parameter);

        if (!$statement->bind_param($types, ...$references)) {
            throw new ParameterMismatchException('Failed to bind MySQL parameters');
        }
    }

    protected function normalizeParameter(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? 1 : 0,
            $value === null, is_int($value), is_float($value), is_string($value) => $value,
            default => throw new InvalidArgumentException('Unsupported MySQL parameter type'),
        };
    }

    protected function getParameterType(mixed $value): string
    {
        return match (true) {
            is_bool($value), is_int($value) => 'i',
            is_float($value) => 'd',
            $value === null, is_string($value), $value instanceof DateTimeInterface => 's',
            default => throw new InvalidArgumentException('Unsupported MySQL parameter type'),
        };
    }

    protected function convertResultValue(
        mixed $value,
        string $columnName,
        int $columnIndex,
        int $mysqlType,
        int $mysqlFlags,
    ): mixed {
        if ($this->resultConverter !== null) {
            return ($this->resultConverter)($value, $columnName, $columnIndex, $mysqlType, $mysqlFlags);
        }
        if ($value === null) {
            return null;
        }
        return match ($mysqlType) {
            MYSQLI_TYPE_JSON => json_decode(json: $value, associative: true, flags: JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    protected function convertErrorToException(
        int $code,
        string $message,
        ?Throwable $previous = null,
    ): Throwable {
        return match ($code) {
            1062 => new DuplicateRecordException(message: $message, previous: $previous),
            1054 => new UnknownColumnException(message: $message, previous: $previous),
            1051, 1146 => new UnknownTableException(message: $message, previous: $previous),
            1305 => new UnknownFunctionException(message: $message, previous: $previous),
            1007 => new DuplicateSchemaException(message: $message, previous: $previous),
            1050 => new DuplicateTableException(message: $message, previous: $previous),
            1060 => new DuplicateColumnException(message: $message, previous: $previous),
            1304 => new DuplicateFunctionException(message: $message, previous: $previous),
            1005 => new InvalidTableDefinitionException(message: $message, previous: $previous),
            1064 => new SyntaxErrorException(message: $message, previous: $previous),
            1136, 1210, 1582 => new ParameterMismatchException(message: $message, previous: $previous),
            default => new DatabaseException(
                message: sprintf('MySQL error %d: %s', $code, $message),
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
            default => throw new InvalidArgumentException('Unsupported MySQL parameter type'),
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
        return sprintf('`%s`', str_replace('`', '``', $identifier));
    }

    protected function quoteString(string $value): string
    {
        return sprintf("'%s'", $this->connection->real_escape_string($value));
    }
}
