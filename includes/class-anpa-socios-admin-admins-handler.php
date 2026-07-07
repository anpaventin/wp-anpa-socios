<?php
/**
 * Admin REST handler for the admins (RBAC) domain.
 *
 * Manages multi-admin grant, list, and revoke operations.
 * All endpoints are gated by permission_master.
 *
 * @since  1.4.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/admins` endpoints.
 *
 * @since 1.4.0
 */
final class ANPA_Socios_Admin_Admins_Handler {

	/**
	 * Registers admin management routes.
	 *
	 * @since  1.4.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/admins', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_admins' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'grant_admin' ),
				'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
			),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/admins/(?P<email>[^/]+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'revoke_admin' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/admins — list all socios with rol='master'.
	 *
	 * @since  1.4.0
	 * @return WP_REST_Response
	 */
	public static function list_admins(): WP_REST_Response {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT email, nome, apelidos, estado, rol FROM {$wpdb->prefix}anpa_socios WHERE rol = 'master' ORDER BY email ASC",
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /admin/admins {email} — promote an active socio to admin.
	 *
	 * Validation order: sanitize email → exists? (404) → active? (409) →
	 * UPDATE rol='master' → write_audit → 200.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function grant_admin( WP_REST_Request $request ) {
		global $wpdb;

		$body  = ANPA_Socios_Admin_Shared::json_body( $request );
		$email = ANPA_Socios_Admin_Payload::sanitise_email( (string) ( $body['email'] ?? '' ) );
		if ( null === $email || '' === $email ) {
			return new WP_Error( 'anpa_admin_invalid', 'Email required', array( 'status' => 400 ) );
		}

		// Check socio exists.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT email, nome, apelidos, estado, rol FROM {$wpdb->prefix}anpa_socios WHERE email = %s",
				$email
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'anpa_admin_socio_not_found', 'Socio non atopado', array( 'status' => 404 ) );
		}

		// Must be active.
		if ( 'activo' !== $row['estado'] ) {
			return new WP_Error( 'anpa_admin_inactive', 'O socio non está activo', array( 'status' => 409 ) );
		}

		// Protected root guard: the master email cannot be promoted (already master).
		if ( ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() ) ) {
			return new WP_Error(
				'anpa_admin_protected_root',
				'O usuario master non se pode modificar',
				array( 'status' => 403 )
			);
		}

		// Promote.
		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_socios',
			array(
				'rol'            => 'master',
				'actualizado_en' => current_time( 'mysql' ),
			),
			array( 'email' => $email ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'admin', $email, 'grant_admin' );

		$row['rol'] = 'master';

		return new WP_REST_Response( $row, 200 );
	}

	/**
	 * DELETE /admin/admins/<email> — revoke admin from a socio.
	 *
	 * Protected root → 403 (DB untouched). Not found/not admin → 404.
	 * Self-demotion of non-root admin is allowed.
	 *
	 * @since  1.4.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revoke_admin( WP_REST_Request $request ) {
		global $wpdb;

		$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', 'Email inválido', array( 'status' => 400 ) );
		}

		// Protected root guard — DB untouched.
		if ( ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() ) ) {
			return new WP_Error(
				'anpa_admin_protected_root',
				'O administrador raíz non pode ser degradado',
				array( 'status' => 403 )
			);
		}

		// Must exist and currently be admin.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT email, nome, apelidos, estado, rol FROM {$wpdb->prefix}anpa_socios WHERE email = %s AND rol = 'master'",
				$email
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'anpa_admin_socio_not_found', 'Socio non atopado ou non é admin', array( 'status' => 404 ) );
		}

		// Demote.
		$updated = $wpdb->update(
			$wpdb->prefix . 'anpa_socios',
			array(
				'rol'            => 'socio',
				'actualizado_en' => current_time( 'mysql' ),
			),
			array( 'email' => $email ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'anpa_admin_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		ANPA_Socios_Admin_Shared::write_audit( $request, 'admin', $email, 'revoke_admin' );

		$row['rol'] = 'socio';

		return new WP_REST_Response( $row, 200 );
	}
}
