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
		// 1.68.0: enrolment requests made while the trimester window was closed.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matriculas/pendentes', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_pendentes' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matricula/(?P<id>\d+)/aprobar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'aprobar_rest' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matricula/(?P<id>\d+)/rexeitar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rexeitar_rest' ),
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
		$valid_estados = ANPA_Socios_Matricula_Estado::TODOS;
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

		// Enrich each row with the «Curso/Aula» label and the computed trimester range.
		// The label is built in PHP (1.56.3): keep this SQL pure ASCII — see ANPA_Socios_Admin_Shared::curso_completo().
		foreach ( $rows as &$row ) {
			$row['curso_completo'] = ANPA_Socios_Admin_Shared::curso_completo( $row['curso'] ?? null, $row['aula'] ?? null );
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

	// ──────────────────────────────────────────────
	// 1.68.0: enrolment requests pending the junta's approval
	// ──────────────────────────────────────────────

	/**
	 * GET /admin/matriculas/pendentes — requests made while the trimester
	 * window was closed (estado = pendente_aprobacion), oldest first, with the
	 * group's occupancy so the junta can see where each one would land.
	 * Pure ASCII SQL; the «Curso/Aula» label is built in PHP (1.56.3).
	 *
	 * @since  1.68.0
	 * @return WP_REST_Response
	 */
	public static function list_pendentes(): WP_REST_Response {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$act_t = ANPA_Socios_DB::tabela_actividades();
		$fc_t  = ANPA_Socios_DB::tabela_fillos_cursos();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin listing.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.trimestre, m.creado_en AS solicitada_en,
				        f.id AS fillo_id, f.nome AS fillo_nome, f.apelidos AS fillo_apelidos, f.socio_email,
				        COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula) AS aula,
				        a.nome AS actividade, g.id AS grupo_id, g.nome AS grupo, g.franxa, g.dias, g.horario, g.curso_escolar,
				        g.estado AS grupo_estado, g.max_pupilos,
				        ( SELECT COUNT(*) FROM {$mat_t} m2 WHERE m2.grupo_id = g.id AND m2.estado = 'activo' ) AS activos,
				        ( SELECT COUNT(*) FROM {$mat_t} m3 WHERE m3.grupo_id = g.id AND m3.estado = 'lista_espera' ) AS espera
				 FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 INNER JOIN {$act_t} a ON a.id = m.activitad_id
				 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
				 LEFT JOIN {$fc_t} fc ON fc.fillo_id = f.id AND fc.curso_escolar = g.curso_escolar
				 WHERE m.estado = %s
				 ORDER BY m.creado_en ASC, m.id ASC",
				ANPA_Socios_Matricula_Estado::PENDENTE_APROBACION
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$row['curso_completo'] = ANPA_Socios_Admin_Shared::curso_completo( $row['curso'] ?? null, $row['aula'] ?? null );
			$row['destino']        = ANPA_Socios_Matricula_Estado::destino_aprobacion( (string) $row['grupo_estado'], (int) $row['activos'], (int) $row['max_pupilos'] );
		}
		unset( $row );

		return new WP_REST_Response( array( 'matriculas' => $rows ), 200 );
	}

	/**
	 * POST /admin/matricula/<id>/aprobar
	 *
	 * @since  1.68.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function aprobar_rest( WP_REST_Request $request ) {
		$r = self::aprobar( (int) $request->get_param( 'id' ), (string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_EMAIL ), (string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_ROL ) );
		return is_wp_error( $r ) ? $r : new WP_REST_Response( $r, 200 );
	}

	/**
	 * POST /admin/matricula/<id>/rexeitar
	 *
	 * @since  1.68.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rexeitar_rest( WP_REST_Request $request ) {
		$r = self::rexeitar( (int) $request->get_param( 'id' ), (string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_EMAIL ), (string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_ROL ) );
		return is_wp_error( $r ) ? $r : new WP_REST_Response( $r, 200 );
	}

	/**
	 * Approves a pending request: a place when the group is open and below its
	 * maximum (counting EVERY active enrolment of the group, not only the
	 * trimester's), the waiting list otherwise. The enrolment's trimester
	 * becomes the current one (operative dates). Emails every active member of
	 * the family. Reused by the trimester activation (Xestión → Matrículas).
	 *
	 * @since  1.68.0
	 * @param  int    $id         Matrícula id.
	 * @param  string $actor      Actor email for the audit row.
	 * @param  string $actor_tipo Actor type for the audit row.
	 * @return array{id:int,estado:string,posicion:int,correos:int}|WP_Error
	 */
	public static function aprobar( int $id, string $actor, string $actor_tipo ) {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$wpdb->last_error = '';
		$mat = $wpdb->get_row( $wpdb->prepare( "SELECT id, fillo_id, activitad_id, grupo_id, estado FROM {$mat_t} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $mat ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_matricula_not_found', __( 'Matrícula non atopada', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( ANPA_Socios_Matricula_Estado::PENDENTE_APROBACION !== (string) $mat['estado'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_no_pendente', __( 'Esta matrícula non está pendente de aprobación', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$grupo = $wpdb->get_row( $wpdb->prepare( "SELECT id, curso_escolar, estado, max_pupilos FROM {$gru_t} WHERE id = %d FOR UPDATE", (int) $mat['grupo_id'] ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $grupo ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'O grupo desta matrícula xa non existe', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$activos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mat_t} WHERE grupo_id = %d AND estado = 'activo'", (int) $grupo['id'] ) );
		$destino = ANPA_Socios_Matricula_Estado::destino_aprobacion( (string) $grupo['estado'], $activos, (int) $grupo['max_pupilos'] );

		// Trimester = the current one by the course's operative dates (same rule as the gate).
		$curso_row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . ANPA_Socios_Matricula_Gate_Repo::CURSO_COLUMNS . ' FROM ' . ANPA_Socios_DB::tabela_cursos() . ' WHERE curso_escolar = %s', (string) $grupo['curso_escolar'] ), ARRAY_A );
		$trimestre = ANPA_Socios_Trimestre::actual_por_datas( is_array( $curso_row ) ? ANPA_Socios_Matricula_Gate::datas_de_fila( $curso_row ) : null );
		$posicion  = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mat_t} WHERE activitad_id = %d AND trimestre = %d AND id <> %d", (int) $mat['activitad_id'], $trimestre, $id ) );

		$updated = $wpdb->update(
			$mat_t,
			array( 'estado' => $destino, 'posicion' => $posicion, 'trimestre' => $trimestre, 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => $id, 'estado' => ANPA_Socios_Matricula_Estado::PENDENTE_APROBACION ),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( $actor, $actor_tipo, 'matricula', (string) $id, 'aprobada_' . ( ANPA_Socios_Matricula_Estado::ACTIVO === $destino ? 'praza' : 'espera' ) );

		$correos = 0;
		$detalle = self::detalle_para_correo( $id );
		if ( is_array( $detalle ) ) {
			foreach ( $detalle['emails'] as $email ) {
				$ok = ANPA_Socios_Matricula_Estado::ACTIVO === $destino
					? ANPA_Socios_Email::enviar_matricula_aprobada_praza( $email, $detalle['alumno'], $detalle['actividade'], $detalle['grupo'] )
					: ANPA_Socios_Email::enviar_matricula_aprobada_espera( $email, $detalle['alumno'], $detalle['actividade'], $detalle['grupo'], $posicion );
				if ( $ok ) {
					++$correos;
				}
			}
		}

		return array( 'id' => $id, 'estado' => $destino, 'posicion' => $posicion, 'correos' => $correos );
	}

	/**
	 * Rejects a pending request: estado = baixa (today) + email to the family.
	 *
	 * @since  1.68.0
	 * @param  int    $id         Matrícula id.
	 * @param  string $actor      Actor email.
	 * @param  string $actor_tipo Actor type.
	 * @return array{id:int,estado:string,correos:int}|WP_Error
	 */
	public static function rexeitar( int $id, string $actor, string $actor_tipo ) {
		global $wpdb;
		$mat_t   = ANPA_Socios_DB::tabela_matriculas();
		$detalle = self::detalle_para_correo( $id );
		$updated = $wpdb->update(
			$mat_t,
			array( 'estado' => ANPA_Socios_Matricula_Estado::BAIXA, 'baixa_en' => current_time( 'mysql' ), 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => $id, 'estado' => ANPA_Socios_Matricula_Estado::PENDENTE_APROBACION ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'anpa_admin_no_pendente', __( 'Esta matrícula non está pendente de aprobación', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( $actor, $actor_tipo, 'matricula', (string) $id, 'matricula_rexeitada' );

		$correos = 0;
		if ( is_array( $detalle ) ) {
			foreach ( $detalle['emails'] as $email ) {
				if ( ANPA_Socios_Email::enviar_matricula_rexeitada( $email, $detalle['alumno'], $detalle['actividade'] ) ) {
					++$correos;
				}
			}
		}
		return array( 'id' => $id, 'estado' => ANPA_Socios_Matricula_Estado::BAIXA, 'correos' => $correos );
	}

	/**
	 * Pupil, activity, group label and the family's active addresses (both
	 * parents) for the approval/rejection emails.
	 *
	 * @since  1.68.0
	 * @param  int $id Matrícula id.
	 * @return array{alumno:string,actividade:string,grupo:string,emails:array<int,string>}|null
	 */
	public static function detalle_para_correo( int $id ): ?array {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$act_t = ANPA_Socios_DB::tabela_actividades();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only lookup for the email.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.id AS fillo_id, f.nome AS fillo_nome, f.apelidos AS fillo_apelidos, a.nome AS actividade,
				        COALESCE(g.nome, '') AS grupo_nome, COALESCE(g.horario, '') AS horario, COALESCE(g.franxa, '') AS franxa, COALESCE(g.dias, '') AS dias
				 FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 INNER JOIN {$act_t} a ON a.id = m.activitad_id
				 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
				 WHERE m.id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'alumno'     => trim( (string) $row['fillo_nome'] . ' ' . (string) $row['fillo_apelidos'] ),
			'actividade' => (string) $row['actividade'],
			'grupo'      => self::grupo_label( (string) $row['grupo_nome'], (string) $row['horario'], (string) $row['franxa'], (string) $row['dias'] ),
			'emails'     => self::familia_emails_por_fillo( (int) $row['fillo_id'] ),
		);
	}

	/**
	 * Every active member of the pupil's family unit (falls back to the
	 * pupil's socio_email when the family has no id yet).
	 *
	 * @since  1.68.0
	 * @param  int $fillo_id Pupil id.
	 * @return array<int,string>
	 */
	public static function familia_emails_por_fillo( int $fillo_id ): array {
		global $wpdb;
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$soc_t = ANPA_Socios_DB::tabela_socios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only recipients lookup.
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT s.email FROM {$soc_t} s INNER JOIN {$fil_t} f ON f.familia_id = s.familia_id
			 WHERE f.id = %d AND f.familia_id > 0 AND s.estado = 'activo' AND s.email <> ''",
			$fillo_id
		) );
		$emails = is_array( $rows ) ? array_map( 'strval', $rows ) : array();
		if ( array() === $emails ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only fallback.
			$one = (string) $wpdb->get_var( $wpdb->prepare( "SELECT socio_email FROM {$fil_t} WHERE id = %d", $fillo_id ) );
			if ( '' !== $one ) {
				$emails[] = $one;
			}
		}
		return array_values( array_unique( array_map( 'strtolower', $emails ) ) );
	}

	/**
	 * «Grupo A · Tarde 16:00-17:00 · Luns, Mércores» for the emails.
	 *
	 * @since  1.68.0
	 * @return string
	 */
	public static function grupo_label( string $nome, string $horario, string $franxa, string $dias ): string {
		$labels = array( 'luns' => 'Luns', 'martes' => 'Martes', 'mercores' => 'Mércores', 'xoves' => 'Xoves', 'venres' => 'Venres' );
		$dias_l = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $dias ) ) ) as $d ) {
			$dias_l[] = $labels[ $d ] ?? $d;
		}
		$parts = array_filter( array(
			$nome,
			trim( ANPA_Socios_Grupo_Serie::horario_label( $horario ) . ' ' . $franxa ),
			implode( ', ', $dias_l ),
		) );
		return implode( ' · ', $parts );
	}
}
