<?php
/**
 * Pure trimester helper (fase7).
 *
 * The school year has 3 fixed trimesters. This maps a month to the
 * current trimester. No WordPress dependency (month injectable for tests).
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Fixed 3-trimester model.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Trimestre {

	/**
	 * Valid trimester numbers.
	 *
	 * @since 1.9.0
	 * @var int[]
	 */
	const VALIDOS = array( 1, 2, 3 );

	/**
	 * Returns the trimester for a given month (1–12).
	 *
	 * Galician school year mapping:
	 *  - Sep–Dec (9–12)  → 1º trimestre
	 *  - Jan–Mar (1–3)   → 2º trimestre
	 *  - Apr–Aug (4–8)   → 3º trimestre
	 *
	 * @since  1.9.0
	 * @param  int|null $month Month 1–12 (defaults to the current UTC month).
	 * @return int 1, 2 or 3.
	 */
	public static function actual( ?int $month = null ): int {
		$month = $month ?? (int) gmdate( 'n' );

		if ( $month >= 9 && $month <= 12 ) {
			return 1;
		}
		if ( $month >= 1 && $month <= 3 ) {
			return 2;
		}

		return 3;
	}

	/**
	 * Whether a value is a valid trimester number.
	 *
	 * @since  1.9.0
	 * @param  int $trimestre Candidate.
	 * @return bool
	 */
	public static function valido( int $trimestre ): bool {
		return in_array( $trimestre, self::VALIDOS, true );
	}
}
