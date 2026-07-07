<?php
/**
 * Pure curso escolar helper.
 *
 * Course boundary is 1 July: dates from July to December belong to
 * YYYY/YYYY+1; dates from January to June belong to YYYY-1/YYYY.
 *
 * @since  1.10.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Curso_Escolar {

	public static function current(): string {
		return self::from_date( date( 'Y-m-d' ) );
	}

	public static function from_date( string $date ): string {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( false === $dt ) {
			$dt = new DateTimeImmutable( 'now' );
		}
		$year  = (int) $dt->format( 'Y' );
		$month = (int) $dt->format( 'n' );
		$start = ( $month >= 7 ) ? $year : $year - 1;

		return self::format_from_start( $start );
	}

	public static function is_valid( string $curso ): bool {
		if ( ! preg_match( '/^(\d{4})\/(\d{4})$/', $curso, $m ) ) {
			return false;
		}

		return (int) $m[2] === ( (int) $m[1] + 1 );
	}

	public static function around( string $curso, int $before = 1, int $after = 1 ): array {
		if ( ! self::is_valid( $curso ) ) {
			$curso = self::current();
		}
		$start = (int) substr( $curso, 0, 4 );
		$out   = array();
		for ( $y = $start - max( 0, $before ); $y <= $start + max( 0, $after ); $y++ ) {
			$out[] = self::format_from_start( $y );
		}

		return $out;
	}

	public static function previous( string $curso ): string {
		$start = self::is_valid( $curso ) ? (int) substr( $curso, 0, 4 ) : (int) substr( self::current(), 0, 4 );

		return self::format_from_start( $start - 1 );
	}

	public static function next( string $curso ): string {
		$start = self::is_valid( $curso ) ? (int) substr( $curso, 0, 4 ) : (int) substr( self::current(), 0, 4 );

		return self::format_from_start( $start + 1 );
	}

	private static function format_from_start( int $start ): string {
		return sprintf( '%04d/%04d', $start, $start + 1 );
	}
}
