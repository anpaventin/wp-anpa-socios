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

		// GET /admin/actividad/{id}/horario-diagnostic?curso_escolar=YYYY/YYYY
		// Read-only diagnostic: why an activity IS or IS NOT in the public horario.
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/actividad/(?P<id>\d+)/horario-diagnostic', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'horario_diagnostic' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
	}

	/**
	 * GET /admin/actividades
	 *
	 * Returns ONE row per base activity (design §8.7), not one row per
	 * (activity, school year) pair. Each row carries `cursos_ofertados`
	 * (chronologically sorted, deduplicated list of every school year the
	 * activity has a row for in `actividades_cursos`) so the admin listing
	 * can show every offered year without duplicating the activity itself.
	 *
	 * The displayed franxa/horarios/grupos/dias/min_pupilos/max_pupilos/
	 * custo/estado/prazas_* come from a single "source" annual row, chosen
	 * with this precedence:
	 *   1. the row for the currently active school year
	 *      (ANPA_Socios_Curso_Activo::get()), if the activity offers it;
	 *   2. otherwise the row for the most recent year present in
	 *      `cursos_ofertados`;
	 *   3. otherwise (legacy activity with no `actividades_cursos` rows
	 *      at all) the base `actividades` columns, same as the previous
	 *      COALESCE-based fallback.
	 *
	 * @since 23.0.0
	 * @return WP_REST_Response
	 */
	public static function list_actividades(): WP_REST_Response {
		global $wpdb;

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$acy_t = ANPA_Socios_DB::tabela_actividades_cursos();
		$mat_t = ANPA_Socios_DB::tabela_matriculas();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		// Base activity rows — one per activity id, no join fan-out.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$base_rows = $wpdb->get_results( "SELECT * FROM {$act_t} ORDER BY nome ASC", ARRAY_A );
		$base_rows = is_array( $base_rows ) ? $base_rows : array();
		if ( array() === $base_rows ) {
			return new WP_REST_Response( array(), 200 );
		}

		$ids     = array_map( static function ( $r ) { return (int) $r['id']; }, $base_rows );
		$id_list = implode( ',', $ids );

		// Every annual row for these activities, chronologically ordered so
		// "most recent" is simply the last element per activity.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$acy_rows = $wpdb->get_results(
			"SELECT * FROM {$acy_t} WHERE actividad_id IN ({$id_list}) ORDER BY curso_escolar ASC",
			ARRAY_A
		);
		$acy_rows = is_array( $acy_rows ) ? $acy_rows : array();

		// Enrolment counts scoped to a specific (actividad_id, curso_escolar)
		// pair — same scoping rule as the previous query: a matricula only
		// counts when its grupo's curso_escolar matches the annual row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$scoped_counts = $wpdb->get_results(
			"SELECT m.activitad_id AS actividad_id, g.curso_escolar AS curso_escolar, m.estado AS estado, COUNT(*) AS total
			 FROM {$mat_t} m
			 LEFT JOIN {$gru_t} g ON g.id = m.grupo_id
			 WHERE m.activitad_id IN ({$id_list}) AND m.estado IN ('activo','lista_espera')
			 GROUP BY m.activitad_id, g.curso_escolar, m.estado",
			ARRAY_A
		);
		$scoped_counts = is_array( $scoped_counts ) ? $scoped_counts : array();

		// Unscoped totals (any curso, any/no grupo) — used only for legacy
		// activities that have no `actividades_cursos` row at all, mirroring
		// the previous behaviour where `ac.curso_escolar IS NULL` counted
		// every matricula regardless of the enrolled group's school year.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$legacy_totals = $wpdb->get_results(
			"SELECT m.activitad_id AS actividad_id, m.estado AS estado, COUNT(*) AS total
			 FROM {$mat_t} m
			 WHERE m.activitad_id IN ({$id_list}) AND m.estado IN ('activo','lista_espera')
			 GROUP BY m.activitad_id, m.estado",
			ARRAY_A
		);
		$legacy_totals = is_array( $legacy_totals ) ? $legacy_totals : array();

		$rows = ANPA_Socios_Actividades_Collapse::collapse(
			$base_rows,
			$acy_rows,
			ANPA_Socios_Curso_Activo::get(),
			$scoped_counts,
			$legacy_totals
		);

		return new WP_REST_Response( $rows, 200 );
	}

	public static function create_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$body = ANPA_Socios_Admin_Shared::json_body( $request );

		// Field-level validation for specific error messages.
		$err = self::validar_campos_actividad( $body );
		if ( null !== $err ) {
			return $err;
		}

		$payload = ANPA_Socios_Admin_Payload::validar_actividad( $body );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$cursos = self::validated_cursos( $body, (string) $payload['curso_escolar'] );
		if ( is_wp_error( $cursos ) ) {
			return $cursos;
		}

		$base = self::base_payload( $payload );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$inserted = $wpdb->insert(
			ANPA_Socios_DB::tabela_actividades(),
			$base,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$actividad_id = (int) $wpdb->insert_id;
		$sync_result  = self::sync_actividad_cursos( $actividad_id, $cursos, $payload );
		if ( is_wp_error( $sync_result ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $sync_result;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $actividad_id, 'create' );

		return new WP_REST_Response( self::get_row( $actividad_id, (string) $payload['curso_escolar'] ), 201 );
	}

	public static function update_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$body = ANPA_Socios_Admin_Shared::json_body( $request );

		// Field-level validation for specific error messages.
		$err = self::validar_campos_actividad( $body );
		if ( null !== $err ) {
			return $err;
		}

		$payload = ANPA_Socios_Admin_Payload::validar_actividad( $body );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$cursos = self::validated_cursos( $body, (string) $payload['curso_escolar'] );
		if ( is_wp_error( $cursos ) ) {
			return $cursos;
		}
		$table = ANPA_Socios_DB::tabela_actividades();
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id )
		);
		if ( 0 === $exists ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Actividade non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		$base                   = self::base_payload( $payload );
		$base['actualizado_en'] = current_time( 'mysql' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$updated = $wpdb->update(
			$table,
			$base,
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$sync_result = self::sync_actividad_cursos( $id, $cursos, $payload );
		if ( is_wp_error( $sync_result ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $sync_result;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		if ( $updated > 0 || $sync_result > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'actividad', (string) $id, 'update' );
		}

		return new WP_REST_Response( self::get_row( $id, (string) $payload['curso_escolar'] ), 200 );
	}

	public static function delete_actividad( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$table = ANPA_Socios_DB::tabela_actividades();
		$estado = $wpdb->get_var(
			$wpdb->prepare( "SELECT estado FROM {$table} WHERE id = %d", $id )
		);
		if ( null === $estado ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Actividade non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( 'inactivo' !== $estado ) {
			return new WP_Error( 'anpa_admin_must_deactivate', __( 'Desactiva a actividade antes de eliminala.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		// Gate: block if ANY matricula exists (any estado, any year) — per design §8.5.
		$mat_table = ANPA_Socios_DB::tabela_matriculas();
		$has_matriculas = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$mat_table} WHERE activitad_id = %d",
				$id
			)
		);
		if ( $has_matriculas > 0 ) {
			return new WP_Error( 'anpa_admin_actividad_has_data', __( 'Non se pode eliminar a actividade porque ten matrículas asociadas.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		// No matriculas exist — safe to cascade-delete empty groups and the activity.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		$groups_table       = ANPA_Socios_DB::tabela_grupos();
		$grupos_niveis_table = ANPA_Socios_DB::tabela_grupos_niveis();

		// Step 1: Delete grupos_niveis for this activity's groups.
		$group_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$groups_table} WHERE actividad_id = %d", $id )
		);
		if ( ! empty( $group_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cascade delete within transaction.
			$deleted_gn = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$grupos_niveis_table} WHERE grupo_id IN ({$placeholders})",
					...$group_ids
				)
			);
			if ( false === $deleted_gn ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
		}

		// Step 2: Delete the groups themselves.
		$deleted_groups = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$groups_table} WHERE actividad_id = %d", $id )
		);
		if ( false === $deleted_groups ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// Step 3: Delete actividades_cursos rows.
		$deleted_courses = $wpdb->delete( ANPA_Socios_DB::tabela_actividades_cursos(), array( 'actividad_id' => $id ), array( '%d' ) );
		if ( false === $deleted_courses ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// Step 4: Delete the actividade itself.
		$deleted = $wpdb->delete( ANPA_Socios_DB::tabela_actividades(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( 0 === $deleted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_not_found', __( 'Actividade non atopada.', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

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
			return (int) $updated;
		}

		$inserted = $wpdb->insert(
			$table,
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		return 1;
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
	 * Validates and normalizes the selected school years.
	 *
	 * @param array<string,mixed> $body         Raw request body.
	 * @param string              $curso_primary Primary school year.
	 * @return string[]|WP_Error
	 */
	private static function validated_cursos( array $body, string $curso_primary ) {
		if ( isset( $body['cursos'] ) && ! is_array( $body['cursos'] ) ) {
			return new WP_Error( 'anpa_admin_invalid_cursos', __( 'Revisa os cursos nos que se oferta a actividade.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$input = isset( $body['cursos'] ) ? $body['cursos'] : array();
		$cursos = ANPA_Socios_Admin_Payload::normalizar_cursos_actividad( $input, $curso_primary );
		if ( null === $cursos ) {
			return new WP_Error( 'anpa_admin_invalid_cursos', __( 'Revisa os cursos nos que se oferta a actividade.', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		foreach ( $cursos as $curso ) {
			if ( ! self::curso_exists( $curso ) ) {
				return new WP_Error( 'anpa_admin_curso_not_found', __( 'Curso escolar non creado', 'anpa-socios' ), array( 'status' => 400 ) );
			}
		}

		return $cursos;
	}

	/**
	 * Syncs the actividades_cursos rows for a given activity with the set
	 * of selected school years. Inserts missing, removes unchecked.
	 *
	 * @since 1.24.0
	 * @param int                  $actividad_id Activity id.
	 * @param string[]             $cursos       Validated school years.
	 * @param array<string,mixed>  $payload      Validated payload for year data.
	 * @return int|WP_Error Number of changed rows, or an error.
	 */
	private static function sync_actividad_cursos( int $actividad_id, array $cursos, array $payload ) {
		global $wpdb;

		$table   = ANPA_Socios_DB::tabela_actividades_cursos();
		$changed = 0;
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT curso_escolar FROM {$table} WHERE actividad_id = %d",
				$actividad_id
			)
		);
		$existing = is_array( $existing ) ? $existing : array();

		$to_remove = array_diff( $existing, $cursos );
		foreach ( $to_remove as $curso ) {
			$deleted = $wpdb->delete( $table, array( 'actividad_id' => $actividad_id, 'curso_escolar' => $curso ) );
			if ( false === $deleted ) {
				return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
			}
			$changed += (int) $deleted;
		}

		foreach ( $cursos as $curso ) {
			$row_payload = $payload;
			$row_payload['curso_escolar'] = $curso;
			$upserted = self::upsert_year_payload( $actividad_id, $row_payload );
			if ( is_wp_error( $upserted ) ) {
				return $upserted;
			}
			$changed += (int) $upserted;
		}

		return $changed;
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

	/**
	 * Field-level validation for actividad required fields.
	 *
	 * Returns specific error messages for common missing/invalid fields,
	 * or null if all required fields pass basic checks.
	 *
	 * @since  1.34.0
	 * @param  array<string,mixed> $body Raw request body.
	 * @return WP_Error|null
	 */
	private static function validar_campos_actividad( array $body ) {
		$issue = ANPA_Socios_Admin_Payload::diagnosticar_actividad( $body );
		if ( null === $issue ) {
			return null;
		}
		$messages = array(
			'empresa_required'       => __( 'Selecciona unha empresa válida.', 'anpa-socios' ),
			'nome_required'          => __( 'O nome da actividade é obrigatorio.', 'anpa-socios' ),
			'descripcion_required'   => __( 'A descrición é obrigatoria.', 'anpa-socios' ),
			'curso_escolar_required' => __( 'O curso escolar é obrigatorio.', 'anpa-socios' ),
			'curso_escolar_invalid'  => __( 'O curso escolar debe ter formato AAAA/AAAA+1.', 'anpa-socios' ),
			'horarios_required'      => __( 'Selecciona polo menos un horario válido.', 'anpa-socios' ),
			'grupos_required'        => __( 'Selecciona polo menos un grupo válido.', 'anpa-socios' ),
			'dias_required'          => __( 'Selecciona polo menos un día válido.', 'anpa-socios' ),
			'custo_invalid'          => __( 'O custo debe ser un número válido.', 'anpa-socios' ),
			'curso_range_invalid'    => __( 'O curso mínimo non pode ser maior ca o curso máximo.', 'anpa-socios' ),
			'estado_invalid'         => __( 'O estado da actividade non é válido.', 'anpa-socios' ),
		);

		return new WP_Error( 'anpa_admin_' . $issue, $messages[ $issue ] ?? __( 'Revisa os datos da actividade.', 'anpa-socios' ), array( 'status' => 400 ) );
	}

	/**
	 * Admin-only diagnostic: why an activity IS or IS NOT in the public horario.
	 *
	 * GET /admin/actividad/{id}/horario-diagnostic?curso_escolar=YYYY/YYYY
	 * Returns one of: incluida_por_grupo, incluida_por_horario_anual_provisional,
	 * sen_franxa, sen_dias, sen_grupo_aberto, estado_inactivo, curso_non_activo.
	 *
	 * @since  1.27.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function horario_diagnostic( WP_REST_Request $request ) {
		global $wpdb;

		$id            = (int) $request['id'];
		$curso_escolar = sanitize_text_field( $request->get_param( 'curso_escolar' ) ?? '' );

		// Default to current active curso if not specified.
		if ( '' === $curso_escolar ) {
			$curso_escolar = ANPA_Socios_Curso_Activo::get() ?? '';
		}
		if ( '' === $curso_escolar ) {
			return new WP_Error( 'anpa_admin_no_curso', 'Non hai curso activo.', array( 'status' => 400 ) );
		}

		$act_t = ANPA_Socios_DB::tabela_actividades();
		$acy_t = ANPA_Socios_DB::tabela_actividades_cursos();
		$gru_t = ANPA_Socios_DB::tabela_grupos();

		// Fetch activity + actividades_cursos row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$activity = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.nome, a.estado, ac.franxa, ac.dias, ac.estado AS curso_estado
				 FROM {$act_t} a
				 LEFT JOIN {$acy_t} ac ON ac.actividad_id = a.id AND ac.curso_escolar = %s
				 WHERE a.id = %d",
				$curso_escolar,
				$id
			),
			ARRAY_A
		);

		if ( null === $activity ) {
			return new WP_Error( 'anpa_admin_not_found', 'Actividade non atopada.', array( 'status' => 404 ) );
		}

		// If no actividades_cursos row exists, it means the activity is not
		// configured for this curso at all.
		if ( null === $activity['curso_estado'] ) {
			return new WP_REST_Response( array(
				'actividad_id'  => $id,
				'curso_escolar' => $curso_escolar,
				'reason'        => 'curso_non_activo',
			) );
		}

		// Determine whether the curso_escolar is the currently active one.
		$curso_is_active = ( $curso_escolar === ANPA_Socios_Curso_Activo::get() );

		// Fetch all grupos for this (actividad, curso) pair (any estado).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$grupos = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.estado FROM {$gru_t} g WHERE g.actividad_id = %d AND g.curso_escolar = %s",
				$id,
				$curso_escolar
			),
			ARRAY_A
		);
		if ( ! is_array( $grupos ) ) {
			$grupos = array();
		}

		$reason = ANPA_Socios_Horario_Builder::diagnose( $activity, $grupos, $curso_is_active );

		return new WP_REST_Response( array(
			'actividad_id'  => $id,
			'curso_escolar' => $curso_escolar,
			'reason'        => $reason,
		) );
	}
}
