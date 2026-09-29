<?php
/**
 * Member baixa while the course is running (1.79.0): as the alta explains, once
 * the course has started a member baixa is only effective at the end of the
 * course. Pure rule + date text.
 *
 * @since   1.79.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Pure helpers for member baixas.
 *
 * @since 1.79.0
 */
final class ANPA_Socios_Baixa_Socio {

	/**
	 * Whether the active course is running today (start ≤ today ≤ end; no end = running once started).
	 *
	 * @param  string $estado_curso Course state.
	 * @param  string $inicio       Start date (Y-m-d or '').
	 * @param  string $peche        End date (Y-m-d or '').
	 * @param  string $hoxe         Today (Y-m-d).
	 * @return bool
	 */
	public static function curso_en_marcha( string $estado_curso, string $inicio, string $peche, string $hoxe ): bool {
		if ( 'activo' !== $estado_curso || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $inicio ) || strcmp( $hoxe, $inicio ) < 0 ) {
			return false;
		}
		return ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $peche ) || strcmp( $hoxe, $peche ) <= 0;
	}

	/**
	 * «20 de xuño de 2027», or «o remate do curso» without a date.
	 *
	 * @param  string $peche End date (Y-m-d or '').
	 * @return string
	 */
	public static function remate_texto( string $peche ): string {
		$t = ANPA_Socios_Oferta_Publica::data_longa( $peche );
		return '' !== $t ? $t : 'o remate do curso';
	}
}
