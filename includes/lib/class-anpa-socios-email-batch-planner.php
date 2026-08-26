<?php
/**
 * Pure batch planner for the email queue (fase35).
 *
 * Given a set of recipient records and the current time, selects which are
 * eligible to be processed in the next batch:
 *   - state must be retryable (pending or failed),
 *   - a scheduled next-attempt time (next_attempt_at) must be due (<= now) or
 *     absent,
 *   - terminal states are never selected,
 *   - the result is capped at the batch size N, oldest id first.
 *
 * WordPress-independent; the glue layer performs the actual locked SELECT/UPDATE
 * using the same rules. This mirror exists so the selection logic is unit-tested.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Batch_Planner {

	const BATCH_DEFAULT = 25;
	const BATCH_MAX     = 100;

	/**
	 * Clamps a configured batch size to the safe range [1, BATCH_MAX].
	 *
	 * @param int $n Configured size.
	 * @return int
	 */
	public static function batch_size( int $n ): int {
		if ( $n < 1 ) {
			return self::BATCH_DEFAULT;
		}
		return (int) min( $n, self::BATCH_MAX );
	}

	/**
	 * Whether a single record is eligible to be processed now.
	 *
	 * @param array<string,mixed> $rec Keys: state, next_attempt_at (Y-m-d H:i:s|'').
	 * @param string              $now Current time 'Y-m-d H:i:s'.
	 * @return bool
	 */
	public static function eligible( array $rec, string $now ): bool {
		$state = (string) ( $rec['state'] ?? '' );
		if ( ! ANPA_Socios_Email_Recipient_State::retryable( $state ) ) {
			return false;
		}
		$when = (string) ( $rec['next_attempt_at'] ?? '' );
		if ( '' === $when || '0000-00-00 00:00:00' === $when ) {
			return true;
		}
		// Lexicographic comparison is correct for 'Y-m-d H:i:s'.
		return $when <= $now;
	}

	/**
	 * Selects up to N eligible records (oldest id first) for the next batch.
	 *
	 * @param array<int,array<string,mixed>> $records Candidate records (each with id).
	 * @param string                         $now     Current time.
	 * @param int                            $n       Batch size (clamped).
	 * @return array<int,array<string,mixed>> Selected subset.
	 */
	public static function select( array $records, string $now, int $n ): array {
		$n = self::batch_size( $n );

		$eligible = array_values(
			array_filter(
				$records,
				static function ( $rec ) use ( $now ) {
					return is_array( $rec ) && self::eligible( $rec, $now );
				}
			)
		);

		usort(
			$eligible,
			static function ( $a, $b ) {
				return ( (int) ( $a['id'] ?? 0 ) ) <=> ( (int) ( $b['id'] ?? 0 ) );
			}
		);

		return array_slice( $eligible, 0, $n );
	}
}
