<?php
/**
 * Admin REST coordinator.
 *
 * This class is intentionally thin: it only owns the REST namespace
 * constant and delegates route registration to one handler per
 * domain (socios, fillos, empresas, actividades, matriculas).
 *
 * Permission checks, audit logging, and session helpers live in
 * `ANPA_Socios_Admin_Shared` so each handler stays focused on its
 * endpoints.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin REST surface coordinator.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_REST {

	/**
	 * REST namespace for admin endpoints.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const REST_NAMESPACE = 'anpa-socios/v1/admin';

	/**
	 * Registers all admin routes by delegating to per-domain handlers.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public static function register_routes(): void {
		ANPA_Socios_Admin_Socios_Handler::register_routes();
		ANPA_Socios_Admin_Approvals_Handler::register_routes();
		ANPA_Socios_Admin_Fillos_Handler::register_routes();
		ANPA_Socios_Admin_Empresas_Handler::register_routes();
		ANPA_Socios_Admin_Actividades_Handler::register_routes();
		ANPA_Socios_Admin_Grupos_Handler::register_routes();
		ANPA_Socios_Admin_Cursos_Handler::register_routes();
		ANPA_Socios_Admin_Matriculas_Handler::register_routes();
		ANPA_Socios_Admin_Admins_Handler::register_routes();
		ANPA_Socios_Admin_Export_Handler::register_routes();
		ANPA_Socios_Admin_Import_Handler::register_routes();
		ANPA_Socios_Admin_Iban_Import_Handler::register_routes();
		ANPA_Socios_Admin_Domiciliacion_Handler::register_routes();
		ANPA_Socios_Admin_Banking_Key_Handler::register_routes();
		ANPA_Socios_Admin_Reports_Handler::register_routes();
		ANPA_Socios_Admin_Eliminar_Handler::register_routes();
	}
}
