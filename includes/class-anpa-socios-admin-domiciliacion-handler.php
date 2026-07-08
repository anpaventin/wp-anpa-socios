<?php
/**
 * Admin REST handler for SEPA banking (domiciliacions).
 *
 * Master-only, audited, on-demand decryption of a family's banking
 * mandate. This is the ONLY surface that decrypts IBAN/NIF, and it is
 * never part of any export.
 *
 * @since  1.7.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/domiciliacion/<familia_id>` endpoint.
 *
 * @since 1.7.0
 */
final class ANPA_Socios_Admin_Domiciliacion_Handler {

	/**
	 * Registers the domiciliacion admin route.
	 *
	 * @since  1.7.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			ANPA_Socios_Admin_REST::REST_NAMESPACE,
			'/domiciliacion/(?P<familia_id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'get_domiciliacion' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			)
		);
	}

	/**
	 * POST /admin/domiciliacion/<familia_id> — master-only, audited.
	 *
	 * Body: { passphrase }. Unwraps the banking secret key with the passphrase
	 * (in-memory) and unseals IBAN + titular NIF on demand. The passphrase is
	 * sent in the body (POST), never in the URL/logs. Every successful read is
	 * audited.
	 *
	 * @since  1.7.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_domiciliacion( WP_REST_Request $request ) {
		global $wpdb;

		$familia_id = (int) $request->get_param( 'familia_id' );
		if ( $familia_id <= 0 ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Identificador inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$body       = ANPA_Socios_Admin_Shared::json_body( $request );
		$passphrase = (string) ( $body['passphrase'] ?? '' );
		if ( '' === $passphrase ) {
			return new WP_Error( 'anpa_admin_passphrase', __( 'Falta o contrasinal de descifrado', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$table = ANPA_Socios_DB::tabela_domiciliacions();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE familia_id = %d", $familia_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'anpa_admin_not_found', __( 'Sen domiciliación', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		// Unwrap the banking secret key with the supplied passphrase (in memory).
		$public  = ANPA_Socios_Banking_Key::public_key();
		$wrapped = ANPA_Socios_Banking_Key::wrapped_secret();
		if ( null === $public || null === $wrapped ) {
			return new WP_Error( 'anpa_admin_no_key', __( 'A clave bancaria non está configurada', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		$secret = ANPA_Socios_Crypto::unwrap_secret( $wrapped['blob'], $wrapped['salt'], $wrapped['nonce'], $passphrase );
		if ( null === $secret ) {
			// Wrong passphrase (or tampered blob): generic error, no oracle.
			ANPA_Socios_Admin_Shared::write_audit( $request, 'domiciliacion', (string) $familia_id, 'decrypt_denied' );
			return new WP_Error( 'anpa_admin_bad_passphrase', __( 'Contrasinal incorrecto', 'anpa-socios' ), array( 'status' => 403 ) );
		}

		$iban = ANPA_Socios_Crypto::unseal( (string) $row['iban_cifrado'], $public, $secret );
		$nif  = ANPA_Socios_Crypto::unseal( (string) $row['titular_nif_cifrado'], $public, $secret );
		sodium_memzero( $secret );

		if ( null === $iban || null === $nif ) {
			ANPA_Socios_Admin_Shared::write_audit( $request, 'domiciliacion', (string) $familia_id, 'decrypt_fail' );
			return new WP_Error( 'anpa_admin_decrypt_error', __( 'Erro ao descifrar', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// Audit the successful read of sensitive banking data.
		ANPA_Socios_Admin_Shared::write_audit( $request, 'domiciliacion', (string) $familia_id, 'read' );

		$response = new WP_REST_Response(
			array(
				'familia_id'        => $familia_id,
				'titular_nome'      => (string) $row['titular_nome'],
				'titular_apelidos'  => (string) $row['titular_apelidos'],
				'titular_nif'       => $nif,
				'enderezo'          => (string) $row['enderezo'],
				'poboacion'         => (string) $row['poboacion'],
				'codigo_postal'     => (string) $row['codigo_postal'],
				'entidade_bancaria' => (string) $row['entidade_bancaria'],
				'iban'              => $iban,
				'iban_last4'        => (string) $row['iban_last4'],
				'autorizacion'      => (int) $row['autorizacion'],
				'lugar_data'        => (string) $row['lugar_data'],
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
