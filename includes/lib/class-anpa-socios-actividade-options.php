<?php
/**
 * Pure helpers for activity option sets (horario / grupo curricular / días).
 *
 * Activities (fase7) define selectable option sets instead of a numeric age
 * range. The sets are stored as canonical ASCII CSV tokens (no accents in
 * data). This class parses, normalises, serialises and validates those sets
 * with no WordPress dependency, so it is fully unit-testable.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Canonical option-set logic for actividades.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Actividade_Options {

	/**
	 * Valid horario tokens (ASCII; UI shows "Mañá"/"Tarde").
	 *
	 * @since 1.9.0
	 * @var string[]
	 */
	const HORARIOS = array( 'manha', 'tarde' );

	/**
	 * Valid grupo-curricular tokens (curso ranges).
	 *
	 * @since 1.9.0
	 * @var string[]
	 */
	const GRUPOS = array( '1-2-3', '4-5-6' );

	/**
	 * Valid día tokens, Monday–Friday (ASCII).
	 *
	 * @since 1.9.0
	 * @var string[]
	 */
	const DIAS = array( 'luns', 'martes', 'mercores', 'xoves', 'venres' );

	/**
	 * Normalises a raw value (array or CSV string) into a canonical, ordered,
	 * de-duplicated array containing only tokens present in $allowed.
	 *
	 * The result preserves the order of $allowed (canonical), not input order,
	 * so equivalent selections serialise identically.
	 *
	 * @since  1.9.0
	 * @param  mixed    $value   Array of tokens or a CSV string.
	 * @param  string[] $allowed Allowed canonical tokens.
	 * @return string[]
	 */
	public static function normalize( $value, array $allowed ): array {
		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? array() : explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$seen = array();
		foreach ( $value as $token ) {
			$token = strtolower( trim( (string) $token ) );
			if ( in_array( $token, $allowed, true ) ) {
				$seen[ $token ] = true;
			}
		}

		// Re-emit in canonical (allowed) order.
		$out = array();
		foreach ( $allowed as $token ) {
			if ( isset( $seen[ $token ] ) ) {
				$out[] = $token;
			}
		}

		return $out;
	}

	/**
	 * Parses a stored CSV token string into a canonical array.
	 *
	 * @since  1.9.0
	 * @param  string   $csv     Stored CSV value.
	 * @param  string[] $allowed Allowed canonical tokens.
	 * @return string[]
	 */
	public static function parse( string $csv, array $allowed ): array {
		return self::normalize( $csv, $allowed );
	}

	/**
	 * Serialises a set into a canonical CSV string (valid, deduped, ordered).
	 *
	 * @since  1.9.0
	 * @param  mixed    $value   Array of tokens or a CSV string.
	 * @param  string[] $allowed Allowed canonical tokens.
	 * @return string
	 */
	public static function serialize( $value, array $allowed ): string {
		return implode( ',', self::normalize( $value, $allowed ) );
	}

	/**
	 * Validates that each of the three sets has at least one valid member.
	 *
	 * @since  1.9.0
	 * @param  mixed $horarios Raw horarios (array or CSV).
	 * @param  mixed $grupos   Raw grupos (array or CSV).
	 * @param  mixed $dias     Raw días (array or CSV).
	 * @return bool True when all three normalise to a non-empty set.
	 */
	public static function validate( $horarios, $grupos, $dias ): bool {
		return array() !== self::normalize( $horarios, self::HORARIOS )
			&& array() !== self::normalize( $grupos, self::GRUPOS )
			&& array() !== self::normalize( $dias, self::DIAS );
	}

	/**
	 * Normalises a real timetable slot into `HH:MM-HH:MM`, or null if invalid.
	 *
	 * @since  1.10.0
	 * @param  mixed $value Raw franxa value.
	 * @return string|null
	 */
	public static function normalize_franxa( $value ): ?string {
		$str = trim( (string) $value );
		if ( ! preg_match( '/^(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2})$/', $str, $m ) ) {
			return null;
		}

		$h1 = (int) $m[1];
		$i1 = (int) $m[2];
		$h2 = (int) $m[3];
		$i2 = (int) $m[4];
		if ( $h1 > 23 || $h2 > 23 || $i1 > 59 || $i2 > 59 ) {
			return null;
		}

		$start = $h1 * 60 + $i1;
		$end   = $h2 * 60 + $i2;
		if ( $end <= $start ) {
			return null;
		}

		return sprintf( '%02d:%02d-%02d:%02d', $h1, $i1, $h2, $i2 );
	}
}
