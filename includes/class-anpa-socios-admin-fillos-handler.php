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
		$payload = ANPA_Socios_Admin_Payload::validar_fillo( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$payload['socio_email'] = $email;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'anpa_fillos',
			$payload,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'fillo', (string) $wpdb->insert_id, 'create' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, socio_email, nome, apelidos, data_nacemento, curso, aula, estado FROM {$wpdb->prefix}anpa_fillos WHERE id = %d",
				$wpdb->insert_id
			),
			ARRAY_A
		);
		if ( is_array( $row ) ) {
			self::sync_current_course_assignment( (int) $row['id'], (string) $row['curso'], (string) $row['aula'] );
		}

		return new WP_REST_Response( is_array( $row ) ? $row : array(), 201 );
	}

	/**
	 * PATCH /admin/fillo/<id>
	 */
	public static function update_fillo( WP_REST_Request $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$payload = ANPA_Socios_Admin_Payload::validar_fillo( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$payload['actualizado_en'] = current_time( 'mysql' );

		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_fillos',
			$payload,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'fillo', (string) $id, 'update' );
		self::sync_current_course_assignment( $id, (string) $payload['curso'], (string) $payload['aula'] );

		return new WP_REST_Response( $payload + array( 'id' => $id ), 200 );
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
	 * DELETE /admin/fillo/<id> (soft delete to estado=baixa).
	 */
	public static function delete_fillo( WP_REST_Request $request ) {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );
		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_fillos',
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
		if ( ! in_array( $curso, array( '1', '2', '3', '4', '5', '6' ), true ) || ! in_array( $aula, array( 'A', 'B', 'C', 'D' ), true ) ) {
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
	 * @since  1.14.0
	 * @param  int    $fillo_id Fillo id.
	 * @param  string $curso    Curso 1-6.
	 * @param  string $aula     Aula A-D.
	 * @return void
	 */
	private static function sync_current_course_assignment( int $fillo_id, string $curso, string $aula ): void {
		if ( $fillo_id <= 0 || ! in_array( $curso, array( '1', '2', '3', '4', '5', '6' ), true ) || ! in_array( $aula, array( 'A', 'B', 'C', 'D' ), true ) ) {
			return;
		}

		global $wpdb;
		$table         = ANPA_Socios_DB::tabela_fillos_cursos();
		$curso_escolar = ANPA_Socios_Curso_Escolar::current();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent admin upsert of current-course assignment.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (fillo_id, curso_escolar, curso, aula)
				VALUES (%d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE curso = VALUES(curso), aula = VALUES(aula), actualizado_en = %s",
				$fillo_id,
				$curso_escolar,
				$curso,
				$aula,
				current_time( 'mysql' )
			)
		);
	}
}
