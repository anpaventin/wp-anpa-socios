<?php
/**
 * Admin REST handler for the fillos domain.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the admin fillos endpoints.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_Fillos_Handler {

	/**
	 * Registers fillos admin routes.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public static function register_routes(): void {
		// List-all: GET /admin/fillos — all fillos with parent info.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/fillos', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_all_fillos' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/socio/(?P<email>[^/]+)/fillos', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_fillos' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_fillo' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/fillo/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_fillo' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_fillo' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/fillo/(?P<id>\d+)/matriculas', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_fillo_matriculas' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/fillo/(?P<id>\d+)/cursos', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_fillo_cursos' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_fillo_curso' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/fillo/(?P<id>\d+)/cursos/(?P<curso_escolar>[^/]+)', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'update_fillo_curso' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/socio/<email>/fillos
	 */
	public static function list_fillos( WP_REST_Request $request ) {
		global $wpdb;

		// rawurldecode + sanitise_email: match the socios/admins handlers so the
		// email path segment is validated (FILTER_VALIDATE_EMAIL), not merely
		// lower-cased/trimmed. Defense-in-depth even though the query is prepared.
		$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Email inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, socio_email, nome, apelidos, data_nacemento, curso, aula, estado FROM {$wpdb->prefix}anpa_fillos WHERE socio_email = %s ORDER BY id ASC",
				$email
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * GET /admin/fillos — all fillos with parent socio info.
	 *
	 * @since  1.16.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_all_fillos( WP_REST_Request $request ) {
		global $wpdb;

		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$soc_t = ANPA_Socios_DB::tabela_socios();

		$rows = $wpdb->get_results(
			"SELECT f.id, f.socio_email, f.nome, f.apelidos, f.data_nacemento, f.curso, f.aula, f.estado,
			        s.nome AS proxenitor_nome, s.apelidos AS proxenitor_apelidos
			 FROM {$fil_t} f
			 LEFT JOIN {$soc_t} s ON s.email = f.socio_email
			 ORDER BY f.apelidos ASC, f.nome ASC",
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /admin/socio/<email>/fillos
	 */
	public static function create_fillo( WP_REST_Request $request ) {
		global $wpdb;

		$email   = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Email inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$body = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso_escolar = isset( $body['curso_escolar'] ) ? (string) $body['curso_escolar'] : '';
		$payload = ANPA_Socios_Admin_Payload::validar_fillo( $body, $curso_escolar );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		// data_nacemento is required for new fillos.
		if ( null === $payload['data_nacemento'] ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Data de nacemento obrigatoria', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$payload['socio_email'] = $email;

		// Resolve familia_id from the owning socio (fase18 schema slice).
		$soc_t   = ANPA_Socios_DB::tabela_socios();
		$soc_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, familia_id FROM {$soc_t} WHERE email = %s", $email ),
			ARRAY_A
		);
		if ( is_array( $soc_row ) ) {
			$payload['familia_id'] = ANPA_Socios_Familia::resolve_familia_id(
				null !== $soc_row['familia_id'] ? (int) $soc_row['familia_id'] : null,
				(int) $soc_row['id']
			);
		}

		$formats = array();
		foreach ( $payload as $key => $value ) {
			$formats[] = ( 'familia_id' === $key ) ? '%d' : '%s';
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'anpa_fillos',
			$payload,
			$formats
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// D94 root cause: write_audit() inserts its own row, clobbering
		// $wpdb->insert_id. Capture the fillo id FIRST or the re-select
		// misses, the fillos_cursos sync is skipped and the 201 body is [].
		$fillo_id = (int) $wpdb->insert_id;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, socio_email, nome, apelidos, data_nacemento, curso, aula, estado FROM {$wpdb->prefix}anpa_fillos WHERE id = %d",
				$fillo_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || ! self::sync_current_course_assignment( $fillo_id, (string) $payload['curso'], (string) $payload['aula'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido gardar a asignación anual do fillo/a.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'fillo', (string) $fillo_id, 'create' );
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $row, 201 );
	}

	/**
	 * PATCH /admin/fillo/<id>
	 */
	public static function update_fillo( WP_REST_Request $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$body    = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso_escolar = isset( $body['curso_escolar'] ) ? (string) $body['curso_escolar'] : '';
		if ( '' === $curso_escolar ) {
			$curso_escolar = ANPA_Socios_Curso_Activo::get() ?? '';
		}
		$payload = ANPA_Socios_Admin_Payload::validar_fillo( $body, $curso_escolar );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		// data_nacemento is optional on update; keep existing value when not provided.
		if ( null === $payload['data_nacemento'] ) {
			unset( $payload['data_nacemento'] );
		}
		$payload['actualizado_en'] = current_time( 'mysql' );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_fillos',
			$payload,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		if ( ! self::sync_current_course_assignment( $id, (string) $payload['curso'], (string) $payload['aula'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido gardar a asignación anual do fillo/a.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'fillo', (string) $id, 'update' );
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $payload + array( 'id' => $id ), 200 );
	}

	/**
	 * GET /admin/fillo/<id>/matriculas
	 *
	 * Read-only list of a fillo's matrículas across all years, newest school
	 * year first, for the informational panel under the fillo editor.
	 *
	 * @since  1.41.2
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_fillo_matriculas( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$id  = (int) $request->get_param( 'id' );
		$mat = ANPA_Socios_DB::tabela_matriculas();
		$gru = ANPA_Socios_DB::tabela_grupos();
		$act = ANPA_Socios_DB::tabela_actividades();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only informational list.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.id,
			        COALESCE(g.curso_escolar, '') AS curso_escolar,
			        COALESCE(a.nome, '') AS actividade,
			        COALESCE(g.franxa, '') AS franxa,
			        m.trimestre, m.estado, m.creado_en
			 FROM {$mat} m
			 LEFT JOIN {$gru} g ON g.id = m.grupo_id
			 LEFT JOIN {$act} a ON a.id = m.activitad_id
			 WHERE m.fillo_id = %d
			 ORDER BY g.curso_escolar DESC, m.creado_en DESC",
			$id
		), ARRAY_A );

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * GET /admin/fillo/<id>/cursos
	 */
	public static function list_fillo_cursos( WP_REST_Request $request ) {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 || ! self::fillo_exists( $id ) ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		$table = ANPA_Socios_DB::tabela_fillos_cursos();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, fillo_id, curso_escolar, curso, aula, creado_en, actualizado_en FROM {$table} WHERE fillo_id = %d ORDER BY curso_escolar DESC",
				$id
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /admin/fillo/<id>/cursos
	 */
	public static function create_fillo_curso( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$payload = self::validate_fillo_curso_payload( ANPA_Socios_Admin_Shared::json_body( $request ), true );
		if ( $id <= 0 || ! self::fillo_exists( $id ) ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table    = ANPA_Socios_DB::tabela_fillos_cursos();
		$inserted = $wpdb->insert(
			$table,
			array(
				'fillo_id'      => $id,
				'curso_escolar' => $payload['curso_escolar'],
				'curso'         => $payload['curso'],
				'aula'          => $payload['aula'],
			),
			array( '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'fillo_curso', (string) $wpdb->insert_id, 'create' );

		return new WP_REST_Response( self::fetch_fillo_curso_row( $id, $payload['curso_escolar'] ) ?: array(), 201 );
	}

	/**
	 * PUT /admin/fillo/<id>/cursos/<curso_escolar>
	 */
	public static function update_fillo_curso( WP_REST_Request $request ) {
		$id             = (int) $request->get_param( 'id' );
		$curso_escolar = str_replace( '-', '/', sanitize_text_field( (string) $request->get_param( 'curso_escolar' ) ) );
		$payload        = self::validate_fillo_curso_payload( ANPA_Socios_Admin_Shared::json_body( $request ), false );
		if ( $id <= 0 || ! self::fillo_exists( $id ) || ! ANPA_Socios_Curso_Escolar::is_valid( $curso_escolar ) ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table   = ANPA_Socios_DB::tabela_fillos_cursos();
		$updated = $wpdb->update(
			$table,
			array(
				'curso'          => $payload['curso'],
				'aula'           => $payload['aula'],
				'actualizado_en' => current_time( 'mysql' ),
			),
			array(
				'fillo_id'      => $id,
				'curso_escolar' => $curso_escolar,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit( $request, 'fillo_curso', (string) $id . ':' . $curso_escolar, 'update' );

		return new WP_REST_Response( self::fetch_fillo_curso_row( $id, $curso_escolar ) ?: array(), 200 );
	}

	/**
	 * DELETE /admin/fillo/<id>
	 *
	 * Default: soft delete (estado=baixa). With `?hard=1`: definitive removal,
	 * only allowed when the fillo/a is already in baixa AND has no matrículas
	 * associated; the annual assignments (fillos_cursos) are removed in the same
	 * transaction. Any matrícula blocks the hard delete with a 409.
	 */
	public static function delete_fillo( WP_REST_Request $request ) {
		global $wpdb;

		$id   = (int) $request->get_param( 'id' );
		$hard = in_array( (string) $request->get_param( 'hard' ), array( '1', 'true' ), true );

		$fillos_t = ANPA_Socios_DB::tabela_fillos();
		$wpdb->last_error = '';
		$estado = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$fillos_t} WHERE id = %d", $id ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( null === $estado ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Fillo/a non atopado.', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		if ( $hard ) {
			if ( 'baixa' !== $estado ) {
				return new WP_Error( 'anpa_admin_must_deactivate', __( 'Desactiva o fillo/a antes de eliminalo definitivamente.', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			$mat_t = ANPA_Socios_DB::tabela_matriculas();
			$fc_t = ANPA_Socios_DB::tabela_fillos_cursos();
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			$wpdb->last_error = '';
			$locked_estado = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$fillos_t} WHERE id = %d FOR UPDATE", $id ) );
			if ( '' !== (string) $wpdb->last_error || 'baixa' !== $locked_estado ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_fillo_changed', __( 'O fillo/a cambiou mentres se procesaba o borrado. Recarga e téntao de novo.', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			$wpdb->last_error = '';
			$refs = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$mat_t} WHERE fillo_id = %d ORDER BY id FOR UPDATE", $id ) );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $refs ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			if ( array() !== $refs ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_fillo_has_data', __( 'Non se pode eliminar: o fillo/a ten matrículas asociadas.', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			$deleted_assignments = $wpdb->query( $wpdb->prepare( "DELETE FROM {$fc_t} WHERE fillo_id = %d", $id ) );
			if ( false === $deleted_assignments ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			$deleted = $wpdb->delete( $fillos_t, array( 'id' => $id ), array( '%d' ) );
			if ( 1 !== $deleted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			ANPA_Socios_Admin_Shared::write_audit( $request, 'fillo', (string) $id, 'delete_hard' );
			return new WP_REST_Response( null, 204 );
		}

		$updated = $wpdb->update(
			$fillos_t,
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

		ANPA_Socios_Admin_Shared::write_audit( $request, 'fillo', (string) $id, 'delete' );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Returns whether a fillo exists.
	 *
	 * @since  1.14.0
	 * @param  int $fillo_id Fillo id.
	 * @return bool
	 */
	private static function fillo_exists( int $fillo_id ): bool {
		if ( $fillo_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_fillos();

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d LIMIT 1", $fillo_id ) );
	}

	/**
	 * Validates a fillo_cursos payload.
	 *
	 * @since  1.14.0
	 * @param  array<string,mixed> $body           Request body.
	 * @param  bool                $require_curso  Whether curso_escolar is required.
	 * @return array<string,string>|null
	 */
	private static function validate_fillo_curso_payload( array $body, bool $require_curso ): ?array {
		$curso_escolar = isset( $body['curso_escolar'] ) ? sanitize_text_field( (string) $body['curso_escolar'] ) : '';
		$curso         = isset( $body['curso'] ) ? sanitize_text_field( (string) $body['curso'] ) : '';
		$aula          = isset( $body['aula'] ) ? sanitize_text_field( (string) $body['aula'] ) : '';
		if ( $require_curso && ! ANPA_Socios_Curso_Escolar::is_valid( $curso_escolar ) ) {
			return null;
		}
		// Dynamic per-curso_escolar validation when available.
		$ce = '' !== $curso_escolar ? $curso_escolar : ANPA_Socios_Curso_Escolar::current();
		if ( ! ANPA_Socios_Admin_Payload::curso_valido_db( $curso, $ce ) || ! ANPA_Socios_Admin_Payload::aula_valida_db( $aula, $ce ) ) {
			return null;
		}

		return array(
			'curso_escolar' => $curso_escolar,
			'curso'         => $curso,
			'aula'          => $aula,
		);
	}

	/**
	 * Fetches one fillo_cursos row.
	 *
	 * @since  1.14.0
	 * @param  int    $fillo_id      Fillo id.
	 * @param  string $curso_escolar Curso escolar.
	 * @return array<string,mixed>|null
	 */
	private static function fetch_fillo_curso_row( int $fillo_id, string $curso_escolar ): ?array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_fillos_cursos();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, fillo_id, curso_escolar, curso, aula, creado_en, actualizado_en FROM {$table} WHERE fillo_id = %d AND curso_escolar = %s LIMIT 1",
				$fillo_id,
				$curso_escolar
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Upserts the fillo assignment for the current school year.
	 *
	 * Thin wrapper over the canonical shared helper so that callers inside
	 * this handler keep their existing interface unchanged.
	 *
	 * @since  1.14.0
	 * @param  int    $fillo_id Fillo id.
	 * @param  string $curso    Curso 1-6.
	 * @param  string $aula     Aula A-H.
	 * @return bool
	 */
	private static function sync_current_course_assignment( int $fillo_id, string $curso, string $aula ): bool {
		if ( $fillo_id <= 0 ) {
			return false;
		}

		$curso_escolar = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso_escolar ) {
			return false;
		}

		// D94: the payload was already validated by validar_fillo() and the
		// upsert resolves nivel_id/aula_id safely (NULL when unmapped), so
		// re-validating here silently skipped the annual row on create.
		return ANPA_Socios_DB::upsert_fillo_curso_assignment( $fillo_id, $curso_escolar, $curso, $aula );
	}
}
