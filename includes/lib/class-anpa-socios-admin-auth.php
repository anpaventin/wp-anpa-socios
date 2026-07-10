<?php
/**
 * Native WordPress admin authorization gate for the admin REST surface.
 *
 * Fase 17 (R6a): admin endpoints are authorized solely by
 * `current_user_can('manage_options')` plus the WordPress REST API nonce
 * (auto-verified via the `X-WP-Nonce` header). The former custom
 * area-session token + admin-password gate is removed.
 *
 * The 5-word banking passphrase is NOT an access credential: it is used
 * exclusively to unwrap the X25519 banking secret for sensitive banking
 * operations, handled by `permission_with_passphrase()`.
 *
 * Design: the decision logic lives in a PURE, WordPress-free core
 * (`decide()`) so it can be unit-tested without a WordPress bootstrap. The
 * public `permission_*` methods are thin adapters that gather WordPress
 * state and translate the pure result into `true|WP_Error`.
 *
 * @since  1.31.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Unified admin authorization gate (WordPress-native).
 *
 * The pure `decide()` core is unit-testable without WordPress. The
 * `permission_*` adapters reference WordPress functions/classes only inside
 * their bodies, so loading this file under a pure PHPUnit bootstrap is safe;
 * the adapters are exercised via `php -l` + staging E2E, not PHPUnit.
 *
 * @since 1.31.0
 */
final class ANPA_Socios_Admin_Auth {

	/**
	 * Request key holding the resolved WP user email (audit identity).
	 *
	 * @since 1.31.0
	 * @var string
	 */
	const REQ_PARAM_EMAIL = '_anpa_admin_email';

	/**
	 * Request key holding the unwrapped banking secret (sensitive ops only).
	 *
	 * @since 1.31.0
	 * @var string
	 */
	const REQ_PARAM_SECRET = '_anpa_banking_secret';

	/**
	 * PURE decision core — contains ALL branching logic, no WordPress calls.
	 *
	 * Fully unit-testable: callers pass the already-resolved WordPress state
	 * (capability + email) and, for passphrase-gated operations, the wrapped
	 * banking secret and the supplied passphrase. Uses the pure
	 * `ANPA_Socios_Crypto::unwrap_secret()` to validate the passphrase.
	 *
	 * @since  1.31.0
	 * @param  bool                                              $can_manage  Result of current_user_can('manage_options').
	 * @param  string                                            $wp_email    Current WP user email (may be '').
	 * @param  bool                                              $need_secret Whether the caller needs the banking secret.
	 * @param  array{blob:string,salt:string,nonce:string}|null $wrapped     Wrapped banking secret parts, or null if unset.
	 * @param  string                                            $passphrase  Passphrase provided by the caller.
	 * @return array{ok:bool,code:string,status:int,email:string,secret:?string}
	 */
	public static function decide( bool $can_manage, string $wp_email, bool $need_secret, ?array $wrapped, string $passphrase ): array {
		if ( ! $can_manage ) {
			return array(
				'ok'     => false,
				'code'   => 'anpa_admin_forbidden',
				'status' => 403,
				'email'  => '',
				'secret' => null,
			);
		}

		$wp_email = strtolower( trim( $wp_email ) );

		if ( ! $need_secret ) {
			return array(
				'ok'     => true,
				'code'   => '',
				'status' => 200,
				'email'  => $wp_email,
				'secret' => null,
			);
		}

		if ( '' === $passphrase ) {
			return array(
				'ok'     => false,
				'code'   => 'anpa_admin_passphrase',
				'status' => 400,
				'email'  => $wp_email,
				'secret' => null,
			);
		}

		if ( null === $wrapped ) {
			return array(
				'ok'     => false,
				'code'   => 'anpa_admin_no_key',
				'status' => 400,
				'email'  => $wp_email,
				'secret' => null,
			);
		}

		$secret = ANPA_Socios_Crypto::unwrap_secret(
			(string) ( $wrapped['blob'] ?? '' ),
			(string) ( $wrapped['salt'] ?? '' ),
			(string) ( $wrapped['nonce'] ?? '' ),
			$passphrase
		);

		if ( null === $secret ) {
			return array(
				'ok'     => false,
				'code'   => 'anpa_admin_bad_passphrase',
				'status' => 403,
				'email'  => $wp_email,
				'secret' => null,
			);
		}

		return array(
			'ok'     => true,
			'code'   => '',
			'status' => 200,
			'email'  => $wp_email,
			'secret' => $secret,
		);
	}

	/**
	 * Standard admin gate: `manage_options` + WP REST nonce (auto-verified).
	 *
	 * Stashes the WP user email on the request for audit logging.
	 *
	 * @since  1.31.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public static function permission_admin( WP_REST_Request $request ) {
		$result = self::decide(
			current_user_can( 'manage_options' ),
			self::current_wp_email(),
			false,
			null,
			''
		);

		return self::to_result( $request, $result );
	}

	/**
	 * Extended gate for operations that need the banking decryption passphrase.
	 *
	 * Verifies `manage_options` + unwraps the banking secret with the supplied
	 * passphrase. On success the unwrapped secret is stashed on the request for
	 * the handler under REQ_PARAM_SECRET.
	 *
	 * @since  1.31.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public static function permission_with_passphrase( WP_REST_Request $request ) {
		$result = self::decide(
			current_user_can( 'manage_options' ),
			self::current_wp_email(),
			true,
			ANPA_Socios_Banking_Key::wrapped_secret(),
			(string) $request->get_param( 'passphrase' )
		);

		return self::to_result( $request, $result );
	}

	/**
	 * Resolves the current WordPress user's email (WP glue).
	 *
	 * @since  1.31.0
	 * @return string
	 */
	private static function current_wp_email(): string {
		$user = wp_get_current_user();

		return ( $user instanceof WP_User ) ? (string) $user->user_email : '';
	}

	/**
	 * Translates a pure decision into a REST permission result (WP glue).
	 *
	 * @since  1.31.0
	 * @param  WP_REST_Request                                                        $request The incoming request.
	 * @param  array{ok:bool,code:string,status:int,email:string,secret:?string} $result  Pure decision.
	 * @return true|WP_Error
	 */
	private static function to_result( WP_REST_Request $request, array $result ) {
		if ( empty( $result['ok'] ) ) {
			return new WP_Error(
				(string) $result['code'],
				self::message( (string) $result['code'] ),
				array( 'status' => (int) $result['status'] )
			);
		}

		$request->set_param( self::REQ_PARAM_EMAIL, (string) $result['email'] );
		if ( null !== $result['secret'] ) {
			$request->set_param( self::REQ_PARAM_SECRET, (string) $result['secret'] );
		}

		return true;
	}

	/**
	 * Maps an error code to a translated, user-facing message (WP glue).
	 *
	 * @since  1.31.0
	 * @param  string $code Error code.
	 * @return string
	 */
	private static function message( string $code ): string {
		switch ( $code ) {
			case 'anpa_admin_forbidden':
				return __( 'Acceso non permitido.', 'anpa-socios' );
			case 'anpa_admin_passphrase':
				return __( 'Falta o contrasinal de descifrado.', 'anpa-socios' );
			case 'anpa_admin_no_key':
				return __( 'Clave bancaria non configurada.', 'anpa-socios' );
			case 'anpa_admin_bad_passphrase':
				return __( 'Contrasinal incorrecto.', 'anpa-socios' );
			default:
				return __( 'Acceso non permitido.', 'anpa-socios' );
		}
	}
}
