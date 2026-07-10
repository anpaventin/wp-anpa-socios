<?php
/**
 * Admin REST handler for IBAN CSV import (socios_iban entity).
 *
 * Sealed-box encrypts IBAN + titular NIF before storage. Uses
 * permission_with_passphrase to verify the caller holds the same
 * banking key currently in use.
 *
 * Security invariants:
 * - Plaintext IBAN/NIF is NEVER persisted, echoed, or logged.
 * - Responses show at most masked/last4 values.
 * - Reuses existing crypto (ANPA_Socios_Crypto::seal + Banking_Key::public_key).
 * - Reuses existing persistence (ANPA_Socios_Domiciliacion::save_sealed).
 *
 * @since  1.35.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for POST /admin/import/socios-iban and
 * POST /admin/import/socios-disable-no-iban.
 *
 * @since 1.35.0
 */
final class ANPA_Socios_Admin_Iban_Import_Handler {

	/**
	 * Maximum preview rows in dry-run response.
	 *
	 * @since 1.35.0
	 * @var int
	 */
	const PREVIEW_LIMIT = 20;

	/**
	 * Registers the IBAN import and disable-non-reimported routes.
	 *
	 * @since  1.35.0
	 * @return void
	 */
	public static function register_routes(): void {
		// IBAN import (passphrase-gated).
		register_rest_route(
			ANPA_Socios_Admin_REST::REST_NAMESPACE,
			'/import/socios-iban',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_iban_import' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Auth', 'permission_with_passphrase' ),
			)
		);

		// Disable socios without IBAN (destructive — dry-run + audited confirm).
		register_rest_route(
			ANPA_Socios_Admin_REST::REST_NAMESPACE,
			'/import/socios-disable-no-iban',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_disable_no_iban' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Auth', 'permission_with_passphrase' ),
			)
		);
	}

	/**
	 * POST /admin/import/socios-iban
	 *
	 * Body: { csv: "<raw text>", passphrase: "...", commit: 0|1 }
	 *
	 * CSV columns: id_familia, titular_nome, titular_apelidos, titular_nif,
	 *              iban, entidade_bancaria, autorizacion
	 *
	 * Dry-run (commit=0): reports which families would get IBAN, which
	 * rows have errors. NEVER echoes plaintext IBAN/NIF.
	 *
	 * Commit (commit=1): seals IBAN+NIF and stores via Domiciliacion::save_sealed.
	 * Idempotent (upserts by familia_id).
	 *
	 * @since  1.35.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_iban_import( WP_REST_Request $request ) {
		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$csv    = isset( $body['csv'] ) ? (string) $body['csv'] : '';
		$commit = ! empty( $body['commit'] );

		if ( '' === $csv ) {
			return new WP_Error( 'anpa_import_empty', 'CSV baleiro', array( 'status' => 400 ) );
		}

		// Parse CSV via the pure core.
		$rows = ANPA_Socios_Csv_Import::parse( $csv );
		if ( empty( $rows ) ) {
			return new WP_Error( 'anpa_import_no_rows', 'O CSV non contén filas válidas', array( 'status' => 400 ) );
		}

		// Analyze (normalize + validate + dedup by id_familia).
		$report = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		// Resolve familia_id from logical id_familia. Families must already
		// exist from the socios import; unknown families are errors.
		// SECURITY: use the strict `valid` set (rows with NO validation errors),
		// never `to_insert` (which keeps error rows for the report) — we must
		// not seal an invalid/empty IBAN/NIF.
		$resolved = self::resolve_families( $report['valid'] );

		if ( ! $commit ) {
			return self::dry_run_response( $report, $resolved );
		}

		// Commit: seal + store.
		$result = self::commit_iban_rows( $resolved['valid'], $request );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /admin/import/socios-disable-no-iban
	 *
	 * Body: { passphrase: "...", imported_familias: [int,...], commit: 0|1 }
	 *
	 * Dry-run (commit=0): reports which active socios would be disabled
	 * because their family did NOT receive IBAN in this pass.
	 *
	 * Commit (commit=1): sets those socios to estado='baixa'. AUDITED.
	 * Never touches the master/admin. Never disables a family that has IBAN.
	 *
	 * @since  1.35.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_disable_no_iban( WP_REST_Request $request ) {
		$body              = ANPA_Socios_Admin_Shared::json_body( $request );
		$imported_familias = isset( $body['imported_familias'] ) && is_array( $body['imported_familias'] )
			? array_map( 'intval', $body['imported_familias'] )
			: array();
		$commit            = ! empty( $body['commit'] );

		if ( empty( $imported_familias ) ) {
			return new WP_Error(
				'anpa_import_no_familias',
				'Debes indicar as familias que recibiron IBAN nesta pasada.',
				array( 'status' => 400 )
			);
		}

		// Find active socios whose family is NOT in the imported list,
		// excluding master/admin roles.
		$candidates = self::find_socios_without_iban( $imported_familias );

		if ( ! $commit ) {
			// Dry-run: show who would be disabled.
			return new WP_REST_Response( array(
				'action'           => 'disable_no_iban_preview',
				'candidates_count' => count( $candidates ),
				'candidates'       => array_slice( $candidates, 0, self::PREVIEW_LIMIT ),
				'imported_familias_count' => count( $imported_familias ),
			), 200 );
		}

		// Commit: disable candidates.
		$result = self::commit_disable( $candidates, $request );

		return new WP_REST_Response( $result, 200 );
	}

	// ─────────────────────────────────────────────────────────────
	// IBAN import internals
	// ─────────────────────────────────────────────────────────────

	/**
	 * Resolves logical id_familia to real DB familia_id for each row.
	 *
	 * The family must already exist in socios table (from the socios
	 * import). Unknown families are reported as errors.
	 *
	 * @since  1.35.0
	 * @param  array $rows Validated rows from analyze().
	 * @return array{valid:array,errors:array}
	 */
	private static function resolve_families( array $rows ): array {
		global $wpdb;
		$soc_t  = ANPA_Socios_DB::tabela_socios();
		$valid  = array();
		$errors = array();

		// Cache: logical id → real familia_id.
		$cache = array();

		foreach ( $rows as $idx => $row ) {
			$logical = (string) ( $row['id_familia'] ?? '' );
			if ( '' === $logical ) {
				$errors[] = array( 'row' => $idx, 'msg' => 'id_familia is empty' );
				continue;
			}

			if ( ! isset( $cache[ $logical ] ) ) {
				// Resolve: look for a socio whose familia_id or id matches.
				$real = $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(NULLIF(familia_id, 0), id) FROM {$soc_t} WHERE id = %d OR familia_id = %d LIMIT 1",
					(int) $logical,
					(int) $logical
				) );
				$cache[ $logical ] = $real ? (int) $real : null;
			}

			if ( null === $cache[ $logical ] ) {
				$errors[] = array( 'row' => $idx, 'msg' => "Familia non atopada: id_familia={$logical}" );
				continue;
			}

			$row['_real_familia_id'] = $cache[ $logical ];
			$valid[] = $row;
		}

		return array( 'valid' => $valid, 'errors' => $errors );
	}

	/**
	 * Builds the dry-run response (no plaintext IBAN/NIF echoed).
	 *
	 * @since  1.35.0
	 * @param  array $report   Analysis report from Csv_Import::analyze().
	 * @param  array $resolved Resolved families result.
	 * @return WP_REST_Response
	 */
	private static function dry_run_response( array $report, array $resolved ): WP_REST_Response {
		// Build a safe preview: show masked IBAN + NIF only.
		$preview = array();
		foreach ( array_slice( $resolved['valid'], 0, self::PREVIEW_LIMIT ) as $row ) {
			$preview[] = array(
				'id_familia'       => $row['id_familia'] ?? '',
				'familia_id'       => $row['_real_familia_id'] ?? 0,
				'titular_nome'     => $row['titular_nome'] ?? '',
				'titular_apelidos' => $row['titular_apelidos'] ?? '',
				'titular_nif_mask' => ANPA_Socios_Csv_Import::mask_nif_for_report( $row['titular_nif'] ?? '' ),
				'iban_last4'       => ANPA_Socios_Csv_Import::mask_iban_for_report( $row['iban'] ?? '' ),
				'entidade_bancaria' => $row['entidade_bancaria'] ?? '',
			);
		}

		// Merge parse errors + resolve errors.
		$all_errors = array_merge( $report['errors'], $resolved['errors'] );

		return new WP_REST_Response( array(
			'total'             => count( $report['rows'] ),
			'to_import_count'   => count( $resolved['valid'] ),
			'resolve_errors'    => $resolved['errors'],
			'validation_errors' => $report['errors'],
			'duplicates_count'  => count( $report['duplicates'] ),
			'preview'           => $preview,
		), 200 );
	}

	/**
	 * Commits IBAN rows: seal and store via Domiciliacion::save_sealed.
	 *
	 * Idempotent: upserts by familia_id.
	 *
	 * @since  1.35.0
	 * @param  array           $rows    Resolved valid rows with _real_familia_id.
	 * @param  WP_REST_Request $request For audit logging.
	 * @return array{imported:int,errors:array,imported_familias:int[]}
	 */
	private static function commit_iban_rows( array $rows, WP_REST_Request $request ): array {
		$imported          = 0;
		$errors            = array();
		$imported_familias = array();

		foreach ( $rows as $idx => $row ) {
			$familia_id = (int) $row['_real_familia_id'];

			// Build the sepa block reusing Domiciliacion::save_sealed signature.
			$sepa = array(
				'titular_nome'     => $row['titular_nome'] ?? '',
				'titular_apelidos' => $row['titular_apelidos'] ?? '',
				'titular_nif'      => $row['titular_nif'] ?? '',
				'iban'             => $row['iban'] ?? '',
				'entidade_bancaria' => $row['entidade_bancaria'] ?? '',
				'enderezo'         => '',
				'poboacion'        => '',
				'codigo_postal'    => '',
				'autorizacion'     => $row['autorizacion'] ?? '1',
				'lugar_data'       => '',
			);

			$result = ANPA_Socios_Domiciliacion::save_sealed( $familia_id, $sepa );
			if ( is_wp_error( $result ) ) {
				$errors[] = array( 'row' => $idx, 'familia_id' => $familia_id, 'msg' => $result->get_error_message() );
				continue;
			}

			$imported++;
			$imported_familias[] = $familia_id;
		}

		if ( $imported > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'import', 'socios_iban', 'iban_import_commit' );
		}

		return array(
			'imported'           => $imported,
			'errors'             => $errors,
			'imported_familias'  => $imported_familias,
		);
	}

	// ─────────────────────────────────────────────────────────────
	// Disable-non-reimported internals
	// ─────────────────────────────────────────────────────────────

	/**
	 * Finds active socios whose family did NOT receive IBAN.
	 *
	 * Excludes master/admin rol. Returns safe display data only (no PII).
	 *
	 * @since  1.35.0
	 * @param  int[] $imported_familias Family IDs that received IBAN.
	 * @return array Array of candidate socios (id, nome, apelidos, familia_id, estado).
	 */
	private static function find_socios_without_iban( array $imported_familias ): array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_socios();

		// Build a safe IN clause.
		$placeholders = implode( ',', array_fill( 0, count( $imported_familias ), '%d' ) );

		// Active socios whose familia_id is NOT in the imported list,
		// excluding master rol (never auto-disable the admin).
		$query = $wpdb->prepare(
			"SELECT id, nome, apelidos, COALESCE(NULLIF(familia_id, 0), id) AS familia_id, estado
			 FROM {$table}
			 WHERE estado = 'activo'
			   AND rol <> 'master'
			   AND COALESCE(NULLIF(familia_id, 0), id) NOT IN ({$placeholders})",
			...$imported_familias
		);

		$results = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Disables (sets estado='baixa') the candidate socios.
	 *
	 * Audits each batch disable action.
	 *
	 * @since  1.35.0
	 * @param  array           $candidates Socios to disable.
	 * @param  WP_REST_Request $request    For audit logging.
	 * @return array{disabled:int,errors:array}
	 */
	private static function commit_disable( array $candidates, WP_REST_Request $request ): array {
		global $wpdb;
		$table    = ANPA_Socios_DB::tabela_socios();
		$disabled = 0;
		$errors   = array();

		foreach ( $candidates as $soc ) {
			$ok = $wpdb->update(
				$table,
				array( 'estado' => 'baixa' ),
				array( 'id' => (int) $soc['id'] ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $ok ) {
				$errors[] = array( 'socio_id' => (int) $soc['id'], 'msg' => 'DB update failed' );
				continue;
			}
			$disabled++;
		}

		if ( $disabled > 0 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'import', 'socios_disable_no_iban', 'disable_commit' );
		}

		return array( 'disabled' => $disabled, 'errors' => $errors );
	}
}
