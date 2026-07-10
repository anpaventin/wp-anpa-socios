<?php
/**
 * Area REST — Fillo course/grupo assignments per school year.
 *
 * A socio can manage their own children's course assignments (which
 * curso and aula the fillo is in for each curso_escolar). This is the
 * foundation for the pre-enrolment check (Capability H) and the
 * compatibility filter (Capability I).
 *
 * @since  1.14.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Socio-owned fillo cursos REST surface.
 *
 * @since 1.14.0
 */
final class ANPA_Socios_Fillo_Cursos_REST {

	/**
	 * REST namespace shared with the member area.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const REST_NAMESPACE = 'anpa-socios/v1';

	/**
	 * Registers the fillo cursos routes.
	 *
	 * @since  1.14.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/area/fillo/(?P<id>\d+)/cursos',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_cursos' ),
					'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_curso' ),
					'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/area/fillo/(?P<id>\d+)/cursos/(?P<curso_escolar>[^/]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_curso' ),
				'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
			)
		);
	}

	/**
	 * GET /area/fillo/<id>/cursos — list a fillo's course assignments.
	 *
	 * @since  1.14.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_cursos( WP_REST_Request $request ) {
		$familia_id = self::current_familia_id( $request );
		if ( 0 === $familia_id ) {
			return self::invalid_session_error();
		}

		$fillo_id = (int) $request->get_param( 'id' );
		if ( null === self::fetch_owned_fillo( $fillo_id, $familia_id ) ) {
			return self::not_found_error();
		}

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_fillos_cursos();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- scoped read of authenticated socio's own fillo assignments.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, curso_escolar, curso, aula, creado_en, actualizado_en
				FROM {$table}
				WHERE fillo_id = %d
				ORDER BY curso_escolar DESC",
				$fillo_id
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /area/fillo/<id>/cursos — create a course assignment.
	 *
	 * @since  1.14.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_curso( WP_REST_Request $request ) {
		$familia_id = self::current_familia_id( $request );
		if ( 0 === $familia_id ) {
			return self::invalid_session_error();
		}

		$fillo_id = (int) $request->get_param( 'id' );
		if ( null === self::fetch_owned_fillo( $fillo_id, $familia_id ) ) {
			return self::not_found_error();
		}

		$body = self::json_body( $request );
		$curso_escolar = isset( $body['curso_escolar'] ) ? sanitize_text_field( $body['curso_escolar'] ) : '';
		$curso         = isset( $body['curso'] ) ? sanitize_text_field( $body['curso'] ) : '';
		$aula          = isset( $body['aula'] ) ? sanitize_text_field( $body['aula'] ) : '';

		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso_escolar ) ) {
			return self::invalid_payload_error( 'curso_escolar inválido' );
		}
		if ( ! in_array( $curso, array( '1', '2', '3', '4', '5', '6' ), true ) ) {
			return self::invalid_payload_error( 'curso inválido' );
		}
		if ( ! in_array( $aula, ANPA_Socios_Admin_Payload::GRUPO_VALIDOS, true ) ) {
			return self::invalid_payload_error( 'aula inválida' );
		}

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_fillos_cursos();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- insert gated by UNIQUE key.
		$inserted = $wpdb->insert(
			$table,
			array(
				'fillo_id'      => $fillo_id,
				'curso_escolar' => $curso_escolar,
				'curso'         => $curso,
				'aula'          => $aula,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// Duplicate key (fillo_id + curso_escolar unique) → 409.
			if ( $wpdb->last_error && false !== strpos( $wpdb->last_error, 'Duplicate' ) ) {
				return new WP_Error(
					'anpa_curso_dup',
					'Xa existe unha asignación para este curso escolar',
					array( 'status' => 409 )
				);
			}
			return self::db_error();
		}

		$row = self::fetch_curso_row( $fillo_id, $curso_escolar );

		return new WP_REST_Response( null === $row ? array() : $row, 201 );
	}

	/**
	 * PUT /area/fillo/<id>/cursos/<curso_escolar> — update a course assignment.
	 *
	 * @since  1.14.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_curso( WP_REST_Request $request ) {
		$familia_id = self::current_familia_id( $request );
		if ( 0 === $familia_id ) {
			return self::invalid_session_error();
		}

		$fillo_id = (int) $request->get_param( 'id' );
		if ( null === self::fetch_owned_fillo( $fillo_id, $familia_id ) ) {
			return self::not_found_error();
		}

		$curso_escolar = sanitize_text_field( $request->get_param( 'curso_escolar' ) );
		// Normalise URL-safe separator back to slash (Gitea router may receive '2025-2026').
		$curso_escolar = str_replace( '-', '/', $curso_escolar );

		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso_escolar ) ) {
			return self::not_found_error();
		}

		$existing = self::fetch_curso_row( $fillo_id, $curso_escolar );
		if ( null === $existing ) {
			return self::not_found_error();
		}

		$body = self::json_body( $request );
		$curso = isset( $body['curso'] ) ? sanitize_text_field( $body['curso'] ) : $existing['curso'];
		$aula  = isset( $body['aula'] ) ? sanitize_text_field( $body['aula'] ) : $existing['aula'];

		if ( ! in_array( $curso, array( '1', '2', '3', '4', '5', '6' ), true ) ) {
			return self::invalid_payload_error( 'curso inválido' );
		}
		if ( ! in_array( $aula, ANPA_Socios_Admin_Payload::GRUPO_VALIDOS, true ) ) {
			return self::invalid_payload_error( 'aula inválida' );
		}

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_fillos_cursos();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- update scoped to fillo_id + curso_escolar.
		$updated = $wpdb->update(
			$table,
			array(
				'curso'          => $curso,
				'aula'           => $aula,
				'actualizado_en' => current_time( 'mysql' ),
			),
			array(
				'fillo_id'      => $fillo_id,
				'curso_escolar' => $curso_escolar,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $updated ) {
			return self::db_error();
		}

		$row = self::fetch_curso_row( $fillo_id, $curso_escolar );

		return new WP_REST_Response( null === $row ? array() : $row, 200 );
	}

	/**
	 * Fetches a single curso assignment row.
	 *
	 * @since  1.14.0
	 * @param  int    $fillo_id      Fillo id.
	 * @param  string $curso_escolar e.g. '2026/2027'.
	 * @return array<string,string>|null
	 */
	private static function fetch_curso_row( int $fillo_id, string $curso_escolar ): ?array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_fillos_cursos();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- scoped read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, fillo_id, curso_escolar, curso, aula, creado_en, actualizado_en
				FROM {$table}
				WHERE fillo_id = %d AND curso_escolar = %s
				LIMIT 1",
				$fillo_id,
				$curso_escolar
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetches a single fillo only if it belongs to the given socio.
	 *
	 * Reuses the same ownership-check pattern as ANPA_Socios_Fillos_REST.
	 *
	 * @since  1.14.0
	 * @param  int    $id    Fillo id.
	 * @param  string $email Owning socio email.
	 * @return array<string,string>|null
	 */
	private static function fetch_owned_fillo( int $id, int $familia_id ): ?array {
		if ( $id <= 0 || $familia_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'anpa_fillos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ownership check by id AND familia_id.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, nome, apelidos, data_nacemento, curso, aula, estado FROM {$table} WHERE id = %d AND familia_id = %d AND estado <> 'baixa' LIMIT 1",
				$id,
				$familia_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the resolved familia_id for the authenticated socio.
	 *
	 * @since  1.21.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return int Resolved familia_id, or 0 when unavailable.
	 */
	private static function current_familia_id( WP_REST_Request $request ): int {
		$profile = $request->get_param( '_anpa_area_profile' );
		if ( ! is_array( $profile ) ) {
			return 0;
		}

		return ANPA_Socios_Familia::resolve_from_profile( $profile );
	}

	/**
	 * Returns the authenticated socio email stashed by the permission callback.
	 *
	 * @since  1.14.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return string Lowercased email, or '' when absent.
	 */
	private static function current_email( WP_REST_Request $request ): string {
		$profile = $request->get_param( '_anpa_area_profile' );
		if ( ! is_array( $profile ) || empty( $profile['email'] ) ) {
			return '';
		}

		return strtolower( trim( (string) $profile['email'] ) );
	}

	/**
	 * Decodes the JSON request body.
	 *
	 * @since  1.14.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 */
	private static function json_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Invalid session error (401).
	 *
	 * @since  1.14.0
	 * @return WP_Error
	 */
	private static function invalid_session_error(): WP_Error {
		return new WP_Error( 'anpa_fillo_cursos_invalid', 'Sesión inválida ou caducada', array( 'status' => 401 ) );
	}

	/**
	 * Invalid payload error (400).
	 *
	 * @since  1.14.0
	 * @param  string $detail Optional detail message.
	 * @return WP_Error
	 */
	private static function invalid_payload_error( string $detail = 'Datos inválidos' ): WP_Error {
		return new WP_Error( 'anpa_fillo_cursos_invalid_payload', $detail, array( 'status' => 400 ) );
	}

	/**
	 * Not-found error (404).
	 *
	 * @since  1.14.0
	 * @return WP_Error
	 */
	private static function not_found_error(): WP_Error {
		return new WP_Error( 'anpa_fillo_cursos_not_found', 'Non atopado', array( 'status' => 404 ) );
	}

	/**
	 * Generic internal DB error (500).
	 *
	 * @since  1.14.0
	 * @return WP_Error
	 */
	private static function db_error(): WP_Error {
		return new WP_Error( 'anpa_fillo_cursos_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
	}
}
