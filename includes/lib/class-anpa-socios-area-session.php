<?php
/**
 * Pure-logic area session helpers for the ANPA Socios plugin.
 *
 * No WordPress dependency, no I/O, no global state. Unit-testable
 * with PHPUnit and a require_once-only bootstrap.
 *
 * @since  1.1.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Cryptographic and validation helpers for the socio personal area
 * session layer.
 *
 * All methods accept the HMAC key as an explicit parameter so tests
 * can assert deterministic outputs without WordPress salts.
 *
 * @since 1.1.0
 */
final class ANPA_Socios_Area_Session {

	/**
	 * Computes the HMAC-SHA256 digest of a session token.
	 *
	 * The digest is what gets stored in the database; the raw token
	 * is only sent to the client once.
	 *
	 * @since  1.1.0
	 * @param  string $token Opaque session token.
	 * @param  string $key   HMAC key.
	 * @return string 64-char hex digest.
	 */
	public static function digest( string $token, string $key ): string {
		return hash_hmac( 'sha256', $token, $key );
	}

	/**
	 * Checks whether a session row is still valid.
	 *
	 * A session is valid when ALL of the following hold:
	 * - usage_count < max_uses (cap not reached)
	 * - expires_at > now (TTL not elapsed)
	 *
	 * @since  1.1.0
	 * @param  array    $row Session row with keys: usage_count, max_uses, expires_at.
	 * @param  int|null $now Reference timestamp (defaults to current time).
	 * @return bool
	 */
	public static function assert_valid( array $row, ?int $now = null ): bool {
		$now = $now ?? time();

		if ( (int) $row['usage_count'] >= (int) $row['max_uses'] ) {
			return false;
		}

		if ( (int) $row['expires_at'] <= $now ) {
			return false;
		}

		return true;
	}

	/**
	 * Produces a keyed hash of the User-Agent string.
	 *
	 * Used as a soft browser-binding check; mismatch does not
	 * reveal whether the token itself was valid.
	 *
	 * @since  1.1.0
	 * @param  string $ua  User-Agent header value.
	 * @param  string $key HMAC key.
	 * @return string 64-char hex hash.
	 */
	public static function ua_hash( string $ua, string $key ): string {
		return hash_hmac( 'sha256', $ua, $key );
	}

	/**
	 * Produces a keyed hash of the client IP address.
	 *
	 * Stored for operational debugging and abuse investigation only.
	 * This hash is never used as an authentication factor.
	 *
	 * @since  1.1.0
	 * @param  string $ip  IP address string.
	 * @param  string $key HMAC key.
	 * @return string 64-char hex hash.
	 */
	public static function ip_hash( string $ip, string $key ): string {
		return hash_hmac( 'sha256', $ip, $key );
	}
}
