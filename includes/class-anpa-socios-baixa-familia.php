<?php
/**
 * Family-wide member baixa (1.80.0), shared by the three ways it happens:
 * confirming a requested baixa (Operacións → Aprobacións), «Dar de baixa» from
 * the member's edit panel, and the end-of-course close (pending requests are
 * confirmed in cascade).
 *
 * Effect: both parents (every active member of the unit) → estado «baixa», the
 * children → estado «baixa», and each parent gets the «baixa efectiva» email.
 * Current enrolments block a manual baixa (the rule: no baixa while a child is
 * enrolled); the course close gives them baixa first (cascade).
 *
 * @since   1.80.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Family baixa service.
 *
 * @since 1.80.0
 */
final class ANPA_Socios_Baixa_Familia {

	/** WP_Error code when the family still has current enrolments. */
	const ERRO_MATRICULAS = 'anpa_baixa_matriculas_vixentes';

	/**
	 * Members, children and current enrolments of the family of $email.
	 *
	 * @param  string $email Member email.
	 * @return array{familia_id:int,membros:array<int,array<string,string>>,emails:array<int,string>,fillos:array<int,int>,matriculas:array<int,array<string,string>>}|null
	 */
	public static function contexto( string $email ): ?array {
		global $wpdb;
		$soc_t = ANPA_Socios_DB::tabela_socios();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$act_t = ANPA_Socios_DB::tabela_actividades();
		$email = strtolower( trim( $email ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- family lookup.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, familia_id FROM {$soc_t} WHERE email = %s", $email ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$familia_id = ANPA_Socios_Familia::resolve_familia_id( isset( $row['familia_id'] ) ? (int) $row['familia_id'] : null, (int) $row['id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- family lookup.
		$membros = $wpdb->get_results( $wpdb->prepare(
			"SELECT email, nome FROM {$soc_t} WHERE estado = 'activo' AND rol <> 'master' AND ( id = %d OR familia_id = %d ) ORDER BY id ASC",
			$familia_id,
			$familia_id
		), ARRAY_A );
		$membros = is_array( $membros ) ? $membros : array();
		if ( array() === $membros ) {
			$membros = array( array( 'email' => $email, 'nome' => '' ) );
		}
		$emails = array_values( array_unique( array_map( static function ( $m ) { return strtolower( (string) $m['email'] ); }, $membros ) ) );

		$ph = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$fillos = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$fil_t} WHERE estado = 'activo' AND ( familia_id = %d OR LOWER(socio_email) IN ({$ph}) )",
			array_merge( array( $familia_id ), $emails )
		) );
		$fillos = array_values( array_map( 'intval', is_array( $fillos ) ? $fillos : array() ) );

