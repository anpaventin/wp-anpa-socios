<?php
/**
 * Persistence for per-course trimester + application-window states (fase34).
 *
 * Thin wpdb glue over wp_anpa_curso_trimestres and the append-only
 * wp_anpa_transicions log. All transition rules live in the pure value objects
 * ANPA_Socios_Trimestre_Estado / ANPA_Socios_Ventana_Estado; this class only
 * reads, seeds on demand (fail-open) and persists validated transitions with an
 * audit row. No public endpoints — callers are admin-post handlers / cron.
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

	/**
	 * Default seed for a course with no rows yet: T1 activo, T2/T3 pendente,
	 * every application window pechada.
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
	 * Returns the three trimester rows for a course, seeding them on demand.
	 *
	 * Fail-open: if the DB is unreachable, returns computed defaults so the
	 * admin panel can still render (no fatal on read).
	 *
	 * @since  1.38.0
	 * @param  string $curso Valid curso escolar (AAAA/AAAA+1).
	 * @return array<int,array{estado:string,ventana_estado:string}>
	 */
	public static function for_curso( string $curso ): array {
		$defaults = array();
		$seed     = self::seed_estados();
		foreach ( array( 1, 2, 3 ) as $tri ) {
			$defaults[ $tri ] = array(
				'estado'         => $seed[ $tri ],
				'ventana_estado' => ANPA_Socios_Ventana_Estado::PECHADA,
			);
		}

		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return $defaults;
		}

		self::ensure_seeded( $curso );

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_curso_trimestres();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only, table name is internal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT trimestre, estado, ventana_estado FROM {$table} WHERE curso_escolar = %s",
				$curso
			),
			ARRAY_A
		);

		$out = $defaults;
		foreach ( (array) $rows as $row ) {
			$tri = (int) $row['trimestre'];
			if ( $tri < 1 || $tri > 3 ) {
				continue;
			}
			$out[ $tri ] = array(
				'estado'         => (string) $row['estado'],
				'ventana_estado' => (string) $row['ventana_estado'],
			);
		}

		return $out;
	}

	/**
	 * Seeds the three trimester rows for a course if none exist yet. Idempotent
	 * (the unique key guards duplicates). Best-effort; never throws.
	 *
	 * @since  1.38.0
	 * @param  string $curso Valid curso escolar.
	 * @return void
	 */
	public static function ensure_seeded( string $curso ): void {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return;
		}

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_curso_trimestres();
		foreach ( self::seed_estados() as $tri => $estado ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- idempotent seed guarded by unique key.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (curso_escolar, trimestre, estado, ventana_estado) VALUES (%s, %d, %s, %s)",
					$curso,
					$tri,
					$estado,
					ANPA_Socios_Ventana_Estado::PECHADA
				)
			);
		}
	}

	/**
	 * Transitions a trimester (lectivo) state, validated by the value object.
	 *
	 * Idempotent: if the trimester is already at the target state, it is a
	 * successful non-op (no audit row written). Any real transition is logged
	 * in wp_anpa_transicions with actor + origin.
	 *
	 * @since  1.38.0
	 * @param  string $curso     Valid curso escolar.
	 * @param  int    $trimestre 1..3.
	 * @param  string $a_estado  Target trimester state.
	 * @param  string $actor     Actor email (or 'cron').
	 * @param  string $orixe     'manual' | 'cron'.
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	public static function transicionar_trimestre( string $curso, int $trimestre, string $a_estado, string $actor, string $orixe = 'manual' ): array {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) || $trimestre < 1 || $trimestre > 3 ) {
			return array( 'ok' => false, 'code' => 'invalid_input', 'changed' => false );
		}
		if ( ! ANPA_Socios_Trimestre_Estado::valido( $a_estado ) ) {
			return array( 'ok' => false, 'code' => 'invalid_state', 'changed' => false );
		}

		self::ensure_seeded( $curso );
		$rows    = self::for_curso( $curso );
		$de      = (string) ( $rows[ $trimestre ]['estado'] ?? '' );

		if ( $de === $a_estado ) {
			return array( 'ok' => true, 'code' => 'noop', 'changed' => false );
		}
		if ( ! ANPA_Socios_Trimestre_Estado::pode_transicionar( $de, $a_estado ) ) {
			return array( 'ok' => false, 'code' => 'transition_not_allowed', 'changed' => false );
		}

		return self::apply(
			$curso,
			self::AMBITO_TRIMESTRE,
			(string) $trimestre,
			'estado',
			$trimestre,
			$de,
			$a_estado,
			$actor,
			$orixe
		);
	}

	/**
	 * Transitions the application-window state of a trimester.
	 *
	 * @since  1.38.0
	 * @param  string $curso     Valid curso escolar.
	 * @param  int    $trimestre 1..3 (window that opens requests for it).
	 * @param  string $a_estado  Target window state.
	 * @param  string $actor     Actor email (or 'cron').
	 * @param  string $orixe     'manual' | 'cron'.
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	public static function transicionar_ventana( string $curso, int $trimestre, string $a_estado, string $actor, string $orixe = 'manual' ): array {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) || $trimestre < 1 || $trimestre > 3 ) {
			return array( 'ok' => false, 'code' => 'invalid_input', 'changed' => false );
		}
		if ( ! ANPA_Socios_Ventana_Estado::valido( $a_estado ) ) {
			return array( 'ok' => false, 'code' => 'invalid_state', 'changed' => false );
		}

		self::ensure_seeded( $curso );
		$rows = self::for_curso( $curso );
		$de   = (string) ( $rows[ $trimestre ]['ventana_estado'] ?? '' );

		if ( $de === $a_estado ) {
			return array( 'ok' => true, 'code' => 'noop', 'changed' => false );
		}
		if ( ! ANPA_Socios_Ventana_Estado::pode_transicionar( $de, $a_estado ) ) {
			return array( 'ok' => false, 'code' => 'transition_not_allowed', 'changed' => false );
		}

		return self::apply(
			$curso,
			self::AMBITO_VENTANA,
			(string) $trimestre,
			'ventana_estado',
			$trimestre,
			$de,
			$a_estado,
			$actor,
			$orixe
		);
	}

	/**
	 * Persists a validated transition (row update + audit log) atomically.
	 *
	 * @since  1.38.0
	 * @param  string $curso      Curso escolar.
	 * @param  string $ambito     AMBITO_TRIMESTRE|AMBITO_VENTANA.
	 * @param  string $referencia Human reference (trimester number).
	 * @param  string $column     Column to update (estado|ventana_estado).
	 * @param  int    $trimestre  Trimester row.
	 * @param  string $de         Current state.
	 * @param  string $a          Target state.
	 * @param  string $actor      Actor email or 'cron'.
	 * @param  string $orixe      manual|cron.
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	private static function apply( string $curso, string $ambito, string $referencia, string $column, int $trimestre, string $de, string $a, string $actor, string $orixe ): array {
		global $wpdb;
		$table  = ANPA_Socios_DB::tabela_curso_trimestres();
		$log    = ANPA_Socios_DB::tabela_transicions();
		$now    = current_time( 'mysql' );
		$orixe  = in_array( $orixe, array( 'manual', 'cron' ), true ) ? $orixe : 'manual';

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

		$logged = $wpdb->insert(
			$log,
			array(
				'curso_escolar' => $curso,
				'ambito'        => $ambito,
				'referencia'    => $referencia,
				'de_estado'     => $de,
				'a_estado'      => $a,
				'actor_email'   => $actor,
				'orixe'         => $orixe,
				'creado_en'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $logged ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => false, 'code' => 'db_error', 'changed' => false );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => false, 'code' => 'db_error', 'changed' => false );
		}

		return array( 'ok' => true, 'code' => 'ok', 'changed' => true );
	}
}
