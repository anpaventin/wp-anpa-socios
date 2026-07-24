<?php
/**
 * Pure academic-calendar helper (fase34).
 *
 * A course has four operational dates (Y-m-d strings):
 *   - inicio : course start (= start of trimester 1)
 *   - t1     : operative close of trimester 1 (optional)
 *   - t2     : operative close of trimester 2 (optional)
 *   - peche  : course end (= end of trimester 3)
 *
 * Trimester boundaries are derived from these dates (NOT from the month):
 *   - T1 = [inicio, t1]
 *   - T2 = (t1, t2]
 *   - T3 = (t2, peche]
 *
 * When the operative dates (t1, t2) are absent, the caller should fall back to
 * the legacy month-based model (ANPA_Socios_Trimestre::actual). No WordPress
 * dependency; Y-m-d strings compare correctly lexicographically.
 *
 * @since  1.38.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Calendario {

	/**
	 * Whether both operative close dates are present and well-formed, so the
	 * date-derived trimester model can be used.
	 *
	 * @param array<string,string> $datas Keys: inicio, t1, t2, peche.
	 * @return bool
	 */
	public static function ten_datas_operativas( array $datas ): bool {
		return self::valida_data( (string) ( $datas['t1'] ?? '' ) )
			&& self::valida_data( (string) ( $datas['t2'] ?? '' ) );
	}

	/**
	 * Validates the calendar dates. Returns a list of error CODES (empty = ok).
	 *
	 * Rules (strict chronological order, all within the course range):
	 *   inicio < t1 < t2 < peche  (t1/t2 optional; if present must be ordered
	 *   and strictly inside (inicio, peche)).
	 *
	 * Error codes: inicio_invalida, peche_invalida, orde_inicio_peche,
	 * t1_invalida, t2_invalida, t1_fora_rango, t2_fora_rango, orde_t1_t2.
	 *
	 * @param array<string,string> $datas Keys: inicio, t1, t2, peche.
	 * @return string[] Ordered list of error codes.
	 */
	public static function validar( array $datas ): array {
		$inicio = (string) ( $datas['inicio'] ?? '' );
		$t1     = (string) ( $datas['t1'] ?? '' );
		$t2     = (string) ( $datas['t2'] ?? '' );
		$peche  = (string) ( $datas['peche'] ?? '' );

		$errors = array();

		$inicio_ok = self::valida_data( $inicio );
		$peche_ok  = self::valida_data( $peche );
		if ( ! $inicio_ok ) {
			$errors[] = 'inicio_invalida';
		}
		if ( ! $peche_ok ) {
			$errors[] = 'peche_invalida';
		}
		if ( $inicio_ok && $peche_ok && $inicio >= $peche ) {
			$errors[] = 'orde_inicio_peche';
		}

		// Operative dates are optional; validate only when provided.
		$t1_present = '' !== $t1;
		$t2_present = '' !== $t2;

		if ( $t1_present ) {
			if ( ! self::valida_data( $t1 ) ) {
				$errors[] = 't1_invalida';
			} elseif ( $inicio_ok && $peche_ok && ( $t1 <= $inicio || $t1 >= $peche ) ) {
				$errors[] = 't1_fora_rango';
			}
		}
		if ( $t2_present ) {
			if ( ! self::valida_data( $t2 ) ) {
				$errors[] = 't2_invalida';
			} elseif ( $inicio_ok && $peche_ok && ( $t2 <= $inicio || $t2 >= $peche ) ) {
				$errors[] = 't2_fora_rango';
			}
		}
		if ( $t1_present && $t2_present && self::valida_data( $t1 ) && self::valida_data( $t2 ) && $t1 >= $t2 ) {
			$errors[] = 'orde_t1_t2';
		}

		return $errors;
	}

	/**
	 * Trimester boundaries derived from the dates. Only meaningful when both
	 * operative dates are present (check ten_datas_operativas first).
	 *
	 * @param array<string,string> $datas Keys: inicio, t1, t2, peche.
	 * @return array<int,array{inicio:string,fin:string}>
	 */
	public static function limites( array $datas ): array {
		$inicio = (string) ( $datas['inicio'] ?? '' );
		$t1     = (string) ( $datas['t1'] ?? '' );
		$t2     = (string) ( $datas['t2'] ?? '' );
		$peche  = (string) ( $datas['peche'] ?? '' );

		return array(
			1 => array( 'inicio' => $inicio, 'fin' => $t1 ),
			2 => array( 'inicio' => self::dia_seguinte( $t1 ), 'fin' => $t2 ),
			3 => array( 'inicio' => self::dia_seguinte( $t2 ), 'fin' => $peche ),
		);
	}

	/**
	 * Derives the trimester (1..3) for a date from the operative dates.
	 *
	 * A date on or before t1 → T1; on or before t2 → T2; otherwise → T3.
	 * Dates before `inicio` map to T1 and after `peche` to T3 (documented).
	 *
	 * @param string               $data  Y-m-d date.
	 * @param array<string,string> $datas Keys: inicio, t1, t2, peche.
	 * @return int 1, 2 or 3.
	 */
	public static function trimestre_para_data( string $data, array $datas ): int {
		$t1 = (string) ( $datas['t1'] ?? '' );
		$t2 = (string) ( $datas['t2'] ?? '' );

		if ( '' !== $t1 && $data <= $t1 ) {
			return 1;
		}
		if ( '' !== $t2 && $data <= $t2 ) {
			return 2;
		}

		return 3;
	}

	/**
	 * Which trimesters have reached their operative close date on a given day.
	 *
	 * Pure detection used by the daily cron to raise a "trimester end reached"
	 * notice WITHOUT changing any state. Only T1 (via t1) and T2 (via t2) have
	 * operative close dates; T3 ends with the course itself. A trimester counts
	 * as reached when its operative date is present, well-formed and <= today.
	 *
	 * @param string               $hoxe  Y-m-d "today".
	 * @param array<string,string> $datas Keys: t1, t2 (inicio/peche ignored).
	 * @return int[] Sorted subset of {1,2}.
	 */
	public static function trimestres_operativos_alcanzados( string $hoxe, array $datas ): array {
		if ( ! self::valida_data( $hoxe ) ) {
			return array();
		}
		$out = array();
		$t1  = (string) ( $datas['t1'] ?? '' );
		$t2  = (string) ( $datas['t2'] ?? '' );
		if ( self::valida_data( $t1 ) && $hoxe >= $t1 ) {
			$out[] = 1;
		}
		if ( self::valida_data( $t2 ) && $hoxe >= $t2 ) {
			$out[] = 2;
		}

		return $out;
	}

	/**
	 * Whether a string is a valid Y-m-d date.
	 *
	 * @param string $data Candidate.
	 * @return bool
	 */
	public static function valida_data( string $data ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data ) ) {
			return false;
		}
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $data );
		return $dt instanceof DateTimeImmutable && $dt->format( 'Y-m-d' ) === $data;
	}

	/**
	 * The day after a Y-m-d date (empty in → empty out).
	 *
	 * @param string $data Y-m-d date.
	 * @return string
	 */
	private static function dia_seguinte( string $data ): string {
		if ( ! self::valida_data( $data ) ) {
			return '';
		}
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $data );
		return false === $dt ? '' : $dt->modify( '+1 day' )->format( 'Y-m-d' );
	}
}
