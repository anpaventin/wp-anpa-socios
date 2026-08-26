<?php
/**
 * Retention policy for the communications log (fase35, PR-35s6).
 *
 * Pure decisions only: how long each layer of data is kept and which UTC cutoff
 * that implies. No WordPress, no database.
 *
 * Two layers, purged in this order and never the other way round:
 *   1. PAYLOAD — the rendered body and the personal context (`payload_snapshot`,
 *      `subject_render`, `last_error`). This is the sensitive layer, so it goes
 *      first, right after the diagnosis window (30 days by default). The state,
 *      the counters and `payload_hash` survive, so it stays provable that a
 *      message was sent and what its content hashed to.
 *   2. METADATA — the minimal rows themselves (campaign, recipients, attempts).
 *      Kept much longer (365 days by default) because they evidence what the
 *      board communicated and when, and only then deleted. Indefinite retention
 *      without a reason is not an option.
 *
 * Both windows are clamped: a zero or negative value would mean purging live
 * data, and an unbounded one would mean keeping personal data forever. The
 * metadata window can never be shorter than the payload window, otherwise the
 * rows would disappear before their sensitive layer was cleared.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Retention {

	/** Days the rendered payload and personal context are kept after a campaign ends. */
	const PAYLOAD_DAYS_DEFAULT = 30;

	/** Days the minimal metadata rows are kept after a campaign ends. */
	const METADATA_DAYS_DEFAULT = 365;

	/** Bounds for the payload window. */
	const PAYLOAD_DAYS_MIN = 1;
	const PAYLOAD_DAYS_MAX = 365;

	/** Bounds for the metadata window. */
	const METADATA_DAYS_MIN = 30;
	const METADATA_DAYS_MAX = 3650;

	/**
	 * Clamps the payload window.
	 *
	 * @since  1.39.0
	 * @param  mixed $days Requested days.
	 * @return int
	 */
	public static function payload_days( $days ): int {
		return self::clamp( $days, self::PAYLOAD_DAYS_DEFAULT, self::PAYLOAD_DAYS_MIN, self::PAYLOAD_DAYS_MAX );
	}

	/**
	 * Clamps the metadata window and keeps it at or above the payload window.
	 *
	 * @since  1.39.0
	 * @param  mixed $days         Requested days.
	 * @param  mixed $payload_days Effective payload window (already clamped or raw).
	 * @return int
	 */
	public static function metadata_days( $days, $payload_days = self::PAYLOAD_DAYS_DEFAULT ): int {
		$metadata = self::clamp( $days, self::METADATA_DAYS_DEFAULT, self::METADATA_DAYS_MIN, self::METADATA_DAYS_MAX );

		return max( $metadata, self::payload_days( $payload_days ) );
	}

	/**
	 * UTC cutoff for a window: rows whose reference date is older than this are
	 * eligible. Returned in MySQL datetime format.
	 *
	 * @since  1.39.0
	 * @param  int    $days    Window in days (already clamped).
	 * @param  int    $now     Reference unix timestamp.
	 * @return string 'Y-m-d H:i:s' in UTC.
	 */
	public static function cutoff_utc( int $days, int $now ): string {
		return gmdate( 'Y-m-d H:i:s', $now - ( max( 0, $days ) * 86400 ) );
	}

	/**
	 * Whether a terminal campaign is old enough for a given window.
	 *
	 * @since  1.39.0
	 * @param  string $reference_utc Terminal date of the campaign ('Y-m-d H:i:s' UTC).
	 * @param  string $cutoff_utc    Cutoff produced by cutoff_utc().
	 * @return bool
	 */
	public static function due( string $reference_utc, string $cutoff_utc ): bool {
		if ( '' === $reference_utc ) {
			return false; // No terminal date: never eligible.
		}

		return strtotime( $reference_utc . ' UTC' ) < strtotime( $cutoff_utc . ' UTC' );
	}

	/**
	 * Normalises an arbitrary value into an int inside bounds.
	 *
	 * @since  1.39.0
	 * @param  mixed $value    Raw value.
	 * @param  int   $fallback Used when the value is not a usable number.
	 * @param  int   $min      Lower bound.
	 * @param  int   $max      Upper bound.
	 * @return int
	 */
	private static function clamp( $value, int $fallback, int $min, int $max ): int {
		if ( is_bool( $value ) || ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) ) {
			return $fallback;
		}
		$days = (int) $value;
		if ( $days < $min ) {
			return $min;
		}
		if ( $days > $max ) {
			return $max;
		}

		return $days;
	}
}
