<?php
/**
 * Upcoming events (1.101.0): pure helpers for the event date of a post and the
 * «Próximos eventos» block.
 *
 * @since  1.101.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Event dates: validation, ordering and labels in Galician.
 *
 * @since 1.101.0
 */
final class ANPA_Socios_Eventos {

	/** Post meta: first day (Y-m-d). */
	const META_DATA = '_anpa_evento_data';

	/** Post meta: start time (H:i), optional. */
	const META_HORA = '_anpa_evento_hora';

	/** Post meta: last day (Y-m-d), optional. */
	const META_FIN = '_anpa_evento_fin';

	/** Post meta: last day used to hide past events (= fin or data). */
	const META_ATA = '_anpa_evento_ata';

	const DIAS        = array( 'domingo', 'luns', 'martes', 'mércores', 'xoves', 'venres', 'sábado' );
	const DIAS_CURTOS = array( 'dom', 'lun', 'mar', 'mér', 'xov', 'ven', 'sáb' );
	const MESES       = array( 1 => 'xaneiro', 'febreiro', 'marzo', 'abril', 'maio', 'xuño', 'xullo', 'agosto', 'setembro', 'outubro', 'novembro', 'decembro' );
	const MESES_CURTOS = array( 1 => 'xan', 'feb', 'mar', 'abr', 'mai', 'xuñ', 'xul', 'ago', 'set', 'out', 'nov', 'dec' );

	/**
	 * A valid Y-m-d date or ''.
	 *
	 * @param  mixed $v Raw value.
	 * @return string
	 */
	public static function data( $v ): string {
		$v  = trim( (string) $v );
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $v );
		return ( false !== $dt && $dt->format( 'Y-m-d' ) === $v ) ? $v : '';
	}

	/**
	 * A valid H:i time or ''.
	 *
	 * @param  mixed $v Raw value.
	 * @return string
	 */
	public static function hora( $v ): string {
		$v = trim( (string) $v );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : '';
	}

	/**
	 * Clean values to store: start date (required for an event), optional time
	 * and end; the end is dropped when it is not after the start.
	 *
	 * @param  mixed $data Start.
	 * @param  mixed $hora Time.
	 * @param  mixed $fin  End.
	 * @return array{data:string,hora:string,fin:string,ata:string} Empty strings = no event.
	 */
	public static function limpar( $data, $hora, $fin ): array {
		$d = self::data( $data );
		if ( '' === $d ) {
			return array( 'data' => '', 'hora' => '', 'fin' => '', 'ata' => '' );
		}
		$f = self::data( $fin );
		if ( '' !== $f && $f <= $d ) {
			$f = '';
		}
		return array( 'data' => $d, 'hora' => self::hora( $hora ), 'fin' => $f, 'ata' => '' !== $f ? $f : $d );
	}

	/**
	 * Events still to come (their last day is today or later), soonest first.
	 *
	 * @param  array<int,array<string,mixed>> $eventos Rows with data, ata, hora.
	 * @param  string                         $hoxe    Today (Y-m-d).
	 * @param  int                            $max     How many.
	 * @return array<int,array<string,mixed>>
	 */
	public static function proximos( array $eventos, string $hoxe, int $max = 3 ): array {
		$out = array_values( array_filter( $eventos, static function ( $e ) use ( $hoxe ) {
			$ata = (string) ( $e['ata'] ?? ( $e['data'] ?? '' ) );
			return '' !== self::data( $e['data'] ?? '' ) && $ata >= $hoxe;
		} ) );
		usort( $out, static function ( $a, $b ) {
			return array( (string) $a['data'], (string) ( $a['hora'] ?? '' ) ) <=> array( (string) $b['data'], (string) ( $b['hora'] ?? '' ) );
		} );
		return array_slice( $out, 0, max( 1, $max ) );
	}

	/**
	 * Parts of the date chip: «ven», «30», «out».
	 *
	 * @param  string $data Y-m-d.
	 * @return array{dia:string,num:string,mes:string}
	 */
	public static function chip( string $data ): array {
		$dt = new DateTimeImmutable( $data );
		return array(
			'dia' => self::DIAS_CURTOS[ (int) $dt->format( 'w' ) ],
			'num' => $dt->format( 'j' ),
			'mes' => self::MESES_CURTOS[ (int) $dt->format( 'n' ) ],
		);
	}

	/**
	 * Full label: «venres 30 de outubro, ás 16:30» or «do luns 2 ao venres 6 de novembro».
	 *
	 * @param  string $data Start (Y-m-d).
	 * @param  string $hora Time (H:i) or ''.
	 * @param  string $fin  End (Y-m-d) or ''.
	 * @return string
	 */
	public static function etiqueta( string $data, string $hora = '', string $fin = '' ): string {
		$d = new DateTimeImmutable( $data );
		$l = function ( DateTimeImmutable $x, bool $mes ) {
			return self::DIAS[ (int) $x->format( 'w' ) ] . ' ' . $x->format( 'j' ) . ( $mes ? ' de ' . self::MESES[ (int) $x->format( 'n' ) ] : '' );
		};
		if ( '' !== $fin ) {
			$f      = new DateTimeImmutable( $fin );
			$mesmo  = $d->format( 'Y-m' ) === $f->format( 'Y-m' );
			$texto  = 'do ' . $l( $d, ! $mesmo ) . ' ao ' . $l( $f, true );
		} else {
			$texto = $l( $d, true );
		}
		return $texto . ( '' !== $hora ? ', ás ' . $hora : '' );
	}
}
