<?php
/**
 * Admin REST handler for the grupos domain (fase7).
 *
 * Groups are the concrete, enrollable units of an activity: a curso range,
 * a day set, a capacity (min/max) and a lifecycle state (aberto/pechado).
 * All endpoints are master-gated and audited.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/actividad/<id>/grupos` and
 * `/admin/grupo/<id>` endpoints.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Admin_Grupos_Handler {

	/**
	 * Registers grupos admin routes.
	 *
	 * @since  1.9.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/grupos-horarios', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_grupos_horarios' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)/grupos', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_grupos' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_grupo' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/grupo/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_grupo' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_grupo' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/grupo/(?P<id>\d+)/estado', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'set_estado' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/grupo/(?P<id>\d+)/matriculas', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_matriculas' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matricula/(?P<id>\d+)/mover', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'mover' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matricula/(?P<id>\d+)/baixa/confirm', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'confirm_baixa' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/grupos-horarios — non-PII aggregate for one school year.
	 *
	 * @since  1.44.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_grupos_horarios( WP_REST_Request $request ) {
		global $wpdb;

		$requested = $request->get_param( 'curso_escolar' );
		$curso     = is_string( $requested ) ? sanitize_text_field( wp_unslash( $requested ) ) : '';
		if ( '' === $curso ) {
			$curso = (string) ANPA_Socios_Curso_Activo::get();
		}
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return new WP_Error( 'anpa_admin_curso_invalid', __( 'Selecciona un curso escolar válido.', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$niveis_t = ANPA_Socios_DB::tabela_niveis();
		$horarios = ANPA_Socios_DB::tabela_horarios_comedor();
		$pivot    = ANPA_Socios_DB::tabela_niveis_curso();
		$grupos   = ANPA_Socios_DB::tabela_grupos();
		$gn       = ANPA_Socios_DB::tabela_grupos_niveis();
		$acts     = ANPA_Socios_DB::tabela_actividades();

		// fase31: levels are GLOBAL (no curso_escolar column) since 1.35.0 and
		// their comedor schedule is per-course in the wp_anpa_niveis_curso pivot
		// since 1.36.0. Load the global active levels first, then resolve each
		// level's meal window for the requested course from the pivot. (The old
		// query joined the dropped n.curso_escolar / n.horario_comedor_id columns
		// and therefore failed with a SQL error on every load.)
		$wpdb->last_error = '';
		$niveis_rows = $wpdb->get_results(
			"SELECT id, codigo, etiqueta, orde
			 FROM {$niveis_t} WHERE estado = 'activo'
			 ORDER BY orde ASC, id ASC",
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $niveis_rows ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido cargar a estrutura escolar.', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$wpdb->last_error = '';
		$meal_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.nivel_id, h.id AS horario_id, h.nome AS horario_nome,
				        h.inicio AS comedor_inicio, h.fin AS comedor_fin
				 FROM {$pivot} p
				 INNER JOIN {$horarios} h ON h.id = p.horario_comedor_id
				 WHERE p.curso_escolar = %s",
				$curso
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $meal_rows ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido cargar a estrutura escolar.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$meal_by_nivel = array();
		foreach ( $meal_rows as $meal_row ) {
			$meal_by_nivel[ (int) $meal_row['nivel_id'] ] = $meal_row;
		}

		$level_rows = array();
		foreach ( $niveis_rows as $nivel_row ) {
			$nid  = (int) $nivel_row['id'];
			$meal = $meal_by_nivel[ $nid ] ?? array();
			$level_rows[] = array(
				'id'             => $nid,
				'codigo'         => (string) $nivel_row['codigo'],
				'etiqueta'       => (string) $nivel_row['etiqueta'],
				'orde'           => (int) $nivel_row['orde'],
				'horario_id'     => isset( $meal['horario_id'] ) ? (int) $meal['horario_id'] : 0,
				'horario_nome'   => (string) ( $meal['horario_nome'] ?? '' ),
				'comedor_inicio' => $meal['comedor_inicio'] ?? null,
				'comedor_fin'    => $meal['comedor_fin'] ?? null,
			);
		}

		$wpdb->last_error = '';
		$group_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id AS grupo_id, g.actividad_id AS actividade_id, g.serie_uid,
				        a.nome AS actividade_nome, g.nome AS grupo_nome,
				        g.horario, g.franxa, g.dias, g.estado, gn.nivel_id
				 FROM {$grupos} g
				 INNER JOIN {$acts} a ON a.id = g.actividad_id
				 INNER JOIN {$gn} gn ON gn.grupo_id = g.id
				 WHERE g.curso_escolar = %s
				 ORDER BY g.franxa ASC, a.nome ASC, g.nome ASC, g.id ASC, gn.nivel_id ASC",
				$curso
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $group_rows ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puideron cargar os grupos.', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( ANPA_Socios_Grupos_Horarios::build( $curso, $level_rows, $group_rows ), 200 );
	}

	/**
	 * GET /admin/actividad/<id>/grupos
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_grupos( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$actividad_id = (int) $request->get_param( 'id' );
		$curso_activo = (string) ANPA_Socios_Curso_Activo::get();
		$table        = ANPA_Socios_DB::tabela_grupos();
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, actividad_id, curso_escolar, serie_uid, nome, horario, franxa, dias,
				        min_pupilos, max_pupilos, estado
				 FROM {$table} WHERE actividad_id = %d
				 ORDER BY nome ASC, curso_escolar DESC, id ASC",
				$actividad_id
			),
			ARRAY_A
		);

		$series = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = '' !== (string) $row['serie_uid'] ? (string) $row['serie_uid'] : 'legacy-' . (int) $row['id'];
			if ( ! isset( $series[ $key ] ) ) {
				$series[ $key ] = array(
					'id'                => 0,
					'serie_uid'         => $key,
					'ten_grupo_actual'  => false,
					'curso_escolar'     => $curso_activo,
					'nivel_ids'         => array(),
					'cursos_anteriores' => array(),
				);
			}
			$annual = array(
				'id'            => (int) $row['id'],
				'curso_escolar' => (string) $row['curso_escolar'],
				'nome'          => (string) $row['nome'],
				'horario'       => (string) $row['horario'],
				'horario_label' => ANPA_Socios_Grupo_Serie::horario_label( (string) $row['horario'] ),
				'franxa'        => (string) $row['franxa'],
				'dias'          => (string) $row['dias'],
				'min_pupilos'   => (int) $row['min_pupilos'],
				'max_pupilos'   => (int) $row['max_pupilos'],
				'estado'        => (string) $row['estado'],
				'nivel_ids'     => ANPA_Socios_DB::get_niveis_for_grupo( (int) $row['id'] ),
			);
			if ( $curso_activo === (string) $row['curso_escolar'] ) {
				$series[ $key ] = array_merge( $series[ $key ], $annual, array( 'ten_grupo_actual' => true ) );
			} else {
				$series[ $key ]['cursos_anteriores'][] = $annual;
				if ( 0 === (int) $series[ $key ]['id'] ) {
					$series[ $key ] = array_merge( $series[ $key ], $annual, array( 'id' => (int) $row['id'], 'curso_escolar' => $curso_activo, 'ten_grupo_actual' => false ) );
				}
			}
		}

		return new WP_REST_Response( array_values( $series ), 200 );
	}

	/**
	 * POST /admin/actividad/<id>/grupos
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_grupo( WP_REST_Request $request ) {
		$actividad_id = (int) $request->get_param( 'id' );
		$payload = self::validate_series_payload( $actividad_id, ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$uid = wp_generate_uuid4();
		$result = self::persist_series( $actividad_id, $uid, $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo_serie', $uid, 'create' );
		return new WP_REST_Response( array( 'serie_uid' => $uid, 'annual_ids' => $result ), 201 );
	}

	/**
	 * PATCH /admin/grupo/<id>
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_grupo( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$current = self::get_grupo_row( $id );
		if ( null === $current ) {
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$uid = (string) ( $current['serie_uid'] ?? '' );
		if ( '' === $uid ) {
			return new WP_Error( 'anpa_admin_grupo_legacy', __( 'Actualiza primeiro o esquema de grupos.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$payload = self::validate_series_payload( (int) $current['actividad_id'], ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$result = self::persist_series( (int) $current['actividad_id'], $uid, $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo_serie', $uid, 'update' );
		return new WP_REST_Response( array( 'serie_uid' => $uid, 'annual_ids' => $result ), 200 );
	}

	/**
	 * DELETE /admin/grupo/<id>
	 *
	 * Refuses deletion when the group still has non-baixa matrículas, to avoid
	 * orphaning enrolments.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_grupo( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$curso_activo = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso_activo ) { return new WP_Error( 'anpa_admin_no_active_course', __( 'Non hai un curso escolar activo.', 'anpa-socios' ), array( 'status' => 409 ) ); }
		$table = ANPA_Socios_DB::tabela_grupos();
		$mat = ANPA_Socios_DB::tabela_matriculas();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		$wpdb->last_error = '';
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT id, actividad_id, serie_uid, curso_escolar FROM {$table} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		if ( ! is_array( $current ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo non atopado', 'anpa-socios' ), array( 'status' => 404 ) ); }
		if ( $curso_activo !== (string) $current['curso_escolar'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_grupo_historico_readonly', __( 'Os grupos de cursos anteriores son históricos e non se poden eliminar desde este formulario.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$wpdb->last_error = '';
		$refs = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$mat} WHERE grupo_id = %d ORDER BY id FOR UPDATE", $id ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $refs ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido comprobar o histórico do grupo.', 'anpa-socios' ), array( 'status' => 500 ) ); }
		if ( array() !== $refs ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_grupo_in_use', __( 'Non se pode eliminar: o grupo ten matrículas ou histórico asociado.', 'anpa-socios' ), array( 'status' => 409 ) ); }
		if ( ! ANPA_Socios_DB::delete_grupo_niveis( $id ) || false === $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo', (string) $id, 'delete' );
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * POST /admin/grupo/<id>/estado — toggle aberto/pechado.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_estado( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$current = self::get_grupo_row( $id );
		if ( null === $current ) { return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo non atopado', 'anpa-socios' ), array( 'status' => 404 ) ); }
		$body = ANPA_Socios_Admin_Shared::json_body( $request );
		$estado = isset( $body['estado'] ) ? (string) $body['estado'] : '';
		if ( ! in_array( $estado, ANPA_Socios_Admin_Payload::GRUPO_ESTADO, true ) ) { return new WP_Error( 'anpa_admin_invalid', __( 'Estado inválido', 'anpa-socios' ), array( 'status' => 400 ) ); }
		$uid = (string) ( $current['serie_uid'] ?? '' );
		$curso_activo = ANPA_Socios_Curso_Activo::get();
		if ( '' === $uid || null === $curso_activo ) { return new WP_Error( 'anpa_admin_grupo_legacy', __( 'Non hai unha serie editable no curso activo.', 'anpa-socios' ), array( 'status' => 409 ) ); }
		$table = ANPA_Socios_DB::tabela_grupos();
		$gn_t = ANPA_Socios_DB::tabela_grupos_niveis();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		$wpdb->last_error = '';
		$current_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, curso_escolar, franxa, dias FROM {$table} WHERE actividad_id = %d AND serie_uid = %s AND curso_escolar = %s FOR UPDATE",
			(int) $current['actividad_id'], $uid, $curso_activo
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $current_row ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Non hai grupo desta serie no curso activo.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$current_id = (int) $current_row['id'];
		if ( 'aberto' === $estado ) {
			$nivel_ids = $wpdb->get_col( $wpdb->prepare( "SELECT nivel_id FROM {$gn_t} WHERE grupo_id = %d ORDER BY nivel_id FOR UPDATE", $current_id ) );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $nivel_ids ) || array() === $nivel_ids ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Non se puideron comprobar os niveis do grupo.', 'anpa-socios' ), array( 'status' => 500 ) ); }
			$payload = array( 'estado' => 'aberto', 'cursos' => array( $curso_activo ), 'niveis_por_ano' => array( $curso_activo => array_map( 'intval', $nivel_ids ) ), 'franxa' => (string) $current_row['franxa'], 'dias' => (string) $current_row['dias'] );
			$conflicts = ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series( $payload );
			if ( is_wp_error( $conflicts ) ) { $wpdb->query( 'ROLLBACK' ); return $conflicts; }
			if ( array() !== $conflicts ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_grupo_comedor_conflict', __( 'O grupo solapa co horario de comedor dalgún nivel seleccionado.', 'anpa-socios' ), array( 'status' => 409, 'conflicts' => $conflicts ) ); }
		}
		$updated = $wpdb->update( $table, array( 'estado' => $estado, 'actualizado_en' => current_time( 'mysql' ) ), array( 'id' => $current_id ), array( '%s', '%s' ), array( '%d' ) );
		if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo', (string) $current_id, 'estado_' . $estado );
		return new WP_REST_Response( array( 'id' => $current_id, 'serie_uid' => $uid, 'estado' => $estado ), 200 );
	}

	// ──────────────────────────────────────────────
	// fase7 PR-7e: organisation (list group matrículas + move)
	// ──────────────────────────────────────────────

	/**
	 * GET /admin/grupo/<id>/matriculas — pupils in a group.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_matriculas( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$grupo_id = (int) $request->get_param( 'id' );
		$mat_t    = ANPA_Socios_DB::tabela_matriculas();
		$fil_t    = ANPA_Socios_DB::tabela_fillos();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.estado, m.posicion, m.trimestre, m.activitad_id,
				        f.id AS fillo_id, f.nome AS fillo_nome, f.apelidos AS fillo_apelidos,
				        COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula) AS aula,
				        CONCAT(COALESCE(fc.curso, f.curso), 'º', COALESCE(fc.aula, f.aula)) AS curso_completo
				 FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 LEFT JOIN {$wpdb->prefix}anpa_grupos g ON g.id = m.grupo_id
				 LEFT JOIN {$wpdb->prefix}anpa_fillos_cursos fc ON fc.fillo_id = f.id AND fc.curso_escolar = g.curso_escolar
				 WHERE m.grupo_id = %d AND m.estado <> 'baixa'
				 ORDER BY m.estado ASC, m.posicion ASC, m.id ASC",
				$grupo_id
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /admin/matricula/<id>/mover — move a pupil to another group of the
	 * SAME activity. Body: { grupo_id }. Refuses curso mismatch or over-capacity
	 * (no silent over-fill).
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function mover( WP_REST_Request $request ) {
		global $wpdb;

		$mat_id = (int) $request->get_param( 'id' );
		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$target = isset( $body['grupo_id'] ) ? (int) $body['grupo_id'] : 0;
		if ( $target <= 0 ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Grupo destino inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$gn_t  = ANPA_Socios_DB::tabela_grupos_niveis();
		$fc_t  = ANPA_Socios_DB::tabela_fillos_cursos();

		// Read only the current source id to establish a deterministic lock order.
		// The matrícula is re-read and verified under lock before any decision.
		$wpdb->last_error = '';
		$preflight = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, grupo_id FROM {$mat_t} WHERE id = %d AND estado <> 'baixa'", $mat_id ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( ! is_array( $preflight ) ) {
			return new WP_Error( 'anpa_admin_matricula_not_found', __( 'Matrícula non atopada', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		$preflight_source = (int) ( $preflight['grupo_id'] ?? 0 );
		$group_ids        = array_values( array_unique( array_filter( array( $preflight_source, $target ) ) ) );
		sort( $group_ids, SORT_NUMERIC );
		$group_in = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$wpdb->last_error = '';
		$locked_groups = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, actividad_id, curso_escolar, nome, horario, franxa, dias, max_pupilos, estado
			 FROM {$gru_t} WHERE id IN ({$group_in}) ORDER BY id FOR UPDATE",
			...$group_ids
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $locked_groups ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puideron bloquear os grupos.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$groups_by_id = array();
		foreach ( $locked_groups as $locked_group ) {
			$groups_by_id[ (int) $locked_group['id'] ] = $locked_group;
		}
		if ( ! isset( $groups_by_id[ $target ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo destino non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$grupo = $groups_by_id[ $target ];

		$wpdb->last_error = '';
		$mat = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, fillo_id, activitad_id, trimestre, grupo_id FROM {$mat_t}
				 WHERE id = %d AND estado <> 'baixa' FOR UPDATE",
				$mat_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( ! is_array( $mat ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_matricula_not_found', __( 'Matrícula non atopada', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( (int) ( $mat['grupo_id'] ?? 0 ) !== $preflight_source ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_mover_concurrente', __( 'A matrícula cambiou mentres se preparaba o movemento. Téntao de novo.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		if ( 'aberto' !== (string) $grupo['estado'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_mover_pechado', __( 'O grupo destino está pechado.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		if ( (int) $grupo['actividad_id'] !== (int) $mat['activitad_id'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_mover_actividade', __( 'Só se pode mover entre grupos da mesma actividade', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$source_curso_escolar = isset( $groups_by_id[ $preflight_source ] )
			? (string) $groups_by_id[ $preflight_source ]['curso_escolar']
			: '';
		if ( '' !== $source_curso_escolar && (string) $grupo['curso_escolar'] !== $source_curso_escolar ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_mover_curso_escolar', __( 'Non se pode mover entre cursos escolares diferentes', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$wpdb->last_error = '';
		$annual = $wpdb->get_row( $wpdb->prepare(
			"SELECT nivel_id FROM {$fc_t} WHERE fillo_id = %d AND curso_escolar = %s LIMIT 1 FOR UPDATE",
			(int) $mat['fillo_id'],
			(string) $grupo['curso_escolar']
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido comprobar o nivel anual.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$nivel_id = is_array( $annual ) ? (int) ( $annual['nivel_id'] ?? 0 ) : 0;

		$wpdb->last_error = '';
		$locked_niveis = $wpdb->get_col( $wpdb->prepare(
			"SELECT nivel_id FROM {$gn_t} WHERE grupo_id = %d ORDER BY nivel_id FOR UPDATE",
			$target
		) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $locked_niveis ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puideron comprobar os niveis do grupo.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( $nivel_id <= 0 || ! in_array( $nivel_id, array_map( 'intval', $locked_niveis ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_mover_curso', 'O curso do alumno/a non encaixa no grupo destino', array( 'status' => 400 ) );
		}

		$conflicts = ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series( array(
			'estado'          => 'aberto',
			'cursos'          => array( (string) $grupo['curso_escolar'] ),
			'niveis_por_ano' => array( (string) $grupo['curso_escolar'] => array( $nivel_id ) ),
			'franxa'          => (string) $grupo['franxa'],
			'dias'            => (string) $grupo['dias'],
		) );
		if ( is_wp_error( $conflicts ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $conflicts;
		}
		if ( array() !== $conflicts ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_grupo_comedor_conflict', __( 'O grupo destino solapa co horario de comedor do nivel anual.', 'anpa-socios' ), array( 'status' => 409, 'conflicts' => $conflicts ) );
		}

		$wpdb->last_error = '';
		$active_rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$mat_t} WHERE grupo_id = %d AND trimestre = %d
			 AND estado = 'activo' AND id <> %d ORDER BY id FOR UPDATE",
			$target,
			(int) $mat['trimestre'],
			$mat_id
		) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $active_rows ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido comprobar a capacidade do grupo.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( count( $active_rows ) >= (int) $grupo['max_pupilos'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_mover_cheo', __( 'O grupo destino está completo', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$mat_t} SET grupo_id = %d, estado = 'activo', actualizado_en = %s WHERE id = %d",
			$target,
			current_time( 'mysql' ),
			$mat_id
		) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $mat_id, 'mover' );
		return new WP_REST_Response( array( 'id' => $mat_id, 'grupo_id' => $target, 'estado' => 'activo' ), 200 );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/** @return array<string,mixed>|WP_Error */
	private static function validate_series_payload( int $actividad_id, array $body ) {
		global $wpdb;

		$curso_activo = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso_activo ) {
			return new WP_Error( 'anpa_admin_no_active_course', __( 'Non hai un curso escolar activo.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$nivel_ids = isset( $body['nivel_ids'] ) && is_array( $body['nivel_ids'] )
			? $body['nivel_ids']
			: ( $body['niveis_por_ano'][ $curso_activo ] ?? array() );
		$body['cursos'] = array( $curso_activo );
		$body['niveis_por_ano'] = array( $curso_activo => $nivel_ids );

		$payload = ANPA_Socios_Grupo_Serie::normalize( $body );
		if ( array() === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Revisa o nome, niveis, horario, franxa, días e capacidade do grupo.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$activities = ANPA_Socios_DB::tabela_actividades();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$activities} WHERE id = %d", $actividad_id ) );
		if ( 0 === $exists ) {
			return new WP_Error( 'anpa_admin_actividad_not_found', __( 'Actividade non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( ! ANPA_Socios_DB::niveis_belong_to_curso( $payload['niveis_por_ano'][ $curso_activo ], $curso_activo ) ) {
			return new WP_Error( 'anpa_admin_grupo_niveis', __( 'Os niveis seleccionados deben pertencer ao curso escolar activo.', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		return $payload;
	}

	/**
	 * Persists only the active annual row in a logical group series.
	 *
	 * @param array<string,mixed> $payload Normalized series payload.
	 * @return array<string,int>|WP_Error Map curso_escolar => annual grupo id.
	 */
	private static function persist_series( int $actividad_id, string $uid, array $payload ) {
		global $wpdb;
		$curso_activo = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso_activo ) {
			return new WP_Error( 'anpa_admin_no_active_course', __( 'Non hai un curso escolar activo.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$table = ANPA_Socios_DB::tabela_grupos();
		$courses = ANPA_Socios_DB::tabela_cursos();
		$activities = ANPA_Socios_DB::tabela_actividades();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$wpdb->last_error = '';
		$locked_activity = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$activities} WHERE id = %d FOR UPDATE", $actividad_id ) );
		if ( '' !== (string) $wpdb->last_error || $actividad_id !== (int) $locked_activity ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_actividad_not_found', __( 'Actividade non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$wpdb->last_error = '';
		$locked_course = $wpdb->get_var( $wpdb->prepare( "SELECT curso_escolar FROM {$courses} WHERE curso_escolar = %s AND estado = 'activo' FOR UPDATE", $curso_activo ) );
		if ( '' !== (string) $wpdb->last_error || $curso_activo !== (string) $locked_course ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_active_course_changed', __( 'O curso activo cambiou. Recarga a páxina e téntao de novo.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$wpdb->last_error = '';
		$existing = $wpdb->get_results( $wpdb->prepare( "SELECT id, curso_escolar FROM {$table} WHERE actividad_id = %d AND serie_uid = %s ORDER BY id FOR UPDATE", $actividad_id, $uid ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $existing ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido bloquear a serie de grupos.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$current_id = 0;
		foreach ( $existing as $row ) {
			if ( $curso_activo === (string) $row['curso_escolar'] ) { $current_id = (int) $row['id']; break; }
		}
		$conflicts = ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series( $payload );
		if ( is_wp_error( $conflicts ) ) { $wpdb->query( 'ROLLBACK' ); return $conflicts; }
		if ( array() !== $conflicts ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_grupo_comedor_conflict', __( 'O grupo solapa co horario de comedor dalgún nivel seleccionado.', 'anpa-socios' ), array( 'status' => 409, 'conflicts' => $conflicts ) );
		}
		$data = array(
			'actividad_id' => $actividad_id, 'curso_escolar' => $curso_activo, 'serie_uid' => $uid,
			'nome' => $payload['nome'], 'horario' => $payload['horario'], 'curso_range' => '',
			'franxa' => $payload['franxa'], 'dias' => $payload['dias'], 'min_pupilos' => $payload['min_pupilos'],
			'max_pupilos' => $payload['max_pupilos'], 'estado' => $payload['estado'], 'actualizado_en' => current_time( 'mysql' ),
		);
		if ( $current_id > 0 ) { $ok = $wpdb->update( $table, $data, array( 'id' => $current_id ), null, array( '%d' ) ); }
		else { $data['creado_en'] = current_time( 'mysql' ); $ok = $wpdb->insert( $table, $data ); $current_id = (int) $wpdb->insert_id; }
		if ( false === $ok || $current_id <= 0 || ! ANPA_Socios_DB::delete_grupo_niveis( $current_id ) || ! ANPA_Socios_DB::insert_grupo_niveis( $current_id, $payload['niveis_por_ano'][ $curso_activo ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		return array( $curso_activo => $current_id );
	}

	/**
	 * POST /admin/matricula/<id>/baixa/confirm — confirm a requested baixa.
	 *
	 * Sets estado=baixa (effective), frees the slot, and offers the freed place
	 * to the next waitlisted pupil of the same group/trimester.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function confirm_baixa( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$mat   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, grupo_id, trimestre, estado FROM {$mat_t} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! is_array( $mat ) ) {
			return new WP_Error( 'anpa_admin_matricula_not_found', __( 'Matrícula non atopada', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( 'baixa_solicitada' !== $mat['estado'] ) {
			return new WP_Error( 'anpa_admin_no_baixa_request', __( 'Esta matrícula non ten unha solicitude de baixa pendente', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$updated = $wpdb->update(
			$mat_t,
			array( 'estado' => 'baixa', 'baixa_en' => current_time( 'mysql' ), 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => $id, 'estado' => 'baixa_solicitada' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $id, 'baixa_confirm' );

		// Free slot → offer to the next in the waitlist.
		ANPA_Socios_Extraescolar_Offers::offer_next( (int) $mat['grupo_id'], (int) $mat['trimestre'] );

		return new WP_REST_Response( array( 'id' => $id, 'estado' => 'baixa' ), 200 );
	}

	/**
	 * Returns a grupo row or null.
	 *
	 * @since  1.9.0
	 * @param  int $id Grupo id.
	 * @return array<string,mixed>|null
	 */
	private static function get_grupo_row( int $id ): ?array {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_grupos();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, actividad_id, curso_escolar, serie_uid, nome, horario, curso_range, franxa, dias, min_pupilos, max_pupilos, estado FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		// Append dynamic nivel_ids from grupos_niveis table.
		$row['nivel_ids'] = ANPA_Socios_DB::get_niveis_for_grupo( $id );

		return $row;
	}

}
