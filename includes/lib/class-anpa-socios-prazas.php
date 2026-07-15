<?php
/**
 * Pure helper for the public "Prazas" (places) summary of an activity card.
 *
 * Aggregates the per-group enrolment detail (activos / max_pupilos / espera)
 * of an activity into a single card-level summary and applies the display
 * rules (fase22 S8): the waitlist part is shown only when the activity is
 * full, and the colour of each number is semantic.
 *
 * No WordPress dependency, so the aggregation + visibility rules are fully
 * unit-testable.
 *
 * @since  1.41.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Places-summary aggregation for offered-activity cards.
 *
 * @since 1.41.0
 */
final class ANPA_Socios_Prazas {

	/**
	 * Aggregates an activity's open groups into a card-level places summary.
	 *
	 * Rules (fase22 S8.5–S8.9):
	 * - `has_groups` is false when the activity has no open groups (e.g. the
	 *   provisional-slot case); the card must then omit the places block.
	 * - `activos`/`max_pupilos`/`espera` are the sum across the given groups.
	 * - `completo` is true when `activos >= max_pupilos` (and there is real
	 *   capacity, i.e. `max_pupilos > 0`).
	 * - `espera_visible` is true whenever the activity is `completo`, even when
	 *   `espera` is 0 (S8.6: the rule is "show waitlist only when full", NOT
	 *   "show waitlist only when espera > 0" — a full activity shows
	 *   "+ 0 en espera"). When not full, the waitlist part is never shown even
	 *   if `espera > 0`.
	 *
	 * @since  1.41.0
	 * @param  array<int,array<string,mixed>> $grupos Group detail rows, each
	 *         with `activos`, `max_pupilos` and `espera` (int or numeric string).
	 * @return array{has_groups:bool,activos:int,max_pupilos:int,espera:int,completo:bool,espera_visible:bool}
	 */
	public static function summary( array $grupos ): array {
		$activos     = 0;
		$max_pupilos = 0;
		$espera      = 0;
		$count       = 0;

		foreach ( $grupos as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$count++;
			$activos     += (int) ( $g['activos'] ?? 0 );
			$max_pupilos += (int) ( $g['max_pupilos'] ?? 0 );
			$espera      += (int) ( $g['espera'] ?? 0 );
		}

		$completo = ( $max_pupilos > 0 ) && ( $activos >= $max_pupilos );

		return array(
			'has_groups'     => $count > 0,
			'activos'        => $activos,
			'max_pupilos'    => $max_pupilos,
			'espera'         => $espera,
			'completo'       => $completo,
			'espera_visible' => $completo,
		);
	}

	/**
	 * CSS class for the `activos` number given a summary.
	 *
	 * @since  1.41.0
	 * @param  array<string,mixed> $summary Output of summary().
	 * @return string 'anpa-extra-prazas-ok' (free places) or
	 *                'anpa-extra-prazas-completo' (full).
	 */
	public static function activos_class( array $summary ): string {
		return ! empty( $summary['completo'] ) ? 'anpa-extra-prazas-completo' : 'anpa-extra-prazas-ok';
	}
}
