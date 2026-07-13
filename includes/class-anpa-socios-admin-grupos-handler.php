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
	 * GET /admin/actividad/<id>/grupos
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_grupos( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$actividad_id = (int) $request->get_param( 'id' );
		$table        = ANPA_Socios_DB::tabela_grupos();
		$curso_filter = $request->get_param( 'curso_escolar' );
		$sql          = "SELECT id, actividad_id, curso_escolar, curso_range, franxa, dias, min_pupilos, max_pupilos, estado FROM {$table} WHERE actividad_id = %d";
		$params       = array( $actividad_id );
		if ( $curso_filter && ANPA_Socios_Curso_Escolar::is_valid( $curso_filter ) ) {
			$sql     .= ' AND curso_escolar = %s';
			$params[] = $curso_filter;
		}
		$sql .= ' ORDER BY curso_escolar DESC, id ASC';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				$params
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /admin/actividad/<id>/grupos
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_grupo( WP_REST_Request $request ) {
		global $wpdb;

		$actividad_id = (int) $request->get_param( 'id' );
		$payload = ANPA_Socios_Admin_Payload::validar_grupo( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$activity = self::get_activity( $actividad_id, (string) $payload['curso_escolar'] );
		if ( null === $activity ) {
			return new WP_Error( 'anpa_admin_actividad_not_found', __( 'Actividade non atopada para ese curso escolar', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$fit_error = self::assert_within_activity( $payload, $activity );
		if ( null !== $fit_error ) {
			return $fit_error;
		}

		$nivel_ids = $payload['nivel_ids'] ?? array();
		unset( $payload['nivel_ids'] );

		$payload['actividad_id'] = $actividad_id;
		$inserted                = $wpdb->insert(
			ANPA_Socios_DB::tabela_grupos(),
			$payload,
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$grupo_id = (int) $wpdb->insert_id;

		// Persist nivel_ids to grupos_niveis table.
		if ( array() !== $nivel_ids ) {
			ANPA_Socios_DB::insert_grupo_niveis( $grupo_id, $nivel_ids );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo', (string) $grupo_id, 'create' );

		return new WP_REST_Response( self::get_grupo_row( $grupo_id ), 201 );
	}

	/**
	 * PATCH /admin/grupo/<id>
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_grupo( WP_REST_Request $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$current = self::get_grupo_row( $id );
		if ( null === $current ) {
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$payload = ANPA_Socios_Admin_Payload::validar_grupo( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$activity = self::get_activity( (int) $current['actividad_id'], (string) $payload['curso_escolar'] );
		if ( null === $activity ) {
			return new WP_Error( 'anpa_admin_actividad_not_found', __( 'Actividade non atopada para ese curso escolar', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$fit_error = self::assert_within_activity( $payload, $activity );
		if ( null !== $fit_error ) {
			return $fit_error;
		}

		$nivel_ids = $payload['nivel_ids'] ?? array();
		unset( $payload['nivel_ids'] );

		$payload['actualizado_en'] = current_time( 'mysql' );
		$updated                   = $wpdb->update(
			ANPA_Socios_DB::tabela_grupos(),
			$payload,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// Refresh nivel_ids in grupos_niveis table.
		ANPA_Socios_DB::delete_grupo_niveis( $id );
		if ( array() !== $nivel_ids ) {
			ANPA_Socios_DB::insert_grupo_niveis( $id, $nivel_ids );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo', (string) $id, 'update' );

		return new WP_REST_Response( self::get_grupo_row( $id ), 200 );
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

		$id  = (int) $request->get_param( 'id' );
		$mat = ANPA_Socios_DB::tabela_matriculas();
		$in_use = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$mat} WHERE grupo_id = %d AND estado <> 'baixa'",
				$id
			)
		);
		if ( $in_use > 0 ) {
			return new WP_Error(
				'anpa_admin_grupo_in_use',
				'Non se pode eliminar: o grupo ten matrículas activas ou en lista de espera.',
				array( 'status' => 409 )
			);
		}

		$deleted = $wpdb->delete( ANPA_Socios_DB::tabela_grupos(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// Clean up grupos_niveis relationships.
		ANPA_Socios_DB::delete_grupo_niveis( $id );

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

		$id     = (int) $request->get_param( 'id' );
		if ( null === self::get_grupo_row( $id ) ) {
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$estado = isset( $body['estado'] ) ? (string) $body['estado'] : '';
		if ( ! in_array( $estado, ANPA_Socios_Admin_Payload::GRUPO_ESTADO, true ) ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Estado inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$updated = $wpdb->update(
			ANPA_Socios_DB::tabela_grupos(),
			array( 'estado' => $estado, 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo', (string) $id, 'estado_' . $estado );

		return new WP_REST_Response( self::get_grupo_row( $id ), 200 );
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
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		$mat = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.id, m.activitad_id, m.trimestre, m.grupo_id, COALESCE(fc.curso, f.curso) AS curso
				 FROM {$mat_t} m INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 LEFT JOIN {$gru_t} g0 ON g0.id = m.grupo_id
				 LEFT JOIN {$wpdb->prefix}anpa_fillos_cursos fc ON fc.fillo_id = f.id AND fc.curso_escolar = g0.curso_escolar
				 WHERE m.id = %d AND m.estado <> 'baixa'",
				$mat_id
			),
			ARRAY_A
		);
		if ( ! is_array( $mat ) ) {
			return new WP_Error( 'anpa_admin_matricula_not_found', __( 'Matrícula non atopada', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		$grupo = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, actividad_id, curso_range, max_pupilos FROM {$gru_t} WHERE id = %d",
				$target
			),
			ARRAY_A
		);
		if ( ! is_array( $grupo ) ) {
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo destino non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		// Must be the same activity.
		if ( (int) $grupo['actividad_id'] !== (int) $mat['activitad_id'] ) {
			return new WP_Error( 'anpa_admin_mover_actividade', __( 'Só se pode mover entre grupos da mesma actividade', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		// Curso fit.
		if ( ! ANPA_Socios_Curso_Fit::fits( (string) $mat['curso'], (string) $grupo['curso_range'] ) ) {
			return new WP_Error( 'anpa_admin_mover_curso', 'O curso do alumno/a non encaixa no grupo destino', array( 'status' => 400 ) );
		}

		// Capacity gate (no silent over-fill): count active in target for the trimester.
		$activos = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$mat_t} WHERE grupo_id = %d AND trimestre = %d AND estado = 'activo' AND id <> %d",
				$target,
				(int) $mat['trimestre'],
				$mat_id
			)
		);
		if ( $activos >= (int) $grupo['max_pupilos'] ) {
			return new WP_Error( 'anpa_admin_mover_cheo', __( 'O grupo destino está completo', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		// Single atomic update. Position is not changed: it is the immutable
		// registration order within the activity/trimestre.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$mat_t} SET grupo_id = %d, estado = 'activo', actualizado_en = %s WHERE id = %d",
				$target,
				current_time( 'mysql' ),
				$mat_id
			)
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $mat_id, 'mover' );

		return new WP_REST_Response( array( 'id' => $mat_id, 'grupo_id' => $target, 'estado' => 'activo' ), 200 );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

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
			array( 'estado' => 'baixa', 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $id, 'baixa_confirm' );

		// Free slot → offer to the next in the waitlist.
		ANPA_Socios_Extraescolar_Offers::offer_next( (int) $mat['grupo_id'], (int) $mat['trimestre'] );

		return new WP_REST_Response( array( 'id' => $id, 'estado' => 'baixa' ), 200 );
	}

	/**
	 * Returns the activity row (grupos + días option sets) or null.
	 *
	 * @since  1.9.0
	 * @param  int $actividad_id Activity id.
	 * @return array<string,mixed>|null
	 */
	private static function get_activity( int $actividad_id, string $curso_escolar ): ?array {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_actividades_cursos();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT actividad_id AS id, curso_escolar, grupos, dias FROM {$table} WHERE actividad_id = %d AND curso_escolar = %s",
				$actividad_id,
				$curso_escolar
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
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
				"SELECT id, actividad_id, curso_escolar, curso_range, franxa, dias, min_pupilos, max_pupilos, estado FROM {$table} WHERE id = %d",
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

	/**
	 * Asserts the group's curso_range and días are subsets of the activity's
	 * option sets. Returns a WP_Error on mismatch, or null when valid.
	 *
	 * @since  1.9.0
	 * @param  array<string,mixed> $payload  Canonical grupo payload.
	 * @param  array<string,mixed> $activity Activity row (grupos, dias).
	 * @return WP_Error|null
	 */
	private static function assert_within_activity( array $payload, array $activity ): ?WP_Error {
		$act_grupos = ANPA_Socios_Actividade_Options::parse( (string) $activity['grupos'], ANPA_Socios_Actividade_Options::GRUPOS );
		if ( ! in_array( $payload['curso_range'], $act_grupos, true ) ) {
			return new WP_Error(
				'anpa_admin_grupo_curso_range',
				'O grupo curricular non está entre os da actividade.',
				array( 'status' => 400 )
			);
		}

		$act_dias   = ANPA_Socios_Actividade_Options::parse( (string) $activity['dias'], ANPA_Socios_Actividade_Options::DIAS );
		$grupo_dias = ANPA_Socios_Actividade_Options::parse( (string) $payload['dias'], ANPA_Socios_Actividade_Options::DIAS );
		foreach ( $grupo_dias as $dia ) {
			if ( ! in_array( $dia, $act_dias, true ) ) {
				return new WP_Error(
					'anpa_admin_grupo_dias',
					'Os días do grupo deben estar entre os días da actividade.',
					array( 'status' => 400 )
				);
			}
		}

		return null;
	}
}
