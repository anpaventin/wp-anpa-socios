<?php
/**
 * Data retention rules (1.83.0): members' data is deleted N months after their
 * baixa (default 9) and the audit log keeps M months (default 12). Pure; the
 * daily job lives in ANPA_Socios_Retencion_Service.
 *
 * @since   1.83.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure retention helpers.
 *
 * @since 1.83.0
 */
final class ANPA_Socios_Retencion {

	/** Months a member's data is kept after the baixa. */
	const MESES_BAIXA = 9;

	/** Months the audit log is kept. */
	const MESES_AUDITORIA = 12;

	/**
	 * A months setting, bounded to 1..120; anything else gives the default.
	 *
	 * @param  mixed $valor   Stored value.
	 * @param  int   $defecto Default.
	 * @return int
	 */
	public static function meses( $valor, int $defecto ): int {
		if ( is_int( $valor ) || ( is_string( $valor ) && ctype_digit( trim( $valor ) ) ) ) {
			$n = (int) $valor;
			if ( $n >= 1 && $n <= 120 ) {
				return $n;
			}
		}
		return $defecto;
	}

	/**
	 * $data plus (or minus) N months, as «Y-m-d H:i:s». Month ends clamp
	 * (31 August + 6 months = 28/29 February), never spill into the next month.
	 *
	 * @param  string $data  «Y-m-d H:i:s» or «Y-m-d».
	 * @param  int    $meses Months (negative to go back).
	 * @return string '' when $data is not a date.
	 */
	public static function sumar_meses( string $data, int $meses ): string {
		try {
			$d = new DateTimeImmutable( $data, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return '';
		}
		$y  = (int) $d->format( 'Y' );
		$m  = (int) $d->format( 'n' ) + $meses;
		$y += (int) floor( ( $m - 1 ) / 12 );
		$m  = ( ( $m - 1 ) % 12 + 12 ) % 12 + 1;
		$dia = min( (int) $d->format( 'j' ), (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $m ), new DateTimeZone( 'UTC' ) ) )->format( 't' ) );
		return sprintf( '%04d-%02d-%02d %s', $y, $m, $dia, $d->format( 'H:i:s' ) );
	}

	/**
	 * What the daily job deletes for a family whose member reached the deadline:
	 * 'familia' when every member is in baixa and past the cut-off (children,
	 * enrolments, banking… go too); 'persoa' when someone is still (or again)
	 * a member — then only that person's own data goes.
	 *
	 * @param  array<int,array<string,mixed>> $membros Rows (estado, baixa_en).
	 * @param  string                         $corte   Cut-off «Y-m-d H:i:s» (now − N months).
	 * @return string 'familia' | 'persoa'
	 */
	public static function alcance( array $membros, string $corte ): string {
		if ( array() === $membros ) {
			return 'persoa';
		}
		foreach ( $membros as $m ) {
			$en = (string) ( $m['baixa_en'] ?? '' );
			if ( 'baixa' !== (string) ( $m['estado'] ?? '' ) || '' === $en || strcmp( $en, $corte ) > 0 ) {
				return 'persoa';
			}
		}
		return 'familia';
	}
}
