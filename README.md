# Overview

DB is a database query builder, core package for [F4](https://github.com/f4php/f4), a lightweight web development framework.

## Configuration

DB relies on the following constants in your environment config:

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

DB tries to recreate SQL syntax in PHP-native expressions as much as possible.

DB is focused around PostgreSQL syntax and hasn't been tested with other DBMS's, although an `Adapter` approach allows a developer to use other database engines.

Currently, DB supports a substantial but still limited subset of SQL syntax, which grows slowly as new features are added.

Currently supported keywords are:

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
`insert()`,
`intersect()`,
`intersectAll()`,
`into()`,
`join()`,
`leftJoin()`,
`leftJoinLateral()`,
`leftOuterJoin()`,
`limit()`,
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

Additionally, the following methods support static invokation:

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

It is the developer's responsibility to follow SQL grammar when daisy-chaining DB method calls.

## Placeholders

DB utilizes a concept of placeholders, which follow custom (non-standard) syntax convention to allow a developer to place/substitute variable values in a query as subqueries or (potentially) complex bound parameters.

Three types of placeholders are supported:

`{#}` for a scalar value

`{#,...#}` for an array

`{#::#}` for a DB object instance

Please see the Usage Examples section below for more details.

## Getting Results

When query is built, you may want to use the following tail methods to fetch results:

`$db->asTable()` to fetch all rows

`$db->commit()` same as `asTable()`

`$db->asRow()` to fetch one row

`$db->asValue()` to fetch scalar value

`$db->asSQL()` to get raw SQL statement without bound parameters

`$db->getPreparedStatement()->query` same as `asSQL()`

`$db->getPreparedStatement()->parameters` returns an array of statement-bound parameters

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

This will expand to the following SQL statement:

```sql
  SELECT * FROM "table1" AS "t1" RIGHT JOIN "table2" AS "t2" USING ("fieldA", "fieldB")
```

and fetch all available rows as a multi-dimensional php array.

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
                    '"handled" = false',
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