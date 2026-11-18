# ToolkitSQLite3
## by Markus Jäger
### Version 1.6.0

---

ToolkitSQLite3 is a lightweight PHP helper class and abstraction layer over SQLite3.
It provides a clean, predictable, and safe API for common database operations, including schema management and data manipulation.

## Features
- Tables: existence checks, creation, and deletion.
- Columns: existence checks, creation, and deletion.
- Rows: existence checks, insert or update (UPSERT), and deletion.
- Strict validation of table and column names.
- Uses prepared statements and strict input validation.
- No fixed data type definitions per column; instead, values use SQLite3-style dynamic typing.
- Focus on a simple API, high performance, and protection against SQL injection.
- SELECT queries with common predicates.

## Installation
Include the class file:
```php
require 'ToolkitSQLite3.php';
```

## Basic Usage
Table names must start with a lowercase letter and may only contain lowercase letters, numbers, or underscores. Additionally, they may be a maximum of 512 characters long (protected constant `MAX_LENGTH`, can be changed via class extension, late static binding).

The constructor opens the database file (it is created if it does not exist). The table is stored with the prefix of the protected constant `TABLE_PREFIX` (default `'toolkitsqlite3_'`), e.g. `toolkitsqlite3_example_table`.

```php
$db = new ToolkitSQLite3( 'database.sqlite3', 'example_table' );
```

## Error Handling
All errors throw a `RuntimeException`: invalid names or values, and every SQLite3 error (e.g. a database that cannot be opened, a missing table or a locked database). Methods that add, update or delete therefore return `true` or throw, they never return `false` on an error.

```php
try {
	$db->table_add_ignore();
	$db->columns_add_ignore( 'title', 'views' );
}
catch( RuntimeException $e ) {
	echo 'Error: ' . $e->getMessage(); // The original SQLite3Exception is available via $e->getPrevious().
}
```

Names and row slugs can be checked in advance without a database connection. Both methods throw a `RuntimeException` for an invalid value:

```php
ToolkitSQLite3::validate_sqlite_name_guard_clause( 'title' );
ToolkitSQLite3::validate_row_slug_guard_clause( 'first-entry' );
```

## Table Handling
### Check if a table exists
```php
if( $db->table_exists() ) {
	echo 'Table exists';
}
else {
	echo 'Table does not exist';
}
```

### Create a table (ignored if it already exists)
```php
if( $db->table_add_ignore() ) {
	echo 'Query was executed successfully';
}
```

### Delete a table (ignored if it does not exist)
```php
if( $db->table_delete_ignore() ) {
	echo 'Query was executed successfully';
}
```

## Column Handling
Column names must start with a lowercase letter, may only contain lowercase letters, numbers or underscores and may be at most 512 characters long (`MAX_LENGTH`, see above). Unlike in traditional database systems, a column has no fixed data type: the type is stored per value (SQLite3 dynamic typing).

### Check if a column exists
```php
if( $db->column_exists( 'title' ) ) {
	echo 'Column exists';
}
else {
	echo 'Column does not exist';
}
```

### Add columns (ignored if they already exist)
```php
if( $db->columns_add_ignore( 'title', 'views', 'status', 'payload', 'float' ) ) {
	echo 'Query was executed successfully';
}
```

### Delete columns (ignored if they do not exist)
```php
if( $db->columns_delete_ignore( 'title', 'views', 'status', 'payload', 'float' ) ) {
	echo 'Query was executed successfully';
}
```

## Row Handling
Each row is uniquely identified by its `_slug`, which is managed internally by the API. The `_slug` must not be empty, otherwise it is not restricted in characters or length (the same applies to the stored values). All inputs are bound with prepared statements.

### Check if a row exists
```php
if( $db->row_isset( 'post_1' ) ) {
	echo 'Row exists';
}
else {
	echo 'Row does not exist';
}
```

### Insert or update a row (UPSERT)
Behavior
- Inserts a new row if _slug does not exist in the database.
- Updates the row if _slug already exists in the database.
- _created_at is set only on initial insert.
- _updated_at is set on both insert and update operations.
- Both store the Unix timestamp in seconds (UTC) and are returned as `int`, e.g. readable with `date( 'Y-m-d H:i:s', $row['_created_at'] )`. In WHERE, compare them only with `int` values (e.g. `time() - 86400`). Other types, such as a `float` or a numeric string (`'1700000000'`), throw a `RuntimeException`.

