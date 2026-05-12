# ToolkitSQLite3
## by Markus Jäger
### Version 0.99

---

ToolkitSQLite3 is a lightweight PHP helper class and abstraction layer over SQLite3.
It provides a clean, predictable, and safe API for common database operations, including schema management and data manipulation.

## Features
- Tables: existence checks, creation, and deletion.
- Columns: existence checks, creation, and deletion.
- Rows: existence checks, creation or update (UPSERT), and deletion.
- Strict validation of table and column names.
- Use of prepared statements and input validation.
- No fixed data type definitions per column; instead dynamic typing at the value level (SQLite3-typical).
- Focus on a simple API, high performance, and protection against SQL injection.
- Retrieving data via SELECT using common predicates.

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
Each row is uniquely identified by a _slug value. This field is predefined within the API and serves as a unique identifier for individual records. Neither the _slug nor the stored values are subject to any restrictions regarding characters or length. All inputs are internally handled using prepared statements to ensure proper and secure parameter binding.

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

The defined name-value pairs determine which columns are created or updated. Column names must be strings and must already exist in the database schema.
Values may use the following PHP data types, which are internally mapped to SQLite’s dynamic typing system at the value level:
- `null` → `SQLITE3_NULL`
- `string` → `SQLITE3_TEXT`
- `int` → `SQLITE3_INTEGER`
- `float` → `SQLITE3_FLOAT`
- `resource` → `SQLITE3_BLOB`

Note: Due to SQLite3 limitations, values such as `bool` or `array` are not supported directly and should be converted beforehand, for example to `integer` or `JSON`.
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

## SELECT Query
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
$result = $db->select([])
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
Use distinct => true to remove duplicate rows.
```php
$result = $db->select([
    'distinct' => true,
    'columns'  => ['title']
]);
// Last check 12.05.2026
```

### ORDER BY
Use the orderby argument.
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
```php
$result = $db->select([
    'limit' => 10
]);
// Last check 12.05.2026
```

### OFFSET
```php
$result = $db->select([
    'offset' => 20
]);
// Last check 12.05.2026
```

### WHERE 
#### Binary predicate
The where argument accepts a structured logical expression tree.
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
- SQLite runtime types are retrieved using `typeof()` for every selected column.
- Nested logical expressions are fully recursive.
- LIMIT and OFFSET values are clamped to a minimum of `0`.

## Performance Notes
- `PRAGMA table_info` is used for schema validation.
- SQLite3 internally caches schema information.
- For typical workloads, this is not a performance bottleneck.
- No unnecessary caching logic is introduced.

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

#### Version 0.99 Changelog:
- Code Quality.
- Finalized return of the selected method.

#### Version 0.98 Changelog:
- Complete overhaul of the code.

#### Version 0.97 Changelog:
- Code Quality.

#### Version 0.96 Changelog:
- Code Quality.

#### Version 0.95 Changelog:
- Update README.md and text indentation ToolkitSQLite3.php

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