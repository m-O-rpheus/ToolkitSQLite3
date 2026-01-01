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
				_created_at TEXT NOT NULL,
				_updated_at TEXT NOT NULL
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
	// Returns true if the query executed successfully (row inserted or updated), or false on query error.
	public function row_upsert( string $rowSlug, array $columnNameValuePair ) : bool {

		self::error_if_empty_sqlite_slug( $rowSlug );

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

		self::error_if_sqlite_parameter_columns_missing( $columnNameValuePair, $prepare );

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



		$sql = <<<SQL
			INSERT INTO `{$this->tblnam}` ({$insert_into}) VALUES ({$insert_values}) ON CONFLICT(_slug) DO UPDATE SET {$do_update_set};
		SQL;

		$fnResult = false;

		if( ( $stmt = $this->sqlite->prepare( $sql ) ) !== false ) {

			$comparison = [];

			if( ( $stmt->bindValue( ':slug', $rowSlug, SQLITE3_TEXT ) ) !== false ) {

				foreach( $prepare as $prepareParam => $prepareArr ) {

					if( ( $stmt->bindValue( $prepareParam, $prepareArr['columnValue'], $prepareArr['typeMapping'] ) ) !== false ) {

						$comparison[$prepareParam] = $prepareArr;
					}
				}
			}

			if( $prepare === $comparison ) {

				if( ( $result = $stmt->execute() ) !== false ) {

					$fnResult = true;
				}
			}
		}

		/** @var bool */
		return $fnResult;
	}


	// Removes a row from the table using its slug if it exists. Returns true if the query executed successfully, or false on query error.
	public function row_remove( string $rowSlug ) : bool {

		self::error_if_empty_sqlite_slug( $rowSlug );

		$sql = <<<SQL
			DELETE FROM `{$this->tblnam}` WHERE _slug = :slug;
		SQL;

		$fnResult = false;

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