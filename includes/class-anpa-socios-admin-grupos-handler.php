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
					'id'               => (int) $row['id'],
					'serie_uid'        => $key,
					'nome'             => (string) $row['nome'],
					'horario'          => (string) $row['horario'],
					'horario_label'    => ANPA_Socios_Grupo_Serie::horario_label( (string) $row['horario'] ),
					'franxa'           => (string) $row['franxa'],
					'dias'             => (string) $row['dias'],
					'min_pupilos'      => (int) $row['min_pupilos'],
					'max_pupilos'      => (int) $row['max_pupilos'],
					'estado'           => (string) $row['estado'],
					'cursos'           => array(),
					'niveis_por_ano'   => array(),
					'annual_ids'        => array(),
				);
			}
			$curso = (string) $row['curso_escolar'];
			$series[ $key ]['cursos'][] = $curso;
			$series[ $key ]['annual_ids'][ $curso ] = (int) $row['id'];
			$series[ $key ]['niveis_por_ano'][ $curso ] = ANPA_Socios_DB::get_niveis_for_grupo( (int) $row['id'] );
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
		$result = self::persist_series( $actividad_id, $uid, $payload, array() );
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
		global $wpdb;

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
		$table = ANPA_Socios_DB::tabela_grupos();
		$existing = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, curso_escolar FROM {$table} WHERE actividad_id = %d AND serie_uid = %s",
			(int) $current['actividad_id'],
			$uid
		), ARRAY_A );
		$result = self::persist_series( (int) $current['actividad_id'], $uid, $payload, is_array( $existing ) ? $existing : array() );
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

		$id      = (int) $request->get_param( 'id' );
		$current = self::get_grupo_row( $id );
		if ( null === $current ) {
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$uid = (string) ( $current['serie_uid'] ?? '' );
		$table = ANPA_Socios_DB::tabela_grupos();
		$mat   = ANPA_Socios_DB::tabela_matriculas();
		$rows  = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE actividad_id = %d AND serie_uid = %s",
			(int) $current['actividad_id'],
			$uid
		) );
		$rows = array_values( array_map( 'intval', is_array( $rows ) ? $rows : array() ) );
		if ( array() === $rows ) {
			$rows = array( $id );
		}
		$in = implode( ',', array_fill( 0, count( $rows ), '%d' ) );
		$refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mat} WHERE grupo_id IN ({$in})", ...$rows ) );
		if ( $refs > 0 ) {
			return new WP_Error( 'anpa_admin_grupo_in_use', __( 'Non se pode eliminar: o grupo ten matrículas ou histórico asociado.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		foreach ( $rows as $row_id ) {
			if ( ! ANPA_Socios_DB::delete_grupo_niveis( $row_id )
				|| false === $wpdb->delete( $table, array( 'id' => $row_id ), array( '%d' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo_serie', $uid, 'delete' );
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

		$id      = (int) $request->get_param( 'id' );
		$current = self::get_grupo_row( $id );
		if ( null === $current ) {
			return new WP_Error( 'anpa_admin_grupo_not_found', __( 'Grupo non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$estado = isset( $body['estado'] ) ? (string) $body['estado'] : '';
		if ( ! in_array( $estado, ANPA_Socios_Admin_Payload::GRUPO_ESTADO, true ) ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Estado inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$uid = (string) ( $current['serie_uid'] ?? '' );
		if ( '' === $uid ) {
			return new WP_Error( 'anpa_admin_grupo_legacy', __( 'Actualiza primeiro o esquema de grupos.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$updated = $wpdb->update(
			ANPA_Socios_DB::tabela_grupos(),
			array( 'estado' => $estado, 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'actividad_id' => (int) $current['actividad_id'], 'serie_uid' => $uid ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'grupo_serie', $uid, 'estado_' . $estado );
		return new WP_REST_Response( array( 'serie_uid' => $uid, 'estado' => $estado ), 200 );
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
				"SELECT m.id, m.activitad_id, m.trimestre, m.grupo_id, COALESCE(fc.curso, f.curso) AS curso, fc.nivel_id
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
				"SELECT id, actividad_id, curso_escolar, nome, max_pupilos FROM {$gru_t} WHERE id = %d",
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

		// Cross-year movement prevention: source and target must share curso_escolar.
		$source_curso_escolar = ! empty( $mat['grupo_id'] )
			? (string) $wpdb->get_var( $wpdb->prepare( "SELECT curso_escolar FROM {$gru_t} WHERE id = %d", (int) $mat['grupo_id'] ) )
			: '';
		if ( '' !== $source_curso_escolar && (string) $grupo['curso_escolar'] !== $source_curso_escolar ) {
			return new WP_Error(
				'anpa_admin_mover_curso_escolar',
				__( 'Non se pode mover entre cursos escolares diferentes', 'anpa-socios' ),
				array( 'status' => 409 )
			);
		}

		// Dynamic level fit.
		$nivel_id = (int) ( $mat['nivel_id'] ?? 0 );
		if ( $nivel_id <= 0 || ! in_array( $nivel_id, ANPA_Socios_DB::get_niveis_for_grupo( $target ), true ) ) {
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

	/** @return array<string,mixed>|WP_Error */
	private static function validate_series_payload( int $actividad_id, array $body ) {
		global $wpdb;

		$payload = ANPA_Socios_Grupo_Serie::normalize( $body );
		if ( array() === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Revisa o nome, cursos, niveis, horario, franxa, días e capacidade do grupo.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$offers = $wpdb->get_col( $wpdb->prepare(
			'SELECT curso_escolar FROM ' . ANPA_Socios_DB::tabela_actividades_cursos() . ' WHERE actividad_id = %d',
			$actividad_id
		) );
		$offers = is_array( $offers ) ? array_map( 'strval', $offers ) : array();
		foreach ( $payload['cursos'] as $curso ) {
			if ( ! in_array( $curso, $offers, true ) ) {
				return new WP_Error( 'anpa_admin_grupo_curso', __( 'Os cursos do grupo deben estar entre os anos nos que se oferta a actividade.', 'anpa-socios' ), array( 'status' => 400 ) );
			}
			if ( ! ANPA_Socios_DB::niveis_belong_to_curso( $payload['niveis_por_ano'][ $curso ], $curso ) ) {
				return new WP_Error( 'anpa_admin_grupo_niveis', __( 'Os niveis seleccionados deben pertencer ao curso escolar correspondente.', 'anpa-socios' ), array( 'status' => 400 ) );
			}
		}

		return $payload;
	}

	/**
	 * Persists every annual row in a logical group series atomically.
	 *
	 * @param array<string,mixed> $payload  Normalized series payload.
	 * @param array<int,array<string,mixed>> $existing Existing annual rows.
	 * @return array<string,int>|WP_Error Map curso_escolar => annual grupo id.
	 */
	private static function persist_series( int $actividad_id, string $uid, array $payload, array $existing ) {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_grupos();
		$mat   = ANPA_Socios_DB::tabela_matriculas();
		$by_year = array();
		foreach ( $existing as $row ) {
			$by_year[ (string) $row['curso_escolar'] ] = (int) $row['id'];
		}
		$removed = array_diff( array_keys( $by_year ), $payload['cursos'] );
		foreach ( $removed as $curso ) {
			$refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mat} WHERE grupo_id = %d", $by_year[ $curso ] ) );
			if ( $refs > 0 ) {
				return new WP_Error( 'anpa_admin_grupo_year_in_use', sprintf( __( 'Non se pode retirar o curso %s: o grupo ten matrículas ou histórico asociado.', 'anpa-socios' ), $curso ), array( 'status' => 409 ) );
			}
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		foreach ( $removed as $curso ) {
			$id = $by_year[ $curso ];
			if ( ! ANPA_Socios_DB::delete_grupo_niveis( $id ) || false === $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			unset( $by_year[ $curso ] );
		}

		foreach ( $payload['cursos'] as $curso ) {
			$data = array(
				'actividad_id'  => $actividad_id,
				'curso_escolar' => $curso,
				'serie_uid'     => $uid,
				'nome'          => $payload['nome'],
				'horario'       => $payload['horario'],
				'curso_range'   => '',
				'franxa'        => $payload['franxa'],
				'dias'          => $payload['dias'],
				'min_pupilos'   => $payload['min_pupilos'],
				'max_pupilos'   => $payload['max_pupilos'],
				'estado'        => $payload['estado'],
				'actualizado_en'=> current_time( 'mysql' ),
			);
			if ( isset( $by_year[ $curso ] ) ) {
				$id = $by_year[ $curso ];
				$ok = $wpdb->update( $table, $data, array( 'id' => $id ), null, array( '%d' ) );
			} else {
				$data['creado_en'] = current_time( 'mysql' );
				$ok = $wpdb->insert( $table, $data );
				$id = (int) $wpdb->insert_id;
				$by_year[ $curso ] = $id;
			}
			if ( false === $ok || $id <= 0 || ! ANPA_Socios_DB::delete_grupo_niveis( $id ) || ! ANPA_Socios_DB::insert_grupo_niveis( $id, $payload['niveis_por_ano'][ $curso ] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return $by_year;
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
				"SELECT actividad_id AS id, curso_escolar, grupos, dias, nivel_min_id, nivel_max_id FROM {$table} WHERE actividad_id = %d AND curso_escolar = %s",
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
