<?php
/**
 * Admin REST — course cycle from Xestión → Extraescolares → Matrículas (1.68.0).
 *
 * Until 1.67.0 the trimester states and the enrolment windows were switched
 * from Axustes → Cursos with six admin-post buttons, and every notice to the
 * families went out by hand from Gmail. This handler moves the switch to
 * Xestión as two combos («Trimestre activo», «Matrículas abertas para») and
 * adds the course-cycle notices, all sent the same way: the junta's address in
 * To and the families in Bcc, in batches (ANPA_Socios_Email::enviar_masivo).
 *
 * Every transition still goes through ANPA_Socios_Trimestre_Repo (validated,
 * logged with actor/origin/correlation) and the single enrolment rule
 * (ANPA_Socios_Matricula_Gate) is untouched: this class only decides WHICH
 * transitions to apply (ANPA_Socios_Trimestre_Combo) and audits the result.
 *
 * @since   1.68.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Admin_Trimestres_Handler {

	/**
	 * Registers the routes.
	 *
	 * @since  1.68.0
	 * @return void
	 */
	public static function register_routes(): void {
		$ns   = ANPA_Socios_Admin_REST::REST_NAMESPACE;
		$perm = array( 'ANPA_Socios_Admin_Shared', 'permission_master' );

		register_rest_route( $ns, '/trimestres', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'estado' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/trimestres/inicializar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'inicializar' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/trimestres/estado', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'set_estado' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/trimestres/ventana', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'set_ventana' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/avisos/comezo-curso', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'aviso_comezo_curso' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/avisos/prazo-matriculas', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'aviso_prazo_matriculas' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/avisos/fin-curso', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'aviso_fin_curso' ),
			'permission_callback' => $perm,
		) );
	}

	// ──────────────────────────────────────────────
	// GET /trimestres?curso=
	// ──────────────────────────────────────────────

	/**
	 * State of the course cycle for one course (default: the active one, else
	 * the most recent pending one): trimester rows, both combo positions, the
	 * enrolment gate, recipients for a mass mail and pending enrolments.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function estado( WP_REST_Request $request ) {
		$curso = self::curso_de( (string) $request->get_param( 'curso' ) );
		if ( is_wp_error( $curso ) ) {
			return $curso;
		}
		return new WP_REST_Response( self::payload( $curso ), 200 );
	}

	/**
	 * Full panel payload for a course.
	 *
	 * @param  string $curso Valid curso escolar.
	 * @return array<string,mixed>
	 */
	public static function payload( string $curso ): array {
		$row    = self::curso_row( $curso );
		$rows   = ANPA_Socios_Trimestre_Repo::for_curso( $curso );
		$resumo = ANPA_Socios_Trimestre_Combo::resumo( $rows );
		$gate   = is_array( $row ) ? ANPA_Socios_Matricula_Gate_Repo::avaliar_fila( $row ) : ANPA_Socios_Matricula_Gate::avaliar( null, array() );
		$emails = self::socios_activos_emails();
		$lotes  = ANPA_Socios_Envio_Masivo::lotes( $emails );

		$trimestres = array();
		foreach ( array( 1, 2, 3 ) as $tri ) {
			$trimestres[] = array(
				'trimestre'      => $tri,
				'estado'         => (string) ( $rows[ $tri ]['estado'] ?? '' ),
				'ventana_estado' => (string) ( $rows[ $tri ]['ventana_estado'] ?? ANPA_Socios_Ventana_Estado::PECHADA ),
				'presente'       => ! empty( $rows[ $tri ]['presente'] ),
			);
		}

		return array(
			'curso'                => $curso,
			'estado_curso'         => is_array( $row ) ? (string) ( $row['estado'] ?? '' ) : '',
			'curso_activo'         => (string) ( ANPA_Socios_Curso_Activo::get() ?? '' ),
			'inicializado'         => (bool) $resumo['inicializado'],
			'trimestre_activo'     => $resumo['trimestre_activo'],
			'ventana_aberta'       => (int) $resumo['ventana_aberta'],
			'trimestres'           => $trimestres,
			'gate'                 => $gate,
			'aviso'                => ANPA_Socios_Matricula_Gate::aviso_listado( $gate ),
			'socios_activos'       => count( $emails ),
			'lotes'                => count( $lotes ),
			'tamano_lote'          => ANPA_Socios_Envio_Masivo::TAMANO_LOTE,
			'correo_xunta'         => ANPA_Socios_Config::master_email(),
			'matriculas_pendentes' => count( self::pendentes_ids( $curso ) ),
			'datas'                => is_array( $row ) ? ANPA_Socios_Matricula_Gate::datas_de_fila( $row ) : array( 'inicio' => '', 't1' => '', 't2' => '', 'peche' => '' ),
		);
	}

	// ──────────────────────────────────────────────
	// POST /trimestres/inicializar
	// ──────────────────────────────────────────────

	/**
	 * Explicit, audited seeding of the three trimester rows (T1 activo, T2/T3
	 * pendente, windows pechada). Never overwrites a managed row.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function inicializar( WP_REST_Request $request ) {
		$body  = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso = self::curso_de( (string) ( $body['curso'] ?? '' ) );
		if ( is_wp_error( $curso ) ) {
			return $curso;
		}
		$res = ANPA_Socios_Trimestre_Repo::ensure_seeded( $curso, ANPA_Socios_Trimestre_Repo::ORIXE_REPARACION, self::actor( $request ) );
		if ( empty( $res['ok'] ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puideron inicializar os trimestres.', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( $curso );
		ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, 'trimestres_seed' );
		return new WP_REST_Response( self::payload( $curso ) + array( 'creados' => (int) $res['created'] ), 200 );
	}

	// ──────────────────────────────────────────────
	// POST /trimestres/estado  { curso, destino: 1|2|3|'pechado', aprobar_pendentes? }
	// ──────────────────────────────────────────────

	/**
	 * Combo «Trimestre activo». Activating a trimester while there are pending
	 * enrolment requests needs `aprobar_pendentes: true`; every pending request
	 * is then approved (place or waiting list) and the families are emailed.
	 * «Curso pechado» closes the three trimesters, the three windows and the
	 * course itself.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_estado( WP_REST_Request $request ) {
		$body  = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso = self::curso_de( (string) ( $body['curso'] ?? '' ) );
		if ( is_wp_error( $curso ) ) {
			return $curso;
		}
		$destino_raw = $body['destino'] ?? null;
		$destino     = ANPA_Socios_Trimestre_Combo::CURSO_PECHADO === $destino_raw ? ANPA_Socios_Trimestre_Combo::CURSO_PECHADO : (int) $destino_raw;
		$rows        = ANPA_Socios_Trimestre_Repo::for_curso( $curso );
		$plan        = ANPA_Socios_Trimestre_Combo::plan_estado( $rows, $destino );
		if ( null === $plan ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Destino inválido ou trimestres sen inicializar.', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$pechar_curso = ANPA_Socios_Trimestre_Combo::CURSO_PECHADO === $destino;
		$pendentes    = $pechar_curso ? array() : self::pendentes_ids( $curso );
		$aprobar      = ! empty( $body['aprobar_pendentes'] );
		if ( ! $pechar_curso && array() !== $pendentes && ! $aprobar ) {
			return new WP_Error(
				'anpa_admin_matriculas_pendentes',
				sprintf(
					/* translators: %d: number of pending enrolment requests */
					_n( 'Hai %d matrícula pendente de aprobación. Confirma que pase á súa actividade ao activar o trimestre, ou xestiónaa antes en Aprobacións.', 'Hai %d matrículas pendentes de aprobación. Confirma que pasen ás súas actividades ao activar o trimestre, ou xestiónaas antes en Aprobacións.', count( $pendentes ), 'anpa-socios' ),
					count( $pendentes )
				),
				array( 'status' => 409, 'pendentes' => count( $pendentes ) )
			);
		}

		$actor       = self::actor( $request );
		$correlacion = self::correlacion( 'te_' );
		$aplicadas   = self::aplicar( $curso, ANPA_Socios_Trimestre_Repo::AMBITO_TRIMESTRE, $plan, $actor, $correlacion, $pechar_curso ? 'Xestión: curso pechado' : sprintf( 'Xestión: trimestre activo %d', (int) $destino ) );
		if ( is_wp_error( $aplicadas ) ) {
			return $aplicadas;
		}
		foreach ( $aplicadas as $t ) {
			if ( ANPA_Socios_Trimestre_Estado::PECHADO === $t[2] ) {
				ANPA_Socios_Season_Service::clear_aviso( $curso, (int) $t[0] );
			}
		}

		$aprobacions = array( 'praza' => 0, 'espera' => 0, 'erros' => 0 );
		if ( $pechar_curso ) {
			$ventanas = self::aplicar( $curso, ANPA_Socios_Trimestre_Repo::AMBITO_VENTANA, (array) ANPA_Socios_Trimestre_Combo::plan_ventana( ANPA_Socios_Trimestre_Repo::for_curso( $curso ), 0 ), $actor, $correlacion, 'Xestión: curso pechado' );
			if ( is_wp_error( $ventanas ) ) {
				return $ventanas;
			}
			if ( ! self::set_curso_estado( $curso, ANPA_Socios_Season::ESTADO_PECHADO ) ) {
				return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido pechar o curso.', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, 'curso_pechado' );
		} else {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, 'trimestre_activo_' . (int) $destino );
			foreach ( $pendentes as $id ) {
				$r = ANPA_Socios_Admin_Matriculas_Handler::aprobar( (int) $id, $actor, (string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_ROL ) );
				if ( is_wp_error( $r ) ) {
					++$aprobacions['erros'];
				} elseif ( ANPA_Socios_Matricula_Estado::ACTIVO === (string) ( $r['estado'] ?? '' ) ) {
					++$aprobacions['praza'];
				} else {
					++$aprobacions['espera'];
				}
			}
		}
		ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( $curso );

		return new WP_REST_Response( self::payload( $curso ) + array( 'aplicadas' => $aplicadas, 'aprobacions' => $aprobacions ), 200 );
	}

	// ──────────────────────────────────────────────
	// POST /trimestres/ventana  { curso, destino: 0|1|2|3, notificar }
	// ──────────────────────────────────────────────

	/**
	 * Combo «Matrículas abertas para». At most one window open: any other open
	 * window is closed first. With `notificar`, the families get the
	 * matriculas_abertas / matriculas_pechadas email.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_ventana( WP_REST_Request $request ) {
		$body  = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso = self::curso_de( (string) ( $body['curso'] ?? '' ) );
		if ( is_wp_error( $curso ) ) {
			return $curso;
		}
		$destino = (int) ( $body['destino'] ?? -1 );
		$rows    = ANPA_Socios_Trimestre_Repo::for_curso( $curso );
		$plan    = ANPA_Socios_Trimestre_Combo::plan_ventana( $rows, $destino );
		if ( null === $plan ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Destino inválido ou trimestres sen inicializar.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$row = self::curso_row( $curso );
		if ( $destino > 0 && ( ! is_array( $row ) || ANPA_Socios_Season::ESTADO_ACTIVO !== (string) $row['estado'] ) ) {
			return new WP_Error( 'anpa_admin_curso_non_activo', __( 'Só se poden abrir as matrículas do curso activo. Usa «Notificar comezo do curso» ou activa o curso primeiro.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$actor     = self::actor( $request );
		$aplicadas = self::aplicar( $curso, ANPA_Socios_Trimestre_Repo::AMBITO_VENTANA, $plan, $actor, self::correlacion( 've_' ), $destino > 0 ? sprintf( 'Xestión: matrículas abertas para o trimestre %d', $destino ) : 'Xestión: matrículas pechadas' );
		if ( is_wp_error( $aplicadas ) ) {
			return $aplicadas;
		}
		ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( $curso );
		ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, $destino > 0 ? 'ventana_aberta_' . $destino : 'ventanas_pechadas' );

		$envio = null;
		if ( ! empty( $body['notificar'] ) ) {
			// Which trimester the notice talks about: the one opened, or the one just closed
			// (falling back to the current one when nothing was open).
			$tri = $destino;
			if ( $tri < 1 ) {
				$tri = array() !== $aplicadas ? (int) $aplicadas[0][0] : (int) ( ANPA_Socios_Matricula_Gate_Repo::para_curso( $curso )['trimestre'] ?? 0 );
			}
			$envio = self::masivo( $request, $destino > 0 ? 'matriculas_abertas' : 'matriculas_pechadas', array( 'trimestre' => self::ordinal( $tri ) ) );
		}

		return new WP_REST_Response( self::payload( $curso ) + array( 'aplicadas' => $aplicadas, 'envio' => $envio ), 200 );
	}

	// ──────────────────────────────────────────────
	// POST /avisos/comezo-curso  { curso }
	// ──────────────────────────────────────────────

	/**
	 * «Notificar comezo do curso»: activates the course when it is pending (or
	 * closed) — refusing when another course is active —, seeds the trimesters,
	 * sets T1 active with its window open, and emails `inicio_curso` to every
	 * active member.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function aviso_comezo_curso( WP_REST_Request $request ) {
		$body  = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso = self::curso_de( (string) ( $body['curso'] ?? '' ) );
		if ( is_wp_error( $curso ) ) {
			return $curso;
		}
		$row = self::curso_row( $curso );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'anpa_admin_curso_invalid', __( 'Curso escolar non atopado.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		$actor = self::actor( $request );
		if ( ANPA_Socios_Season::ESTADO_ACTIVO !== (string) $row['estado'] ) {
			$outro = ANPA_Socios_Curso_Activo::get();
			if ( null !== $outro && $outro !== $curso ) {
				return new WP_Error(
					'anpa_admin_active_course_conflict',
					sprintf(
						/* translators: %s: the course that is currently active */
						__( 'Xa hai outro curso activo (%s). Péchao primeiro escollendo «Curso pechado» no seu panel.', 'anpa-socios' ),
						$outro
					),
					array( 'status' => 409, 'active_course' => $outro )
				);
			}
			if ( ! self::set_curso_estado( $curso, ANPA_Socios_Season::ESTADO_ACTIVO ) ) {
				return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido activar o curso.', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, 'activar_pechado' );
		}
		ANPA_Socios_Trimestre_Repo::ensure_seeded( $curso, ANPA_Socios_Trimestre_Repo::ORIXE_ACTIVACION, $actor );

		$correlacion = self::correlacion( 'cc_' );
		$rows        = ANPA_Socios_Trimestre_Repo::for_curso( $curso );
		$t = self::aplicar( $curso, ANPA_Socios_Trimestre_Repo::AMBITO_TRIMESTRE, (array) ANPA_Socios_Trimestre_Combo::plan_estado( $rows, 1 ), $actor, $correlacion, 'Xestión: comezo do curso' );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$v = self::aplicar( $curso, ANPA_Socios_Trimestre_Repo::AMBITO_VENTANA, (array) ANPA_Socios_Trimestre_Combo::plan_ventana( ANPA_Socios_Trimestre_Repo::for_curso( $curso ), 1 ), $actor, $correlacion, 'Xestión: comezo do curso' );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( $curso );
		ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, 'aviso_inicio_curso' );

		$envio = self::masivo( $request, 'inicio_curso', array() );

		return new WP_REST_Response( self::payload( $curso ) + array( 'aplicadas' => array_merge( $t, $v ), 'envio' => $envio ), 200 );
	}

	// ──────────────────────────────────────────────
	// POST /avisos/prazo-matriculas  { data_peche, data_inicio_actividades }
	// ──────────────────────────────────────────────

	/**
	 * «Notificar prazo de matrículas»: reminder with the closing date and the
	 * first day of the activities.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function aviso_prazo_matriculas( WP_REST_Request $request ) {
		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$peche  = self::data_valida( (string) ( $body['data_peche'] ?? '' ) );
		$inicio = self::data_valida( (string) ( $body['data_inicio_actividades'] ?? '' ) );
		if ( null === $peche || null === $inicio ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Indica a data de peche do prazo e a de comezo das actividades (AAAA-MM-DD).', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$envio = self::masivo( $request, 'prazo_matriculas', array(
			'data_peche'              => $peche,
			'data_inicio_actividades' => $inicio,
		) );
		return new WP_REST_Response( array( 'envio' => $envio ), 200 );
	}

	// ──────────────────────────────────────────────
	// POST /avisos/fin-curso  { curso }
	// ──────────────────────────────────────────────

	/**
	 * «Notificar fin de curso»: closes the three trimesters, the three windows
	 * and the course; every open group of the course becomes «pechado» and
	 * every current enrolment «baixa» (today); then emails `fin_curso`.
	 *
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function aviso_fin_curso( WP_REST_Request $request ) {
		global $wpdb;
		$body  = ANPA_Socios_Admin_Shared::json_body( $request );
		$curso = self::curso_de( (string) ( $body['curso'] ?? '' ) );
		if ( is_wp_error( $curso ) ) {
			return $curso;
		}
		$actor       = self::actor( $request );
		$correlacion = self::correlacion( 'fc_' );
		$rows        = ANPA_Socios_Trimestre_Repo::for_curso( $curso );
		if ( ANPA_Socios_Trimestre_Repo::esta_inicializado( $curso ) ) {
			$t = self::aplicar( $curso, ANPA_Socios_Trimestre_Repo::AMBITO_TRIMESTRE, (array) ANPA_Socios_Trimestre_Combo::plan_estado( $rows, ANPA_Socios_Trimestre_Combo::CURSO_PECHADO ), $actor, $correlacion, 'Xestión: fin de curso' );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$v = self::aplicar( $curso, ANPA_Socios_Trimestre_Repo::AMBITO_VENTANA, (array) ANPA_Socios_Trimestre_Combo::plan_ventana( ANPA_Socios_Trimestre_Repo::for_curso( $curso ), 0 ), $actor, $correlacion, 'Xestión: fin de curso' );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			foreach ( array( 1, 2, 3 ) as $tri ) {
				ANPA_Socios_Season_Service::clear_aviso( $curso, $tri );
			}
		}
		if ( ! self::set_curso_estado( $curso, ANPA_Socios_Season::ESTADO_PECHADO ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Non se puido pechar o curso.', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$now   = current_time( 'mysql' );
		$in    = ANPA_Socios_Matricula_Estado::sql_in( ANPA_Socios_Matricula_Estado::VIXENTES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- end-of-year bulk close, audited below.
		$mats = $wpdb->query( $wpdb->prepare(
			"UPDATE {$mat_t} m INNER JOIN {$gru_t} g ON g.id = m.grupo_id
			 SET m.estado = 'baixa', m.baixa_en = %s, m.oferta_token = NULL, m.oferta_expira = NULL, m.actualizado_en = %s
			 WHERE g.curso_escolar = %s AND m.estado IN ({$in})",
			$now,
			$now,
			$curso
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- end-of-year bulk close, audited below.
		$grupos = $wpdb->query( $wpdb->prepare(
			"UPDATE {$gru_t} SET estado = 'pechado', actualizado_en = %s WHERE curso_escolar = %s AND estado = 'aberto'",
			$now,
			$curso
		) );
		ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( $curso );
		ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', $curso, 'aviso_fin_curso' );
		ANPA_Socios_Admin_Shared::write_audit( $request, 'curso', sprintf( '%s:g%d/m%d', $curso, (int) $grupos, (int) $mats ), 'fin_curso_peche' );

		$envio = self::masivo( $request, 'fin_curso', array( 'curso_escolar' => $curso ) );

		return new WP_REST_Response( self::payload( $curso ) + array(
			'grupos_pechados'  => false === $grupos ? 0 : (int) $grupos,
			'matriculas_baixa' => false === $mats ? 0 : (int) $mats,
			'envio'            => $envio,
		), 200 );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Resolves the course to operate on: the given one, else the active one,
	 * else the most recent pending one.
	 *
	 * @param  string $curso Requested course ('' = default).
	 * @return string|WP_Error
	 */
	private static function curso_de( string $curso ) {
		$curso = trim( $curso );
		if ( '' === $curso ) {
			$curso = (string) ( ANPA_Socios_Curso_Activo::get() ?? '' );
		}
		if ( '' === $curso ) {
			global $wpdb;
			$table = ANPA_Socios_DB::tabela_cursos();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only fallback.
			$curso = (string) $wpdb->get_var( "SELECT curso_escolar FROM {$table} WHERE estado = 'pendente' ORDER BY curso_escolar DESC LIMIT 1" );
		}
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return new WP_Error( 'anpa_admin_curso_invalid', __( 'Non hai ningún curso escolar configurado. Créao en Axustes → Cursos.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		return $curso;
	}

	/**
	 * @param  string $curso Course.
	 * @return array<string,mixed>|null
	 */
	private static function curso_row( string $curso ): ?array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_cursos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . ANPA_Socios_Matricula_Gate_Repo::CURSO_COLUMNS . " FROM {$table} WHERE curso_escolar = %s", $curso ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Writes cursos.estado (matriculas_abertas is a derived cache: 0 unless the gate says otherwise later).
	 *
	 * @param  string $curso  Course.
	 * @param  string $estado activo|pechado.
	 * @return bool
	 */
	private static function set_curso_estado( string $curso, string $estado ): bool {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_cursos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lifecycle write, audited by the caller.
		$done = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET estado = %s, matriculas_abertas = 0, actualizado_en = %s WHERE curso_escolar = %s",
			$estado,
			current_time( 'mysql' ),
			$curso
		) );
		return false !== $done;
	}

	/**
	 * Applies a plan of single transitions through the repo. Stops at the first
	 * failure and reports it; the transitions already applied stay (each one is
	 * legal and logged on its own).
	 *
	 * @param  string                                       $curso       Course.
	 * @param  string                                       $ambito      AMBITO_TRIMESTRE|AMBITO_VENTANA.
	 * @param  array<int,array{0:int,1:string,2:string}>    $plan        From ANPA_Socios_Trimestre_Combo.
	 * @param  string                                       $actor       Actor email.
	 * @param  string                                       $correlacion Operation id.
	 * @param  string                                       $motivo      Human reason.
	 * @return array<int,array{0:int,1:string,2:string}>|WP_Error Applied transitions.
	 */
	private static function aplicar( string $curso, string $ambito, array $plan, string $actor, string $correlacion, string $motivo ) {
		$done = array();
		foreach ( $plan as $t ) {
			$res = ANPA_Socios_Trimestre_Repo::AMBITO_VENTANA === $ambito
				? ANPA_Socios_Trimestre_Repo::transicionar_ventana( $curso, (int) $t[0], (string) $t[2], $actor, ANPA_Socios_Trimestre_Repo::ORIXE_MANUAL, $correlacion, $motivo )
				: ANPA_Socios_Trimestre_Repo::transicionar_trimestre( $curso, (int) $t[0], (string) $t[2], $actor, ANPA_Socios_Trimestre_Repo::ORIXE_MANUAL, $correlacion, $motivo );
			if ( empty( $res['ok'] ) ) {
				return new WP_Error(
					'anpa_admin_transicion',
					sprintf(
						/* translators: 1: trimester number, 2: target state, 3: repo error code */
						__( 'Non se puido aplicar a transición do %1$dº trimestre a «%2$s» (%3$s).', 'anpa-socios' ),
						(int) $t[0],
						(string) $t[2],
						(string) ( $res['code'] ?? 'erro' )
					),
					array( 'status' => 409, 'aplicadas' => $done )
				);
			}
			if ( ! empty( $res['changed'] ) ) {
				$done[] = array( (int) $t[0], (string) $t[1], (string) $t[2] );
			}
		}
		return $done;
	}

	/**
	 * Mass mail to every active member + one audit row with the batch summary.
	 *
	 * @param  WP_REST_Request      $request     Request (actor for the audit).
	 * @param  string               $template_id Template id.
	 * @param  array<string,string> $context     Variables.
	 * @return array<string,int>
	 */
	private static function masivo( WP_REST_Request $request, string $template_id, array $context ): array {
		$resumo = ANPA_Socios_Email::enviar_masivo( self::socios_activos_emails(), $template_id, $context );
		ANPA_Socios_Admin_Shared::write_audit( $request, 'email', ANPA_Socios_Envio_Masivo::etiqueta_auditoria( $template_id, $resumo ), 'masivo' );
		return $resumo;
	}

	/**
	 * Every active member's address (both parents when both have an account).
	 *
	 * @return array<int,string>
	 */
	public static function socios_activos_emails(): array {
		global $wpdb;
		$soc_t = ANPA_Socios_DB::tabela_socios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only recipients list.
		$rows = $wpdb->get_col( "SELECT email FROM {$soc_t} WHERE estado = 'activo' AND rol <> 'master' AND email <> '' ORDER BY email ASC" );
		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Ids of the pending enrolment requests of a course.
	 *
	 * @param  string $curso Course.
	 * @return array<int,int>
	 */
	public static function pendentes_ids( string $curso ): array {
		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT m.id FROM {$mat_t} m INNER JOIN {$gru_t} g ON g.id = m.grupo_id WHERE g.curso_escolar = %s AND m.estado = %s ORDER BY m.creado_en ASC, m.id ASC",
			$curso,
			ANPA_Socios_Matricula_Estado::PENDENTE_APROBACION
		) );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * @param  WP_REST_Request $request Request.
	 * @return string
	 */
	private static function actor( WP_REST_Request $request ): string {
		$email = strtolower( trim( (string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_EMAIL ) ) );
		return '' !== $email ? $email : 'admin';
	}

	/**
	 * @param  string $prefix Prefix for the fallback id.
	 * @return string
	 */
	private static function correlacion( string $prefix ): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( $prefix, true );
	}

	/**
	 * «1º», «2º», «3º».
	 *
	 * @param  int $tri Trimester.
	 * @return string
	 */
	public static function ordinal( int $tri ): string {
		return $tri > 0 ? sprintf( '%dº', $tri ) : '';
	}

	/**
	 * Y-m-d → d/m/Y, or null when invalid.
	 *
	 * @param  string $ymd Date.
	 * @return string|null
	 */
	private static function data_valida( string $ymd ): ?string {
		$ymd = trim( $ymd );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return null;
		}
		$d = DateTime::createFromFormat( '!Y-m-d', $ymd );
		if ( ! $d instanceof DateTime || $d->format( 'Y-m-d' ) !== $ymd ) {
			return null;
		}
		return $d->format( 'd/m/Y' );
	}
}
