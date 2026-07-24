<?php
/**
 * Pure batch planner for the communications queue (fase35).
 *
 * Given a set of recipient records and the current time, selects which are
 * eligible to be processed in the next batch:
 *   - state must be retryable (pendente or fallido),
 *   - a scheduled next-attempt time (seguinte_intento_en) must be due (<= now)
 *     or absent,
 *   - terminal states are never selected,
 *   - the result is capped at the batch size N, oldest first.
 *
 * WordPress-independent; the glue layer performs the actual locked SELECT/UPDATE
 * using the same rules. This mirror exists so the selection logic is unit-tested.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Cola_Planner {

	const LOTE_DEFAULT = 25;
	const LOTE_MAX     = 100;

	/**
	 * Clamps a configured batch size to the safe range [1, LOTE_MAX].
	 *
	 * @param int $n Configured size.
	 * @return int
	 */
	public static function tamano_lote( int $n ): int {
		if ( $n < 1 ) {
			return self::LOTE_DEFAULT;
		}
		return (int) min( $n, self::LOTE_MAX );
	}

	/**
	 * Whether a single record is eligible to be processed now.
	 *
	 * @param array<string,mixed> $rec Keys: estado, seguinte_intento_en (Y-m-d H:i:s|'').
	 * @param string              $now Current time 'Y-m-d H:i:s'.
	 * @return bool
	 */
	public static function elixible( array $rec, string $now ): bool {
		$estado = (string) ( $rec['estado'] ?? '' );
		if ( ! ANPA_Socios_Comunicacion_Estado::reintentable( $estado ) ) {
			return false;
		}
		$cando = (string) ( $rec['seguinte_intento_en'] ?? '' );
		if ( '' === $cando || '0000-00-00 00:00:00' === $cando ) {
			return true;
		}
		// Lexicographic comparison is correct for 'Y-m-d H:i:s'.
		return $cando <= $now;
	}

	/**
	 * Selects up to N eligible records (oldest id first) for the next batch.
	 *
	 * @param array<int,array<string,mixed>> $records Candidate records (each with id).
	 * @param string                         $now     Current time.
	 * @param int                            $n       Batch size (clamped).
	 * @return array<int,array<string,mixed>> Selected subset.
	 */
	public static function seleccionar( array $records, string $now, int $n ): array {
		$n = self::tamano_lote( $n );

		$elixibles = array_values(
			array_filter(
				$records,
				static function ( $rec ) use ( $now ) {
					return is_array( $rec ) && self::elixible( $rec, $now );
				}
			)
		);

		usort(
			$elixibles,
			static function ( $a, $b ) {
				return ( (int) ( $a['id'] ?? 0 ) ) <=> ( (int) ( $b['id'] ?? 0 ) );
			}
		);

		return array_slice( $elixibles, 0, $n );
	}
}
