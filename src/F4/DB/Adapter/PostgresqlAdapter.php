<?php

declare(strict_types=1);

namespace F4\DB\Adapter;

use Composer\Pcre\Preg;
use DateTimeInterface,
    ErrorException,
    InvalidArgumentException,
    Throwable;
use F4\Config;
use F4\DB\Adapter\AdapterInterface;
use F4\DB\Exception\{
    DuplicateColumnException,
    DuplicateFunctionException,
    DuplicateObjectException,
    DuplicateRecordException,
    DuplicateSchemaException,
    DuplicateTableException,
    Exception,
    InvalidResultValueException,
    InvalidTableDefinitionException,
    InvalidTextRepresentationException,
    ParameterMismatchException,
    SyntaxErrorException,
    UnknownColumnException,
    UnknownFunctionException,
    UnknownTableException,
};
use F4\DB\PreparedStatement;

use PgSql\{
    Connection,
    // Result,
};

use function
    array_count_values,
    array_find_key,
    array_map,
    array_unique,
    count,
    implode,
    in_array,
    is_array,
    is_bool,
    is_float,
    is_int,
    is_scalar,
    json_decode,
    mb_check_encoding,
    mb_list_encodings,
    mb_str_split,
    pg_escape_bytea,
    pg_escape_literal,
    pg_fetch_row,
    pg_field_name,
    pg_field_type,
    pg_free_result,
    pg_get_result,
    pg_last_error,
    pg_num_fields,
    pg_query,
    pg_result_error_field,
    pg_result_status,
    pg_send_query_params,
    pg_send_query,
    pg_set_client_encoding,
    pg_unescape_bytea,
    sprintf,
    str_contains,
    str_replace,
    str_starts_with,
    trim,
    strtoupper;

