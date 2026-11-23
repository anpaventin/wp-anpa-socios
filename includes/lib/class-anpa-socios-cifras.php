<?php
/**
 * Pure helpers for the «A ANPA en cifras» block (1.95.0).
 *
 * Turns the raw aggregate counts (families, pupils, enrolments, groups) into
 * the list of figures the public shortcode and the socios panel show, with the
 * privacy rule for small numbers: a public figure below the threshold is shown
 * as «menos de N» so nobody can work out who is behind it.
 *
 * No WordPress dependency apart from the __() / _n() translation calls, so it
 * is fully unit-testable.
 *
 * @since  1.95.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Figures for the public and socios-only statistics blocks.
 *
 * @since 1.95.0
 */
final class ANPA_Socios_Cifras {

	/**
	 * Public figures below this value are shown as «menos de 5».
	 */
	const LIMIAR = 5;

	/**
	 * Figures the public shortcode shows when no `mostrar` attribute is given.
	 */
	const PUBLICAS_DEFECTO = array( 'familias', 'alumnado', 'extraescolares', 'actividades', 'prazas' );

	/**
	 * Every figure key the shortcode accepts, in display order.
	 */
	const CLAVES = array( 'familias', 'alumnado', 'extraescolares', 'inscricions', 'actividades', 'empresas', 'prazas', 'comedor', 'tarde' );

	/**
	 * Parses the shortcode `mostrar` attribute into known keys, in the order given.
	 *
	 * Unknown keys and duplicates are dropped; an empty or fully unknown list
	 * falls back to PUBLICAS_DEFECTO.
	 *
	 * @since  1.95.0
	 * @param  string $mostrar Comma-separated keys, e.g. "familias, prazas".
	 * @return string[]
	 */
	public static function claves( string $mostrar ): array {
		$out = array();
		foreach ( explode( ',', strtolower( $mostrar ) ) as $k ) {
			$k = trim( $k );
			if ( in_array( $k, self::CLAVES, true ) && ! in_array( $k, $out, true ) ) {
				$out[] = $k;
			}
		}
		return array() === $out ? self::PUBLICAS_DEFECTO : $out;
	}

	/**
	 * Display value for a public count: the number, or «menos de N» when small.
	 *
	 * Zero is shown as 0 (nobody is identifiable in an empty set).
	 *
	 * @since  1.95.0
	 * @param  int $n      Count.
	 * @param  int $limiar Threshold (counts 1..limiar-1 are hidden).
	 * @return string
	 */
	public static function valor_publico( int $n, int $limiar = self::LIMIAR ): string {
		if ( $n > 0 && $n < $limiar ) {
			/* translators: %d: privacy threshold, e.g. 5 */
			return sprintf( __( 'menos de %d', 'anpa-socios' ), $limiar );
		}
		return number_format( max( 0, $n ), 0, ',', '.' );
	}

	/**
	 * Label under a figure, singular or plural by count.
	 *
	 * @since  1.95.0
	 * @param  string $clave Figure key.
	 * @param  int    $n     Count (decides singular/plural).
	 * @return string '' for an unknown key.
	 */
	public static function etiqueta( string $clave, int $n ): string {
		switch ( $clave ) {
			case 'familias':
				return _n( 'familia socia', 'familias socias', $n, 'anpa-socios' );
			case 'alumnado':
				return _n( 'alumno/a de familias socias', 'alumnos/as de familias socias', $n, 'anpa-socios' );
			case 'extraescolares':
				return _n( 'neno/a en extraescolares', 'nenos/as en extraescolares', $n, 'anpa-socios' );
			case 'inscricions':
				return _n( 'inscrición en extraescolares', 'inscricións en extraescolares', $n, 'anpa-socios' );
			case 'actividades':
				return _n( 'actividade extraescolar', 'actividades extraescolares', $n, 'anpa-socios' );
			case 'empresas':
				return _n( 'empresa colaboradora', 'empresas colaboradoras', $n, 'anpa-socios' );
			case 'prazas':
				return _n( 'grupo con prazas libres', 'grupos con prazas libres', $n, 'anpa-socios' );
			case 'comedor':
				return _n( 'neno/a en actividades de mediodía', 'nenos/as en actividades de mediodía', $n, 'anpa-socios' );
			case 'tarde':
				return _n( 'neno/a en actividades de tarde', 'nenos/as en actividades de tarde', $n, 'anpa-socios' );
		}
		return '';
	}

	/**
	 * Builds the figure list for the given keys.
	 *
	 * @since  1.95.0
	 * @param  array<string,int> $datos   Raw counts keyed like CLAVES.
	 * @param  string[]          $claves  Keys to show, in order.
	 * @param  bool              $publico True applies the small-number rule.
	 * @return array<int,array{clave:string,valor:string,etiqueta:string}>
	 */
	public static function items( array $datos, array $claves, bool $publico ): array {
		$out = array();
		foreach ( $claves as $clave ) {
			if ( ! array_key_exists( $clave, $datos ) ) {
				continue;
			}
			$n = max( 0, (int) $datos[ $clave ] );
			// A public block never advertises an empty offer («0 actividades»).
			if ( $publico && 0 === $n && in_array( $clave, array( 'actividades', 'empresas', 'prazas', 'extraescolares', 'inscricions', 'comedor', 'tarde' ), true ) ) {
				continue;
			}
			$out[] = array(
				'clave'    => $clave,
				'valor'    => $publico ? self::valor_publico( $n ) : number_format( $n, 0, ',', '.' ),
				'etiqueta' => self::etiqueta( $clave, $n ),
			);
		}
		return $out;
	}

	/**
	 * Places summary of the groups that still take enrolments.
	 *
	 * A group with max_pupilos 0 has no limit and always counts as having
	 * places. Free places are only summed for limited groups.
	 *
	 * @since  1.95.0
	 * @param  array<int,array<string,mixed>> $grupos Rows with max_pupilos and activos.
	 * @return array{grupos_con_prazas:int,prazas_libres:int,sen_limite:int,completos:int}
	 */
	public static function prazas( array $grupos ): array {
		$con = 0;
		$libres = 0;
		$sen_limite = 0;
		$completos = 0;
		foreach ( $grupos as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$max     = (int) ( $g['max_pupilos'] ?? 0 );
			$activos = (int) ( $g['activos'] ?? 0 );
			if ( $max <= 0 ) {
				$sen_limite++;
				$con++;
				continue;
			}
			$quedan = $max - $activos;
			if ( $quedan > 0 ) {
				$con++;
				$libres += $quedan;
			} else {
				$completos++;
			}
		}
		return array(
			'grupos_con_prazas' => $con,
			'prazas_libres'     => $libres,
			'sen_limite'        => $sen_limite,
			'completos'         => $completos,
		);
	}

	/**
	 * Date from which a socio counts as a new «alta» of the course.
	 *
	 * Families sign up over the summer for the coming course, so the course
	 * «2026/2027» counts altas from 1 July 2026.
	 *
	 * @since  1.95.0
	 * @param  string $curso School year like "2026/2027".
	 * @return string Y-m-d date, '' when the course is not valid.
	 */
	public static function inicio_altas( string $curso ): string {
		if ( ! preg_match( '/^(\d{4})\/\d{4}$/', $curso, $m ) ) {
			return '';
		}
		return $m[1] . '-07-01';
	}
}
