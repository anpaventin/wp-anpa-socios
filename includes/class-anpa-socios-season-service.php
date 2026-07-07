<?php
/**
 * Course season lifecycle service (fase12).
 *
 * WordPress glue around the pure ANPA_Socios_Season helper:
 *   - a daily cron that closes finished courses, creates the next course as
 *     `pendente`, and activates pending courses on their start date;
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
	 * Runs the season check: close finished courses, create the next course as
	 * `pendente`, and activate pending courses whose start date has arrived.
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

			$wpdb->update(
				$cursos,
				array(
					'estado'         => ANPA_Socios_Season::ESTADO_PECHADO,
					'actualizado_en' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$summary['closed'][] = $curso;

			$next        = ANPA_Socios_Season::next_curso( $curso );
			$next_inicio = ANPA_Socios_Season::default_data_inicio( $next );
			$next_peche  = ANPA_Socios_Season::default_data_peche( $next );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent next-course creation (unique key guards duplicates).
			$affected = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$cursos} (curso_escolar, matriculas_abertas, estado, data_inicio, data_peche) VALUES (%s, 1, %s, %s, %s)",
					$next,
					ANPA_Socios_Season::ESTADO_PENDENTE,
					$next_inicio,
					$next_peche
				)
			);
			if ( (int) $affected > 0 ) {
				$summary['created'][] = $next;
			}
		}

		// Pass 2: activate pending courses whose start date has arrived (re-read
		// so a course created in pass 1 with a past start date also activates).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only lifecycle scan.
		$rows = $wpdb->get_results( "SELECT id, curso_escolar, estado, data_inicio FROM {$cursos}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $summary;
		}
		foreach ( $rows as $row ) {
			$estado = (string) $row['estado'];
			$inicio = (string) ( $row['data_inicio'] ?? '' );
			if ( ! ANPA_Socios_Season::should_activate( $today, $estado, $inicio ) ) {
				continue;
			}
			$wpdb->update(
				$cursos,
				array(
					'estado'         => ANPA_Socios_Season::ESTADO_ACTIVO,
					'actualizado_en' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$summary['activated'][] = (string) $row['curso_escolar'];
		}

		return $summary;
	}

	/**
	 * Returns the current course row (season fields), fail-open with computed
	 * defaults when no row exists so the site is never left unusable.
	 *
	 * @return array{curso_escolar:string,estado:string,data_inicio:string,data_peche:string}
	 */
	public static function current_course_row(): array {
		global $wpdb;

		$curso  = ANPA_Socios_Curso_Escolar::current();
		$cursos = ANPA_Socios_DB::tabela_cursos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only current-course lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT curso_escolar, estado, data_inicio, data_peche FROM {$cursos} WHERE curso_escolar = %s", $curso ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array(
				'curso_escolar' => $curso,
				'estado'        => ANPA_Socios_Season::ESTADO_ACTIVO,
				'data_inicio'   => ANPA_Socios_Season::default_data_inicio( $curso ),
				'data_peche'    => ANPA_Socios_Season::default_data_peche( $curso ),
			);
		}

		return array(
			'curso_escolar' => (string) $row['curso_escolar'],
			'estado'        => (string) ( $row['estado'] ?? ANPA_Socios_Season::ESTADO_ACTIVO ),
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