/**
 * 
 * Postgresql adapter class
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class PostgresqlAdapter implements AdapterInterface
{
    /**
     * PostgreSQL client-encoding name (Config::DB_CHARSET) => mbstring encoding name.
     * Used to validate identifier bytes connectionlessly, mirroring pg_escape_identifier's
     * client-encoding check. Charsets not listed here skip validation (pass-through) rather
     * than being falsely rejected.
     */
    private const array MBSTRING_ENCODING_MAP = [
        'UTF8' => 'UTF-8',
        'LATIN1' => 'ISO-8859-1',
        'LATIN2' => 'ISO-8859-2',
        'LATIN5' => 'ISO-8859-9',
        'LATIN9' => 'ISO-8859-15',
        'WIN1251' => 'Windows-1251',
        'WIN1252' => 'Windows-1252',
        'EUC_JP' => 'EUC-JP',
        'EUC_KR' => 'EUC-KR',
        'SJIS' => 'SJIS',
        'BIG5' => 'BIG-5',
        'GBK' => 'CP936',
        'UHC' => 'UHC',
        'SQL_ASCII' => 'ASCII',
    ];

    protected Connection $connection {
        get => $this->connection ?? ($this->connection=$this->connect(connectionString: $this->connectionString, connectionFlags: $this->connectionFlags));
    }
    protected ?string $connectionString;
    protected int $connectionFlags;

    public function __construct(?string $connectionString = null, int $connectionFlags = 0)
    {
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
    }
    protected static function buildConnectionString(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
        string $encoding,
    ): string {
        $host = self::escapeConnectionStringValue($host, $encoding);
        $database = self::escapeConnectionStringValue($database, $encoding);
        $username = self::escapeConnectionStringValue($username, $encoding);
        $password = self::escapeConnectionStringValue($password, $encoding);

        return match (str_starts_with(trim($host), '/')) {
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
        $mbEncoding = self::MBSTRING_ENCODING_MAP[strtoupper($encoding)] ?? null;
        if ($mbEncoding === null || !self::isSupportedMbEncoding($mbEncoding)) {
            return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        }
        if (!mb_check_encoding($value, $mbEncoding)) {
            throw new InvalidArgumentException(sprintf('PostgreSQL connection value is not valid %s', $encoding));
        }

        return implode('', array_map(
            static fn (string $character): string => match ($character) {
                '\\', "'" => '\\' . $character,
                default => $character,
            },
            mb_str_split($value, 1, $mbEncoding),
        ));
    }
    public function execute(PreparedStatement $statement, ?int $stopAfter = null): array
    {
        if (!$this->connection) {
            throw new ErrorException('Failed to connect to the database', 500);
        }
        $query = $statement->query;
        // PHP bools are cast to empty strings by default, which requires a workaround
        $parameters = array_map(
            callback: self::normalizeParameter(...),
            array: $statement->parameters
        );
        if (
            (count($parameters) && pg_send_query_params(connection: $this->connection, query: $query, params: $parameters) !== TRUE)
            ||
            (!count($parameters) && pg_send_query(connection: $this->connection, query: $query) !== TRUE)
        ) {
            throw new Exception(message: pg_last_error($this->connection), code: 500);
        }
        $rows = [];
        try {
            while(($result = pg_get_result($this->connection)) !== FALSE) {
                try {
                    switch(pg_result_status($result)) {
                        case PGSQL_BAD_RESPONSE:
                        case PGSQL_NONFATAL_ERROR:
                        case PGSQL_FATAL_ERROR:
                            throw match($code = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE)) {
                                null, false => new Exception(message: "Undefined database error", code: 500),
                                default => $this->convertErrorToException(code: $code, message: pg_last_error($this->connection)),
                            };
                        case PGSQL_TUPLES_OK:
                            $columnNames = [];
                            $columnTypes = [];
                            for ($i = 0; $i < pg_num_fields($result); $i++) {
                                $columnNames[] = pg_field_name($result, $i);
                                $columnTypes[] = pg_field_type($result, $i);
                            }
                            if (
                                !Config::DB_OVERWRITE_DUPLICATE_RESPONSE_COLUMNS
                                && count($columnNames) !== count(array_unique($columnNames))
                            ) {
                                $columnName = array_find_key(
                                    array: array_count_values($columnNames),
                                    callback: static fn(int $count): bool => $count > 1,
                                );
                                throw new DuplicateColumnException(sprintf(
                                    'Duplicate result column name "%s"; alias duplicate columns in the query',
                                    $columnName,
                                ));
                            }

                            while ((($stopAfter === null) || $stopAfter > 0) && ($row = pg_fetch_row($result)) !== FALSE) {
                                if (is_array($row)) {
                                    $processedRow = [];
                                    for ($i = 0; $i < count($row); $i++) {
                                        $processedRow[$columnNames[$i]] = $this->castType($row[$i], $columnTypes[$i]);
                                    }
                                } else {
                                    $processedRow = $row;
                                }
                                $rows[] = $processedRow;
                                if (($stopAfter !== null) && count($rows) >= $stopAfter) {
                                    break;
                                }
                            };
                            break;
                        case PGSQL_EMPTY_QUERY:
                        case PGSQL_COMMAND_OK:
                        case PGSQL_TUPLES_CHUNK:
                        case PGSQL_COPY_OUT:
                        case PGSQL_COPY_IN:
                        default:
                            break;
                    }
                } finally {
                    pg_free_result($result);
                }
            }
        }
        catch (Throwable $exception) {
            // Purge remaining data in protocol queue
            while (($result = pg_get_result($this->connection)) !== false) {
                pg_free_result($result);
            }
            throw $exception;
        }
        return $rows;
    }
    public function enumerateParameters(int $index): string
    {
        return sprintf('$%d', $index);
    }
    protected static function normalizeParameter(mixed $parameter): mixed
    {
        return match (true) {
            $parameter instanceof DateTimeInterface => $parameter->format('Y-m-d\TH:i:s.uP'),
            is_bool($parameter) => $parameter ? 'true' : 'false',
            default => $parameter,
        };
    }
    protected function castType(mixed $value, string $type): mixed
    {
        if (is_array($value)) {
            foreach ($value as $i => $v) {
                $value[$i] = $this->castType($v, $type);
            }
        } else {
            if ($value === null) {
                return null;
            }
            switch ($type) {
                case 'smallint':
                case 'smallserial':
                case 'integer':
                case 'serial':
                case 'bigint':
                case 'bigserial':
                case 'int2':
                case 'int4':
                case 'int8':
                    $value = (int) $value;
                    break;
                case 'real':
                case 'double precision':
                case 'float4':
                case 'float8':
                    $value = (float) $value;
                    break;
                case 'numeric':
                    // doesn't match any native php type, should remain as is (presumably, a string) for versatility
                    break;
                case 'json':
                case 'jsonb':
                    $value = json_decode(json: $value, associative: true, flags: JSON_THROW_ON_ERROR);
                    break;
                case 'boolean':
                case 'bool':
                    $value = match ($value) {
                        't' => true,
                        'f' => false,
                        default => throw new InvalidResultValueException('Unexpected PostgreSQL boolean representation'),
                    };
                    break;
                case 'bytea':
                    $value = pg_unescape_bytea($value);
                    break;
                // TODO: process pgsql arrays http://stackoverflow.com/questions/9169176/accessing-psql-array-directly-in-php
                //        case '_text':
                //                  if($parts='')
                //                    $value=$this->castType($parts, $type);
                //                  break;
                default:
            }
        }
        return $value;
    }
    protected function convertErrorToException(string $code, string $message): Throwable
    {
        return match ($code) {
            '08P01' => new ParameterMismatchException(message: $message),
            '22P02' => new InvalidTextRepresentationException(message: $message),
            '42601' => new SyntaxErrorException(message: $message),
            '23505' => new DuplicateRecordException(message: $message),
            '42703' => new UnknownColumnException(message: $message),
            '42723' => new DuplicateFunctionException(message: $message),
            '42883' => new UnknownFunctionException(message: $message),
            '42P01' => new UnknownTableException(message: $message),
            '42P06' => new DuplicateSchemaException(message: $message),
            '42P07' => new DuplicateTableException(message: $message),
            '42710' => new DuplicateObjectException(message: $message),
            '42701' => new DuplicateColumnException(message: $message),
            '42P16' => new InvalidTableDefinitionException(message: $message),
            default => new Exception(message: sprintf("Database error %s, %s", $code, $message))
        };
    }
    public function connect(string $connectionString, int $connectionFlags = 0): Connection
    {
        $connection = null;
        try {
            $connection = match (Config::DB_PERSIST) {
                true => @pg_pconnect(connection_string: $connectionString, flags: $connectionFlags),
                default => @pg_connect(connection_string: $connectionString, flags: $connectionFlags)
            };
            if ((false === $connection) || (pg_connection_status(connection: $connection) !== PGSQL_CONNECTION_OK)) {
                throw new ErrorException('Database connection failed', 500);
            }
        } catch (ErrorException $e) {
            match (Config::DEBUG_MODE && ($connection !== false)) {
                true => throw new ErrorException(message: sprintf("Database connection error: %s", pg_last_error($connection)), code: 500, previous: $e),
                default => throw $e
            };
        } catch (Exception $e) {
            throw $e;
        }
        try {
            if (pg_set_client_encoding(connection: $connection, encoding: Config::DB_CHARSET) !== 0) {
                throw new ErrorException(message: "failed-to-set-database-encoding", code: 500);
            }
            if (Config::TIMEZONE && !@pg_query(connection: $connection, query: sprintf('SET TIME ZONE %s', self::requireEscapedLiteral(pg_escape_literal($connection, Config::TIMEZONE))))) {
                throw new ErrorException('Failed to set database timezone', 500);
            }
            if (Config::DB_SCHEMA && !@pg_query(connection: $connection, query: sprintf('SET "search_path" TO %s', self::requireEscapedLiteral(pg_escape_literal($connection, Config::DB_SCHEMA))))) {
                throw new ErrorException('Failed to set database schema', 500);
            }
            if (Config::DB_APP_NAME && !@pg_query(connection: $connection, query: sprintf('SET "application_name" = %s', self::requireEscapedLiteral(pg_escape_literal($connection, Config::DB_APP_NAME))))) {
                throw new ErrorException('Failed to set database application name', 500);
            }
        } catch (ErrorException $e) {
            match (Config::DEBUG_MODE) {
                true => throw new ErrorException(message: sprintf("Database error: %s", pg_last_error($connection)), code: 500, previous: $e),
                default => throw $e
            };
        }
        return $connection;
    }
    public function getEscapedValue(mixed $value): string
    {
        if (!$this->connection) {
            throw new ErrorException('Failed to connect to the database', 500);
        }
        return match ($value === null) {
            true => 'NULL',
            default => match (is_bool($value)) {
                    true => $value ? 'TRUE' : 'FALSE',
                    default => match (is_int($value) || is_float($value)) {
                            true => (string) $value,
                            default => match ($value instanceof DateTimeInterface) {
                                    true => self::requireEscapedLiteral(pg_escape_literal($this->connection, $value->format('Y-m-d\TH:i:s.uP'))),
                                    default => match (is_scalar($value)) {
                                            true => self::requireEscapedLiteral(pg_escape_literal($this->connection, (string) $value)),
                                            default => throw new InvalidArgumentException('Unsupported parameter type')
                                        }
                                }
                        }
                }
        };
    }
    protected static function requireEscapedLiteral(string|false $escapedLiteral): string
    {
        if ($escapedLiteral === false) {
            throw new ErrorException('Failed to escape PostgreSQL literal', 500);
        }
        return $escapedLiteral;
    }
    public function getEscapedBinary(string $value): string
    {
        if (!$this->connection) {
            throw new ErrorException('Failed to connect to the database', 500);
        }
        return pg_escape_bytea($this->connection, $value);
    }
    public function getEscapedIdentifier(string $identifier): string
    {
        /**
         * Connectionless identifier quoting: PostgreSQL identifier quoting is a fixed rule
         * (wrap in double quotes, double any embedded double quote) — pg_escape_identifier is
         * connection-bound only to validate identifier bytes against the client encoding. We
         * reproduce that validation against the configured Config::DB_CHARSET (the same value
         * connect() pushes via pg_set_client_encoding), so identifiers are quoted at render time
         * and during prepared-statement generation without ever opening a connection.
         */
        if ($identifier === '') {
            throw new InvalidArgumentException('Cannot quote an empty SQL identifier');
        }
        if (str_contains($identifier, "\0")) {
            throw new InvalidArgumentException('SQL identifier contains a NUL byte');
        }
        $mbEncoding = self::MBSTRING_ENCODING_MAP[strtoupper(Config::DB_CHARSET)] ?? null;
        // Only validate when this mbstring build actually supports the mapped encoding name;
        // otherwise pass through (unknown/unsupported mappings must not throw a ValueError).
        if ($mbEncoding !== null && self::isSupportedMbEncoding($mbEncoding) && !mb_check_encoding($identifier, $mbEncoding)) {
            throw new InvalidArgumentException(sprintf('SQL identifier is not valid %s', Config::DB_CHARSET));
        }
        // No /u modifier: we double the ASCII byte 0x22 (") and must not require the subject
        // to be valid UTF-8, otherwise valid non-UTF-8 identifiers (e.g. LATIN1) would throw.
        return sprintf('"%s"', Preg::replace(pattern: '/"/', replacement: '""', subject: $identifier));
    }
    private static function isSupportedMbEncoding(string $encoding): bool
    {
        static $supported = null;
        $supported ??= array_map(strtoupper(...), mb_list_encodings());
        return in_array(strtoupper($encoding), $supported, true);
    }
}
