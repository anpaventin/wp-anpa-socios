<?php
/**
 * Admin REST handler for CSV exports.
 *
 * Provides bulk data export as CSV files for the master admin.
 * This is a format variant of data the master can already access
 * through the admin REST endpoints — it does NOT expose additional
 * data. However, it is bulk personal data egress so it is gated
 * behind permission_master and logged to the audit table.
 *
 * Formula injection defense is delegated to ANPA_Socios_Csv::cell().
 *
 * @since  1.4.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV export endpoint handler.
 *
 * @since 1.4.0
 */
final class ANPA_Socios_Admin_Export_Handler {

	/**
	 * Strict whitelist of exportable entities.
	 *
	 * @since 1.4.0
	 * @var string[]
	 */
	private const ALLOWED_ENTITIES = array(
		'socios',
		'empresas',
		'actividades',
		'matriculas',
		'fillos',
	);

	/**
	 * Column definitions per entity — matches the admin list endpoints.
	 *
	 * Per RF-5, exports use natural keys only — no internal DB IDs.
	 * Actividades includes empresa_email (natural key) instead of empresa_id.
	 * Matriculas includes fillo/actividade natural-key columns instead of
	 * fillo_id/activitad_id. Fillos drops socio_email (internal relation).
	 *
	 * @since 1.4.0
	 * @var array<string,string[]>
	 */
	private const ENTITY_COLUMNS = array(
		'socios'      => array( 'id_familia', 'rol_familia', 'email', 'nome', 'apelidos', 'nif', 'telefono', 'estado', 'segundo_proxenitor_nome', 'segundo_proxenitor_apelidos', 'segundo_proxenitor_email', 'segundo_proxenitor_nif', 'segundo_proxenitor_telefono' ),
		'empresas'    => array( 'nome', 'email', 'responsable', 'telefono', 'url_web', 'estado' ),
		'actividades' => array( 'empresa_email', 'nome', 'icono', 'descripcion', 'curso_escolar', 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max', 'nivel_min_codigo', 'nivel_max_codigo', 'custo', 'estado' ),
		'matriculas'  => array( 'proxenitor_email', 'fillo_nome', 'fillo_apelidos', 'empresa_email', 'actividade_nome', 'curso_escolar', 'grupo_nome', 'grupo_curso_range', 'grupo_franxa', 'grupo_dias', 'trimestre', 'posicion', 'comedor', 'tarde', 'observaciones', 'estado' ),
		'fillos'      => array( 'proxenitor_email', 'nome', 'apelidos', 'data_nacemento', 'curso', 'aula', 'curso_escolar', 'image_consent', 'estado' ),
	);

	/**
	 * Entities that require a JOIN to resolve natural-key columns.
	 *
	 * @since 1.34.0
	 * @var string[]
	 */
	private const JOIN_ENTITIES = array( 'actividades', 'matriculas', 'socios', 'fillos' );

	/**
	 * Registers export admin routes.
	 *
	 * The /export/alumnos route is registered BEFORE the generic
	 * /export/<entity> so it takes precedence.
	 *
	 * @since  1.4.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/export/alumnos', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_export_alumnos' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );

		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/export/(?P<entity>[a-z_]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_export' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/export/<entity>
	 *
	 * Returns a CSV file as a download attachment.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_export( WP_REST_Request $request ) {
		$entity = (string) $request->get_param( 'entity' );

		// Strict whitelist check BEFORE any database query.
		if ( ! in_array( $entity, self::ALLOWED_ENTITIES, true ) ) {
			return new WP_Error(
				'anpa_admin_invalid_entity',
				'Entidade non válida para exportación',
				array( 'status' => 400 )
			);
		}

		$columns = self::ENTITY_COLUMNS[ $entity ];
		$rows    = self::fetch_entity_rows( $entity, $columns );

		if ( null === $rows ) {
			return new WP_Error(
				'anpa_admin_db_error',
				'Erro interno ao exportar',
				array( 'status' => 500 )
			);
		}

		// Audit: log the export action.
		ANPA_Socios_Admin_Shared::write_audit( $request, 'export', $entity, 'export_csv' );

		$csv      = ANPA_Socios_Csv::document( $columns, $rows );
		$filename = 'anpa-' . $entity . '-' . gmdate( 'Y-m-d' ) . '.csv';

		$response = new WP_REST_Response( null, 200 );
		$response->set_headers( array(
			'Content-Type'        => 'text/csv; charset=utf-8',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			'Content-Length'      => (string) strlen( $csv ),
			'Cache-Control'      => 'no-store',
		) );

		// Override the standard JSON serialization: send raw CSV.
		// WP REST cannot natively stream non-JSON, so we use a filter.
		add_filter( 'rest_pre_serve_request', function ( $served, $result ) use ( $csv ) {
			if ( $result instanceof WP_HTTP_Response ) {
				$headers = $result->get_headers();
				if ( isset( $headers['Content-Type'] ) && 0 === strpos( $headers['Content-Type'], 'text/csv' ) ) {
					foreach ( $headers as $key => $value ) {
						header( "$key: $value" );
					}
					echo $csv;
					return true; // Signal that the request has been served.
				}
			}
			return $served;
		}, 10, 2 );

		return $response;
	}

	/**
	 * GET /admin/export/alumnos
	 *
	 * Returns a CSV with all active enrolments across all empresas.
	 *
	 * @since  1.5.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_export_alumnos( WP_REST_Request $request ) {
		$rows = ANPA_Socios_Alumnos_Export::rows( null );

		if ( null === $rows ) {
			return new WP_Error(
				'anpa_admin_db_error',
				'Erro interno ao exportar',
				array( 'status' => 500 )
			);
		}

		$columns  = ANPA_Socios_Alumnos_Export::columns( true );
		$csv      = ANPA_Socios_Csv::document( $columns, $rows );
		$filename = 'alumnos-todos.csv';

		// Audit: log the export action.
		ANPA_Socios_Admin_Shared::write_audit( $request, 'export', (string) count( $rows ), 'export_alumnos_admin' );

		$response = new WP_REST_Response( null, 200 );
		$response->set_headers( array(
			'Content-Type'        => 'text/csv; charset=utf-8',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			'Content-Length'      => (string) strlen( $csv ),
			'Cache-Control'       => 'no-store',
		) );

		add_filter( 'rest_pre_serve_request', function ( $served, $result ) use ( $csv ) {
			if ( $result instanceof WP_HTTP_Response ) {
				$headers = $result->get_headers();
				if ( isset( $headers['Content-Type'] ) && 0 === strpos( $headers['Content-Type'], 'text/csv' ) ) {
					foreach ( $headers as $key => $value ) {
						header( "$key: $value" );
					}
					echo $csv;
					return true;
				}
			}
			return $served;
		}, 10, 2 );

		return $response;
	}

	/**
	 * Fetches all rows for the given entity.
	 *
	 * For simple entities, selects columns directly. For actividades
	 * and matriculas, uses JOINs to resolve natural-key columns.
	 *
	 * @since  1.4.0
	 * @param  string   $entity  Entity name.
	 * @param  string[] $columns Columns to select (used for simple entities).
	 * @return array[]|null Rows or null on DB error.
	 */
	private static function fetch_entity_rows( string $entity, array $columns ): ?array {
		global $wpdb;

		if ( in_array( $entity, self::JOIN_ENTITIES, true ) ) {
			return self::fetch_joined_entity( $entity );
		}

		$cols  = implode( ', ', $columns );
		$table = self::resolve_table( $entity );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dynamic table from strict whitelist.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cols and $table from constants.
		$rows = $wpdb->get_results( "SELECT {$cols} FROM {$table} ORDER BY 1 ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : null;
	}

	/**
	 * Fetches entity rows with JOINs to resolve natural-key columns.
	 *
	 * Entities: actividades (empresa JOIN), matriculas (fillo+actividade+empresa),
	 * socios (self-JOIN for second parent), fillos (socio_email alias).
	 *
	 * @since  1.34.0
	 * @param  string $entity Entity name.
	 * @return array[]|null Rows or null on DB error.
	 */
	private static function fetch_joined_entity( string $entity ): ?array {
		global $wpdb;

		$prefix = $wpdb->prefix;

		if ( 'actividades' === $entity ) {
			$sql = "SELECT e.email AS empresa_email, a.nome, a.icono, a.descripcion, ac.curso_escolar,
					ac.min_pupilos, ac.max_pupilos, a.curso_min, a.curso_max,
					nmin.codigo AS nivel_min_codigo, nmax.codigo AS nivel_max_codigo,
					ac.custo, ac.estado
					FROM {$prefix}anpa_actividades a
					INNER JOIN {$prefix}anpa_actividades_cursos ac ON ac.actividad_id = a.id
					LEFT JOIN {$prefix}anpa_empresas e ON e.id = a.empresa_id
					LEFT JOIN {$prefix}anpa_niveis nmin ON nmin.id = ac.nivel_min_id
					LEFT JOIN {$prefix}anpa_niveis nmax ON nmax.id = ac.nivel_max_id
					ORDER BY e.email ASC, a.nome ASC, ac.curso_escolar ASC";
		} elseif ( 'matriculas' === $entity ) {
			$sql = "SELECT f.socio_email AS proxenitor_email, f.nome AS fillo_nome, f.apelidos AS fillo_apelidos,
					e.email AS empresa_email, act.nome AS actividade_nome,
					g.curso_escolar, g.nome AS grupo_nome, g.curso_range AS grupo_curso_range, g.franxa AS grupo_franxa,
					g.dias AS grupo_dias, m.trimestre, m.posicion, m.comedor, m.tarde, m.observaciones, m.estado
					FROM {$prefix}anpa_matriculas m
					LEFT JOIN {$prefix}anpa_fillos f ON f.id = m.fillo_id
					LEFT JOIN {$prefix}anpa_actividades act ON act.id = m.activitad_id
					LEFT JOIN {$prefix}anpa_empresas e ON e.id = act.empresa_id
					LEFT JOIN {$prefix}anpa_grupos g ON g.id = m.grupo_id
					ORDER BY f.socio_email ASC, f.nome ASC, f.apelidos ASC";
		} elseif ( 'socios' === $entity ) {
			$sql = "SELECT COALESCE(NULLIF(p.familia_id, 0), p.id) AS id_familia,
					p.rol_familia,
					p.email, p.nome, p.apelidos, p.nif, p.telefono, p.estado,
					s.nome AS segundo_proxenitor_nome,
					s.apelidos AS segundo_proxenitor_apelidos,
					s.email AS segundo_proxenitor_email,
					s.nif AS segundo_proxenitor_nif,
					s.telefono AS segundo_proxenitor_telefono
					FROM {$prefix}anpa_socios p
					LEFT JOIN {$prefix}anpa_socios s
						ON COALESCE(NULLIF(s.familia_id, 0), s.id) = COALESCE(NULLIF(p.familia_id, 0), p.id)
						AND s.id <> p.id
						AND s.rol_familia = 'secundario'
					WHERE p.rol <> 'master' AND p.rol_familia = 'principal'
					ORDER BY p.email ASC";
		} elseif ( 'fillos' === $entity ) {
			// fillos_cursos holds one row PER SCHOOL YEAR per fillo (UNIQUE(fillo_id,
			// curso_escolar) — see design.md fase23 §2.4). A bare LEFT JOIN would
			// duplicate this row once per historical year once a fillo accumulates
			// more than one. Scope the join to the CURRENT course year only, so the
			// export stays one row per fillo regardless of history depth.
			$current_curso = class_exists( 'ANPA_Socios_Curso_Escolar' ) ? ANPA_Socios_Curso_Escolar::current() : '';
			$sql = $wpdb->prepare(
				"SELECT socio_email AS proxenitor_email, f.nome, f.apelidos, f.data_nacemento, f.curso, f.aula,
					COALESCE(fc.curso_escolar, '') AS curso_escolar, f.image_consent, f.estado
					FROM {$prefix}anpa_fillos f
					LEFT JOIN {$prefix}anpa_fillos_cursos fc ON fc.fillo_id = f.id AND fc.curso_escolar = %s
					ORDER BY f.socio_email ASC",
				$current_curso
			);
		} else {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- JOIN query from strict whitelist entity.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input in query.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : null;
	}

	/**
	 * Resolves the full table name for an entity.
	 *
	 * @since  1.4.0
	 * @param  string $entity Entity name (from whitelist).
	 * @return string Full table name including prefix.
	 */
	private static function resolve_table( string $entity ): string {
		global $wpdb;

		$map = array(
			'socios'      => 'anpa_socios',
			'empresas'    => 'anpa_empresas',
			'actividades' => 'anpa_actividades',
			'matriculas'  => 'anpa_matriculas',
			'fillos'      => 'anpa_fillos',
		);

		return $wpdb->prefix . $map[ $entity ];
	}
}
