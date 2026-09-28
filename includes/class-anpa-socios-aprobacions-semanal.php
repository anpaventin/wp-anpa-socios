<?php
/**
 * Monday 10:00 reminder of pending approvals (1.74.0). One single event per
 * week (rescheduled after each run) so the hour follows the site timezone
 * through summer time. Sends only when something is pending.
 *
 * WP-Cron runs on page visits: if nobody visits the site at 10:00 the email
 * goes with the first visit after that (a real cron on wp-cron.php makes it exact).
 *
 * @since   1.74.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly approvals reminder.
 *
 * @since 1.74.0
 */
final class ANPA_Socios_Aprobacions_Semanal {

	const CRON_HOOK = 'anpa_socios_aprobacions_semanal';

	/**
	 * Schedules the next Monday 10:00 run (idempotent).
	 *
	 * @return void
	 */
	public static function programar(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( ANPA_Socios_Aviso_Semanal::proximo_luns( time(), wp_timezone() ), self::CRON_HOOK );
		}
	}

	/**
	 * @return void
	 */
	public static function desprogramar(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: email the junta when something waits, then schedule next week.
	 *
	 * @return void
	 */
	public static function executar(): void {
		try {
			$contas = ANPA_Socios_Admin_Approvals_Handler::contas_pendentes();
			if ( ANPA_Socios_Aviso_Semanal::debe_enviar( $contas ) ) {
				ANPA_Socios_Email::enviar_aprobacions_pendentes( $contas );
			}
		} catch ( \Throwable $e ) {
			// Best-effort: never break the cron run.
			unset( $e );
		}
		self::programar();
	}
}