The provided column-value pairs determine which columns are written. Column names must be strings and must already exist in the database schema (add them with `columns_add_ignore()`). A failing query throws a `RuntimeException`, so `row_upsert()` returns `true` or throws.
The following PHP data types are supported and internally mapped to SQLite’s dynamic typing system:
- `null` → `SQLITE3_NULL`
- `string` → `SQLITE3_BLOB` (text or binary data, stored 1:1)
- `int` → `SQLITE3_INTEGER`
- `float` → `SQLITE3_FLOAT`
- `array` → `SQLITE3_TEXT` (encoded as JSON, invalid UTF-8 in a string is replaced by U+FFFD)

Other types (e.g. `bool`, objects or stream resources) throw a `RuntimeException` ("Unsupported data type"). An array that cannot be encoded as JSON (e.g. with `INF` or `NAN`, or more than 512 levels) throws a `RuntimeException` ("(JSON error)").

Strings and arrays use different SQLite storage classes (BLOB and TEXT), so `select()` returns every value with its PHP type.
```php
$row = ['title' => 'Hello World 1', 'views' => 42, 'status' => 'active'];

if( $db->row_upsert( 'post_1', $row ) ) {
	echo 'Query was executed successfully';
}
```

### UPSERT using loops
```php
$rows = [
	'post_1' => ['title' => 'Hello World 1', 'views' => 42, 'status' => 'active'],
	'post_2' => ['title' => 'Hello World 2', 'views' => 7, 'status' => 'pending'],
	'post_3' => ['title' => 'Hello World 3', 'views' => 150, 'status' => 'closed'],
	'post_4' => ['title' => 'Hello World 1', 'views' => 120, 'status' => 'active'],
];

foreach( $rows as $slug => $row ) {

	if( $db->row_upsert( $slug, $row ) ) {
		echo 'Query was executed successfully';
	}
}
```

### Remove a row
```php
if( $db->row_remove( 'post_1' ) ) {
	echo 'Row was removed';
}
else {
	echo 'No matching row';
}
```

### Text and binary data (BLOB)
A PHP `string` may contain text as well as binary data (e.g. an image, encrypted data or NUL bytes). Every string is stored 1:1 as BLOB and comes back byte for byte unchanged as `string`, no conversion is needed.

```php
$db->row_upsert( 'image_1', ['payload' => file_get_contents( 'image.avif' )] );

$rows = $db->select( [
	'columns' => ['payload'],
	'where'   => ['column' => '_slug', 'op' => '=', 'value' => 'image_1']
] );

$data = $rows[0]['payload']; // The binary data, byte for byte unchanged.
```

## SELECT Queries
The select() method provides a structured and safe abstraction layer for building SQLite3 SELECT queries.

Instead of writing raw SQL manually, queries are defined using associative arrays and logical expression trees. All values are internally bound using prepared statements, while table and column identifiers are validated before execution.

The system supports:
- column selection
- DISTINCT queries
- WHERE conditions
- nested logical expressions (AND, OR, NOT)
- sorting
- LIMIT / OFFSET

The comments below the following examples show the `_slug` of the found rows, based on the rows of the example "UPSERT using loops".

### Basic Usage
If no arguments are provided:
- all columns are selected
- all rows are returned
- no filtering is applied
```php
$result = $db->select( [] );
// post_1, post_2, post_3, post_4 (all columns)
```

`select()` returns an array of rows (an empty array if nothing matches). Each row is an associative array of column name => value. A value has the PHP type of its SQLite3 storage class: `null`, `int`, `float`, `string` (BLOB and the TEXT of `_slug`) or `array` (JSON TEXT of a user column).

### Selecting Specific Columns
Use the columns argument to limit the selected columns.
```php
$result = $db->select( [
	'columns' => ['title', 'views']
] );
// 4 rows with the columns title and views
```

### DISTINCT
Removes duplicate rows from the result set.
```php
$result = $db->select( [
	'distinct' => true,
	'columns'  => ['title']
] );
// 3 rows: Hello World 1, Hello World 2, Hello World 3
```

### ORDER BY
Defines the result ordering based on one or more columns with ASC or DESC direction.
```php
$result = $db->select( [
	'orderby' => [
		'views' => 'DESC',
		'title' => 'ASC'
	]
] );
// post_3, post_4, post_1, post_2
```

### LIMIT
Restricts the maximum number of rows returned by the query. The value must be an `int` (like `offset`), other values are ignored.
```php
$result = $db->select( [
	'limit' => 2
] );
// post_1, post_2
```

### OFFSET
Defines the number of rows to skip before returning results.
```php
$result = $db->select( [
	'offset' => 2
] );
// post_3, post_4
```

