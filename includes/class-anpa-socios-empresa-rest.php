<?php
/**
 * REST endpoints for the empresa authenticated surface.
 *
 * Reuses the canonical session validator from ANPA_Socios_Area_REST
 * with an empresa-specific session context (different table, header,
 * and principal lookup). No weaker gate is introduced.
 *
 * @since  1.4.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the empresa REST endpoints.
 *
 * @since 1.4.0
 */
class ANPA_Socios_Empresa_REST {

	/**
	 * REST namespace shared by the anpa-socios plugin.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const REST_NAMESPACE = 'anpa-socios/v1';

	/**
	 * Registers all empresa routes.
	 *
	 * @since  1.4.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/empresa/solicitar-codigo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_solicitar_codigo' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'website' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'_ts'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/empresa/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_create_session' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== $value && strlen( $value ) <= 64;
						},
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/empresa/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_me' ),
				'permission_callback' => array( __CLASS__, 'permission_empresa' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/empresa/me/session',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'handle_delete_session' ),
				'permission_callback' => array( __CLASS__, 'permission_empresa' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/empresa/me/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_export' ),
				'permission_callback' => array( __CLASS__, 'permission_empresa' ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// Permission gate
	// ──────────────────────────────────────────────

	/**
	 * Permission callback for authenticated empresa endpoints.
	 *
	 * Delegates to the canonical session authenticator with the empresa
	 * session context. On success stashes the session id and active
	 * empresa profile on the request.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function permission_empresa( WP_REST_Request $request ) {
		$auth = ANPA_Socios_Area_REST::authenticate_area_session(
			$request,
			'anpa_empresa_me_rl_',
			self::empresa_session_context()
		);
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$request->set_param( '_anpa_empresa_session_id', $auth['session_id'] );
		$request->set_param( '_anpa_empresa_profile', $auth['profile'] );

		return true;
	}

	/**
	 * Returns the empresa session context descriptor for the canonical
	 * session authenticator.
	 *
	 * @since  1.4.0
	 * @return array{table:string,token_header:string,principal:callable}
	 */
	private static function empresa_session_context(): array {
		return array(
			'table'        => ANPA_Socios_DB::tabela_sesions_empresas(),
			'token_header' => 'X-Anpa-Empresa-Token',
			'principal'    => array( __CLASS__, 'load_active_empresa' ),
		);
	}

	/**
	 * Loads an active empresa profile by email.
	 *
	 * Used as the principal callable by the session authenticator.
	 * Returns null when no active empresa exists (generic 401 by the
	 * authenticator — never reveals empresa membership).
	 *
	 * @since  1.4.0
	 * @param  string $email Empresa email.
	 * @return array<string,mixed>|WP_Error|null
	 */
	public static function load_active_empresa( string $email ) {
		global $wpdb;

		$table_name = ANPA_Socios_DB::tabela_empresas();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct lookup by email checks authoritative empresa state.
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, nome, email, responsable, telefono, url_web, estado FROM {$table_name} WHERE email = %s LIMIT 1",
				$email
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_area_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		if ( ! is_array( $row ) || 'activo' !== (string) $row['estado'] ) {
			return null;
		}

		return $row;
	}

	// ──────────────────────────────────────────────
	// Handlers
	// ──────────────────────────────────────────────

