<?php
/**
 * 1.93.0: automatic approval of extraescolar enrolments.
 *
 * With «As inscricións non precisan aprobación da xunta» ticked (Axustes → Xeral,
 * only in the «abertas todo o curso» mode), every request still starts pending;
 * once it has waited the configured hours, the hourly cron approves it with the
 * same code as the junta's button (ANPA_Socios_Admin_Matriculas_Handler::aprobar):
 * a place or the waiting list, the confirmation email to the family and, for a
 * group that is running, the notice to the company and the canteen. The junta
 * may approve or reject it earlier; a request that cannot be approved (e.g. the
 * group was disabled) stays pending for the junta.
 *
 * Only requests aprobar() can resolve are picked (a disabled group, or an accepted
 * offer in a group no longer open, is left to the junta), so a request that keeps
 * failing never blocks the ones behind it.
 *
 * The waiting time counts from matriculas.creado_en (reset when a baixa row is
 * reused for a new request). An accepted waiting-list offer is approved at once
 * (ANPA_Socios_Extraescolares_REST::accept_offer); the cron is its safety net.
 *
 * @package ANPA_Socios
 * @since   1.93.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Aprobacion_Automatica {

	/** Audit action of an automatic approval. */
	const AUDIT = 'aprobada_automatica';

	/** Requests per cron run (the rest go in the next hour). */
	const LOTE = 50;

	/**
	 * Cron: approves the pending requests whose waiting time is over.
	 *
	 * @return int How many were approved.
	 */
	public static function executar(): int {
		if ( ! ANPA_Socios_Config::matricula_aprobacion_automatica() ) {
			return 0;
		}
		$curso = ANPA_Socios_Curso_Activo::get();
		if ( null === $curso ) {
			return 0;
		}
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only candidates.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT m.id FROM {$mat_t} m INNER JOIN {$gru_t} g ON g.id = m.grupo_id
			 WHERE g.curso_escolar = %s AND m.estado = 'pendente_aprobacion'
			   AND ( m.oferta_aceptada_en IS NOT NULL OR m.creado_en <= NOW() - INTERVAL %d HOUR )
			   AND ( g.estado IN ('aberto','sen_minimo') OR ( g.estado = 'pechado' AND m.oferta_aceptada_en IS NULL ) )
			 ORDER BY m.creado_en ASC, m.id ASC LIMIT %d",
			$curso,
			ANPA_Socios_Config::matricula_aprobacion_horas(),
			self::LOTE
		) );
		$feitas = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$r = ANPA_Socios_Admin_Matriculas_Handler::aprobar( (int) $id, 'system', 'system', true );
			if ( ! is_wp_error( $r ) ) {
				ANPA_Socios_Admin_Shared::write_audit_actor( 'system', 'system', 'matricula', (string) $id, self::AUDIT );
				++$feitas;
			}
		}
		return $feitas;
	}
}