### WHERE
The where argument accepts a structured logical expression tree. A leaf that is no valid predicate throws a `RuntimeException`: an unknown operator, a binary predicate without `value` or with `'value' => null` (use `IS NULL`), an `IN` with an empty `values` list, or a `BETWEEN` whose `min` or `max` is missing or not scalar.

Values compared with a reserved column (`value`, every entry of `values`, `min` and `max`) must have the type of the column: a `string` for `_slug` (bound as TEXT), an `int` for `_id`, `_created_at` and `_updated_at` (bound as INTEGER). Any other type (e.g. `_slug = 10`, `_id = '1'`, a `float` or `null` in an `IN` list) throws a `RuntimeException` ("Unsupported data type").
#### Binary predicate (=, !=, <, <=, >, >=, LIKE, GLOB)
```php
$result = $db->select( [
	'where' => [
		'column' => 'views',
		'op'     => '>=',
		'value'  => 100
	]
] );
// post_3, post_4
```

#### Membership predicate (IN)
```php
$result = $db->select( [
	'where' => [
		'column' => 'status',
		'op'     => 'IN',
		'values' => ['open', 'closed']
	]
] );
// post_3
```

#### Range predicate (BETWEEN)
```php
$result = $db->select( [
	'where' => [
		'column' => 'views',
		'op'     => 'BETWEEN',
		'min'    => 10,
		'max'    => 100
	]
] );
// post_1
```

#### Null predicate (IS NULL)
```php
$result = $db->select( [
	'where' => [
		'column' => 'payload',
		'op'     => 'IS NULL'
	]
] );
// post_1, post_2, post_3, post_4
```

#### AND conditions
```php
$result = $db->select( [
	'where' => [
		'AND' => [
			[
				'column' => 'views',
				'op'     => '>=',
				'value'  => 10
			],
			[
				'column' => 'status',
				'op'     => '=',
				'value'  => 'active'
			]
		]
	]
] );
// post_1, post_4
```

#### OR conditions
```php
$result = $db->select( [
	'where' => [
		'OR' => [
			[
				'column' => 'status',
				'op'     => '=',
				'value'  => 'active'
			],
			[
				'column' => 'status',
				'op'     => '=',
				'value'  => 'pending'
			]
		]
	]
] );
// post_1, post_2, post_4
```

#### NOT conditions
```php
$result = $db->select( [
	'where' => [
		'NOT' => [
			'column' => 'status',
			'op'     => '=',
			'value'  => 'deleted'
		]
	]
] );
// post_1, post_2, post_3, post_4
```

#### Nested Logical Trees
Complex logical structures can be nested arbitrarily.
```php
$result = $db->select( [
	'where' => [
		'AND' => [
			[
				'column' => 'views',
				'op'     => '>=',
				'value'  => 100
			],
			[
				'OR' => [
					[
						'column' => 'status',
						'op'     => '=',
						'value'  => 'active'
					],
					[
						'column' => 'status',
						'op'     => '=',
						'value'  => 'pending'
					]
				]
			]
		]
	]
] );
// post_4
```

## Combining Multiple Query Components
```php
$result = $db->select( [
	'distinct' => true,
	'columns' => [
		'title',
		'views'
	],
	'where' => [
		'AND' => [
			[
				'column' => 'views',
				'op'     => '>=',
				'value'  => 10
			],
			[
				'column' => 'status',
				'op'     => '=',
				'value'  => 'active'
			]
		]
	],
	'orderby' => [
		'views' => 'DESC'
	],
	'limit'  => 25,
	'offset' => 0
] );
// 2 rows: Hello World 1 with 120 views, Hello World 1 with 42 views
```

## Concurrent Access
Several processes (e.g. parallel requests) can use the same database file. Two protected constants control the behavior and can be changed via class extension (late static binding):
- `BUSY_TIMEOUT` (default `10000`): milliseconds to wait for the lock of another process before an operation fails with "database is locked".
- `JOURNAL_MODE` (default `'WAL'`): with Write-Ahead Logging, readers do not block writers and vice versa. SQLite3 creates the files `*-wal` and `*-shm` next to the database file, so the folder must be writable. WAL does not work on network file systems, use e.g. `'DELETE'` there.

The journal mode is stored permanently in the database file, so the constructor writes it only if it differs from the current mode (without the busy timeout). It can only be changed while no other connection has the file open: change `JOURNAL_MODE` for a new database, otherwise the constructor throws "database is locked".

```php
class ToolkitSQLite3New extends ToolkitSQLite3 {

	protected const BUSY_TIMEOUT = 20000;
	protected const JOURNAL_MODE = 'DELETE';
}

$db = new ToolkitSQLite3New( 'database.sqlite3', 'example_table' );
```

