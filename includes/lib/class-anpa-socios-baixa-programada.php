<?php
/**
 * 1.91.0: a baixa the company reported to the junta («a familia pediunos a
 * baixa»). The junta registers it in Xestión; the enrolment becomes
 * «baixa_solicitada» (it keeps its place) with the moment it becomes effective
 * on its own, PRAZO_HORAS later (matriculas.baixa_efectiva_en, UTC). Until then
 * the family can say it is a mistake (Anular baixa in its area, or the junta
 * rejects it); after that the cron makes it effective exactly like «Confirmar
 * baixa» and the place goes to the waiting list.
 *
 * Pure: dates in and out, no WordPress.
 *
 * @since   1.91.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules of the scheduled (company-reported) activity baixa.
 *
 * @since 1.91.0
 */
final class ANPA_Socios_Baixa_Programada {

	/** Hours the family has to say it is a mistake. */
	const PRAZO_HORAS = 24;

	/** Audit action when the junta registers it. */
	const AUDIT_PROGRAMADA = 'baixa_programada_empresa';

	/** Audit action when the cron makes it effective. */
	const AUDIT_EFECTIVA = 'baixa_programada_efectiva';

	/** Audit action when it is cancelled (family or junta). */
	const AUDIT_ANULADA = 'baixa_programada_anulada';

	/**
	 * Moment (UTC, MySQL format) the baixa becomes effective.
	 *
	 * @param  int $agora Unix time.
	 * @return string
	 */
	public static function efectiva_en( int $agora ): string {
		return gmdate( 'Y-m-d H:i:s', $agora + self::PRAZO_HORAS * 3600 );
	}

	/**
	 * Whether the row is a scheduled baixa (company-reported), not a family request.
	 *
	 * @param  string      $estado            Enrolment state.
	 * @param  string|null $baixa_efectiva_en matriculas.baixa_efectiva_en.
	 * @return bool
	 */
	public static function e_programada( string $estado, ?string $baixa_efectiva_en ): bool {
		return 'baixa_solicitada' === $estado && null !== $baixa_efectiva_en && '' !== trim( $baixa_efectiva_en );
	}

	/**
	 * Whether a scheduled baixa is due.
	 *
	 * @param  string|null $baixa_efectiva_en UTC moment.
	 * @param  int         $agora             Unix time.
	 * @return bool
	 */
	public static function vencida( ?string $baixa_efectiva_en, int $agora ): bool {
		if ( null === $baixa_efectiva_en || '' === trim( $baixa_efectiva_en ) ) {
			return false;
		}
		$ts = strtotime( trim( $baixa_efectiva_en ) . ' UTC' );
		return false !== $ts && $ts <= $agora;
	}

	/**
	 * «29/10/2026 ás 10:30» in the site's time zone, for the emails and Xestión.
	 *
	 * @param  string        $utc UTC moment (MySQL format).
	 * @param  \DateTimeZone $tz  Site time zone.
	 * @return string '' when the date is not valid.
	 */
	public static function texto_data( string $utc, \DateTimeZone $tz ): string {
		$d = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', trim( $utc ), new \DateTimeZone( 'UTC' ) );
		if ( false === $d ) {
			return '';
		}
		$d = $d->setTimezone( $tz );
		/* translators: 1: date dd/mm/yyyy, 2: time hh:mm */
		return sprintf( __( '%1$s ás %2$s', 'anpa-socios' ), $d->format( 'd/m/Y' ), $d->format( 'H:i' ) );
	}
}
