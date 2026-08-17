# DB Query Builder - Agent Guide

DB is a database query builder with a fluent PHP interface that closely mirrors SQL syntax. It is primarily focused on PostgreSQL but ships with both a PostgreSQL and a SQLite adapter. This guide helps AI agents understand and work with the codebase effectively.

## Architecture Overview

### Core Components

- **DB** ([src/F4/DB.php](src/F4/DB.php)) - Main entry point supporting both static and instance method calls
- **QueryBuilder** ([src/F4/DB/QueryBuilder.php](src/F4/DB/QueryBuilder.php)) - Core query building logic, extends FragmentCollection
- **Fragment** ([src/F4/DB/Fragment.php](src/F4/DB/Fragment.php)) - Base class for SQL query fragments with parameter substitution
- **Adapters** ([src/F4/DB/Adapter/](src/F4/DB/Adapter/)) - Database-specific implementations: `PostgresqlAdapter` (default, `pgsql` ext) and `SqliteAdapter` (`SQLite3` ext)
- **DelimitedIdentifier** ([src/F4/DB/DelimitedIdentifier.php](src/F4/DB/DelimitedIdentifier.php)) - A `Fragment` holding a single identifier that quotes itself via the active adapter at render time
- **Reference types** ([src/F4/DB/Reference/](src/F4/DB/Reference/)) - Parse identifier strings (`table`, `t.col`, `col alias`) into `DelimitedIdentifier`s; extend `Fragment`
- **Collections** - Specialized fragment collections for different SQL clauses (WHERE, SELECT, JOIN, etc.)

### Key Abstractions

- `FragmentInterface` - Base interface for all SQL fragments; `getQuery()`/`getPreparedStatement()` accept an optional `?AdapterInterface $adapter` threaded at render time
- `FragmentCollectionInterface` - Interface for collections of fragments
- `QueryBuilderInterface` - Interface defining all query builder methods
- `AdapterInterface` - Database-specific escaping/execution; `getEscapedIdentifier()` must be connectionless

## Placeholder System

DB uses a custom placeholder syntax for parameter binding:

- `{#}` - Single scalar value (string, int, float, bool, null)
- `{#,...#}` - Array of values (expands to comma-separated placeholders)
- `{#::#}` - Subquery (accepts DB/Fragment objects)

The final prepared-statement placeholder form is adapter-specific: PostgreSQL enumerates as `$1, $2, …`, SQLite as `?`. Examples below show the PostgreSQL form.

These tokens are reserved throughout every custom SQL string. Placeholder
recognition deliberately does not analyze SQL lexical regions, so the tokens are
still interpreted inside quoted literals, quoted identifiers, comments, and
dollar-quoted bodies. Do not use the literal token sequences in SQL templates;
bind them as values when they represent data. This is an intentional template
language rule, not a request for an SQL lexer.

### Placeholder Examples

```php
// Scalar placeholder
DB::select()->where(['"age" > {#}' => 18])
// Produces: WHERE "age" > $1

// Array placeholder
DB::select()->where(['"status" IN ({#,...#})' => ['active', 'pending']])
// Produces: WHERE "status" IN ($1,$2)

// Subquery placeholder
DB::select()->where(['"count" = ({#::#})' => DB::select('COUNT(*)')->from('items')])
// Produces: WHERE "count" = (SELECT COUNT(*) FROM "items")
```

## Method Chaining Pattern

All QueryBuilder methods return `QueryBuilderInterface`, enabling fluent chaining:

```php
DB::select(['id', 'name'])
    ->from('users u')
    ->where(['active' => true])
    ->orderBy('name')
    ->limit(10);
```

## Executing Queries

### Result Methods

- `asTable()` - Fetch all rows as array (alias: `commit()`)
- `asRow()` - Fetch single row as associative array
- `asValue($index = 0)` - Fetch single scalar value (by index or column name)
- `asSQL()` - Get SQL string with escaped values (for debugging)
- `getPreparedStatement()` - Get PreparedStatement object with `->query` and `->parameters`

### Example

```php
$users = DB::select()->from('users')->where(['active' => true])->asTable();
$user = DB::select()->from('users')->where(['id' => 5])->asRow();
$count = DB::select('COUNT(*)')->from('users')->asValue();
```

## WHERE Clause Construction

### Associative Array Syntax