## Reserved Columns
The following columns are internally reserved and automatically allowed without validation. In WHERE they are compared only with a value of the named type:
- _id (`int`, unique row number)
- _slug (`string`, unique identifier of the row)
- _created_at (`int`, Unix timestamp of the insert)
- _updated_at (`int`, Unix timestamp of the last insert or update)

`select()` reads the SQLite3 storage class of every column under an alias that starts with the protected constant `TYPE_ALIAS` (default `'_typeof_'`). A column name always starts with a lowercase letter, so it never starts with the default. A class extension must keep a value that no valid column name can start with (e.g. one that starts with `_`), otherwise `select()` mixes up values and types.

## Schema & SQL Safety
This toolkit enforces strict validation and protection mechanisms to ensure safe database operations.

It validates:
- table names
- column names
- row identifiers

If invalid parameters are detected, execution stops immediately with a clear error message, preventing silent data loss or inconsistent schema states.

### SQL injection
SQL injection through the API is not possible: all values from user input (stored values, row slugs and WHERE values) are bound with prepared statements and therefore never become part of the SQL statement.

Table and column names are validated and quoted, so every SQLite keyword (e.g. `order`, `select`, `where` or `null`) can be used as a name. The only exceptions are the column names `rowid` and `oid`: as long as no such column exists, SQLite reads them as the internal row number (`_id`) instead of throwing an error.

## Notes
- All errors throw a `RuntimeException`, also SQLite3 errors (e.g. a database that cannot be opened, a locked database or an invalid query). The original `SQLite3Exception` (or `Exception` if the database cannot be opened) is available via `getPrevious()`.
- `columns_add_ignore()` and `columns_delete_ignore()` are safe for concurrent processes: a column that another process added or removed at the same time counts as success.
- Empty WHERE trees throw an exception.
- Unsupported operators are rejected automatically.
- SQLite runtime types are determined using `typeof()` for every selected column.
- Nested logical expressions are fully recursive.
- LIMIT and OFFSET values are clamped to a minimum of `0`.

## Performance Notes
- `PRAGMA table_info` is used to determine the columns (`select()` without `columns`, `column_exists()`).
- SQLite3 internally caches schema information.
- For typical workloads, this is not a performance bottleneck.
- No additional caching layer is introduced.

## Design Philosophy
- No ORM
- No hidden state
- No auto-migrations
- Explicit is better than implicit

This toolkit is designed to be predictable, readable, and boring — in the best possible way.

---

## Requirements
- **PHP:** 8.3 or higher
- **PHP extensions:** `sqlite3` with SQLite 3.38 or higher

---

## Attribution / License Notice
This repository is licensed under the MIT License.

Any use, copy, modification, or redistribution of this repository
or any substantial portion of it must retain attribution to the
original author and the original GitHub repository.

Copyright (c) 2026 Markus Jäger
https://github.com/m-O-rpheus/ToolkitSQLite3

---

### Low-level framework without dependencies:
```
ToolkitSQLite3-main
```

---

#### Version 1.6.0 Changelog:
- README: the section "Reserved Columns" describes the protected constant `TYPE_ALIAS` and which values a class extension may use. No functional changes.

#### Version 1.5.9 Changelog:
- Breaking change: a `string` is stored as BLOB (before: TEXT) and comes back unchanged, also with NUL bytes (before: cut at the first NUL byte). An `array` is stored as JSON TEXT without a marker (before: BLOB with the prefix `JSON_ALIAS`). `select()` returns a BLOB as `string` and the TEXT of a user column as `array`, the reserved columns are unchanged (`_slug` stays TEXT).
- Breaking change: stream resources are no longer supported. `string_to_resource()`, `resource_to_string()` and the constant `JSON_ALIAS` were removed, binary data is stored as `string`.
- Breaking change: create the tables of an older version again. A string that an older version stored as TEXT is read as JSON and throws a `RuntimeException` ("(JSON error)"), an array of an older version (BLOB with marker) comes back as `string`. BLOB values of stream resources stay readable and come back as `string`.
- The message of SQLite3 errors is "ToolkitSQLite3: (SQLite3 error) The SQLite3 operation failed: …" (before: "ToolkitSQLite3: (SQLite3 error) …"), followed by the original message.
- New error "ToolkitSQLite3: (JSON error) …": every error of the JSON conversion of an array (e.g. `INF` or `NAN`, too deep nesting, or a TEXT that is no JSON) throws a `RuntimeException` with this message, the `JsonException` is available via `getPrevious()`. Invalid UTF-8 in a string of an array is replaced by U+FFFD (before: "Unsupported data type").
- Fixed: a stored stream resource whose content started with the JSON marker came back as `array`, a string with NUL bytes was cut.
- README: the sections "Insert or update a row (UPSERT)", "Text and binary data (BLOB)" and "Basic Usage" of "SELECT Queries" describe the storage of strings (BLOB) and arrays (JSON TEXT).

