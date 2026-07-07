# ToolkitSQLite3
## by Markus Jäger
### Version 1.2.1

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

## Requirements
- PHP 8.1 or higher
- SQLite3 extension enabled (SQLite3 ≥ 3.37)

## Installation
Include the class file:
```php
require 'ToolkitSQLite3.php';
// Last check 06.05.2026
```

## Basic Usage
Table names must start with a letter and may only contain letters, numbers, or underscores. Additionally, they may be a maximum of 512 characters long (this behavior can be modified using late static binding).

```php
$db = new ToolkitSQLite3( 'database.sqlite3', 'example_table' );
// Last check 06.05.2026
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
// Last check 11.05.2026
```

### Create a table (ignored if it already exists)
```php
if( $db->table_add_ignore() ) {
    echo 'Query was executed successfully';
}
else {
    echo 'Query error';
}
// Last check 11.05.2026
```

### Delete a table (ignored if it does not exist)
```php
if( $db->table_delete_ignore() ) {
    echo 'Query was executed successfully';
}
else {
    echo 'Query error';
}
// Last check 11.05.2026
```

## Column Handling
Column names must start with a letter and may only contain letters, numbers, or underscores. Additionally, they may be a maximum of 512 characters long (this behavior can be modified using late static binding). In contrast to traditional database systems, no fixed data types are defined per column. Instead, typing is handled dynamically at the value level, following the SQLite3-style approach.

### Check if a column exists
```php
if( $db->column_exists( 'title' ) ) {
    echo 'Column exists';
}
else {
    echo 'Column does not exist';
}
// Last check 11.05.2026
```

### Add columns (ignored if they already exist)
```php
if( $db->columns_add_ignore( 'title', 'views', 'payload', 'float' ) ) {
    echo 'Query was executed successfully';
}
else {
    echo 'Query error';
}
// Last check 11.05.2026
```

### Delete columns (ignored if they do not exist)
```php
if( $db->columns_delete_ignore( 'title', 'views', 'payload', 'float' ) ) {
    echo 'Query was executed successfully';
}
else {
    echo 'Query error';
}
// Last check 11.05.2026
```

## Row Handling
Each row is uniquely identified by its _slug value. This field is internally managed by the API and serves as a unique identifier for individual records. Neither the _slug nor the stored values are subject to any restrictions regarding characters or length. All inputs are internally handled using prepared statements to ensure proper and secure parameter binding.

### Check if a row exists
```php
if( $db->row_isset( 'post_1' ) ) {
    echo 'Row exists';
}
else {
    echo 'Row does not exist';
}
// Last check 11.05.2026
```

### Insert or update a row (UPSERT)
Behavior
- Inserts a new row if _slug does not exist in the database.
- Updates the row if _slug already exists in the database.
- _created_at is set only on initial insert.
- _updated_at is set on both insert and update operations.

The provided column-value pairs determine which columns are created or updated. Column names must be strings and must already exist in the database schema.
The following PHP data types are supported and internally mapped to SQLite’s dynamic typing system:
- `null` → `SQLITE3_NULL`
- `string` → `SQLITE3_TEXT`
- `int` → `SQLITE3_INTEGER`
- `float` → `SQLITE3_FLOAT`
- `resource` → `SQLITE3_BLOB`
- `array` → `SQLITE3_BLOB` (encoded as JSON)

Both resource values and array values share the same SQLite storage class (BLOB). To disambiguate these two logically different data types, arrays are serialized into JSON before storage and are prefixed with a dedicated alias marker. This alias ensures that JSON-encoded payloads can be reliably distinguished from raw binary stream data when reading from the database.
```php
$row = ['title' => 'Hello World 1', 'views' => 42];

if( $db->row_upsert( 'post_1', $row ) ) {
    echo 'Query was executed successfully';
}
else {
    echo 'Query error';
}
// Last check 11.05.2026
```

### UPSERT using loops
```php
$rows = [
    ['title' => 'Hello World 1', 'views' => 42],
    ['title' => 'Hello World 1', 'views' => 42],
    ['title' => 'Hello World 1', 'views' => 42],
];

foreach( $rows as $row ) {

    if( $db->row_upsert( 'post_1', $row ) ) {
        echo 'Query was executed successfully';
    }
    else {
        echo 'Query error';
    }
}
// Last check 11.05.2026
```

### Remove a row
```php
$db->row_remove( 'post_1' );
// Last check 01.01.2026
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
$result = $db->select([]);
// Last check 12.05.2026
```

### Selecting Specific Columns
Use the columns argument to limit the selected columns.
```php
$result = $db->select([
    'columns' => ['title', 'views']
]);
// Last check 12.05.2026
```

