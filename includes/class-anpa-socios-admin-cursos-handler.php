<?php
/**
 * Admin REST handler for curso lifecycle (fase10).
 *
 * @since  1.10.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for course open/close state.
 *
 * @since 1.10.0
 */
final class ANPA_Socios_Admin_Cursos_Handler {

	/**
	 * Registers cursos admin routes.
	 *
	 * @since  1.10.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/cursos', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_cursos' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/curso', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'update_curso' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/cursos.
	 *
	 * @since  1.10.0
	 * @return WP_REST_Response
	 */
	public static function list_cursos(): WP_REST_Response {
		global $wpdb;

		$cursos_t = ANPA_Socios_DB::tabela_cursos();
		$acts_t   = ANPA_Socios_DB::tabela_actividades();
		$current  = ANPA_Socios_Curso_Escolar::current();

		// Ensure current course is always available for the selector.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent selector seed.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$cursos_t} (curso_escolar, matriculas_abertas) VALUES (%s, 1)", $current ) );

		// Seed course rows discovered in activities so older courses can be selected.
		$activity_courses = $wpdb->get_col( "SELECT DISTINCT curso_escolar FROM {$acts_t} WHERE curso_escolar <> ''" );
		if ( is_array( $activity_courses ) ) {
			foreach ( $activity_courses as $curso ) {
				$curso = (string) $curso;
				if ( ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent selector seed.
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$cursos_t} (curso_escolar, matriculas_abertas) VALUES (%s, 1)", $curso ) );
				}
			}
		}

		$rows = $wpdb->get_results(
			"SELECT curso_escolar, matriculas_abertas, actualizado_en FROM {$cursos_t} ORDER BY curso_escolar DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$row['matriculas_abertas'] = (bool) (int) $row['matriculas_abertas'];
			$row['actual']             = ( $row['curso_escolar'] === $current );
		}
		unset( $row );

		return new WP_REST_Response( array(
			'current' => $current,
			'cursos'  => $rows,
		), 200 );
	}

	/**
	 * PUT /admin/curso. Body: { curso_escolar, matriculas_abertas }.
	 *
	 * @since  1.10.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_curso( WP_REST_Request $request ) {
		global $wpdb;

		$body  = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso = isset( $body['curso_escolar'] ) ? (string) $body['curso_escolar'] : '';
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return new WP_Error( 'anpa_admin_curso_invalid', 'Curso escolar inválido', array( 'status' => 400 ) );
		}
		$open = ! empty( $body['matriculas_abertas'] ) ? 1 : 0;
		$table = ANPA_Socios_DB::tabela_cursos();

		$now = current_time( 'mysql' );

		// Seed season dates + lifecycle state on INSERT so the course can
		// auto-close/activate (should_close needs a non-empty data_peche).
		// On an existing row, dates/estado are preserved (only the gate changes).
		$inicio = ANPA_Socios_Season::default_data_inicio( $curso );
		$peche  = ANPA_Socios_Season::default_data_peche( $curso );
		$estado = ANPA_Socios_Season::estado_for( current_time( 'Y-m-d' ), $inicio, $peche );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- upsert of course gate.
		$done = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (curso_escolar, matriculas_abertas, estado, data_inicio, data_peche, actualizado_en)
				 VALUES (%s, %d, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE matriculas_abertas = VALUES(matriculas_abertas), actualizado_en = VALUES(actualizado_en)",
				$curso,
				$open,
				$estado,
				$inicio,
				$peche,
				$now
			)
		);
		if ( false === $done ) {
			return new WP_Error( 'anpa_admin_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, $open ? 'abrir' : 'pechar' );

		return new WP_REST_Response( array(
			'curso_escolar'       => $curso,
			'matriculas_abertas' => (bool) $open,
			'actualizado_en'      => $now,
		), 200 );
	}
}
