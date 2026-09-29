<?php
/**
 * Admin REST — Operacións → Correos masivos (1.84.0): the catalogue of mass
 * emails and the log of the ones sent (from the audit log). The sends
 * themselves reuse their existing routes (contactos-google/inicio-curso,
 * avisos/prazo-matriculas…).
 *
 * @since   1.84.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mass emails section.
 *
 * @since 1.84.0
 */
final class ANPA_Socios_Admin_Correos_Handler {

	/** Rows shown in the send log. */
	const REXISTRO_MAX = 100;

	/** Registers the route. */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/correos-masivos', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'estado' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/correos-masivos — catalogue, recipients and send log.
	 *
	 * @return WP_REST_Response
	 */
	public static function estado(): WP_REST_Response {
		global $wpdb;
		$soc_t = ANPA_Socios_DB::tabela_socios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count, ASCII SQL.
		$activos = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$soc_t} WHERE estado = 'activo' AND rol <> 'master' AND email <> ''" );
		$lote    = ANPA_Socios_Envio_Masivo::TAMANO_LOTE;

		$aud_t = ANPA_Socios_DB::tabela_audit_log();
		$in    = implode( ',', array_fill( 0, count( ANPA_Socios_Correos_Masivos::ACCIONS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT actor_email, target_id, accion, `timestamp` FROM {$aud_t} WHERE target_tipo = 'email' AND accion IN ({$in}) ORDER BY id DESC LIMIT %d", array_merge( ANPA_Socios_Correos_Masivos::ACCIONS, array( self::REXISTRO_MAX ) ) ), ARRAY_A );
		$rexistro = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$f = ANPA_Socios_Correos_Masivos::fila( $r );
			if ( null === $f ) {
				continue;
			}
			// Audit timestamps are UTC.
			$f['data']  = function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $f['timestamp'] ) : $f['timestamp'];
			$rexistro[] = $f;
		}

		return new WP_REST_Response( array(
			'catalogo'           => ANPA_Socios_Correos_Masivos::catalogo(),
			'rexistro'           => $rexistro,
			'socios_activos'     => $activos,
			'lotes'              => (int) ceil( $activos / max( 1, $lote ) ),
			'tamano_lote'        => $lote,
			'conta_xunta'        => ANPA_Socios_Config::master_email(),
			'etiqueta'           => ANPA_Socios_Admin_Contactos_Google_Handler::LABEL,
			'instrucions_url'    => ANPA_Socios_Config::instrucions_url(),
			'extraescolares_url' => ANPA_Socios_Config::extraescolares_url(),
		), 200 );
	}
}
