<?php

declare(strict_types=1);

namespace F4;

use BadMethodCallException;

use F4\Config;
use F4\DB\Adapter\AdapterInterface;
use F4\DB\QueryBuilderInterface;
use Throwable;

use function array_map;
use function implode;
use function is_array;
use function is_string;

/**
 *
 * DBTransaction is a wrapper for executing atomic transactions
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 * @method static DBTransaction add(QueryBuilderInterface|array<QueryBuilderInterface> $query) Add query to transaction (static)
 * @method DBTransaction add(QueryBuilderInterface|array<QueryBuilderInterface> $query) Add query to transaction
 */
class DBTransaction
{
    protected AdapterInterface $adapter;
    protected array $queries = [];
    public function __construct(?string $connectionString = null, string|AdapterInterface $adapter = Config::DB_ADAPTER_CLASS)
    {
        $this->adapter = match (is_string($adapter)) {
            true => new $adapter($connectionString),
            default => $adapter,
        };
    }
    public function __call(string $method, array $arguments): mixed
    {
        match ($method) {
            'add' => $this->addQuery(...$arguments),
            default => throw new BadMethodCallException(message: "Unsupported method {$method}()")
        };
        return $this;
    }
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return match ($method) {
            'add' => new static()->$method(...$arguments),
            default => throw new BadMethodCallException(message: "Unsupported method {$method}()")
        };
    }
    protected function addQuery(array|QueryBuilderInterface $query): static
    {
        $this->queries = [
            ...$this->queries,
            ...array_map(
                // This makes sure all added queries implement QueryBuilderInterface and use the same adapter instance
                // (all queries within single transaction are by design required to use the same database connection)
                callback: fn(QueryBuilderInterface $query): QueryBuilderInterface => (clone $query)->useAdapter($this->adapter),
                array: match (is_array($query)) {
                    true => $query,
                    default => [$query],
                },
            )
        ];
        return $this;
    }
    public function asSQL(): string
    {
        return implode('; ', array_map(
            callback: fn (QueryBuilderInterface $query): string => $query->asSQL(),
            array: $this->getQueries(),
        ));
    }
    public function commit(): mixed
    {
        try {
            return array_map(
                callback: function (QueryBuilderInterface $query): mixed {
                    $preparedStatement = $query->getPreparedStatement($this->adapter->enumerateParameters(...));
                    return $this->adapter->execute(statement: $preparedStatement);
                },
                array: $this->getQueries(),
            );
        } catch (Throwable $e) {
            $query = DB::raw('ROLLBACK');
            $this->adapter->execute(statement: $query->getPreparedStatement());
            throw $e;
        }
    }
    protected function getQueries(): array
    {
        return [
            DB::raw('BEGIN'),
            ...$this->queries,
            DB::raw('COMMIT'),
        ];
    }
}