#### Version 1.5.8 Changelog:
- README: the new subsection "SQL injection" of the section "Schema & SQL Safety" describes that SQL injection through the API is not possible and that every SQLite keyword can be used as a table or column name (except the column names `rowid` and `oid`).
- The comment of the reserved columns in `select()` is on its own line. The behavior is unchanged.

#### Version 1.5.7 Changelog:
- Breaking change: `select()` compares the reserved columns only with values of their type (`value`, every entry of `values`, `min` and `max`): `_slug` with a `string`, `_id`, `_created_at` and `_updated_at` with an `int` (before: both types for all four columns). E.g. `_slug = 10` or `_created_at < '1700000000'` now throw a `RuntimeException` ("Unsupported data type").
- Internal placeholder names, `select()` defines the prefix of every reserved column together with its name:
  - `:table_name` and `:column_name` (before: `:reserved_table` and `:reserved_column`)
  - `:row_string` for the row slug of `row_isset()`, `row_upsert()` and `row_remove()` (before: `:reserved_slug`), `:row_string1, …` for WHERE values of `_slug`
  - `:row_int1, …` for WHERE values of `_id`, `_created_at` and `_updated_at` (before, like `_slug`: `:reserved_bind1, …`)
  - `:row_any1, …` for the values of the user columns (before: `:any_bind1, …`)
- The message of the error "Unsupported data type" names the allowed type of the reserved columns (`_slug`: `string`, `_id`, `_created_at` and `_updated_at`: `int`).
- README: the sections "WHERE", "Reserved Columns" and "Insert or update a row (UPSERT)" describe the type of the reserved columns in WHERE.

#### Version 1.5.6 Changelog:
- Breaking change: `select()` accepts only `string` and `int` as WHERE values for the reserved columns `_id`, `_slug`, `_created_at` and `_updated_at` (`value`, every entry of `values`, `min` and `max`), any other type throws a `RuntimeException` ("Unsupported data type"). Before, e.g. a `float` (`_created_at > 1.5`), `null` in an `IN` list or an `array` was accepted.
- Table names, column names and values of the reserved columns skip the type mapping of the PHP data type: a `string` is bound as TEXT, an `int` as INTEGER. Internal placeholders: `:reserved_table`, `:reserved_column`, `:reserved_slug` and `:reserved_bind1, …` (WHERE on a reserved column), `:any_bind1, …` for the user columns (type of the PHP value). Otherwise the behavior is unchanged.
- The message of the error "Unsupported data type" names the allowed types of the reserved columns (`string`, `int`).
- README: the sections "WHERE", "Reserved Columns" and "Insert or update a row (UPSERT)" describe that the reserved columns are compared only with `string` or `int` values.

#### Version 1.5.5 Changelog:
- Breaking change: the minimum requirement is SQLite 3.38 (before: 3.37), because of `unixepoch()`.
- Breaking change: `_created_at` and `_updated_at` are INTEGER columns with the Unix timestamp in seconds (UTC) instead of TEXT (`YYYY-MM-DD HH:MM:SS`), created with `unixepoch()` (default of both columns, `_updated_at` also in `row_upsert()`). `select()` returns them as `int`. Compare them with `int` values, a text such as `'2026-11-17'` is greater than every number. An existing table keeps its TEXT columns (`CREATE TABLE IF NOT EXISTS`): create it again, otherwise text and Unix timestamps are mixed.
- README: the section "Insert or update a row (UPSERT)" describes the Unix timestamps, the section "Reserved Columns" names the type of every built-in column, the section "Requirements" names SQLite 3.38.

#### Version 1.5.4 Changelog:
- `column_exists()` binds the table name as a value (`pragma_table_info(:table)`) instead of writing it in double quotes, like `table_exists()`. Before, it relied on SQLite3 reading a double-quoted name as text: it failed without double-quoted string literals (`SQLITE_DQS=0`) and returned `false` for every column of a table named like a column of `pragma_table_info` (e.g. `name`, only possible with a changed `TABLE_PREFIX`). Otherwise the behavior is unchanged.

