<?php
/**
 * Pure meal-window availability helper (fase26 D6).
 *
 * No WordPress, no DB. Times are canonical `HH:MM`; intervals are semi-open
 * `[inicio, fin)`, so contiguous slots never overlap. This class is the ONLY
 * source of the "the level is having lunch" block — legacy `horario=manha`
 * labels, `matriculas.comedor` authorisations and the 1.12.0 migration
 * hardcodes are presentation/consent/history and MUST NOT feed this gate.
 *
 * @since   1.44.0
 * @package ANPA_Socios
 */

declare( strict_types=1 );

if ( ! class_exists( 'ANPA_Socios_Disponibilidade_Horaria' ) ) {

	/**
	 * Normalisation and overlap arithmetic for annual meal windows.
	 */
	final class ANPA_Socios_Disponibilidade_Horaria {

		/**
		 * Canonicalises a wall-clock time.
		 *
		 * @param  string|null $value Raw input (e.g. `9:05`, ` 14:20 `).
		 * @return string|null `HH:MM` or null when unparseable.
		 */
		public static function normalize_time( $value ): ?string {
			if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) ) {
				return null;
			}
			$value = trim( (string) $value );
			if ( 1 !== preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m ) ) {
				return null;
			}
			return sprintf( '%02d:%s', (int) $m[1], $m[2] );
		}

		/**
		 * Normalises a start/end pair.
		 *
		 * @param  string|null $inicio Start time.
		 * @param  string|null $fin    End time.
		 * @return array{inicio:string,fin:string}|array{}|null Interval,
		 *         empty array when BOTH are blank (no window configured),
		 *         null when the pair is broken (one blank, bad time, or
		 *         `inicio >= fin`).
		 */
		public static function normalize_interval( $inicio, $fin ): ?array {
			if ( ( null !== $inicio && ! is_string( $inicio ) && ! is_int( $inicio ) )
				|| ( null !== $fin && ! is_string( $fin ) && ! is_int( $fin ) ) ) {
				return null;
			}
			$inicio = trim( (string) $inicio );
			$fin    = trim( (string) $fin );
			if ( '' === $inicio && '' === $fin ) {
				return array();
			}

			$a = self::normalize_time( $inicio );
			$b = self::normalize_time( $fin );
			if ( null === $a || null === $b || $a >= $b ) {
				return null;
			}
			return array( 'inicio' => $a, 'fin' => $b );
		}

		/**
		 * Semi-open interval overlap: `a_inicio < b_fin AND a_fin > b_inicio`.
		 *
		 * Canonical `HH:MM` strings compare correctly as strings.
		 *
		 * @param  string $a_inicio Interval A start.
		 * @param  string $a_fin    Interval A end.
		 * @param  string $b_inicio Interval B start.
		 * @param  string $b_fin    Interval B end.
		 * @return bool True when the intervals share any time.
		 */
		public static function overlaps( string $a_inicio, string $a_fin, string $b_inicio, string $b_fin ): bool {
			return $a_inicio < $b_fin && $a_fin > $b_inicio;
		}

		/**
		 * Detects meal-window conflicts for one annual group row.
		 *
		 * A group without a parseable `HH:MM-HH:MM` schedule never invents a
		 * block. Levels with an empty window (both NULL) never conflict.
		 *
		 * @param  array{horario?:string,dias?:array<int,string>} $group Group row.
		 * @param  array<int|string,array{inicio?:string,fin?:string}|array{}> $level_meals
		 *         Meal window per nivel_id (empty array = none configured).
		 * @return array<int,array{nivel_id:int,comedor_inicio:string,comedor_fin:string}>
		 */
		public static function conflicts( array $group, array $level_meals ): array {
			$horario = trim( (string) ( $group['horario'] ?? '' ) );
			if ( 1 !== preg_match( '/^(\S+)\s*-\s*(\S+)$/', $horario, $m ) ) {
				return array();
			}
			$g_inicio = self::normalize_time( $m[1] );
			$g_fin    = self::normalize_time( $m[2] );
			if ( null === $g_inicio || null === $g_fin || $g_inicio >= $g_fin ) {
				return array();
			}

			$conflicts = array();
			foreach ( $level_meals as $nivel_id => $meal ) {
				$inicio = (string) ( $meal['inicio'] ?? '' );
				$fin    = (string) ( $meal['fin'] ?? '' );
				if ( '' === $inicio || '' === $fin ) {
					continue;
				}
				if ( self::overlaps( $g_inicio, $g_fin, $inicio, $fin ) ) {
					$conflicts[] = array(
						'nivel_id'       => (int) $nivel_id,
						'comedor_inicio' => $inicio,
						'comedor_fin'    => $fin,
					);
				}
			}
			return $conflicts;
		}
	}
}
