<?php
/**
 * Course season lifecycle service (fase12).
 *
 * WordPress glue around the pure ANPA_Socios_Season helper:
 *   - a daily cron that closes finished courses and creates the next course as
 *     `pendente`; activation is always an explicit admin decision;
 *   - read helpers used by the pre-season access gate and the socios page.
 *
 * All transitions are idempotent and safe to run multiple times per day.
 *
 * @since  1.18.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Season_Service {

	/**
	 * Daily cron hook that runs the season check.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'anpa_socios_season_check';

	/**
	 * Option storing persistent "trimester operative-close reached" flags.
	 * Shape: array<string,array{curso:string,trimestre:int,data:string,creado:string}>
	 * keyed by "{curso}:{trimestre}" so a reached trimester is announced once.
	 *
	 * @var string
	 */
	const AVISOS_OPTION = 'anpa_socios_trimestre_avisos';

	/**
	 * Schedules the daily season check (idempotent).
	 *
	 * @return void
	 */
	public static function programar(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clears the daily season check.
	 *
	 * @return void
	 */
	public static function desprogramar(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Runs the season check: closes finished courses and creates the following
	 * course as `pendente` with enrolments closed. Activation is manual.
	 *
	 * @param  string|null $today Optional Y-m-d override (testing/manual runs).
	 * @return array{closed:string[],created:string[],activated:string[]}
	 */
	public static function run_check( ?string $today = null ): array {
		global $wpdb;

		// Use WordPress timezone (current_time) so transitions don't fire a day
		// early/late near midnight relative to the server tz.
		$today   = ( null !== $today && '' !== $today ) ? $today : current_time( 'Y-m-d' );
		$cursos  = ANPA_Socios_DB::tabela_cursos();
		$summary = array(
			'closed'    => array(),
			'created'   => array(),
			'activated' => array(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only lifecycle scan.
		$rows = $wpdb->get_results( "SELECT id, curso_escolar, estado, data_inicio, data_peche FROM {$cursos}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $summary;
		}

		// Pass 1: close finished courses and create the following course.
		foreach ( $rows as $row ) {
			$curso  = (string) $row['curso_escolar'];
			$estado = (string) $row['estado'];
			$peche  = (string) ( $row['data_peche'] ?? '' );
			if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
				continue;
			}
			if ( ! ANPA_Socios_Season::should_close( $today, $estado, $peche ) ) {
				continue;
			}

			$rollover = ANPA_Socios_Curso_Lifecycle::season_rollover( $curso );
			$wpdb->update(
				$cursos,
				array(
					'estado'              => $rollover['current_estado'],
					'matriculas_abertas' => $rollover['current_matriculas_abertas'] ? 1 : 0,
					'actualizado_en'      => current_time( 'mysql' ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			$summary['closed'][] = $curso;

			$next        = $rollover['next_curso'];
			$next_inicio = ANPA_Socios_Season::default_data_inicio( $next );
			$next_peche  = ANPA_Socios_Season::default_data_peche( $next );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent next-course creation (unique key guards duplicates).
			$affected = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$cursos} (curso_escolar, matriculas_abertas, estado, data_inicio, data_peche) VALUES (%s, %d, %s, %s, %s)",
					$next,
					$rollover['next_matriculas_abertas'] ? 1 : 0,
					$rollover['next_estado'],
					$next_inicio,
					$next_peche
				)
			);
			if ( (int) $affected > 0 ) {
				$summary['created'][] = $next;
			}
		}

		// Pass 2 (fase34): detect trimesters that reached their operative close
		// date on the ACTIVE course and raise a persistent, idempotent notice.
		// This NEVER changes any trimester/window/course state — the junta must
		// apply the transition manually from Axustes → Cursos.
		$summary['trimestre_avisos'] = self::detect_trimestre_ends( $today );

		return $summary;
	}

	/**
	 * Detects reached operative trimester-close dates for the active course and
	 * persists a one-shot notice flag per trimester. Idempotent: an already
	 * flagged trimester is not re-added. Only trimesters still `activo` qualify
	 * (a pechado trimester was already managed, so no notice is needed).
	 *
	 * @param  string $today Y-m-d "today".
	 * @return array<int,array{curso:string,trimestre:int,data:string}> Newly flagged.
	 */
	private static function detect_trimestre_ends( string $today ): array {
		global $wpdb;

		$new    = array();
		$active = ANPA_Socios_Curso_Activo::get();
		if ( null === $active || ! ANPA_Socios_Curso_Escolar::is_valid( (string) $active ) ) {
			return $new;
		}
		$active = (string) $active;
		$cursos = ANPA_Socios_DB::tabela_cursos();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only single-row lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t1_peche_operativo, t2_peche_operativo FROM {$cursos} WHERE curso_escolar = %s",
				$active
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return $new;
		}

		$datas = array(
			't1' => (string) ( $row['t1_peche_operativo'] ?? '' ),
			't2' => (string) ( $row['t2_peche_operativo'] ?? '' ),
		);
		$reached = ANPA_Socios_Calendario::trimestres_operativos_alcanzados( $today, $datas );
		if ( array() === $reached ) {
			return $new;
		}

		$estados = ANPA_Socios_Trimestre_Repo::for_curso( $active );
		$avisos  = self::get_avisos();

		foreach ( $reached as $tri ) {
			$key = $active . ':' . $tri;
			if ( isset( $avisos[ $key ] ) ) {
				continue; // Already announced — idempotent.
			}
			$estado = (string) ( $estados[ $tri ]['estado'] ?? '' );
			if ( ANPA_Socios_Trimestre_Estado::ACTIVO !== $estado ) {
				continue; // Already managed (pechado) — no notice needed.
			}
			$data          = 1 === $tri ? $datas['t1'] : $datas['t2'];
			$avisos[ $key ] = array(
				'curso'     => $active,
				'trimestre' => $tri,
				'data'      => $data,
				'creado'    => current_time( 'mysql' ),
			);
			$new[] = array( 'curso' => $active, 'trimestre' => $tri, 'data' => $data );
		}

		if ( array() !== $new ) {
			update_option( self::AVISOS_OPTION, $avisos, false );
		}

		return $new;
	}

	/**
	 * Returns the persistent trimester-end notices, pruning malformed entries.
	 *
	 * @return array<string,array{curso:string,trimestre:int,data:string,creado:string}>
	 */
	public static function get_avisos(): array {
		$raw = get_option( self::AVISOS_OPTION, array() );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Clears the persistent notice for a managed trimester (called after a
	 * manual transition). No-op when the flag is absent.
	 *
	 * @param  string $curso     Curso escolar.
	 * @param  int    $trimestre 1..3.
	 * @return void
	 */
	public static function clear_aviso( string $curso, int $trimestre ): void {
		$avisos = self::get_avisos();
		$key    = $curso . ':' . $trimestre;
		if ( ! isset( $avisos[ $key ] ) ) {
			return;
		}
		unset( $avisos[ $key ] );
		update_option( self::AVISOS_OPTION, $avisos, false );
	}

	/**
	 * admin_notices callback: shows a persistent notice for each trimester that
	 * reached its operative close date but is still `activo` (pending manual
	 * management). Links to the Cursos settings section. Read-only.
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$avisos = self::get_avisos();
		if ( array() === $avisos ) {
			return;
		}

		$url = admin_url( 'admin.php?page=anpa-socios-settings&tab=cursos&section=curso-escolar' );
		foreach ( $avisos as $aviso ) {
			$curso = (string) ( $aviso['curso'] ?? '' );
			$tri   = (int) ( $aviso['trimestre'] ?? 0 );
			$data  = (string) ( $aviso['data'] ?? '' );
			if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) || $tri < 1 ) {
				continue;
			}
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html( sprintf(
					/* translators: 1: trimester number, 2: course */
					__( 'Fin do %1$dº trimestre alcanzado (curso %2$s).', 'anpa-socios' ),
					$tri,
					$curso
				) ),
				esc_html( sprintf(
					/* translators: %s: operative close date */
					__( 'Chegou a data operativa (%s). Xestiona a transición manualmente cando queiras:', 'anpa-socios' ),
					$data
				) ),
				esc_url( $url ),
				esc_html__( 'ir a Cursos', 'anpa-socios' )
			);
		}
	}

	/**
	 * Returns the current course row (season fields), fail-open with computed
	 * defaults when no row exists so the site is never left unusable.
	 *
	 * @return array{curso_escolar:string,estado:string,data_inicio:string,data_peche:string}
	 */
	public static function current_course_row(): array {
		global $wpdb;

		$curso  = ANPA_Socios_Curso_Activo::get();
		$cursos = ANPA_Socios_DB::tabela_cursos();
		if ( null === $curso ) {
			$curso = ANPA_Socios_Curso_Escolar::current();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only operational-course lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT curso_escolar, estado, data_inicio, data_peche FROM {$cursos} WHERE curso_escolar = %s", $curso ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array(
				'curso_escolar' => $curso,
				'estado'        => ANPA_Socios_Season::ESTADO_PENDENTE,
				'data_inicio'   => ANPA_Socios_Season::default_data_inicio( $curso ),
				'data_peche'    => ANPA_Socios_Season::default_data_peche( $curso ),
			);
		}

		return array(
			'curso_escolar' => (string) $row['curso_escolar'],
			'estado'        => (string) ( $row['estado'] ?? ANPA_Socios_Season::ESTADO_PENDENTE ),
			'data_inicio'   => (string) ( $row['data_inicio'] ?? '' ),
			'data_peche'    => (string) ( $row['data_peche'] ?? '' ),
		);
	}

	/**
	 * Whether the current course is in the pre-season (`pendente`) state.
	 *
	 * @return bool
	 */
	public static function is_preseason(): bool {
		$row = self::current_course_row();

		return ANPA_Socios_Season::ESTADO_PENDENTE === (string) $row['estado'];
	}
}
