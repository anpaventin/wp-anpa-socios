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
	 * @param  string $efecto     1.93.1: EFECTO_XA (effective once confirmed) or EFECTO_FIN (at the end of the trimester).
	 * @return array{id:int,estado:string,baixa_efectiva_en:string,data_efectiva:string,correo_familia:bool,correo_empresa:bool}|WP_Error
	 */
	public static function programar( int $id, string $actor, string $actor_tipo, string $efecto = '' ) {
		global $wpdb;
		if ( ! in_array( $efecto, array( ANPA_Socios_Baixa_Programada::EFECTO_XA, ANPA_Socios_Baixa_Programada::EFECTO_FIN ), true ) ) {
			return new WP_Error( 'anpa_admin_baixa_efecto', __( 'Indica se a baixa é efectiva xa ou ao remate do trimestre.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
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
		self::gardar_efecto( $id, $efecto );

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $agora + ANPA_Socios_Baixa_Programada::PRAZO_HORAS * HOUR_IN_SECONDS + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $id ) );
		}

		$data    = ANPA_Socios_Baixa_Programada::texto_data( $efectiva, wp_timezone() );
		// 1.93.1: when it takes effect once confirmed (now, or the last day of the trimester).
		$fin     = ANPA_Socios_Baixa_Programada::EFECTO_FIN === $efecto ? self::avaliacion( $id, $efecto ) : null;
		$data_fin = is_array( $fin ) && ANPA_Socios_Baixa_Extraescolar_Efectos::FIN_TRIMESTRE === $fin['efecto'] ? self::dmy( $fin['data_fin'] ) : '';
		$familia = false;
		$detalle = ANPA_Socios_Admin_Matriculas_Handler::detalle_para_correo( $id );
		$row     = ANPA_Socios_Alumnos_Export::row_panel_matricula( $id );
		if ( is_array( $detalle ) ) {
			$familia = ANPA_Socios_Email::enviar_baixa_extraescolar_programada( $detalle['emails'], $detalle['alumno'], $detalle['actividade'], is_array( $row ) ? (string) $row['empresa_nome'] : '', $data,
				'' !== $data_fin
					/* translators: %s: last day of the trimester */
					? sprintf( __( 'A baixa terá efecto ao remate do trimestre, o %s: ata entón segue na actividade.', 'anpa-socios' ), $data_fin )
					: __( 'Desde ese momento a baixa é efectiva.', 'anpa-socios' ) );
		}
		$empresa = ANPA_Socios_Email::avisar_empresa( $id, 'matricula_baixa_programada_aviso', array(
			'data_efectiva' => $data,
			'efecto_texto'  => '' !== $data_fin
				/* translators: %s: last day of the trimester */
				? sprintf( __( 'Se non, a baixa confírmase nese momento e ten efecto ao remate do trimestre, o %s; recibiredes o aviso definitivo.', 'anpa-socios' ), $data_fin )
				: __( 'Se non, a baixa será efectiva nese momento e recibiredes o aviso definitivo.', 'anpa-socios' ),
		) );
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

		// 1.62.0: when the baixa takes effect (end of the running trimester, or immediately and
		// free of charge). 1.93.0: decided before the update, from the window AND the group.
		$correo_enviado = false;
		$detalle        = ANPA_Socios_Admin_Baixas_Handler::detalle_matricula( $id );
		// 1.93.1: a reported baixa follows what the junta chose (now / end of the trimester).
		$avaliacion     = self::avaliacion( $id, $programada ? self::efecto( $id ) : '' );
		$efectos        = is_array( $avaliacion ) ? $avaliacion['texto'] : '';
		// 1.93.0: a baixa effective at the end of the trimester keeps its place until then: the row is
		// dated that day (baixa_en in the future counts as taken, ANPA_Socios_Lista_Espera::SQL_OCUPAN)
		// and the hourly cron offers the place once the date has passed.
		$baixa_en = current_time( 'mysql' );
		$ao_final = is_array( $avaliacion ) && ANPA_Socios_Baixa_Extraescolar_Efectos::FIN_TRIMESTRE === $avaliacion['efecto']
			&& '' !== $avaliacion['data_fin'] && $avaliacion['data_fin'] > substr( $baixa_en, 0, 10 );
		if ( $ao_final ) {
			$baixa_en = $avaliacion['data_fin'] . ' 23:59:59';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- guarded state change.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$mat_t} SET estado = 'baixa', baixa_en = %s, baixa_efectiva_en = NULL, oferta_aceptada_en = NULL, actualizado_en = %s WHERE id = %d AND estado = 'baixa_solicitada'",
			$baixa_en,
			current_time( 'mysql' ),
			$id
		) );
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit_actor( $actor, $actor_tipo, 'matricula', (string) $id, $accion );

		self::borrar_efecto( $id );

		// Free slot → offer to the next in the waitlist (a reported baixa: copy to the junta).
		// 1.93.1: a baixa at the end of the trimester offers the place now for the start of the next
		// trimester; in the last trimester the place is not offered.
		if ( $ao_final ) {
			$tri    = (int) $avaliacion['trimestre'];
			$oferta = $tri > 0 && $tri < 3 && ANPA_Socios_Extraescolar_Offers::offer_next( (int) $mat['grupo_id'], (int) $mat['trimestre'], 0, $programada, $tri + 1 );
		} else {
			$oferta = ANPA_Socios_Extraescolar_Offers::offer_next( (int) $mat['grupo_id'], (int) $mat['trimestre'], 0, $programada );
		}

		if ( is_array( $detalle ) ) {
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
		self::borrar_efecto( $id );
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
		self::borrar_efecto( $id );
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

	/**
	 * 1.93.1: when a baixa takes effect and what the family is told. The window and the group
	 * decide (ANPA_Socios_Baixa_Extraescolar_Efectos); for a reported baixa the junta's choice:
	 * EFECTO_XA = effective at once; EFECTO_FIN = at the end of the trimester when the group is
	 * running (a group not running is always immediate and free).
	 *
	 * @param  int    $id     Matrícula id.
	 * @param  string $efecto '' (family baixa) | EFECTO_XA | EFECTO_FIN.
	 * @return array{efecto:string,trimestre:int,data_fin:string,texto:string}|null
	 */
	public static function avaliacion( int $id, string $efecto ): ?array {
		global $wpdb;
		$detalle = ANPA_Socios_Admin_Baixas_Handler::detalle_matricula( $id );
		if ( ! is_array( $detalle ) ) {
			return null;
		}
		$grupo_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT grupo_id FROM ' . ANPA_Socios_DB::tabela_matriculas() . ' WHERE id = %d', $id ) );
		$curso    = (string) ( $detalle['curso_escolar'] ?? '' );
		$row      = ANPA_Socios_Curso_Escolar::is_valid( $curso )
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only course calendar for the email.
			? $wpdb->get_row( $wpdb->prepare( 'SELECT ' . ANPA_Socios_Matricula_Gate_Repo::CURSO_COLUMNS . ' FROM ' . ANPA_Socios_DB::tabela_cursos() . ' WHERE curso_escolar = %s', $curso ), ARRAY_A )
			: null;
		$gate  = is_array( $row ) ? ANPA_Socios_Matricula_Gate_Repo::avaliar_fila( $row ) : ANPA_Socios_Matricula_Gate::avaliar( null, array() );
		$tris  = ANPA_Socios_Curso_Escolar::is_valid( $curso ) ? ANPA_Socios_Trimestre_Repo::for_curso( $curso ) : array();
		$datas = is_array( $row ) ? ANPA_Socios_Matricula_Gate::datas_de_fila( $row ) : null;
		$clave = ANPA_Socios_Email::contexto_grupo( $grupo_id )['clave'];
		$av    = ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( $gate, $tris, $datas, $clave );
		if ( ANPA_Socios_Baixa_Programada::EFECTO_XA === $efecto && ANPA_Socios_Baixa_Extraescolar_Efectos::INMEDIATA !== $av['efecto'] ) {
			return array(
				'efecto'    => ANPA_Socios_Baixa_Extraescolar_Efectos::INMEDIATA,
				'trimestre' => $av['trimestre'],
				'data_fin'  => '',
				'texto'     => __( 'Tal como comunicou a empresa, a baixa é efectiva desde hoxe.', 'anpa-socios' ),
			);
		}
		if ( ANPA_Socios_Baixa_Programada::EFECTO_FIN === $efecto && ANPA_Socios_Baixa_Extraescolar_Efectos::INMEDIATA === $av['efecto'] && ANPA_Socios_Estado_Grupo_Texto::EN_MARCHA === $clave ) {
			// The window is open but the group runs: the junta said end of the trimester.
			$closed = $tris;
			foreach ( $closed as $k => $t ) {
				$closed[ $k ]['ventana_estado'] = ANPA_Socios_Ventana_Estado::PECHADA;
				$closed[ $k ]['presente']       = true;
			}
			return ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( array( 'trimestre' => $av['trimestre'], 'abertas' => false ), $closed, $datas, $clave );
		}
		return $av;
	}

	/**
	 * @param  int $id Matrícula id.
	 * @return string The junta's choice for a reported baixa (EFECTO_XA when none was stored, as before 1.93.1).
	 */
	public static function efecto( int $id ): string {
		$all = get_option( ANPA_Socios_Baixa_Programada::OPTION_EFECTO, array() );
		$v   = is_array( $all ) ? (string) ( $all[ $id ] ?? '' ) : '';
		return ANPA_Socios_Baixa_Programada::EFECTO_FIN === $v ? $v : ANPA_Socios_Baixa_Programada::EFECTO_XA;
	}

	private static function gardar_efecto( int $id, string $efecto ): void {
		$all        = get_option( ANPA_Socios_Baixa_Programada::OPTION_EFECTO, array() );
		$all        = is_array( $all ) ? $all : array();
		$all[ $id ] = $efecto;
		update_option( ANPA_Socios_Baixa_Programada::OPTION_EFECTO, $all, false );
	}

	private static function borrar_efecto( int $id ): void {
		$all = get_option( ANPA_Socios_Baixa_Programada::OPTION_EFECTO, array() );
		if ( is_array( $all ) && isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( ANPA_Socios_Baixa_Programada::OPTION_EFECTO, $all, false );
		}
	}

	/**
	 * @param  string $ymd Y-m-d.
	 * @return string DD/MM/AAAA ('' when not a date).
	 */
	private static function dmy( string $ymd ): string {
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
	}
}