		$matriculas = array();
		if ( array() !== $fillos ) {
			$fph = implode( ',', array_fill( 0, count( $fillos ), '%d' ) );
			$in  = ANPA_Socios_Matricula_Estado::sql_in( ANPA_Socios_Matricula_Estado::VIXENTES );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT m.id, f.nome, f.apelidos, COALESCE(a.nome, '') AS actividade
				 FROM {$mat_t} m INNER JOIN {$fil_t} f ON f.id = m.fillo_id LEFT JOIN {$act_t} a ON a.id = m.activitad_id
				 WHERE m.fillo_id IN ({$fph}) AND m.estado IN ({$in})",
				$fillos
			), ARRAY_A );
			$matriculas = is_array( $rows ) ? $rows : array();
		}

		return array(
			'familia_id' => $familia_id,
			'membros'    => $membros,
			'emails'     => $emails,
			'fillos'     => $fillos,
			'matriculas' => $matriculas,
		);
	}

	/**
	 * Gives baixa to the whole family of $email.
	 *
	 * @param  WP_REST_Request|null $request            Request (audit actor); null = system.
	 * @param  string               $email              Member who triggers it (requester / edited member).
	 * @param  string               $accion             Audit action for $email (the others: baixa_confirm_familia).
	 * @param  bool                 $cascada_matriculas True = give current enrolments baixa first (course close);
	 *                                                  false = refuse while any exists.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executar( $request, string $email, string $accion, bool $cascada_matriculas ) {
		global $wpdb;
		$ctx = self::contexto( $email );
		if ( null === $ctx ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Socio/a non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( ! $cascada_matriculas && array() !== $ctx['matriculas'] ) {
			$lista = array();
			foreach ( array_slice( $ctx['matriculas'], 0, 5 ) as $m ) {
				$lista[] = trim( $m['nome'] . ' ' . $m['apelidos'] ) . ( '' !== $m['actividade'] ? ' (' . $m['actividade'] . ')' : '' );
			}
			return new WP_Error(
				self::ERRO_MATRICULAS,
				sprintf(
					/* translators: 1: number of enrolments, 2: pupils and activities. */
					__( 'Non se pode dar de baixa: a familia ten %1$d matrícula(s) vixente(s) en extraescolares (%2$s). Dálles de baixa primeiro en Extraescolares → Matrículas.', 'anpa-socios' ),
					count( $ctx['matriculas'] ),
					implode( ', ', $lista )
				),
				array( 'status' => 409, 'matriculas' => count( $ctx['matriculas'] ) )
			);
		}

		$soc_t = ANPA_Socios_DB::tabela_socios();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$now   = current_time( 'mysql' );
		$fam   = (int) $ctx['familia_id'];

		$matriculas_baixa = 0;
		if ( array() !== $ctx['matriculas'] ) {
			$ids = array_map( static function ( $m ) { return (int) $m['id']; }, $ctx['matriculas'] );
			$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
			$matriculas_baixa = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$mat_t} SET estado = 'baixa', baixa_en = %s, oferta_token = NULL, oferta_expira = NULL, actualizado_en = %s WHERE id IN ({$ph})",
				array_merge( array( $now, $now ), $ids )
			) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the baixa itself.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$soc_t} SET estado = 'baixa', baixa_estado = 'none', actualizado_en = %s WHERE estado = 'activo' AND rol <> 'master' AND ( id = %d OR familia_id = %d )",
			$now,
			$fam,
			$fam
		) );
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// 1.83.0: when the baixa became effective (data retention counts from here).
		$eph = implode( ',', array_fill( 0, count( $ctx['emails'] ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$soc_t} SET baixa_en = %s WHERE estado = 'baixa' AND rol <> 'master' AND LOWER(email) IN ({$eph})", array_merge( array( $now ), $ctx['emails'] ) ) );

		$fillos_baixa = 0;
		if ( array() !== $ctx['fillos'] ) {
			$ph = implode( ',', array_fill( 0, count( $ctx['fillos'] ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
			$fillos_baixa = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$fil_t} SET estado = 'baixa', actualizado_en = %s WHERE id IN ({$ph})",
				array_merge( array( $now ), $ctx['fillos'] )
			) );
		}

		$email = strtolower( trim( $email ) );
		foreach ( $ctx['emails'] as $e ) {
			self::audit( $request, 'socio', $e, $e === $email ? $accion : 'baixa_confirm_familia' );
		}
		foreach ( $ctx['fillos'] as $id ) {
			self::audit( $request, 'fillo', (string) $id, 'baixa_familia' );
		}
		foreach ( $ctx['matriculas'] as $m ) {
			self::audit( $request, 'matricula', (string) $m['id'], 'baixa_familia' );
		}

		$lista    = implode( ', ', $ctx['emails'] );
		$enviados = 0;
		foreach ( $ctx['membros'] as $m ) {
			if ( ANPA_Socios_Email::enviar_baixa_socio_confirmada( (string) $m['email'], (string) $m['nome'], $lista ) ) {
				++$enviados;
			}
		}

		return array(
			'emails_baixa'     => $ctx['emails'],
			'correos_enviados' => $enviados,
			'correo_enviado'   => $enviados === count( $ctx['membros'] ),
			'fillos_baixa'     => $fillos_baixa,
			'matriculas_baixa' => $matriculas_baixa,
		);
	}

	/**
	 * End of course: every pending member baixa is confirmed in cascade
	 * (enrolments, children, both parents, emails). Lista Gmail picks them up
	 * as baixas on its next comparison.
	 *
	 * @param  WP_REST_Request|null $request Request (audit actor).
	 * @return array{familias:int,erros:int}
	 */
	public static function confirmar_pendentes_fin_curso( $request ): array {
		global $wpdb;
		$soc_t = ANPA_Socios_DB::tabela_socios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only list.
		$emails = $wpdb->get_col( "SELECT email FROM {$soc_t} WHERE baixa_estado = 'solicitada' AND estado = 'activo' AND rol <> 'master' ORDER BY id ASC" );
		$out    = array( 'familias' => 0, 'erros' => 0 );
		foreach ( is_array( $emails ) ? $emails : array() as $email ) {
			// A parent of a family already processed in this loop is no longer active.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- per-row recheck.
			$activo = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$soc_t} WHERE email = %s", (string) $email ) );
			if ( 'activo' !== $activo ) {
				continue;
			}
			$r = self::executar( $request, (string) $email, 'baixa_fin_curso', true );
			if ( is_wp_error( $r ) ) {
				++$out['erros'];
			} else {
				++$out['familias'];
			}
		}
		return $out;
	}

	/**
	 * @param WP_REST_Request|null $request Request or null.
	 */
	private static function audit( $request, string $tipo, string $id, string $accion ): void {
		if ( $request instanceof WP_REST_Request ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, $tipo, $id, $accion );
		} else {
			ANPA_Socios_Admin_Shared::write_audit_actor( 'system', 'system', $tipo, $id, $accion );
		}
	}
}