```php
// Simple equality
['name' => 'John']  // "name" = $1

// NULL check
['deleted_at' => null]  // "deleted_at" IS NULL

// IN clause (array value)
['status' => ['active', 'pending']]  // "status" IN ($1,$2)

// Custom expression (numeric key)
['"age" > {#}' => 18]  // "age" > $1

// Subquery
['count' => DB::select('COUNT(*)')->from('items')]  // "count" = (SELECT COUNT(*) FROM "items")
```

### Logical Operators

```php
use F4\DB\AnyConditionCollection as any;
use F4\DB\ConditionCollection as all;
use F4\DB\NoneConditionCollection as none;

// AND (default)
DB::select()->from('users')->where(['active' => true, 'verified' => true])
// WHERE "active" = $1 AND "verified" = $2

// OR
DB::select()->from('users')->where(any::of(['role' => 'admin', 'role' => 'moderator']))
// WHERE ("role" = $1 OR "role" = $2)

// NOT
DB::select()->from('users')->where(none::of(['banned' => true, 'deleted' => true]))
// WHERE NOT ("banned" = $1 OR "deleted" = $2)

// Nested
DB::select()->from('users')->where([
    'active' => true,
    any::of(['role' => 'admin', 'verified' => true])
])
// WHERE "active" = $1 AND ("role" = $2 OR "verified" = $3)
```

## Supported SQL Keywords

### Query Types

- `select()`, `selectDistinct()` - SELECT statements
- `insert()` - INSERT statements
- `update()` - UPDATE statements
- `delete()` - DELETE statements
- `dropTable()`, `dropTableIfExists()` - DROP TABLE statements

### Clauses

- `from()` - FROM clause
- `where()` - WHERE clause (chainable, conditions AND together)
- `join()`, `leftJoin()`, `rightJoin()`, `innerJoin()`, `fullOuterJoin()` - JOIN clauses
- `crossJoin()`, `naturalJoin()` - Specialized joins
- `joinLateral()`, `leftJoinLateral()`, etc. - LATERAL joins
- `on()` - JOIN conditions
- `using()` - USING clause for joins
- `group()`, `groupBy()`, `groupByAll()`, `groupByDistinct()` - GROUP BY
- `having()` - HAVING clause
- `order()`, `orderBy()` - ORDER BY clause
- `limit()`, `offset()` - LIMIT/OFFSET clauses
- `with()`, `withRecursive()` - CTE (Common Table Expressions)

### Set Operations

- `union()`, `unionAll()` - UNION operations
- `intersect()`, `intersectAll()` - INTERSECT operations
- `except()`, `exceptAll()` - EXCEPT operations

### INSERT/UPDATE Specific

- `into()` - INTO clause (for INSERT)
- `values()` - VALUES clause (accepts associative arrays)
- `set()` - SET clause (for UPDATE)
- `onConflict()` - ON CONFLICT clause
- `doNothing()` - DO NOTHING action
- `doUpdateSet()` - DO UPDATE SET action
- `returning()` - RETURNING clause

### Raw SQL

- `raw()` - Insert raw SQL fragments (use sparingly)

## Common Patterns

### Simple SELECT

```php
DB::select(['id', 'name', 'email'])
    ->from('users')
    ->where(['active' => true])
    ->orderBy('name')
    ->limit(10)
    ->asTable();
```

### JOIN with Conditions

```php
DB::select(['u.name', 'o.total'])
    ->from('users u')
    ->leftJoin('orders o')
    ->on(['"u"."id" = "o"."user_id"'])
    ->where(['u.active' => true])
    ->asTable();
```

### INSERT with Conflict Handling

```php

use F4\DB\Fragment;

DB::insert()
    ->into('users')
    ->values([
        'email' => 'user@example.com',
        'name' => 'John Doe',
        'created_at' => new Fragment('NOW()') // Fragment wrapper must be used to add SQL expression without converting it to a bound parameter
    ])
    ->onConflict('email')
    ->doUpdateSet(['name' => 'John Doe', '"updated_at" = NOW()'])
    ->returning('id')
    ->asValue();
```

### UPDATE Statement

```php
DB::update('users')
    ->set(['active' => false, '"deactivated_at" = NOW()'])
    ->where(['id' => 123])
    ->commit();
```

### Common Table Expressions (CTE)

