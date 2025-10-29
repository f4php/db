# Overview

**DB** is a database query builder and a core package of [F4](https://github.com/f4php/f4), a lightweight web development framework.

## Configuration

DB relies on the following constants defined in your environment configuration:

```php

namespace F4;

class Config {
    public const string DB_HOST = 'localhost';
    public const string DB_CHARSET = 'UTF8';
    public const string DB_PORT = '5432';
    public const string DB_NAME = '';
    public const string DB_USERNAME = '';
    #[SensitiveParameter]
    public const string DB_PASSWORD = '';
    public const string DB_SCHEMA = '';
    public const ?string DB_APP_NAME = null;
    public const string DB_ADAPTER_CLASS = \F4\DB\Adapter\PostgresqlAdapter::class;
    public const bool DB_PERSIST = true;
}
```

## Key Concepts

DB aims to replicate SQL syntax using native PHP expressions as closely as possible.

It is primarily focused on PostgreSQL syntax and has not been tested with other DBMSs. However, its adapter-based architecture enables support for other database engines.

DB currently supports a significant but still limited subset of SQL syntax, which is gradually expanding as new features are added.

Currently supported keywords are:

`crossJoin()`,
`crossJoinLateral()`,
`delete()`,
`doNothing()`,
`doUpdateSet()`,
`dropTable()`,
`dropTableIfExists()`,
`dropTableWithCascade()`,
`dropTableIfExistsWithCascade()`,
`except()`,
`exceptAll()`,
`from()`,
`fullOuterJoin()`,
`group()`, `groupBy()`,
`groupByAll()`,
`groupByDistinct()`,
`having()`,
`innerJoin()`,
`innerJoinLateral()`,
`insert()`,
`intersect()`,
`intersectAll()`,
`into()`,
`join()`,
`joinLateral()`,
`leftJoin()`,
`leftJoinLateral()`,
`leftOuterJoin()`,
`limit()`,
`naturalJoin()`,
`naturalLeftOuterJoin()`,
`naturalRightOuterJoin()`,
`offset()`,
`on()`,
`onConflict()`,
`order()`, `orderBy()`,
`raw()`,
`returning()`,
`rightJoin()`,
`rightOuterJoin()`,
`select()`,
`selectDistinct()`,
`set()`,
`update()`,
`union()`,
`unionAll()`,
`using()`,
`values()`,
`where()`,
`with()`,
`withRecursive()`

The following methods support static invokation:

`DB::raw()`,
`DB::delete()`,
`DB::dropTable()`,
`DB::dropTableIfExists()`,
`DB::dropTableWithCascade()`,
`DB::dropTableIfExistsWithCascade()`,
`DB::insert()`,
`DB::select()`,
`DB::selectDistinct()`,
`DB::update()`,
`DB::with()`,
`DB::withRecursive()`

It is the developer's responsibility to maintain valid SQL grammar when chaining DB method calls.

## Placeholders

DB introduces a custom (non-standard) placeholder syntax that allows substitution of variable values, subqueries, or complex bound parameters.

Three placeholder types are supported:

`{#}` for a scalar value

`{#,...#}` for an array

`{#::#}` for a DB object instance

Refer to the Usage Examples section below for practical demonstration.

## Getting Results

After building a query, the following tail methods are available for fetching results:

`$db->asTable()` to fetch all rows

`$db->commit()` same as `asTable()`

`$db->asRow()` to fetch one row

`$db->asValue()` to fetch scalar value

`$db->asSQL()` to get raw SQL statement without bound parameters

`$db->getPreparedStatement()->query` same as `asSQL()`

`$db->getPreparedStatement()->parameters` returns an array of statement-bound parameters

## Data Types

DB attempts to cast returned values to appropriate PHP types, but since PHP and DBMS type systems are not fully compatible, some inconsistencies may occur.

The PostgreSQL adapter automatically applies the following casting rules:

```php
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
            default => null
        };
        break;
    default:
  }
```

## Usage Examples

### Simple Query

```php
use F4\DB;

$rows = DB::select()
    ->from('table1 t1')
    ->rightJoin("table2 t2")
    ->using('fieldA', 'fieldB')
    ->asTable();
```

This code will internally expand to the following SQL statement:

```sql
  SELECT * FROM "table1" AS "t1" RIGHT JOIN "table2" AS "t2" USING ("fieldA", "fieldB")
```
and fetch all available rows as a multi-dimensional PHP array.

### Slightly More Complex Query

```php
use F4\DB;
use F4\DB\AnyConditionCollection as any;

// ...

$minEmployeesCount = 5;
$statusFilter = ['ongoing', 'started'];

$rows = DB::with([
    'project' => DB::select([
            '"project".*',
            '"risks"."relation_jsonb" AS "unhandledRisks"',
        ])
        ->from('project')
        ->leftJoinLateral([
            '({#::#}) AS "risks"' => DB::select('jsonb_agg(to_jsonb("risk".*)) AS "relation_jsonb"')
                ->from('risk')
                ->where([
                    '"project"."projectUUID" = "risk"."projectUUID"',
                    'handled' => false, // Note: subquery placeholder ensures that all subquery parameters
                                        // are correctly bound and processed in the main query
                ]),
        ])
        ->on('true')
    ])
    ->select()
    ->from('project')
    ->where(
        '"unhandledRisks" IS NOT NULL',
        any::of([
          '"employeesCount" >= {#}' => $minEmployeesCount,
          'missionCritical' => true,
        ]),
        '"status" IN ({#,...#})' => $statusFilter,
    )
    ->asTable();
```