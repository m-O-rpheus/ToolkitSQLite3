# ToolkitSQLite3
## by Markus Jäger
### Version 0.94

---

ToolkitSQLite3 is a lightweight PHP helper class designed for simple, safe, and predictable interaction with SQLite3.
It encapsulates common database tasks such as table and column management as well as row upserts in a clean and performant API.

## Features
- Automatic creation and deletion of tables
- Adding and removing columns (ignore-safe)
- Safe UPSERT operations on rows using `_slug`
- Strict sanitization of table and column names
- Prepared statements with correct type mapping
- Focus on simplicity, performance, and SQL injection safety
- All "ignore" operations are implemented via schema pre-checks, not via SQL IF EXISTS clauses

## Requirements
- PHP 8.1 or higher
- SQLite3 extension enabled (SQLite ≥ 3.35)

## Installation
Include the class file:
```php
require 'ToolkitSQLite3.php';
// Last check 01.01.2026
```

## Basic Usage
```php
$db = new ToolkitSQLite3( 'database.sqlite3', 'example_table' );
// Last check 01.01.2026
```

## Table Handling
**Check if a table exists**
```php
if ( $db->table_exists() ) {
    echo 'Table exists';
}
// Last check 01.01.2026
```

**Create a table (ignored if it already exists)**
```php
$result = $db->table_add_ignore();

if ( $result === 1 ) {
    echo 'Table created';
} elseif ( $result === 2 ) {
    echo 'Table already exists';
} else {
    echo 'Query error';
}
// Last check 01.01.2026
```

**Delete a table (ignored if it does not exist)**
```php
$result = $db->table_delete_ignore();
// Last check 01.01.2026
```

## Column Handling
**Add a column (ignored if it already exists)**
```php
$db->column_add_ignore( 'title', 'TEXT' );
$db->column_add_ignore( 'views', 'INTEGER' );
$db->column_add_ignore( 'data', 'BLOB' );
// Last check 01.01.2026
```

**Create table and multiple columns at once**
```php
$db->table_column_add_ignore( [
    'title'   => 'TEXT',
    'views'   => 'INTEGER',
    'payload' => 'BLOB',
] );
// Last check 01.01.2026
```

**Check if a column exists**
```php
if ( $db->column_exists( 'title' ) ) {
    echo 'Column exists';
}
// Last check 01.01.2026
```

**Remove a column**
```php
$db->column_delete_ignore( 'views' );
// Last check 01.01.2026
```

## Row Handling
Each row is uniquely identified by a _slug value

**Check if a row exists**
```php
if ( $db->row_isset( 'post_1' ) ) {
    echo 'Row exists';
}
// Last check 01.01.2026
```

**Insert or update a row (UPSERT)**
```php
$db->row_upsert( 'post_1', [
    'title' => 'Hello World',
    'views' => 42,
] );
// Last check 01.01.2026
```
Behavior
- Inserts a new row if _slug does not exist
- Updates the row if _slug already exists
- _created_at is set on first insert.
- _updated_at is always set on insert and update.

Return value
- true – query executed successfully
- false – query error

**UPSERT using loops**
```php
$items = [
    'a' => 'Alpha',
    'b' => 'Beta',
    'c' => 'Gamma',
];

foreach ( $items as $slug => $value ) {
    $db->row_upsert( $slug, [
        'title' => $value,
    ] );
}
// Last check 01.01.2026
```

**Delete a row**
```php
$db->row_remove( 'post_1' );
// Last check 01.01.2026
```

## Schema Safety
This toolkit validates:
- table names
- column names
- column types
- missing columns during UPSERT operations
If invalid parameters are detected, execution stops immediately with a clear error message, preventing silent data loss or inconsistent schema states.

## SQL Injection Safety
- All values are bound using prepared statements
- Table and column names are strictly validated
- No user input is ever concatenated into SQL values

## Performance Notes
- PRAGMA table_info is used for schema validation
- SQLite internally caches schema information
- For typical workloads, this is not a performance bottleneck
- No unnecessary caching logic is introduced

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