#### Version 1.5.3 Changelog:
- `select()`: the column list uses fully qualified column names with an alias (`"table"."column" AS "column"`, also for `typeof()`), all column names of `select()` are now quoted. A column named like an SQLite keyword (e.g. `order`) can be selected, also with `select( [] )`, and a missing column always throws ("no such column: table.column"). Otherwise the behavior is unchanged (same result columns, values and types).

#### Version 1.5.2 Changelog:
- `select()`: ORDER BY uses fully qualified column names (`"table"."column"`). A column with the name of an SQLite keyword (e.g. `order`) can be used in ORDER BY, a missing column always throws ("no such column: table.column"). Otherwise the behavior is unchanged. The column list of `select()` does not quote the names yet.

#### Version 1.5.1 Changelog:
- `select()` (the four WHERE predicates), `row_isset()` and `row_remove()` use fully qualified column names (`"table"."column"`) in WHERE. A column with the name of an SQLite keyword (e.g. `order`) can be used in WHERE, a missing column always throws ("no such column: table.column"). Otherwise the behavior is unchanged. The column list and ORDER BY of `select()` do not quote the names yet.

#### Version 1.5.0 Changelog:
- `row_upsert()`: the column names in the column list of `INSERT` and in `SET` are quoted, so a column with the name of an SQLite keyword (e.g. `order`) can be written. A missing column still throws. `select()` (column list, ORDER BY, WHERE) does not quote the names yet.

#### Version 1.4.9 Changelog:
- `validate_sqlite_name_guard_clause()`: the regular expression ends with `\z` instead of `$`, so a table or column name with a line break at the end is rejected (before, `$` also matched before a final line break).

#### Version 1.4.8 Changelog:
- The `final` methods `validate_sqlite_name_guard_clause()` and `validate_row_slug_guard_clause()` are called with `self::` instead of `static::` (11 calls), as all members that cannot be overridden. No functional changes.

#### Version 1.4.7 Changelog:
- The built-in columns `_id`, `_slug`, `_created_at` and `_updated_at` are quoted in the SQL statements of the table and row methods. The behavior is unchanged.

#### Version 1.4.6 Changelog:
- `validate_sqlite_name_guard_clause()` and `validate_row_slug_guard_clause()` are public, so that names and row slugs can be checked in advance. Both methods are final, so that the protection against SQL injection cannot be weakened via class extension.

#### Version 1.4.5 Changelog:
- README: the SELECT examples show the expected result in a comment. The example "UPSERT using loops" has a fourth row with the same title as the first one, so that DISTINCT and the combined example return a visible result, and `limit` and `offset` fit the four rows (before: `10` and `20`, which returned all rows or no row at all). No functional changes.

#### Version 1.4.4 Changelog:
- README: the examples create and fill the column `status` that the WHERE examples use (before: "no such column: status"), the third row has 150 views, so that the examples with `>= 100` find a row. The section "Concurrent Access" notes that the journal mode can only be changed while no other connection has the database file open. No functional changes.

#### Version 1.4.3 Changelog:
- README: the class extension in the example is named `ToolkitSQLite3New` instead of `ToolkitSQLite3Slow`. No functional changes.

#### Version 1.4.2 Changelog:
- Breaking change: README: the minimum requirement is PHP 8.3 (before: 8.1). New section "Requirements" (PHP, PHP extensions, PHP settings, browser, repositories, other requirements), structured the same in all repositories.

#### Version 1.4.1 Changelog:
- Code style: the README examples are indented with tabs instead of four spaces. No functional changes.

#### Version 1.4.0 Changelog:
- Comments: the constructor and the destructor have a description, `resource_to_string()` and `string_to_resource()` describe their return value (`false` on failure), `consume_query()` and `select_buildWhere__renderLeafNode()` mention the `RuntimeException`.
- README: "Row Handling" no longer says that the `_slug` has no restrictions (an empty slug throws). New: the table prefix (`TABLE_PREFIX`), `MAX_LENGTH`, the `RuntimeException` for unsupported data types in `row_upsert()` and for invalid WHERE predicates, `limit` and `offset` as `int`. "UPSERT using loops" writes three different rows, the performance note describes the current use of `PRAGMA table_info`. No functional changes.

#### Version 1.3.9 Changelog:
- README: new section "Binary data (BLOB)" describes the conversion helpers `string_to_resource()` and `resource_to_string()` with an example and their return value (`false` on failure). No functional changes.

