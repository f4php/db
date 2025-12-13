<?php

declare(strict_types=1);

namespace F4;

use BadMethodCallException;
use F4\DB\Adapter\AdapterInterface;
use F4\DB\QueryBuilder;
use F4\DB\QueryBuilderInterface;
use F4\Config;
use TypeError;

use function str_contains;

/**
 * 
 * DB is a QueryBuilder wrapper that add support for both static and non-static method calls 
 * and acts as a main entry point for building queries
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class DB
{
    protected QueryBuilderInterface $queryBuilder;
    public function __construct(?string $connectionString = null, string|AdapterInterface $adapter = Config::DB_ADAPTER_CLASS)
    {
        $this->queryBuilder = new QueryBuilder(connectionString: $connectionString, adapter: $adapter);
    }
    public function __call(string $method, array $arguments): QueryBuilderInterface
    {
        try {
            return $this->queryBuilder->$method(...$arguments);
        }
        catch(TypeError $e) {
            throw match(str_contains(
                haystack: $e->getMessage(),
                needle: 'Return value must be of type F4\DB\QueryBuilderInterface',
            )) {
                true => new BadMethodCallException(message: "Call to unsupported method {$method}()"),
                default => $e,
            };
        }
    }
    public static function __callStatic(string $method, array $arguments): QueryBuilderInterface
    {
        try {
            return new static()->$method(...$arguments);
        }
        catch(TypeError $e) {
            throw match(str_contains(
                haystack: $e->getMessage(),
                needle: 'Return value must be of type F4\DB\QueryBuilderInterface',
            )) {
                true => new BadMethodCallException(message: "Call to unsupported method {$method}()"),
                default => $e,
            };
        }
    }
    public static function escapeIdentifier(string $identifier): string
    {
        return new static()->queryBuilder->escapeIdentifier($identifier);
    }
}