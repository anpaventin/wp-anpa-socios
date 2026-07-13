<?php
/**
 * Admin REST handler for the actividades domain.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/actividad*` endpoints.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_Actividades_Handler {

	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividades', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_actividades' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_actividad' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_actividad' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_actividad' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );

		// POST /admin/actividad/{id}/copy-to-current
		// Legacy route kept for back-compat — uses current course.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)/copy-to-current', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'copy_actividad_to_current' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );

		// POST /admin/actividad/{id}/duplicate
		// Duplicates an activity into a chosen target school year.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)/duplicate', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate_actividad' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
	}

	public static function list_actividades(): WP_REST_Response {
		global $wpdb;

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$acy_t = ANPA_Socios_DB::tabela_actividades_cursos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();
		$rows  = $wpdb->get_results(
			"SELECT a.id, a.empresa_id, a.nome, a.icono, a.descripcion,
			        COALESCE(ac.curso_escolar, a.curso_escolar) AS curso_escolar,
			        COALESCE(ac.franxa, a.franxa) AS franxa,
			        COALESCE(ac.horarios, a.horarios) AS horarios,
			        COALESCE(ac.grupos, a.grupos) AS grupos,
			        COALESCE(ac.dias, a.dias) AS dias,
			        COALESCE(ac.min_pupilos, a.min_pupilos) AS min_pupilos,
			        COALESCE(ac.max_pupilos, a.max_pupilos) AS max_pupilos,
			        COALESCE(ac.custo, a.custo) AS custo,
			        COALESCE(ac.estado, a.estado) AS estado,
			        a.curso_min,
			        a.curso_max,
			        (SELECT COUNT(*) FROM {$mat_t} m LEFT JOIN {$gru_t} g ON g.id = m.grupo_id WHERE m.activitad_id = a.id AND m.estado = 'activo' AND (ac.curso_escolar IS NULL OR g.curso_escolar = ac.curso_escolar)) AS prazas_ocupadas,
			        (SELECT COUNT(*) FROM {$mat_t} m LEFT JOIN {$gru_t} g ON g.id = m.grupo_id WHERE m.activitad_id = a.id AND m.estado = 'lista_espera' AND (ac.curso_escolar IS NULL OR g.curso_escolar = ac.curso_escolar)) AS prazas_espera
			 FROM {$act_t} a
			 LEFT JOIN {$acy_t} ac ON ac.actividad_id = a.id
			 ORDER BY COALESCE(ac.curso_escolar, a.curso_escolar) DESC, a.nome ASC",
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();

		// Attach the list of cursos (school years) each activity is offered in.
		$all_ids = array();
		foreach ( $rows as $r ) { $all_ids[ (int) $r['id'] ] = true; }
		$cursos_map = array();
		if ( ! empty( $all_ids ) ) {
			$id_list = implode( ',', array_map( 'intval', array_keys( $all_ids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$cursos_rows = $wpdb->get_results(
				"SELECT actividad_id, curso_escolar FROM {$acy_t} WHERE actividad_id IN ({$id_list}) ORDER BY curso_escolar ASC",
				ARRAY_A
			);
			if ( is_array( $cursos_rows ) ) {
				foreach ( $cursos_rows as $cr ) {
					$cursos_map[ (int) $cr['actividad_id'] ][] = $cr['curso_escolar'];
				}
			}
		}
		foreach ( $rows as &$r ) {
			$r['cursos'] = $cursos_map[ (int) $r['id'] ] ?? array();
		}
		unset( $r );

		return new WP_REST_Response( $rows, 200 );
	}

	public static function create_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$payload = ANPA_Socios_Admin_Payload::validar_actividad( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		if ( ! self::curso_exists( (string) $payload['curso_escolar'] ) ) {
			return new WP_Error( 'anpa_admin_curso_not_found', __( 'Curso escolar non creado', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$base = self::base_payload( $payload );
		$inserted = $wpdb->insert(
			ANPA_Socios_DB::tabela_actividades(),
			$base,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$actividad_id = (int) $wpdb->insert_id;

		// Multi-course sync: use cursos array if provided, else fall back to single curso_escolar.
		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$cursos = isset( $body['cursos'] ) && is_array( $body['cursos'] ) ? $body['cursos'] : array();
		if ( empty( $cursos ) ) {
			$cursos = array( (string) $payload['curso_escolar'] );
		}
		self::sync_actividad_cursos( $actividad_id, $cursos, $payload );

		ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $actividad_id, 'create' );

		return new WP_REST_Response( self::get_row( $actividad_id, (string) $payload['curso_escolar'] ), 201 );
	}

	public static function update_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$payload = ANPA_Socios_Admin_Payload::validar_actividad( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		if ( ! self::curso_exists( (string) $payload['curso_escolar'] ) ) {
			return new WP_Error( 'anpa_admin_curso_not_found', __( 'Curso escolar non creado', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$base                   = self::base_payload( $payload );
		$base['actualizado_en'] = current_time( 'mysql' );
		$updated = $wpdb->update(
			ANPA_Socios_DB::tabela_actividades(),
			$base,
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// Multi-course sync: use cursos array if provided, else fall back to single curso_escolar.
		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$cursos = isset( $body['cursos'] ) && is_array( $body['cursos'] ) ? $body['cursos'] : array();
		if ( empty( $cursos ) ) {
			$cursos = array( (string) $payload['curso_escolar'] );
		}
		self::sync_actividad_cursos( $id, $cursos, $payload );

		ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $id, 'update' );

		return new WP_REST_Response( self::get_row( $id, (string) $payload['curso_escolar'] ), 200 );
	}

	public static function delete_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );
		$updated = $wpdb->update(
			ANPA_Socios_DB::tabela_actividades(),
			array(
				'estado'         => 'inactivo',
				'actualizado_en' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$wpdb->update(
			ANPA_Socios_DB::tabela_actividades_cursos(),
			array( 'estado' => 'inactivo', 'actualizado_en' => current_time( 'mysql' ) ),
			array( 'actividad_id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $id, 'delete' );

		return new WP_REST_Response( null, 204 );
	}

	/** @param array<string,mixed> $payload */
	private static function base_payload( array $payload ): array {
		return array(
			'empresa_id'    => (int) $payload['empresa_id'],
			'nome'          => (string) $payload['nome'],
			'icono'         => (string) $payload['icono'],
			'descripcion'   => (string) $payload['descripcion'],
			'curso_escolar' => (string) $payload['curso_escolar'],
			'franxa'        => (string) $payload['franxa'],
			'horarios'      => (string) $payload['horarios'],
			'grupos'        => (string) $payload['grupos'],
			'dias'          => (string) $payload['dias'],
			'curso_min'     => $payload['curso_min'],
			'curso_max'     => $payload['curso_max'],
			'min_pupilos'   => (int) $payload['min_pupilos'],
			'max_pupilos'   => (int) $payload['max_pupilos'],
			'custo'         => (float) $payload['custo'],
			'estado'        => (string) $payload['estado'],
		);
	}

	/** @param array<string,mixed> $payload */
	private static function year_payload( int $actividad_id, array $payload ): array {
		return array(
			'actividad_id'   => $actividad_id,
			'curso_escolar'  => (string) $payload['curso_escolar'],
			'franxa'         => (string) $payload['franxa'],
			'horarios'       => (string) $payload['horarios'],
			'grupos'         => (string) $payload['grupos'],
			'dias'           => (string) $payload['dias'],
			'min_pupilos'    => (int) $payload['min_pupilos'],
			'max_pupilos'    => (int) $payload['max_pupilos'],
			'custo'          => (float) $payload['custo'],
			'estado'         => (string) $payload['estado'],
			'actualizado_en' => current_time( 'mysql' ),
		);
	}

	/** @param array<string,mixed> $payload */
	private static function upsert_year_payload( int $actividad_id, array $payload ) {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_actividades_cursos();
		$row   = self::year_payload( $actividad_id, $payload );
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE actividad_id = %d AND curso_escolar = %s",
				$actividad_id,
				(string) $payload['curso_escolar']
			)
		);

		if ( $exists > 0 ) {
			$updated = $wpdb->update(
				$table,
				$row,
				array( 'id' => $exists ),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			return true;
		}

		$inserted = $wpdb->insert(
			$table,
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		return true;
	}

	private static function get_row( int $id, string $curso_escolar ): array {
		global $wpdb;

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$acy_t = ANPA_Socios_DB::tabela_actividades_cursos();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.id, a.empresa_id, a.nome, a.icono, a.descripcion,
				        COALESCE(ac.curso_escolar, a.curso_escolar) AS curso_escolar,
				        COALESCE(ac.franxa, a.franxa) AS franxa,
				        COALESCE(ac.horarios, a.horarios) AS horarios,
				        COALESCE(ac.grupos, a.grupos) AS grupos,
				        COALESCE(ac.dias, a.dias) AS dias,
				        COALESCE(ac.min_pupilos, a.min_pupilos) AS min_pupilos,
				        COALESCE(ac.max_pupilos, a.max_pupilos) AS max_pupilos,
				        COALESCE(ac.custo, a.custo) AS custo,
				        COALESCE(ac.estado, a.estado) AS estado,
				        0 AS prazas_ocupadas,
				        0 AS prazas_espera
				 FROM {$act_t} a
				 LEFT JOIN {$acy_t} ac ON ac.actividad_id = a.id AND ac.curso_escolar = %s
				 WHERE a.id = %d",
				$curso_escolar,
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	private static function curso_exists( string $curso_escolar ): bool {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_cursos();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$table} WHERE curso_escolar = %s",
				$curso_escolar
			)
		) > 0;
	}

	/**
	 * Syncs the actividades_cursos rows for a given activity with the set
	 * of selected school years. Inserts missing, removes unchecked.
	 *
	 * @since 1.24.0
	 * @param int                  $actividad_id Activity id.
	 * @param string[]             $cursos       List of curso_escolar values.
	 * @param array<string,mixed>  $payload      Validated payload for year data.
	 * @return void
	 */
	private static function sync_actividad_cursos( int $actividad_id, array $cursos, array $payload ): void {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_actividades_cursos();

		// Filter to valid curso_escolar strings only.
		$valid_cursos = array();
		foreach ( $cursos as $c ) {
			if ( ANPA_Socios_Curso_Escolar::is_valid( (string) $c ) ) {
				$valid_cursos[] = (string) $c;
			}
		}
		if ( empty( $valid_cursos ) ) {
			$valid_cursos = array( (string) $payload['curso_escolar'] );
		}

		// Get existing rows for this activity.
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT curso_escolar FROM {$table} WHERE actividad_id = %d",
				$actividad_id
			)
		);
		$existing = is_array( $existing ) ? $existing : array();

		// Remove rows for courses no longer selected.
		$to_remove = array_diff( $existing, $valid_cursos );
		foreach ( $to_remove as $rm ) {
			$wpdb->delete( $table, array( 'actividad_id' => $actividad_id, 'curso_escolar' => $rm ) );
		}

		// Upsert rows for each selected course.
		foreach ( $valid_cursos as $curso ) {
			$row_payload = $payload;
			$row_payload['curso_escolar'] = $curso;
			self::upsert_year_payload( $actividad_id, $row_payload );
		}
	}

	/**
	 * POST /admin/actividad/{id}/copy-to-current (legacy — delegates to duplicate).
	 *
	 * @since 1.20.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function copy_actividad_to_current( WP_REST_Request $request ) {
		$active = ANPA_Socios_Curso_Activo::get();
		if ( null === $active ) {
			return new WP_Error( 'anpa_admin_no_active_course', 'Non hai un curso activo.', array( 'status' => 409 ) );
		}
		$request->set_body_params( array( 'target_curso' => $active ) );
		return self::duplicate_actividad( $request );
	}

	/**
	 * POST /admin/actividad/{id}/duplicate
	 *
	 * Duplicates an existing actividad into a chosen school year.
	 *
	 * Body: { target_curso: "2025/2026" }. If omitted, defaults to current.
	 *
	 * Creates a NEW actividad row copying descriptive data, associated with
	 * the chosen target school year (its own actividades_cursos row).
	 *
	 * @since 1.24.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$src_id = (int) $request->get_param( 'id' );
		$body   = is_array( $request->get_json_params() ) ? $request->get_json_params() : array();
		$target = isset( $body['target_curso'] ) && ANPA_Socios_Curso_Escolar::is_valid( (string) $body['target_curso'] )
			? (string) $body['target_curso']
			: ANPA_Socios_Curso_Activo::get();
		if ( null === $target ) {
			return new WP_Error( 'anpa_admin_no_active_course', 'Non hai un curso activo.', array( 'status' => 409 ) );
		}

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$acy_t = ANPA_Socios_DB::tabela_actividades_cursos();

		// Read the source row.
		$src = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$act_t} WHERE id = %d", $src_id ),
			ARRAY_A
		);
		if ( ! is_array( $src ) ) {
			return new WP_Error( 'anpa_admin_not_found', 'Actividade non atopada.', array( 'status' => 404 ) );
		}

		// Copy descriptive fields; curso_escolar set to target.
		$copy = array(
			'empresa_id'    => isset( $src['empresa_id'] ) ? (int) $src['empresa_id'] : 0,
			'nome'          => (string) ( $src['nome'] ?? '' ),
			'icono'         => (string) ( $src['icono'] ?? '' ),
			'descripcion'   => (string) ( $src['descripcion'] ?? '' ),
			'curso_escolar' => $target,
			'franxa'        => (string) ( $src['franxa'] ?? '' ),
			'horarios'      => (string) ( $src['horarios'] ?? '' ),
			'grupos'        => (string) ( $src['grupos'] ?? '' ),
			'dias'          => (string) ( $src['dias'] ?? '' ),
			'curso_min'     => isset( $src['curso_min'] ) && '' !== (string) $src['curso_min'] ? (int) $src['curso_min'] : null,
			'curso_max'     => isset( $src['curso_max'] ) && '' !== (string) $src['curso_max'] ? (int) $src['curso_max'] : null,
			'min_pupilos'   => isset( $src['min_pupilos'] ) ? (int) $src['min_pupilos'] : 10,
			'max_pupilos'   => isset( $src['max_pupilos'] ) ? (int) $src['max_pupilos'] : 15,
			'custo'         => isset( $src['custo'] ) ? (float) $src['custo'] : 0.0,
			'estado'        => 'activo',
		);

		$wpdb->last_error = '';
		$ok = $wpdb->insert( $act_t, $copy );
		if ( false === $ok ) {
			return new WP_Error( 'anpa_socios_db_error', 'Erro ao crear a actividade duplicada.', array( 'status' => 500 ) );
		}
		$new_id = (int) $wpdb->insert_id;

		// Duplicate the per-course-year entry (if any) for the target year.
		$src_acy = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$acy_t} WHERE actividad_id = %d ORDER BY id ASC LIMIT 1",
				$src_id
			),
			ARRAY_A
		);
		$acy_copy = array(
			'actividad_id'   => $new_id,
			'curso_escolar'  => $target,
			'franxa'         => is_array( $src_acy ) ? (string) ( $src_acy['franxa'] ?? $copy['franxa'] ) : $copy['franxa'],
			'horarios'       => is_array( $src_acy ) ? (string) ( $src_acy['horarios'] ?? $copy['horarios'] ) : $copy['horarios'],
			'grupos'         => is_array( $src_acy ) ? (string) ( $src_acy['grupos'] ?? $copy['grupos'] ) : $copy['grupos'],
			'dias'           => is_array( $src_acy ) ? (string) ( $src_acy['dias'] ?? $copy['dias'] ) : $copy['dias'],
			'min_pupilos'    => is_array( $src_acy ) && isset( $src_acy['min_pupilos'] ) ? (int) $src_acy['min_pupilos'] : $copy['min_pupilos'],
			'max_pupilos'    => is_array( $src_acy ) && isset( $src_acy['max_pupilos'] ) ? (int) $src_acy['max_pupilos'] : $copy['max_pupilos'],
			'custo'          => is_array( $src_acy ) && isset( $src_acy['custo'] ) ? (float) $src_acy['custo'] : $copy['custo'],
			'estado'         => 'activo',
			'actualizado_en' => current_time( 'mysql' ),
		);
		$wpdb->insert( $acy_t, $acy_copy );

		ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $new_id, 'duplicate_from_' . $src_id . '_to_' . $target );

		return new WP_REST_Response( self::get_row( $new_id, $target ), 201 );
	}
}