```php
DB::with([
    'active_users' => DB::select()->from('users')->where(['active' => true])
])
    ->select()
    ->from('active_users')
    ->where(['"created_at" > {#}' => '2024-01-01'])
    ->asTable();
```

### Complex Subqueries

```php
DB::select([
    'u.*',
    'order_count' => DB::select('COUNT(*)')
        ->from('orders o')
        ->where(['"o"."user_id" = "u"."id"'])
])
    ->from('users u')
    ->where([
        'active' => true,
        any::of([
            'role' => 'admin',
            'order_count' => DB::select('COUNT(*)')
                ->from('orders')
                ->where(['"user_id" = "u"."id"'])
        ])
    ])
    ->asTable();
```

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/phpunit tests/F4/Tests/DBTest.php
```

### Test Structure

- Unit tests: [tests/F4/Tests/](tests/F4/Tests/)
- Test files primarily verify SQL generation via `getPreparedStatement()->query`
- MockAdapter available at [tests/F4/Tests/DB/MockAdapter.php](tests/F4/Tests/DB/MockAdapter.php)

## Configuration

DB requires configuration constants (typically in `F4\Config` class):

```php
namespace F4;

class Config {
    public const string DB_HOST = 'localhost';
    public const string DB_CHARSET = 'UTF8';
    public const string DB_PORT = '5432';
    public const string DB_NAME = 'database_name';
    public const string DB_USERNAME = 'user';
    public const string DB_PASSWORD = 'password';
    public const string DB_SCHEMA = 'public';
    public const ?string DB_APP_NAME = null;
    public const string DB_ADAPTER_CLASS = \F4\DB\Adapter\PostgresqlAdapter::class;
    public const bool DB_PERSIST = true;
}
```

Select the adapter via `DB_ADAPTER_CLASS` (or at runtime via `useAdapter()`). For SQLite, set `DB_ADAPTER_CLASS = \F4\DB\Adapter\SqliteAdapter::class` and use `DB_NAME` as the database filename (`":memory:"` for in-memory). Tests run against `MockAdapter` regardless of this setting.

## Type Casting

Type conversion is adapter-specific.

The **PostgreSQL adapter** automatically casts database types to PHP types:

- `smallint`, `integer`, `bigint`, `serial` → `int`
- `real`, `double precision`, `float4`, `float8` → `float`
- `json`, `jsonb` → `array` (via `json_decode`)
- `boolean` → `bool`
- `numeric` → `string` (no automatic casting)

The **SQLite adapter** returns SQLite storage-class values as-is by default. To convert, pass a result-converter callback `(mixed $value, string $columnName, int $columnIndex, int $sqliteType): mixed` to the `SqliteAdapter` constructor, or subclass and override `convertResultValue()`. Because SQLite exposes storage classes rather than declared types, mapping by output alias is recommended.

## Important Implementation Details

### Fragment Collections

Different collection types handle different SQL clause structures:

- `ConditionCollection` - AND-joined conditions (WHERE, HAVING, ON)
- `AnyConditionCollection` - OR-joined conditions
- `NoneConditionCollection` - NOT (OR-joined) conditions
- `SelectExpressionCollection` - SELECT clause expressions
- `TableReferenceCollection` - Table references with optional aliases
- `SimpleColumnReferenceCollection` - Column references
- `AssignmentCollection` - SET/UPDATE assignments
- `OrderCollection` - ORDER BY expressions
- `ValueExpressionCollection` - VALUES clause
- `WithTableReferenceCollection` - CTE definitions

### Method Categories

**Static-callable methods** (can use `DB::methodName()`):
- `select()`, `selectDistinct()`
- `insert()`, `update()`, `delete()`
- `dropTable()`, `dropTableIfExists()`, `dropTableWithCascade()`, `dropTableIfExistsWithCascade()`
- `with()`, `withRecursive()`
- `raw()`

**Instance-only methods** (require chaining):
- All JOIN methods
- `from()`, `where()`, `into()`, `values()`, `set()`
- `group()`, `having()`, `order()`, `limit()`, `offset()`
- Result methods: `asTable()`, `asRow()`, `asValue()`, `asSQL()`

### Identifier Quoting

DB automatically quotes simple identifiers via the **active adapter at render time** (not at construction). PostgreSQL and SQLite use double quotes; MySQL uses backticks:

- `users` → `"users"` (PostgreSQL/SQLite) or `` `users` `` (MySQL)
- `users u` → `"users" AS "u"` (PostgreSQL/SQLite)
- `users.id` in a column-reference context → `"users"."id"` (PostgreSQL/SQLite)

Mechanism: reference strings are parsed into `DelimitedIdentifier`s (see [src/F4/DB/Reference/](src/F4/DB/Reference/)); a collection embeds a recognized reference via the `{#::#}` seam, which calls `getQuery($adapter)` at render time so the query's *current* adapter (honoring `useAdapter()` and transactions) performs the quoting. `AdapterInterface::getEscapedIdentifier()` is connectionless — building/rendering a query never opens a connection. `DB::escapeIdentifier()` returns a `DelimitedIdentifier` (string coercion is deprecated; use `getQuery()`).

Most SQL-structure methods deliberately have two parsing modes. A reference
string matching the method's strict identifier grammar is automatically quoted.
Every other string is treated as developer-authored SQL and rendered as-is; a
numeric array key or a key/expression containing a custom placeholder commonly
selects this mode. Internally, an unrecognized reference has
`getDelimited() === null`, and the consuming collection supplies its literal
fragment. Never pass untrusted input through this SQL-string mode. Only values
bound through placeholders are protected; identifiers, operators, directions,
and all other SQL structure must be selected from trusted application code or an
allowlist.

## Anti-Patterns to Avoid

1. **Don't mix placeholder types incorrectly**
   ```php
   // WRONG: Using scalar where array expected
   where(['"status" IN {#}' => ['a', 'b']])  // Error!

   // RIGHT:
   where(['"status" IN ({#,...#})' => ['a', 'b']])
   ```

2. **Don't forget to call execution methods**
   ```php
   // WRONG: No execution
   $query = DB::select()->from('users');  // Just builds, doesn't execute

   // RIGHT:
   $users = DB::select()->from('users')->asTable();
   ```

3. **Don't reuse builder instances for multiple queries**
   ```php
   // WRONG: Mutations accumulate
   $base = DB::select()->from('users');
   $admin = $base->where(['role' => 'admin'])->asTable();  // Mutates $base!
   $user = $base->where(['role' => 'user'])->asTable();     // Has BOTH conditions!

   // Right: Cloning the base
   $base = DB::select()->from('users');
   $admin = (clone $base)->where(['role' => 'admin'])->asTable();
   $user = (clone $base)->where(['role' => 'user'])->asTable();

   // RIGHT: Create fresh instances
   $admin = DB::select()->from('users')->where(['role' => 'admin'])->asTable();
   $user = DB::select()->from('users')->where(['role' => 'user'])->asTable();
   ```

## Code Navigation

- Entry point: [src/F4/DB.php](src/F4/DB.php)
- Query builder: [src/F4/DB/QueryBuilder.php](src/F4/DB/QueryBuilder.php)
- Fragment system: [src/F4/DB/Fragment.php](src/F4/DB/Fragment.php)
- Delimited identifier: [src/F4/DB/DelimitedIdentifier.php](src/F4/DB/DelimitedIdentifier.php)
- Collections: [src/F4/DB/](src/F4/DB/) (*Collection.php files)
- Reference types: [src/F4/DB/Reference/](src/F4/DB/Reference/)
- Adapter interface: [src/F4/DB/Adapter/AdapterInterface.php](src/F4/DB/Adapter/AdapterInterface.php)
- PostgreSQL adapter: [src/F4/DB/Adapter/PostgresqlAdapter.php](src/F4/DB/Adapter/PostgresqlAdapter.php)
- SQLite adapter: [src/F4/DB/Adapter/SqliteAdapter.php](src/F4/DB/Adapter/SqliteAdapter.php)
- Tests: [tests/F4/Tests/DBTest.php](tests/F4/Tests/DBTest.php)

## Not Yet Implemented

The following methods exist but throw `BadMethodCallException`:

- DDL operations: `createTable()`, `alterTable()`, `addColumn()`, `dropColumn()`
- Index management: `createIndex()`, `createIndexIfNotExists()`
- View operations: `createView()`, `createOrReplaceView()`, `createMaterializedView()`

Check method implementation before using - if it throws `BadMethodCallException`, construct the query using `raw()` or wait for implementation.
