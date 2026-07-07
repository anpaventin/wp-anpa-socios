<?php
/**
 * Storage for the banking keypair (fase6 sealed-box model).
 *
 * Stores the PUBLIC key (used by the server to seal banking data at alta) and
 * the passphrase-WRAPPED secret key blob. The server can seal but cannot open
 * banking data without an admin passphrase to unwrap the secret key.
 *
 * The public key may also be provided via the ANPA_SOCIOS_PUBLIC_KEY constant
 * (wp-config); the constant takes precedence over the stored option.
 *
 * @since  1.8.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option-backed store for the banking public key + wrapped secret key.
 *
 * @since 1.8.0
 */
final class ANPA_Socios_Banking_Key {

	/**
	 * Option name for the base64 public key.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const OPTION_PUBKEY = 'anpa_socios_banking_pubkey';

	/**
	 * Option name for the wrapped secret key (json: blob/salt/nonce).
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const OPTION_SECKEY = 'anpa_socios_banking_seckey_wrapped';

	/**
	 * Returns the base64 public key (constant wins over option), or null.
	 *
	 * @since  1.8.0
	 * @return string|null
	 */
	public static function public_key(): ?string {
		if ( defined( 'ANPA_SOCIOS_PUBLIC_KEY' ) ) {
			$const = (string) constant( 'ANPA_SOCIOS_PUBLIC_KEY' );
			if ( '' !== $const ) {
				return $const;
			}
		}
		$opt = get_option( self::OPTION_PUBKEY, '' );

		return ( is_string( $opt ) && '' !== $opt ) ? $opt : null;
	}

	/**
	 * Returns the wrapped secret blob array (blob/salt/nonce), or null.
	 *
	 * @since  1.8.0
	 * @return array{blob:string,salt:string,nonce:string}|null
	 */
	public static function wrapped_secret(): ?array {
		$raw = get_option( self::OPTION_SECKEY, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded )
			|| empty( $decoded['blob'] ) || empty( $decoded['salt'] ) || empty( $decoded['nonce'] ) ) {
			return null;
		}

		return array(
			'blob'  => (string) $decoded['blob'],
			'salt'  => (string) $decoded['salt'],
			'nonce' => (string) $decoded['nonce'],
		);
	}

	/**
	 * Returns whether banking encryption is ready (a public key is available).
	 *
	 * @since  1.8.0
	 * @return bool
	 */
	public static function is_configured(): bool {
		return null !== self::public_key();
	}

	/**
	 * Persists the public key and the wrapped secret blob.
	 *
	 * @since  1.8.0
	 * @param  string                                    $public_key_b64 Base64 public key.
	 * @param  array{blob:string,salt:string,nonce:string} $wrapped        Wrapped secret.
	 * @return void
	 */
	public static function store( string $public_key_b64, array $wrapped ): void {
		update_option( self::OPTION_PUBKEY, $public_key_b64, false );
		update_option( self::OPTION_SECKEY, (string) wp_json_encode( $wrapped ), false );
	}
}
