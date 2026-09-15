<?php
/**
 * Admin REST — pending withdrawal requests («Baixas solicitadas»).
 *
 * Families request two kinds of withdrawal from the area: leaving the
 * association (anpa_socios.baixa_estado = 'solicitada') and leaving an
 * activity (anpa_matriculas.estado = 'baixa_solicitada'). Until 1.57.0 the
 * junta only learnt about them by email and there was no place in Xestión to
 * see or resolve them; the confirm endpoints existed but had no button.
 *
 * This handler lists both queues and adds the "reject" side. Confirmation
 * keeps living in the socios/grupos handlers (they own the side effects:
 * protected root guard, freeing the seat for the waitlist).
 *
 * @since   1.57.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pending withdrawals: list + reject.
 *
 * @since 1.57.0
 */
final class ANPA_Socios_Admin_Baixas_Handler {

	/**
	 * Registers the routes.
	 *
	 * @since  1.57.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/baixas-pendentes', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_pendentes' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/socio/(?P<email>[^/]+)/baixa/reject', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reject_socio' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/matricula/(?P<id>\d+)/baixa/reject', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reject_matricula' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/baixas-pendentes — both queues in one call.
	 *
	 * Socios: every active member with a pending request, with the number of
	 * active children and current enrolments so the junta sees what the baixa
	 * drags along. Matrículas: every enrolment in estado 'baixa_solicitada',
	 * any school year (the row carries curso_escolar), oldest request first.
	 *
	 * The SQL is deliberately pure ASCII: a non-ASCII literal makes wpdb run its
	 * invalid-text parser, which misreads a FROM inside a function (see 1.56.3).
	 *
	 * @since  1.57.0
	 * @return WP_REST_Response
	 */
	public static function list_pendentes(): WP_REST_Response {
		global $wpdb;

		$soc_t = ANPA_Socios_DB::tabela_socios();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$act_t = ANPA_Socios_DB::tabela_actividades();
		$fc_t  = ANPA_Socios_DB::tabela_fillos_cursos();
		$curso = (string) ( ANPA_Socios_Curso_Activo::get() ?? '' );

		$socios = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.email, s.nome, s.apelidos, s.telefono, s.baixa_solicitada_en,
				        ( SELECT COUNT(*) FROM {$fil_t} f
				          WHERE f.familia_id = s.familia_id AND f.estado = 'activo' ) AS fillos_activos,
				        ( SELECT COUNT(*) FROM {$mat_t} m
				          INNER JOIN {$fil_t} f2 ON f2.id = m.fillo_id
				          INNER JOIN {$gru_t} g2 ON g2.id = m.grupo_id
				          WHERE f2.familia_id = s.familia_id AND g2.curso_escolar = %s
				            AND m.estado IN ('activo','lista_espera','oferta','baixa_solicitada') ) AS matriculas_vixentes
				 FROM {$soc_t} s
				 WHERE s.baixa_estado = 'solicitada' AND s.estado = 'activo' AND s.rol <> 'master'
				 ORDER BY s.baixa_solicitada_en ASC, s.email ASC",
				$curso
			),
			ARRAY_A
		);

		$matriculas = $wpdb->get_results(
			"SELECT m.id, m.trimestre, m.actualizado_en AS solicitada_en,
			        f.id AS fillo_id, f.nome AS fillo_nome, f.apelidos AS fillo_apelidos, f.socio_email,
			        COALESCE(fc.curso, f.curso) AS curso, COALESCE(fc.aula, f.aula) AS aula,
			        a.nome AS actividade, g.id AS grupo_id, g.nome AS grupo, g.franxa, g.dias, g.horario, g.curso_escolar
			 FROM {$mat_t} m
			 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
			 INNER JOIN {$act_t} a ON a.id = m.activitad_id
			 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
			 LEFT JOIN {$fc_t} fc ON fc.fillo_id = f.id AND fc.curso_escolar = g.curso_escolar
			 WHERE m.estado = 'baixa_solicitada'
			 ORDER BY m.actualizado_en ASC, m.id ASC",
			ARRAY_A
		);
		$matriculas = is_array( $matriculas ) ? $matriculas : array();
		foreach ( $matriculas as &$row ) {
			$row['curso_completo'] = ANPA_Socios_Admin_Shared::curso_completo( $row['curso'] ?? null, $row['aula'] ?? null );
		}
		unset( $row );

		return new WP_REST_Response(
			array(
				'curso_escolar' => $curso,
				'socios'        => is_array( $socios ) ? $socios : array(),
				'matriculas'    => $matriculas,
			),
			200
		);
	}

	/**
	 * POST /admin/socio/<email>/baixa/reject — the junta declines the request.
	 *
	 * Mirrors the family's own cancel (area): clears the flag, the member stays
	 * active. Refuses (409) when nothing is pending, so a stale screen never
	 * "rejects" something that was already resolved.
	 *
	 * @since  1.57.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reject_socio( WP_REST_Request $request ) {
		global $wpdb;

		$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Email inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$updated = $wpdb->update(
			ANPA_Socios_DB::tabela_socios(),
			array(
				'baixa_estado'   => 'none',
				'actualizado_en' => current_time( 'mysql' ),
			),
			array(
				'email'        => $email,
				'estado'       => 'activo',
				'baixa_estado' => 'solicitada',
			),
			array( '%s', '%s' ),
			array( '%s', '%s', '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( 0 === (int) $updated ) {
			return new WP_Error( 'anpa_admin_no_baixa_request', 'Este socio/a non ten unha solicitude de baixa pendente', array( 'status' => 409 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'socio', $email, 'baixa_reject' );

		// 1.62.0: the member is told the request was not accepted (and whom to contact).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only lookup for the email.
		$nome           = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT nome FROM ' . ANPA_Socios_DB::tabela_socios() . ' WHERE email = %s', $email ) );
		$correo_enviado = ANPA_Socios_Email::enviar_baixa_socio_rexeitada( $email, $nome );

		return new WP_REST_Response( array( 'email' => $email, 'baixa_estado' => 'none', 'correo_enviado' => $correo_enviado ), 200 );
	}

	/**
	 * POST /admin/matricula/<id>/baixa/reject — the enrolment goes back to activo.
	 *
	 * A 'baixa_solicitada' row still occupies its seat, so returning it to
	 * 'activo' never over-fills the group. Refuses (409) unless the request is
	 * still pending.
	 *
	 * @since  1.57.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reject_matricula( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$mat_t = ANPA_Socios_DB::tabela_matriculas();

		$updated = $wpdb->update(
			$mat_t,
			array( 'estado' => 'activo', 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => $id, 'estado' => 'baixa_solicitada' ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'anpa_admin_no_baixa_request', __( 'Esta matrícula non ten unha solicitude de baixa pendente', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'matricula', (string) $id, 'baixa_reject' );

		// 1.62.0: the family is told the request was not accepted (and whom to contact).
		$correo_enviado = false;
		$detalle        = self::detalle_matricula( $id );
		if ( is_array( $detalle ) ) {
			$correo_enviado = ANPA_Socios_Email::enviar_baixa_extraescolar_rexeitada(
				(string) $detalle['socio_email'],
				trim( (string) $detalle['fillo_nome'] . ' ' . (string) $detalle['fillo_apelidos'] ),
				(string) $detalle['actividade']
			);
		}

		return new WP_REST_Response( array( 'id' => $id, 'estado' => 'activo', 'correo_enviado' => $correo_enviado ), 200 );
	}

	/**
	 * Pupil, family email, activity and school year of an enrolment — what the
	 * confirmation/rejection emails need. Pure ASCII SQL (see 1.56.3).
	 *
	 * @since  1.62.0
	 * @param  int $id Matrícula id.
	 * @return array{fillo_nome:string,fillo_apelidos:string,socio_email:string,actividade:string,curso_escolar:string}|null
	 */
	public static function detalle_matricula( int $id ): ?array {
		global $wpdb;

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$act_t = ANPA_Socios_DB::tabela_actividades();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only lookup for the email.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.nome AS fillo_nome, f.apelidos AS fillo_apelidos, f.socio_email, a.nome AS actividade, COALESCE(g.curso_escolar, '') AS curso_escolar
				 FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 INNER JOIN {$act_t} a ON a.id = m.activitad_id
				 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
				 WHERE m.id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'fillo_nome'     => (string) $row['fillo_nome'],
			'fillo_apelidos' => (string) $row['fillo_apelidos'],
			'socio_email'    => (string) $row['socio_email'],
			'actividade'     => (string) $row['actividade'],
			'curso_escolar'  => (string) $row['curso_escolar'],
		);
	}
}
