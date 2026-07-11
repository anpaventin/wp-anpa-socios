<?php
/**
 * Socio-facing REST endpoints for extraescolar enrolment (fase7 PR-7d).
 *
 * Authorization reuses the canonical member-area session
 * (`ANPA_Socios_Area_REST::permission_area_session`). Every mutation is
 * scoped to the authenticated socio's own fillos (no IDOR): a fillo id from
 * the client is only honoured when it belongs to the caller's email.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parent self-enrolment surface.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Extraescolares_REST {

	/**
	 * REST namespace shared with the member area.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const REST_NAMESPACE = 'anpa-socios/v1';

	/**
	 * Registers the socio enrolment routes.
	 *
	 * @since  1.9.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/area/extraescolares', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_oferta' ),
			'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
			'args'                => array(
				'fillo_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );
		register_rest_route( self::REST_NAMESPACE, '/area/fillo/(?P<id>\d+)/matricula', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'enrol' ),
			'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/area/me/matriculas', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_minhas' ),
			'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/area/matricula/(?P<id>\d+)/baixa', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'request_baixa' ),
			'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/area/matricula/(?P<id>\d+)/baixa/cancel', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'cancel_baixa' ),
			'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/area/matricula/(?P<id>\d+)/oferta/aceptar', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'accept_oferta' ),
			'permission_callback' => array( 'ANPA_Socios_Area_REST', 'permission_area_session' ),
		) );
	}

	/**
	 * GET /area/extraescolares — active activities + their open groups with
	 * current occupancy, for the enrolment picker.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_oferta( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$acy_t = ANPA_Socios_DB::tabela_actividades_cursos();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$curso = ANPA_Socios_Curso_Escolar::current();
		if ( ! self::course_is_open( $curso ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		$fillo_id    = (int) $request->get_param( 'fillo_id' );
		$curso_fillo = null;
		$sen_curso   = false;
		$sql         = "SELECT a.id, a.nome, a.descripcion FROM {$act_t} a INNER JOIN {$acy_t} ac ON ac.actividad_id = a.id AND ac.curso_escolar = %s WHERE a.estado = 'activo' AND ac.estado = 'activo'";
		$params      = array( $curso );

		// R-F1: optional fillo_id filters out already-enrolled activities.
		if ( $fillo_id > 0 ) {
			$familia_id = self::current_familia_id( $request );
			if ( $familia_id > 0 && null !== self::fetch_owned_fillo( $fillo_id, $familia_id ) ) {
				$trimestre = ANPA_Socios_Trimestre::actual( (int) current_time( 'n' ) );
				$sql      .= " AND NOT EXISTS (SELECT 1 FROM {$mat_t} WHERE activitad_id = a.id AND fillo_id = %d AND trimestre = %d AND estado <> 'baixa')";
				$params[]  = $fillo_id;
				$params[]  = $trimestre;

				$fc_t   = ANPA_Socios_DB::tabela_fillos_cursos();
				$fc_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT curso FROM {$fc_t} WHERE fillo_id = %d AND curso_escolar = %s LIMIT 1",
						$fillo_id,
						$curso
					),
					ARRAY_A
				);
				if ( is_array( $fc_row ) ) {
					$curso_fillo = (string) $fc_row['curso'];
				} else {
					$sen_curso = true;
				}
			}
		}

		$sql .= ' ORDER BY nome ASC';
		$acts = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$acts = is_array( $acts ) ? $acts : array();

		$out = array();
		foreach ( $acts as $act ) {
			$grupos = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, curso_escolar, curso_range, franxa, dias, max_pupilos, estado FROM {$gru_t} WHERE actividad_id = %d AND curso_escolar = %s AND estado = 'aberto' ORDER BY franxa ASC, id ASC",
					(int) $act['id'],
					$curso
				),
				ARRAY_A
			);
			$grupos = is_array( $grupos ) ? $grupos : array();
			if ( array() === $grupos ) {
				continue;
			}

			$grupo_out = array();
			foreach ( $grupos as $g ) {
				if ( null !== $curso_fillo && ! ANPA_Socios_Curso_Fit::fits( $curso_fillo, (string) $g['curso_range'] ) ) {
					continue;
				}

				$activos = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(1) FROM {$mat_t} WHERE grupo_id = %d AND estado = 'activo'",
						(int) $g['id']
					)
				);
				$grupo_out[] = array(
					'id'          => (int) $g['id'],
					'curso_range' => $g['curso_range'],
					'franxa'      => $g['franxa'],
					'dias'        => $g['dias'],
					'max_pupilos' => (int) $g['max_pupilos'],
					'activos'     => $activos,
					'cheo'        => $activos >= (int) $g['max_pupilos'],
				);
			}

			if ( array() === $grupo_out ) {
				continue;
			}

			$out[] = array(
				'id'          => (int) $act['id'],
				'nome'        => $act['nome'],
				'descripcion' => $act['descripcion'],
				'grupos'      => $grupo_out,
			);
		}

		if ( $sen_curso ) {
			return new WP_REST_Response(
				array(
					'activities' => $out,
					'sen_curso'  => $fillo_id,
				),
				200
			);
		}

		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * POST /area/fillo/<id>/matricula — enrol a fillo into a chosen group.
	 *
	 * Body: { actividad_id, grupo_id }. Immediate `activo` when the group is
	 * open and below capacity; otherwise `lista_espera` with the next position.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function enrol( WP_REST_Request $request ) {
		global $wpdb;

		$email = self::current_email( $request );
		if ( '' === $email ) {
			return self::err( 'anpa_extra_invalid', 'Sesión inválida ou caducada', 401 );
		}

		$familia_id = self::current_familia_id( $request );
		if ( 0 === $familia_id ) {
			return self::err( 'anpa_extra_invalid', 'Sesión inválida ou caducada', 401 );
		}

		$fillo_id = (int) $request->get_param( 'id' );
		$fillo    = self::fetch_owned_fillo( $fillo_id, $familia_id );
		if ( null === $fillo ) {
			return self::err( 'anpa_extra_not_found', 'Non atopado', 404 );
		}

		$body         = self::json_body( $request );
		$actividad_id = isset( $body['actividad_id'] ) ? (int) $body['actividad_id'] : 0;
		$grupo_id     = isset( $body['grupo_id'] ) ? (int) $body['grupo_id'] : 0;
		if ( $actividad_id <= 0 || $grupo_id <= 0 ) {
			return self::err( 'anpa_extra_invalid_payload', 'Datos inválidos', 400 );
		}

		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$grupo = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT g.id, g.actividad_id, g.curso_escolar, g.curso_range, g.franxa, g.max_pupilos, g.estado
				 FROM {$gru_t} g
				 WHERE g.id = %d",
				$grupo_id
			),
			ARRAY_A
		);
		if ( ! is_array( $grupo ) || (int) $grupo['actividad_id'] !== $actividad_id ) {
			return self::err( 'anpa_extra_grupo', 'Grupo non válido para esa actividade', 400 );
		}
		if ( ! self::course_is_open( (string) $grupo['curso_escolar'] ) ) {
			return self::err( 'anpa_extra_curso_pechado', 'Este curso está pechado para novas matrículas ou baixas.', 409 );
		}

		// Curso fit: use anpa_fillos_cursos for the group's curso_escolar.
		// If the fillo has no assignment for that course, enrolment is blocked
		// until the family/admin assigns curso+aula explicitly.
		$curso_act  = (string) $grupo['curso_escolar'];
		$fc_t       = ANPA_Socios_DB::tabela_fillos_cursos();
		$fc_row     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT curso, aula FROM {$fc_t} WHERE fillo_id = %d AND curso_escolar = %s LIMIT 1",
				$fillo_id,
				$curso_act
			),
			ARRAY_A
		);
		if ( is_array( $fc_row ) ) {
			// R-I2: use the fillo_cursos assignment for fit check.
			if ( ! ANPA_Socios_Curso_Fit::fits( (string) $fc_row['curso'], (string) $grupo['curso_range'] ) ) {
				return self::err( 'anpa_extra_curso', 'O curso do alumno/a non encaixa neste grupo', 400 );
			}
		} else {
			// R-H1: no assignment for this course → reject with specific error.
			return self::err(
				'anpa_fillo_sen_curso',
				'Este alumno/a non ten curso asignado para o curso escolar actual. Asigna o seu curso e grupo antes de continuar.',
				409
			);
		}

		$autorizacions = self::validate_authorisations( $body, (string) $grupo['franxa'] );
		if ( is_wp_error( $autorizacions ) ) {
			return $autorizacions;
		}

		$trimestre = ANPA_Socios_Trimestre::actual( (int) current_time( 'n' ) );
		$mat_t     = ANPA_Socios_DB::tabela_matriculas();

		// Serialize concurrent enrolments for this group so the capacity check is
		// atomic (prevents two requests both taking the last seat). The group row
		// is locked FOR UPDATE for the duration of the transaction.
		$wpdb->query( 'START TRANSACTION' );

		$locked = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, max_pupilos, estado FROM {$gru_t} WHERE id = %d FOR UPDATE", $grupo_id ),
			ARRAY_A
		);
		if ( ! is_array( $locked ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::err( 'anpa_extra_grupo', 'Grupo non válido', 400 );
		}

		// One non-baixa matrícula per fillo + activity + trimester (under lock).
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$mat_t} WHERE fillo_id = %d AND activitad_id = %d AND trimestre = %d AND estado <> 'baixa'",
				$fillo_id,
				$actividad_id,
				$trimestre
			)
		);
		if ( $existing > 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return self::err( 'anpa_extra_dup', 'Este alumno/a xa está matriculado nesta actividade neste trimestre', 409 );
		}

		// Capacity gate → activo or lista_espera.
		$activos = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$mat_t} WHERE grupo_id = %d AND trimestre = %d AND estado = 'activo'",
				$grupo_id,
				$trimestre
			)
		);
		$full = ( 'pechado' === (string) $locked['estado'] ) || ( $activos >= (int) $locked['max_pupilos'] );

		$estado   = $full ? 'lista_espera' : 'activo';
		$posicion = 1 + (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$mat_t} WHERE activitad_id = %d AND trimestre = %d",
				$actividad_id,
				$trimestre
			)
		);

		$fields = array(
			'grupo_id'                   => $grupo_id,
			'estado'                     => $estado,
			'posicion'                   => $posicion,
			'baixa_en'                   => null,
			'autorizacion_comedor'       => $autorizacions['autorizacion_comedor'],
			'tarde_transicion'           => $autorizacions['tarde_transicion'],
			'tardes_divertidas_continua' => $autorizacions['tardes_divertidas_continua'],
			'recollida_autorizada'       => $autorizacions['recollida_autorizada'],
			'cesion_datos_empresa'       => $autorizacions['cesion_datos_empresa'],
			'actualizado_en'             => current_time( 'mysql' ),
		);

		// A prior 'baixa' row for the same (fillo, activity, trimester) collides
		// with the unique key on INSERT — reactivate it instead of failing.
		$baixa_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$mat_t} WHERE fillo_id = %d AND activitad_id = %d AND trimestre = %d AND estado = 'baixa' LIMIT 1",
				$fillo_id,
				$actividad_id,
				$trimestre
			)
		);

		if ( $baixa_id > 0 ) {
			$ok     = $wpdb->update( $mat_t, $fields, array( 'id' => $baixa_id ) );
			$mat_id = $baixa_id;
		} else {
			$insert_data = array_merge(
				array(
					'fillo_id'     => $fillo_id,
					'activitad_id' => $actividad_id,
					'trimestre'    => $trimestre,
				),
				$fields
			);
			$ok     = $wpdb->insert( $mat_t, $insert_data );
			$mat_id = (int) $wpdb->insert_id;
		}

		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return self::err( 'anpa_extra_db', 'Erro interno ao matricular', 500 );
		}

		$wpdb->query( 'COMMIT' );

		return new WP_REST_Response( array(
			'id'        => $mat_id,
			'estado'    => $estado,
			'posicion'  => $posicion,
			'trimestre' => $trimestre,
		), 201 );
	}

	/**
	 * GET /area/me/matriculas — the socio's fillos' enrolments.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_minhas( WP_REST_Request $request ) {
		global $wpdb;

		$familia_id = self::current_familia_id( $request );
		if ( 0 === $familia_id ) {
			return self::err( 'anpa_extra_invalid', 'Sesión inválida ou caducada', 401 );
		}

		$mat_t   = ANPA_Socios_DB::tabela_matriculas();
		$fil_t   = ANPA_Socios_DB::tabela_fillos();
		$act_t   = ANPA_Socios_DB::tabela_actividades();
		$gru_t   = ANPA_Socios_DB::tabela_grupos();

		$curso_filter = $request->get_param( 'curso_escolar' );
		$sql  = "SELECT m.id, m.estado, m.posicion, m.trimestre, m.grupo_id,
			        f.id AS fillo_id, f.nome AS fillo_nome, f.apelidos AS fillo_apelidos,
			        a.nome AS actividade, g.curso_escolar, g.curso_range, g.franxa, g.dias
			 FROM {$mat_t} m
			 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
			 LEFT JOIN {$act_t} a ON a.id = m.activitad_id
			 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
			 WHERE f.familia_id = %d";
		$params = array( $familia_id );

		if ( $curso_filter && ANPA_Socios_Curso_Escolar::is_valid( $curso_filter ) ) {
			$sql    .= ' AND g.curso_escolar = %s';
			$params[] = $curso_filter;
		}

		$sql  .= ' ORDER BY m.id DESC';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		$data = is_array( $rows ) ? $rows : array();


		// Send only the current course plus school years where this family has
		// enrolments. Past years should be selectable only when there is actual
		// history for the family.
		$current = ANPA_Socios_Curso_Escolar::current();
		$cursos  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT g.curso_escolar
				 FROM {$mat_t} m
				 INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
				 WHERE f.familia_id = %d AND g.curso_escolar IS NOT NULL AND g.curso_escolar <> ''
				 ORDER BY g.curso_escolar DESC",
				$familia_id
			)
		);
		$cursos  = is_array( $cursos ) ? array_values( array_unique( array_merge( array( $current ), $cursos ) ) ) : array( $current );

		return new WP_REST_Response(
			array(
				'matriculas'          => $data,
				'current'             => $current,
				'available_courses'   => $cursos,
			),
			200
		);
	}

	// ──────────────────────────────────────────────
	// fase7 PR-7f: socio baixa / cancel / accept offer
	// ──────────────────────────────────────────────

	/**
	 * POST /area/matricula/<id>/baixa — request baixa of an enrolment.
	 *
	 * Active enrolment → estado=baixa_solicitada + email the junta (effective on
	 * admin confirm). A waitlisted/offered enrolment leaves immediately (baixa)
	 * and the group's waitlist is renumbered (offer advances if it was offered).
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function request_baixa( WP_REST_Request $request ) {
		global $wpdb;

		$email = self::current_email( $request );
		if ( '' === $email ) {
			return self::err( 'anpa_extra_invalid', 'Sesión inválida ou caducada', 401 );
		}

		$familia_id = self::current_familia_id( $request );
		if ( 0 === $familia_id ) {
			return self::err( 'anpa_extra_invalid', 'Sesión inválida ou caducada', 401 );
		}

		$mat = self::fetch_owned_matricula( (int) $request->get_param( 'id' ), $familia_id );
		if ( null === $mat ) {
			return self::err( 'anpa_extra_not_found', 'Non atopado', 404 );
		}
		if ( ! self::course_is_open( (string) ( $mat['curso_escolar'] ?? '' ) ) ) {
			return self::err( 'anpa_extra_curso_pechado', 'Este curso está pechado para novas matrículas ou baixas.', 409 );
		}

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$id    = (int) $mat['id'];

		if ( 'activo' === $mat['estado'] ) {
			$wpdb->update(
				$mat_t,
				array( 'estado' => 'baixa_solicitada', 'actualizado_en' => current_time( 'mysql' ) ),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			ANPA_Socios_Email::enviar_aviso_baixa_extraescolar( $email, self::pupil_name( (int) $mat['fillo_id'] ), self::activity_name( (int) $mat['activitad_id'] ) );
			return new WP_REST_Response( array( 'id' => $id, 'estado' => 'baixa_solicitada' ), 200 );
		}

		if ( in_array( $mat['estado'], array( 'lista_espera', 'oferta' ), true ) ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$mat_t} SET estado = 'baixa', baixa_en = %s, oferta_token = NULL, oferta_expira = NULL, actualizado_en = %s WHERE id = %d AND estado IN ('lista_espera','oferta')",
					current_time( 'mysql' ),
					current_time( 'mysql' ),
					$id
				)
			);
			if ( (int) $affected > 0 ) {
				// posición is the immutable registration order in the activity.
				if ( 'oferta' === $mat['estado'] ) {
					ANPA_Socios_Extraescolar_Offers::offer_next( (int) $mat['grupo_id'], (int) $mat['trimestre'] );
				}
			}
			return new WP_REST_Response( array( 'id' => $id, 'estado' => 'baixa' ), 200 );
		}

		return self::err( 'anpa_extra_estado', 'Esta matrícula non admite baixa neste estado', 409 );
	}

	/**
	 * POST /area/matricula/<id>/baixa/cancel — cancel a pending baixa request.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel_baixa( WP_REST_Request $request ) {
		global $wpdb;

		$familia_id = self::current_familia_id( $request );
		if ( 0 === $familia_id ) {
			return self::err( 'anpa_extra_invalid', 'Sesión inválida ou caducada', 401 );
		}

		$mat = self::fetch_owned_matricula( (int) $request->get_param( 'id' ), $familia_id );
		if ( null === $mat || 'baixa_solicitada' !== $mat['estado'] ) {
			return self::err( 'anpa_extra_not_found', 'Non atopado', 404 );
		}
		if ( ! self::course_is_open( (string) ( $mat['curso_escolar'] ?? '' ) ) ) {
			return self::err( 'anpa_extra_curso_pechado', 'Este curso está pechado para novas matrículas ou baixas.', 409 );
		}

		$wpdb->update(
			ANPA_Socios_DB::tabela_matriculas(),
			array( 'estado' => 'activo', 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'id' => (int) $mat['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return new WP_REST_Response( array( 'id' => (int) $mat['id'], 'estado' => 'activo' ), 200 );
	}

	/**
	 * POST /area/matricula/<id>/oferta/aceptar — accept a waitlist offer.
	 *
	 * Gated by the authenticated area session + ownership + estado=oferta +
	 * not expired (the offer is single-use: accepting flips it to activo).
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function accept_oferta( WP_REST_Request $request ) {
		global $wpdb;

		$familia_id = self::current_familia_id( $request );
		if ( 0 === $familia_id ) {
			return self::err( 'anpa_extra_invalid', 'Sesión inválida ou caducada', 401 );
		}

		$mat = self::fetch_owned_matricula( (int) $request->get_param( 'id' ), $familia_id );
		if ( null === $mat || 'oferta' !== $mat['estado'] ) {
			return self::err( 'anpa_extra_not_found', 'Non atopado', 404 );
		}
		if ( ! self::course_is_open( (string) ( $mat['curso_escolar'] ?? '' ) ) ) {
			return self::err( 'anpa_extra_curso_pechado', 'Este curso está pechado para novas matrículas ou baixas.', 409 );
		}

		// Expiry check (server-authoritative).
		$expira = isset( $mat['oferta_expira'] ) ? strtotime( (string) $mat['oferta_expira'] . ' UTC' ) : 0;
		if ( ! $expira || $expira < time() ) {
			return self::err( 'anpa_extra_oferta_expirada', 'A oferta caducou', 409 );
		}

		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$mat_t} SET estado = 'activo', oferta_token = NULL, oferta_expira = NULL, actualizado_en = %s WHERE id = %d AND estado = 'oferta'",
				current_time( 'mysql' ),
				(int) $mat['id']
			)
		);
		if ( 1 !== (int) $updated ) {
			// Lost the race (e.g. cron expired it concurrently).
			return self::err( 'anpa_extra_oferta_estado', 'A oferta xa non está dispoñible', 409 );
		}
		// posición is the immutable registration order in the activity.

		return new WP_REST_Response( array( 'id' => (int) $mat['id'], 'estado' => 'activo' ), 200 );
	}

	/**
	 * Fetches a matrícula only if it belongs to the authenticated socio's family.
	 *
	 * @since  1.9.0
	 * @param  int $id         Matrícula id.
	 * @param  int $familia_id Owning family id.
	 * @return array<string,mixed>|null
	 */
	private static function fetch_owned_matricula( int $id, int $familia_id ): ?array {
		if ( $id <= 0 || $familia_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.id, m.fillo_id, m.activitad_id, m.grupo_id, m.trimestre, m.estado, m.oferta_expira, g.curso_escolar
				 FROM {$mat_t} m INNER JOIN {$fil_t} f ON f.id = m.fillo_id
				 LEFT JOIN " . ANPA_Socios_DB::tabela_grupos() . " g ON g.id = m.grupo_id
				 WHERE m.id = %d AND f.familia_id = %d LIMIT 1",
				$id,
				$familia_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Pupil display name by id (for the junta email).
	 *
	 * @since  1.9.0
	 * @param  int $fillo_id Fillo id.
	 * @return string
	 */
	private static function pupil_name( int $fillo_id ): string {
		global $wpdb;
		$fil_t = ANPA_Socios_DB::tabela_fillos();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT nome, apelidos FROM {$fil_t} WHERE id = %d", $fillo_id ),
			ARRAY_A
		);

		return is_array( $row ) ? trim( ( $row['nome'] ?? '' ) . ' ' . ( $row['apelidos'] ?? '' ) ) : '';
	}

	/**
	 * Activity name by id (for the junta email).
	 *
	 * @since  1.9.0
	 * @param  int $actividad_id Activity id.
	 * @return string
	 */
	private static function activity_name( int $actividad_id ): string {
		global $wpdb;
		$act_t = ANPA_Socios_DB::tabela_actividades();

		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT nome FROM {$act_t} WHERE id = %d", $actividad_id )
		);
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Validates the enrolment authorisations required by the selected slot.
	 *
	 * Payload contract:
	 * - Always required: cesion_datos_empresa=true.
	 * - Comedor slot (franxa before 16:10): autorizacion_comedor='si'|'non'.
	 * - Tarde slot: tarde_transicion='comedor'|'familia'. Optional booleans:
	 *   tardes_divertidas_continua, recollida_autorizada.
	 *
	 * @since  1.15.0
	 * @param  array<string,mixed> $body   Request body.
	 * @param  string              $franxa Group time slot.
	 * @return array<string,int|string>|WP_Error
	 */
	private static function validate_authorisations( array $body, string $franxa ) {
		$cesion = ! empty( $body['cesion_datos_empresa'] );
		if ( ! $cesion ) {
			return self::err( 'anpa_extra_cesion_datos', 'É obrigatorio autorizar a cesión dos datos necesarios á empresa da actividade.', 400 );
		}

		$is_comedor = self::is_comedor_slot( $franxa );
		$out        = array(
			'autorizacion_comedor'       => 'na',
			'tarde_transicion'           => 'na',
			'tardes_divertidas_continua' => 0,
			'recollida_autorizada'       => 0,
			'cesion_datos_empresa'       => 1,
		);

		if ( $is_comedor ) {
			$aut_comedor = isset( $body['autorizacion_comedor'] ) ? sanitize_text_field( (string) $body['autorizacion_comedor'] ) : '';
			if ( ! in_array( $aut_comedor, array( 'si', 'non' ), true ) ) {
				return self::err( 'anpa_extra_aut_comedor', 'Nas actividades de comedor é obrigatorio indicar se autorizas ao persoal de comedor a facilitar a participación.', 400 );
			}
			$out['autorizacion_comedor'] = $aut_comedor;
			return $out;
		}

		$tarde_transicion = isset( $body['tarde_transicion'] ) ? sanitize_text_field( (string) $body['tarde_transicion'] ) : '';
		if ( ! in_array( $tarde_transicion, array( 'comedor', 'familia' ), true ) ) {
			return self::err( 'anpa_extra_tarde_transicion', 'Nas actividades de tarde é obrigatorio indicar se o neno/a vén do comedor ou se a familia o levará á actividade.', 400 );
		}

		$out['tarde_transicion']           = $tarde_transicion;
		$out['tardes_divertidas_continua'] = ! empty( $body['tardes_divertidas_continua'] ) ? 1 : 0;
		$out['recollida_autorizada']       = ! empty( $body['recollida_autorizada'] ) ? 1 : 0;

		return $out;
	}

	/**
	 * Returns whether a group slot belongs to the comedor period.
	 *
	 * @since  1.15.0
	 * @param  string $franxa Slot like 14:20-15:10 or 16:45-17:45.
	 * @return bool
	 */
	private static function is_comedor_slot( string $franxa ): bool {
		if ( ! preg_match( '/^(\d{2}):(\d{2})-/', $franxa, $m ) ) {
			return false;
		}
		$minutes = ( (int) $m[1] * 60 ) + (int) $m[2];

		return $minutes < ( 16 * 60 + 10 );
	}

	/**
	 * Authenticated socio email stashed by the permission callback.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return string
	 */
	private static function current_email( WP_REST_Request $request ): string {
		$profile = $request->get_param( '_anpa_area_profile' );
		if ( ! is_array( $profile ) || empty( $profile['email'] ) ) {
			return '';
		}

		return strtolower( trim( (string) $profile['email'] ) );
	}

	/**
	 * Returns the resolved familia_id for the authenticated socio.
	 *
	 * @since  1.21.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return int Resolved familia_id, or 0 when unavailable.
	 */
	private static function current_familia_id( WP_REST_Request $request ): int {
		$profile = $request->get_param( '_anpa_area_profile' );
		if ( ! is_array( $profile ) ) {
			return 0;
		}

		return ANPA_Socios_Familia::resolve_from_profile( $profile );
	}

	/**
	 * Fetches a fillo only if owned by the socio's family and not in baixa.
	 *
	 * @since  1.9.0
	 * @param  int $id         Fillo id.
	 * @param  int $familia_id Owning family id.
	 * @return array<string,mixed>|null
	 */
	private static function fetch_owned_fillo( int $id, int $familia_id ): ?array {
		if ( $id <= 0 || $familia_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_fillos();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, curso FROM {$table} WHERE id = %d AND familia_id = %d AND estado <> 'baixa' LIMIT 1",
				$id,
				$familia_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns whether a course accepts new enrolment/baixa changes.
	 *
	 * @since  1.10.0
	 * @param  string $curso Curso escolar.
	 * @return bool
	 */
	private static function course_is_open( string $curso ): bool {
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			return false;
		}
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_cursos();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only course gate lookup.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT matriculas_abertas, estado FROM {$table} WHERE curso_escolar = %s", $curso ), ARRAY_A );
		if ( null === $row ) {
			// New/unconfigured courses default open to avoid breaking existing staging data.
			return true;
		}

		// A course only accepts enrolment changes while its season is active.
		// The coarse lifecycle estado (pendente/pechado) overrides the finer
		// matriculas_abertas flag: a course that has not started or has finished
		// is never open, regardless of the flag.
		$estado = isset( $row['estado'] ) ? (string) $row['estado'] : ANPA_Socios_Season::ESTADO_ACTIVO;
		if ( ANPA_Socios_Season::ESTADO_ACTIVO !== $estado ) {
			return false;
		}

		return 1 === (int) $row['matriculas_abertas'];
	}

	/**
	 * Decodes the JSON request body.
	 *
	 * @since  1.9.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 */
	private static function json_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Builds a WP_Error.
	 *
	 * @since  1.9.0
	 * @param  string $code    Error code.
	 * @param  string $message Message.
	 * @param  int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function err( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
