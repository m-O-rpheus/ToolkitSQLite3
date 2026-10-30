# ToolkitSQLite3
## by Markus Jäger
### Version 1.4.3

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
Column names must start with a lowercase letter and may only contain lowercase letters, numbers, or underscores. Additionally, they may be a maximum of 512 characters long (`MAX_LENGTH`, see above). In contrast to traditional database systems, no fixed data types are defined per column. Instead, typing is handled dynamically at the value level, following the SQLite3-style approach.

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
if( $db->columns_add_ignore( 'title', 'views', 'payload', 'float' ) ) {
	echo 'Query was executed successfully';
}
```

### Delete columns (ignored if they do not exist)
```php
if( $db->columns_delete_ignore( 'title', 'views', 'payload', 'float' ) ) {
	echo 'Query was executed successfully';
}
```

## Row Handling
Each row is uniquely identified by its _slug value. This field is internally managed by the API and serves as a unique identifier for individual records. The _slug must not be empty, otherwise it is not restricted regarding characters or length (the same applies to the stored values). All inputs are internally handled using prepared statements to ensure proper and secure parameter binding.

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

The provided column-value pairs determine which columns are written. Column names must be strings and must already exist in the database schema (add them with `columns_add_ignore()`). A failing query throws a `RuntimeException`, so `row_upsert()` returns `true` or throws.
The following PHP data types are supported and internally mapped to SQLite’s dynamic typing system:
- `null` → `SQLITE3_NULL`
- `string` → `SQLITE3_TEXT`
- `int` → `SQLITE3_INTEGER`
- `float` → `SQLITE3_FLOAT`
- `resource` → `SQLITE3_BLOB`
- `array` → `SQLITE3_BLOB` (encoded as JSON)

Other types (e.g. `bool` or objects) throw a `RuntimeException` ("Unsupported data type").

Both resource values and array values share the same SQLite storage class (BLOB). To disambiguate these two logically different data types, arrays are serialized into JSON before storage and are prefixed with a dedicated alias marker. This alias ensures that JSON-encoded payloads can be reliably distinguished from raw binary stream data when reading from the database.
```php
$row = ['title' => 'Hello World 1', 'views' => 42];

