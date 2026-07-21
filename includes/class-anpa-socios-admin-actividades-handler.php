<?php
/**
 * Admin REST handler for the actividades domain.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/actividad*` endpoints.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_Actividades_Handler {

	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividades', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_actividades' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_actividad' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_actividad' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_actividad' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );

		// POST /admin/actividad/{id}/copy-to-current
		// Legacy route kept for back-compat — uses current course.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)/copy-to-current', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'copy_actividad_to_current' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );

		// POST /admin/actividad/{id}/duplicate
		// Duplicates an activity into a chosen target school year.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)/duplicate', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate_actividad' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );

		// GET /admin/actividad/{id}/horario-diagnostic?curso_escolar=YYYY/YYYY
		// Read-only diagnostic: why an activity IS or IS NOT in the public horario.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)/horario-diagnostic', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'horario_diagnostic' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
	}

	/**
	 * GET /admin/actividades.
	 *
	 * Returns one row per descriptive activity. Annual presence, current-course
	 * status and offered years are derived exclusively from annual groups.
	 * Activities without groups remain visible so their first group can be made.
	 *
	 * @since 30.0.0
	 * @return WP_REST_Response
	 */
	public static function list_actividades(): WP_REST_Response {
		global $wpdb;

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		// One row per base activity. Activities without current groups must remain
		// administrable so the board can create their first annual group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$base_rows = $wpdb->get_results( "SELECT * FROM {$act_t} ORDER BY nome ASC", ARRAY_A );
		$base_rows = is_array( $base_rows ) ? $base_rows : array();
		if ( array() === $base_rows ) {
			return new WP_REST_Response( array(), 200 );
		}

		$ids     = array_map( static function ( $row ) { return (int) $row['id']; }, $base_rows );
		$id_list = implode( ',', $ids );
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- integer ids from DB.
		$group_rows = $wpdb->get_results(
			"SELECT actividad_id, curso_escolar, estado FROM {$gru_t} WHERE actividad_id IN ({$id_list}) ORDER BY curso_escolar ASC, id ASC",
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $group_rows ) ) {
			$group_rows = array();
		}

		$rows = ANPA_Socios_Activity_Group_Projection::build(
			$base_rows,
			$group_rows,
			(string) ANPA_Socios_Curso_Activo::get()
		);

		return new WP_REST_Response( $rows, 200 );
	}

	public static function create_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$body = ANPA_Socios_Admin_Shared::json_body( $request );
		$err  = self::validar_campos_actividad( $body );
		if ( null !== $err ) {
			return $err;
		}
		$payload = ANPA_Socios_Admin_Payload::validar_actividad( $body );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$inserted = $wpdb->insert(
			ANPA_Socios_DB::tabela_actividades(),
			self::base_payload( $payload ),
			array( '%d', '%s', '%s', '%s', '%f', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$actividad_id = (int) $wpdb->insert_id;
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $actividad_id, 'create' );
		return new WP_REST_Response( self::get_row( $actividad_id ), 201 );
	}

	public static function update_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$id   = (int) $request->get_param( 'id' );
		$body = ANPA_Socios_Admin_Shared::json_body( $request );
		$err  = self::validar_campos_actividad( $body );
		if ( null !== $err ) {
			return $err;
		}
		$payload = ANPA_Socios_Admin_Payload::validar_actividad( $body );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$table  = ANPA_Socios_DB::tabela_actividades();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) );
		if ( 0 === $exists ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Actividade non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		$base                   = self::base_payload( $payload );
		$base['actualizado_en'] = current_time( 'mysql' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$updated = $wpdb->update(
			$table,
			$base,
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s', '%f', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( $updated > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $id, 'update' );
		}

		return new WP_REST_Response( self::get_row( $id ), 200 );
	}

	public static function delete_actividad( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$table = ANPA_Socios_DB::tabela_actividades();
		$groups = ANPA_Socios_DB::tabela_grupos();
		$relations = ANPA_Socios_DB::tabela_grupos_niveis();
		$matriculas = ANPA_Socios_DB::tabela_matriculas();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$wpdb->last_error = '';
		$estado = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$table} WHERE id = %d FOR UPDATE", $id ) );
		if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		if ( null === $estado ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_not_found', __( 'Actividade non atopada.', 'anpa-socios' ), array( 'status' => 404 ) ); }
		if ( 'inactivo' !== $estado ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_must_deactivate', __( 'Desactiva a actividade antes de eliminala.', 'anpa-socios' ), array( 'status' => 409 ) ); }
		$wpdb->last_error = '';
		$group_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$groups} WHERE actividad_id = %d ORDER BY id FOR UPDATE", $id ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $group_ids ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Non se puideron comprobar os grupos.', 'anpa-socios' ), array( 'status' => 500 ) ); }
		$ref_sql = "SELECT id FROM {$matriculas} WHERE activitad_id = %d";
		$ref_args = array( $id );
		if ( $group_ids ) {
			$in = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
			$ref_sql .= " OR grupo_id IN ({$in})";
			$ref_args = array_merge( $ref_args, array_map( 'intval', $group_ids ) );
		}
		$wpdb->last_error = '';
		$refs = $wpdb->get_col( $wpdb->prepare( $ref_sql . ' ORDER BY id FOR UPDATE', ...$ref_args ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $refs ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido comprobar o histórico da actividade.', 'anpa-socios' ), array( 'status' => 500 ) ); }
		if ( $refs ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_actividad_has_data', __( 'Non se pode eliminar a actividade porque ten matrículas asociadas.', 'anpa-socios' ), array( 'status' => 409 ) ); }
		if ( $group_ids ) {
			$in = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
			if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$relations} WHERE grupo_id IN ({$in})", ...array_map( 'intval', $group_ids ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		}
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$groups} WHERE actividad_id = %d", $id ) ) || 1 !== (int) $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) ); }
		ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $id, 'delete' );
		return new WP_REST_Response( null, 204 );
	}

	/** @param array<string,mixed> $payload */
	private static function base_payload( array $payload ): array {
		// PR-GA5: the base actividades row keeps only descriptive fields.
		// Schedule, capacity and range live in annual offers and groups.
		return array(
			'empresa_id'    => (int) $payload['empresa_id'],
			'nome'          => (string) $payload['nome'],
			'icono'         => (string) $payload['icono'],
			'descripcion'   => (string) $payload['descripcion'],
			'custo'         => (float) $payload['custo'],
			'estado'        => (string) $payload['estado'],
		);
	}

	private static function get_row( int $id ): array {
		global $wpdb;

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$base  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$act_t} WHERE id = %d", $id ),
			ARRAY_A
		);
		if ( ! is_array( $base ) ) {
			return array();
		}
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT actividad_id, curso_escolar, estado FROM {$gru_t} WHERE actividad_id = %d ORDER BY curso_escolar ASC, id ASC",
				$id
			),
			ARRAY_A
		);
		$rows = ANPA_Socios_Activity_Group_Projection::build(
			array( $base ),
			is_array( $groups ) ? $groups : array(),
			(string) ANPA_Socios_Curso_Activo::get()
		);

		return $rows[0] ?? array();
	}

	/**
	 * Legacy alias. Fase30 duplicates only the descriptive activity identity.
	 */
	public static function copy_actividad_to_current( WP_REST_Request $request ) {
		return self::duplicate_actividad( $request );
	}

	/**
	 * Duplicates only the descriptive identity. Annual groups are never copied.
	 */
	public static function duplicate_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$src_id = (int) $request->get_param( 'id' );
		$act_t  = ANPA_Socios_DB::tabela_actividades();
		$src    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$act_t} WHERE id = %d", $src_id ),
			ARRAY_A
		);
		if ( ! is_array( $src ) ) {
			return new WP_Error( 'anpa_admin_not_found', 'Actividade non atopada.', array( 'status' => 404 ) );
		}

		$copy = array(
			'empresa_id'  => (int) ( $src['empresa_id'] ?? 0 ),
			'nome'        => (string) ( $src['nome'] ?? '' ) . ' (copia)',
			'icono'       => (string) ( $src['icono'] ?? '' ),
			'descripcion' => (string) ( $src['descripcion'] ?? '' ),
			'custo'       => (float) ( $src['custo'] ?? 0 ),
			'estado'      => 'activo',
		);
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$ok = $wpdb->insert( $act_t, $copy, array( '%d', '%s', '%s', '%s', '%f', '%s' ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_socios_db_error', 'Erro ao crear a actividade duplicada.', array( 'status' => 500 ) );
		}
		$new_id = (int) $wpdb->insert_id;
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $new_id, 'duplicate_from_' . $src_id );
		return new WP_REST_Response( self::get_row( $new_id ), 201 );
	}

	/**
	 * Field-level validation for actividad required fields.
	 *
	 * Returns specific error messages for common missing/invalid fields,
	 * or null if all required fields pass basic checks.
	 *
	 * @since  1.34.0
	 * @param  array<string,mixed> $body Raw request body.
	 * @return WP_Error|null
	 */
	private static function validar_campos_actividad( array $body ) {
		$issue = ANPA_Socios_Admin_Payload::diagnosticar_actividad( $body );
		if ( null === $issue ) {
			return null;
		}
		$messages = array(
			'empresa_required'       => __( 'Selecciona unha empresa válida.', 'anpa-socios' ),
			'nome_required'          => __( 'O nome da actividade é obrigatorio.', 'anpa-socios' ),
			'descripcion_required'   => __( 'A descrición é obrigatoria.', 'anpa-socios' ),

			'custo_invalid'          => __( 'O custo debe ser un número válido.', 'anpa-socios' ),
			'estado_invalid'         => __( 'O estado da actividade non é válido.', 'anpa-socios' ),
		);

		return new WP_Error( 'anpa_admin_' . $issue, $messages[ $issue ] ?? __( 'Revisa os datos da actividade.', 'anpa-socios' ), array( 'status' => 400 ) );
	}

	/**
	 * Admin-only diagnostic: why an activity IS or IS NOT in the public horario.
	 *
	 * GET /admin/actividad/{id}/horario-diagnostic?curso_escolar=YYYY/YYYY
	 * Returns one of: incluida_por_grupo,
	 * sen_franxa, sen_dias, sen_grupo_aberto, estado_inactivo, curso_non_activo.
	 *
	 * @since  1.27.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function horario_diagnostic( WP_REST_Request $request ) {
		global $wpdb;

		$id            = (int) $request['id'];
		$curso_escolar = sanitize_text_field( $request->get_param( 'curso_escolar' ) ?? '' );

		// Default to current active curso if not specified.
		if ( '' === $curso_escolar ) {
			$curso_escolar = ANPA_Socios_Curso_Activo::get() ?? '';
		}
		if ( '' === $curso_escolar ) {
			return new WP_Error( 'anpa_admin_no_curso', 'Non hai curso activo.', array( 'status' => 400 ) );
		}

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		// Fetch the descriptive activity. Annual presence comes only from groups.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$activity = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT nome, estado FROM {$act_t} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( null === $activity ) {
			return new WP_Error( 'anpa_admin_not_found', 'Actividade non atopada.', array( 'status' => 404 ) );
		}

		// Determine whether the curso_escolar is the currently active one.
		$curso_is_active = ( $curso_escolar === ANPA_Socios_Curso_Activo::get() );

		// Fetch all grupos for this (actividad, curso) pair (any estado).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$grupos = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.estado, g.franxa, g.dias FROM {$gru_t} g WHERE g.actividad_id = %d AND g.curso_escolar = %s",
				$id,
				$curso_escolar
			),
			ARRAY_A
		);
		if ( ! is_array( $grupos ) ) {
			$grupos = array();
		}

		$reason = ANPA_Socios_Horario_Builder::diagnose( $activity, $grupos, $curso_is_active );

		return new WP_REST_Response( array(
			'actividad_id'  => $id,
			'curso_escolar' => $curso_escolar,
			'reason'        => $reason,
		) );
	}
}
