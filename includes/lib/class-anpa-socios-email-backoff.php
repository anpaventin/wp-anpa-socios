<?php
/**
 * Pure retry-backoff policy for the email queue (fase35).
 *
 * Exponential backoff with a cap and a maximum attempt count. Deterministic and
 * WordPress-independent so it is fully unit-testable.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Backoff {

	const BASE_DEFAULT = 300;    // 5 minutes.
	const CAP_DEFAULT  = 21600;  // 6 hours.
	const MAX_DEFAULT  = 5;      // attempts before giving up.

	/**
	 * Seconds to wait before the next attempt, given attempts already made
	 * (1-based). Exponential with a hard cap.
	 *
	 * @param int $attempts Attempts already made (>=1).
	 * @param int $base     Base seconds.
	 * @param int $cap      Maximum seconds.
	 * @return int Seconds (>=0).
	 */
	public static function delay_for( int $attempts, int $base = self::BASE_DEFAULT, int $cap = self::CAP_DEFAULT ): int {
		if ( $attempts < 1 ) {
			return 0;
		}
		$base = max( 1, $base );
		$cap  = max( $base, $cap );
		$exp  = min( $attempts - 1, 30 ); // clamp to avoid overflow.
		$delay = $base * ( 2 ** $exp );
		return (int) min( $delay, $cap );
	}

	/**
	 * The recipient state after a failed attempt: still retryable ('failed') or
	 * exhausted ('failed_permanent').
	 *
	 * @param int $attempts Attempts already made (including the one that just failed).
	 * @param int $max      Maximum attempts allowed.
	 * @return string ANPA_Socios_Email_Recipient_State::FAILED|FAILED_PERMANENT.
	 */
	public static function state_after_failure( int $attempts, int $max = self::MAX_DEFAULT ): string {
		$max = max( 1, $max );
		return $attempts >= $max
			? ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT
			: ANPA_Socios_Email_Recipient_State::FAILED;
	}
}
