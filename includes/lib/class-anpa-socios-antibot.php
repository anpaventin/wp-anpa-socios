<?php
/**
 * Pure anti-bot validator for ANPA Socios code-request endpoints.
 *
 * Honeypot + time-trap logic. No WordPress dependencies, no I/O.
 * Unit-testable with PHPUnit in the pure bootstrap.
 *
 * @since  1.4.1
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Static validator that decides whether a code-request submission
 * looks human (passes) or automated (fails).
 *
 * A failing check silently returns false; the call site returns
 * the handler's normal generic success response (no oracle).
 *
 * @since 1.4.1
 */
final class ANPA_Socios_Antibot {

	/**
	 * Evaluates honeypot + time-trap anti-bot heuristics.
	 *
	 * @since  1.4.1
	 * @param  string   $honeypot    Value of the hidden 'website' field (should be empty).
	 * @param  int|null $render_ts   Unix timestamp rendered in the form (null = missing/invalid).
	 * @param  int      $now         Current Unix timestamp (server time at request receipt).
	 * @param  int      $min_seconds Minimum elapsed seconds (default 3).
	 * @param  int      $max_seconds Maximum elapsed seconds (default 3600).
	 * @return bool True if the request looks human; false if bot-detected.
	 */
	public static function passes(
		string $honeypot,
		?int $render_ts,
		int $now,
		int $min_seconds = 3,
		int $max_seconds = 3600
	): bool {
		// Honeypot filled → bot.
		if ( '' !== trim( $honeypot ) ) {
			return false;
		}

		// Missing timestamp → bot (fail-closed).
		if ( null === $render_ts ) {
			return false;
		}

		$elapsed = $now - $render_ts;

		// Too fast → bot.
		if ( $elapsed < $min_seconds ) {
			return false;
		}

		// Too slow → stale/replayed form → bot.
		if ( $elapsed > $max_seconds ) {
			return false;
		}

		return true;
	}
}
