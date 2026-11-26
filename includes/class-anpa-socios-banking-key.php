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
	 * Wrong passphrase attempts allowed per actor within the lockout window.
	 *
	 * @since 1.102.0
	 * @var int
	 */
	const PASSPHRASE_MAX_FAILS = 5;

	/**
	 * Lockout window in seconds (15 minutes), counted from the last failure.
	 *
	 * @since 1.102.0
	 * @var int
	 */
	const PASSPHRASE_LOCK_SECONDS = 900;

	/**
	 * Transient prefix for the shared failed-passphrase counter.
	 *
	 * @since 1.102.0
	 * @var string
	 */
	const PASSPHRASE_FAIL_PREFIX = 'anpa_banking_pp_fail_';

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

	/**
	 * 1.102.0: why a new passphrase cannot be used ('' = it can). Pure. The
	 * result is the notice key shown in Axustes → Mantemento.
	 *
	 * Leading or trailing spaces are refused because some screens trim the
	 * phrase they send, which would make it fail there.
	 *
	 * @since  1.102.0
	 * @param  string $vella  Current passphrase, as typed.
	 * @param  string $nova   New passphrase.
	 * @param  string $nova2  New passphrase, typed again.
	 * @param  int    $minimo Minimum length in bytes.
	 * @return string
	 */
	public static function erro_frase_nova( string $vella, string $nova, string $nova2, int $minimo ): string {
		if ( $nova !== $nova2 ) {
			return 'frase_distinta';
		}
		if ( trim( $nova ) !== $nova ) {
			return 'frase_espazos';
		}
		if ( strlen( $nova ) < $minimo ) {
			return 'frase_curta';
		}
		if ( $nova === $vella ) {
			return 'frase_igual';
		}

		return '';
	}

	/**
	 * Transient key of the failed-passphrase counter for an actor.
	 *
	 * One counter is shared by every entry point that checks the banking
	 * passphrase (export, domiciliación read, IBAN import, backup, restore), so
	 * an attacker cannot multiply attempts by switching endpoints.
	 *
	 * @since  1.102.0
	 * @param  string $actor Admin identity (WP user email); '' falls back to a shared bucket.
	 * @return string
	 */
	public static function passphrase_lock_key( string $actor ): string {
		$actor = strtolower( trim( $actor ) );

		return self::PASSPHRASE_FAIL_PREFIX . md5( '' !== $actor ? $actor : 'anon' );
	}

	/**
	 * Whether the actor has reached the failed-passphrase limit.
	 *
	 * @since  1.102.0
	 * @param  string $actor Admin identity (WP user email).
	 * @return bool
	 */
	public static function passphrase_locked( string $actor ): bool {
		return (int) get_transient( self::passphrase_lock_key( $actor ) ) >= self::PASSPHRASE_MAX_FAILS;
	}

	/**
	 * Records a wrong passphrase attempt (restarts the 15-minute window).
	 *
	 * @since  1.102.0
	 * @param  string $actor Admin identity (WP user email).
	 * @return void
	 */
	public static function passphrase_failed( string $actor ): void {
		$key = self::passphrase_lock_key( $actor );
		set_transient( $key, (int) get_transient( $key ) + 1, self::PASSPHRASE_LOCK_SECONDS );
	}

	/**
	 * Clears the failed-attempt counter after a correct passphrase.
	 *
	 * @since  1.102.0
	 * @param  string $actor Admin identity (WP user email).
	 * @return void
	 */
	public static function passphrase_succeeded( string $actor ): void {
		delete_transient( self::passphrase_lock_key( $actor ) );
	}

	/**
	 * User-facing message shown while the actor is locked out.
	 *
	 * @since  1.102.0
	 * @return string
	 */
	public static function passphrase_locked_message(): string {
		return __( 'Demasiados intentos co contrasinal. Téntao de novo en 15 minutos.', 'anpa-socios' );
	}

	/**
	 * WordPress email of the current admin, used as the lockout actor.
	 *
	 * @since  1.102.0
	 * @return string
	 */
	public static function current_actor(): string {
		$user = wp_get_current_user();

		return ( $user instanceof WP_User ) ? (string) $user->user_email : '';
	}
}
