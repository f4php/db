<?php

declare(strict_types=1);

namespace F4;

use BadMethodCallException,
    TypeError
;
use F4\DB\{
    QueryBuilder,
    QueryBuilderInterface,
    Adapter\AdapterInterface,
};
use F4\Config;

use function str_contains;

/**
 *
 * DB is a QueryBuilder wrapper that adds support for both static and non-static method calls
 * and acts as main entry point for building queries
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 * @method static QueryBuilderInterface addColumn(...$arguments) Add column
 * @method static QueryBuilderInterface addColumnIfNotExists(...$arguments) Add column if not exists
 * @method static QueryBuilderInterface alterColumn(...$arguments) Alter column
 * @method static QueryBuilderInterface alterTableIfExists(...$arguments) Alter table if exists
 * @method static QueryBuilderInterface createIndex(...$arguments) Create index
 * @method static QueryBuilderInterface createIndexIfNotExists(...$arguments) Create index if not exists
 * @method static QueryBuilderInterface createTable(string $name, array $columns) Create table
 * @method static QueryBuilderInterface createTableIfNotExists(string $name, array $columns) Create table if not exists
 * @method static QueryBuilderInterface createView(...$arguments) Create view
 * @method static QueryBuilderInterface createOrReplaceView(...$arguments) Create or replace view
 * @method static QueryBuilderInterface createMaterializedView(...$arguments) Create materialized view
 * @method static QueryBuilderInterface createMaterializedViewIfNotExists(...$arguments) Create materialized view if not exists
 * @method static QueryBuilderInterface crossJoin(...$arguments) Cross join
 * @method static QueryBuilderInterface crossJoinLateral(...$arguments) Cross join lateral
 * @method static QueryBuilderInterface delete() Delete
 * @method static QueryBuilderInterface doNothing() Do nothing
 * @method static QueryBuilderInterface doUpdateSet(...$arguments) Do update set
 * @method static QueryBuilderInterface dropColumn(...$arguments) Drop column
 * @method static QueryBuilderInterface dropColumnIfExists(...$arguments) Drop column if exists
 * @method static QueryBuilderInterface dropTable(...$arguments) Drop table
 * @method static QueryBuilderInterface dropTableIfExists(...$arguments) Drop table if exists
 * @method static QueryBuilderInterface dropTableWithCascade(...$arguments) Drop table with cascade
 * @method static QueryBuilderInterface dropTableIfExistsWithCascade(...$arguments) Drop table if exists with cascade
 * @method static QueryBuilderInterface except() Except
 * @method static QueryBuilderInterface exceptAll(...$arguments) Except all
 * @method static QueryBuilderInterface from(...$arguments) From
 * @method static QueryBuilderInterface fullOuterJoin(...$arguments) Full outer join
 * @method static QueryBuilderInterface group(...$arguments) Group
 * @method static QueryBuilderInterface groupBy(...$arguments) Group by
 * @method static QueryBuilderInterface groupByAll(...$arguments) Group by all
 * @method static QueryBuilderInterface groupByDistinct(...$arguments) Group by distinct
 * @method static QueryBuilderInterface having(...$arguments) Having
 * @method static QueryBuilderInterface innerJoin(...$arguments) Inner join
 * @method static QueryBuilderInterface innerJoinLateral(...$arguments) Inner join lateral
 * @method static QueryBuilderInterface insert() Insert
 * @method static QueryBuilderInterface intersect() Intersect
 * @method static QueryBuilderInterface intersectAll() Intersect all
 * @method static QueryBuilderInterface into(...$arguments) Into
 * @method static QueryBuilderInterface join(...$arguments) Join
 * @method static QueryBuilderInterface joinLateral(...$arguments) Join lateral
 * @method static QueryBuilderInterface leftJoin(...$arguments) Left join
 * @method static QueryBuilderInterface leftJoinLateral(...$arguments) Left join lateral
 * @method static QueryBuilderInterface leftOuterJoin(...$arguments) Left outer join
 * @method static QueryBuilderInterface limit(int $limit, int $offset = 0) Limit
 * @method static QueryBuilderInterface naturalJoin(...$arguments) Natural join
 * @method static QueryBuilderInterface naturalLeftOuterJoin(...$arguments) Natural left outer join
 * @method static QueryBuilderInterface naturalRightOuterJoin(...$arguments) Natural right outer join
 * @method static QueryBuilderInterface offset(int $offset) Offset
 * @method static QueryBuilderInterface on(...$arguments) On
 * @method static QueryBuilderInterface onConflict(...$arguments) On conflict
 * @method static QueryBuilderInterface order(...$arguments) Order
 * @method static QueryBuilderInterface orderBy(...$arguments) Order by
 * @method static QueryBuilderInterface raw(...$arguments) Raw
 * @method static QueryBuilderInterface returning(...$arguments) Returning
 * @method static QueryBuilderInterface rightJoin(...$arguments) Right join
 * @method static QueryBuilderInterface rightOuterJoin(...$arguments) Right outer join
 * @method static QueryBuilderInterface select(...$arguments) Select
 * @method static QueryBuilderInterface selectDistinct(...$arguments) Select distinct
 * @method static QueryBuilderInterface set(...$arguments) Set
 * @method static QueryBuilderInterface update(...$arguments) Update
 * @method static QueryBuilderInterface union(...$arguments) Union
 * @method static QueryBuilderInterface unionAll(...$arguments) Union all
 * @method static QueryBuilderInterface useAdapter(AdapterInterface $adapter) Use adapter
 * @method static QueryBuilderInterface using(...$arguments) Using
 * @method static QueryBuilderInterface values(...$arguments) Values
 * @method static QueryBuilderInterface where(...$arguments) Where
 * @method static QueryBuilderInterface with(...$arguments) With
 * @method static QueryBuilderInterface withRecursive(...$arguments) With recursive
 *
 * @method QueryBuilderInterface addColumn(...$arguments) Add column
 * @method QueryBuilderInterface addColumnIfNotExists(...$arguments) Add column if not exists
 * @method QueryBuilderInterface alterColumn(...$arguments) Alter column
 * @method QueryBuilderInterface alterTableIfExists(...$arguments) Alter table if exists
 * @method QueryBuilderInterface createIndex(...$arguments) Create index
 * @method QueryBuilderInterface createIndexIfNotExists(...$arguments) Create index if not exists
 * @method QueryBuilderInterface createTable(string $name, array $columns) Create table
 * @method QueryBuilderInterface createTableIfNotExists(string $name, array $columns) Create table if not exists
 * @method QueryBuilderInterface createView(...$arguments) Create view
 * @method QueryBuilderInterface createOrReplaceView(...$arguments) Create or replace view
 * @method QueryBuilderInterface createMaterializedView(...$arguments) Create materialized view
 * @method QueryBuilderInterface createMaterializedViewIfNotExists(...$arguments) Create materialized view if not exists
 * @method QueryBuilderInterface crossJoin(...$arguments) Cross join
 * @method QueryBuilderInterface crossJoinLateral(...$arguments) Cross join lateral
 * @method QueryBuilderInterface delete(...$arguments) Delete
 * @method QueryBuilderInterface doNothing(...$arguments) Do nothing
 * @method QueryBuilderInterface doUpdateSet(...$arguments) Do update set
 * @method QueryBuilderInterface dropColumn(...$arguments) Drop column
 * @method QueryBuilderInterface dropColumnIfExists(...$arguments) Drop column if exists
 * @method QueryBuilderInterface dropTable(...$arguments) Drop table
 * @method QueryBuilderInterface dropTableIfExists(...$arguments) Drop table if exists
 * @method QueryBuilderInterface dropTableWithCascade(...$arguments) Drop table with cascade
 * @method QueryBuilderInterface dropTableIfExistsWithCascade(...$arguments) Drop table if exists with cascade
 * @method QueryBuilderInterface except(...$arguments) Except
 * @method QueryBuilderInterface exceptAll(...$arguments) Except all
 * @method QueryBuilderInterface from(...$arguments) From
 * @method QueryBuilderInterface fullOuterJoin(...$arguments) Full outer join
 * @method QueryBuilderInterface group(...$arguments) Group
 * @method QueryBuilderInterface groupBy(...$arguments) Group by
 * @method QueryBuilderInterface groupByAll(...$arguments) Group by all
 * @method QueryBuilderInterface groupByDistinct(...$arguments) Group by distinct
 * @method QueryBuilderInterface having(...$arguments) Having
 * @method QueryBuilderInterface innerJoin(...$arguments) Inner join
 * @method QueryBuilderInterface innerJoinLateral(...$arguments) Inner join lateral
 * @method QueryBuilderInterface insert(...$arguments) Insert
 * @method QueryBuilderInterface intersect(...$arguments) Intersect
 * @method QueryBuilderInterface intersectAll(...$arguments) Intersect all
 * @method QueryBuilderInterface into(...$arguments) Into
 * @method QueryBuilderInterface join(...$arguments) Join
 * @method QueryBuilderInterface joinLateral(...$arguments) Join lateral
 * @method QueryBuilderInterface leftJoin(...$arguments) Left join
 * @method QueryBuilderInterface leftJoinLateral(...$arguments) Left join lateral
 * @method QueryBuilderInterface leftOuterJoin(...$arguments) Left outer join
 * @method QueryBuilderInterface limit(int $limit, int $offset = 0) Limit
 * @method QueryBuilderInterface naturalJoin(...$arguments) Natural join
 * @method QueryBuilderInterface naturalLeftOuterJoin(...$arguments) Natural left outer join
 * @method QueryBuilderInterface naturalRightOuterJoin(...$arguments) Natural right outer join
 * @method QueryBuilderInterface offset(int $offset) Offset
 * @method QueryBuilderInterface on(...$arguments) On
 * @method QueryBuilderInterface onConflict(...$arguments) On conflict
 * @method QueryBuilderInterface order(...$arguments) Order
 * @method QueryBuilderInterface orderBy(...$arguments) Order by
 * @method QueryBuilderInterface raw(...$arguments) Raw
 * @method QueryBuilderInterface returning(...$arguments) Returning
 * @method QueryBuilderInterface rightJoin(...$arguments) Right join
 * @method QueryBuilderInterface rightOuterJoin(...$arguments) Right outer join
 * @method QueryBuilderInterface select(...$arguments) Select
 * @method QueryBuilderInterface selectDistinct(...$arguments) Select distinct
 * @method QueryBuilderInterface set(...$arguments) Set
 * @method QueryBuilderInterface update(...$arguments) Update
 * @method QueryBuilderInterface union(...$arguments) Union
 * @method QueryBuilderInterface unionAll(...$arguments) Union all
 * @method QueryBuilderInterface useAdapter(AdapterInterface $adapter) Use adapter
 * @method QueryBuilderInterface using(...$arguments) Using
 * @method QueryBuilderInterface values(...$arguments) Values
 * @method QueryBuilderInterface where(...$arguments) Where
 * @method QueryBuilderInterface with(...$arguments) With
 * @method QueryBuilderInterface withRecursive(...$arguments) With recursive
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