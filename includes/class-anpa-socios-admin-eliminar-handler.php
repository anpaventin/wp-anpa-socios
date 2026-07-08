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
 * WARNING: this handler permanently removes a socio and all associated
 * data (fillos, matriculas, domiciliacions, sesions). The action is
 * irreversible and audited server-side. Use the two-step UI confirm.
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
	 * Permanently removes a socio and all related records. The root master
	 * account is protected and cannot be deleted.
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

		// Verify the socio exists.
		$socios_table = $wpdb->prefix . 'anpa_socios';
		$socio        = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$socios_table} WHERE email = %s", $email ) );
		if ( null === $socio ) {
			return new WP_Error( 'anpa_admin_socio_not_found', __( 'Socio non atopado', 'anpa-socios' ), array( 'status' => 404 ) );
		}

		$fillos_table       = $wpdb->prefix . 'anpa_fillos';
		$matriculas_table   = $wpdb->prefix . 'anpa_matriculas';
		$fillos_cursos_table = ANPA_Socios_DB::tabela_fillos_cursos();
		$domiciliacions_table = ANPA_Socios_DB::tabela_domiciliacions();
		$sesions_table      = ANPA_Socios_DB::tabela_sesions();

		// Collect fillo IDs for cascade.
		$fillo_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$fillos_table} WHERE socio_email = %s", $email ) );

		if ( ! empty( $fillo_ids ) ) {
			$ids_placeholder = implode( ',', array_fill( 0, count( $fillo_ids ), '%d' ) );

			// Delete matriculas.
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$matriculas_table} WHERE fillo_id IN ({$ids_placeholder})",
				$fillo_ids
			) );

			// Delete fillos_cursos.
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$fillos_cursos_table} WHERE fillo_id IN ({$ids_placeholder})",
				$fillo_ids
			) );

			// Delete fillos.
			$wpdb->delete( $fillos_table, array( 'socio_email' => $email ), array( '%s' ) );
		}

		// Delete domiciliacions.
		$wpdb->delete( $domiciliacions_table, array( 'socio_email' => $email ), array( '%s' ) );

		// Delete sesions.
		$wpdb->delete( $sesions_table, array( 'socio_email' => $email ), array( '%s' ) );

		// Delete the socio row.
		$wpdb->delete( $socios_table, array( 'email' => $email ), array( '%s' ) );

		// Audit trail (write AFTER deletion).
		ANPA_Socios_Admin_Shared::write_audit_actor(
			(string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_EMAIL ),
			(string) $request->get_param( ANPA_Socios_Admin_Shared::REQ_PARAM_ROL ),
			'eliminar_socio',
			$email,
			'delete'
		);

		return new WP_REST_Response( array(
			'message'  => 'Socio/a e todos os seus datos foron eliminados permanentemente.',
			'email'    => $email,
			'fillos'   => count( $fillo_ids ),
		), 200 );
	}
}
