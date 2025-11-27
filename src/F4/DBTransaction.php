<?php

declare(strict_types=1);

namespace F4;

use BadMethodCallException;

use F4\Config;
use F4\DB;
use F4\DB\Adapter\AdapterInterface;
use Throwable;

use function array_map;
use function implode;
use function is_array;
use function is_string;
use function call_user_func_array;

/**
 * 
 * DBTransaction is a wrapper for executing atomic transactions
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
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
            'add'
            => $this->addQuery(...$arguments),
            default
            => throw new BadMethodCallException(message: "Unsupported method {$method}()")
        };
        return $this;
    }
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return match ($method) {
            'add'
            => call_user_func_array(callback: [new self(), $method], args: $arguments),
            default
            => throw new BadMethodCallException(message: "Unsupported method {$method}()")
        };
    }
    protected function addQuery(array|DB $query): static {
        $this->queries = [
            ...$this->queries,
            ...match(is_array($query)) {
                true => $query,
                default => [$query],
            }
        ];
        return $this;
    }
    public function asSQL(): mixed
    {
        return implode('; ', array_map(
            callback: function ($query) {
                $query->useAdapter($this->adapter);
                return $query->asSQL();
            },
            array: $this->getQueries(),
        ));
    }
    public function commit(): mixed
    {
        try {
            return array_map(
                callback: function ($query) {
                    $query->useAdapter($this->adapter);
                    $preparedStatement = $query->getPreparedStatement($this->adapter->enumerateParameters(...));
                    return $this->adapter->execute(statement: $preparedStatement);
                },
                array: $this->getQueries(),
            );
        }
        catch(Throwable $e) {
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