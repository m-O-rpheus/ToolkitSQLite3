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


		use ToolkitSQLite3_methods_errors;
		use ToolkitSQLite3_methods_table;
		use ToolkitSQLite3_methods_column;
		use ToolkitSQLite3_methods_row;
		use ToolkitSQLite3_methods_select;
		use ToolkitSQLite3_methods_select__parts;
		use ToolkitSQLite3_methods_select__where;



		protected static $tblprefix = 'toolkitsqlite3_'; // Prefix used to identify the context, prepended to table names.
		protected static $typealias = '_typeof_';        // Temporary prefix for generated SELECT alias columns that store SQLite3 value types.
		protected static $maxlength = 512;               // Maximum length for table and column names (excluding the prefix).



		private readonly SQLite3 $sqlite;
		private readonly string  $tblnam;

		public function __construct( string $fileName, string $tableName ) {

			self::error_if_invalid_sqlite_name( $tableName );

			$this->sqlite = new SQLite3( $fileName );
			$this->tblnam = static::$tblprefix . $tableName;
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
		// The following PHP data types are possible: null, string, float, int, resource.
		// The callback is executed only if all bindings were successfully applied.
		private function consume_query( string $sql, array $bindings, callable $callback ) : void {

			// Prepare the SQLite3 statement.
			if( ( $stmt = $this->sqlite->prepare( $sql ) ) !== false ) {

				$comparison = [];

				foreach( $bindings as $paramName => $paramValue ) {

					if( is_string( $paramName ) ) {

						// paramName:  string
						// paramValue: mixed  (null, string, float, int, resource)
						$validType = false;

						if( is_null( $paramValue ) ) {

							$paramType = SQLITE3_NULL;
							$validType = true;
						}
						else if( is_string( $paramValue ) ) {

							$paramType = SQLITE3_TEXT;
							$validType = true;
						}
						else if( is_float( $paramValue ) ) {

							$paramType = SQLITE3_FLOAT;
							$validType = true;
						}
						else if( is_int( $paramValue ) ) {

							$paramType = SQLITE3_INTEGER;
							$validType = true;
						}
						else if( is_resource( $paramValue ) ) {

							if( ( $paramValue = stream_get_contents( $paramValue ) ) !== false ) {

								$paramType = SQLITE3_BLOB;
								$validType = true;
							}
						}

						// If the affinity type has been correctly mapped, the value is bound to the prepared statement.
						if( $validType ) {

							if( ( $stmt->bindValue( $paramName, $paramValue, $paramType ) ) !== false ) {

								$comparison[] = $paramName;
							}
						}
					}
				}

				// Execute the statement only if all values were bound successfully.
				if( array_keys( $bindings ) === $comparison ) {

					if( ( $result = $stmt->execute() ) !== false ) {

						try {

							/** @param callable(SQLite3Result) $callback */
							$callback( $result );
						}
						finally {

							$result->finalize();
						}
					}
				}
			}
		}
	}





	// Shared error handling and validation helpers.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_errors {


		// Error handler: validates a SQLite3 identifier (table or column name). Ensures the name matches format and length constraints.
		private static function error_if_invalid_sqlite_name( string $name ) : void {

			if( strlen( $name ) > static::$maxlength || preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*$/', $name ) !== 1 ) {

				throw new InvalidArgumentException( 'ERROR: Invalid SQLite3 table or column name. It must start with a letter and contain only letters, digits, or underscores, and be at most ' . static::$maxlength . ' characters long.' );
			}
		}



		// Error handler: ensures that the SQLite3 row slug is not empty.
		private static function error_if_empty_sqlite_slug( string $slug ) : void {

			if( strlen( $slug ) === 0 ) {

				throw new InvalidArgumentException( 'ERROR: The row slug in SQLite3 must not be empty.' );
			}
		}



		// Error handler: ensures that the generated WHERE clause contains at least one valid predicate expression.
		private static function error_if_where_all_predicate_empty( string $where ) : void {

			if( empty( $where ) ) {

				throw new InvalidArgumentException( 'ERROR: Failed to generate the SQLite3 WHERE clause. No valid predicate expression could be resolved from the provided WHERE definition.' );
			}
		}
	}





	// Instance Table-level operations for SQLite3 tables.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_table {


		// Checks whether the table exists in the database.
		// Returns true if the table exists, otherwise false.
		public function table_exists() : bool {

			$bindings = [':table' => $this->tblnam];

			$sql = <<<SQL
				SELECT 1 FROM sqlite_master WHERE type='table' AND name=:table LIMIT 1;
			SQL;

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = $result->fetchArray( SQLITE3_NUM ) !== false;
			});

			/** @var bool */
			return $fnResult;
		}



		// Creates the table if it does not exist.
		// Returns true if the query was executed successfully (it can be assumed that the table exists afterwards), otherwise false on query error.
		public function table_add_ignore() : bool {

			$sql = <<<SQL
				CREATE TABLE IF NOT EXISTS "{$this->tblnam}" (
					_id INTEGER PRIMARY KEY AUTOINCREMENT,
					_slug TEXT NOT NULL UNIQUE,
					_created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
					_updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
				) STRICT;
			SQL;

			/** @var bool */
			return $this->sqlite->exec( $sql ) !== false;
		}



		// Drops the table if it exists.
		// Returns true if the query was executed successfully (it can be assumed that the table does not exist afterwards), otherwise false on query error.
		public function table_delete_ignore() : bool {

			$sql = <<<SQL
				DROP TABLE IF EXISTS "{$this->tblnam}";
			SQL;

			/** @var bool */
			return $this->sqlite->exec( $sql ) !== false;
		}
	}





	// Instance Column-level operations for SQLite3 tables.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_column {


		// Checks whether a column exists in the current table.
		// Returns true if the column exists, otherwise false.
		public function column_exists( string $columnName ) : bool {

			self::error_if_invalid_sqlite_name( $columnName );

			$bindings = [':column' => $columnName];

			$sql = <<<SQL
				SELECT 1 FROM pragma_table_info("$this->tblnam") WHERE name=:column LIMIT 1;
			SQL;

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = $result->fetchArray( SQLITE3_NUM ) !== false;
			});

			/** @var bool */
			return $fnResult;
		}



		// Adds columns to the table if they do not already exist.
		// Returns true if the query was executed successfully (it can be assumed that the columns were processed afterwards), otherwise false on query error.
		public function columns_add_ignore( string ...$columnNames ) : bool {

			$sql = '';

			foreach( $columnNames as $columnName ) {

				if( !$this->column_exists( $columnName ) ) {

					$sql .= <<<SQL
						ALTER TABLE "{$this->tblnam}" ADD COLUMN "{$columnName}" ANY;
					SQL;
				}
			}

			/** @var bool */
			return empty( $sql ) ? true : ( $this->sqlite->exec( $sql ) !== false );
		}



		// Removes columns from the table if they exist.
		// Returns true if the query was executed successfully (it can be assumed that the columns were processed afterwards), otherwise false on query error.
		public function columns_delete_ignore( string ...$columnNames ) : bool {

			$sql = '';

			foreach( $columnNames as $columnName ) {

				if( $this->column_exists( $columnName ) ) {

					$sql .= <<<SQL
						ALTER TABLE "{$this->tblnam}" DROP COLUMN "{$columnName}";
					SQL;
				}
			}

			/** @var bool */
			return empty( $sql ) ? true : ( $this->sqlite->exec( $sql ) !== false );
		}
	}





	// Instance Row-level operations for SQLite3 tables.
	// -----------------------------------------------------------------------------------------------------------------------------

	trait ToolkitSQLite3_methods_row {


		// Checks if a row exists in the table using its slug value.
		// Returns true if the row exists, otherwise false.
		public function row_isset( string $rowSlug ) : bool {

			self::error_if_empty_sqlite_slug( $rowSlug );

			$bindings = [':slug' => $rowSlug];

			$sql = <<<SQL
				SELECT 1 FROM "{$this->tblnam}" WHERE _slug=:slug LIMIT 1;
			SQL;

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = $result->fetchArray( SQLITE3_NUM ) !== false;
			});

			/** @var bool */
			return $fnResult;
		}



		// Inserts a new row or updates an existing row based on the slug value.
		// All values are safely bound using prepared statements and column names are validated at runtime.
		// Returns true if the query executed successfully (insert or update), false on query error.
		public function row_upsert( string $rowSlug, array $columnNameValuePair ) : bool {

			self::error_if_empty_sqlite_slug( $rowSlug );

			$columns  = ['_slug'];
			$params   = [':slug'];
			$clauses  = ['_updated_at=CURRENT_TIMESTAMP'];
			$bindings = [':slug' => $rowSlug];

			foreach( $columnNameValuePair as $columnName => $columnValue ) {

				self::error_if_invalid_sqlite_name( $columnName );

				$token = $this->get_unique_token();

				$columns[]        = $columnName;
				$params[]         = $token;
				$clauses[]        = $columnName . '=' . $token;
				$bindings[$token] = $columnValue;
			}

			$columns = implode( ', ', $columns );
			$params  = implode( ', ', $params );
			$clauses = implode( ', ', $clauses );

			$sql = <<<SQL
				INSERT INTO "{$this->tblnam}" ({$columns}) VALUES ({$params}) ON CONFLICT(_slug) DO UPDATE SET {$clauses};
			SQL;

			$fnResult = false;

			$this->consume_query( $sql, $bindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$fnResult = true;
			});

			/** @var bool */
			return $fnResult;
		}



		// Removes a row from the table using its slug value.
		// Returns true if a row was deleted, or false if no matching row existed or the deletion failed.
		public function row_remove( string $rowSlug ) : bool {

			self::error_if_empty_sqlite_slug( $rowSlug );

			$bindings = [':slug' => $rowSlug];

			$sql = <<<SQL
				DELETE FROM "{$this->tblnam}" WHERE _slug=:slug;
			SQL;

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

			$sql = <<<SQL
				SELECT {$buildDistinct} {$buildList} FROM "{$this->tblnam}" {$buildWhere} {$buildOrderBy} {$buildLimit} {$buildOffset};
			SQL;

			$fnResult = [];

			$this->consume_query( $sql, $this->selectBindings, function( SQLite3Result $result ) use ( &$fnResult ) : void {

				$len = strlen( static::$typealias );

				while( ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) !== false ) {

					$record = [];

					foreach( $row as $columnName => $columnValue ) {

						$attributeKey = 'value';

						if( str_starts_with( $columnName, static::$typealias ) ) {

							$attributeKey = 'type';
							$columnName   = substr( $columnName, $len );
						}

						$record[$columnName][$attributeKey] = $columnValue;
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
		// Example usage: $db->select([ 'distinct' => true ])
		private function select_buildDistinct( array $args ) : string {

			/** @var string */
			return !empty( $args['distinct'] ) ? 'DISTINCT' : '';
		}



		// Helper to build the SELECT list for the SELECT statement.
		// Returns all table columns if no column list is provided, otherwise only the specified columns.
		// Generates a SELECT list that includes each column and its SQLite3 runtime type using typeof().
		// Example usage: $db->select([ 'columns' => array( 'col1', 'col2' ) ])
		private function select_buildList( array $args ) : string {

			$columnParts = [];
			$columnNames = ( isset( $args['columns'] ) && is_array( $args['columns'] ) ) ? $args['columns'] : [];

			if( empty( $columnNames ) ) {

				$sql = <<<SQL
					PRAGMA table_info("{$this->tblnam}");
				SQL;

				if( ( $result = $this->sqlite->query( $sql ) ) !== false ) {

					while( ( $col = $result->fetchArray( SQLITE3_ASSOC ) ) !== false ) {

						if( isset( $col['name'] ) ) {

							$columnNames[] = $col['name'];
						}
					}

					$result->finalize();
				}
			}

			foreach( $columnNames as $columnName ) {

				if( !in_array( $columnName, $this->selectReserved, true ) ) {

					self::error_if_invalid_sqlite_name( $columnName );
				}

				$columnParts[] = $columnName;
				$columnParts[] = 'typeof(' . $columnName . ') AS ' . static::$typealias . $columnName;
			}

			/** @var string */
			return implode( ', ', $columnParts );
		}



		// Helper to build the ORDER BY list for the SELECT statement.
		// Defines the result ordering based on one or more columns with ASC or DESC direction.
		// Example usage: $db->select([ 'orderby' => array( 'col1' => 'desc' ) ])
		private function select_buildOrderBy( array $args ) : string {

			$sortParts = [];
			$sortRules = ( isset( $args['orderby'] ) && is_array( $args['orderby'] ) ) ? $args['orderby'] : [];

			foreach( $sortRules as $columnName => $direction ) {

				if( !in_array( $columnName, $this->selectReserved, true ) ) {

					self::error_if_invalid_sqlite_name( $columnName );
				}

				$sortParts[] = $columnName . ' ' . ( strtoupper( strval( $direction ) ) === 'DESC' ? 'DESC' : 'ASC' );
			}

			/** @var string */
			return ( !empty( $sortParts ) ? 'ORDER BY ' . implode( ', ', $sortParts ) : '' );
		}



		// Helper to build the LIMIT clause for the SELECT statement.
		// Restricts the maximum number of rows returned by the query.
		// Example usage: $db->select([ 'limit' => 10 ])
		private function select_buildLimit( array $args ) : string {

			/** @var string */
			return ( isset( $args['limit'] ) && is_int( $args['limit'] ) ) ? 'LIMIT ' . max( 0, $args['limit'] ) : '';
		}



		// Helper to build the OFFSET clause for the SELECT statement.
		// Defines the number of rows to skip before returning results.
		// Example usage: $db->select([ 'offset' => 10 ])
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
		// Example usage: $db->select([ 'where' => array( ... ) ])
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

			if( array_key_exists( 'AND', $node ) && is_array( $node['AND'] ) ) {

				$fnResult = '(' . implode( ' AND ', array_filter( array_map( [$this, 'select_buildWhere__renderTreeNode'], $node['AND'] ) ) ) . ')';
			}
			else if( array_key_exists( 'OR', $node ) && is_array( $node['OR'] ) ) {

				$fnResult = '(' . implode( ' OR ', array_filter( array_map( [$this, 'select_buildWhere__renderTreeNode'], $node['OR'] ) ) ) . ')';
			}
			else if( array_key_exists( 'NOT', $node ) && is_array( $node['NOT'] ) ) {

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

					self::error_if_invalid_sqlite_name( $node['column'] );
				}

				// Register all specialized leaf predicate renderers.
				// The renderers are evaluated sequentially until the first non-empty SQL fragment is returned.
				$predicates = array(
					'select_buildWhere__renderLeafNode__binaryPredicate',
					'select_buildWhere__renderLeafNode__membershipPredicate',
					'select_buildWhere__renderLeafNode__rangePredicate',
					'select_buildWhere__renderLeafNode__nullPredicate',
				);

				foreach( $predicates as $predicate ) {

					if( !empty( $result = $this->$predicate( $node ) ) ) {

						$fnResult = $result;
						break;
					}
				}
			}

			self::error_if_where_all_predicate_empty( $fnResult );

			/** @var string */
			return $fnResult;
		}



		// Helper for the WHERE clause Builder - Leaf Node Renderer - Binary predicate - SQL: <column> OP <value>.
		// Example usage: $db->select([ 'where' => array( 'column' => 'col1', 'op' => '=', 'value' => 'val1' ) ])
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
		// Example usage: $db->select([ 'where' => array( 'column' => 'col1', 'op' => 'IN', 'values' => array( 'val1', 'val2' ) ) ])
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
		// Example usage: $db->select([ 'where' => array( 'column' => 'col1', 'op' => 'BETWEEN', 'min' => 10, 'max' => 20 ) ])
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
		// Example usage: $db->select([ 'where' => array( 'column' => 'col1', 'op' => 'IS NULL' ) ])
		private function select_buildWhere__renderLeafNode__nullPredicate( array $node ) : string {

			$fnResult = '';

			if( $node['op'] === 'IS NULL' ) {

				$fnResult = $node['column'] . ' IS NULL';
			}

			/** @var string */
			return $fnResult;
		}
	}


	// TODO: Review README.md documentation.
	// TODO: Check for possible race conditions.
	// TODO: Review and refine code comments.
	// TODO: Consider extracting runtime type reconversion into a dedicated trait.


?>