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
	public static function offer_next( int $grupo_id, int $trimestre, int $excluir = 0 ): void {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// 1.85.0: only a group that runs or is waiting for its minimum, and only with a free place
		// (an outstanding or accepted offer keeps its place). $excluir = the family that just let
		// its offer go: it is never offered the same place again straight away.
		$grupo = $wpdb->get_row( $wpdb->prepare( 'SELECT estado, max_pupilos FROM ' . ANPA_Socios_DB::tabela_grupos() . ' WHERE id = %d', $grupo_id ), ARRAY_A );
		if ( ! is_array( $grupo ) || ! in_array( (string) $grupo['estado'], array( ANPA_Socios_Grupo_Serie::ESTADO_ABERTO, ANPA_Socios_Grupo_Serie::ESTADO_SEN_MINIMO ), true ) ) {
			return;
		}
		if ( (int) $grupo['max_pupilos'] > 0 && ANPA_Socios_Lista_Espera::ocupadas( $grupo_id ) >= (int) $grupo['max_pupilos'] ) {
			return;
		}
		// The group's whole waiting list (a place freed by a row of another trimester is still
		// this group's place), earliest trimester first, then position. A family that let an
		// offer go is skipped for DESCANSO_DIAS (oferta_expira keeps when its offer ended).
		$descanso = gmdate( 'Y-m-d H:i:s', time() - ANPA_Socios_Lista_Espera::DESCANSO_DIAS * DAY_IN_SECONDS );
		$next     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, posicion, trimestre FROM {$mat_t} WHERE grupo_id = %d AND estado = 'lista_espera' AND id <> %d AND posicion IS NOT NULL
				 AND ( oferta_expira IS NULL OR oferta_expira < %s )
				 ORDER BY trimestre ASC, posicion ASC, id ASC LIMIT 1",
				$grupo_id,
				$excluir,
				$descanso
			),
			ARRAY_A
		);
		if ( ! is_array( $next ) ) {
			return;
		}
		$trimestre = (int) $next['trimestre'];

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			$token = wp_generate_password( 32, false );
		}
		$expira = gmdate( 'Y-m-d H:i:s', time() + self::OFFER_TTL_DAYS * DAY_IN_SECONDS );

		$n = $wpdb->update(
			$mat_t,
			array(
				'estado'         => 'oferta',
				'oferta_token'   => $token,
				'oferta_expira'  => $expira,
				'actualizado_en' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $next['id'], 'estado' => 'lista_espera' ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $n ) {
			return; // it changed meanwhile (baixa, another offer…).
		}

		ANPA_Socios_Lista_Espera::renumerar( $grupo_id, $trimestre );
		ANPA_Socios_Admin_Shared::write_audit_actor( 'system', 'system', 'matricula', (string) $next['id'], 'oferta_enviada' );
		self::notify_offer( (int) $next['id'] );
	}

	/**
	 * An offer the family let expire or turned down (1.85.0): it goes back to
	 * the END of the group's waiting list (it does not leave it), the family is
	 * told, and the place is offered to the next one.
	 *
	 * @since  1.9.0
	 * @param  int    $matricula_id Matrícula currently in 'oferta'.
	 * @param  string $motivo       'caducada' (no reply in time) | 'rexeitada' (turned down).
	 * @param  string $actor        Audit actor email.
	 * @param  string $actor_tipo   Audit actor type.
	 * @return bool False when it was no longer an offer.
	 */
	public static function decline_and_advance( int $matricula_id, string $motivo = 'caducada', string $actor = 'system', string $actor_tipo = 'system' ): bool {
		$r = ANPA_Socios_Lista_Espera::ao_final( $matricula_id );
		if ( null === $r ) {
			return false;
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( $actor, $actor_tipo, 'matricula', (string) $matricula_id, 'rexeitada' === $motivo ? 'oferta_rexeitada' : 'oferta_caducada' );
		self::offer_next( $r['grupo_id'], $r['trimestre'], $matricula_id );
		// After the next offer, so the email gives the family's final position.
		self::notify_final_lista( $matricula_id, $motivo );
		return true;
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
		self::encher_prazas_libres();
	}

	/**
	 * Hourly too (1.85.0): a group with a free place and somebody waiting (out of
	 * their cooling-off) but no offer outstanding gets its next offer — e.g. the only
	 * family on the list let an offer go: it is offered again after DESCANSO_DIAS.
	 *
	 * @return void
	 */
	public static function encher_prazas_libres(): void {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only candidate groups.
		$grupos = $wpdb->get_col(
			"SELECT DISTINCT g.id FROM {$gru_t} g INNER JOIN {$mat_t} m ON m.grupo_id = g.id AND m.estado = 'lista_espera'
			 WHERE g.estado IN ('aberto','sen_minimo')
			   AND NOT EXISTS (SELECT 1 FROM {$mat_t} o WHERE o.grupo_id = g.id AND o.estado = 'oferta')"
		);
		foreach ( is_array( $grupos ) ? $grupos : array() as $gid ) {
			self::offer_next( (int) $gid, 0 );
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
	/**
	 * Tells the family it went back to the end of the waiting list (1.85.0).
	 *
	 * @param  int    $matricula_id Matrícula.
	 * @param  string $motivo       caducada | rexeitada.
	 * @return void
	 */
	private static function notify_final_lista( int $matricula_id, string $motivo ): void {
		$detalle = ANPA_Socios_Admin_Matriculas_Handler::detalle_para_correo( $matricula_id );
		if ( ! is_array( $detalle ) ) {
			return;
		}
		global $wpdb;
		$pos = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT posicion FROM ' . ANPA_Socios_DB::tabela_matriculas() . ' WHERE id = %d', $matricula_id ) );
		foreach ( $detalle['emails'] as $email ) {
			ANPA_Socios_Email::enviar_oferta_final_lista( $email, $detalle['alumno'], $detalle['actividade'], $detalle['grupo'], $pos, 'rexeitada' === $motivo );
		}
	}

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
