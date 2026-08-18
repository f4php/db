<?php

declare(strict_types=1);

namespace F4;

use BadMethodCallException,
    Throwable;
use F4\Config;
use F4\DB\{
    QueryBuilderInterface,
    Adapter\AdapterInterface,
    Exception\RollbackFailedException,
};

use function
    array_map,
    implode,
    is_array,
    is_string,
    sprintf
;

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
        if ($this->queries === []) {
            throw new BadMethodCallException('Cannot commit a transaction in uncertain state or with no queued queries');
        }

        $runQuery = function (QueryBuilderInterface $query): mixed {
            $preparedStatement = $query->getPreparedStatement($this->adapter->enumerateParameters(...));
            return $this->adapter->execute($preparedStatement);
        };
        // BEGIN runs outside the try/catch: if it fails, this transaction never
        // started, so we must not roll back — the connection may already be inside
        // a transaction owned by the caller. Rethrow without touching it.
        $beginResult = $runQuery(DB::raw('BEGIN'));
        try {
            return [
                $beginResult,
                ...array_map(callback: $runQuery, array: $this->queries),
                $runQuery(DB::raw('COMMIT')),
            ];
        } catch (Throwable $e) {
            try {
                $runQuery(DB::raw('ROLLBACK'));
            } catch (Throwable $rollbackError) {
                // prevent replay on transaction in uncertain state
                $this->queries = [];
                // ROLLBACK failed: the connection may be left in a dirty transactional
                // state. Discard it so the poisoned handle is never reused, then surface
                // the rollback failure as the primary error (more severe than the query
                // failure), keeping the original query error available via getPrevious().
                // discardConnection() is contractually non-throwing, but a third-party
                // adapter could violate that; swallow any such error so it cannot mask
                // both the query failure and the rollback failure below.
                try {
                    $this->adapter->discardConnection();
                } catch (Throwable) {
                    // Best-effort discard; the RollbackFailedException below is preserved.
                }
                throw new RollbackFailedException(
                    message: sprintf('Transaction rollback failed: %s', $rollbackError->getMessage()),
                    code: 500,
                    previous: $e,
                );
            }
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
