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
				"SELECT m.id, m.estado, CASE WHEN m.estado = 'lista_espera' THEN m.posicion ELSE NULL END AS posicion, m.trimestre, m.grupo_id, m.creado_en, m.baixa_en,
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

	/**
	 * POST /admin/matriculas — the junta enrols a pupil in a group.
	 *
	 * 1.93.0: the same checks as a family enrolment. The group must belong to the
	 * activity and to the active course and must not be disabled, and the pupil's
	 * level for that course must fit it. One live enrolment per pupil and activity.
	 * The capacity decides the result: a place, or the waiting list when the group
	 * is full or somebody is already waiting. Nothing is pending: the junta itself
	 * enrols.
	 *
	 * The admin must say whether emails go out: `notificar` is required
	 * (true = confirmation to the family and, for a running group, the notice to
	 * the company and the canteen; false = no email). Before 1.93.0 the row was
	 * inserted without a group, without a trimester and without any check.
	 *
	 * Body: { fillo_id, activitad_id, grupo_id, notificar, observaciones? }.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_matricula( WP_REST_Request $request ) {
		global $wpdb;

		$body    = ANPA_Socios_Admin_Shared::json_body( $request );
		$payload = ANPA_Socios_Admin_Payload::validar_matricula( $body );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$grupo_id = isset( $body['grupo_id'] ) ? (int) $body['grupo_id'] : 0;
		if ( $grupo_id <= 0 ) {
			return new WP_Error( 'anpa_admin_grupo_requirido', __( 'Indica o grupo (grupo_id) no que se matricula.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		if ( ! array_key_exists( 'notificar', $body ) || ! is_bool( $body['notificar'] ) ) {
			return new WP_Error( 'anpa_admin_notificar_requirido', __( 'Indica se se avisa por correo (notificar: true ou false): á familia e, se o grupo xa está en marcha, á empresa e ao comedor.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$notificar = (bool) $body['notificar'];
		$fillo_id  = (int) $payload['fillo_id'];
		$act_id    = (int) $payload['activitad_id'];
		$mat_t     = ANPA_Socios_DB::tabela_matriculas();
		$gru_t     = ANPA_Socios_DB::tabela_grupos();

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$fail = static function ( string $code, string $msg, int $status ) use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( $code, $msg, array( 'status' => $status ) );
		};
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- locked group row (capacity check).
		$grupo = $wpdb->get_row( $wpdb->prepare( "SELECT id, actividad_id, curso_escolar, estado, max_pupilos FROM {$gru_t} WHERE id = %d FOR UPDATE", $grupo_id ), ARRAY_A );
		if ( ! is_array( $grupo ) || (int) $grupo['actividad_id'] !== $act_id ) {
			return $fail( 'anpa_admin_grupo', __( 'O grupo non é desa actividade.', 'anpa-socios' ), 400 );
		}
		$curso = (string) $grupo['curso_escolar'];
		if ( ANPA_Socios_Curso_Activo::get() !== $curso ) {
			return $fail( 'anpa_admin_curso_pechado', __( 'O grupo non é do curso activo.', 'anpa-socios' ), 409 );
		}
		if ( ANPA_Socios_Grupo_Serie::ESTADO_DESHABILITADO === (string) $grupo['estado'] ) {
			return $fail( 'anpa_admin_grupo_deshabilitado', __( 'Este grupo está deshabilitado e non admite matrículas.', 'anpa-socios' ), 409 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only level of the pupil for that course.
		$nivel_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT nivel_id FROM ' . ANPA_Socios_DB::tabela_fillos_cursos() . ' WHERE fillo_id = %d AND curso_escolar = %s LIMIT 1', $fillo_id, $curso ) );
		if ( $nivel_id <= 0 ) {
			return $fail( 'anpa_fillo_sen_curso', __( 'Este alumno/a non ten curso asignado para o curso escolar actual.', 'anpa-socios' ), 409 );
		}
		if ( ! in_array( $nivel_id, ANPA_Socios_DB::get_niveis_for_grupo( $grupo_id ), true ) ) {
			return $fail( 'anpa_admin_curso', __( 'O curso do alumno/a non encaixa neste grupo.', 'anpa-socios' ), 409 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one live enrolment per pupil and activity.
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$mat_t} WHERE fillo_id = %d AND activitad_id = %d AND estado <> 'baixa'", $fillo_id, $act_id ) ) > 0 ) {
			return $fail( 'anpa_admin_already_enrolled', __( 'Xa matriculado nesta actividade', 'anpa-socios' ), 409 );
		}

		// Capacity: the same rule as a family enrolment and as aprobar().
		$destino = ANPA_Socios_Matricula_Estado::destino_aprobacion( (string) $grupo['estado'], ANPA_Socios_Lista_Espera::ocupadas( $grupo_id ), (int) $grupo['max_pupilos'] );
		if ( ANPA_Socios_Matricula_Estado::ACTIVO === $destino && ANPA_Socios_Lista_Espera::en_espera( $grupo_id ) > 0 ) {
			$destino = ANPA_Socios_Matricula_Estado::LISTA_ESPERA;
		}
		$posicion  = ANPA_Socios_Matricula_Estado::LISTA_ESPERA === $destino ? ANPA_Socios_Lista_Espera::seguinte_posicion( $grupo_id ) : null;
		$trimestre = ANPA_Socios_Matricula_Gate_Repo::trimestre_vixente( $curso );
		if ( $trimestre < 1 ) {
			return $fail( 'anpa_admin_trimestres', __( 'O curso non ten os trimestres inicializados (Xestión → Extraescolares → Matrículas).', 'anpa-socios' ), 409 );
		}
		$fields    = array(
			'grupo_id'             => $grupo_id,
			'estado'               => $destino,
			'posicion'             => $posicion,
			'baixa_en'             => null,
			'observaciones'        => (string) $payload['observaciones'],
			// 1.70.0: the data-sharing consent is mandatory to enrol, so it is always recorded as given.
			'cesion_datos_empresa' => 1,
			'oferta_token'         => null,
			'oferta_expira'        => null,
			'oferta_aceptada_en'   => null,
			'baixa_efectiva_en'    => null,
			'actualizado_en'       => current_time( 'mysql' ),
		);
		// A baixa row of the same pupil, activity and trimester (unique key) is reused.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only.
		$reuso = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$mat_t} WHERE fillo_id = %d AND activitad_id = %d AND trimestre = %d AND estado = 'baixa' LIMIT 1", $fillo_id, $act_id, $trimestre ) );
		if ( $reuso > 0 ) {
			$ok     = $wpdb->update( $mat_t, $fields, array( 'id' => $reuso ) );
			$new_id = $reuso;
			if ( false !== $ok ) {
				$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$mat_t} SET creado_en = NOW() WHERE id = %d", $reuso ) );
			}
		} else {
			$ok     = $wpdb->insert( $mat_t, array( 'fillo_id' => $fillo_id, 'activitad_id' => $act_id, 'trimestre' => $trimestre ) + $fields );
			$new_id = (int) $wpdb->insert_id;
		}
		if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( ANPA_Socios_Matricula_Estado::LISTA_ESPERA === $destino ) {
			ANPA_Socios_Lista_Espera::renumerar( $grupo_id );
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $new_id, 'create_' . $destino . ( $notificar ? '' : '_sen_correo' ) );

		$correos = 0;
		if ( $notificar ) {
			$correos = self::enviar_confirmacion( $new_id, $grupo_id, $destino, (int) $posicion );
			// 1.72.0: a pupil added to a running group → company + canteen.
			if ( ANPA_Socios_Matricula_Estado::ACTIVO === $destino ) {
				ANPA_Socios_Email::avisar_empresa_comedor( $new_id, ANPA_Socios_Aviso_Matricula::PLANTILLA_ALTA );
			}
		}

		return new WP_REST_Response( array(
			'id'        => $new_id,
			'fillo_id'  => $fillo_id,
			'activitad_id' => $act_id,
			'grupo_id'  => $grupo_id,
			'estado'    => $destino,
			'posicion'  => (int) $posicion,
			'trimestre' => $trimestre,
			'notificar' => $notificar,
			'correos'   => $correos,
		), 201 );
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
			$wpdb->prepare( "SELECT grupo_id, trimestre, estado, oferta_aceptada_en FROM {$mat_t} WHERE id = %d", $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$updated = $wpdb->update(
			$mat_t,
			array(
				'estado'             => 'baixa',
				'baixa_en'           => current_time( 'mysql' ),
				'actualizado_en'     => current_time( 'mysql' ),
				// 1.93.0: no stale offer data left on the row (it may be reused by a new request).
				'oferta_token'       => null,
				'oferta_expira'      => null,
				'oferta_aceptada_en' => null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $id, 'delete' );
		ANPA_Socios_Baixa_Programada_Service::limpar( $id );

		// Freeing a place (1.85.0: also an offer, a requested baixa or an accepted offer): next one.
		$libera = in_array( (string) $row['estado'], array( 'activo', 'oferta', 'baixa_solicitada' ), true ) || ( 'pendente_aprobacion' === (string) $row['estado'] && ! empty( $row['oferta_aceptada_en'] ) );
		if ( $libera && ! empty( $row['grupo_id'] ) && class_exists( 'ANPA_Socios_Extraescolar_Offers' ) ) {
			ANPA_Socios_Extraescolar_Offers::offer_next( (int) $row['grupo_id'], (int) $row['trimestre'] );
		}
		// 1.72.0: a pupil removed from a created group → company + canteen.
		if ( ANPA_Socios_Aviso_Matricula::estaba_no_grupo( (string) $row['estado'] ) ) {
			ANPA_Socios_Email::avisar_empresa_comedor( $id, ANPA_Socios_Aviso_Matricula::PLANTILLA_BAIXA, array( 'efectos' => '' ) );
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
				"SELECT m.id, m.trimestre, m.creado_en AS solicitada_en, m.oferta_aceptada_en,
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
		$rows   = is_array( $rows ) ? $rows : array();
		$grupos = array();
		foreach ( $rows as &$row ) {
			$row['curso_completo'] = ANPA_Socios_Admin_Shared::curso_completo( $row['curso'] ?? null, $row['aula'] ?? null );
			// 1.85.0: an accepted offer from the waiting list already holds its place.
			$row['de_oferta']      = ! empty( $row['oferta_aceptada_en'] );
			// 1.93.0: one lookup per group (every request is pending now: many rows per group).
			$gid = (int) $row['grupo_id'];
			if ( ! isset( $grupos[ $gid ] ) ) {
				$grupos[ $gid ] = array(
					'ocupadas' => ANPA_Socios_Lista_Espera::ocupadas( $gid ),
					'espera'   => ANPA_Socios_Lista_Espera::en_espera( $gid ),
					'xunta'    => ANPA_Socios_Email::contexto_grupo( $gid )['xunta'],
				);
			}
			$row['destino']        = $row['de_oferta'] ? ANPA_Socios_Matricula_Estado::ACTIVO : ANPA_Socios_Matricula_Estado::destino_aprobacion( (string) $row['grupo_estado'], $grupos[ $gid ]['ocupadas'], (int) $row['max_pupilos'] );
			// 1.93.0: same rule as aprobar(): behind whoever is already on the waiting list.
			if ( ! $row['de_oferta'] && ANPA_Socios_Matricula_Estado::ACTIVO === $row['destino'] && $grupos[ $gid ]['espera'] > 0 ) {
				$row['destino'] = ANPA_Socios_Matricula_Estado::LISTA_ESPERA;
			}
			// 1.93.0: the group's state with the numbers (Sen mínimo, aínda non creado, en marcha).
			$row['estado_grupo']   = $grupos[ $gid ]['xunta'];
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
	 * @param  bool   $avisar_empresa 1.71.0: tell the company and the canteen when a place is granted
	 *                                mid-course (false for the trimester activation, a mass moment).
	 * @return array{id:int,estado:string,posicion:int,correos:int}|WP_Error
	 */
	public static function aprobar( int $id, string $actor, string $actor_tipo, bool $avisar_empresa = true ) {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$wpdb->last_error = '';
		$mat = $wpdb->get_row( $wpdb->prepare( "SELECT id, fillo_id, activitad_id, grupo_id, estado, trimestre, oferta_aceptada_en FROM {$mat_t} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
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
		// 1.93.0: a disabled group takes no enrolment (not even into its waiting list).
		if ( ANPA_Socios_Grupo_Serie::ESTADO_DESHABILITADO === (string) $grupo['estado'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_grupo_deshabilitado', __( 'O grupo está deshabilitado: rexeita a solicitude ou move o alumno/a a outro grupo.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		// 1.93.0: never approve into a course that is no longer active.
		if ( ANPA_Socios_Curso_Activo::get() !== (string) $grupo['curso_escolar'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_curso_pechado', __( 'O curso desta solicitude xa non está activo: rexéitaa.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		// 1.85.0: an offer the family accepted kept its place: it becomes a place, in its trimester.
		$de_oferta = ! empty( $mat['oferta_aceptada_en'] );
		if ( $de_oferta && ! in_array( (string) $grupo['estado'], array( ANPA_Socios_Grupo_Serie::ESTADO_ABERTO, ANPA_Socios_Grupo_Serie::ESTADO_SEN_MINIMO ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_grupo_pechado', __( 'O grupo xa non está aberto: rexeita a solicitude ou move o alumno/a a outro grupo.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		if ( $de_oferta ) {
			$destino   = ANPA_Socios_Matricula_Estado::ACTIVO;
			// 1.93.1: an offer for a later trimester keeps it (the place starts then); any other offer
			// starts now, in the current trimester (the waiting row may come from an earlier one).
			$trimestre = max( (int) $mat['trimestre'], ANPA_Socios_Matricula_Gate_Repo::trimestre_vixente( (string) $grupo['curso_escolar'] ) );
			if ( $trimestre !== (int) $mat['trimestre'] && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$mat_t} WHERE fillo_id = %d AND activitad_id = %d AND trimestre = %d AND id <> %d", (int) $mat['fillo_id'], (int) $mat['activitad_id'], $trimestre, $id ) ) > 0 ) {
				$trimestre = (int) $mat['trimestre'];
			}
		} else {
			// The rows that hold a place (this one does not yet).
			$ocupadas  = ANPA_Socios_Lista_Espera::ocupadas( (int) $grupo['id'] );
			$destino   = ANPA_Socios_Matricula_Estado::destino_aprobacion( (string) $grupo['estado'], $ocupadas, (int) $grupo['max_pupilos'] );
			// 1.93.0: while somebody waits for this group a newcomer queues behind them (same rule as enrol()).
			if ( ANPA_Socios_Matricula_Estado::ACTIVO === $destino && ANPA_Socios_Lista_Espera::en_espera( (int) $grupo['id'] ) > 0 ) {
				$destino = ANPA_Socios_Matricula_Estado::LISTA_ESPERA;
			}
			// Trimester = the active one (same rule as the gate).
			$trimestre = ANPA_Socios_Matricula_Gate_Repo::trimestre_vixente( (string) $grupo['curso_escolar'] );
			// 1.93.0: the unique key is (fillo, activity, trimester) across courses: another row of the
			// same pupil and activity in that trimester (e.g. last year's baixa) would make the update
			// fail. Keep the request's own trimester then.
			$colide = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$mat_t} WHERE fillo_id = %d AND activitad_id = %d AND trimestre = %d AND id <> %d", (int) $mat['fillo_id'], (int) $mat['activitad_id'], $trimestre, $id ) );
			if ( $colide > 0 ) {
				$trimestre = (int) $mat['trimestre'];
			}
		}
		// «posición» = place in this group's waiting list (only waiting rows have one).
		$posicion = ANPA_Socios_Matricula_Estado::LISTA_ESPERA === $destino ? ANPA_Socios_Lista_Espera::seguinte_posicion( (int) $grupo['id'] ) : null;

		$updated = $wpdb->update(
			$mat_t,
			array( 'estado' => $destino, 'posicion' => $posicion, 'trimestre' => $trimestre, 'oferta_aceptada_en' => null, 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => $id, 'estado' => ANPA_Socios_Matricula_Estado::PENDENTE_APROBACION ),
			array( '%s', '%d', '%d', '%s', '%s' ),
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
		if ( ANPA_Socios_Matricula_Estado::LISTA_ESPERA === $destino ) {
			ANPA_Socios_Lista_Espera::renumerar( (int) $grupo['id'] );
		}

		$correos = self::enviar_confirmacion( $id, (int) $grupo['id'], $destino, (int) $posicion );
		// 1.71.0: a place granted mid-course → company + canteen (Bcc), with the family's contact.
		// 1.85.0: an accepted offer from the waiting list always tells them (once the group exists).
		if ( ( $avisar_empresa || $de_oferta ) && ANPA_Socios_Matricula_Estado::ACTIVO === $destino ) {
			ANPA_Socios_Email::avisar_empresa_comedor( $id, ANPA_Socios_Aviso_Matricula::PLANTILLA_ALTA );
		}

		return array( 'id' => $id, 'estado' => $destino, 'posicion' => (int) $posicion, 'correos' => $correos );
	}

	/**
	 * 1.93.0: the confirmation to every parent once the enrolment has a place or a
	 * waiting-list position (approval by the junta, automatic approval, or a direct
	 * enrolment during a free period), with the group's real state.
	 *
	 * @since  1.93.0
	 * @param  int    $id       Matrícula id.
	 * @param  int    $grupo_id Group id.
	 * @param  string $estado   activo | lista_espera.
	 * @param  int    $posicion Waiting-list position.
	 * @return int Emails accepted.
	 */
	public static function enviar_confirmacion( int $id, int $grupo_id, string $estado, int $posicion ): int {
		$correos = 0;
		$detalle = self::detalle_para_correo( $id );
		if ( ! is_array( $detalle ) ) {
			return 0;
		}
		$praza = ANPA_Socios_Matricula_Estado::ACTIVO === $estado;
		$frase = ANPA_Socios_Email::estado_grupo_familia( $grupo_id, $praza ? ANPA_Socios_Estado_Grupo_Texto::PRAZA : ANPA_Socios_Estado_Grupo_Texto::ESPERA );
		// 1.93.1: a place offered for the next trimester (a baixa at the end of this one) starts then.
		if ( $praza ) {
			global $wpdb;
			$tri  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT trimestre FROM ' . ANPA_Socios_DB::tabela_matriculas() . ' WHERE id = %d', $id ) );
			$para = ANPA_Socios_Extraescolar_Offers::para_trimestre( $grupo_id, $tri );
			if ( $para > 0 ) {
				$frase = ANPA_Socios_Estado_Grupo_Texto::comezo_praza( $para, ANPA_Socios_Extraescolar_Offers::inicio_trimestre( $grupo_id, $para ) );
			}
		}
		foreach ( $detalle['emails'] as $email ) {
			$ok = $praza
				? ANPA_Socios_Email::enviar_matricula_aprobada_praza( $email, $detalle['alumno'], $detalle['actividade'], $detalle['grupo'], $frase )
				: ANPA_Socios_Email::enviar_matricula_aprobada_espera( $email, $detalle['alumno'], $detalle['actividade'], $detalle['grupo'], $posicion, $frase );
			if ( $ok ) {
				++$correos;
			}
		}
		return $correos;
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
		// 1.85.0: rejecting an accepted offer frees the place it held → next in the list.
		$previa  = $wpdb->get_row( $wpdb->prepare( "SELECT grupo_id, trimestre, oferta_aceptada_en FROM {$mat_t} WHERE id = %d", $id ), ARRAY_A );
		$updated = $wpdb->update(
			$mat_t,
			array( 'estado' => ANPA_Socios_Matricula_Estado::BAIXA, 'baixa_en' => current_time( 'mysql' ), 'oferta_aceptada_en' => null, 'actualizado_en' => current_time( 'mysql' ) ),
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
		if ( is_array( $previa ) && ! empty( $previa['oferta_aceptada_en'] ) ) {
			ANPA_Socios_Extraescolar_Offers::offer_next( (int) $previa['grupo_id'], (int) $previa['trimestre'], $id );
		}

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