### DISTINCT
Removes duplicate rows from the result set.
```php
$result = $db->select([
    'distinct' => true,
    'columns'  => ['title']
]);
// Last check 12.05.2026
```

### ORDER BY
Defines the result ordering based on one or more columns with ASC or DESC direction.
```php
$result = $db->select([
    'orderby' => [
        'views' => 'DESC',
        'title' => 'ASC'
    ]
]);
// Last check 12.05.2026
```

### LIMIT
Restricts the maximum number of rows returned by the query.
```php
$result = $db->select([
    'limit' => 10
]);
// Last check 12.05.2026
```

### OFFSET
Defines the number of rows to skip before returning results.
```php
$result = $db->select([
    'offset' => 20
]);
// Last check 12.05.2026
```

### WHERE
The where argument accepts a structured logical expression tree.
#### Binary predicate (=, !=, <, <=, >, >=, LIKE, GLOB)
```php
$result = $db->select([
    'where' => [
        'column' => 'views',
        'op'     => '>=',
        'value'  => 100
    ]
]);
// Last check 12.05.2026
```

#### Membership predicate (IN)
```php
$result = $db->select([
    'where' => [
        'column' => 'status',
        'op'     => 'IN',
        'values' => ['open', 'closed']
    ]
]);
// Last check 12.05.2026
```

#### Range predicate (BETWEEN)
```php
$result = $db->select([
    'where' => [
        'column' => 'views',
        'op'     => 'BETWEEN',
        'min'    => 10,
        'max'    => 100
    ]
]);
// Last check 12.05.2026
```

#### Null predicate (IS NULL)
```php
$result = $db->select([
    'where' => [
        'column' => 'payload',
        'op'     => 'IS NULL'
    ]
]);
// Last check 12.05.2026
```

#### AND conditions
```php
$result = $db->select([
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
]);
// Last check 12.05.2026
```

#### OR conditions
```php
$result = $db->select([
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
]);
// Last check 12.05.2026
```

#### NOT conditions
```php
$result = $db->select([
    'where' => [
        'NOT' => [
            'column' => 'status',
            'op'     => '=',
            'value'  => 'deleted'
        ]
    ]
]);
// Last check 12.05.2026
```

#### Nested Logical Trees
Complex logical structures can be nested arbitrarily.
```php
$result = $db->select([
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
]);
// Last check 12.05.2026
```

## Combining Multiple Query Components
```php
$result = $db->select([
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
]);
// Last check 12.05.2026
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
- Empty WHERE trees throw an exception.
- Unsupported operators are rejected automatically.
- SQLite runtime types are determined using `typeof()` for every selected column.
- Nested logical expressions are fully recursive.
- LIMIT and OFFSET values are clamped to a minimum of `0`.

## Performance Notes
- `PRAGMA table_info` is used for schema validation.
- SQLite3 internally caches schema information.
- For typical workloads, this is not a performance bottleneck.
- No additional caching layer is introduced.

## Design Philosophy
- No ORM
- No hidden state
- No auto-migrations
- Explicit is better than implicit

This toolkit is designed to be predictable, readable, and boring — in the best possible way.

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

#### Version 1.2.1 Changelog:
- Added and improved code comments for better readability and maintainability.

#### Version 1.2 Changelog:
- Updated README.md.

#### Version 1.1 Changelog:
- Added support for storing PHP arrays via JSON encoding in BLOB columns.
- Introduced a type alias prefix for JSON payloads to distinguish them from raw resource BLOB data.
- Enhanced BLOB handling to support both binary streams (resource) and structured data (array) within the same storage class.
- Improved decode logic to automatically restore JSON-tagged arrays on retrieval.

#### Version 1.0 Changelog:
- Code fully reviewed and extensively refactored
- Naming conventions standardized and made consistent across the project
- Comments cleaned up, reduced, and structurally unified
- Documentation reviewed and aligned with the current implementation
- Internal logic and workflows validated and stabilized
- Error handling centralized and standardized
- Prepared statement handling reviewed and made more robust
- SQL generation and parameter binding checked for consistency and safety

#### Version 0.99 Changelog:
- Code Quality.
- Finalized return of the selected method.

#### Version 0.98 Changelog:
- Complete codebase overhaul.

#### Version 0.97 Changelog:
- Code Quality.

#### Version 0.96 Changelog:
- Code Quality.

#### Version 0.95 Changelog:
- Updated README.md and text indentation in ToolkitSQLite3.php.

#### Version 0.94 Changelog:
- Added LICENSE file and license info in source code.

#### Version 0.93 Changelog:
- Update.

#### Version 0.92 Changelog:
- Update.

#### Version 0.91 Changelog:
- Update.

#### Version 0.9 Changelog:
- Init.