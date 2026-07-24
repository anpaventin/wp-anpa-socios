<?php
/**
 * Persistence for per-course trimester + application-window states (fase34).
 *
 * Thin wpdb glue over wp_anpa_curso_trimestres and the append-only
 * wp_anpa_transicions log. All transition RULES live in the pure value objects
 * (ANPA_Socios_Trimestre_Estado / ANPA_Socios_Ventana_Estado); this class only
 * reads, seeds at explicit lifecycle points and persists validated transitions
 * with an audit row. No public endpoints — callers are admin-post handlers/cron.
 *
 * Safety model (FAIL-CLOSED). Reads NEVER write and NEVER fabricate an "open"
 * or "activo" state:
 *   - `for_curso()` is strictly read-only. Missing rows are reported as
 *     `presente => false` with SAFE display defaults (estado '' = "sen
 *     configurar", ventana `pechada` = closed). It never seeds and never
 *     invents an `activo` trimester.
 *   - Seeding (`ensure_seeded()`) happens ONLY at unambiguous lifecycle points:
 *     schema migration, course activation, or an explicit admin repair action.
 *     It is idempotent and logs each newly created trimester.
 *   - Critical writes (`transicionar_*`) BLOCK with code `sen_configurar` when
 *     the target row is missing, instead of assuming a state. They never open a
 *     window or activate a trimester "by default".
 *
 * @since  1.38.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and transitions trimester/window states for a course.
 *
 * @since 1.38.0
 */
final class ANPA_Socios_Trimestre_Repo {

	const AMBITO_TRIMESTRE = 'trimestre';
	const AMBITO_VENTANA   = 'ventana';

	// Seed origins for the audit log (wp_anpa_transicions.orixe).
	const ORIXE_MIGRACION  = 'migracion';
	const ORIXE_ACTIVACION = 'activacion';
	const ORIXE_REPARACION = 'reparacion';
	const ORIXE_MANUAL     = 'manual';
	const ORIXE_CRON       = 'cron';

	/**
	 * Genesis seed for a course: T1 activo, T2/T3 pendente, every application
	 * window pechada (closed). Used ONLY by explicit seeding, never by reads.
	 *
	 * @since  1.38.0
	 * @return array<int,string> trimestre => estado.
	 */
	private static function seed_estados(): array {
		return array(
			1 => ANPA_Socios_Trimestre_Estado::ACTIVO,
			2 => ANPA_Socios_Trimestre_Estado::PENDENTE,
			3 => ANPA_Socios_Trimestre_Estado::PENDENTE,
		);
	}

