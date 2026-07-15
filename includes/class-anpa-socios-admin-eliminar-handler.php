<?php
/**
 * Admin REST handler for complete socio deletion.
 *
 * @since  1.21.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for socio deletion (admin/master only).
 *
 * This handler permanently removes a socio only after baixa and only when
 * there are no family, pupil, enrolment or banking records to preserve.
 *
 * @since 1.21.0
 */
final class ANPA_Socios_Admin_Eliminar_Handler {

	/**
	 * Registers the delete socio route.
	 *
	 * @since  1.21.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/socio/(?P<email>[^/]+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'eliminar_socio' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * DELETE /admin/socio/<email>
	 *
	 * Permanently removes an already-disabled socio with no associated data.
	 * The root master account is protected and cannot be deleted.
	 *
	 * @since  1.21.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function eliminar_socio( WP_REST_Request $request ) {
		global $wpdb;

		$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $request->get_param( 'email' ) ) );
		if ( null === $email ) {
			return new WP_Error( 'anpa_admin_invalid', __( 'Email inválido', 'anpa-socios' ), array( 'status' => 400 ) );
		}

		// Protect the root master account.
		if ( ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() ) ) {
			return new WP_Error( 'anpa_admin_protected_root',
				'O administrador raíz non pode ser eliminado',
				array( 'status' => 403 ) );
		}

		// Verify the socio exists and is already disabled.
		$socios_table = ANPA_Socios_DB::tabela_socios();
		$socio        = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, email, estado, rol, familia_id FROM {$socios_table} WHERE email = %s", $email ),
			ARRAY_A
		);
		if ( ! is_array( $socio ) ) {
			return new WP_Error( 'anpa_admin_socio_not_found', __( 'Socio non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}
		if ( 'baixa' !== $socio['estado'] ) {
			return new WP_Error( 'anpa_admin_must_deactivate', __( 'Desactiva o socio/a antes de eliminalo definitivamente.', 'anpa-socios' ), array( 'status' => 409 ) );
		}
		if ( 'master' === $socio['rol'] ) {
			return new WP_Error( 'anpa_admin_master_delete', __( 'Non se pode eliminar un administrador.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$familia_id           = ! empty( $socio['familia_id'] ) ? (int) $socio['familia_id'] : (int) $socio['id'];
		$fillos_table         = ANPA_Socios_DB::tabela_fillos();
		$domiciliacions_table = ANPA_Socios_DB::tabela_domiciliacions();
		$sesions_table        = ANPA_Socios_DB::tabela_sesions();

		$family_refs = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$socios_table} WHERE id <> %d AND COALESCE(NULLIF(familia_id, 0), id) = %d",
			(int) $socio['id'],
			$familia_id
		) );
		if ( $family_refs > 0 ) {
			return new WP_Error( 'anpa_admin_socio_has_family', __( 'Non se pode eliminar: hai outro proxenitor asociado á familia.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$fillo_refs = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$fillos_table} WHERE socio_email = %s OR familia_id = %d",
			$email,
			$familia_id
		) );
		if ( $fillo_refs > 0 ) {
			return new WP_Error( 'anpa_admin_socio_has_fillos', __( 'Non se pode eliminar: o socio/a ten fillos ou datos escolares asociados.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		$bank_refs = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$domiciliacions_table} WHERE familia_id = %d",
			$familia_id
		) );
		if ( $bank_refs > 0 ) {
			return new WP_Error( 'anpa_admin_socio_has_domiciliacion', __( 'Non se pode eliminar: o socio/a ten unha domiciliación asociada.', 'anpa-socios' ), array( 'status' => 409 ) );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->delete( $sesions_table, array( 'email' => $email ), array( '%s' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}
		$deleted = $wpdb->delete( $socios_table, array( 'email' => $email ), array( '%s' ) );
		if ( 1 !== $deleted || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_admin_db_error', __( 'Erro interno', 'anpa-socios' ), array( 'status' => 500 ) );
		}

		// Audit trail (write AFTER deletion).
		ANPA_Socios_Admin_Shared::write_audit_actor(
			(string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_EMAIL ),
			(string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_ROL ),
			'eliminar_socio',
			$email,
			'delete'
		);

		return new WP_REST_Response( array(
			'message'  => 'Socio/a eliminado permanentemente.',
			'email'    => $email,
		), 200 );
	}
}
