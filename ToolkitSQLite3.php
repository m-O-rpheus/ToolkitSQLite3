<?php


// ToolkitSQLite3
// Provides a helper class for SQLite3 operations: creating tables, adding/removing columns, and upserting rows.
// Focuses on simplicity, speed, and SQL injection safety.
// 
// Example:
// $instance = new ToolkitSQLite3( 'database.sqlite3', 'table_name_1' );
// $instance->table_column_add_ignore( ['meta_column1' => 'TEXT', 'meta_column2' => 'INT', 'meta_column3' => 'BLOB'] );
// $instance->row_upsert( 'row1', ['meta_column1' => 'value1', 'meta_column2' => '2', 'meta_column3' => 'value3'] );


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


	private static function error_if_sqlite_executions_differ( array $arrbefore, array $arrafter ) : void {

		if( count( $arrbefore ) !== count( $arrafter ) ) {

			trigger_error( 'ERROR: The SQLite3 SQL operation could not be executed correctly. Possible causes: data inconsistency or attempt to select non-existent columns.', E_USER_ERROR );
			exit();
		}
	}





	// Instance Common Methods.
	// -----------------------------------------------------------------------------------------------------------------------------

	// Retrieves an array of column names and their types for the current table. Returns an empty array if the table does not exist or the query fails.
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

	// Checks if the table exists by verifying whether column information can be retrieved.
	public function table_exists() : bool {

		/** @var bool */
		return !empty( $this->table_info_columns() );
	}


	// Creates a table if it does not already exist. Returns 0 on query error, 1 if table was successfully created, or 2 if the table already exists and was ignored.
	public function table_add_ignore() : int {

		$sql = <<<SQL
			CREATE TABLE `{$this->tblnam}` (
				_id INTEGER PRIMARY KEY AUTOINCREMENT,
				_slug TEXT NOT NULL UNIQUE,
				_created_at TEXT NOT NULL,
				_updated_at TEXT NOT NULL
			);
		SQL;

		/** @var int */
		return $this->table_exists() ? 2 : ( $this->sqlite->exec( $sql ) !== false ? 1 : 0 );
	}


	// Drops the table if it exists. Returns 0 on query error, 1 if table was removed, or 2 if the table did not exist and was ignored.
	public function table_delete_ignore() : int {

		$sql = <<<SQL
			DROP TABLE `{$this->tblnam}`;
		SQL;

		/** @var int */
		return $this->table_exists() ? ( $this->sqlite->exec( $sql ) !== false ? 1 : 0 ) : 2;
	}





	// Instance Column Methods.
	// -----------------------------------------------------------------------------------------------------------------------------

	// Checks whether a column exists in the current table by verifying the column name in the table's column information.
	public function column_exists( string $columnName ) : bool {

		self::error_if_invalid_sqlite_name( $columnName );

		/** @var bool */
		return array_key_exists( $columnName, $this->table_info_columns() );
	}


	// Adds a column to the table if it does not exist. Returns 0 on query error, 1 if the column was added, or 2 if the column already exists and was ignored.
	public function column_add_ignore( string $columnName, string $columnType ) : int {

		self::error_if_invalid_sqlite_name( $columnName );
		self::error_if_invalid_sqlite_type( $columnType );

		$sql = <<<SQL
			ALTER TABLE `{$this->tblnam}` ADD COLUMN `{$columnName}` {$columnType};
		SQL;

		/** @var int */
		return $this->column_exists( $columnName ) ? 2 : ( $this->sqlite->exec( $sql ) !== false ? 1 : 0 );
	}


	// Removes a column from the table if it exists. Returns 0 on query error, 1 if column was removed, or 2 if the column does not exist and was ignored.
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
	// Throws an error if there are problems executing the statement due to SQL issues.
	public function table_column_add_ignore( array $columnNameTypePair ) : bool {

		$comparison = [];

		if( $this->table_add_ignore() > 0 ) {

			foreach( $columnNameTypePair as $columnName => $columnType ) {

				if( $this->column_add_ignore( $columnName, $columnType ) > 0 ) {

					$comparison[$columnName] = $columnType;
				}
			}
		}

		self::error_if_sqlite_executions_differ( $columnNameTypePair, $comparison );

		/** @var bool */
		return true;
	}





	// Instance Row Methods.
	// -----------------------------------------------------------------------------------------------------------------------------

	// Checks if a row exists in the table using its slug value. Returns true if it exists, false otherwise.
	public function row_isset( string $rowSlug ) : bool {

		self::error_if_empty_sqlite_slug( $rowSlug );

		$sql = <<<SQL
			SELECT 1 FROM `{$this->tblnam}` WHERE _slug = :slug LIMIT 1;
		SQL;

		$fnResult = false;

		if( ( $stmt = $this->sqlite->prepare( $sql ) ) !== false ) {

			if( ( $stmt->bindValue( ':slug', $rowSlug, SQLITE3_TEXT ) ) !== false ) {

				if( ( $result = $stmt->execute() ) !== false ) {

					$fnResult = $result->fetchArray( SQLITE3_ASSOC ) !== false;
				}
			}
		}

		/** @var bool */
		return $fnResult;
	}


	// Inserts a new row or updates an existing row based on the slug value. All values are safely bound using prepared statements with correct type mapping.
	// Returns true if the operation was successful.
	public function row_upsert( string $rowSlug, array $columnNameValuePair ) : bool {

		self::error_if_empty_sqlite_slug( $rowSlug );

		$fnResult = false;

		$tblinfo = $this->table_info_columns();
		$mapping = ['INTEGER' => SQLITE3_INTEGER, 'REAL' => SQLITE3_FLOAT, 'BLOB' => SQLITE3_BLOB];
		$paramno = 0;
		$prepare = [];

		// Build the prepare array which contains all custom columnName => columnValue pairs with their corresponding bind values and type mapping.
		foreach( $columnNameValuePair as $columnName => $columnValue ) {

			// The following statements are equivalent to column_exists, but prevent multiple executions of table_info_columns.
			self::error_if_invalid_sqlite_name( $columnName );

			if( array_key_exists( $columnName, $tblinfo ) ) {

				$typeMapping = ( $columnValue === null ) ? SQLITE3_NULL : ( $mapping[$tblinfo[$columnName]] ?? SQLITE3_TEXT );

				$paramno++;
				$prepare[':param'.$paramno] = [
					'columnName'  => $columnName,
					'columnValue' => $columnValue,
					'typeMapping' => $typeMapping,
				];
			}
		}

		self::error_if_sqlite_executions_differ( $columnNameValuePair, $prepare );


		// Define default values for INSERT operations that are always added.
		$insertDefaults = [
			"_slug" => ":slug",
			"_created_at" => "datetime('now')",
		];

		// Define default values for DO UPDATE SET operations that are always updated on conflict.
		$upsertDefaults = [
			"_updated_at" => "datetime('now')",
		];

		// Extract the custom prepared columns from the prepare array for use in SQL statements.
		$upsertCustom = array_combine( array_column( $prepare, 'columnName' ), array_keys( $prepare ) );



		// Merge all INSERT columns and values into one set for the final INSERT statement.
		$insert_merged = array_merge( $insertDefaults, $upsertDefaults, $upsertCustom );
		$insert_into   = implode( ',', array_keys( $insert_merged ) );
		$insert_values = implode( ',', array_values( $insert_merged ) );

		// Merge all columns and values for the DO UPDATE SET part of the UPSERT statement.
		$do_update_merged = array_merge( $upsertDefaults, $upsertCustom );
		$do_update_set    = implode( ',', array_map( function( string $k, string $v ) : string { return $k . '=' . $v; }, array_keys( $do_update_merged ), array_values( $do_update_merged ) ) );



		// Build the final SQL statement for the UPSERT operation using ON CONFLICT DO UPDATE.
		$sql = <<<SQL
			INSERT INTO `{$this->tblnam}` ({$insert_into}) VALUES ({$insert_values}) ON CONFLICT(_slug) DO UPDATE SET {$do_update_set};
		SQL;



		// Execute the prepared statement.
		if( ( $stmt = $this->sqlite->prepare( $sql ) ) !== false ) {

			$comparison = [];

			if( ( $stmt->bindValue( ':slug', $rowSlug, SQLITE3_TEXT ) ) !== false ) {

				foreach( $prepare as $prepareParam => $prepareArr ) {

					if( ( $stmt->bindValue( $prepareParam, $prepareArr['columnValue'], $prepareArr['typeMapping'] ) ) !== false ) {

						$comparison[$prepareParam] = $prepareArr;
					}
				}
			}

			self::error_if_sqlite_executions_differ( $prepare, $comparison );

			if( ( $result = $stmt->execute() ) !== false ) {

				$fnResult = true;
			}
		}

		/** @var bool */
		return $fnResult;
	}


	// Removes a row from the table using its slug if it exists. Returns true if the deletion query executed successfully, false otherwise.
	public function row_remove( string $rowSlug ) : bool {

		self::error_if_empty_sqlite_slug( $rowSlug );

		$fnResult = false;

		$sql = <<<SQL
			DELETE FROM `{$this->tblnam}` WHERE _slug = :slug;
		SQL;

		if( ( $stmt = $this->sqlite->prepare( $sql ) ) !== false ) {

			if( ( $stmt->bindValue( ':slug', $rowSlug, SQLITE3_TEXT ) ) !== false ) {

				if( ( $result = $stmt->execute() ) !== false ) {

					$fnResult = true;
				}
			}
		}

		/** @var bool */
		return $fnResult;
	}


}


?>