<?php
/**
 * Pure timestamp-window rate limiter for ANPA Socios signup flow.
 *
 * No WordPress dependencies and no database access.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

class ANPA_Socios_Rate_Limiter {

	/**
	 * Decides whether another request is allowed inside the time window.
	 *
	 * @param  array<int,mixed> $timestamps      Previous request timestamps.
	 * @param  int              $max             Maximum requests allowed in window.
	 * @param  int              $window_seconds  Window size in seconds.
	 * @param  int|null         $now             Optional reference timestamp.
	 * @return bool
	 */
	public static function permitir( array $timestamps, int $max, int $window_seconds, ?int $now = null ): bool {
		$now    = $now ?? time();
		$cutoff = $now - $window_seconds;
		$count  = 0;

		foreach ( $timestamps as $timestamp ) {
			$timestamp = (int) $timestamp;

			if ( $timestamp >= $cutoff ) {
				++$count;
			}
		}

		return $count < $max;
	}
}
