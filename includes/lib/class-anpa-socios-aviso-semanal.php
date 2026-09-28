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
	 * @param  array<string,int> $contas ANPA_Socios_Admin_Approvals_Handler::contas_pendentes().
	 * @return bool Only when something is pending.
	 */
	public static function debe_enviar( array $contas ): bool {
		return (int) ( $contas['total'] ?? 0 ) > 0;
	}
}
