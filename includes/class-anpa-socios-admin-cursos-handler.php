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
		$acy_t    = ANPA_Socios_DB::tabela_actividades_cursos();
		$suggested = ANPA_Socios_Curso_Escolar::current();
		$active    = ANPA_Socios_Curso_Activo::get();

		// Ensure the date-derived course is available only as a candidate. Never
		// open enrolments or activate it implicitly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent selector seed.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$cursos_t} (curso_escolar, matriculas_abertas, estado) VALUES (%s, 0, 'pendente')", $suggested ) );

		// Seed course rows discovered in activities as inactive candidates.
		$activity_courses = $wpdb->get_col(
			"SELECT curso_escolar FROM {$acy_t} WHERE curso_escolar <> ''
			 UNION SELECT curso_escolar FROM {$acts_t} WHERE curso_escolar <> ''"
		);
		if ( is_array( $activity_courses ) ) {
			foreach ( $activity_courses as $curso ) {
				$curso = (string) $curso;
				if ( ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent selector seed.
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$cursos_t} (curso_escolar, matriculas_abertas, estado) VALUES (%s, 0, 'pendente')", $curso ) );
				}
			}
		}

		$rows = $wpdb->get_results(
			"SELECT curso_escolar, matriculas_abertas, estado, data_inicio, data_peche, actualizado_en FROM {$cursos_t} ORDER BY curso_escolar DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$row['matriculas_abertas'] = (bool) (int) $row['matriculas_abertas'];
			$row['actual']             = ( $row['curso_escolar'] === $active );
		}
		unset( $row );

		return new WP_REST_Response( array(
			'current'   => $active,
			'suggested' => $suggested,
			'cursos'    => $rows,
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
			return new WP_Error( 'anpa_admin_curso_invalid', __( 'Curso escolar inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$table   = ANPA_Socios_DB::tabela_cursos();
		$estado  = isset( $body['estado'] ) ? sanitize_key( (string) $body['estado'] ) : 'activo';
		$open    = ! empty( $body['matriculas_abertas'] );
		$replace = ! empty( $body['replace_active'] );
		if ( ! in_array( $estado, array( 'pendente', 'activo', 'pechado' ), true ) ) {
			return new WP_Error( 'anpa_admin_curso_estado_invalid', __( 'Estado do curso inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$wpdb->query( 'START TRANSACTION' );
		// Serialize lifecycle writes and inspect every active row under lock.
		$locked_rows = $wpdb->get_results( "SELECT curso_escolar, estado FROM {$table} FOR UPDATE", ARRAY_A );
		$other_active = null;
		foreach ( is_array( $locked_rows ) ? $locked_rows : array() as $locked_row ) {
			if ( 'activo' === (string) $locked_row['estado'] && $curso !== (string) $locked_row['curso_escolar'] ) {
				$other_active = (string) $locked_row['curso_escolar'];
				break;
			}
		}
		$current_target_active = false;
		foreach ( is_array( $locked_rows ) ? $locked_rows : array() as $locked_row ) {
			if ( $curso === (string) $locked_row['curso_escolar'] && 'activo' === (string) $locked_row['estado'] ) {
				$current_target_active = true;
				break;
			}
		}
		$active = null !== $other_active ? $other_active : ( $current_target_active ? $curso : null );
		$plan   = ANPA_Socios_Curso_Lifecycle::plan( $curso, $estado, $open, $active, $replace );

		if ( ! $plan['allowed'] ) {
			$wpdb->query( 'ROLLBACK' );
			$message = 'active_course_conflict' === $plan['error']
				? __( 'Xa existe outro curso activo. Confirma que queres desactivalo antes de activar este.', 'anpa-socios' )
				: __( 'Só se poden abrir as matrículas do curso activo.', 'anpa-socios' );
			return new WP_Error( 'anpa_admin_' . $plan['error'], $message, array( 'status' => 409, 'active_course' => $active ) );
		}

		$now    = current_time( 'mysql' );
		$inicio = ANPA_Socios_Season::default_data_inicio( $curso );
		$peche  = ANPA_Socios_Season::default_data_peche( $curso );

		if ( null !== $plan['deactivate'] ) {
			$closed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET estado = 'pechado', matriculas_abertas = 0, actualizado_en = %s WHERE estado = 'activo' AND curso_escolar <> %s",
					$now,
					$curso
				)
			);
			if ( false === $closed ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
		}

		$done = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (curso_escolar, matriculas_abertas, estado, data_inicio, data_peche, actualizado_en)
				 VALUES (%s, %d, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE matriculas_abertas = VALUES(matriculas_abertas), estado = VALUES(estado), actualizado_en = VALUES(actualizado_en)",
				$curso,
				$plan['target_open'] ? 1 : 0,
				$plan['target_estado'],
				$inicio,
				$peche,
				$now
			)
		);
		if ( false === $done ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );

		$action = 'activo' === $plan['target_estado'] ? ( $plan['target_open'] ? 'activar_abrir' : 'activar_pechado' ) : 'desactivar_pechar';
		ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, $action );

		return new WP_REST_Response( array(
			'curso_escolar'       => $curso,
			'estado'              => $plan['target_estado'],
			'matriculas_abertas' => $plan['target_open'],
			'previous_active'     => $plan['deactivate'],
			'actualizado_en'      => $now,
		), 200 );
	}
}
