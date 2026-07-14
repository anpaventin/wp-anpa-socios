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
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matriculas/sen-grupo', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_matriculas_sen_grupo' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/actividad/<id>/matriculas — enriched listing for the
	 * actividade editor. Filterable by curso_escolar and estado. Returns
	 * year-scoped nivel/aula (not the current-year mirror), grupo details,
	 * and pagination metadata. NO IBAN/NIF/banking data.
	 *
	 * @since  1.27.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_matriculas( WP_REST_Request $request ) {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		$where  = array( "m.activitad_id = %d" );
		$params = array( $id );

		// Optional curso_escolar filter.
		$curso_filter = $request->get_param( 'curso_escolar' );
		if ( $curso_filter && ANPA_Socios_Curso_Escolar::is_valid( (string) $curso_filter ) ) {
			$where[]  = "g.curso_escolar = %s";
			$params[] = (string) $curso_filter;
		}

		// Optional estado filter.
		$estado_filter = $request->get_param( 'estado' );
		$valid_estados = array( 'activo', 'lista_espera', 'oferta', 'baixa_solicitada', 'baixa' );
		if ( $estado_filter && in_array( $estado_filter, $valid_estados, true ) ) {
			$where[]  = "m.estado = %s";
			$params[] = $estado_filter;
		}

		// Pagination.
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 200, max( 10, (int) ( $request->get_param( 'per_page' ) ?? 50 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		// Total count for pagination metadata.
		$count_sql = "SELECT COUNT(*) FROM {$mat_t} m LEFT JOIN {$gru_t} g ON g.id = m.grupo_id WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin-only paginated listing.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.estado, m.trimestre, m.creado_en,
				        f.nome AS fillo_nome, f.apelidos AS fillo_apelidos,
				        COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula) AS aula,
				        g.curso_escolar, g.franxa, g.curso_range AS grupo_range, g.dias AS grupo_dias
				 FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
				 LEFT JOIN {$wpdb->prefix}anpa_fillos_cursos fc ON fc.fillo_id = f.id AND fc.curso_escolar = g.curso_escolar
				 WHERE {$where_sql}
				 ORDER BY g.curso_escolar DESC, f.apelidos ASC, f.nome ASC, m.id ASC
				 LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$response = new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );

		return $response;
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
			$curso = (string) ( ANPA_Socios_Curso_Activo::get() ?? '' );
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
				"SELECT m.id, m.estado, m.posicion, m.trimestre, m.grupo_id, m.creado_en, m.baixa_en,
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

		$rows = is_array( $rows ) ? $rows : array();

		// Enrich each row with computed trimester range.
		foreach ( $rows as &$row ) {
			$tri_alta  = ANPA_Socios_Trimestre::actual( (int) gmdate( 'n', strtotime( (string) $row['creado_en'] ) ) );
			$tri_baixa = ! empty( $row['baixa_en'] )
				? ANPA_Socios_Trimestre::actual( (int) gmdate( 'n', strtotime( (string) $row['baixa_en'] ) ) )
				: null;
			$row['trimestres'] = implode( ' ', ANPA_Socios_Trimestre::rango( $tri_alta, $tri_baixa ) );
		}
		unset( $row );

		return new WP_REST_Response( $rows, 200 );
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

	/**
	 * GET /admin/matriculas/sen-grupo — legacy enrolments without a grupo assignment.
	 *
	 * Informational only: surfaces matriculas where grupo_id IS NULL so an admin
	 * can assess backfill or manual reassignment. Never auto-assigns a grupo.
	 * No banking/IBAN data exposed.
	 *
	 * @since  1.27.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_matriculas_sen_grupo( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$act_t = ANPA_Socios_DB::tabela_actividades();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin-only diagnostic report.
		$rows = $wpdb->get_results(
			"SELECT m.id, m.estado, m.trimestre, m.creado_en,
			        f.nome AS fillo_nome, f.apelidos AS fillo_apelidos,
			        a.nome AS actividade_nome
			 FROM {$mat_t} m
			 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
			 INNER JOIN {$act_t} a ON a.id = m.activitad_id
			 WHERE m.grupo_id IS NULL
			 ORDER BY m.creado_en DESC",
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
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
				'baixa_en'       => current_time( 'mysql' ),
				'actualizado_en' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
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
