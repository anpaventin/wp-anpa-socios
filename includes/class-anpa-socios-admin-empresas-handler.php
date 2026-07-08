<?php
/**
 * Admin REST handler for the empresas domain.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/empresa*` endpoints.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_Empresas_Handler {

	/**
	 * Registers empresas admin routes.
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/empresas', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_empresas' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_empresa' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/empresa/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_empresa' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_empresa' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
	}

	public static function list_empresas(): WP_REST_Response {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, nome, email, responsable, telefono, url_web, estado, creado_en, actualizado_en FROM {$wpdb->prefix}anpa_empresas ORDER BY nome ASC",
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	public static function create_empresa( WP_REST_Request $request ) {
		global $wpdb;

		$payload = ANPA_Socios_Admin_Payload::validar_empresa( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'anpa_empresas',
			$payload,
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$code = (string) $wpdb->last_error;
			if ( false !== strpos( $code, '1062' ) ) {
				return new WP_Error( 'anpa_admin_email_taken', __( 'Email xa rexistrado', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'empresa', (string) $wpdb->insert_id, 'create' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, nome, email, responsable, telefono, url_web, estado, creado_en, actualizado_en FROM {$wpdb->prefix}anpa_empresas WHERE id = %d",
				$wpdb->insert_id
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $row ) ? $row : array(), 201 );
	}

	public static function update_empresa( WP_REST_Request $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$payload = ANPA_Socios_Admin_Payload::validar_empresa( ANPA_Socios_Admin_Shared::json_body( $request ) );
		if ( null === $payload ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Datos inválidos', 'anpa-socios' ), array( 'status' => 400 ) );
		}
		$payload['actualizado_en'] = current_time( 'mysql' );

		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_empresas',
			$payload,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			$code = (string) $wpdb->last_error;
			if ( false !== strpos( $code, '1062' ) ) {
				return new WP_Error( 'anpa_admin_email_taken', __( 'Email xa rexistrado', 'anpa-socios' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'empresa', (string) $id, 'update' );

		return new WP_REST_Response( $payload + array( 'id' => $id ), 200 );
	}

	public static function delete_empresa( WP_REST_Request $request ) {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );
		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_empresas',
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

		ANPA_Socios_Admin_Shared::write_audit( $request, 'empresa', (string) $id, 'delete' );

		return new WP_REST_Response( null, 204 );
	}
}
