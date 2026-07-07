<?php
/**
 * Pure verification-code generator for ANPA Socios signup flow.
 *
 * No WordPress dependencies.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

class ANPA_Socios_Codigo_Generator {

	/**
	 * Code time-to-live in seconds.
	 */
	private const TTL_SECONDS = 15 * 60;

	/**
	 * Generates a six-digit numeric code with zero-padding.
	 *
	 * @return string
	 */
	public static function generate(): string {
		return sprintf( '%06d', random_int( 0, 999999 ) );
	}

	/**
	 * Hashes a verification code using native PHP password hashing.
	 *
	 * @param  string $code Verification code.
	 * @return string
	 */
	public static function hash_code( string $code ): string {
		return password_hash( $code, PASSWORD_BCRYPT );
	}

	/**
	 * Calculates the expiration timestamp for a code.
	 *
	 * @param  int $now Reference timestamp.
	 * @return int
	 */
	public static function expiry( int $now ): int {
		return $now + self::TTL_SECONDS;
	}
}
