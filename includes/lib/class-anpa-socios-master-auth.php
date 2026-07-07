<?php
/**
 * Master authentication & initialization helpers.
 *
 * Manages:
 *  - Master initialization state (DB, banking key, admin password)
 *  - Admin panel password (shared by all admins, master-only change)
 *  - Admin session password verification (once per session, transient)
 *
 * @since  1.21.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Master authentication & init gate.
 *
 * @since 1.21.0
 */
final class ANPA_Socios_Master_Auth {

	/**
	 * Option key: bool — whether master init has been completed.
	 *
	 * @since 1.21.0
	 * @var string
	 */
	const INIT_OPTION = 'anpa_socios_master_initialized';

	/**
	 * Option key: password_hash of the shared admin panel password.
	 *
	 * @since 1.21.0
	 * @var string
	 */
	const ADMIN_PASSWORD_OPTION = 'anpa_socios_admin_password_hash';

	/**
	 * Minimum length for the admin panel password.
	 *
	 * @since 1.21.0
	 * @var int
	 */
	const ADMIN_PASSWORD_MIN = 8;

	/**
	 * Transient prefix for admin session verification (once per session).
	 * Full key: anpa_admin_auth_<token_digest>
	 *
	 * @since 1.21.0
	 * @var string
	 */
	const ADMIN_SESSION_PREFIX = 'anpa_admin_auth_';

	/**
	 * Admin session auth TTL (same as the area session, typically 24h).
	 *
	 * @since 1.21.0
	 * @var int
	 */
	const ADMIN_SESSION_TTL = DAY_IN_SECONDS;

	/**
	 * Returns whether the master init has been completed.
	 *
	 * @since  1.21.0
	 * @return bool
	 */
	public static function is_initialized(): bool {
		return '1' === get_option( self::INIT_OPTION, '0' );
	}

	/**
	 * Marks the master init as completed.
	 *
	 * @since  1.21.0
	 * @return void
	 */
	public static function mark_initialized(): void {
		update_option( self::INIT_OPTION, '1', false );
	}

	/**
	 * Validates the admin panel password complexity.
	 *
	 * Requirements: 8+ chars, at least 1 uppercase, at least 1 symbol.
	 *
	 * @since  1.21.0
	 * @param  string $password Candidate password.
	 * @return true|string True on success, error string on failure.
	 */
	public static function validate_admin_password( string $password ) {
		if ( strlen( $password ) < self::ADMIN_PASSWORD_MIN ) {
			return 'O contrasinal debe ter polo menos ' . self::ADMIN_PASSWORD_MIN . ' caracteres.';
		}
		if ( ! preg_match( '/[A-Z]/', $password ) ) {
			return 'O contrasinal debe conter polo menos unha letra maiúscula.';
		}
		if ( ! preg_match( '/[^a-zA-Z0-9]/', $password ) ) {
			return 'O contrasinal debe conter polo menos un símbolo (ex: !@#$%^&*).';
		}
		return true;
	}

	/**
	 * Sets the shared admin panel password.
	 *
	 * Master-only operation (caller must check permission_master beforehand).
	 *
	 * @since  1.21.0
	 * @param  string $password Plaintext admin password.
	 * @return true|string True on success, error string on failure.
	 */
	public static function set_admin_password( string $password ) {
		$validation = self::validate_admin_password( $password );
		if ( true !== $validation ) {
			return $validation;
		}
		update_option( self::ADMIN_PASSWORD_OPTION, password_hash( $password, PASSWORD_BCRYPT ), false );
		return true;
	}

	/**
	 * Verifies the shared admin panel password.
	 *
	 * @since  1.21.0
	 * @param  string $password Plaintext candidate.
	 * @return bool
	 */
	public static function verify_admin_password( string $password ): bool {
		$hash = get_option( self::ADMIN_PASSWORD_OPTION, '' );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return false;
		}
		return password_verify( $password, $hash );
	}

	/**
	 * Checks whether the admin password has been configured.
	 *
	 * @since  1.21.0
	 * @return bool
	 */
	public static function admin_password_exists(): bool {
		$hash = get_option( self::ADMIN_PASSWORD_OPTION, '' );
		return is_string( $hash ) && '' !== $hash;
	}

	/**
	 * Records that the current area session has passed admin password auth.
	 *
	 * @since  1.21.0
	 * @param  string $area_token The current session's area token.
	 * @return void
	 */
	public static function mark_admin_session_authorized( string $area_token ): void {
		$digest = hash_hmac( 'sha256', $area_token, wp_salt( 'nonce' ) );
		set_transient(
			self::ADMIN_SESSION_PREFIX . $digest,
			'1',
			self::ADMIN_SESSION_TTL
		);
	}

	/**
	 * Returns whether the current area session has passed admin password auth.
	 *
	 * @since  1.21.0
	 * @param  string $area_token The current session's area token.
	 * @return bool
	 */
	public static function is_admin_session_authorized( string $area_token ): bool {
		$digest = hash_hmac( 'sha256', $area_token, wp_salt( 'nonce' ) );
		return '1' === get_transient( self::ADMIN_SESSION_PREFIX . $digest );
	}

	/**
	 * Clears the admin session auth flag for a given token.
	 *
	 * @since  1.21.0
	 * @param  string $area_token The current session's area token.
	 * @return void
	 */
	public static function clear_admin_session_authorized( string $area_token ): void {
		$digest = hash_hmac( 'sha256', $area_token, wp_salt( 'nonce' ) );
		delete_transient( self::ADMIN_SESSION_PREFIX . $digest );
	}

	/**
	 * Generates a random 5-word passphrase in galician.
	 *
	 * @since  1.21.0
	 * @return string
	 */
	public static function generate_banking_passphrase(): string {
		$words = array(
			'ceu', 'mar', 'sol', 'lua', 'nube', 'monte', 'rio', 'ponte',
			'casa', 'horta', 'rosa', 'pino', 'carvalho', 'nogueira', 'salgueiro',
			'pomba', 'gaivota', 'andoriña', 'paxaro', 'grilo',
			'mazá', 'pera', 'uvas', 'froita', 'millo',
			'auga', 'lume', 'vento', 'neve', 'brétema',
			'verde', 'azul', 'branco', 'negro', 'roxo',
			'música', 'danza', 'canto', 'risa', 'soño',
			'amor', 'paz', 'ben', 'luz', 'vida',
		);

		$selected = array();
		$keys     = array_rand( $words, 5 );
		foreach ( (array) $keys as $k ) {
			$selected[] = $words[ $k ];
		}

		return implode( '-', $selected );
	}
}