#### Version 1.3.8 Changelog:
- Breaking change: the connection uses `SQLite3::enableExceptions( true )`:
  - Every SQLite3 error throws a `RuntimeException` ("ToolkitSQLite3: (SQLite3 error) …", original exception via `getPrevious()`, new helper `throw_rethrow_sqlite_error()`) instead of a PHP warning and `false`, also a database that cannot be opened.
  - `table_add_ignore()`, `table_delete_ignore()`, `columns_add_ignore()` and `columns_delete_ignore()` return `true` or throw (callers that checked `false` now get the exception), the column methods throw "no such table" on a missing table.
- `try`/`catch( SQLite3Exception )` in the constructor (`catch( Exception )`), `consume_query()`, `table_add_ignore()`, `table_delete_ignore()`, `columns_add_ignore()`, `columns_delete_ignore()` and `select_buildList()`.
- Bugfix (race condition): `columns_add_ignore()` and `columns_delete_ignore()` run one `ALTER TABLE` per column without `column_exists()` before (the names are validated directly), "duplicate column name" and "no such column" count as success. Before, two processes that added or removed the same column at the same time failed (test with 8 processes and 40 columns: 53 and 102 of 320 operations failed, now none).
- README: new section "Error Handling", two notes, the examples of the four methods above no longer show an unreachable `else { echo 'Query error'; }`.

#### Version 1.3.7 Changelog:
- Code style: `consume_query()` sets `$err = false` after the callback instead of before it (the statement counts as successful after the callback has finished). No functional changes.

#### Version 1.3.6 Changelog:
- Comments and README, no functional changes:
  - `row_upsert()` and `row_remove()` never return `false` on a failing query, `consume_query()` throws a `RuntimeException` ("false on query error" and "or the deletion failed" were wrong). The comments of all methods that use `consume_query()` mention it.
  - `row_upsert()` does not create columns ("created or updated" was wrong), its examples no longer show an unreachable `else { echo 'Query error'; }`.
  - "Concurrent Access" explains that the journal mode is written only if it differs, "SELECT Queries" describes the return value of `select()` (array of rows, PHP types of the values).
  - The example of `row_remove()` shows the return value (`true` if a row was removed, `false` if no matching row existed), the comments "// Last check …" are removed from the examples.

#### Version 1.3.5 Changelog:
- Breaking change: table and column names must start with a lowercase letter and contain only lowercase letters, digits or underscores (before: also uppercase letters), otherwise a `RuntimeException` is thrown. SQLite3 compares names case-insensitively (`B` and `b` are the same column), but e.g. `column_exists( 'B' )` checked case-sensitively. Use lowercase names and create databases with uppercase table or column names again.

#### Version 1.3.4 Changelog:
- Code style: `select_buildList()` closes the result of `PRAGMA table_info` in a `finally` block, the same way as `consume_query()`. No functional changes.

#### Version 1.3.3 Changelog:
- Bugfix: the constructor writes the journal mode only if it differs from the current one (the mode, e.g. WAL, is stored permanently in the database file). Changing the journal mode does not use the busy timeout, in parallel operation it failed sporadically with "database is locked".
- Bugfix: `consume_query()` executes the statement only if the SQL contains no placeholder without a value (`count( $comparison ) === $stmt->paramCount()`). Before, only the passed values were checked, a placeholder without a value was used as `NULL` silently and the row was stored with an empty value.

#### Version 1.3.2 Changelog:
- Code style: the section of the exception helpers (trait `ToolkitSQLite3_methods_errors`) has the uniform header "Centralized exception handling helpers.". No functional changes.

#### Version 1.3.1 Changelog:
- Bugfix: `tagged_json_decode()` checks the decoded value with `is_array()` instead of `json_last_error()`. A JSON-tagged BLOB with valid JSON that is not an array (e.g. `"1"` or `null`) no longer causes a `TypeError` (return type `array|false`), it is treated like an invalid JSON payload.

#### Version 1.3.0 Changelog:
- Bugfix: `select()` with `offset` but without `limit` no longer fails. SQLite3 only accepts `OFFSET` after `LIMIT`, so `LIMIT -1` (no limit) is added.

#### Version 1.2.9 Changelog:
- Code style: `isset()` instead of `array_key_exists()` for the logical operators `AND`, `OR` and `NOT` of the WHERE tree (the value must be an array anyway). No functional changes.

#### Version 1.2.8 Changelog:
- Revised the complete changelog: checked against the commits, completed short entries, marked breaking changes. Commits without a version are listed under the following version. No functional changes.

#### Version 1.2.7 Changelog:
- Code style: `[]` instead of `array()` (also in the example comments). The `select()` examples in comments and README are written as `select( [ … ] )`. No functional changes.

