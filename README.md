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

PostgreSQL is the primary, first-class database and `ext-pgsql` is a package requirement. Optional adapters are also included for SQLite (`ext-sqlite3`) and MySQL (`ext-mysqli`); these extensions are listed under Composer `suggest` and must be installed separately when the corresponding adapter is used. The optional adapters do not currently remove the package-level `ext-pgsql` requirement.

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
    #[\SensitiveParameter]
    public const string DB_PASSWORD = '';
    public const string DB_SCHEMA = '';
    public const ?string DB_APP_NAME = null;
    public const string DB_ADAPTER_CLASS = \F4\DB\Adapter\PostgresqlAdapter::class;
    public const bool DB_PERSIST = true;
    public const bool DEBUG_MODE = true;
    public const string TIMEZONE = '';
}
```

### Database Adapters

The active adapter is selected via `DB_ADAPTER_CLASS`, or by passing an adapter instance to a builder with `useAdapter()`.

| Adapter | Support level | PHP extension | Prepared parameters | Identifier quoting |
| --- | --- | --- | --- | --- |
| `PostgresqlAdapter` | First-class; default | `ext-pgsql` | `$1`, `$2`, … | `"identifier"` |
| `SqliteAdapter` | Optional; not first-class | `ext-sqlite3` | `?` | `"identifier"` |
| `MysqlAdapter` | Optional; not first-class | `ext-mysqli` | `?` | `` `identifier` `` |

The optional adapters provide connections, prepared-parameter binding, result fetching and conversion, error mapping, and database-appropriate identifier quoting. The query builder itself remains PostgreSQL-oriented, so availability of an adapter does not imply that every builder method emits SQL accepted by that database.

#### PostgreSQL configuration

`PostgresqlAdapter` uses all of the configuration constants shown above. When no explicit connection string is supplied, it builds one in this form:

```text
host='localhost' port='5432' dbname='application' user='app' password='secret'
```

If `DB_HOST` begins with `/`, it is treated as a Unix-socket directory and `DB_PORT` is omitted. `DB_CHARSET`, `DB_SCHEMA`, `DB_APP_NAME`, and `TIMEZONE` are applied after connecting, and `DB_PERSIST` selects persistent or non-persistent PostgreSQL connections.

#### SQLite configuration

For SQLite, `DB_NAME` is the database filename. Use `:memory:` for an in-memory database:

```php
class Config {
    public const string DB_NAME = '/var/data/app.sqlite';
    public const string DB_ADAPTER_CLASS = \F4\DB\Adapter\SqliteAdapter::class;
}
```

An explicit non-empty constructor connection string replaces `DB_NAME`; a null or empty string falls back to `DB_NAME`. `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SCHEMA`, `DB_APP_NAME`, `DB_PERSIST`, and `TIMEZONE` are not used. The adapter enables foreign-key enforcement and a 5-second busy timeout when it opens a connection.

#### MySQL configuration

For MySQL, configure the same core constants used by PostgreSQL, with MySQL-specific values:

```php
class Config {
    public const string DB_HOST = 'localhost';
    public const string DB_PORT = '3306';
    public const string DB_NAME = 'application';
    public const string DB_USERNAME = 'app';
    public const string DB_PASSWORD = 'secret';
    public const string DB_CHARSET = 'utf8mb4';
    public const string TIMEZONE = '';
    public const bool DB_PERSIST = true;
    public const string DB_ADAPTER_CLASS = \F4\DB\Adapter\MysqlAdapter::class;
}
```

When no explicit connection string is supplied, `MysqlAdapter` builds the same key/value form as `PostgresqlAdapter`:

```text
host='localhost' port='3306' dbname='application' user='app' password='secret'
```

If `DB_HOST` begins with `/`, it is treated as a Unix-socket path and the generated string omits `port`. Explicit strings may additionally use `database`, `username`, `charset`, and `socket` keys as aliases or connection options:

```php
$adapter = new \F4\DB\Adapter\MysqlAdapter(
    "host='db.internal' port='3306' dbname='application' "
    . "user='app' password='secret' charset='utf8mb4'",
);

$rows = DB::select()
    ->from('user')
    ->useAdapter($adapter)
    ->asTable();
