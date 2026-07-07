<?php
/**
 * REST endpoints for a socio to manage their own children (fillos).
 *
 * Authorization reuses the canonical member-area session
 * (`ANPA_Socios_Area_REST::permission_area_session`): every request is
 * tied to an active socio, and every query is scoped to that socio's
 * email. A socio can never read or mutate another socio's fillos.
 *
 * @since  1.4.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Socio-owned fillos REST surface.
 *
 * @since 1.4.0
 */
final class ANPA_Socios_Fillos_REST {

	/**
	 * REST namespace shared with the member area.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const REST_NAMESPACE = 'anpa-socios/v1';

	/**
	 * Registers the socio fillos routes.
	 *
	 * @since  1.4.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/fillos',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_fillos' ),
					'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_fillo' ),
					'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/fillo/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_fillo' ),
					'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_fillo' ),
					'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
				),
			)
		);
	}

	/**
	 * GET /fillos — lists the current socio's active fillos.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_fillos( WP_REST_Request $request ) {
		$email = self::current_email( $request );
		if ( '' === $email ) {
			return self::invalid_session_error();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'anpa_fillos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- scoped read of the authenticated socio's own children.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, nome, apelidos, data_nacemento, curso, aula, estado FROM {$table} WHERE socio_email = %s AND estado <> 'baixa' ORDER BY id ASC",
				$email
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /fillos — creates a fillo owned by the current socio.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_fillo( WP_REST_Request $request ) {
		$email = self::current_email( $request );
		if ( '' === $email ) {
			return self::invalid_session_error();
		}

		$payload = ANPA_Socios_Admin_Payload::validar_fillo( self::json_body( $request ) );
		if ( null === $payload ) {
			return self::invalid_payload_error();
		}

		// ── Fase 8b: Check for duplicate fillo by normalized name + apelidos ──
		$dup_check = self::check_duplicate_fillo( $payload, $email );
		if ( null !== $dup_check ) {
			return $dup_check;
		}

		// A socio always creates an active fillo; estado is not client-controlled here.
		$payload['estado']      = 'activo';
		$payload['socio_email'] = $email;

		global $wpdb;
		$table = $wpdb->prefix . 'anpa_fillos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- insert of the authenticated socio's own child.
		$inserted = $wpdb->insert(
			$table,
			$payload,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return self::db_error();
		}

		$row = self::fetch_owned_fillo( (int) $wpdb->insert_id, $email );
		if ( null !== $row ) {
			self::sync_current_course_assignment( (int) $row['id'], (string) $row['curso'], (string) $row['aula'] );
		}

		return new WP_REST_Response( null === $row ? array() : $row, 201 );
	}

	/**
	 * PATCH /fillo/<id> — updates a fillo the current socio owns.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_fillo( WP_REST_Request $request ) {
		$email = self::current_email( $request );
		if ( '' === $email ) {
			return self::invalid_session_error();
		}

		$id = (int) $request->get_param( 'id' );
		if ( null === self::fetch_owned_fillo( $id, $email ) ) {
			return self::not_found_error();
		}

		$payload = ANPA_Socios_Admin_Payload::validar_fillo( self::json_body( $request ) );
		if ( null === $payload ) {
			return self::invalid_payload_error();
		}

		// Socio cannot change estado via update; ownership column is immutable.
		$update = array(
			'nome'           => $payload['nome'],
			'apelidos'       => $payload['apelidos'],
			'data_nacemento' => $payload['data_nacemento'],
			'curso'          => $payload['curso'],
			'aula'           => $payload['aula'],
			'actualizado_en' => current_time( 'mysql' ),
		);

		global $wpdb;
		$table = $wpdb->prefix . 'anpa_fillos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- update scoped to id AND owning socio email.
		$updated = $wpdb->update(
			$table,
			$update,
			array(
				'id'          => $id,
				'socio_email' => $email,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			return self::db_error();
		}

		$row = self::fetch_owned_fillo( $id, $email );
		if ( null !== $row ) {
			self::sync_current_course_assignment( (int) $row['id'], (string) $row['curso'], (string) $row['aula'] );
		}

		return new WP_REST_Response( null === $row ? array() : $row, 200 );
	}

	/**
	 * DELETE /fillo/<id> — soft-deletes a fillo the current socio owns.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_fillo( WP_REST_Request $request ) {
		$email = self::current_email( $request );
		if ( '' === $email ) {
			return self::invalid_session_error();
		}

		$id = (int) $request->get_param( 'id' );
		if ( null === self::fetch_owned_fillo( $id, $email ) ) {
			return self::not_found_error();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'anpa_fillos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- soft delete scoped to id AND owning socio email.
		$updated = $wpdb->update(
			$table,
			array(
				'estado'         => 'baixa',
				'actualizado_en' => current_time( 'mysql' ),
			),
			array(
				'id'          => $id,
				'socio_email' => $email,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			return self::db_error();
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Returns the authenticated socio email stashed by the permission callback.
	 *
	 * @since  1.4.0
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
	 * Fetches a single fillo only if it belongs to the given socio and is
	 * not soft-deleted. Returns null when missing, not owned, or in baixa.
	 *
	 * @since  1.4.0
	 * @param  int    $id    Fillo id.
	 * @param  string $email Owning socio email.
	 * @return array<string,string>|null
	 */
	private static function fetch_owned_fillo( int $id, string $email ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'anpa_fillos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ownership check by id AND socio email.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, nome, apelidos, data_nacemento, curso, aula, estado FROM {$table} WHERE id = %d AND socio_email = %s AND estado <> 'baixa' LIMIT 1",
				$id,
				$email
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Upserts the fillo assignment for the current school year.
	 *
	 * Keeps legacy anpa_fillos.curso/aula and the new per-year
	 * anpa_fillos_cursos table in sync for the current course.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent upsert of authenticated socio's own fillo current-course assignment.
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

	/**
	 * Decodes the JSON request body.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 */
	private static function json_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Generic invalid-session error (401).
	 *
	 * @since  1.4.0
	 * @return WP_Error
	 */
	private static function invalid_session_error(): WP_Error {
		return new WP_Error( 'anpa_fillos_invalid', 'Sesión inválida ou caducada', array( 'status' => 401 ) );
	}

	/**
	 * Invalid payload error (400).
	 *
	 * @since  1.4.0
	 * @return WP_Error
	 */
	private static function invalid_payload_error(): WP_Error {
		return new WP_Error( 'anpa_fillos_invalid_payload', 'Datos inválidos', array( 'status' => 400 ) );
	}

	/**
	 * Not-found error (404). Same response for missing and not-owned, so
	 * ownership is not disclosed.
	 *
	 * @since  1.4.0
	 * @return WP_Error
	 */
	private static function not_found_error(): WP_Error {
		return new WP_Error( 'anpa_fillos_not_found', 'Non atopado', array( 'status' => 404 ) );
	}

	/**
	 * Generic internal DB error (500).
	 *
	 * @since  1.4.0
	 * @return WP_Error
	 */
	private static function db_error(): WP_Error {
		return new WP_Error( 'anpa_fillos_db_error', 'Erro interno', array( 'status' => 500 ) );
	}

	/**
	 * Checks if a fillo with the same nome+apelidos already exists for another
	 * active socio. Returns a WP_Error if found, null if safe to create.
	 *
	 * @since  1.18.0
	 * @param  array       $payload The fillo data being created.
	 * @param  string      $email   The current socio's email.
	 * @return WP_Error|null
	 */
	private static function check_duplicate_fillo( array $payload, string $email ): ?WP_Error {
		global $wpdb;
		$table = $wpdb->prefix . 'anpa_fillos';

		$nome_lc     = strtolower( trim( (string) $payload['nome'] ) );
		$apelidos_lc = strtolower( trim( (string) $payload['apelidos'] ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.socio_email, s.nome AS socio_nome, s.apelidos AS socio_apelidos
				 FROM {$table} f
				 JOIN {$wpdb->prefix}anpa_socios s ON s.email = f.socio_email
				 WHERE LOWER(f.nome) = %s AND LOWER(f.apelidos) = %s AND f.socio_email <> %s AND f.estado <> 'baixa'
				 LIMIT 1",
				$nome_lc,
				$apelidos_lc,
				$email
			),
			ARRAY_A
		);

		if ( ! is_array( $existing ) ) {
			return null;
		}

		// Mask the other socio's data.
		$other_email = (string) $existing['socio_email'];
		$parts       = explode( '@', $other_email );
		$local       = $parts[0] ?? '';
		$domain      = $parts[1] ?? '';
		$masked_email = substr( $local, 0, min( 3, strlen( $local ) ) ) . '***@' . $domain;

		$other_nome   = (string) $existing['socio_nome'];
		$other_apels  = (string) $existing['socio_apelidos'];
		$masked_nome  = strlen( $other_nome ) > 4 ? substr( $other_nome, 0, 4 ) . '***' : $other_nome;

		$apel_parts   = explode( ' ', $other_apels );
		$masked_apels = array();
		foreach ( $apel_parts as $part ) {
			if ( strlen( $part ) > 3 ) {
				$masked_apels[] = substr( $part, 0, 4 ) . '***';
			} else {
				$masked_apels[] = $part;
			}
		}
		$masked_full = $masked_nome . ' ' . implode( ' ', $masked_apels );

		return new WP_Error(
			'anpa_fillos_duplicate',
			sprintf(
				'O usuario con conta de correo %s e o nome %s xa ten ese fillo dado de alta. Se consideras que isto é un erro, ponte en contacto co ANPA no correo xunta.directiva@anpaventin.es',
				$masked_email,
				$masked_full
			),
			array( 'status' => 409 )
		);
	}
}
