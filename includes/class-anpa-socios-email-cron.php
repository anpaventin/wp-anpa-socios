<?php
/**
 * Email queue cron wiring (fase35).
 *
 * Registers a custom recurrence (default 5 min, filterable within safe bounds),
 * schedules the recurring tick idempotently, recovers it if it disappears, and
 * unschedules it on deactivation WITHOUT deleting any data. The tick itself is a
 * guarded no-op until the queue processor lands in a later PR — it never runs
 * during a migration/install and never sends email by itself here.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Cron {

	/** Recurrence name registered in cron_schedules. */
	const RECURRENCE = 'anpa_socios_email_5min';

	/** Recurring event hook. */
	const HOOK = 'anpa_socios_email_queue_tick';

	/** Default interval seconds. */
	const DEFAULT_INTERVAL = 300;

	/** Safe bounds for the interval (no dangerously short intervals). */
	const MIN_INTERVAL = 60;
	const MAX_INTERVAL = 3600;

	/**
	 * Resolves the tick interval in seconds. Adjustable via the
	 * `anpa_socios_email_cron_interval` filter, clamped to [MIN, MAX]. Not
	 * configurable from the UI (technical setting only).
	 *
	 * @return int
	 */
	public static function interval_seconds(): int {
		$seconds = (int) apply_filters( 'anpa_socios_email_cron_interval', self::DEFAULT_INTERVAL );
		if ( $seconds < self::MIN_INTERVAL ) {
			return self::MIN_INTERVAL;
		}
		if ( $seconds > self::MAX_INTERVAL ) {
			return self::MAX_INTERVAL;
		}
		return $seconds;
	}

	/**
	 * Registers the custom recurrence. Hook to `cron_schedules`.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules[ self::RECURRENCE ] = array(
			'interval' => self::interval_seconds(),
			'display'  => __( 'Cada 5 minutos (cola de correo ANPA)', 'anpa-socios' ),
		);
		return $schedules;
	}

	/**
	 * Schedules the recurring tick if it is not already scheduled. Idempotent.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::RECURRENCE, self::HOOK );
		}
	}

	/**
	 * Unschedules the recurring tick. Used on deactivation. Does NOT delete any
	 * data — only removes the scheduled event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Recovers the schedule if the event has disappeared (e.g. cron array reset).
	 * Safe to call on every admin_init.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		self::schedule();
	}

	/**
	 * Reports the health of the scheduled tick so the admin screen can warn when
	 * the queue is silently not advancing.
	 *
	 * Three independent problems are distinguished, because the fix differs:
	 *   - `disabled`: DISABLE_WP_CRON is set, so WordPress will never fire the
	 *     event by itself. A real server cron must call wp-cron.php.
	 *   - `unscheduled`: the event is missing from the cron array.
	 *   - `stalled`: it is scheduled but no run has been recorded for far longer
	 *     than the interval (typically a site with no traffic, since WP-Cron only
	 *     fires on visits).
	 *
	 * @since  1.39.0
	 * @return array{ok:bool,problems:string[],last_run_utc:string,next_run_utc:string}
	 */
	public static function health(): array {
		$problems = array();

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$problems[] = 'disabled';
		}

		$next = wp_next_scheduled( self::HOOK );
		if ( false === $next ) {
			$problems[] = 'unscheduled';
		}

		$last     = (string) get_option( ANPA_Socios_Email_Processor::LAST_RUN_OPTION, '' );
		$tolerance = max( 3 * self::interval_seconds(), 15 * MINUTE_IN_SECONDS );
		if ( '' !== $last ) {
			$age = time() - (int) strtotime( $last . ' UTC' );
			if ( $age > $tolerance ) {
				$problems[] = 'stalled';
			}
		}

		return array(
			'ok'           => array() === $problems,
			'problems'     => $problems,
			'last_run_utc' => $last,
			'next_run_utc' => false === $next ? '' : gmdate( 'Y-m-d H:i:s', (int) $next ),
		);
	}

	/**
	 * Cron callback. Guarded no-op until the queue processor exists. Never runs
	 * during install/upgrade; never sends email by itself in this PR.
	 *
	 * @return void
	 */
	public static function tick(): void {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return; // Never process during install/upgrade.
		}
		// The processor is introduced in a later PR; until then this is a no-op.
		if ( class_exists( 'ANPA_Socios_Email_Queue' ) && method_exists( 'ANPA_Socios_Email_Queue', 'process_due_batch' ) ) {
			ANPA_Socios_Email_Queue::process_due_batch();
		}
	}
}
