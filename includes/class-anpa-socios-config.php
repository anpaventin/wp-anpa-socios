<?php
/**
 * Configuration resolver for the ANPA Socios plugin.
 *
 * Resolves the master email with the following precedence (highest first):
 *   1. PHP constant ANPA_SOCIOS_MASTER_EMAIL (set in wp-config.php)
 *   2. WordPress option 'anpa_socios_master_email' (set via admin UI / WP-CLI)
 *   3. Default: ANPA_Socios_Roles::MASTER_EMAIL
 *
 * The returned value is always lowercased and trimmed.
 *
 * This class depends on WordPress (get_option, defined, constant). It is
 * NOT unit-testable without a WP bootstrap; coverage is provided by
 * staging E2E tests.
 *
 * @since  1.4.1
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves plugin configuration values from constant / option / default.
 *
 * @since 1.4.1
 */
final class ANPA_Socios_Config {

	/**
	 * WordPress option key for the master email override.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const OPTION = 'anpa_socios_master_email';

	/**
	 * PHP constant name checked in wp-config.php.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const CONST_NAME = 'ANPA_SOCIOS_MASTER_EMAIL';

	/**
	 * Returns the resolved master email.
	 *
	 * Precedence: wp-config constant > WP option > default constant.
	 * Always lowercased and trimmed.
	 *
	 * @since  1.4.1
	 * @return string
	 */
	public static function master_email(): string {
		// 1. PHP constant (highest priority — set in wp-config.php).
		if ( defined( self::CONST_NAME ) && '' !== trim( (string) constant( self::CONST_NAME ) ) ) {
			return strtolower( trim( (string) constant( self::CONST_NAME ) ) );
		}

		// 2. WordPress option (admin UI or WP-CLI).
		$opt = get_option( self::OPTION, '' );
		if ( is_string( $opt ) && '' !== trim( $opt ) ) {
			return strtolower( trim( $opt ) );
		}

		// 3. Fallback to the hardcoded default.
		return strtolower( ANPA_Socios_Roles::MASTER_EMAIL );
	}

	/**
	 * Option keys for fase12 identity/config.
	 *
	 * @var string
	 */
	const OPTION_ASSOCIATION  = 'anpa_socios_association_name';
	const OPTION_SIGNATURE     = 'anpa_socios_email_signature';
	const OPTION_APPROVAL      = 'anpa_socios_require_approval';
	const DEFAULT_ASSOCIATION  = 'ANPA As Brañas';

	/**
	 * The configurable association name (variable, not hardcoded).
	 *
	 * @return string
	 */
	public static function association_name(): string {
		$value = trim( (string) get_option( self::OPTION_ASSOCIATION, '' ) );

		return '' !== $value ? $value : self::DEFAULT_ASSOCIATION;
	}

	/**
	 * The configurable signature appended to plugin emails.
	 *
	 * @return string
	 */
	public static function email_signature(): string {
		return (string) get_option( self::OPTION_SIGNATURE, '' );
	}

	/**
	 * Whether new socios require master approval before gaining access.
	 *
	 * @return bool
	 */
	public static function require_approval(): bool {
		return '1' === (string) get_option( self::OPTION_APPROVAL, '0' );
	}
}
