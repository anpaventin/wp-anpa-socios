<?php
/**
 * Admin REST handler for the full export (with optional decrypted banking)
 * and the audit-log viewer (fase6 PR-5a).
 *
 * Master-only. The full export ALWAYS requires the banking passphrase (it
 * authorises the sensitive bulk egress); the admin chooses whether to include
 * decrypted banking columns. Decryption happens in-memory with the unwrapped
 * secret key. The action is audited.
 *
 * @since  1.8.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for `/admin/export/full` and `/admin/audit`.
 *
 * @since 1.8.0
 */
final class ANPA_Socios_Admin_Reports_Handler {

	/**
	 * Registers the report routes.
	 *
	 * @since  1.8.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/export/full', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'export_full' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/audit', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_audit' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/audit — recent audit-log rows (master-only).
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function list_audit( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 || $limit > 200 ) {
			$limit = 50;
		}

		$table = ANPA_Socios_DB::tabela_audit_log();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT actor_email, actor_tipo, target_tipo, target_id, accion, timestamp FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /admin/export/full { passphrase, include_banking } — master-only.
	 *
	 * Always requires a valid passphrase (authorises the sensitive bulk export).
	 * include_banking adds decrypted titular/IBAN/NIF columns.
	 *
	 * @since  1.8.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function export_full( WP_REST_Request $request ) {
		global $wpdb;

		$body            = ANPA_Socios_Admin_Shared::json_body( $request );
		$passphrase      = (string) ( $body['passphrase'] ?? '' );
		$include_banking = filter_var( $body['include_banking'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( '' === $passphrase ) {
			return new WP_Error( 'anpa_admin_passphrase', 'Falta o contrasinal de descifrado', array( 'status' => 400 ) );
		}

		$public  = ANPA_Socios_Banking_Key::public_key();
		$wrapped = ANPA_Socios_Banking_Key::wrapped_secret();
		if ( null === $public || null === $wrapped ) {
			return new WP_Error( 'anpa_admin_no_key', 'A clave bancaria non está configurada', array( 'status' => 409 ) );
		}

		// Brute-force lockout on the decryption passphrase (per admin actor):
		// 5 wrong attempts within 15 minutes blocks further tries.
		$actor    = (string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_EMAIL );
		$lock_key = 'anpa_export_pp_fail_' . md5( '' !== $actor ? $actor : 'export' );
		$fails    = (int) get_transient( $lock_key );
		if ( $fails >= 5 ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'export', 'full', 'export_locked' );
			return new WP_Error( 'anpa_admin_export_locked', 'Demasiados intentos co contrasinal. Téntao de novo en 15 minutos.', array( 'status' => 429 ) );
		}

		$secret = ANPA_Socios_Crypto::unwrap_secret( $wrapped['blob'], $wrapped['salt'], $wrapped['nonce'], $passphrase );
		if ( null === $secret ) {
			set_transient( $lock_key, $fails + 1, 15 * MINUTE_IN_SECONDS );
			ANPA_Socios_Admin_Shared::write_audit( $request, 'export', 'full', 'export_denied' );
			return new WP_Error( 'anpa_admin_bad_passphrase', 'Contrasinal incorrecto', array( 'status' => 403 ) );
		}
		// Correct passphrase: clear the failed-attempt counter.
		delete_transient( $lock_key );

		$socios = ANPA_Socios_DB::tabela_socios();
		$dom    = ANPA_Socios_DB::tabela_domiciliacions();

		$columns = array( 'email', 'nome', 'apelidos', 'nif', 'telefono', 'estado', 'rol', 'familia_id' );

		if ( $include_banking ) {
			// Only fetch banking ciphertext when it will actually be used.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from DB helper; master-only bulk export.
			$rows = $wpdb->get_results(
				"SELECT s.email, s.nome, s.apelidos, s.nif, s.telefono, s.estado, s.rol, IFNULL(s.familia_id, s.id) AS familia_id,
					d.titular_nome, d.titular_apelidos, d.entidade_bancaria, d.autorizacion,
					d.iban_cifrado, d.titular_nif_cifrado
				FROM {$socios} s
				LEFT JOIN {$dom} d ON d.familia_id = IFNULL(s.familia_id, s.id)
				ORDER BY s.apelidos, s.nome",
				ARRAY_A
			);
			$columns = array_merge(
				$columns,
				array( 'titular_nome', 'titular_apelidos', 'entidade_bancaria', 'iban', 'titular_nif_banco', 'autorizacion' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from DB helper; master-only export.
			$rows = $wpdb->get_results(
				"SELECT s.email, s.nome, s.apelidos, s.nif, s.telefono, s.estado, s.rol, IFNULL(s.familia_id, s.id) AS familia_id
				FROM {$socios} s
				ORDER BY s.apelidos, s.nome",
				ARRAY_A
			);
		}
		$rows = is_array( $rows ) ? $rows : array();

		$out = array();
		foreach ( $rows as $r ) {
			$line = array(
				'email'      => (string) $r['email'],
				'nome'       => (string) $r['nome'],
				'apelidos'   => (string) $r['apelidos'],
				'nif'        => (string) ( $r['nif'] ?? '' ),
				'telefono'   => (string) ( $r['telefono'] ?? '' ),
				'estado'     => (string) $r['estado'],
				'rol'        => (string) $r['rol'],
				'familia_id' => (string) $r['familia_id'],
			);
			if ( $include_banking ) {
				$has_banking               = ! empty( $r['iban_cifrado'] );
				$line['titular_nome']      = (string) ( $r['titular_nome'] ?? '' );
				$line['titular_apelidos']  = (string) ( $r['titular_apelidos'] ?? '' );
				$line['entidade_bancaria'] = (string) ( $r['entidade_bancaria'] ?? '' );
				$line['iban']              = $has_banking ? (string) ANPA_Socios_Crypto::unseal( (string) $r['iban_cifrado'], $public, $secret ) : '';
				$line['titular_nif_banco'] = $has_banking ? (string) ANPA_Socios_Crypto::unseal( (string) $r['titular_nif_cifrado'], $public, $secret ) : '';
				$line['autorizacion']      = isset( $r['autorizacion'] ) ? (string) (int) $r['autorizacion'] : '';
			}
			$out[] = $line;
		}

		sodium_memzero( $secret );

		ANPA_Socios_Admin_Shared::write_audit( $request, 'export', 'full', $include_banking ? 'export_full_banking' : 'export_full' );

		$csv      = ANPA_Socios_Csv::document( $columns, $out );
		$filename = 'anpa-export-completo-' . gmdate( 'Y-m-d' ) . '.csv';

		$response = new WP_REST_Response( null, 200 );
		$response->set_headers( array(
			'Content-Type'        => 'text/csv; charset=utf-8',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			'Content-Length'      => (string) strlen( $csv ),
			'Cache-Control'       => 'no-store',
			'Pragma'              => 'no-cache',
		) );

		add_filter( 'rest_pre_serve_request', function ( $served, $result ) use ( $csv ) {
			if ( $result instanceof WP_HTTP_Response ) {
				$headers = $result->get_headers();
				if ( isset( $headers['Content-Type'] ) && 0 === strpos( $headers['Content-Type'], 'text/csv' ) ) {
					foreach ( $headers as $key => $value ) {
						header( "$key: $value" );
					}
					echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV body.
					return true;
				}
			}
			return $served;
		}, 10, 2 );

		return $response;
	}
}
