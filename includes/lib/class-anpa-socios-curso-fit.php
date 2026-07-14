<?php
/**
 * Pure helper: does a fillo's curso fit a group's nivel set?
 *
 * Supports both legacy RANGES and dynamic grupos_niveis lookup.
 * No WordPress dependency.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Curso-range / grupos_niveis membership logic.
 *
 * Supports dual-path design: RANGES matches only the literal legacy
 * range-key strings ('1-2-3'/'4-5-6'). When curso_range is a numeric
 * grupo_id, fit is resolved via the dynamic grupos_niveis table instead.
 * There is no collision risk between legacy string keys and numeric IDs.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Curso_Fit {

	/**
	 * Canonical curso range → member cursos.
	 *
	 * @since 1.9.0
	 * @var array<string,string[]>
	 */
	const RANGES = array(
		'1-2-3' => array( '1', '2', '3' ),
		'4-5-6' => array( '4', '5', '6' ),
	);

	/**
	 * Returns whether $curso belongs to $curso_range or to the dynamic
	 * nivel set resolved via grupos_niveis.
	 *
	 * @since  1.9.0
	 * @param  string $curso       Fillo curso ('1'..'6').
	 * @param  string $curso_range Canonical range key.
	 * @return bool
	 */
	public static function fits( string $curso, string $curso_range ): bool {
		$curso = trim( $curso );

		// Legacy RANGES fallback
		if ( isset( self::RANGES[ $curso_range ] ) ) {
			return in_array( $curso, self::RANGES[ $curso_range ], true );
		}

		// Dynamic grupos_niveis: curso_range is actually a grupo_id
		if ( is_numeric( $curso_range ) ) {
			$nivel_ids = ANPA_Socios_DB::get_niveis_for_grupo( (int) $curso_range );
			if ( array() === $nivel_ids ) {
				return false;
			}
			// Map curso string to nivel_id by querying the niveis table
			return self::curso_in_nivel_ids( $curso, $nivel_ids );
		}

		return false;
	}

	/**
	 * Checks if a curso string matches any of the given nivel_ids.
	 *
	 * @since  1.27.0
	 * @param  string $curso     Fillo curso.
	 * @param  int[]  $nivel_ids Nivel ids.
	 * @return bool
	 */
	private static function curso_in_nivel_ids( string $curso, array $nivel_ids ): bool {
		global $wpdb;

		if ( array() === $nivel_ids ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $nivel_ids ), '%d' ) );
		$params       = $nivel_ids;
		$params[]     = $curso;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- pure utility.
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}anpa_niveis WHERE id IN ({$placeholders}) AND codigo = %s",
			$params
		) );

		return '1' === $found;
	}

	/**
	 * Returns the canonical range key for a curso, or '' if none.
	 *
	 * @since  1.9.0
	 * @param  string $curso Fillo curso.
	 * @return string
	 */
	public static function range_for( string $curso ): string {
		foreach ( self::RANGES as $range => $members ) {
			if ( in_array( trim( $curso ), $members, true ) ) {
				return $range;
			}
		}

		return '';
	}

	/**
	 * Whether a value is a valid canonical range key.
	 *
	 * @since  1.9.0
	 * @param  string $curso_range Candidate range key.
	 * @return bool
	 */
	public static function is_range( string $curso_range ): bool {
		return isset( self::RANGES[ $curso_range ] );
	}
}
