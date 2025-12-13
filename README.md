# Overview

**DB** is a database query builder and a core package of [F4](https://github.com/f4php/f4), a lightweight web development framework.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Key Concepts](#key-concepts)
- [Placeholders](#placeholders)
- [WHERE Clauses](#where-clauses)
- [Common Operations](#common-operations)
- [Getting Results](#getting-results)
- [Data Types](#data-types)
- [Best Practices](#best-practices)
- [Common Pitfalls](#common-pitfalls)

## Installation

```bash
composer require f4php/db
```

## Quick Start

```php
use F4\DB;

// Simple query
$users = DB::select(['id', 'name', 'email'])
    ->from('user')
    ->where(['active' => true])
    ->asTable();

// Single row
$user = DB::select()
    ->from('user')
    ->where(['id' => 5])
    ->asRow();

// Single value
$count = DB::select('COUNT(*)')
    ->from('user')
    ->where(['active' => true])
    ->asValue();
```

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

It is the developer's responsibility to maintain valid SQL grammar when chaining DB method calls.

## Placeholders

DB introduces a custom (non-standard) placeholder syntax that allows substitution of variable values, subqueries, or complex bound parameters.

Three placeholder types are supported:

`{#}` for a scalar value

`{#,...#}` for an array

`{#::#}` for a DB Query Builder object instance

Refer to the Usage Examples section below for practical demonstration.

## WHERE Clauses

DB provides intuitive WHERE clause construction using associative arrays:

```php
// Simple equality
DB::select()->from('user')->where(['name' => 'John', 'active' => true])
// WHERE "name" = $1 AND "active" = $2

// IN clause with arrays
DB::select()->from('user')->where(['status' => ['active', 'pending']])
// WHERE "status" IN ($1, $2)

// NULL checks
DB::select()->from('user')->where(['deleted_at' => null])
// WHERE "deleted_at" IS NULL

// Custom expressions with placeholders
DB::select()->from('user')->where(['"age" >= {#}' => 18])
// WHERE "age" >= $1

// OR conditions
use F4\DB\AnyConditionCollection as any;

DB::select()->from('user')->where(any::of(['role' => 'admin', 'role' => 'moderator']))
// WHERE ("role" = $1 OR "role" = $2)

// Nested conditions
use F4\DB\ConditionCollection as all;

DB::select()->from('user')->where([
    'active' => true,
    any::of([
        'role' => 'admin',
        all::of(['"age" >= {#}' => 18, 'verified' => true])
    ])
])
// WHERE "active" = $1 AND ("role" = $2 OR ("age" >= $3 AND "verified" = $4))

// NOT conditions
use F4\DB\NoneConditionCollection as none;

DB::select()->from('user')->where(none::of(['banned' => true, 'deleted' => true]))
// WHERE NOT ("banned" = $1 OR "deleted" = $2)
```

## Common Operations

### INSERT with Values

```php

use F4\DB\Fragment;

DB::insert()
    ->into('user')
    ->values([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'created_at' => new Fragment('NOW()') // Fragment wrapper must be used to add SQL expression without converting it to a bound parameter
    ])
    ->returning('id')
    ->asValue();
```

### UPDATE Statement

```php
DB::update('user')
    ->set(['active' => false, '"updated_at" = NOW()'])
    ->where(['id' => 123])
    ->commit();
```

### DELETE Statement

```php
DB::delete()
    ->from('user')
    ->where(['active' => false, '"last_login" < {#}' => '2023-01-01'])
    ->commit();
```

### UPSERT (INSERT with ON CONFLICT)

```php
DB::insert()
    ->into('settings')
    ->values(['key' => 'theme', 'value' => 'dark'])
    ->onConflict('key')
    ->doUpdateSet(['value' => 'dark', '"updated_at" = "NOW()'])
    ->commit();
```

### JOIN Operations

```php
// INNER JOIN with ON clause
DB::select(['u.name', 'o.total'])
    ->from('user u')
    ->innerJoin('order o')
    ->on(['"u"."id" = "o"."user_id"'])
    ->asTable();

// Multiple JOINs
DB::select()
    ->from('order o')
    ->join('user u')->on(['"o"."user_id" = "u"."id"'])
    ->leftJoin('payment p')->on(['"o"."id" = "p"."order_id"'])
    ->where(['o.status' => 'completed'])
    ->asTable();

// USING clause for natural joins
DB::select()
    ->from('user u')
    ->join('profile p')
    ->using('user_id')
    ->asTable();
```

### Common Table Expressions (CTEs)

```php
// Simple CTE
DB::with(['active_user' => DB::select()->from('user')->where(['active' => true])])
    ->select()
    ->from('active_user')
    ->where(['"created_at" > {#}' => '2024-01-01'])
    ->asTable();

// Multiple CTEs
DB::with([
    'active_user' => DB::select()->from('user')->where(['active' => true]),
    'recent_order' => DB::select()->from('order')->where(['"created_at" > {#}' => '2024-01-01'])
])
    ->select(['u.*', 'o.total'])
    ->from('active_user u')
    ->join('recent_order o')->on(['"u"."id" = "o"."user_id"'])
    ->asTable();

// Recursive CTE (for hierarchical data)
DB::withRecursive([
    'org_tree' => DB::select(['id', 'name', 'parent_id', '1 AS "level"'])
        ->from('department')
        ->where(['parent_id' => null])
        ->union()
        ->select(['d.id', 'd.name', 'd.parent_id', '"t"."level" + 1'])
        ->from('department d')
        ->join('org_tree t')->on(['"d"."parent_id" = "t"."id"'])
])
    ->select()
    ->from('org_tree')
    ->orderBy('level', 'name')
    ->asTable();
```

### Subqueries with `{#::#}` Placeholder

```php
// Subquery in SELECT clause
DB::select([
    'u.*',
    'order_count' => DB::select('COUNT(*)')
        ->from('order o')
        ->where(['"o"."user_id" = "u"."id"'])
])
    ->from('user u')
    ->asTable();
// SELECT "u".*, (SELECT COUNT(*) FROM "order" AS "o" WHERE "o"."user_id" = "u"."id") AS "order_count" FROM "user" AS "u"

// Subquery in WHERE clause
DB::select()
    ->from('user')
    ->where([
        'id' => DB::select('user_id')
            ->from('order')
            ->where(['status' => 'completed'])
            ->limit(1)
    ])
    ->asTable();
// WHERE "id" = (SELECT "user_id" FROM "order" WHERE "status" = $1 LIMIT 1)

// Subquery in FROM clause (derived table)
DB::select(['summary.*'])
    ->from([
        'summary' => DB::select(['user_id', 'COUNT(*) AS "total"'])
            ->from('order')
            ->groupBy('user_id')
    ])
    ->where(['"total" > {#}' => 10])
    ->asTable();
// FROM (SELECT "user_id", COUNT(*) AS "total" FROM "order" GROUP BY ("user_id")) AS "summary"

// Complex subquery with LATERAL JOIN
DB::select(['"user".*', '"latest_order"."created_at" AS "last_order_date"'])
    ->from('user')
    ->leftJoinLateral([
        '({#::#}) AS "latest_order"' => DB::select('created_at')
            ->from('order')
            ->where(['"user_id" = "user"."id"'])
            ->orderBy('"created_at" DESC')
            ->limit(1)
    ])
    ->on('true')
    ->asTable();
```

### Complex Query example

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

## Getting Results

After building a query, the following tail methods are available for fetching results:

`$query->asTable()` to fetch all rows

`$query->commit()` same as `asTable()`

`$query->asRow()` to fetch one row

`$query->asValue($index)` to fetch scalar value (by numeric index or column name)

`$query->asSQL()` to get SQL with values escaped (for debugging - **not for execution**)

`$query->getPreparedStatement()->query` to get SQL with parameter placeholders as supported by the database server ($1, $2, etc.)

`$query->getPreparedStatement()->parameters` to get array of bound parameters

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
## Best Practices

- **Always use placeholders for user input** - Never concatenate values into SQL strings to prevent SQL injection
- **Use `asRow()` instead of `asTable()[0]`** when fetching a single row - It's more efficient and stops after finding one result
- **Use `asValue()` for single values** like `COUNT(*)`, `MAX(id)`, or `SUM(amount)` instead of fetching a full row
- **Prefer static methods for new queries** - Use `DB::select()` to start a new query chain, instance methods for chaining
- **Don't reuse builder instances** - Each query should use a fresh instance to avoid mutations accumulating

## Common Pitfalls

### Builder Instances Are Mutable

Builder instances accumulate mutations. Don't reuse them:

```php
// ❌ WRONG - mutations accumulate
$base = DB::select()->from('user');
$admins = $base->where(['role' => 'admin'])->asTable();  // Mutates $base!
$regularUsers = $base->where(['role' => 'user'])->asTable();    // Has BOTH conditions!

// ✅ RIGHT - create fresh instances
$admins = DB::select()->from('user')->where(['role' => 'admin'])->asTable();
$regularUsers = DB::select()->from('user')->where(['role' => 'user'])->asTable();
```

### Match Placeholder Types to Values

Use the correct placeholder for each value type:

```php
// ❌ WRONG - scalar placeholder with array value
where(['"status" IN {#}' => ['a', 'b']])  // Error!

// ✅ RIGHT - array placeholder with array value
where(['"status" IN ({#,...#})' => ['a', 'b']])

// ❌ WRONG - array placeholder with scalar value
where(['"name" = ({#,...#})' => 'John'])  // Error!

// ✅ RIGHT - scalar placeholder with scalar value
where(['"name" = {#}' => 'John'])
```

### Don't Manually Quote Auto-Quoted Identifiers

When using the associative array shorthand, identifiers are quoted automatically:

```php
// ❌ AVOID - missing double quoting for identifiers
where(['name = {#}' => 'John'])  // Produces unquoted: name = $1

// ✅ RIGHT - let DB quote it
where(['name' => 'John'])  // Produces: "name" = $1

// ✅ ALSO RIGHT - use quotes in custom expressions
where(['"age" > {#}' => 18])  // Custom expression, you control quoting
```

### Don't Forget Execution Methods

Building a query doesn't execute it:

```php
// ❌ WRONG - no execution
$query = DB::select()->from('user');  // Just builds the query, doesn't run it

// ✅ RIGHT - call an execution method
$users = DB::select()->from('user')->asTable();      // Execute and fetch all
$user = DB::select()->from('user')->asRow();         // Execute and fetch one
$count = DB::select('COUNT(*)')->from('user')->asValue();  // Execute and fetch value
```