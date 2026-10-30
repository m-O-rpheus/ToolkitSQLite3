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

		protected const TABLE_PREFIX = 'toolkitsqlite3_';               // Prefix used to identify the context and is prepended to table names.
		protected const TYPE_ALIAS   = '_typeof_';                      // Prefix for SELECT alias columns used to temporarily store SQLite3 value types.
		protected const JSON_ALIAS   = '@@<#~TOOLKITSQLITE3~JSON~#>@@'; // Prefix used to mark JSON blobs.
		protected const MAX_LENGTH   = 512;                             // Maximum length for table and column names (excluding the prefix).
		protected const BUSY_TIMEOUT = 10000;                           // Milliseconds to wait for a lock of another process before "database is locked" (0 = fail immediately).
		protected const JOURNAL_MODE = 'WAL';                           // SQLite3 journal mode (WAL: readers and writers do not block each other, stored in the database file).





		// Private members and helper methods used internally for class functionality.
		// -----------------------------------------------------------------------------------------------------------------------------

		private readonly SQLite3 $sqlite;
		private readonly string  $tblnam;

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

		public function __destruct() {

			$this->sqlite->close();
		}



		// Generates a unique SQL parameter token (e.g. :bind1, :bind2, ...).
		// Used only to ensure unique binding names within the instance.
		private int $i = 0;

		private function get_unique_token() : string {

			$this->i++;

			/** @var string */
			return ':bind' . $this->i;
		}



		// Executes a prepared SQLite3 statement. The method accepts an SQL string with named placeholders as well as an associative array of parameter names mapped to values.
		// Since columns are not strictly typed (using flexible storage types), the actual SQLite3 affinity is derived from the PHP data type.
		// The following PHP data types are possible: null, string, float, int, array, resource.
		// The callback is executed only if all bindings were successfully applied.
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
							// paramValue: mixed  (null, string, float, int, array, resource)
							if( is_null( $paramValue ) ) {

								$paramType = SQLITE3_NULL;
							}
							else if( is_string( $paramValue ) ) {

								$paramType = SQLITE3_TEXT;
							}
							else if( is_float( $paramValue ) ) {

								$paramType = SQLITE3_FLOAT;
							}
							else if( is_int( $paramValue ) ) {

								$paramType = SQLITE3_INTEGER;
							}
							else if( is_array( $paramValue ) ) {

								if( ( $paramValue = self::tagged_json_encode( $paramValue ) ) !== false ) {

									$paramType = SQLITE3_BLOB;
								}
								else self::throw_unsupported_data_type();
							}
							else if( is_resource( $paramValue ) ) {

								if( ( $paramValue = self::resource_to_string( $paramValue ) ) !== false ) {

									$paramType = SQLITE3_BLOB;
								}
								else self::throw_unsupported_data_type();
							}
							else self::throw_unsupported_data_type();

							if( ( $stmt->bindValue( $paramName, $paramValue, $paramType ) ) !== false ) {

								$comparison[] = $paramName;
							}
						}
					}

					// Execute the statement only if all passed values were bound successfully and the SQL contains no further placeholders (an unbound placeholder would be NULL silently).
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





		// Internal validation methods using exception handling.
		// -----------------------------------------------------------------------------------------------------------------------------

		private static function validate_sqlite_name_guard_clause( string $name ) : void {

			if( strlen( $name ) > static::MAX_LENGTH || preg_match( '/^[a-z][a-z0-9_]*$/', $name ) !== 1 ) {

				self::throw_invalid_sqlite_name();
			}
		}


		private static function validate_row_slug_guard_clause( string $slug ) : void {

			if( strlen( $slug ) === 0 ) {

				self::throw_empty_row_slug();
			}
		}
	}





	// Conversion helpers between PHP strings, stream resources and tagged JSON blobs.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_convert {


		// Convert helper, stream resource to string.
		final public static function resource_to_string( mixed $data ) : mixed {

			$fnResult = false;

			if( is_resource( $data ) ) {

				rewind( $data );

				if( ( $data = stream_get_contents( $data ) ) !== false ) {

					$fnResult = $data;
				}
			}

			/** @var string|false */
			return $fnResult;
		}



		// Convert helper, string to stream resource.
		final public static function string_to_resource( mixed $data ) : mixed {

			$fnResult = false;

			if( is_string( $data ) ) {

				if( ( $stream = fopen( 'php://temp', 'r+' ) ) !== false ) {

					if( fwrite( $stream, $data ) !== false ) {

						rewind( $stream );
						$fnResult = $stream;
					}
				}
			}

			/** @var resource|false */
			return $fnResult;
		}



		// Convert helper, array to tagged JSON blob.
		private static function tagged_json_encode( array $data ) : string|false {

			$fnResult = false;

			if( ( $data = json_encode( $data ) ) !== false ) {

				$fnResult = static::JSON_ALIAS . $data;
			}

			/** @var string|false */
			return $fnResult;
		}



		// Convert helper, tagged JSON blob to array.
		private static function tagged_json_decode( string $data ) : array|false {

			$fnResult = false;

			if( str_starts_with( $data, static::JSON_ALIAS ) ) {

				if( is_array( $data = json_decode( substr( $data, strlen( static::JSON_ALIAS ) ), true ) ) ) {

					$fnResult = $data;
				}
			}

			/** @var array|false */
			return $fnResult;
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

			throw new RuntimeException( 'ToolkitSQLite3: Unsupported data type. Allowed PHP <> SQLite3 runtime types are: null, string, float, int, array, resource.' );
		}


		private static function throw_statement_binding_failed() : never {

			throw new RuntimeException( 'ToolkitSQLite3: Binding of values for the prepared statement failed due to an unexpected error.' );
		}


		private static function throw_rethrow_sqlite_error( Exception $e ) : never {

			throw new RuntimeException( 'ToolkitSQLite3: (SQLite3 error) ' . $e->getMessage(), 0, $e );
		}
	}





	// Instance Table-level operations for SQLite3 tables.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_table {


		// Checks whether the table exists in the database.
		// Returns true if the table exists, otherwise false. A failing query throws a RuntimeException.
		public function table_exists() : bool {

			$bindings = [':table' => $this->tblnam];

			$sql = trim(<<<SQL
				SELECT 1 FROM sqlite_master WHERE type='table' AND name=:table LIMIT 1;
			SQL);

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = $result->fetchArray( SQLITE3_NUM ) !== false;
			});

			/** @var bool */
			return $fnResult;
		}



		// Creates the table if it does not exist.
		// Returns true, the table exists afterwards. Every SQLite3 error throws a RuntimeException.
		public function table_add_ignore() : bool {

			$sql = trim(<<<SQL
				CREATE TABLE IF NOT EXISTS "{$this->tblnam}" (
					_id INTEGER PRIMARY KEY AUTOINCREMENT,
					_slug TEXT NOT NULL UNIQUE,
					_created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
					_updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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

			$bindings = [':column' => $columnName];

			$sql = trim(<<<SQL
				SELECT 1 FROM pragma_table_info("$this->tblnam") WHERE name=:column LIMIT 1;
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

			$bindings = [':slug' => $rowSlug];

			$sql = trim(<<<SQL
				SELECT 1 FROM "{$this->tblnam}" WHERE _slug=:slug LIMIT 1;
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
		// Returns true if the query executed successfully (insert or update). A failing query throws a RuntimeException.
		public function row_upsert( string $rowSlug, array $columnNameValuePair ) : bool {

			self::validate_row_slug_guard_clause( $rowSlug );

			$columns  = ['_slug'];
			$params   = [':slug'];
			$clauses  = ['_updated_at=CURRENT_TIMESTAMP'];
			$bindings = [':slug' => $rowSlug];

			foreach( $columnNameValuePair as $columnName => $columnValue ) {

				self::validate_sqlite_name_guard_clause( $columnName );

				$token = $this->get_unique_token();

				$columns[]        = $columnName;
				$params[]         = $token;
				$clauses[]        = $columnName . '=' . $token;
				$bindings[$token] = $columnValue;
			}

			$columns = implode( ', ', $columns );
			$params  = implode( ', ', $params );
			$clauses = implode( ', ', $clauses );

			$sql = trim(<<<SQL
				INSERT INTO "{$this->tblnam}" ({$columns}) VALUES ({$params}) ON CONFLICT(_slug) DO UPDATE SET {$clauses};
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

			$bindings = [':slug' => $rowSlug];

			$sql = trim(<<<SQL
				DELETE FROM "{$this->tblnam}" WHERE _slug=:slug;
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

			$this->selectReserved = ['_id', '_slug', '_created_at', '_updated_at']; // Built-in column names excluded from validation.
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

						if( $attribute['type'] === 'null' ) {

							$record[$columnName] = null; // Null
						}
						else if( $attribute['type'] === 'text' ) {

							$record[$columnName] = strval( $attribute['value'] ); // String
						}
						else if( $attribute['type'] === 'real' ) {

							$record[$columnName] = floatval( $attribute['value'] ); // Float
						}
						else if( $attribute['type'] === 'integer' ) {

							$record[$columnName] = intval( $attribute['value'] ); // Integer
						}
						else if( $attribute['type'] === 'blob' ) {

							if( ( $val = self::tagged_json_decode( $attribute['value'] ) ) !== false ) {

								$record[$columnName] = $val; // Array
							}
							else if( ( $val = self::string_to_resource( $attribute['value'] ) ) !== false ) {

								$record[$columnName] = $val; // Resource
							}
							else self::throw_unsupported_data_type();
						}
						else self::throw_unsupported_data_type();
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

				if( !in_array( $columnName, $this->selectReserved, true ) ) {

					self::validate_sqlite_name_guard_clause( $columnName );
				}

				$columnParts[] = $columnName;
				$columnParts[] = 'typeof(' . $columnName . ') AS ' . static::TYPE_ALIAS . $columnName;
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

				if( !in_array( $columnName, $this->selectReserved, true ) ) {

					self::validate_sqlite_name_guard_clause( $columnName );
				}

				$sortParts[] = $columnName . ' ' . ( strtoupper( strval( $direction ) ) === 'DESC' ? 'DESC' : 'ASC' );
			}

			/** @var string */
			return ( !empty( $sortParts ) ? 'ORDER BY ' . implode( ', ', $sortParts ) : '' );
		}



		// Helper to build the LIMIT clause for the SELECT statement.
		// Restricts the maximum number of rows returned by the query. Without a valid limit, 'LIMIT -1' (no limit) is used, because SQLite3 only accepts OFFSET after LIMIT.
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


		// Helper to build the WHERE clause for the SELECT statement.
		// Entry point for building the WHERE clause from a structured condition tree.
		// The expression tree is evaluated by a Logical Tree Node Renderer (for logical structure such as AND/OR/NOT) and a Leaf Node Renderer (for atomic comparisons such as column operators and value checks).
		// Due to this hierarchical complexity, the evaluation logic is separated into dedicated helper methods.
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
		// Processes atomic WHERE expressions representing a single column-based condition.
		// Each leaf node defines a basic predicate consisting of a column identifier, an operator, and the associated operand(s).
		private function select_buildWhere__renderLeafNode( array $node ) : string {

			$fnResult = '';

			// Structural validation: all leaf nodes require a column identifier and an operator.
			if( isset( $node['column'], $node['op'] ) && is_string( $node['column'] ) && is_string( $node['op'] ) ) {

				if( !in_array( $node['column'], $this->selectReserved, true ) ) {

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

				$token = $this->get_unique_token();
				$this->selectBindings[$token] = $node['value'];

				$fnResult = $node['column'] . ' ' . $node['op'] . ' ' . $token;
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

					$token = $this->get_unique_token();
					$this->selectBindings[$token] = $value;
					$in[] = $token;
				}

				if( !empty( $in ) ) {

					$fnResult = $node['column'] . ' IN (' . implode( ', ', $in ) . ')';
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

				$min = $this->get_unique_token();
				$this->selectBindings[$min] = $node['min'];

				$max = $this->get_unique_token();
				$this->selectBindings[$max] = $node['max'];

				$fnResult = $node['column'] . ' BETWEEN ' . $min . ' AND ' . $max;
			}

			/** @var string */
			return $fnResult;
		}



		// Helper for the WHERE clause Builder - Leaf Node Renderer - Null predicate - SQL: <column> IS NULL.
		// Example usage: $db->select( ['where' => ['column' => 'col1', 'op' => 'IS NULL']] )
		private function select_buildWhere__renderLeafNode__nullPredicate( array $node ) : string {

			$fnResult = '';

			if( $node['op'] === 'IS NULL' ) {

				$fnResult = $node['column'] . ' IS NULL';
			}

			/** @var string */
			return $fnResult;
		}
	}


?>