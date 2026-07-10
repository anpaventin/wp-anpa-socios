<?php
/**
 * REST endpoints for the ANPA Socios personal area.
 *
 * @since  1.1.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the passwordless member-area REST surface.
 *
 * @since 1.1.0
 */
class ANPA_Socios_Area_REST {

	/**
	 * REST namespace shared by the anpa-socios plugin.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REST_NAMESPACE = 'anpa-socios/v1';

	/**
	 * Area-session TTL in seconds.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const SESSION_TTL = DAY_IN_SECONDS;

	/**
	 * Maximum authenticated member-area calls per session.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const SESSION_MAX_USES = 100;

	/**
	 * Registers all member-area routes.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/area/session',
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
			'/area/session-status',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'handle_session_status' ),
				'permission_callback' => array( __CLASS__, 'permission_area_session' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/area/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_me' ),
					'permission_callback' => array( __CLASS__, 'permission_area_session' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'handle_update_me' ),
					'permission_callback' => array( __CLASS__, 'permission_area_session' ),
					'args'                => array(
						'nome'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'apelidos' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/area/me/session',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'handle_delete_session' ),
				'permission_callback' => array( __CLASS__, 'permission_area_session' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/area/me/banking',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_banking' ),
					'permission_callback' => array( __CLASS__, 'permission_area_session' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'handle_put_banking' ),
					'permission_callback' => array( __CLASS__, 'permission_area_session' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/area/me/baixa',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_request_baixa' ),
				'permission_callback' => array( __CLASS__, 'permission_area_session' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/area/me/baixa/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_cancel_baixa' ),
				'permission_callback' => array( __CLASS__, 'permission_area_session' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/area/reactivar',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_reactivar' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'   => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
					'website' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'_ts'     => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

	}

	/**
	 * POST /area/reactivar { email } — public reactivation request.
	 *
	 * A former member (estado='baixa') asks to come back. This ONLY flips
	 * `baixa`→`pendiente_alta` (an admin must explicitly activate to restore
	 * login, since solicitar-codigo serves only active socios) and notifies the
	 * junta. Anti-bot + rate-limited. ALWAYS returns the same generic response
	 * (no account enumeration).
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_reactivar( WP_REST_Request $request ): WP_REST_Response {
		$honeypot  = (string) $request->get_param( 'website' );
		$ts_raw    = $request->get_param( '_ts' );
		$render_ts = is_numeric( $ts_raw ) ? (int) $ts_raw : null;
		if ( ! ANPA_Socios_Antibot::passes( $honeypot, $render_ts, time() ) ) {
			return self::reactivar_generic();
		}
		if ( ! self::consume_rate_limit( 'anpa_reactivar_rl_', 5, HOUR_IN_SECONDS ) ) {
			return self::reactivar_generic();
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return self::reactivar_generic();
		}

		// Per-email cooldown: avoid repeated junta notifications if an account
		// is flipped back to baixa and re-requested (low-grade abuse guard).
		$cooldown_key = 'anpa_reactivar_cd_' . md5( $email );
		if ( false !== get_transient( $cooldown_key ) ) {
			return self::reactivar_generic();
		}

		global $wpdb;
		self::clear_db_error();
		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_socios',
			array( 'estado' => 'pendiente_alta', 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'email' => $email, 'estado' => 'baixa' ),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		if ( is_int( $updated ) && $updated > 0 ) {
			set_transient( $cooldown_key, 1, DAY_IN_SECONDS );
			ANPA_Socios_Email::enviar_aviso_reactivacion( $email );
		}

		return self::reactivar_generic();
	}

	/**
	 * Generic reactivation response (no enumeration).
	 *
	 * @since  1.9.0
	 * @return WP_REST_Response
	 */
	private static function reactivar_generic(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( __( 'Se a túa conta pode reactivarse, a directiva revisará a túa solicitude e activarate en breve.', 'anpa-socios' ), 'anpa-socios' ),
			),
			200
		);
	}

	/**
	 * Resolves the authenticated socio's id + familia_id by email.
	 *
	 * familia_id defaults to the socio's own id when unset (single-parent /
	 * pre-linkage rows), matching the /alta behaviour.
	 *
	 * @since  1.8.0
	 * @param  string $email Authenticated socio email.
	 * @return array{id:int,familia_id:int}|null
	 */
	private static function resolve_familia( string $email ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'anpa_socios';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, familia_id FROM {$table} WHERE email = %s AND estado = 'activo'", $email ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$id  = (int) $row['id'];
		$fam = (int) ( $row['familia_id'] ?: $id );

		return array( 'id' => $id, 'familia_id' => $fam );
	}

	/**
	 * GET /area/me/banking — masked view only (never the full IBAN/NIF).
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get_banking( WP_REST_Request $request ) {
		global $wpdb;

		$profile = self::request_profile( $request );
		if ( null === $profile ) {
			return self::invalid_session_error();
		}
		$fam = self::resolve_familia( (string) $profile['email'] );
		if ( null === $fam ) {
			return self::invalid_session_error();
		}

		$dom = ANPA_Socios_DB::tabela_domiciliacions();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT titular_nome, titular_apelidos, entidade_bancaria, iban_last4, autorizacion FROM {$dom} WHERE familia_id = %d",
				$fam['familia_id']
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return new WP_REST_Response( array( 'has_banking' => false ), 200 );
		}

		// Masked, non-sensitive view only. NEVER ciphertext, NEVER full IBAN/NIF.
		return new WP_REST_Response(
			array(
				'has_banking'       => true,
				'titular_nome'      => (string) $row['titular_nome'],
				'titular_apelidos'  => (string) $row['titular_apelidos'],
				'entidade_bancaria' => (string) $row['entidade_bancaria'],
				'iban_mascara'      => ANPA_Socios_Domiciliacion::mask_from_last4( (string) $row['iban_last4'] ),
				'autorizacion'      => (int) $row['autorizacion'],
			),
			200
		);
	}

	/**
	 * PUT /area/me/banking — re-enter ALL banking fields to change them.
	 *
	 * The prior value is never returned to the user; they must re-type
	 * everything. Validates and re-seals to the public key.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_put_banking( WP_REST_Request $request ) {
		$profile = self::request_profile( $request );
		if ( null === $profile ) {
			return self::invalid_session_error();
		}
		$fam = self::resolve_familia( (string) $profile['email'] );
		if ( null === $fam ) {
			return self::invalid_session_error();
		}

		$body = $request->get_json_params();
		$sepa = ANPA_Socios_Alta_Payload::validar_sepa_opcional( is_array( $body ) ? $body : array() );
		if ( ! is_array( $sepa ) ) {
			// null (no data) or 'invalid' -> a change request must carry valid data.
			return new WP_Error( 'anpa_area_invalid_payload', __( 'Datos bancarios inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$saved = ANPA_Socios_Domiciliacion::save_sealed( $fam['familia_id'], $sepa );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Datos bancarios actualizados', 'anpa-socios' ) ), 200 );
	}

	/**
	 * POST /area/me/baixa — the socio requests baixa from the association.
	 *
	 * Sets baixa_estado='solicitada' (the baixa is NOT effective until an
	 * admin confirms it) and notifies the junta. Idempotent: a second
	 * request while one is pending just reports the current state.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request_baixa( WP_REST_Request $request ) {
		$profile = self::request_profile( $request );
		if ( null === $profile ) {
			return self::invalid_session_error();
		}
		$email = (string) $profile['email'];

		// Admins must keep access: a master account in 'baixa' would be locked
		// out of login (the code-request endpoint only serves active socios).
		if ( 'master' === (string) ( $profile['rol'] ?? '' ) ) {
			return new WP_Error(
				'anpa_area_master_no_baixa',
				'Os administradores non poden solicitar a baixa. Contacta coa ANPA.',
				array( 'status' => 403 )
			);
		}

		// Idempotent: if a request is already pending, report it.
		if ( 'solicitada' === (string) ( $profile['baixa_estado'] ?? 'none' ) ) {
			return new WP_REST_Response(
				array(
					'success'      => true,
					'baixa_estado' => 'solicitada',
					'message'      => __( __( 'A túa solicitude de baixa xa está rexistrada e pendente de confirmación pola directiva.', 'anpa-socios' ), 'anpa-socios' ),
				),
				200
			);
		}

		global $wpdb;

		// Business rule (AGENTS.md): a socio cannot leave while the family unit
		// still has children actively enrolled in extraescolar activities.
		// "Active" = activo | lista_espera | oferta (baixa does not count).
		$fam          = self::resolve_familia( $email );
		$fam_id       = is_array( $fam ) ? (int) $fam['familia_id'] : 0;
		$mat_t        = ANPA_Socios_DB::tabela_matriculas();
		$fil_t        = ANPA_Socios_DB::tabela_fillos();
		$soc_t        = ANPA_Socios_DB::tabela_socios();
		$active_extra = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 WHERE ( f.socio_email = %s OR f.socio_email IN ( SELECT email FROM {$soc_t} WHERE familia_id = %d ) )
				   AND m.estado IN ( 'activo', 'lista_espera', 'oferta' )",
				$email,
				$fam_id
			)
		);
		if ( $active_extra > 0 ) {
			return new WP_Error(
				'anpa_baixa_con_extraescolares',
				'Non podes tramitar a baixa mentres a túa unidade familiar teña alumnado matriculado en actividades extraescolares. Da de baixa primeiro esas matrículas ou contacta coa directiva.',
				array( 'status' => 409 )
			);
		}

		$table = $wpdb->prefix . 'anpa_socios';

		self::clear_db_error();
		$updated = $wpdb->update(
			$table,
			array(
				'baixa_estado'        => 'solicitada',
				'baixa_solicitada_en' => current_time( 'mysql' ),
				'actualizado_en'      => current_time( 'mysql' ),
			),
			array(
				'email'  => $email,
				'estado' => 'activo',
			),
			array( '%s', '%s', '%s' ),
			array( '%s', '%s' )
		);

		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_area_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( 0 === (int) $updated ) {
			// No active socio row matched — do not falsely report a pending baixa.
			return new WP_Error(
				'anpa_area_no_activo',
				'A túa conta non está activa, así que non se pode solicitar a baixa.',
				array( 'status' => 409 )
			);
		}

		// Notify the junta. Best-effort: a mail failure must not fail the request.
		ANPA_Socios_Email::enviar_aviso_baixa_socio( $email, (string) $profile['nome'], (string) $profile['apelidos'] );

		return new WP_REST_Response(
			array(
				'success'      => true,
				'baixa_estado' => 'solicitada',
				'message'      => __( __( 'Solicitude de baixa rexistrada. A baixa será efectiva a fin de curso, tras a confirmación da directiva. Lembra que a cota anual do curso xa xerada non se devolve.', 'anpa-socios' ), 'anpa-socios' ),
			),
			200
		);
	}

	/**
	 * POST /area/me/baixa/cancel — the socio cancels their pending baixa request.
	 *
	 * Only clears a pending request on the AUTHENTICATED active socio. Idempotent:
	 * if there is nothing pending, reports success with the current (none) state.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_cancel_baixa( WP_REST_Request $request ) {
		$profile = self::request_profile( $request );
		if ( null === $profile ) {
			return self::invalid_session_error();
		}
		$email = (string) $profile['email'];

		global $wpdb;
		$table = $wpdb->prefix . 'anpa_socios';

		self::clear_db_error();
		$updated = $wpdb->update(
			$table,
			array(
				'baixa_estado'   => 'none',
				'actualizado_en' => current_time( 'mysql' ),
			),
			array(
				'email'        => $email,
				'estado'       => 'activo',
				'baixa_estado' => 'solicitada',
			),
			array( '%s', '%s' ),
			array( '%s', '%s', '%s' )
		);

		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_area_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$message = ( 0 === (int) $updated )
			? 'Non tiñas ningunha solicitude de baixa pendente.'
			: 'Solicitude de baixa anulada. Segues sendo socio/a.';

		return new WP_REST_Response(
			array(
				'success'      => true,
				'baixa_estado' => 'none',
				'message'      => $message,
			),
			200
		);
	}

	/**
	 * Creates a member-area session from a valid Fase 1 verification token.
	 *
	 * @since  1.1.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_create_session( WP_REST_Request $request ) {
		if ( ! self::consume_rate_limit( 'anpa_area_session_rl_', 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'anpa_area_rate_limited', __( 'Demasiadas solicitudes', 'anpa-socios' ), array( 'status' => 429 ) );
		}

		$fase1_token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$email       = get_transient( 'anpa_token_' . $fase1_token );
		if ( false === $email || ! is_string( $email ) || '' === $email ) {
			return self::invalid_session_error();
		}

		$profile = self::get_active_profile_by_email( $email );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		if ( null === $profile ) {
			return self::invalid_session_error();
		}

		$result = self::issue_session( ANPA_Socios_DB::tabela_sesions(), $profile['email'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_transient( 'anpa_token_' . $fase1_token );

		return new WP_REST_Response(
			array(
				'session_token' => $result['session_token'],
				'expires_in'    => $result['expires_in'],
				'max_uses'      => $result['max_uses'],
				'email'         => $profile['email'],
			),
			200
		);
	}

	/**
	 * Permission callback for authenticated member-area endpoints.
	 *
	 * Thin wrapper over the canonical session authenticator. On success it
	 * stashes the session id and active socio profile on the request so the
	 * member-area handlers can reuse them.
	 *
	 * @since  1.1.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function permission_area_session( WP_REST_Request $request ) {
		$auth = self::authenticate_area_session( $request, 'anpa_area_me_rl_' );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$request->set_param( '_anpa_area_session_id', $auth['session_id'] );
		$request->set_param( '_anpa_area_profile', $auth['profile'] );

		return true;
	}

	/**
	 * GET|POST /area/session-status — lightweight "am I logged in" check.
	 *
	 * Gated by permission_area_session, so reaching the callback means the
	 * area token is valid. Returns the authenticated socio's basic identity so
	 * the front-end can restore the session and route (email + rol).
	 *
	 * @since  1.22.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_session_status( WP_REST_Request $request ) {
		$profile = $request->get_param( '_anpa_area_profile' );
		$profile = is_array( $profile ) ? $profile : array();

		return new WP_REST_Response(
			array(
				'success'            => true,
				'email'              => (string) ( $profile['email'] ?? '' ),
				'nome'               => (string) ( $profile['nome'] ?? '' ),
				'apelidos'           => (string) ( $profile['apelidos'] ?? '' ),
				'rol'                => (string) ( $profile['rol'] ?? 'socio' ),
				'estado'             => (string) ( $profile['estado'] ?? '' ),
			),
			200
		);
	}

	/**
	 * Canonical area-session authentication, shared by the member area and
	 * any privileged surface (admin/master) that builds on a socio session,
	 * and by the empresa surface via an explicit session context.
	 *
	 * Order is intentionally strict: rate limit, HMAC digest lookup,
	 * User-Agent binding, active principal state, TTL/usage validation, then
	 * an atomic usage_count increment. Any failure after a session row is
	 * identified deletes that row and returns the generic 401. This is the
	 * single source of truth for session validity — callers must NOT
	 * reimplement a weaker check.
	 *
	 * The optional $ctx parameter generalises the validator for different
	 * session tables, token headers, and principal lookups. When omitted it
	 * defaults to the socio context (byte-for-byte equivalent to the
	 * pre-refactor behaviour).
	 *
	 * @since  1.3.0
	 * @param  WP_REST_Request $request   Incoming request.
	 * @param  string          $rl_prefix Rate-limit transient prefix (per surface).
	 * @param  array|null      $ctx       Optional session context descriptor.
	 * @return array{session_id:int,profile:array<string,string>}|WP_Error
	 */
	public static function authenticate_area_session( WP_REST_Request $request, string $rl_prefix, ?array $ctx = null ) {
		$ctx = $ctx ?? self::socio_session_context();

		if ( ! self::consume_rate_limit( $rl_prefix, 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'anpa_area_rate_limited', __( 'Demasiadas solicitudes', 'anpa-socios' ), array( 'status' => 429 ) );
		}

		$token = self::get_session_token( $request, $ctx['token_header'] );
		if ( '' === $token ) {
			return self::invalid_session_error();
		}

		global $wpdb;

		$table_name = $ctx['table'];
		$digest     = ANPA_Socios_Area_Session::digest( $token, self::auth_key() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct lookup by indexed token digest is the session-auth contract.
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE token_digest = %s LIMIT 1",
				$digest
			),
			ARRAY_A
		);

		if ( ! is_array( $session ) ) {
			return self::invalid_session_error();
		}

		$session_id = (int) $session['id'];

		// Determine the email column (empresa sessions use empresa_email).
		$email_column = isset( $session['empresa_email'] ) ? 'empresa_email' : 'email';

		if ( ! hash_equals( (string) $session['ua_hash'], ANPA_Socios_Area_Session::ua_hash( self::get_user_agent(), self::auth_key() ) ) ) {
			self::delete_session_row( $table_name, $session_id );
			return self::invalid_session_error();
		}

		$profile = call_user_func( $ctx['principal'], (string) $session[ $email_column ] );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		if ( null === $profile ) {
			self::delete_session_row( $table_name, $session_id );
			return self::invalid_session_error();
		}

		$validation_session               = $session;
		$validation_session['expires_at'] = strtotime( (string) $session['expires_at'] ) ?: 0;

		if ( ! ANPA_Socios_Area_Session::assert_valid( $validation_session, current_time( 'timestamp' ) ) ) {
			self::delete_session_row( $table_name, $session_id );
			return self::invalid_session_error();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic usage increment enforces the session cap race-free.
		self::clear_db_error();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name} SET usage_count = usage_count + 1 WHERE id = %d AND usage_count < %d",
				$session_id,
				(int) $session['max_uses']
			)
		);

		if ( 1 !== (int) $updated ) {
			self::delete_session_row( $table_name, $session_id );
			return self::invalid_session_error();
		}

		return array(
			'session_id' => $session_id,
			'profile'    => $profile,
		);
	}

	/**
	 * Returns the default socio session context descriptor.
	 *
	 * When `authenticate_area_session` receives no explicit $ctx, this
	 * context is used — preserving byte-for-byte equivalence with the
	 * pre-refactor hardcoded values.
	 *
	 * @since  1.4.0
	 * @return array{table:string,token_header:string,principal:callable}
	 */
	private static function socio_session_context(): array {
		return array(
			'table'        => ANPA_Socios_DB::tabela_sesions(),
			'token_header' => 'X-Anpa-Area-Token',
			'principal'    => array( __CLASS__, 'get_active_profile_by_email' ),
		);
	}

	/**
	 * Extracts a session token from the given custom request header.
	 *
	 * Generalises `get_area_token` for arbitrary header names. The socio
	 * context uses 'X-Anpa-Area-Token'; the empresa context uses
	 * 'X-Anpa-Empresa-Token'.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request      Incoming request.
	 * @param  string          $header_name  HTTP header name.
	 * @return string
	 */
	public static function get_session_token( WP_REST_Request $request, string $header_name ): string {
		$token = $request->get_header( $header_name );
		if ( ! is_string( $token ) || '' === $token ) {
			// Try underscore-normalised fallback (nginx/apache header transform).
			$normalised = str_replace( '-', '_', strtolower( $header_name ) );
			$token      = $request->get_header( $normalised );
		}

		return sanitize_text_field( (string) $token );
	}

	/**
	 * Deletes a session row by id from the given table.
	 *
	 * Generalises the original `delete_session_by_id` for any
	 * session table (socio or empresa).
	 *
	 * @since  1.4.0
	 * @param  string $table      Full table name.
	 * @param  int    $session_id Session row id.
	 * @return void
	 */
	public static function delete_session_row( string $table, int $session_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit server-side session revocation.
		$wpdb->delete(
			$table,
			array( 'id' => $session_id ),
			array( '%d' )
		);
	}

	/**
	 * Issues a new session token into the specified table.
	 *
	 * Generates a cryptographically random 32-byte token, computes its
	 * HMAC digest, UA hash, IP hash, and inserts a session row. Returns
	 * the raw token (shown to the client once) on success.
	 *
	 * Shared by the socio `handle_create_session` and the empresa
	 * session endpoint to avoid duplicating security-relevant issuance
	 * logic.
	 *
	 * @since  1.4.0
	 * @param  string $table Full table name for session insert.
	 * @param  string $email Principal email to bind to the session.
	 * @return array{session_token:string,expires_in:int,max_uses:int}|WP_Error
	 */
	public static function issue_session( string $table, string $email ) {
		try {
			$session_token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'anpa_area_token_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		global $wpdb;

		$expires_at   = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::SESSION_TTL );
		$token_digest = ANPA_Socios_Area_Session::digest( $session_token, self::auth_key() );
		$user_agent   = self::get_user_agent();
		$ip           = self::get_request_ip();

		// Determine the email column name from the table.
		$email_column = ( $table === ANPA_Socios_DB::tabela_sesions_empresas() )
			? 'empresa_email'
			: 'email';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated session-table write for issued area token.
		self::clear_db_error();
		$inserted = $wpdb->insert(
			$table,
			array(
				'token_digest'  => $token_digest,
				$email_column   => $email,
				'ua_hash'       => ANPA_Socios_Area_Session::ua_hash( $user_agent, self::auth_key() ),
				'ip_hash'       => ANPA_Socios_Area_Session::ip_hash( $ip, self::auth_key() ),
				'usage_count'   => 0,
				'max_uses'      => self::SESSION_MAX_USES,
				'expires_at'    => $expires_at,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_area_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		return array(
			'session_token' => $session_token,
			'expires_in'    => self::SESSION_TTL,
			'max_uses'      => self::SESSION_MAX_USES,
		);
	}

	/**
	 * Handles GET /area/me.
	 *
	 * @since  1.1.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_get_me( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::response_profile( $request ), 200 );
	}

	/**
	 * Handles PUT /area/me.
	 *
	 * @since  1.1.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update_me( WP_REST_Request $request ) {
		$nome     = ANPA_Socios_Payload::validar_nome( sanitize_text_field( (string) $request->get_param( 'nome' ) ) );
		$apelidos = ANPA_Socios_Payload::validar_apelidos( sanitize_text_field( (string) $request->get_param( 'apelidos' ) ) );

		if ( null === $nome || null === $apelidos ) {
			return new WP_Error( 'anpa_area_invalid_payload', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$profile = self::request_profile( $request );
		if ( null === $profile ) {
			return self::invalid_session_error();
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'anpa_socios';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- member profile update is scoped to the authenticated email.
		self::clear_db_error();
		$updated = $wpdb->update(
			$table_name,
			array(
				'nome'           => $nome,
				'apelidos'       => $apelidos,
				'actualizado_en' => current_time( 'mysql' ),
			),
			array(
				'email'  => $profile['email'],
				'estado' => 'activo',
			),
			array( '%s', '%s', '%s' ),
			array( '%s', '%s' )
		);

		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_area_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$updated_profile = self::get_active_profile_by_email( $profile['email'] );
		if ( is_wp_error( $updated_profile ) ) {
			return $updated_profile;
		}
		if ( null === $updated_profile ) {
			return self::invalid_session_error();
		}

		return new WP_REST_Response( self::format_profile( $updated_profile ), 200 );
	}

	/**
	 * Handles DELETE /area/me/session.
	 *
	 * @since  1.1.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete_session( WP_REST_Request $request ) {
		$session_id = (int) $request->get_param( '_anpa_area_session_id' );
		if ( $session_id <= 0 ) {
			return self::invalid_session_error();
		}

		self::delete_session_row( ANPA_Socios_DB::tabela_sesions(), $session_id );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Returns a generic invalid-session error.
	 *
	 * @since  1.1.0
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
	 * WordPress-boundary auth key provider for pure session helpers.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	private static function auth_key(): string {
		return wp_salt( 'auth' );
	}

	/**
	 * Clears wpdb's last error before a query whose result is checked.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	private static function clear_db_error(): void {
		global $wpdb;

		$wpdb->last_error = '';
	}

	/**
	 * Extracts the member-area token from the custom request header.
	 *
	 * @since  1.1.0
	 * @deprecated 1.4.0 Use get_session_token() instead.
	 * @param  WP_REST_Request $request Incoming request.
	 * @return string
	 */
	private static function get_area_token( WP_REST_Request $request ): string {
		return self::get_session_token( $request, 'X-Anpa-Area-Token' );
	}

	/**
	 * Returns the current request IP using REMOTE_ADDR only.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	private static function get_request_ip(): string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * Returns the current request User-Agent.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	private static function get_user_agent(): string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	}

	/**
	 * Applies a transient-backed rate limit for the current request IP.
	 *
	 * @since  1.1.0
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
	 * Loads an active socio profile by email.
	 *
	 * @since  1.1.0
	 * @param  string $email Socio email.
	 * @return array<string,string>|WP_Error|null
	 */
	private static function get_active_profile_by_email( string $email ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'anpa_socios';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct lookup by email checks the authoritative socio state.
		self::clear_db_error();
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT email, nome, apelidos, estado, rol, baixa_estado, baixa_solicitada_en, creado_en, actualizado_en FROM {$table_name} WHERE email = %s LIMIT 1",
				$email
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'anpa_area_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		if ( ! is_array( $profile ) || 'activo' !== (string) $profile['estado'] ) {
			return null;
		}

		return array_map( 'strval', $profile );
	}

	/**
	 * Deletes a session row by id (socio sessions table).
	 *
	 * @since  1.1.0
	 * @deprecated 1.4.0 Use delete_session_row() instead.
	 * @param  int $session_id Session row id.
	 * @return void
	 */
	private static function delete_session_by_id( int $session_id ): void {
		self::delete_session_row( ANPA_Socios_DB::tabela_sesions(), $session_id );
	}

	/**
	 * Returns the authenticated profile attached by the permission callback.
	 *
	 * @since  1.1.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return array<string,string>|null
	 */
	private static function request_profile( WP_REST_Request $request ): ?array {
		$profile = $request->get_param( '_anpa_area_profile' );

		return is_array( $profile ) ? $profile : null;
	}

	/**
	 * Returns the current request profile formatted for API output.
	 *
	 * @since  1.1.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return array<string,string>
	 */
	private static function response_profile( WP_REST_Request $request ): array {
		$profile = self::request_profile( $request );

		return self::format_profile( is_array( $profile ) ? $profile : array() );
	}

	/**
	 * Formats a socio profile for REST responses.
	 *
	 * @since  1.1.0
	 * @param  array<string,string> $profile Raw DB row.
	 * @return array<string,string>
	 */
	private static function format_profile( array $profile ): array {
		return array(
			'email'          => isset( $profile['email'] ) ? (string) $profile['email'] : '',
			'nome'           => isset( $profile['nome'] ) ? (string) $profile['nome'] : '',
			'apelidos'       => isset( $profile['apelidos'] ) ? (string) $profile['apelidos'] : '',
			'estado'         => isset( $profile['estado'] ) ? (string) $profile['estado'] : '',
			'rol'            => isset( $profile['rol'] ) ? (string) $profile['rol'] : 'socio',
			'baixa_estado'   => isset( $profile['baixa_estado'] ) ? (string) $profile['baixa_estado'] : 'none',
			'creado_en'      => isset( $profile['creado_en'] ) ? (string) $profile['creado_en'] : '',
			'actualizado_en' => isset( $profile['actualizado_en'] ) ? (string) $profile['actualizado_en'] : '',
		);
	}
}
