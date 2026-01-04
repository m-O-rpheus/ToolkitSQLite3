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


		// Constructor.
		// -----------------------------------------------------------------------------------------------------------------------------

		protected readonly SQLite3 $sqlite;
		protected readonly string  $tblnam;

		public function __construct( string $fileName, string $tableName ) {

			self::error_if_invalid_sqlite_name( $tableName );

			$this->sqlite = new SQLite3( $fileName );
			$this->tblnam = $tableName;
		}





		// Error Handlers.
		// -----------------------------------------------------------------------------------------------------------------------------

		private static function error_if_invalid_sqlite_name( string $name ) : void {

			if( preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*$/', $name ) !== 1 ) {

				trigger_error( 'ERROR: The table or column name in SQLite3 is invalid. It may contain only letters, numbers, or underscores.', E_USER_ERROR );
				exit();
			}
		}


		private static function error_if_invalid_sqlite_type( string $type ) : void {

			if( !in_array( $type, ['INTEGER', 'REAL', 'BLOB', 'TEXT'], true ) ) {

				trigger_error( 'ERROR: The SQLite3 column type is invalid. Allowed types are: INTEGER, REAL, BLOB, TEXT.', E_USER_ERROR );
				exit();
			}
		}


		private static function error_if_empty_sqlite_slug( string $slug ) : void {

			if( strlen( $slug ) === 0 ) {
				
				trigger_error( 'ERROR: The row slug in SQLite3 must not be empty.', E_USER_ERROR );
				exit();
			}
		}


		private static function error_if_sqlite_parameter_columns_missing( array $arrbefore, array $arrafter ) : void {

			if( count( $arrbefore ) !== count( $arrafter ) ) {

				trigger_error( 'ERROR: Invalid function parameter array for the SQLite3 operation. Not all specified columns exist in the database table. Please create all required columns first or adjust the function parameter array accordingly.', E_USER_ERROR );
				exit();
			}
		}





		// Instance Common Methods.
		// -----------------------------------------------------------------------------------------------------------------------------

		// Retrieves an array of column names and their types for the current table. Returns an empty array if the table does not exist or on query error.
		public function table_info_columns() : array {

			$sql = <<<SQL
				PRAGMA table_info(`{$this->tblnam}`);
			SQL;

			$fnResult = [];

			if( ( $result = $this->sqlite->query( $sql ) ) !== false ) {

				while( ( $columns = $result->fetchArray( SQLITE3_ASSOC ) ) !== false ) {

					if( isset( $columns['name'] ) && isset( $columns['type'] ) ) {

						$fnResult[$columns['name']] = $columns['type'];
					}
				}
			}

			/** @var array */
			return $fnResult;
		}





		// Instance Table Methods.
		// -----------------------------------------------------------------------------------------------------------------------------

		// Checks if the table exists by verifying whether column information can be retrieved. Returns true if the table exists, false otherwise.
		public function table_exists() : bool {

			/** @var bool */
			return !empty( $this->table_info_columns() );
		}


		// Creates a table if it does not already exist. Returns 1 if the table was created successfully, 2 if it already exists, or 0 on query error.
		public function table_add_ignore() : int {

			$sql = <<<SQL
				CREATE TABLE `{$this->tblnam}` (
					_id INTEGER PRIMARY KEY AUTOINCREMENT,
					_slug TEXT NOT NULL UNIQUE,
					_created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
					_updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
				);
			SQL;

			/** @var int */
			return $this->table_exists() ? 2 : ( $this->sqlite->exec( $sql ) !== false ? 1 : 0 );
		}


		// Drops the table if it exists. Returns 1 if the table was removed successfully, 2 if it did not exist, or 0 on query error.
		public function table_delete_ignore() : int {

			$sql = <<<SQL
				DROP TABLE `{$this->tblnam}`;
			SQL;

			/** @var int */
			return $this->table_exists() ? ( $this->sqlite->exec( $sql ) !== false ? 1 : 0 ) : 2;
		}





		// Instance Column Methods.
		// -----------------------------------------------------------------------------------------------------------------------------

		// Checks whether a column exists in the current table by verifying the column name in the table's column information. Returns true if the column exists, false otherwise.
		public function column_exists( string $columnName ) : bool {

			self::error_if_invalid_sqlite_name( $columnName );

			/** @var bool */
			return array_key_exists( $columnName, $this->table_info_columns() );
		}


		// Adds a column to the table if it does not exist. Returns 1 if the column was added successfully, 2 if it already exists, or 0 on query error.
		public function column_add_ignore( string $columnName, string $columnType ) : int {

			self::error_if_invalid_sqlite_name( $columnName );
			self::error_if_invalid_sqlite_type( $columnType );

			$sql = <<<SQL
				ALTER TABLE `{$this->tblnam}` ADD COLUMN `{$columnName}` {$columnType};
			SQL;

			/** @var int */
			return $this->column_exists( $columnName ) ? 2 : ( $this->sqlite->exec( $sql ) !== false ? 1 : 0 );
		}


		// Removes a column from the table if it exists. Returns 1 if the column was removed successfully, 2 if it did not exist, or 0 on query error.
		public function column_delete_ignore( string $columnName ) : int {

			self::error_if_invalid_sqlite_name( $columnName );

			$sql = <<<SQL
				ALTER TABLE `{$this->tblnam}` DROP COLUMN `{$columnName}`;
			SQL;

			/** @var int */
			return $this->column_exists( $columnName ) ? ( $this->sqlite->exec( $sql ) !== false ? 1 : 0 ) : 2;
		}





		// Instance Combination Methods.
		// -----------------------------------------------------------------------------------------------------------------------------

		// Creates a table and multiple columns in a single call. Existing tables or columns are ignored.
		// Returns true if the query executed successfully (table and all columns created or already existed), or false on query error.
		public function table_column_add_ignore( array $columnNameTypePair ) : bool {

			$comparison = [];

			if( $this->table_add_ignore() > 0 ) {

				foreach( $columnNameTypePair as $columnName => $columnType ) {

					if( $this->column_add_ignore( $columnName, $columnType ) > 0 ) {

						$comparison[$columnName] = $columnType;
					}
				}
			}

			/** @var bool */
			return $columnNameTypePair === $comparison;
		}





		// Instance Row Methods.
		// -----------------------------------------------------------------------------------------------------------------------------

		// Checks if a row exists in the table using its slug value. Returns true if the row exists, false otherwise.
		public function row_isset( string $rowSlug ) : bool {

			self::error_if_empty_sqlite_slug( $rowSlug );

			$sql = <<<SQL
				SELECT 1 FROM `{$this->tblnam}` WHERE _slug = :slug LIMIT 1;
			SQL;

			/** @var bool */
			return ( $result = $this->execute_statement( $sql, [['columnName'=> '_slug', 'bindMarker' => ':slug', 'origValue' => $rowSlug]] ) ) !== false && $result->fetchArray( SQLITE3_ASSOC ) !== false;
		}


		// Inserts a new row or updates an existing row based on the slug value. All values are safely bound using prepared statements with correct type mapping.
		// Returns true if the query executed successfully (row inserted or updated), or false on query error.
		public function row_upsert( string $rowSlug, array $columnNameValuePair ) : bool {

			self::error_if_empty_sqlite_slug( $rowSlug );

			$bindIndex  = 0;
			$bindValues = [];

			// Build the prepare array which contains all custom columnName => columnValue pairs with their corresponding bind values and type mapping.
			foreach( $columnNameValuePair as $columnName => $columnValue ) {

				if( $this->column_exists( $columnName ) ) {

					$marker = ':bind' . $bindIndex;
					$bindIndex++;
					$bindValues[] = ['columnName'=> $columnName, 'bindMarker' => $marker, 'origValue' => $columnValue];
				}
			}

			self::error_if_sqlite_parameter_columns_missing( $columnNameValuePair, $bindValues );

			// Define default values for INSERT operations that are always added.
			$insertDefaults = [
				'_slug' => ':slug',
			];

			// Define default values for DO UPDATE SET operations that are always updated on conflict.
			$upsertDefaults = [
				'_updated_at' => 'CURRENT_TIMESTAMP',
			];

			// Extract the custom prepared columns from the prepare array for use in SQL statements.
			$upsertCustom = array_combine( array_column( $bindValues, 'columnName' ), array_column( $bindValues, 'bindMarker' ) );

			// Merge all INSERT columns and values into one set for the final INSERT statement.
			$insert_merged = array_merge( $insertDefaults, $upsertCustom );
			$insert_into   = implode( ', ', array_keys( $insert_merged ) );
			$insert_values = implode( ', ', array_values( $insert_merged ) );

			// Merge all columns and values for the DO UPDATE SET part of the UPSERT statement.
			$do_update_merged = array_merge( $upsertDefaults, $upsertCustom );
			$do_update_pair   = implode( ', ', array_map( function( string $k, string $v ) : string { return $k . '=' . $v; }, array_keys( $do_update_merged ), array_values( $do_update_merged ) ) );

			// Extend execute_statement to include the _slug parameter.
			$bindValues[] = ['columnName'=> '_slug', 'bindMarker' => ':slug', 'origValue' => $rowSlug];

			$sql = <<<SQL
				INSERT INTO `{$this->tblnam}` ({$insert_into}) VALUES ({$insert_values}) ON CONFLICT(_slug) DO UPDATE SET {$do_update_pair};
			SQL;

			/** @var bool */
			return $this->execute_statement( $sql, $bindValues ) !== false;
		}


		// Removes a row from the table using its slug if it exists. Returns true if the query executed successfully, or false on query error.
		public function row_remove( string $rowSlug ) : bool {

			self::error_if_empty_sqlite_slug( $rowSlug );

			$sql = <<<SQL
				DELETE FROM `{$this->tblnam}` WHERE _slug = :slug;
			SQL;

			/** @var bool */
			return $this->execute_statement( $sql, [['columnName'=> '_slug', 'bindMarker' => ':slug', 'origValue' => $rowSlug]] ) !== false;
		}





		// Instance Database Select Methods.
		// -----------------------------------------------------------------------------------------------------------------------------

		// Select specific records based on the provided arguments array.
		public function select( array $args ) : array {

			// Builder function for DISTINCT.
			$buildDistinct = function() use ( $args ) : string {

				/** @var string */
				return isset( $args['distinct'] ) && $args['distinct'] === true ? 'DISTINCT' : '';
			};

			// Builder function for COLUMNS.
			$buildColumns = function() use ( $args ) : string {

				$temp = [];

				if( isset( $args['columns'] ) && is_array( $args['columns'] ) ) {

					foreach( $args['columns'] as $columnName ) {

						self::error_if_invalid_sqlite_name( $columnName );

						$temp[] = $columnName;
					}
				}

				/** @var string */
				return !empty( $temp ) ? implode( ', ', $temp ) : '*';
			};

			// Builder function for WHERE.
			$bindIndex  = 0;
			$bindValues = [];
			$buildWhere = function() use ( $args, &$bindIndex, &$bindValues ) : string {

				$helperWhereRecursive = function( array $node ) use ( &$helperWhereRecursive, &$bindIndex, &$bindValues ) : string {

					// 1. LOGICAL CONTAINERS: AND / OR
					if( isset( $node['AND'] ) && is_array( $node['AND'] ) ) {

						return '(' . implode(' AND ', array_filter( array_map( $helperWhereRecursive, $node['AND'] ) ) ) . ')';
					}

					else if( isset( $node['OR'] ) && is_array( $node['OR'] ) ) {

						return '(' . implode(' OR ', array_filter( array_map( $helperWhereRecursive, $node['OR'] ) ) ) . ')';
					}

					// 2. NOT (Unary, rekursiv)
					else if( isset( $node['NOT'] ) && is_array( $node['NOT'] ) ) {

						return 'NOT (' . $helperWhereRecursive( $node['NOT'] ) . ')';
					}

					// 3. LEAF-NODES: einfache Bedingungen
					else if( isset( $node['column'] ) && isset( $node['op'] ) ) {

						self::error_if_invalid_sqlite_name( $node['column'] );

						if( $node['op'] === 'IS NULL' ) {

							return $node['column'] . ' IS NULL';
						}

						else if( $node['op'] === 'EXISTS' ) {
							// TODO
						}

						else if( $node['op'] === 'IN' ) {
							// TODO
						}

						else if( $node['op'] === 'BETWEEN' ) {
							// TODO
						}

						else if( in_array( $node['op'], array( '=', '!=', '<', '<=', '>', '>=', 'LIKE' ) ) && isset( $node['value'] ) ) {

							$marker = ':bind' . $bindIndex;
							$bindIndex++;
							$bindValues[] = ['columnName'=> $node['column'], 'bindMarker' => $marker, 'origValue' => $node['value']];

							return $node['column'] . ' ' . $node['op'] . ' ' . $marker;
						}
						else {

							// ERROR
						}
					}
					else {

						// ERROR
					}

					return '';
				};


				/** @var string */
				return isset( $args['where'] ) && is_array( $args['where'] ) ? 'WHERE ' . $helperWhereRecursive( $args['where'] ) : '';
			};

			// Builder function for ORDER BY.
			$buildOrderBy = function() use ( $args ) : string {

				$temp = [];

				if( isset( $args['orderby'] ) && is_array( $args['orderby'] ) ) {

					foreach( $args['orderby'] as $columnName => $sort ) {

						self::error_if_invalid_sqlite_name( $columnName );

						$temp[] = $columnName . ' ' . ( $sort === 'DESC' ? 'DESC' : 'ASC' );
					}
				}

				/** @var string */
				return !empty( $temp ) ? 'ORDER BY ' . implode( ', ', $temp ) : '';
			};

			// Builder function for LIMIT.
			$buildLimit = function() use ( $args ) : string {

				/** @var string */
				return isset( $args['limit'] ) && is_int( $args['limit'] ) ? 'LIMIT ' . max( 0, $args['limit'] ) : '';
			};

			// Builder function for OFFSET.
			$buildOffset = function() use ( $args ) : string {

				/** @var string */
				return isset( $args['offset'] ) && is_int( $args['offset'] ) ? 'OFFSET ' . max( 0, $args['offset'] ) : '';
			};

			$sql = <<<SQL
				SELECT {$buildDistinct()} {$buildColumns()} FROM `{$this->tblnam}` {$buildWhere()} {$buildOrderBy()} {$buildLimit()} {$buildOffset()};
			SQL;

			$fnResult = [];

			if( ( $result = $this->execute_statement( $sql, $bindValues ) ) !== false ) {

				while( ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) !== false ) {
				
					$fnResult[] = $row;
				}
			}

			/** @var array */
			return $fnResult;
		}





		// Instance Helper Methods.
		// -----------------------------------------------------------------------------------------------------------------------------

		// Maps a PHP value to the appropriate SQLite3 type.
		// Returns SQLITE3_NULL for null, SQLITE3_INTEGER, SQLITE3_FLOAT, SQLITE3_BLOB based on table info, or SQLITE3_TEXT by default.
		private function type_mapping( string $columnName, mixed $columnValue ) : int {

			$fnResult = SQLITE3_TEXT;

			if( $columnValue === null ) {

				$fnResult = SQLITE3_NULL;
			}
			else {

				$info = $this->table_info_columns();
				$map  = array( 'INTEGER' => SQLITE3_INTEGER, 'REAL' => SQLITE3_FLOAT, 'BLOB' => SQLITE3_BLOB );

				if( isset( $info[$columnName] ) && isset( $map[$info[$columnName]] ) ) {

					$fnResult = $map[$info[$columnName]];
				}
			}

			/** @var int */
			return $fnResult;
		}


		// Executes a prepared SQLite3 statement with bound values.
		// SQL with placeholders (e.g., :bind0, :slug) and an array with columnName, bindMarker, origValue.
		private function execute_statement( string $sql, array $bindValues ) : false|SQLite3Result {

			$fnResult = false;

			if( ( $stmt = $this->sqlite->prepare( $sql ) ) !== false ) {

				$comparison = [];

				foreach( $bindValues as $key => $arr ) {

					if( ( $stmt->bindValue( $arr['bindMarker'], $arr['origValue'], $this->type_mapping( $arr['columnName'], $arr['origValue'] ) ) ) !== false ) {

						$comparison[$key] = $arr;
					}
				}

				if( $bindValues === $comparison && ( $result = $stmt->execute() ) !== false ) {

					$fnResult = $result;
				}
			}

			/** @var array */
			return $fnResult;
		}



		// NOCH MACHEN ... AB Instance Row Methods. ALLES NOCHMAL PRÜFEN!!!!!



	}


?>