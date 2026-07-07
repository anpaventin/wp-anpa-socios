<?php
/**
 * Absorbed email-verification module (fase13b).
 *
 * Serves the `anpa/v1/solicitar-codigo` and `anpa/v1/verificar-codigo` routes
 * that were previously provided by the standalone `anpa-verificacion` plugin
 * (Fase 1). The request/response contracts are preserved byte-for-byte so the
 * unified area login and the alta flow keep working unchanged.
 *
 * DOUBLE-REGISTRATION GUARD: these routes are only registered when the legacy
 * `anpa-verificacion` plugin is NOT active (see ANPA_Socios_Verificacion_Guard).
 * While the legacy plugin is active it keeps serving them; once it is
 * deactivated, this module takes over seamlessly.
 *
 * @since  1.26.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST handler for the absorbed verification routes.
 *
 * @since 1.26.0
 */
final class ANPA_Socios_Verificacion_REST {

	const NAMESPACE = 'anpa/v1';

	/**
	 * Registers the verification routes.
	 *
	 * @since  1.26.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/solicitar-codigo', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_solicitar_codigo' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'email' => array( 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/verificar-codigo', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_verificar_codigo' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'email'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
				'codigo' => array( 'required' => true, 'sanitize_callback' => array( __CLASS__, 'sanitize_codigo' ) ),
			),
		) );
	}

	/**
	 * Strips everything except digits from a code.
	 *
	 * @param  mixed $value Raw input.
	 * @return string
	 */
	public static function sanitize_codigo( $value ): string {
		return preg_replace( '/[^0-9]/', '', (string) $value );
	}

	/**
	 * Generic success response (no account-existence oracle).
	 *
	 * @return WP_REST_Response
	 */
	private static function resposta_xenerica() {
		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Se o email está rexistrado, recibirás un código en breve',
		) );
	}

	/**
	 * REMOTE_ADDR only (no spoofable proxy headers).
	 *
	 * @return string
	 */
	private static function get_request_ip(): string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * POST /anpa/v1/solicitar-codigo — sends a login code to ACTIVE socios only.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_solicitar_codigo( WP_REST_Request $request ) {
		global $wpdb;

		$email = (string) $request->get_param( 'email' );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Email non válido', array( 'status' => 400 ) );
		}

		$tabela_codigos = $wpdb->prefix . 'anpa_codigos_verificacion';
		$ip             = self::get_request_ip();

		$timestamps = $wpdb->get_col( $wpdb->prepare(
			"SELECT UNIX_TIMESTAMP(creado_en) FROM {$tabela_codigos} WHERE email = %s AND ip = %s AND creado_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
			$email,
			$ip
		) );
		$timestamps = array_map( 'intval', is_array( $timestamps ) ? $timestamps : array() );

		if ( ! ANPA_Socios_Rate_Limiter::permitir( $timestamps, 3, 3600 ) ) {
			return self::resposta_xenerica();
		}

		$tabela_socios = $wpdb->prefix . 'anpa_socios';
		$socio         = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$tabela_socios} WHERE email = %s AND estado = %s",
			$email,
			'activo'
		) );

		if ( $socio ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$tabela_codigos} SET usado = 1 WHERE email = %s AND usado = 0",
				$email
			) );

			$codigo = ANPA_Socios_Codigo_Generator::generate();
			$hash   = ANPA_Socios_Codigo_Generator::hash_code( $codigo );
			$expira = date( 'Y-m-d H:i:s', ANPA_Socios_Codigo_Generator::expiry( time() ) );

			$inserted = $wpdb->insert(
				$tabela_codigos,
				array( 'email' => $email, 'codigo_hash' => $hash, 'expira_en' => $expira, 'intentos' => 0, 'usado' => 0, 'ip' => $ip ),
				array( '%s', '%s', '%s', '%d', '%d', '%s' )
			);

			if ( false !== $inserted ) {
				ANPA_Socios_Email::enviar_codigo( $email, $codigo, 'verificacion' );
			}
		} else {
			// Timing equalisation: exactly one bcrypt on the non-socio path too.
			ANPA_Socios_Codigo_Generator::hash_code( ANPA_Socios_Codigo_Generator::generate() );
		}

		return self::resposta_xenerica();
	}

	/**
	 * POST /anpa/v1/verificar-codigo — validates the code and issues a token.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_verificar_codigo( WP_REST_Request $request ) {
		global $wpdb;

		$email  = (string) $request->get_param( 'email' );
		$codigo = (string) $request->get_param( 'codigo' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Email non válido', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[0-9]{6}$/', $codigo ) ) {
			return new WP_Error( 'invalid_code', 'Código non válido', array( 'status' => 400 ) );
		}

		$ip = self::get_request_ip();
		if ( '' === $ip ) {
			return new WP_Error( 'origin_unknown', 'Non se puido determinar a orixe da solicitude', array( 'status' => 400 ) );
		}
		$rl_key      = 'anpa_vcode_ip_' . md5( $ip );
		$rl_attempts = get_transient( $rl_key );
		$rl_attempts = is_array( $rl_attempts ) ? array_map( 'intval', $rl_attempts ) : array();
		if ( ! ANPA_Socios_Rate_Limiter::permitir( $rl_attempts, 20, 3600 ) ) {
			return new WP_Error( 'too_many_requests', 'Demasiados intentos. Téntao de novo máis tarde.', array( 'status' => 429 ) );
		}
		$cutoff      = time() - 3600;
		$rl_attempts = array_values( array_filter(
			array_merge( $rl_attempts, array( time() ) ),
			static function ( $ts ) use ( $cutoff ) {
				return $ts >= $cutoff;
			}
		) );
		set_transient( $rl_key, $rl_attempts, HOUR_IN_SECONDS );

		$tabela = $wpdb->prefix . 'anpa_codigos_verificacion';
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tabela} WHERE email = %s AND usado = 0 ORDER BY id DESC LIMIT 1",
			$email
		) );

		if ( ! $row ) {
			ANPA_Socios_Codigo_Generator::hash_code( $codigo ); // timing equalisation.
			return new WP_Error( 'invalid_code', 'Código incorrecto', array( 'status' => 400 ) );
		}

		if ( ANPA_Socios_Codigo_Generator::is_expired( (int) strtotime( (string) $row->expira_en ) ) ) {
			return new WP_Error( 'code_expired', 'Código expirado, solicita un novo', array( 'status' => 400 ) );
		}
		if ( (int) $row->intentos >= 5 ) {
			return new WP_Error( 'code_expired', 'Código expirado, solicita un novo', array( 'status' => 400 ) );
		}

		if ( ! ANPA_Socios_Codigo_Generator::verify( $codigo, (string) $row->codigo_hash ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$tabela} SET intentos = intentos + 1 WHERE id = %d", $row->id ) );
			return new WP_Error( 'invalid_code', 'Código incorrecto', array( 'status' => 400 ) );
		}

		$wpdb->update( $tabela, array( 'usado' => 1 ), array( 'id' => $row->id ), array( '%d' ), array( '%d' ) );

		$token = wp_generate_password( 32, false );
		set_transient( 'anpa_token_' . $token, $email, 30 * MINUTE_IN_SECONDS );

		return rest_ensure_response( array( 'success' => true, 'token' => $token ) );
	}
}