	/**
	 * Reads the three trimester rows for a course. STRICTLY READ-ONLY: never
	 * seeds, never writes, never fabricates an `activo`/`aberta` state.
	 *
	 * For a trimester with no stored row (or on a read error) it returns SAFE
	 * defaults so the admin panel can render: estado '' ("sen configurar" —
	 * indeterminate) and ventana `pechada` (closed). The `presente` flag lets
	 * callers distinguish a real stored state from a missing one and fail closed.
	 *
	 * @since  1.38.0
	 * @param  string $curso Valid curso escolar (AAAA/AAAA+1).
	 * @return array<int,array{estado:string,ventana_estado:string,presente:bool}>
	 */
	public static function for_curso( string $curso ): array {
		$out = array();
		foreach ( array( 1, 2, 3 ) as $tri ) {
			$out[ $tri ] = array(
				'estado'         => '', // '' = sen configurar (indeterminate); never assume activo.
				'ventana_estado' => ANPA_Socios_Ventana_Estado::PECHADA, // safe default: closed.
				'presente'       => false,
			);
		}

		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return $out;
		}

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_curso_trimestres();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT trimestre, estado, ventana_estado FROM {$table} WHERE curso_escolar = %s ORDER BY trimestre",
				$curso
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$tri = (int) $row['trimestre'];
			if ( $tri < 1 || $tri > 3 ) {
				continue;
			}
			$out[ $tri ] = array(
				'estado'         => (string) $row['estado'],
				'ventana_estado' => (string) $row['ventana_estado'],
				'presente'       => true,
			);
		}

		return $out;
	}

	/**
	 * Whether all three trimester rows exist for the course (fully initialised).
	 *
	 * @since  1.38.0
	 * @param  string $curso Curso escolar.
	 * @return bool
	 */
	public static function esta_inicializado( string $curso ): bool {
		$rows = self::for_curso( $curso );
		foreach ( array( 1, 2, 3 ) as $tri ) {
			if ( empty( $rows[ $tri ]['presente'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Seeds the missing trimester rows for a course (genesis state) at an
	 * unambiguous lifecycle point (migration, course activation, admin repair).
	 *
	 * Idempotent: only inserts trimesters that do NOT already exist, so it never
	 * overwrites an admin-managed state. Each newly created trimester is logged
	 * in wp_anpa_transicions with the given origin, so a repair is auditable and
	 * corruption is not hidden silently.
	 *
	 * @since  1.38.0
	 * @param  string $curso Valid curso escolar.
	 * @param  string $orixe One of ORIXE_MIGRACION|ORIXE_ACTIVACION|ORIXE_REPARACION.
	 * @param  string $actor Actor email or 'sistema'.
	 * @return array{ok:bool,created:int}
	 */
	public static function ensure_seeded( string $curso, string $orixe = self::ORIXE_MIGRACION, string $actor = 'sistema' ): array {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return array( 'ok' => false, 'created' => 0 );
		}

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_curso_trimestres();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only existence check.
		$existing = $wpdb->get_col(
			$wpdb->prepare( "SELECT trimestre FROM {$table} WHERE curso_escolar = %s", $curso )
		);
		$existing = array_map( 'intval', (array) $existing );

		$now     = current_time( 'mysql' );
		$created = 0;
		foreach ( self::seed_estados() as $tri => $estado ) {
			if ( in_array( $tri, $existing, true ) ) {
				continue; // Never overwrite an existing (possibly managed) row.
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- idempotent seed guarded by unique key + existence check.
			$ins = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (curso_escolar, trimestre, estado, ventana_estado, creado_en, actualizado_en) VALUES (%s, %d, %s, %s, %s, %s)",
					$curso,
					$tri,
					$estado,
					ANPA_Socios_Ventana_Estado::PECHADA,
					$now,
					$now
				)
			);
			if ( $ins ) {
				++$created;
				self::log( $curso, self::AMBITO_TRIMESTRE, (string) $tri, '', $estado, $actor, $orixe, '', 'seed', $now );
			}
		}

		return array( 'ok' => true, 'created' => $created );
	}

	/**
	 * Transitions a trimester (lectivo) state, validated by the value object.
	 *
	 * FAIL-CLOSED: if the trimester has no stored row, the operation is BLOCKED
	 * (code `sen_configurar`) instead of assuming a state or auto-seeding.
	 * Idempotent: if already at the target state it is a successful non-op with
	 * NO audit row (a repeated/double-submitted action never duplicates a log).
	 *
	 * @since  1.38.0
	 * @param  string $curso       Valid curso escolar.
	 * @param  int    $trimestre   1..3.
	 * @param  string $a_estado    Target trimester state.
	 * @param  string $actor       Actor email (or 'cron').
	 * @param  string $orixe       ORIXE_MANUAL|ORIXE_CRON.
	 * @param  string $correlacion Correlation/idempotency id for the operation.
	 * @param  string $motivo      Optional human reason.
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	public static function transicionar_trimestre( string $curso, int $trimestre, string $a_estado, string $actor, string $orixe = self::ORIXE_MANUAL, string $correlacion = '', string $motivo = '' ): array {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) || $trimestre < 1 || $trimestre > 3 ) {
			return array( 'ok' => false, 'code' => 'invalid_input', 'changed' => false );
		}
		if ( ! ANPA_Socios_Trimestre_Estado::valido( $a_estado ) ) {
			return array( 'ok' => false, 'code' => 'invalid_state', 'changed' => false );
		}

		$rows = self::for_curso( $curso );
		if ( empty( $rows[ $trimestre ]['presente'] ) ) {
			// Fail-closed: state cannot be determined → block, do not seed here.
			return array( 'ok' => false, 'code' => 'sen_configurar', 'changed' => false );
		}
		$de = (string) $rows[ $trimestre ]['estado'];

		if ( $de === $a_estado ) {
			return array( 'ok' => true, 'code' => 'noop', 'changed' => false );
		}
		if ( ! ANPA_Socios_Trimestre_Estado::pode_transicionar( $de, $a_estado ) ) {
			return array( 'ok' => false, 'code' => 'transition_not_allowed', 'changed' => false );
		}

		return self::apply( $curso, self::AMBITO_TRIMESTRE, (string) $trimestre, 'estado', $trimestre, $de, $a_estado, $actor, $orixe, $correlacion, $motivo );
	}

	/**
	 * Transitions the application-window state of a trimester. FAIL-CLOSED and
	 * idempotent, same contract as transicionar_trimestre().
	 *
	 * @since  1.38.0
	 * @param  string $curso       Valid curso escolar.
	 * @param  int    $trimestre   1..3 (window that opens requests for it).
	 * @param  string $a_estado    Target window state.
	 * @param  string $actor       Actor email (or 'cron').
	 * @param  string $orixe       ORIXE_MANUAL|ORIXE_CRON.
	 * @param  string $correlacion Correlation/idempotency id.
	 * @param  string $motivo      Optional human reason.
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	public static function transicionar_ventana( string $curso, int $trimestre, string $a_estado, string $actor, string $orixe = self::ORIXE_MANUAL, string $correlacion = '', string $motivo = '' ): array {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) || $trimestre < 1 || $trimestre > 3 ) {
			return array( 'ok' => false, 'code' => 'invalid_input', 'changed' => false );
		}
		if ( ! ANPA_Socios_Ventana_Estado::valido( $a_estado ) ) {
			return array( 'ok' => false, 'code' => 'invalid_state', 'changed' => false );
		}

		$rows = self::for_curso( $curso );
		if ( empty( $rows[ $trimestre ]['presente'] ) ) {
			return array( 'ok' => false, 'code' => 'sen_configurar', 'changed' => false );
		}
		$de = (string) $rows[ $trimestre ]['ventana_estado'];

		if ( $de === $a_estado ) {
			return array( 'ok' => true, 'code' => 'noop', 'changed' => false );
		}
		if ( ! ANPA_Socios_Ventana_Estado::pode_transicionar( $de, $a_estado ) ) {
			return array( 'ok' => false, 'code' => 'transition_not_allowed', 'changed' => false );
		}

		return self::apply( $curso, self::AMBITO_VENTANA, (string) $trimestre, 'ventana_estado', $trimestre, $de, $a_estado, $actor, $orixe, $correlacion, $motivo );
	}

	/**
	 * Persists a validated transition (row update + audit log) atomically.
	 *
	 * @since  1.38.0
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	private static function apply( string $curso, string $ambito, string $referencia, string $column, int $trimestre, string $de, string $a, string $actor, string $orixe, string $correlacion, string $motivo ): array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_curso_trimestres();
		$now   = current_time( 'mysql' );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return array( 'ok' => false, 'code' => 'db_error', 'changed' => false );
		}

		$updated = $wpdb->update(
			$table,
			array( $column => $a, 'actualizado_en' => $now ),
			array( 'curso_escolar' => $curso, 'trimestre' => $trimestre ),
			array( '%s', '%s' ),
			array( '%s', '%d' )
		);
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => false, 'code' => 'db_error', 'changed' => false );
		}

		if ( ! self::log( $curso, $ambito, $referencia, $de, $a, $actor, $orixe, $correlacion, $motivo, $now ) ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => false, 'code' => 'db_error', 'changed' => false );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => false, 'code' => 'db_error', 'changed' => false );
		}

		return array( 'ok' => true, 'code' => 'ok', 'changed' => true );
	}

	/**
	 * Appends one row to the append-only transition audit log.
	 *
	 * Stores no unnecessary personal data — only the actor email (or 'sistema'/
	 * 'cron'), which is required to attribute the action.
	 *
	 * @since  1.38.0
	 * @param  string $now Timestamp; caller passes a shared value for correlation.
	 * @return bool True on insert success.
	 */
	private static function log( string $curso, string $ambito, string $referencia, string $de, string $a, string $actor, string $orixe, string $correlacion, string $motivo, string $now ): bool {
		global $wpdb;
		$log   = ANPA_Socios_DB::tabela_transicions();
		$orixe = in_array( $orixe, array( self::ORIXE_MANUAL, self::ORIXE_CRON, self::ORIXE_MIGRACION, self::ORIXE_ACTIVACION, self::ORIXE_REPARACION ), true ) ? $orixe : self::ORIXE_MANUAL;

		$inserted = $wpdb->insert(
			$log,
			array(
				'curso_escolar' => $curso,
				'ambito'        => $ambito,
				'referencia'    => $referencia,
				'de_estado'     => $de,
				'a_estado'      => $a,
				'actor_email'   => $actor,
				'orixe'         => $orixe,
				'correlacion'   => $correlacion,
				'motivo'        => $motivo,
				'creado_en'     => '' !== $now ? $now : current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}
}
