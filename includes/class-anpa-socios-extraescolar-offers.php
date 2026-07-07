<?php
/**
 * Waitlist-offer lifecycle service for extraescolar enrolments (fase7 PR-7f).
 *
 * When a group slot frees, the next waitlisted pupil is offered the place for
 * a bounded window; on no/late response the offer passes to the next in line.
 * The pure ordering lives in ANPA_Socios_Waitlist; this class is the WordPress
 * glue (DB + email + cron).
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers the next waitlisted pupil a freed slot and expires stale offers.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Extraescolar_Offers {

	/**
	 * Hourly cron hook that expires stale offers.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const CRON_HOOK = 'anpa_socios_extraescolar_offers';

	/**
	 * Offer time-to-live in days.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const OFFER_TTL_DAYS = 3;

	/**
	 * Schedules the hourly offer-expiry cron (idempotent).
	 *
	 * @since  1.9.0
	 * @return void
	 */
	public static function programar(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Clears the offer-expiry cron.
	 *
	 * @since  1.9.0
	 * @return void
	 */
	public static function desprogramar(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Offers the freed slot to the first waitlisted pupil of a group/trimester.
	 *
	 * No-op when the waitlist is empty. Sets estado=oferta + a single-use token
	 * + an expiry, and emails the pupil's socio.
	 *
	 * @since  1.9.0
	 * @param  int $grupo_id  Group id.
	 * @param  int $trimestre Trimester.
	 * @return void
	 */
	public static function offer_next( int $grupo_id, int $trimestre ): void {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, posicion FROM {$mat_t} WHERE grupo_id = %d AND trimestre = %d AND estado = 'lista_espera'",
				$grupo_id,
				$trimestre
			),
			ARRAY_A
		);
		$next = ANPA_Socios_Waitlist::first_offerable( is_array( $rows ) ? $rows : array() );
		if ( null === $next ) {
			return;
		}

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			$token = wp_generate_password( 32, false );
		}
		$expira = gmdate( 'Y-m-d H:i:s', time() + self::OFFER_TTL_DAYS * DAY_IN_SECONDS );

		$wpdb->update(
			$mat_t,
			array(
				'estado'         => 'oferta',
				'oferta_token'   => $token,
				'oferta_expira'  => $expira,
				'actualizado_en' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $next['id'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::notify_offer( (int) $next['id'] );
	}

	/**
	 * Declines an offer (or baixa of an offered matrícula) and advances the
	 * waitlist to the next pupil.
	 *
	 * @since  1.9.0
	 * @param  int $matricula_id Matrícula currently in 'oferta'.
	 * @return void
	 */
	public static function decline_and_advance( int $matricula_id ): void {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, grupo_id, trimestre FROM {$mat_t} WHERE id = %d",
				$matricula_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return;
		}

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$mat_t} SET estado = 'baixa', oferta_token = NULL, oferta_expira = NULL, actualizado_en = %s WHERE id = %d AND estado = 'oferta'",
				current_time( 'mysql' ),
				$matricula_id
			)
		);
		if ( (int) $affected > 0 ) {
			self::offer_next( (int) $row['grupo_id'], (int) $row['trimestre'] );
		}
	}

	/**
	 * Cron callback: expires offers past their deadline and advances each
	 * affected group's waitlist.
	 *
	 * @since  1.9.0
	 * @return void
	 */
	public static function expire_stale(): void {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$mat_t} WHERE estado = 'oferta' AND oferta_expira IS NOT NULL AND oferta_expira < %s",
				$now
			)
		);
		if ( ! is_array( $ids ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::decline_and_advance( (int) $id );
		}
	}

	/**
	 * Renumbers the remaining waitlist of a group/trimester to contiguous 1..N.
	 *
	 * @since  1.9.0
	 * @param  int $grupo_id  Group id.
	 * @param  int $trimestre Trimester.
	 * @return void
	 */
	public static function renumber_group( int $grupo_id, int $trimestre ): void {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$mat_t} WHERE grupo_id = %d AND trimestre = %d AND estado = 'lista_espera' ORDER BY posicion ASC, id ASC",
				$grupo_id,
				$trimestre
			)
		);
		$map = ANPA_Socios_Waitlist::renumber( is_array( $ids ) ? $ids : array() );
		foreach ( $map as $id => $pos ) {
			$wpdb->update(
				$mat_t,
				array( 'posicion' => (int) $pos ),
				array( 'id' => (int) $id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Emails the socio that a place opened up (offer pending in their area).
	 *
	 * Best-effort; never throws. Resolves the activity name and socio email.
	 *
	 * @since  1.9.0
	 * @param  int $matricula_id Matrícula in 'oferta'.
	 * @return void
	 */
	private static function notify_offer( int $matricula_id ): void {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$act_t = ANPA_Socios_DB::tabela_actividades();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.socio_email, a.nome AS actividade
				 FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 LEFT JOIN {$act_t} a ON a.id = m.activitad_id
				 WHERE m.id = %d",
				$matricula_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || empty( $row['socio_email'] ) ) {
			return;
		}

		ANPA_Socios_Email::enviar_oferta_extraescolar(
			(string) $row['socio_email'],
			(string) ( $row['actividade'] ?? '' ),
			self::OFFER_TTL_DAYS
		);
	}
}
