<?php
/**
 * Binary download from a REST route (1.74.0): spreadsheets (.ods) for the
 * company / canteen panel and Xestión. WP REST only serves JSON, so the bytes
 * are sent through rest_pre_serve_request, as the CSV exports already do.
 *
 * @since   1.74.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST file responses.
 *
 * @since 1.74.0
 */
final class ANPA_Socios_Descarga {

	/**
	 * @param  string $bytes File contents.
	 * @param  string $tipo  Media type.
	 * @param  string $nome  File name (UTF-8; an ASCII fallback is added).
	 * @return WP_REST_Response
	 */
	public static function resposta( string $bytes, string $tipo, string $nome ): WP_REST_Response {
		$ascii = function_exists( 'remove_accents' ) ? remove_accents( $nome ) : $nome;
		$ascii = (string) preg_replace( '/[^A-Za-z0-9 ._()-]/', '_', $ascii );
		$response = new WP_REST_Response( null, 200 );
		$response->set_headers( array(
			'Content-Type'                  => $tipo,
			'Content-Disposition'           => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $nome ),
			'Content-Length'                => (string) strlen( $bytes ),
			'Cache-Control'                 => 'no-store',
			'Access-Control-Expose-Headers' => 'Content-Disposition',
		) );
		add_filter( 'rest_pre_serve_request', static function ( $served, $result ) use ( $bytes, $tipo ) {
			if ( $result instanceof WP_HTTP_Response ) {
				$headers = $result->get_headers();
				if ( isset( $headers['Content-Type'] ) && $tipo === $headers['Content-Type'] ) {
					foreach ( $headers as $key => $value ) {
						header( "$key: $value" );
					}
					echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file.
					return true;
				}
			}
			return $served;
		}, 10, 2 );
		return $response;
	}
}
