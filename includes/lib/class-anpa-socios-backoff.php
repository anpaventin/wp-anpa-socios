<?php
/**
 * Pure retry-backoff policy for the communications queue (fase35).
 *
 * Exponential backoff with a cap and a maximum attempt count. Deterministic and
 * WordPress-independent so it is fully unit-testable.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Backoff {

	const BASE_DEFAULT  = 300;    // 5 minutes.
	const CAP_DEFAULT   = 21600;  // 6 hours.
	const MAX_DEFAULT   = 5;      // attempts before giving up.

	/**
	 * Seconds to wait before the next attempt, given how many attempts have
	 * already been made (1-based). Exponential with a hard cap.
	 *
	 * attempt 1 → base, 2 → base*2, 3 → base*4 … capped at $cap.
	 *
	 * @param int $intentos Attempts already made (>=1).
	 * @param int $base     Base seconds.
	 * @param int $cap      Maximum seconds.
	 * @return int Seconds (>=0).
	 */
	public static function espera_para( int $intentos, int $base = self::BASE_DEFAULT, int $cap = self::CAP_DEFAULT ): int {
		if ( $intentos < 1 ) {
			return 0;
		}
		$base = max( 1, $base );
		$cap  = max( $base, $cap );
		// 2^(intentos-1) can grow fast; clamp the exponent to avoid overflow.
		$exp   = min( $intentos - 1, 30 );
		$espera = $base * ( 2 ** $exp );
		return (int) min( $espera, $cap );
	}

	/**
	 * The recipient state after a failed attempt: still retryable ('fallido') or
	 * exhausted ('fallido_definitivo').
	 *
	 * @param int $intentos Attempts already made (including the one that just failed).
	 * @param int $max      Maximum attempts allowed.
	 * @return string ANPA_Socios_Comunicacion_Estado::FALLIDO|FALLIDO_DEFINITIVO.
	 */
	public static function estado_tras_fallo( int $intentos, int $max = self::MAX_DEFAULT ): string {
		$max = max( 1, $max );
		return $intentos >= $max
			? ANPA_Socios_Comunicacion_Estado::FALLIDO_DEFINITIVO
			: ANPA_Socios_Comunicacion_Estado::FALLIDO;
	}
}
