<?php
/**
 * 1.91.0: activity baixa reported by the company (WordPress glue of
 * ANPA_Socios_Baixa_Programada).
 *
 * The company emails the junta that a family asked IT for the baixa. In
 * Xestión the junta presses «Baixa comunicada pola empresa»: the enrolment
 * becomes «baixa_solicitada» (it keeps its place) with baixa_efectiva_en =
 * now + 24 h; the family (Bcc the junta) is told to say so if it is a mistake
 * and the company gets an acknowledgement. When the time is up the cron makes
 * it effective with the same code as «Confirmar baixa» (efectivar()): the place
 * is offered to the waiting list and the family, the company and the canteen
 * get the definitive notices. «Anular baixa» (family) and «Rexeitar» (junta)
 * cancel it and the company is told. Every step also goes to Telegram.
 *
 * @since   1.91.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled (company-reported) activity baixa.
 *
 * @since 1.91.0
 */
final class ANPA_Socios_Baixa_Programada_Service {

	/** Single event at the moment a reported baixa becomes effective (the hourly offers cron is the safety net). */
	const CRON_HOOK = 'anpa_socios_baixa_programada';

	/**
	 * The junta registers a baixa the company reported. Only an active enrolment.
	 *
	 * @param  int    $id         Matrícula id.
	 * @param  string $actor      Admin email (audit).
	 * @param  string $actor_tipo Admin role (audit).
	 * @return array{id:int,estado:string,baixa_efectiva_en:string,data_efectiva:string,correo_familia:bool,correo_empresa:bool}|WP_Error
	 */
	public static function programar( int $id, string $actor, string $actor_tipo ) {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only guard.
		$estado = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$mat_t} WHERE id = %d", $id ) );
		if ( null === $estado ) {
			return new WP_Error( 'anpa_admin_matricula_not_found', __( 'Matrícula non atopada', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( 'activo' !== (string) $estado ) {
			return new WP_Error( 'anpa_admin_matricula_estado', __( 'Só se pode rexistrar a baixa dunha matrícula activa.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$agora   = time();
		$efectiva = ANPA_Socios_Baixa_Programada::efectiva_en( $agora );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded state change.
		$n = $wpdb->query( $wpdb->prepare(
			"UPDATE {$mat_t} SET estado = 'baixa_solicitada', baixa_efectiva_en = %s, actualizado_en = %s WHERE id = %d AND estado = 'activo'",
			$efectiva,
			current_time( 'mysql' ),
			$id
		) );
		if ( 1 !== (int) $n ) {
			return new WP_Error( 'anpa_admin_matricula_estado', __( 'Só se pode rexistrar a baixa dunha matrícula activa.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( $actor, $actor_tipo, 'matricula', (string) $id, ANPA_Socios_Baixa_Programada::AUDIT_PROGRAMADA );

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $agora + ANPA_Socios_Baixa_Programada::PRAZO_HORAS * HOUR_IN_SECONDS + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $id ) );
		}

		$data    = ANPA_Socios_Baixa_Programada::texto_data( $efectiva, wp_timezone() );
		$familia = false;
		$detalle = ANPA_Socios_Admin_Matriculas_Handler::detalle_para_correo( $id );
		$row     = ANPA_Socios_Alumnos_Export::row_panel_matricula( $id );
		if ( is_array( $detalle ) ) {
			$familia = ANPA_Socios_Email::enviar_baixa_extraescolar_programada( $detalle['emails'], $detalle['alumno'], $detalle['actividade'], is_array( $row ) ? (string) $row['empresa_nome'] : '', $data );
		}
		$empresa = ANPA_Socios_Email::avisar_empresa( $id, 'matricula_baixa_programada_aviso', array( 'data_efectiva' => $data ) );
		ANPA_Socios_Email::telegram_baixa_programada( 'programada' );

		return array(
			'id'                => $id,
			'estado'            => 'baixa_solicitada',
			'baixa_efectiva_en' => $efectiva,
			'data_efectiva'     => $data,
			'correo_familia'    => $familia,
			'correo_empresa'    => is_array( $empresa ) && (int) ( $empresa['enviados'] ?? 0 ) > 0,
		);
	}

	/**
	 * Cron: every reported baixa whose time is up becomes effective.
	 *
	 * @return int How many.
	 */
	public static function executar_vencidas(): int {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only candidates.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$mat_t} WHERE estado = 'baixa_solicitada' AND baixa_efectiva_en IS NOT NULL AND baixa_efectiva_en <= %s ORDER BY baixa_efectiva_en ASC, id ASC",
			gmdate( 'Y-m-d H:i:s' )
		) );
		$feitas = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$r = self::efectivar( (int) $id, 'system', 'system', ANPA_Socios_Baixa_Programada::AUDIT_EFECTIVA );
			if ( ! is_wp_error( $r ) ) {
				++$feitas;
			}
		}
		return $feitas;
	}

	/**
	 * Makes a requested baixa effective — «Confirmar baixa» and the cron share it.
	 * Frees the place (offered to the next on the waiting list), tells the family
	 * when it takes effect and whether anything is charged, and notifies the
	 * company and the canteen. A reported (scheduled) baixa also writes to every
	 * parent with a copy to the junta, and to Telegram.
	 *
	 * @param  int    $id         Matrícula id in «baixa_solicitada».
	 * @param  string $actor      Audit actor.
	 * @param  string $actor_tipo Audit actor type.
	 * @param  string $accion     Audit action.
	 * @return array{id:int,estado:string,correo_enviado:bool,efectos:string,programada:bool,oferta:bool}|WP_Error
	 */
	public static function efectivar( int $id, string $actor, string $actor_tipo, string $accion ) {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only guard.
		$mat = $wpdb->get_row( $wpdb->prepare( "SELECT id, fillo_id, grupo_id, trimestre, estado, baixa_efectiva_en FROM {$mat_t} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $mat ) ) {
			return new WP_Error( 'anpa_admin_matricula_not_found', __( 'Matrícula non atopada', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( 'baixa_solicitada' !== $mat['estado'] ) {
			return new WP_Error( 'anpa_admin_no_baixa_request', __( 'Esta matrícula non ten unha solicitude de baixa pendente', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$programada = ANPA_Socios_Baixa_Programada::e_programada( (string) $mat['estado'], isset( $mat['baixa_efectiva_en'] ) ? (string) $mat['baixa_efectiva_en'] : null );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded state change.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$mat_t} SET estado = 'baixa', baixa_en = %s, baixa_efectiva_en = NULL, actualizado_en = %s WHERE id = %d AND estado = 'baixa_solicitada'",
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			$id
		) );
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit_actor( $actor, $actor_tipo, 'matricula', (string) $id, $accion );

		// Free slot → offer to the next in the waitlist (a reported baixa: copy to the junta).
		$oferta = ANPA_Socios_Extraescolar_Offers::offer_next( (int) $mat['grupo_id'], (int) $mat['trimestre'], 0, $programada );

		// 1.62.0: tell the family when the baixa takes effect (end of the running
		// trimester, or immediately and free of charge during pre-enrolment).
		$correo_enviado = false;
		$efectos        = '';
		$detalle        = ANPA_Socios_Admin_Baixas_Handler::detalle_matricula( $id );
		if ( is_array( $detalle ) ) {
			$curso = (string) ( $detalle['curso_escolar'] ?? '' );
			$row   = ANPA_Socios_Curso_Escolar::is_valid( $curso )
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only course calendar for the email.
				? $wpdb->get_row( $wpdb->prepare( 'SELECT ' . ANPA_Socios_Matricula_Gate_Repo::CURSO_COLUMNS . ' FROM ' . ANPA_Socios_DB::tabela_cursos() . ' WHERE curso_escolar = %s', $curso ), ARRAY_A )
				: null;
			$gate  = is_array( $row ) ? ANPA_Socios_Matricula_Gate_Repo::avaliar_fila( $row ) : ANPA_Socios_Matricula_Gate::avaliar( null, array() );
			$tris  = ANPA_Socios_Curso_Escolar::is_valid( $curso ) ? ANPA_Socios_Trimestre_Repo::for_curso( $curso ) : array();
			$datas = is_array( $row ) ? ANPA_Socios_Matricula_Gate::datas_de_fila( $row ) : null;
			$efectos = ANPA_Socios_Baixa_Extraescolar_Efectos::texto( $gate, $tris, $datas );
			$alumno  = trim( (string) $detalle['fillo_nome'] . ' ' . (string) $detalle['fillo_apelidos'] );
			$correo_enviado = $programada
				? ANPA_Socios_Email::enviar_baixa_programada_efectiva( ANPA_Socios_Admin_Matriculas_Handler::familia_emails_por_fillo( (int) $mat['fillo_id'] ), $alumno, (string) $detalle['actividade'], $efectos )
				: ANPA_Socios_Email::enviar_baixa_extraescolar_confirmada( (string) $detalle['socio_email'], $alumno, (string) $detalle['actividade'], $efectos );
		}
		// 1.71.0: mid-course baixa → company + canteen (Bcc).
		ANPA_Socios_Email::avisar_empresa_comedor( $id, ANPA_Socios_Aviso_Matricula::PLANTILLA_BAIXA, array( 'efectos' => $efectos ) );
		if ( $programada ) {
			ANPA_Socios_Email::telegram_baixa_programada( 'efectiva', $oferta );
		}

		return array( 'id' => $id, 'estado' => 'baixa', 'correo_enviado' => $correo_enviado, 'efectos' => $efectos, 'programada' => $programada, 'oferta' => $oferta );
	}

	/**
	 * A reported baixa was cancelled («Anular baixa» from the area, «Rexeitar» in
	 * Xestión): the caller already put the enrolment back to «activo». Tells the
	 * company (Para: xunta) and Telegram. No-op for a family request.
	 *
	 * @param  int    $id          Matrícula id.
	 * @param  bool   $era_programada Whether the row had baixa_efectiva_en before the change.
	 * @param  string $actor       Audit actor.
	 * @param  string $actor_tipo  Audit actor type.
	 * @return void
	 */
	public static function anulada( int $id, bool $era_programada, string $actor, string $actor_tipo ): void {
		if ( ! $era_programada ) {
			return;
		}
		ANPA_Socios_Admin_Shared::write_audit_actor( $actor, $actor_tipo, 'matricula', (string) $id, ANPA_Socios_Baixa_Programada::AUDIT_ANULADA );
		ANPA_Socios_Email::avisar_empresa( $id, 'matricula_baixa_anulada_aviso' );
		ANPA_Socios_Email::telegram_baixa_programada( 'anulada' );
	}

	/**
	 * Clears the scheduled moment (the enrolment left «baixa_solicitada», or a
	 * family request reused it). Separate query: a site where the 1.51.0
	 * migration has not run yet (it runs on admin_init) only logs a DB error,
	 * the family's action still goes through.
	 *
	 * @param  int $id Matrícula id.
	 * @return void
	 */
	public static function limpar( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded single-row update.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ANPA_Socios_DB::tabela_matriculas() . ' SET baixa_efectiva_en = NULL WHERE id = %d AND baixa_efectiva_en IS NOT NULL', $id ) );
	}

	/**
	 * Whether the enrolment is a reported baixa still pending (read before cancelling it).
	 *
	 * @param  int $id Matrícula id.
	 * @return bool
	 */
	public static function e_programada( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only lookup.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT estado, baixa_efectiva_en FROM ' . ANPA_Socios_DB::tabela_matriculas() . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) && ANPA_Socios_Baixa_Programada::e_programada( (string) $row['estado'], null === $row['baixa_efectiva_en'] ? null : (string) $row['baixa_efectiva_en'] );
	}
}