if( $db->row_upsert( 'post_1', $row ) ) {
	echo 'Query was executed successfully';
}
```

### UPSERT using loops
```php
$rows = [
	'post_1' => ['title' => 'Hello World 1', 'views' => 42],
	'post_2' => ['title' => 'Hello World 2', 'views' => 7],
	'post_3' => ['title' => 'Hello World 3', 'views' => 0],
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

### Binary data (BLOB)
Binary data is stored and read as a stream resource. Two static conversion helpers convert between strings and stream resources:
- `string_to_resource( $data )`: converts a string into a stream resource for `row_upsert()`. Returns `false` if the value is not a string or the stream cannot be created.
- `resource_to_string( $data )`: reads a stream resource (e.g. a BLOB value of `select()`) into a string. Returns `false` if the value is not a stream resource or the stream cannot be read.

```php
$blob = ToolkitSQLite3::string_to_resource( file_get_contents( 'image.avif' ) );

if( $blob !== false ) {
	$db->row_upsert( 'image_1', ['payload' => $blob] );
}

$rows = $db->select( [
	'columns' => ['payload'],
	'where'   => ['column' => '_slug', 'op' => '=', 'value' => 'image_1']
] );

$data = ToolkitSQLite3::resource_to_string( $rows[0]['payload'] ); // The binary string, or false on failure.
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

### Basic Usage
If no arguments are provided:
- all columns are selected
- all rows are returned
- no filtering is applied
```php
$result = $db->select( [] );
```

`select()` returns an array of rows (an empty array if nothing matches). Each row is an associative array of column name => value. The value has the PHP type of its SQLite3 runtime type: `null`, `string`, `int`, `float`, `array` (JSON-encoded arrays) or a stream `resource` (other BLOBs, read it with `resource_to_string()`).

### Selecting Specific Columns
Use the columns argument to limit the selected columns.
```php
$result = $db->select( [
	'columns' => ['title', 'views']
] );
```

### DISTINCT
Removes duplicate rows from the result set.
```php
$result = $db->select( [
	'distinct' => true,
	'columns'  => ['title']
] );
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
```

### LIMIT
Restricts the maximum number of rows returned by the query. The value must be an `int` (like `offset`), other values are ignored.
```php
$result = $db->select( [
	'limit' => 10
] );
```

### OFFSET
Defines the number of rows to skip before returning results.
```php
$result = $db->select( [
	'offset' => 20
] );
```

### WHERE
The where argument accepts a structured logical expression tree. A leaf that is no valid predicate throws a `RuntimeException`: an unknown operator, a binary predicate without `value` or with `'value' => null` (use `IS NULL`), an `IN` with an empty `values` list, or a `BETWEEN` whose `min` or `max` is missing or not scalar.
#### Binary predicate (=, !=, <, <=, >, >=, LIKE, GLOB)
```php
$result = $db->select( [
	'where' => [
		'column' => 'views',
		'op'     => '>=',
		'value'  => 100
	]
] );
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
```

#### Null predicate (IS NULL)
```php
$result = $db->select( [
	'where' => [
		'column' => 'payload',
		'op'     => 'IS NULL'
	]
] );
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
```

## Concurrent Access
Several processes (e.g. parallel requests) can use the same database file. Two protected constants control the behavior and can be changed via class extension (late static binding):
- `BUSY_TIMEOUT` (default `10000`): milliseconds to wait for the lock of another process before an operation fails with "database is locked".
- `JOURNAL_MODE` (default `'WAL'`): with Write-Ahead Logging, readers do not block writers and vice versa. SQLite3 creates the files `*-wal` and `*-shm` next to the database file, so the folder must be writable. WAL does not work on network file systems, use e.g. `'DELETE'` there. The journal mode is stored permanently in the database file, so the constructor writes it only if it differs from the current mode (changing it does not use the busy timeout).

```php
class ToolkitSQLite3New extends ToolkitSQLite3 {

	protected const BUSY_TIMEOUT = 20000;
	protected const JOURNAL_MODE = 'DELETE';
}

$db = new ToolkitSQLite3New( 'database.sqlite3', 'example_table' );
```

## Reserved Columns
The following columns are internally reserved and automatically allowed without validation:
- _id
- _slug
- _created_at
- _updated_at

## Schema & SQL Safety
This toolkit enforces strict validation and protection mechanisms to ensure safe database operations.

It validates:
- table names
- column names
- row identifiers

All runtime values are bound using prepared statements. Table and column identifiers are strictly validated, and no user input is ever concatenated directly into SQL statements.

If invalid parameters are detected, execution stops immediately with a clear error message, preventing silent data loss or inconsistent schema states.

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
- **PHP extensions:** `sqlite3` with SQLite 3.37 or higher

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

#### Version 1.4.3 Changelog:
- README: the class extension in the example is named `ToolkitSQLite3New` instead of `ToolkitSQLite3Slow`. No functional changes.

#### Version 1.4.2 Changelog:
- Breaking change: README: the minimum requirement is PHP 8.3 (before: 8.1). New section "Requirements" (PHP, PHP extensions, PHP settings, browser, repositories, other requirements), structured the same in all repositories.

#### Version 1.4.1 Changelog:
- Code style: the README examples are indented with tabs instead of four spaces. No functional changes.

#### Version 1.4.0 Changelog:
- Comments: the constructor and the destructor have a description, `resource_to_string()` and `string_to_resource()` describe their return value (`false` on failure), `consume_query()` and `select_buildWhere__renderLeafNode()` mention the `RuntimeException`.
- README: the section "Row Handling" no longer says that the `_slug` has no restrictions (an empty slug throws). New: the table prefix (`TABLE_PREFIX`) and the constant `MAX_LENGTH`, the `RuntimeException` for unsupported data types in `row_upsert()` and for invalid WHERE predicates, `limit` and `offset` must be an `int`. The example "UPSERT using loops" writes three different rows. The performance note on `PRAGMA table_info` describes its current use. No functional changes.

#### Version 1.3.9 Changelog:
- README: new section "Binary data (BLOB)" describes the conversion helpers `string_to_resource()` and `resource_to_string()` with an example and their return value (`false` on failure). No functional changes.

#### Version 1.3.8 Changelog:
- Breaking change: the connection uses `SQLite3::enableExceptions( true )`. Every SQLite3 error throws a `RuntimeException` (message "ToolkitSQLite3: (SQLite3 error) …", the original exception via `getPrevious()`, new helper `throw_rethrow_sqlite_error()`) instead of a PHP warning and the return value `false`. Also a database that cannot be opened (`new SQLite3()` throws a plain `Exception`) throws a `RuntimeException`. `table_add_ignore()`, `table_delete_ignore()`, `columns_add_ignore()` and `columns_delete_ignore()` return `true` or throw (callers that checked `false` now receive the exception), `columns_add_ignore()` and `columns_delete_ignore()` on a missing table throw "no such table".
- `try`/`catch( SQLite3Exception )` in the constructor (`catch( Exception )`), `consume_query()`, `table_add_ignore()`, `table_delete_ignore()`, `columns_add_ignore()`, `columns_delete_ignore()` and `select_buildList()`.
- Bugfix (race condition): `columns_add_ignore()` and `columns_delete_ignore()` run one `ALTER TABLE` per column without `column_exists()` before (the column names are validated directly), "duplicate column name" and "no such column" count as success. Before, two processes that added (or removed) the same column at the same time failed. Tested with 8 parallel processes and 40 columns: before 53 (add) and 102 (delete) of 320 operations failed, now none.
- README: new section "Error Handling", two notes, the examples of the four methods above no longer show an unreachable `else { echo 'Query error'; }`.

#### Version 1.3.7 Changelog:
- Code style: `consume_query()` sets `$err = false` after the callback instead of before it (the statement counts as successful after the callback has finished). No functional changes.

#### Version 1.3.6 Changelog:
- Comments and README: `row_upsert()` and `row_remove()` never return `false` on a failing query, `consume_query()` throws a `RuntimeException` ("false on query error" and "or the deletion failed" were wrong). The comments of all methods that use `consume_query()` (`table_exists()`, `column_exists()`, `row_isset()`, `row_upsert()`, `row_remove()`, `select()`) mention it. The examples of `row_upsert()` no longer show an unreachable `else { echo 'Query error'; }`. `row_upsert()` does not create columns ("created or updated" was wrong). The section "Concurrent Access" explains that the journal mode is written only if it differs. The section "SELECT Queries" describes the return value of `select()` (array of rows, PHP types of the values). The example of `row_remove()` shows the return value (`true` if a row was removed, `false` if no matching row existed). The comments "// Last check …" are removed from the examples. No functional changes.

#### Version 1.3.5 Changelog:
- Breaking change: table and column names must start with a lowercase letter and contain only lowercase letters, digits or underscores (before: also uppercase letters), otherwise a `RuntimeException` is thrown. SQLite3 compares names case-insensitively, so `B` and `b` are the same column: e.g. `columns_delete_ignore( 'B' )` checked `column_exists( 'B' )` case-sensitively. Callers with uppercase names must use lowercase names, existing databases with uppercase table or column names must be created again.

#### Version 1.3.4 Changelog:
- Code style: `select_buildList()` closes the result of `PRAGMA table_info` in a `finally` block, the same way as `consume_query()`. No functional changes.

#### Version 1.3.3 Changelog:
- Bugfix: the constructor writes the journal mode only if it differs from the current one (the mode, e.g. WAL, is stored permanently in the database file). Changing the journal mode does not use the busy timeout, in parallel operation it failed sporadically with "database is locked".
- Bugfix: `consume_query()` executes the statement only if the SQL contains no placeholder without a value (`count( $comparison ) === $stmt->paramCount()`). Before, only the passed values were checked (`array_keys( $bindings ) === $comparison`), a placeholder in the SQL without a passed value was used as `NULL` silently and the row was stored with an empty value.

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
