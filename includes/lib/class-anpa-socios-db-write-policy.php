<?php
/**
 * Reusable failure policy for $wpdb write operations.
 *
 * A silent `$wpdb->insert()` failure returning false with no surfaced message
 * already cost this project a defect in 36s2 (only caught by the integration
 * run). This class provides wrappers that surface the engine message on every
 * failure, making "what went wrong" diagnosable instead of invisible.
 *
 * Each method throws `ANPA_Socios_DB_Write_Exception` on failure, carrying the
 * engine message. Callers decide whether to catch-and-log or let it propagate;
 * the default is LOUD, because the silent default is what caused the defect.
 *
 * Pure glue: depends on $wpdb but contains no business logic. Testable via
 * php -l + integration against a real engine.
 *
 * @since   1.41.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown when a $wpdb write operation fails.
 *
 * Carries the operation (insert/update/delete/query), the table, and the engine
 * message so the caller or the log always knows WHAT failed, WHERE, and WHY.
 */
class ANPA_Socios_DB_Write_Exception extends RuntimeException {

	/** @var string */
	private $operation;

	/** @var string */
	private $table;

	/** @var string */
	private $engine_message;

	/**
	 * @param string $operation      One of: insert, update, delete, query.
	 * @param string $table          The table name.
	 * @param string $engine_message The raw $wpdb->last_error.
	 */
	public function __construct( string $operation, string $table, string $engine_message ) {
		$this->operation      = $operation;
		$this->table          = $table;
		$this->engine_message = $engine_message;

		parent::__construct(
			sprintf(
				'[anpa-socios] %s on %s failed: %s',
				$operation,
				$table,
				'' !== $engine_message ? $engine_message : '(no engine message; $wpdb returned false)'
			)
		);
	}

	public function operation(): string {
		return $this->operation;
	}

	public function table(): string {
		return $this->table;
	}

	public function engine_message(): string {
		return $this->engine_message;
	}
}

/**
 * Static helpers wrapping $wpdb write operations with failure surfacing.
 *
 * Usage:
 *   ANPA_Socios_DB_Write_Policy::insert_or_throw( $table, $data, $format );
 *
 * Catches both `false` returns AND non-empty `$wpdb->last_error` (some MySQL
 * errors leave a message without returning false).
 */
class ANPA_Socios_DB_Write_Policy {

	/**
	 * Insert a row or throw with the engine message.
	 *
	 * @param  string               $table  Table name (fully qualified with prefix).
	 * @param  array<string,mixed>  $data   Column → value pairs.
	 * @param  array<string>|null   $format Optional format array.
	 * @return int The insert_id on success.
	 * @throws ANPA_Socios_DB_Write_Exception On failure.
	 */
	public static function insert_or_throw( string $table, array $data, $format = null ): int {
		global $wpdb;

		$wpdb->last_error = '';
		$result = $wpdb->insert( $table, $data, $format );

		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			throw new ANPA_Socios_DB_Write_Exception( 'insert', $table, (string) $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update rows or throw with the engine message.
	 *
	 * @param  string               $table  Table name (fully qualified with prefix).
	 * @param  array<string,mixed>  $data   Column → value pairs to set.
	 * @param  array<string,mixed>  $where  WHERE conditions.
	 * @param  array<string>|null   $format Optional data format array.
	 * @param  array<string>|null   $where_format Optional WHERE format array.
	 * @return int Number of rows updated (may be 0 if nothing matched).
	 * @throws ANPA_Socios_DB_Write_Exception On failure.
	 */
	public static function update_or_throw( string $table, array $data, array $where, $format = null, $where_format = null ): int {
		global $wpdb;

		$wpdb->last_error = '';
		$result = $wpdb->update( $table, $data, $where, $format, $where_format );

		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			throw new ANPA_Socios_DB_Write_Exception( 'update', $table, (string) $wpdb->last_error );
		}

		return (int) $result;
	}

	/**
	 * Delete rows or throw with the engine message.
	 *
	 * @param  string               $table  Table name (fully qualified with prefix).
	 * @param  array<string,mixed>  $where  WHERE conditions.
	 * @param  array<string>|null   $where_format Optional WHERE format array.
	 * @return int Number of rows deleted.
	 * @throws ANPA_Socios_DB_Write_Exception On failure.
	 */
	public static function delete_or_throw( string $table, array $where, $where_format = null ): int {
		global $wpdb;

		$wpdb->last_error = '';
		$result = $wpdb->delete( $table, $where, $where_format );

		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			throw new ANPA_Socios_DB_Write_Exception( 'delete', $table, (string) $wpdb->last_error );
		}

		return (int) $result;
	}

	/**
	 * Execute a raw query or throw with the engine message.
	 *
	 * @param  string $sql  The SQL to execute (already prepared or safe).
	 * @param  string $table_hint A human-readable table hint for the exception message.
	 * @return int Number of affected rows.
	 * @throws ANPA_Socios_DB_Write_Exception On failure.
	 */
	public static function query_or_throw( string $sql, string $table_hint = '(query)' ): int {
		global $wpdb;

		$wpdb->last_error = '';
		$result = $wpdb->query( $sql );

		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			throw new ANPA_Socios_DB_Write_Exception( 'query', $table_hint, (string) $wpdb->last_error );
		}

		return (int) $result;
	}
}
