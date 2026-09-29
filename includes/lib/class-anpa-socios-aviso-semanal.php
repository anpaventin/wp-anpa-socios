<?php
/**
 * Monday 10:00 reminder to the junta when something waits in Operacións →
 * Aprobacións (1.74.0). Pure date rule; the cron glue lives in
 * ANPA_Socios_Aprobacions_Semanal.
 *
 * @since   1.74.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Pure helpers for the weekly reminder.
 *
 * @since 1.74.0
 */
final class ANPA_Socios_Aviso_Semanal {

	const HORA = 10;

	/**
	 * Next Monday at 10:00 local time strictly after $agora. Scheduled as a
	 * single event each week so the hour never drifts with summer time.
	 *
	 * @param  int          $agora Unix time.
	 * @param  DateTimeZone $tz    Site timezone.
	 * @return int Unix time.
	 */
	public static function proximo_luns( int $agora, DateTimeZone $tz ): int {
		$d = ( new DateTimeImmutable( '@' . $agora ) )->setTimezone( $tz );
		$c = $d->setTime( self::HORA, 0, 0 );
		$dias = ( 8 - (int) $c->format( 'N' ) ) % 7; // days until Monday (0 = today).
		$c = $c->modify( '+' . $dias . ' days' )->setTime( self::HORA, 0, 0 );
		if ( $c->getTimestamp() <= $agora ) {
			$c = $c->modify( '+7 days' )->setTime( self::HORA, 0, 0 );
		}
		return $c->getTimestamp();
	}

	/**
	 * @param  array<string,int>             $contas    ANPA_Socios_Admin_Approvals_Handler::contas_pendentes().
	 * @param  array<int,array<string,string>> $conflitos 1.77.0: members using a reserved address.
	 * @return bool When something is pending or the review found a problem.
	 */
	public static function debe_enviar( array $contas, array $conflitos = array() ): bool {
		return (int) ( $contas['total'] ?? 0 ) > 0 || array() !== $conflitos;
	}

	/**
	 * Weekly review line: members whose email is a company's, the canteen's or the junta's
	 * (they would open the other panel or mix two roles). Pure.
	 *
	 * @since  1.77.0
	 * @param  array<int,array{email:string,motivo:string,nome:string}> $conflitos Rows.
	 * @return string
	 */
	public static function revision_correos( array $conflitos ): string {
		if ( array() === $conflitos ) {
			return 'Ningún socio/a usa o correo dunha empresa, do comedor ou da xunta directiva.';
		}
		$partes = array();
		foreach ( $conflitos as $c ) {
			$motivo = (string) ( $c['motivo'] ?? '' );
			if ( 'empresa' === $motivo ) {
				$que = 'correo da empresa «' . (string) ( $c['nome'] ?? '' ) . '»';
			} elseif ( 'comedor' === $motivo ) {
				$que = 'correo do comedor';
			} else {
				$que = 'correo da xunta directiva';
			}
			$partes[] = (string) ( $c['email'] ?? '' ) . ' (' . $que . ')';
		}
		return 'OLLO: ' . count( $conflitos ) . ' socio/a(s) usan un correo reservado e deberían cambialo en Xestión → Socios/as: ' . implode( '; ', $partes ) . '.';
	}
}
