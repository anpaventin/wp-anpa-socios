<?php
/**
 * Pure course season lifecycle helper (fase12).
 *
 * A course row has a seasonal lifecycle:
 *   - pendente : created, season not started yet (pre-season)
 *   - activo   : season running
 *   - pechado  : season finished
 *
 * Default season dates for a curso "YYYY/YYYY+1":
 *   - data_inicio : YYYY-09-01     (season start / auto-activation)
 *   - data_peche  : (YYYY+1)-06-20 (season end / auto-close)
 *
 * All comparisons use Y-m-d strings, which compare correctly lexicographically.
 *
 * @since  1.18.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Season {

	const ESTADO_PENDENTE = 'pendente';
	const ESTADO_ACTIVO   = 'activo';
	const ESTADO_PECHADO  = 'pechado';

	/**
	 * Season start date (1 September of the course start year).
	 */
	public static function default_data_inicio( string $curso ): string {
		return sprintf( '%04d-09-01', self::start_year( $curso ) );
	}

	/**
	 * Season end date (20 June of the course end year).
	 */
	public static function default_data_peche( string $curso ): string {
		return sprintf( '%04d-06-20', self::start_year( $curso ) + 1 );
	}

	/**
	 * Should an active course be closed today?
	 */
	public static function should_close( string $today, string $estado, string $data_peche ): bool {
		return self::ESTADO_ACTIVO === $estado && '' !== $data_peche && $today >= $data_peche;
	}

	/**
	 * Should a pending course be activated today?
	 */
	public static function should_activate( string $today, string $estado, string $data_inicio ): bool {
		return self::ESTADO_PENDENTE === $estado && '' !== $data_inicio && $today >= $data_inicio;
	}

	/**
	 * Derive the lifecycle state for a course from today and its season dates.
	 * Used for the migration seed and as a fail-safe.
	 */
	public static function estado_for( string $today, string $data_inicio, string $data_peche ): string {
		if ( '' !== $data_peche && $today >= $data_peche ) {
			return self::ESTADO_PECHADO;
		}
		if ( '' !== $data_inicio && $today < $data_inicio ) {
			return self::ESTADO_PENDENTE;
		}

		return self::ESTADO_ACTIVO;
	}

	/**
	 * The next course label after the given one.
	 */
	public static function next_curso( string $curso ): string {
		return ANPA_Socios_Curso_Escolar::next( $curso );
	}

	private static function start_year( string $curso ): int {
		if ( ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return (int) substr( $curso, 0, 4 );
		}

		return (int) substr( ANPA_Socios_Curso_Escolar::current(), 0, 4 );
	}
}
