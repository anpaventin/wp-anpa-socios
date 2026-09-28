<?php
/**
 * Public offer helpers (1.71.0): activity start/end dates configured in
 * Axustes → Xeral, and the state each group shows to families once the
 * enrolment window is closed. Pure: no WordPress state, no I/O.
 *
 * @since   1.71.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Pure helpers for the [anpa_extraescolares_ofertadas] page.
 *
 * @since 1.71.0
 */
final class ANPA_Socios_Oferta_Publica {

	/** Group open for enrolment (shown as today). */
	const ABERTO = 'aberto';

	/** Group closed and confirmed by the junta («grupo creado» notice): shown with «Creado». */
	const CREADO = 'creado';

	/** Group closed below its minimum: listed by name only. */
	const NON_ACADADO = 'non_acadado';

	const MESES = array( 1 => 'xaneiro', 'febreiro', 'marzo', 'abril', 'maio', 'xuño', 'xullo', 'agosto', 'setembro', 'outubro', 'novembro', 'decembro' );

	/**
	 * @param  string $value Raw Y-m-d value.
	 * @return string The date, or '' when it is not a real calendar day.
	 */
	public static function data_valida( string $value ): string {
		$value = trim( $value );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * «1 de outubro de 2026» (or without the year).
	 *
	 * @param  string $data    Y-m-d.
	 * @param  bool   $con_ano Append the year.
	 * @return string '' for an invalid date.
	 */
	public static function data_longa( string $data, bool $con_ano = true ): string {
		$data = self::data_valida( $data );
		if ( '' === $data ) {
			return '';
		}
		list( $ano, $mes, $dia ) = array_map( 'intval', explode( '-', $data ) );
		return $dia . ' de ' . self::MESES[ $mes ] . ( $con_ano ? ' de ' . $ano : '' );
	}

	/**
	 * Sentence for the information box at the top of the page ('' = no box).
	 *
	 * @param  string $inicio Start date (Y-m-d or '').
	 * @param  string $remate End date (Y-m-d or '').
	 * @return string
	 */
	public static function aviso_datas( string $inicio, string $remate ): string {
		$i = self::data_longa( $inicio );
		$r = self::data_longa( $remate );
		if ( '' !== $i && '' !== $r ) {
			return sprintf( __( 'As actividades extraescolares comezan o %1$s e rematan o %2$s.', 'anpa-socios' ), $i, $r );
		}
		if ( '' !== $i ) {
			return sprintf( __( 'As actividades extraescolares comezan o %s.', 'anpa-socios' ), $i );
		}
		if ( '' !== $r ) {
			return sprintf( __( 'As actividades extraescolares rematan o %s.', 'anpa-socios' ), $r );
		}
		return '';
	}

	/**
	 * Short line inside each activity card ('' = no line).
	 *
	 * @param  string $inicio Start date (Y-m-d or '').
	 * @param  string $remate End date (Y-m-d or '').
	 * @return string
	 */
	public static function texto_curto( string $inicio, string $remate ): string {
		$i = self::data_longa( $inicio, false );
		$r = self::data_longa( $remate, false );
		if ( '' !== $i && '' !== $r ) {
			return sprintf( __( 'Do %1$s ao %2$s', 'anpa-socios' ), $i, $r );
		}
		if ( '' !== $i ) {
			return sprintf( __( 'Comeza o %s', 'anpa-socios' ), $i );
		}
		if ( '' !== $r ) {
			return sprintf( __( 'Remata o %s', 'anpa-socios' ), $r );
		}
		return '';
	}

	/**
	 * What the public page does with a group of the active course.
	 *
	 * - aberto → shown as today; «Creado» when it was already created and has pupils (reopened, 1.72.0).
	 * - pechado, notified «grupo creado» and with pupils → «Creado».
	 * - pechado below the minimum → «Non acadaron o mínimo».
	 * - deshabilitado that had enrolments and is below the minimum → «Non acadaron o
	 *   mínimo» (the «Pechar por non acadar o mínimo» button disables the group and
	 *   gives every enrolment baixa). Any other deshabilitado group is never shown.
	 *
	 * @param  string      $estado   Group state (aberto|pechado|deshabilitado).
	 * @param  string|null $aviso_en When the «grupo creado» notice was sent or marked (null = never).
	 * @param  int         $activos  Active enrolments.
	 * @param  int         $minimo   Group minimum.
	 * @param  int         $total    Enrolments of any state (0 = nobody ever signed up).
	 * @return string self::ABERTO | self::CREADO | self::NON_ACADADO | '' (not shown)
	 */
	public static function estado_grupo( string $estado, ?string $aviso_en, int $activos, int $minimo, int $total = 0 ): string {
		if ( 'aberto' === $estado ) {
			// 1.72.0: a created group reopened for the next trimester keeps «Creado».
			return ( null !== $aviso_en && '' !== trim( $aviso_en ) && $activos > 0 ) ? self::CREADO : self::ABERTO;
		}
		if ( 'pechado' === $estado ) {
			if ( null !== $aviso_en && '' !== trim( $aviso_en ) && $activos > 0 ) {
				return self::CREADO;
			}
			return $activos < $minimo ? self::NON_ACADADO : '';
		}
		if ( 'deshabilitado' === $estado && $total > 0 && $activos < $minimo ) {
			return self::NON_ACADADO;
		}
		return '';
	}

	/**
	 * Groups below the minimum, by activity (names only, alphabetical, no duplicates).
	 *
	 * @param  array<int,array{actividade:string,grupo:string}> $rows Rows.
	 * @return array<int,array{actividade:string,grupos:array<int,string>}>
	 */
	public static function non_acadados( array $rows ): array {
		$by = array();
		foreach ( $rows as $r ) {
			$a = trim( (string) ( $r['actividade'] ?? '' ) );
			$g = trim( (string) ( $r['grupo'] ?? '' ) );
			if ( '' === $a ) {
				continue;
			}
			if ( ! isset( $by[ $a ] ) ) {
				$by[ $a ] = array();
			}
			if ( '' !== $g && ! in_array( $g, $by[ $a ], true ) ) {
				$by[ $a ][] = $g;
			}
		}
		uksort( $by, 'strcoll' );
		$out = array();
		foreach ( $by as $a => $grupos ) {
			sort( $grupos, SORT_STRING );
			$out[] = array( 'actividade' => (string) $a, 'grupos' => $grupos );
		}
		return $out;
	}
}