	/**
	 * Handles POST /empresa/solicitar-codigo — empresa passwordless code request.
	 *
	 * Mirrors the privacy and storage contract of anpa-verificacion's
	 * handle_solicitar_codigo (same shared table `wp_anpa_codigos_verificacion`
	 * consumed by `anpa/v1/verificar-codigo`). Key guarantees:
	 *  - Table-based rate limit: 3 codes per email+IP per hour.
	 *  - Active empresa lookup (WHERE estado='activo').
	 *  - Invalidate old unused codes before inserting a new one.
	 *  - Bcrypt hash stored (compatible with password_verify).
	 *  - Dummy bcrypt on not-found path to equalise timing.
	 *  - Always returns generic 200 (no information leak).
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_solicitar_codigo( WP_REST_Request $request ) {
		global $wpdb;

		$email = (string) $request->get_param( 'email' );

		// Step 1: validate email format.
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Email non válido', array( 'status' => 400 ) );
		}

		// Anti-bot: honeypot + time-trap. On detection, return the same
		// generic success response (no oracle — indistinguishable from real).
		$honeypot  = (string) $request->get_param( 'website' );
		$ts_raw    = $request->get_param( '_ts' );
		$render_ts = is_numeric( $ts_raw ) ? (int) $ts_raw : null;
		if ( ! ANPA_Socios_Antibot::passes( $honeypot, $render_ts, time() ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => 'Se o email está rexistrado, recibirás un código en breve',
				),
				200
			);
		}

		$tabela_codigos = $wpdb->prefix . 'anpa_codigos_verificacion';
		$ip             = self::get_request_ip();

		// Step 2: table-based rate limit — 3 codes/h by email+IP.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rate-limit query on shared codes table.
		$timestamps = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT UNIX_TIMESTAMP(creado_en) FROM {$tabela_codigos} "
				. 'WHERE email = %s AND ip = %s '
				. 'AND creado_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
				$email,
				$ip
			)
		);
		$timestamps = array_map( 'intval', is_array( $timestamps ) ? $timestamps : array() );

		if ( ! ANPA_Socios_Rate_Limiter::permitir( $timestamps, 3, 3600 ) ) {
			// Rate-limited: return the same generic response (no info leak).
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => 'Se o email está rexistrado, recibirás un código en breve',
				),
				200
			);
		}

		// Step 3: look up active empresa.
		$empresa_table = ANPA_Socios_DB::tabela_empresas();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lookup by email for empresa existence.
		$empresa = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$empresa_table} WHERE email = %s AND estado = %s LIMIT 1",
				$email,
				'activo'
			)
		);

		if ( $empresa ) {
			// Step 4: real path — active empresa found.
			// Invalidate old unused codes for this email.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- invalidate prior codes.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tabela_codigos} SET usado = 1 WHERE email = %s AND usado = 0",
					$email
				)
			);

			$codigo = ANPA_Socios_Codigo_Generator::generate();
			$hash   = ANPA_Socios_Codigo_Generator::hash_code( $codigo );
			$expira = gmdate( 'Y-m-d H:i:s', ANPA_Socios_Codigo_Generator::expiry( time() ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- INSERT verification code row.
			$inserted = $wpdb->insert(
				$tabela_codigos,
				array(
					'email'       => $email,
					'codigo_hash' => $hash,
					'expira_en'   => $expira,
					'intentos'    => 0,
					'usado'       => 0,
					'ip'          => $ip,
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s' )
			);

			if ( false !== $inserted ) {
				ANPA_Socios_Email::enviar_codigo( $email, $codigo );
			}
		} else {
			// Step 5: dummy path — one bcrypt to match timing.
			ANPA_Socios_Codigo_Generator::hash_code( ANPA_Socios_Codigo_Generator::generate() );
		}

		// Step 6: generic success response (both paths).
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Se o email está rexistrado, recibirás un código en breve',
			),
			200
		);
	}

	/**
	 * Handles POST /empresa/session — passwordless login for empresa.
	 *
	 * Reuses the Fase 1 email→code→token chain. The Fase 1 transient
	 * binds the token to an email; we verify the email belongs to an
	 * active empresa before issuing the session.
	 *
	 * NOTE (UX follow-up): the code-request email currently uses socio
	 * "alta"-worded copy. A neutral subject/body should be added in a
	 * future UX PR (non-blocking, non-auth).
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_create_session( WP_REST_Request $request ) {
		if ( ! self::consume_rate_limit( 'anpa_empresa_session_rl_', 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'anpa_area_rate_limited', 'Demasiadas solicitudes', array( 'status' => 429 ) );
		}

		$fase1_token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$email       = get_transient( 'anpa_token_' . $fase1_token );
		if ( false === $email || ! is_string( $email ) || '' === $email ) {
			return self::invalid_session_error();
		}

		$profile = self::load_active_empresa( $email );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		if ( null === $profile ) {
			return self::invalid_session_error();
		}

		$result = ANPA_Socios_Area_REST::issue_session(
			ANPA_Socios_DB::tabela_sesions_empresas(),
			(string) $profile['email']
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_transient( 'anpa_token_' . $fase1_token );

		return new WP_REST_Response(
			array(
				'session_token' => $result['session_token'],
				'expires_in'    => $result['expires_in'],
				'max_uses'      => $result['max_uses'],
				'email'         => (string) $profile['email'],
				'nome'          => (string) $profile['nome'],
			),
			200
		);
	}

	/**
	 * Handles GET /empresa/me.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_get_me( WP_REST_Request $request ): WP_REST_Response {
		$profile = $request->get_param( '_anpa_empresa_profile' );

		return new WP_REST_Response(
			ANPA_Socios_Empresa_View::public_empresa( is_array( $profile ) ? $profile : array() ),
			200
		);
	}

	/**
	 * Handles DELETE /empresa/me/session — logout.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete_session( WP_REST_Request $request ) {
		$session_id = (int) $request->get_param( '_anpa_empresa_session_id' );
		if ( $session_id <= 0 ) {
			return self::invalid_session_error();
		}

		ANPA_Socios_Area_REST::delete_session_row(
			ANPA_Socios_DB::tabela_sesions_empresas(),
			$session_id
		);

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Handles GET /empresa/me/export.
	 *
	 * Returns a CSV file with all active alumnos enrolled in activities
	 * owned by this empresa.
	 *
	 * @since  1.5.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_export( WP_REST_Request $request ) {
		$profile    = $request->get_param( '_anpa_empresa_profile' );
		$empresa_id = is_array( $profile ) && isset( $profile['id'] ) ? (int) $profile['id'] : 0;

		if ( $empresa_id <= 0 ) {
			return new WP_Error( 'anpa_empresa_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		$rows = ANPA_Socios_Alumnos_Export::rows( $empresa_id );
		if ( null === $rows ) {
			return new WP_Error( 'anpa_empresa_db_error', 'Erro interno ao exportar', array( 'status' => 500 ) );
		}

		$columns  = ANPA_Socios_Alumnos_Export::columns( false );
		$csv      = ANPA_Socios_Csv::document( $columns, $rows );
		$filename = 'alumnos-empresa.csv';

		// Audit the export action.
		$empresa_email = is_array( $profile ) && isset( $profile['email'] ) ? (string) $profile['email'] : '';
		ANPA_Socios_Admin_Shared::write_audit_actor(
			$empresa_email,
			'empresa',
			'export',
			(string) count( $rows ),
			'export_alumnos_empresa'
		);

		$response = new WP_REST_Response( null, 200 );
		$response->set_headers( array(
			'Content-Type'        => 'text/csv; charset=utf-8',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			'Content-Length'      => (string) strlen( $csv ),
			'Cache-Control'       => 'no-store',
		) );

		// Override REST JSON serialization: send raw CSV via filter.
		add_filter( 'rest_pre_serve_request', function ( $served, $result ) use ( $csv ) {
			if ( $result instanceof WP_HTTP_Response ) {
				$headers = $result->get_headers();
				if ( isset( $headers['Content-Type'] ) && 0 === strpos( $headers['Content-Type'], 'text/csv' ) ) {
					foreach ( $headers as $key => $value ) {
						header( "$key: $value" );
					}
					echo $csv;
					return true;
				}
			}
			return $served;
		}, 10, 2 );

		return $response;
	}

	// ──────────────────────────────────────────────
	// Internal helpers
	// ──────────────────────────────────────────────

	/**
	 * Returns a generic invalid-session error.
	 *
	 * @since  1.4.0
	 * @return WP_Error
	 */
	private static function invalid_session_error(): WP_Error {
		return new WP_Error(
			'anpa_area_invalid',
			'Sesión inválida ou caducada',
			array( 'status' => 401 )
		);
	}

	/**
	 * Applies a transient-backed rate limit for the current request IP.
	 *
	 * @since  1.4.0
	 * @param  string $prefix         Transient key prefix.
	 * @param  int    $max            Maximum requests inside the window.
	 * @param  int    $window_seconds Window size in seconds.
	 * @return bool
	 */
	private static function consume_rate_limit( string $prefix, int $max, int $window_seconds ): bool {
		$now       = time();
		$ip        = self::get_request_ip();
		$key       = $prefix . md5( $ip );
		$stored    = get_transient( $key );
		$history   = is_array( $stored ) ? array_map( 'intval', $stored ) : array();
		$permitted = ANPA_Socios_Rate_Limiter::permitir( $history, $max, $window_seconds, $now );

		if ( ! $permitted ) {
			return false;
		}

		$history[] = $now;
		set_transient( $key, $history, $window_seconds );

		return true;
	}

	/**
	 * Returns the current request IP using REMOTE_ADDR only.
	 *
	 * @since  1.4.0
	 * @return string
	 */
	private static function get_request_ip(): string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
}
