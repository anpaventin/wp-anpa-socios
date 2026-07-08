<?php
/**
 * Admin REST handler for one-time banking key setup (fase6).
 *
 * Generates the X25519 keypair used to seal banking data, wraps the secret
 * key under an admin passphrase, stores the public key + wrapped secret, and
 * returns the plaintext secret key ONCE so the admin can escrow it offline.
 *
 * Master-only and audited. Setup is refused if a key already exists (a fresh
 * keypair would make existing sealed data unreadable — rotation is a separate,
 * deliberate operation documented in docs/BANKING-KEY.md).
 *
 * @since  1.8.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for `/admin/banking-key/*`.
 *
 * @since 1.8.0
 */
final class ANPA_Socios_Admin_Banking_Key_Handler {

	/**
	 * Minimum passphrase length for wrapping the banking secret key.
	 *
	 * @since 1.8.0
	 * @var int
	 */
	const MIN_PASSPHRASE = 12;

	/**
	 * Registers the banking-key admin routes.
	 *
	 * @since  1.8.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			ANPA_Socios_Admin_REST::REST_NAMESPACE,
			'/banking-key/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			)
		);
		register_rest_route(
			ANPA_Socios_Admin_REST::REST_NAMESPACE,
			'/banking-key/setup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'setup' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			)
		);
	}

	/**
	 * GET /admin/banking-key/status — whether banking encryption is configured.
	 *
	 * @since  1.8.0
	 * @return WP_REST_Response
	 */
	public static function status(): WP_REST_Response {
		$configured = ANPA_Socios_Banking_Key::is_configured();
		$source     = 'none';
		if ( defined( 'ANPA_SOCIOS_PUBLIC_KEY' ) && '' !== (string) constant( 'ANPA_SOCIOS_PUBLIC_KEY' ) ) {
			$source = 'constant';
		} elseif ( $configured ) {
			$source = 'option';
		}

		return new WP_REST_Response(
			array(
				'configured'   => $configured,
				'source'       => $source,
				'has_wrapped'  => null !== ANPA_Socios_Banking_Key::wrapped_secret(),
			),
			200
		);
	}

	/**
	 * POST /admin/banking-key/setup — generate + store the keypair (once).
	 *
	 * Body: { passphrase }. Returns the secret key ONCE for offline escrow.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function setup( WP_REST_Request $request ) {
		// Refuse if already configured (rotation is a separate deliberate flow).
		if ( ANPA_Socios_Banking_Key::is_configured() || null !== ANPA_Socios_Banking_Key::wrapped_secret() ) {
			return new WP_Error(
				'anpa_banking_key_exists',
				'A clave bancaria xa está configurada. A rotación faise por un procedemento dedicado.',
				array( 'status' => 409 )
			);
		}

		$body       = ANPA_Socios_Admin_Shared::json_body( $request );
		$passphrase = (string) ( $body['passphrase'] ?? '' );
		if ( strlen( $passphrase ) < self::MIN_PASSPHRASE ) {
			return new WP_Error(
				'anpa_banking_key_weak',
				'O contrasinal da clave debe ter polo menos ' . self::MIN_PASSPHRASE . ' caracteres.',
				array( 'status' => 400 )
			);
		}

		// Atomic claim: add_option performs a DB INSERT that fails if the
		// option already exists, closing the TOCTOU window of two concurrent
		// setups overwriting each other's keypair.
		if ( false === add_option( ANPA_Socios_Banking_Key::OPTION_PUBKEY, 'pending', '', false ) ) {
			return new WP_Error(
				'anpa_banking_key_exists',
				'A clave bancaria xa está configurada. A rotación faise por un procedemento dedicado.',
				array( 'status' => 409 )
			);
		}

		$keypair = ANPA_Socios_Crypto::generate_keypair();
		$wrapped = ! empty( $keypair['secret'] ) ? ANPA_Socios_Crypto::wrap_secret( $keypair['secret'], $passphrase ) : null;
		if ( empty( $keypair['public'] ) || empty( $keypair['secret'] ) || null === $wrapped ) {
			// Release the claim so the admin can retry.
			delete_option( ANPA_Socios_Banking_Key::OPTION_PUBKEY );
			return new WP_Error( 'anpa_banking_key_error', __( 'Erro interno ao xerar a clave', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Banking_Key::store( $keypair['public'], $wrapped );
		ANPA_Socios_Admin_Shared::write_audit( $request, 'banking_key', 'keypair', 'setup' );

		$response = new WP_REST_Response(
			array(
				'success'    => true,
				'public_key' => $keypair['public'],
				'secret_key' => $keypair['secret'],
				'aviso'      => 'Garda esta clave privada e o contrasinal nun lugar seguro (escrow). Non se volverá amosar. Se os perdes, os datos bancarios serán irrecuperables.',
			),
			200
		);
		// Sensitive payload: never cache the secret key anywhere.
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		// Best-effort wipe of our local copy of the secret (the response keeps its own).
		sodium_memzero( $keypair['secret'] );

		return $response;
	}
}