#### Version 1.2.6 Changelog:
- Bugfix: Parallel requests no longer fail with "database is locked". The connection waits up to 10 seconds for a lock (`busyTimeout()`) and uses the journal mode WAL, so readers and writers do not block each other. Both values are protected constants (`BUSY_TIMEOUT`, `JOURNAL_MODE`) and can be changed via class extension.

#### Version 1.2.5 Changelog:
- Code style: removed trailing whitespace in the license header. No functional changes.
- README: added the separator `---` before the section "Attribution / License Notice".

#### Version 1.2.4 Changelog:
- Changed: The callback parameter of `consume_query()` is now typed as `Closure` instead of `callable`.
- Updated the callback docblock from `callable` to `Closure`.

#### Version 1.2.3 Changelog:
- Changed: Replaced `void` with `never` for methods that always throw an exception. The validation is done by the new guard methods `validate_sqlite_name_guard_clause()` and `validate_row_slug_guard_clause()`, the `throw_*()` methods only throw.

#### Version 1.2.2 Changelog:
- Renamed error handling methods for clearer naming (`error_if_*()` → `throw_*()`, e.g. `throw_invalid_sqlite_name()`, `throw_unsupported_data_type()`).
- Breaking change: All errors throw a `RuntimeException` instead of an `InvalidArgumentException`.

#### Version 1.2.1 Changelog:
- Added and improved code comments for better readability and maintainability.

#### Version 1.2 Changelog:
- Updated README.md.

#### Version 1.1 Changelog:
- Added support for storing PHP arrays via JSON encoding in BLOB columns.
- Introduced a type alias prefix for JSON payloads to distinguish them from raw resource BLOB data.
- Enhanced BLOB handling to support both binary streams (resource) and structured data (array) within the same storage class.
- Improved decode logic to automatically restore JSON-tagged arrays on retrieval.
- The configuration values are protected constants (`TABLE_PREFIX`, `TYPE_ALIAS`, `JSON_ALIAS`, `MAX_LENGTH`), which can be changed via class extension.
- Breaking change: `resource2string()` and `string2resource()` were renamed to `resource_to_string()` and `string_to_resource()`.

#### Version 1.0 Changelog:
- Code fully reviewed and extensively refactored.
- Naming conventions standardized and made consistent across the project.
- Comments cleaned up, reduced, and structurally unified.
- Documentation reviewed and aligned with the current implementation.
- Internal logic and workflows validated and stabilized.
- Error handling centralized and standardized: all error messages start with "ToolkitSQLite3:", new errors for unsupported data types and failed bindings of prepared statements.
- Prepared statement handling reviewed and made more robust.
- SQL generation and parameter binding checked for consistency and safety.

#### Version 0.99 Changelog:
- Code Quality: new public helpers `resource2string()` and `string2resource()` for BLOB values.
- Finalized return of the selected method: `select()` returns every value with its PHP type (`null`, `string`, `int`, `float`, BLOB as stream resource), determined with `typeof()`.

#### Version 0.98 Changelog:
- Complete codebase overhaul: the methods are split into traits (table, column, row, select, errors), all queries use prepared statements (`consume_query()`), errors throw exceptions instead of `trigger_error()`.
- Breaking change: columns no longer have a fixed type (SQLite3 dynamic typing). `column_add_ignore( $name, $type )` and `column_delete_ignore( $name )` were replaced by `columns_add_ignore( ...$names )` and `columns_delete_ignore( ...$names )`, `table_column_add_ignore()` and `table_info_columns()` were removed.
- Breaking change: `table_add_ignore()` and `table_delete_ignore()` return `bool` instead of `int`.
- The connection is closed in `__destruct()`.

#### Version 0.97 Changelog:
- Code Quality: new method `select()`, all queries run through the private helper `consume_query()`.

#### Version 0.96 Changelog:
- Code Quality: `_created_at` and `_updated_at` have the default value of the current time.

#### Version 0.95 Changelog:
- Updated README.md and text indentation in ToolkitSQLite3.php.

#### Version 0.94 Changelog:
- Added LICENSE file and license info in source code.

#### Version 0.93 Changelog:
- Update: README.md extended with examples for all methods.

#### Version 0.92 Changelog:
- Update: new error for columns that do not exist in the table.

#### Version 0.91 Changelog:
- Update: the error methods were renamed (`error_if_*()`), new errors for an empty row slug and an invalid column type.
- Breaking change: removed the public methods `sanitize_sqlite_name()` and `sanitize_sqlite_type()`, invalid names and types trigger an error instead of being cleaned.

#### Version 0.9 Changelog:
- Init.
