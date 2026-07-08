<?php
/**
 * Admin REST handler for the matriculas domain.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/matricula*` endpoints.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_Matriculas_Handler {

	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)/matriculas', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_matriculas' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matriculas', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_matriculas_by_curso' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_matricula' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matricula/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_matricula' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	public static function list_matriculas( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$id   = (int) $request->get_param( 'id' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, fillo_id, activitad_id, estado, comedor, tarde, observaciones FROM {$wpdb->prefix}anpa_matriculas WHERE activitad_id = %d ORDER BY id ASC",
				$id
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * GET /admin/matriculas?curso=YYYY/YYYY — all enrolments for a course.
	 *
	 * @since  1.10.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_matriculas_by_curso( WP_REST_Request $request ) {
		global $wpdb;

		$curso = (string) $request->get_param( 'curso' );
		if ( '' === $curso ) {
			$curso = ANPA_Socios_Curso_Escolar::current();
		}
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return new WP_Error( 'anpa_admin_curso_invalid', __( 'Curso escolar inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$act_t = ANPA_Socios_DB::tabela_actividades();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.estado, m.posicion, m.trimestre, m.grupo_id,
				        m.autorizacion_comedor, m.tarde_transicion, m.tardes_divertidas_continua,
				        m.recollida_autorizada, m.cesion_datos_empresa,
				        f.id AS fillo_id, f.nome AS fillo_nome, f.apelidos AS fillo_apelidos,
				        COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula) AS aula,
				        CONCAT(COALESCE(fc.curso, f.curso), 'º', COALESCE(fc.aula, f.aula)) AS curso_completo,
				        a.id AS actividade_id, a.nome AS actividade, g.curso_escolar, g.franxa,
				        g.curso_range, g.dias
				 FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 INNER JOIN {$act_t} a ON a.id = m.activitad_id
				 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
				 LEFT JOIN {$wpdb->prefix}anpa_fillos_cursos fc ON fc.fillo_id = f.id AND fc.curso_escolar = g.curso_escolar
				 WHERE g.curso_escolar = %s
				 ORDER BY a.nome ASC, g.curso_range ASC, f.apelidos ASC, f.nome ASC, m.id ASC",
				$curso
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	public static function create_matricula( WP_REST_Request $request ) {
		global $wpdb;

		$payload = ANPA_Socios_Admin_Payload::validar_matricula( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'anpa_matriculas',
			$payload,
			array( '%d', '%d', '%s', '%d', '%d', '%s' )
		);
		if ( false === $inserted ) {
			$code = (string) $wpdb->last_error;
			if ( false !== strpos( $code, '1062' ) ) {
				return new WP_Error( 'anpa_admin_already_enrolled', __( 'Xa matriculado nesta actividade', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $wpdb->insert_id, 'create' );

		return new WP_REST_Response( $payload + array( 'id' => $wpdb->insert_id ), 201 );
	}

	public static function delete_matricula( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$mat_t = $wpdb->prefix . 'anpa_matriculas';

		// Capture the group/trimester/state BEFORE the change so we can promote
		// the waitlist when an ACTIVE seat is freed (parity with confirm_baixa).
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT grupo_id, trimestre, estado FROM {$mat_t} WHERE id = %d", $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$updated = $wpdb->update(
			$mat_t,
			array(
				'estado'         => 'baixa',
				'actualizado_en' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $id, 'delete' );

		// Freeing an active seat: offer it to the next waitlisted pupil.
		if ( 'activo' === (string) $row['estado'] && ! empty( $row['grupo_id'] ) && class_exists( 'ANPA_Socios_Extraescolar_Offers' ) ) {
			ANPA_Socios_Extraescolar_Offers::offer_next( (int) $row['grupo_id'], (int) $row['trimestre'] );
		}

		return new WP_REST_Response( null, 204 );
	}
}
