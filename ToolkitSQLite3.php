<?php


/*
 * ToolkitSQLite3
 *
 * This repository is licensed under the MIT License.
 *
 * Any use, copy, modification, or redistribution of this repository
 * or any substantial portion of it must retain attribution to the
 * original author and the original GitHub repository.
 *
 * Copyright (c) 2026 Markus Jäger
 * https://github.com/m-O-rpheus/ToolkitSQLite3
 */


	class ToolkitSQLite3 {


		use ToolkitSQLite3_methods_convert;
		use ToolkitSQLite3_methods_errors;
		use ToolkitSQLite3_methods_table;
		use ToolkitSQLite3_methods_column;
		use ToolkitSQLite3_methods_row;
		use ToolkitSQLite3_methods_select;
		use ToolkitSQLite3_methods_select__parts;
		use ToolkitSQLite3_methods_select__where;





		// Protected constants used for configuration and default settings.
		// -----------------------------------------------------------------------------------------------------------------------------

		protected const TABLE_PREFIX = 'toolkitsqlite3_'; // Prefix used to identify the context and is prepended to table names.
		protected const TYPE_ALIAS   = '_typeof_';        // Prefix for SELECT alias columns used to temporarily store SQLite3 value types.
		protected const MAX_LENGTH   = 512;               // Maximum length for table and column names (excluding the prefix).
		protected const BUSY_TIMEOUT = 10000;             // Milliseconds to wait for a lock of another process before "database is locked" (0 = fail immediately).
		protected const JOURNAL_MODE = 'WAL';             // SQLite3 journal mode (WAL: readers and writers do not block each other, stored in the database file).





		// Private members and helper methods used internally for class functionality.
		// -----------------------------------------------------------------------------------------------------------------------------

		private readonly SQLite3 $sqlite;
		private readonly string  $tblnam;

		// Validates the table name, opens the database file (it is created if it does not exist) and sets the busy timeout and the journal mode.
		// The table is stored with the prefix TABLE_PREFIX (e.g. "toolkitsqlite3_example_table"). Every error throws a RuntimeException.
		public function __construct( string $fileName, string $tableName ) {

			self::validate_sqlite_name_guard_clause( $tableName );

			// Every SQLite3 error (opening and configuring the database) is passed on as a RuntimeException.
			try {

				$this->sqlite = new SQLite3( $fileName ); // Throws an Exception if the database cannot be opened.
				$this->sqlite->enableExceptions( true );  // Must come directly after opening: every following SQLite3 call throws a SQLite3Exception on error (instead of a warning and false).
				$this->sqlite->busyTimeout( static::BUSY_TIMEOUT );

				// The journal mode is stored permanently in the database file, so it is only changed if it differs.
				if( strcasecmp( strval( $this->sqlite->querySingle( 'PRAGMA journal_mode;' ) ), static::JOURNAL_MODE ) !== 0 ) {

					$this->sqlite->exec( 'PRAGMA journal_mode = ' . static::JOURNAL_MODE . ';' );
				}
			}
			catch( Exception $e ) {

				self::throw_rethrow_sqlite_error( $e );
			}

			$this->tblnam = static::TABLE_PREFIX . $tableName;
		}

		// Closes the database connection.
		public function __destruct() {

			$this->sqlite->close();
		}



		// Generates a unique SQL parameter token (e.g. :row_any1, :row_string2, :row_int3, ...), the counter keeps the names unique within the instance.
		// The prefix decides how consume_query() binds the value.
		private int $i = 0;

		private function get_unique_token( string $prefix = ':row_any' ) : string {

			$this->i++;

			/** @var string */
			return $prefix . $this->i;
		}



		// Executes a prepared SQLite3 statement with named placeholders and an associative array of placeholder name => value.
		// The placeholder name decides the binding of each value (see the three branches below). A string is bound as BLOB (byte-exact, also with NUL bytes)
		// and an array as JSON TEXT, so select() can tell both apart by their storage class.
		// The callback is executed only if all values were bound, otherwise (e.g. a placeholder without a value) a RuntimeException is thrown.
		private function consume_query( string $sql, array $bindings, Closure $callback ) : void {

			// Every SQLite3 error (preparing, binding, executing and reading the rows in the callback) is passed on as a RuntimeException.
			try {

				$err = true;

				// Prepare the SQLite3 statement.
				if( ( $stmt = $this->sqlite->prepare( $sql ) ) !== false ) {

					$comparison = [];

					foreach( $bindings as $paramName => $paramValue ) {

						if( is_string( $paramName ) ) {

							// paramName:  string
							// paramValue: mixed  (null, string, float, int, array)
							// paramType:  int    (SQLite3 type constant)

							// :table_name, :column_name and :row_string (values of _slug) skip the type mapping: always PHP string -> SQLite3 TEXT, other PHP types throw.
							if( $paramName === ':table_name' || $paramName === ':column_name' || str_starts_with( $paramName, ':row_string' ) ) {

								if( is_string( $paramValue ) ) {

									$paramType = SQLITE3_TEXT;
								}
								else self::throw_unsupported_data_type();
							}

							// :row_int (values of _id, _created_at and _updated_at) skips the type mapping: always PHP int -> SQLite3 INTEGER, other PHP types throw.
							else if( str_starts_with( $paramName, ':row_int' ) ) {

								if( is_int( $paramValue ) ) {

									$paramType = SQLITE3_INTEGER;
								}
								else self::throw_unsupported_data_type();
							}

							// :row_any (values of the user columns) uses the type mapping PHP -> SQLite3, because the user columns have no fixed type (ANY). Other PHP types throw.
							else if( str_starts_with( $paramName, ':row_any' ) ) {

								if( is_null( $paramValue ) ) {        // typemapping: PHP null -> SQLite3 NULL

									$paramType = SQLITE3_NULL;
								}
								else if( is_string( $paramValue ) ) { // typemapping: PHP string -> SQLite3 BLOB

									$paramType = SQLITE3_BLOB;
								}
								else if( is_float( $paramValue ) ) {  // typemapping: PHP float -> SQLite3 REAL

									$paramType = SQLITE3_FLOAT;
								}
								else if( is_int( $paramValue ) ) {    // typemapping: PHP int -> SQLite3 INTEGER

									$paramType = SQLITE3_INTEGER;
								}
								else if( is_array( $paramValue ) ) {  // typemapping: PHP array -> SQLite3 TEXT (JSON)

									$paramValue = self::jsonEncode( $paramValue );
									$paramType  = SQLITE3_TEXT;
								}
								else self::throw_unsupported_data_type();
							}
							else self::throw_unsupported_data_type();

							// Bind the value to its placeholder name.
							if( ( $stmt->bindValue( $paramName, $paramValue, $paramType ) ) !== false ) {

								$comparison[] = $paramName;
							}
						}
					}

					// Execute only if all values were bound and the SQL has no placeholder without a value (it would be NULL silently).
					if( array_keys( $bindings ) === $comparison && count( $comparison ) === $stmt->paramCount() ) {

						if( ( $result = $stmt->execute() ) !== false ) {

							try {

								/** @param Closure(SQLite3Result) $callback */
								$callback( $result );

								$err = false;
							}
							finally {

								$result->finalize();
							}
						}
					}
				}

				if( $err ) {

					self::throw_statement_binding_failed();
				}
			}
			catch( SQLite3Exception $e ) {

				self::throw_rethrow_sqlite_error( $e );
			}
		}





		// Validation methods using exception handling. They are public, so that names can be checked in advance (e.g. when a form is rendered).
		// -----------------------------------------------------------------------------------------------------------------------------

		final public static function validate_sqlite_name_guard_clause( string $name ) : void {

			if( strlen( $name ) > static::MAX_LENGTH || preg_match( '/^[a-z][a-z0-9_]*\z/', $name ) !== 1 ) {

				self::throw_invalid_sqlite_name();
			}
		}


		final public static function validate_row_slug_guard_clause( string $slug ) : void {

			if( strlen( $slug ) === 0 ) {

				self::throw_empty_row_slug();
			}
		}
	}





	// Conversion helpers between PHP arrays and the JSON TEXT of the user columns (see consume_query() and select()).
	// jsonEncode() replaces invalid UTF-8 in a string by U+FFFD, jsonDecode() always gets a JSON array of jsonEncode().
	// Every JSON error (e.g. INF, NAN or too deep nesting) throws a RuntimeException (see throw_rethrow_json_error()).
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_convert {


		private static function jsonEncode( array $data ) : string {

			try {

				/** @var string */
				return json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
			}
			catch( JsonException $e ) {

				self::throw_rethrow_json_error( $e );
			}
		}


		private static function jsonDecode( string $data ) : array {

			try {

				/** @var array */
				return json_decode( $data, true, 512, JSON_THROW_ON_ERROR );
			}
			catch( JsonException $e ) {

				self::throw_rethrow_json_error( $e );
			}
		}
	}





	// Centralized exception handling helpers.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_errors {


		private static function throw_invalid_sqlite_name() : never {

			throw new RuntimeException( 'ToolkitSQLite3: Invalid SQLite3 table or column name. It must start with a lowercase letter and contain only lowercase letters, digits, or underscores, and be at most ' . static::MAX_LENGTH . ' characters long.' );
		}


		private static function throw_empty_row_slug() : never {

			throw new RuntimeException( 'ToolkitSQLite3: The row slug in SQLite3 must not be empty.' );
		}


		private static function throw_where_all_predicate_empty() : never {

			throw new RuntimeException( 'ToolkitSQLite3: Failed to generate the SQLite3 WHERE clause. No valid predicate expression could be resolved from the provided WHERE definition.' );
		}


		private static function throw_unsupported_data_type() : never {

			throw new RuntimeException( 'ToolkitSQLite3: Unsupported data type. Allowed PHP <> SQLite3 runtime types are: null, string, float, int, array (values of _slug: only string, values of _id, _created_at and _updated_at: only int).' );
		}


		private static function throw_statement_binding_failed() : never {

			throw new RuntimeException( 'ToolkitSQLite3: Binding of values for the prepared statement failed due to an unexpected error.' );
		}


		private static function throw_rethrow_sqlite_error( Exception $e ) : never {

			throw new RuntimeException( 'ToolkitSQLite3: (SQLite3 error) The SQLite3 operation failed: ' . $e->getMessage(), 0, $e );
		}


		private static function throw_rethrow_json_error( JsonException $e ) : never {

			throw new RuntimeException( 'ToolkitSQLite3: (JSON error) The conversion between array and JSON TEXT failed: ' . $e->getMessage(), 0, $e );
		}
	}





	// Instance Table-level operations for SQLite3 tables.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_table {


		// Checks whether the table exists in the database.
		// Returns true if the table exists, otherwise false. A failing query throws a RuntimeException.
		public function table_exists() : bool {

			$bindings = [':table_name' => $this->tblnam];

			$sql = trim(<<<SQL
				SELECT 1 FROM sqlite_master WHERE type='table' AND name=:table_name LIMIT 1;
			SQL);

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = $result->fetchArray( SQLITE3_NUM ) !== false;
			});

			/** @var bool */
			return $fnResult;
		}



		// Creates the table if it does not exist.
		// _created_at and _updated_at store the Unix timestamp as INTEGER (seconds, UTC, unixepoch() requires SQLite3 3.38).
		// Returns true, the table exists afterwards. Every SQLite3 error throws a RuntimeException.
		public function table_add_ignore() : bool {

			$sql = trim(<<<SQL
				CREATE TABLE IF NOT EXISTS "{$this->tblnam}" (
					"_id" INTEGER PRIMARY KEY AUTOINCREMENT,
					"_slug" TEXT NOT NULL UNIQUE,
					"_created_at" INTEGER NOT NULL DEFAULT (unixepoch()),
					"_updated_at" INTEGER NOT NULL DEFAULT (unixepoch())
				) STRICT;
			SQL);

			try {

				$this->sqlite->exec( $sql );
			}
			catch( SQLite3Exception $e ) {

				self::throw_rethrow_sqlite_error( $e );
			}

			/** @var bool */
			return true;
		}



		// Drops the table if it exists.
		// Returns true, the table does not exist afterwards. Every SQLite3 error throws a RuntimeException.
		public function table_delete_ignore() : bool {

			$sql = trim(<<<SQL
				DROP TABLE IF EXISTS "{$this->tblnam}";
			SQL);

			try {

				$this->sqlite->exec( $sql );
			}
			catch( SQLite3Exception $e ) {

				self::throw_rethrow_sqlite_error( $e );
			}

			/** @var bool */
			return true;
		}
	}





	// Instance Column-level operations for SQLite3 tables.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_column {


		// Checks whether a column exists in the current table.
		// Returns true if the column exists, otherwise false. A failing query throws a RuntimeException.
		public function column_exists( string $columnName ) : bool {

			self::validate_sqlite_name_guard_clause( $columnName );

			$bindings = [':table_name' => $this->tblnam, ':column_name' => $columnName];

			$sql = trim(<<<SQL
				SELECT 1 FROM pragma_table_info(:table_name) WHERE name=:column_name LIMIT 1;
			SQL);

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = $result->fetchArray( SQLITE3_NUM ) !== false;
			});

			/** @var bool */
			return $fnResult;
		}



		// Adds columns to the table if they do not already exist.
		// Returns true, all columns exist afterwards. Every other SQLite3 error throws a RuntimeException.
		// The ALTER TABLE runs for every column without a check before (SQLite has no ADD COLUMN IF NOT EXISTS). "duplicate column name" counts as success,
		// also if another process adds the same column at the same time. A failing ALTER TABLE does not wait for the write lock.
		public function columns_add_ignore( string ...$columnNames ) : bool {

			foreach( $columnNames as $columnName ) {

				self::validate_sqlite_name_guard_clause( $columnName );

				$sql = trim(<<<SQL
					ALTER TABLE "{$this->tblnam}" ADD COLUMN "{$columnName}" ANY;
				SQL);

				try {

					$this->sqlite->exec( $sql );
				}
				catch( SQLite3Exception $e ) {

					if( !str_contains( $e->getMessage(), 'duplicate column name' ) ) {

						self::throw_rethrow_sqlite_error( $e );
					}
				}
			}

			/** @var bool */
			return true;
		}



		// Removes columns from the table if they exist.
		// Returns true, none of the columns exists afterwards. Every other SQLite3 error throws a RuntimeException.
		// The ALTER TABLE runs for every column without a check before (SQLite has no DROP COLUMN IF EXISTS). "no such column" counts as success,
		// also if another process removes the same column at the same time. A failing ALTER TABLE does not wait for the write lock.
		public function columns_delete_ignore( string ...$columnNames ) : bool {

			foreach( $columnNames as $columnName ) {

				self::validate_sqlite_name_guard_clause( $columnName );

				$sql = trim(<<<SQL
					ALTER TABLE "{$this->tblnam}" DROP COLUMN "{$columnName}";
				SQL);

				try {

					$this->sqlite->exec( $sql );
				}
				catch( SQLite3Exception $e ) {

					if( !str_contains( $e->getMessage(), 'no such column' ) ) {

						self::throw_rethrow_sqlite_error( $e );
					}
				}
			}

			/** @var bool */
			return true;
		}
	}





	// Instance Row-level operations for SQLite3 tables.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_row {


		// Checks if a row exists in the table using its slug value.
		// Returns true if the row exists, otherwise false. A failing query throws a RuntimeException.
		public function row_isset( string $rowSlug ) : bool {

			self::validate_row_slug_guard_clause( $rowSlug );

			$bindings = [':row_string' => $rowSlug];

			$sql = trim(<<<SQL
				SELECT 1 FROM "{$this->tblnam}" WHERE "{$this->tblnam}"."_slug"=:row_string LIMIT 1;
			SQL);

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = $result->fetchArray( SQLITE3_NUM ) !== false;
			});

			/** @var bool */
			return $fnResult;
		}



		// Inserts a new row or updates an existing row based on the slug value.
		// All values are safely bound using prepared statements and column names are validated at runtime.
		// _updated_at (and _created_at on insert, default of the table) is set to the current Unix timestamp by SQLite3 itself, it is no bound value.
		// Returns true if the query executed successfully (insert or update). A failing query throws a RuntimeException.
		public function row_upsert( string $rowSlug, array $columnNameValuePair ) : bool {

			self::validate_row_slug_guard_clause( $rowSlug );

			$columns  = ['"_slug"'];
			$params   = [':row_string'];
			$clauses  = ['"_updated_at"=unixepoch()'];
			$bindings = [':row_string' => $rowSlug];

			foreach( $columnNameValuePair as $columnName => $columnValue ) {

				self::validate_sqlite_name_guard_clause( $columnName );

				$token = $this->get_unique_token();

				$columns[]        = '"' . $columnName . '"';
				$params[]         = $token;
				$clauses[]        = '"' . $columnName . '"=' . $token;
				$bindings[$token] = $columnValue;
			}

			$columns = implode( ', ', $columns );
			$params  = implode( ', ', $params );
			$clauses = implode( ', ', $clauses );

			$sql = trim(<<<SQL
				INSERT INTO "{$this->tblnam}" ({$columns}) VALUES ({$params}) ON CONFLICT ("_slug") DO UPDATE SET {$clauses};
			SQL);

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = true;
			});

			/** @var bool */
			return $fnResult;
		}



		// Removes a row from the table using its slug value.
		// Returns true if a row was deleted, or false if no matching row existed. A failing query throws a RuntimeException.
		public function row_remove( string $rowSlug ) : bool {

			self::validate_row_slug_guard_clause( $rowSlug );

			$bindings = [':row_string' => $rowSlug];

			$sql = trim(<<<SQL
				DELETE FROM "{$this->tblnam}" WHERE "{$this->tblnam}"."_slug"=:row_string;
			SQL);

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = ( $this->sqlite->changes() > 0 );
			});

			/** @var bool */
			return $fnResult;
		}
	}





	// Instance methods for selecting and filtering database entries.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_select {


		// Builds the SELECT query and executes it using a prepared statement.
		// Two internal helper properties are used within this method and its helpers to manage query state.
		// Returns the found rows as an array. A failing query throws a RuntimeException.
		private array $selectReserved;
		private array $selectBindings;

		public function select( array $args ) : array {

			// Reserved column names (excluded from validation) mapped to the binding prefix of their WHERE values (see consume_query()).
			$this->selectReserved = ['_id' => ':row_int', '_slug' => ':row_string', '_created_at' => ':row_int', '_updated_at' => ':row_int'];
			$this->selectBindings = [];

			$buildDistinct = $this->select_buildDistinct( $args );
			$buildList     = $this->select_buildList( $args );
			$buildWhere    = $this->select_buildWhere( $args );
			$buildOrderBy  = $this->select_buildOrderBy( $args );
			$buildLimit    = $this->select_buildLimit( $args );
			$buildOffset   = $this->select_buildOffset( $args );

			$sql = trim(<<<SQL
				SELECT {$buildDistinct} {$buildList} FROM "{$this->tblnam}" {$buildWhere} {$buildOrderBy} {$buildLimit} {$buildOffset};
			SQL);

			$fnResult = [];

			$this->consume_query( $sql, $this->selectBindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$len = strlen( static::TYPE_ALIAS );

				while( ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) !== false ) {

					$record = [];

					foreach( $row as $columnName => $columnValue ) {

						$attributeKey = 'value';

						if( str_starts_with( $columnName, static::TYPE_ALIAS ) ) {

							$attributeKey = 'type';
							$columnName   = substr( $columnName, $len );
						}

						$record[$columnName][$attributeKey] = $columnValue;
					}

					foreach( $record as $columnName => $attribute ) {

						// _slug skips the type mapping: always SQLite3 TEXT -> PHP string (the only TEXT column with a plain string, no JSON).
						if( $columnName === '_slug' ) {

							$record[$columnName] = strval( $attribute['value'] );
						}

						// All other columns (user columns, _id, _created_at, _updated_at) use the type mapping SQLite3 -> PHP by the storage class of typeof(), other storage classes throw.
						else {

							if( $attribute['type'] === 'null' ) {         // typemapping: SQLite3 NULL -> PHP null

								$record[$columnName] = null;
							}
							else if( $attribute['type'] === 'blob' ) {    // typemapping: SQLite3 BLOB -> PHP string

								$record[$columnName] = strval( $attribute['value'] );
							}
							else if( $attribute['type'] === 'real' ) {    // typemapping: SQLite3 REAL -> PHP float

								$record[$columnName] = floatval( $attribute['value'] );
							}
							else if( $attribute['type'] === 'integer' ) { // typemapping: SQLite3 INTEGER -> PHP int

								$record[$columnName] = intval( $attribute['value'] );
							}
							else if( $attribute['type'] === 'text' ) {    // typemapping: SQLite3 TEXT -> PHP array (JSON of a user column)

								$record[$columnName] = self::jsonDecode( strval( $attribute['value'] ) );
							}
							else self::throw_unsupported_data_type();
						}
					}

					$fnResult[] = $record;
				}
			});

			/** @var array */
			return $fnResult;
		}
	}





	// Helper methods exclusively used within the select() method for building simple SELECT query clauses (non-complex components of a SELECT statement).
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_select__parts {


		// Helper to build the DISTINCT keyword for the SELECT statement.
		// Removes duplicate rows from the result set.
		// Example usage: $db->select( ['distinct' => true] )
		private function select_buildDistinct( array $args ) : string {

			/** @var string */
			return !empty( $args['distinct'] ) ? 'DISTINCT' : '';
		}



		// Helper to build the SELECT list for the SELECT statement.
		// Returns all table columns if no column list is provided, otherwise only the specified columns.
		// Generates a SELECT list that includes each column and its SQLite3 runtime type using typeof().
		// Example usage: $db->select( ['columns' => ['col1', 'col2']] )
		private function select_buildList( array $args ) : string {

			$columnParts = [];
			$columnNames = ( isset( $args['columns'] ) && is_array( $args['columns'] ) ) ? $args['columns'] : [];

			if( empty( $columnNames ) ) {

				$sql = trim(<<<SQL
					PRAGMA table_info("{$this->tblnam}");
				SQL);

				// Every SQLite3 error (querying and reading the rows) is passed on as a RuntimeException.
				try {

					if( ( $result = $this->sqlite->query( $sql ) ) !== false ) {

						try {

							while( ( $col = $result->fetchArray( SQLITE3_ASSOC ) ) !== false ) {

								if( isset( $col['name'] ) ) {

									$columnNames[] = $col['name'];
								}
							}
						}
						finally {

							$result->finalize();
						}
					}
				}
				catch( SQLite3Exception $e ) {

					self::throw_rethrow_sqlite_error( $e );
				}
			}

			foreach( $columnNames as $columnName ) {

				if( !in_array( $columnName, array_keys( $this->selectReserved ), true ) ) {

					self::validate_sqlite_name_guard_clause( $columnName );
				}

				// Value and SQLite3 type of the column, both with the fully qualified name ("table"."column").
				// typeof() returns null, integer, real, text or blob, select() derives the PHP type from it.
				$columnParts[] = '"' . $this->tblnam . '"."' . $columnName . '" AS "' . $columnName . '"';
				$columnParts[] = 'typeof("' . $this->tblnam . '"."' . $columnName . '") AS "' . static::TYPE_ALIAS . $columnName . '"';
			}

			/** @var string */
			return implode( ', ', $columnParts );
		}



		// Helper to build the ORDER BY list for the SELECT statement.
		// Defines the result ordering based on one or more columns with ASC or DESC direction.
		// Example usage: $db->select( ['orderby' => ['col1' => 'desc']] )
		private function select_buildOrderBy( array $args ) : string {

			$sortParts = [];
			$sortRules = ( isset( $args['orderby'] ) && is_array( $args['orderby'] ) ) ? $args['orderby'] : [];

			foreach( $sortRules as $columnName => $direction ) {

				if( !in_array( $columnName, array_keys( $this->selectReserved ), true ) ) {

					self::validate_sqlite_name_guard_clause( $columnName );
				}

				$sortParts[] = '"' . $this->tblnam . '"."' . $columnName . '" ' . ( strtoupper( strval( $direction ) ) === 'DESC' ? 'DESC' : 'ASC' );
			}

			/** @var string */
			return ( !empty( $sortParts ) ? 'ORDER BY ' . implode( ', ', $sortParts ) : '' );
		}



		// Helper to build the LIMIT clause for the SELECT statement.
		// Restricts the number of returned rows. Without a valid limit 'LIMIT -1' (no limit) is used, because SQLite3 accepts OFFSET only after LIMIT.
		// Example usage: $db->select( ['limit' => 10] )
		private function select_buildLimit( array $args ) : string {

			/** @var string */
			return ( isset( $args['limit'] ) && is_int( $args['limit'] ) ) ? 'LIMIT ' . max( 0, $args['limit'] ) : 'LIMIT -1';
		}



		// Helper to build the OFFSET clause for the SELECT statement.
		// Defines the number of rows to skip before returning results.
		// Example usage: $db->select( ['offset' => 10] )
		private function select_buildOffset( array $args ) : string {

			/** @var string */
			return ( isset( $args['offset'] ) && is_int( $args['offset'] ) ) ? 'OFFSET ' . max( 0, $args['offset'] ) : '';
		}
	}





	// Helper methods exclusively used within the select() method for building complex WHERE expressions (logical trees and filter conditions).
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_select__where {


		// Helper to build the WHERE clause for the SELECT statement from a structured condition tree.
		// The tree is evaluated by the Logical Tree Node Renderer (AND, OR, NOT) and the Leaf Node Renderer (single comparisons), each in its own helper method.
		// Example usage: $db->select( ['where' => [...]] )
		private function select_buildWhere( array $args ) : string {

			$fnResult = '';

			if( isset( $args['where'] ) && is_array( $args['where'] ) ) {

				if( !empty( $result = $this->select_buildWhere__renderTreeNode( $args['where'] ) ) ) {

					$fnResult = 'WHERE ' . $result;
				}
			}

			/** @var string */
			return $fnResult;
		}



		// Helper for the WHERE clause Builder - Logical Tree Node Renderer.
		// Processes a hierarchical expression tree of logical operators (AND / OR as n-ary operators, NOT as unary operator).
		// Leaf nodes are delegated to the Leaf Node Renderer for atomic condition evaluation.
		private function select_buildWhere__renderTreeNode( array $node ) : string {

			$fnResult = '';

			if( isset( $node['AND'] ) && is_array( $node['AND'] ) ) {

				$fnResult = '(' . implode( ' AND ', array_filter( array_map( [$this, 'select_buildWhere__renderTreeNode'], $node['AND'] ) ) ) . ')';
			}
			else if( isset( $node['OR'] ) && is_array( $node['OR'] ) ) {

				$fnResult = '(' . implode( ' OR ', array_filter( array_map( [$this, 'select_buildWhere__renderTreeNode'], $node['OR'] ) ) ) . ')';
			}
			else if( isset( $node['NOT'] ) && is_array( $node['NOT'] ) ) {

				$fnResult = 'NOT (' . $this->select_buildWhere__renderTreeNode( $node['NOT'] ) . ')';
			}
			else {

				$fnResult = $this->select_buildWhere__renderLeafNode( $node );
			}

			/** @var string */
			return $fnResult;
		}



		// Helper for the WHERE clause Builder - Leaf Node Renderer.
		// Renders a single condition: a column, an operator and its operand(s).
		// An invalid node (e.g. an unknown operator, a missing or null value, or an empty IN list) throws a RuntimeException.
		// The values get the binding prefix of their column from $this->selectReserved, otherwise :row_any (see consume_query()).
		private function select_buildWhere__renderLeafNode( array $node ) : string {

			$fnResult = '';

			// Structural validation: all leaf nodes require a column identifier and an operator.
			if( isset( $node['column'], $node['op'] ) && is_string( $node['column'] ) && is_string( $node['op'] ) ) {

				if( !in_array( $node['column'], array_keys( $this->selectReserved ), true ) ) {

					self::validate_sqlite_name_guard_clause( $node['column'] );
				}

				// Register all specialized leaf predicate renderers.
				// The renderers are evaluated sequentially until the first non-empty SQL fragment is returned.
				$predicates = [
					'select_buildWhere__renderLeafNode__binaryPredicate',
					'select_buildWhere__renderLeafNode__membershipPredicate',
					'select_buildWhere__renderLeafNode__rangePredicate',
					'select_buildWhere__renderLeafNode__nullPredicate',
				];

				foreach( $predicates as $predicate ) {

					if( !empty( $result = $this->$predicate( $node ) ) ) {

						$fnResult = $result;
						break;
					}
				}
			}

			if( empty( $fnResult ) ) {

				self::throw_where_all_predicate_empty();
			}

			/** @var string */
			return $fnResult;
		}



		// Helper for the WHERE clause Builder - Leaf Node Renderer - Binary predicate - SQL: <column> OP <value>.
		// Example usage: $db->select( ['where' => ['column' => 'col1', 'op' => '=', 'value' => 'val1']] )
		private function select_buildWhere__renderLeafNode__binaryPredicate( array $node ) : string {

			$fnResult = '';

			if( in_array( $node['op'], ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'GLOB'], true ) && isset( $node['value'] ) ) {

				$token = $this->get_unique_token( $this->selectReserved[$node['column']] ?? ':row_any' );
				$this->selectBindings[$token] = $node['value'];

				$fnResult = '"' . $this->tblnam . '"."' . $node['column'] . '" ' . $node['op'] . ' ' . $token;
			}

			/** @var string */
			return $fnResult;
		}



		// Helper for the WHERE clause Builder - Leaf Node Renderer - Membership predicate - SQL: <column> IN (<value list>).
		// Example usage: $db->select( ['where' => ['column' => 'col1', 'op' => 'IN', 'values' => ['val1', 'val2']]] )
		private function select_buildWhere__renderLeafNode__membershipPredicate( array $node ) : string {

			$fnResult = '';

			if( $node['op'] === 'IN' && isset( $node['values'] ) && is_array( $node['values'] ) ) {

				$in = [];

				foreach( $node['values'] as $value ) {

					$token = $this->get_unique_token( $this->selectReserved[$node['column']] ?? ':row_any' );
					$this->selectBindings[$token] = $value;
					$in[] = $token;
				}

				if( !empty( $in ) ) {

					$fnResult = '"' . $this->tblnam . '"."' . $node['column'] . '" IN (' . implode( ', ', $in ) . ')';
				}
			}

			/** @var string */
			return $fnResult;
		}



		// Helper for the WHERE clause Builder - Leaf Node Renderer - Range predicate - SQL: <column> BETWEEN <min> AND <max>.
		// Example usage: $db->select( ['where' => ['column' => 'col1', 'op' => 'BETWEEN', 'min' => 10, 'max' => 20]] )
		private function select_buildWhere__renderLeafNode__rangePredicate( array $node ) : string {

			$fnResult = '';

			if( $node['op'] === 'BETWEEN' && isset( $node['min'], $node['max'] ) && is_scalar( $node['min'] ) && is_scalar( $node['max'] ) ) {

				$min = $this->get_unique_token( $this->selectReserved[$node['column']] ?? ':row_any' );
				$this->selectBindings[$min] = $node['min'];

				$max = $this->get_unique_token( $this->selectReserved[$node['column']] ?? ':row_any' );
				$this->selectBindings[$max] = $node['max'];

				$fnResult = '"' . $this->tblnam . '"."' . $node['column'] . '" BETWEEN ' . $min . ' AND ' . $max;
			}

			/** @var string */
			return $fnResult;
		}



		// Helper for the WHERE clause Builder - Leaf Node Renderer - Null predicate - SQL: <column> IS NULL.
		// Example usage: $db->select( ['where' => ['column' => 'col1', 'op' => 'IS NULL']] )
		private function select_buildWhere__renderLeafNode__nullPredicate( array $node ) : string {

			$fnResult = '';

			if( $node['op'] === 'IS NULL' ) {

				$fnResult = '"' . $this->tblnam . '"."' . $node['column'] . '" IS NULL';
			}

			/** @var string */
			return $fnResult;
		}
	}


?>