<?php
/**
 * Pure helper: does a fillo's curso fit a group's curso range?
 *
 * Curso ranges are the canonical grupos curriculares used by activities and
 * groups: `1-2-3` (1º-2º-3º) and `4-5-6` (4º-5º-6º). No WordPress dependency.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Curso-range membership logic.
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
	 * Returns whether $curso belongs to $curso_range.
	 *
	 * @since  1.9.0
	 * @param  string $curso       Fillo curso ('1'..'6').
	 * @param  string $curso_range Canonical range key.
	 * @return bool
	 */
	public static function fits( string $curso, string $curso_range ): bool {
		$curso = trim( $curso );

		return isset( self::RANGES[ $curso_range ] )
			&& in_array( $curso, self::RANGES[ $curso_range ], true );
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