```

Inside a quoted MySQL connection-string value, escape the active quote and a
literal backslash with `\` (for example, `password='can\'t\\stop'`). Single- and
double-quoted values are supported. Parsing is strict: unterminated values and
non-whitespace text after a closing quote are rejected instead of being
partially interpreted as connection options. Generated strings apply these
rules automatically using `DB_CHARSET` so multibyte characters remain intact.

`DB_CHARSET` is applied with `mysqli::set_charset()`, `TIMEZONE` is applied as the session `time_zone`, and `DB_PERSIST` controls the `mysqli` persistent-host prefix. `DB_SCHEMA` and `DB_APP_NAME` are not used by `MysqlAdapter`.

#### Optional-adapter SQL compatibility

SQLite and MySQL support is intentionally not first-class. In particular:

- PostgreSQL-specific raw expressions that use double-quoted identifiers may need rewriting for MySQL.
- `onConflict()`, `doNothing()`, and `doUpdateSet()` generate PostgreSQL/SQLite-style `ON CONFLICT` syntax; MySQL instead uses `ON DUPLICATE KEY UPDATE`.
- MySQL does not support `FULL OUTER JOIN` and does not generally support DML `RETURNING`; lateral and set-operation syntax is version-dependent.
- SQLite does not support lateral joins or `DROP TABLE ... CASCADE`; support for features such as `RETURNING` and right/full joins also depends on the deployed SQLite version.
- Set-operation and grouping variants vary by MySQL and SQLite version. Validate generated SQL against the target server and use `raw()` where a database-specific statement is required.

#### Optional result conversion

Both optional adapters expose `convertResultValue()` for subclass customization and accept an optional constructor callback. SQLite callbacks receive the value, output column name, column index, and SQLite storage-class constant:

```php
$adapter = new \F4\DB\Adapter\SqliteAdapter(
    ':memory:',
    resultConverter: static fn (
        mixed $value,
        string $columnName,
        int $columnIndex,
        int $sqliteType,
    ): mixed => $columnName === 'is_active' ? (bool) $value : $value,
);
```

MySQL callbacks additionally receive the MySQL field flags:

```php
$adapter = new \F4\DB\Adapter\MysqlAdapter(
    resultConverter: static fn (
        mixed $value,
        string $columnName,
        int $columnIndex,
        int $mysqlType,
        int $mysqlFlags,
    ): mixed => $columnName === 'is_active' ? (bool) $value : $value,
);
```

Mapping by an explicit output alias is recommended for application-level types such as booleans because SQLite storage classes and MySQL field metadata do not always preserve that semantic distinction.

## Key Concepts

DB aims to replicate SQL syntax using native PHP expressions as closely as possible.

It is primarily focused on PostgreSQL syntax. `PostgresqlAdapter` is first-class; `SqliteAdapter` and `MysqlAdapter` provide optional, non-first-class execution support. Identifiers are quoted by the *active* adapter at query-render time, but raw expressions and SQL grammar remain the caller's responsibility.

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

`{#}` for a scalar, `null`, or `DateTimeInterface` value

`{#,...#}` for an array of scalar, `null`, or `DateTimeInterface` values

`{#::#}` for a DB Query Builder object instance

These placeholders are internal builder syntax. At preparation time, the active adapter converts scalar placeholders to `$1`, `$2`, … for PostgreSQL or positional `?` parameters for SQLite and MySQL.

PostgreSQL normalizes `DateTimeInterface` values as `Y-m-d\TH:i:s.uP`, preserving
the timezone offset and six-digit microseconds. Unsupported objects are rejected
when the fragment is constructed, including objects nested inside a comma
placeholder array.

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
    ->doUpdateSet(['value' => 'dark', '"updated_at" = NOW()'])
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

### ORDER BY Identifiers and SQL Expressions

`orderBy()` follows the same two parsing modes as other query clauses. Lone
column identifiers are escaped through the active adapter; custom SQL strings
are used as-is:

```php
// Recognized identifiers are escaped: ORDER BY "level", "user"."name"
DB::select()->from('user')->orderBy('level', 'user.name');

// The associative direction form also escapes its identifier
DB::select()->from('user')->orderBy(['created_at' => 'DESC']);

// Other strings are trusted SQL and remain unchanged
DB::select()->from('user')->orderBy('"created_at" DESC NULLS LAST');

// Bind values in custom SQL templates; do not interpolate untrusted values
DB::select()->from('user')->orderBy([
    'CASE WHEN "priority" = {#} THEN 0 ELSE 1 END' => 'high',
]);
```

Custom SQL strings are developer-authored templates. Only their placeholder
values should contain untrusted input.

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

`$query->getPreparedStatement()->query` to get SQL using the active adapter's parameter convention: PostgreSQL produces `$1`, `$2`, …, while SQLite and MySQL produce positional `?` parameters. An explicit enumerator callback overrides the adapter. Standalone `Fragment` instances without adapter context retain the PostgreSQL-style `$n` fallback

`$query->getPreparedStatement()->parameters` to get array of bound parameters

## Data Types

DB attempts to cast returned values to appropriate PHP types, but since PHP and DBMS type systems are not fully compatible, some inconsistencies may occur. Type conversion is adapter-specific.

The **SQLite adapter** returns values in SQLite's native storage classes by default; supply a result-converter callback to the `SqliteAdapter` constructor (or override `convertResultValue()`) to map values to application types.

The **MySQL adapter** returns values from the `mysqli` prepared-statement protocol and decodes columns reported as MySQL `JSON` into associative arrays. MySQL does not distinguish `BOOLEAN` from `TINYINT(1)` reliably in result metadata, so boolean and other application-specific conversion should use the optional constructor callback or an override of `convertResultValue()`.

The **PostgreSQL adapter** automatically applies the following casting rules:

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
$admins = $base->where(['role' => 'admin'])->asTable();       // Mutates $base!
$regularUsers = $base->where(['role' => 'user'])->asTable();  // Has BOTH conditions!

// ✅ RIGHT - clone the base
$base = DB::select()->from('user');
$admins = (clone $base)->where(['role' => 'admin'])->asTable();
$regularUsers = (clone $base)->where(['role' => 'user'])->asTable();

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
