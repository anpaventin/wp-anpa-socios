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
	 * @since 1.4.0
	 * @var array<string,string[]>
	 */
	private const ENTITY_COLUMNS = array(
		'socios'      => array( 'email', 'nome', 'apelidos', 'estado', 'rol', 'creado_en', 'actualizado_en' ),
		'empresas'    => array( 'id', 'nome', 'email', 'responsable', 'telefono', 'estado', 'creado_en', 'actualizado_en' ),
		'actividades' => array( 'id', 'empresa_id', 'nome', 'descripcion', 'curso_escolar', 'idade_min', 'idade_max', 'custo', 'estado' ),
		'matriculas'  => array( 'id', 'fillo_id', 'activitad_id', 'estado', 'comedor', 'tarde', 'observaciones' ),
		'fillos'      => array( 'id', 'socio_email', 'nome', 'apelidos', 'data_nacemento', 'curso', 'aula', 'estado' ),
	);

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
	 * @since  1.4.0
	 * @param  string   $entity  Entity name.
	 * @param  string[] $columns Columns to select.
	 * @return array[]|null Rows or null on DB error.
	 */
	private static function fetch_entity_rows( string $entity, array $columns ): ?array {
		global $wpdb;

		$cols  = implode( ', ', $columns );
		$table = self::resolve_table( $entity );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dynamic table from strict whitelist.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cols and $table from constants.
		$rows = $wpdb->get_results( "SELECT {$cols} FROM {$table} ORDER BY 1 ASC", ARRAY_A );

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
