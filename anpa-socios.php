<?php
/**
 * Plugin Name: ANPA Socios
 * Description: Sistema de altas de socios — formulario público e endpoint REST (Fase 2, paso 1).
 * Version: 1.23.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ANPA As Brañas
 * License: GPL-2.0-or-later
 * Text Domain: anpa-socios
 *
 * Sibling plugin of anpa-verificacion; depends on its REST API
 * but does NOT require_once it.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANPA_SOCIOS_VERSION', '1.23.0' );
define( 'ANPA_SOCIOS_DB_VERSION', '1.19.0' );
define( 'ANPA_SOCIOS_PLUGIN_FILE', __FILE__ );
define( 'ANPA_SOCIOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-payload.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-sepa.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-normalize.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-crypto.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-banking-key.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-domiciliacion.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-codigo-generator.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-rate-limiter.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-area-session.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-master-auth.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-roles.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-config.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-flow.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-admin-payload.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-actividade-options.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-curso-fit.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-curso-escolar.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-season.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-preseason-gate.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-horario-builder.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-waitlist.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-trimestre.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-alta-payload.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-db.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-area-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-preflight-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-shared.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-socios-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-approvals-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-fillos-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-empresas-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-actividades-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-grupos-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-cursos-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-matriculas-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-admins-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-export-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-domiciliacion-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-banking-key-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-reports-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-eliminar-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-csv.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-antibot.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-alumnos-export.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-fillos-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-fillo-cursos-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-extraescolares-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-extraescolar-offers.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-season-service.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-empresa-view.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-empresa-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-area-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-header-nav.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-hub-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-extraescolares-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-unified-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-preseason-guard.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-settings.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-backup.php';

register_activation_hook( __FILE__, array( 'ANPA_Socios_DB', 'crear_tabelas' ) );
register_activation_hook( __FILE__, array( 'ANPA_Socios_Extraescolar_Offers', 'programar' ) );
register_activation_hook( __FILE__, array( 'ANPA_Socios_Season_Service', 'programar' ) );
register_deactivation_hook( __FILE__, array( 'ANPA_Socios_DB', 'desprogramar_limpeza_sesions' ) );
register_deactivation_hook( __FILE__, array( 'ANPA_Socios_Extraescolar_Offers', 'desprogramar' ) );
register_deactivation_hook( __FILE__, array( 'ANPA_Socios_Season_Service', 'desprogramar' ) );

add_action( 'rest_api_init', array( 'ANPA_Socios_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Area_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Preflight_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Admin_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Fillos_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Fillo_Cursos_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Extraescolares_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Empresa_REST', 'register_routes' ) );
add_action( ANPA_Socios_DB::CLEANUP_HOOK, array( 'ANPA_Socios_DB', 'borrar_sesions_expiradas' ) );
add_action( ANPA_Socios_Extraescolar_Offers::CRON_HOOK, array( 'ANPA_Socios_Extraescolar_Offers', 'expire_stale' ) );
add_action( ANPA_Socios_Season_Service::CRON_HOOK, array( 'ANPA_Socios_Season_Service', 'run_check' ) );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Do not show the global banking-key warning on the plugin's own Axustes/Docs
	// screens: those render the banking status inline, and during the setup
	// self-POST admin_notices fires before the page configures the key, which
	// would otherwise show a stale "not configured" warning next to "success".
	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( in_array( $current_page, array( 'anpa-socios-settings', 'anpa-socios-docs' ), true ) ) {
		return;
	}
	if ( ! ANPA_Socios_Banking_Key::is_configured() ) {
		echo '<div class="notice notice-warning"><p><strong>ANPA Socios:</strong> a clave bancaria (cifrado) aínda non está configurada. Ata configurala, non se poderán gardar datos bancarios novos. Un admin debe executar o setup da clave (POST <code>/anpa-socios/v1/admin/banking-key/setup</code>) e gardar a clave privada e o contrasinal nun lugar seguro. Ver <code>docs/BANKING-KEY.md</code>.</p></div>';
	}
} );
add_shortcode( 'anpa_socios_asociarse', array( 'ANPA_Socios_Socios_Page', 'render' ) );
add_shortcode( 'anpa_socios_area', array( 'ANPA_Socios_Area_Page', 'render' ) );
add_shortcode( 'anpa_socios_hub', array( 'ANPA_Socios_Hub_Page', 'render' ) );
add_shortcode( 'anpa_socios_area_link', array( 'ANPA_Socios_Hub_Page', 'render_area_link' ) );
add_shortcode( 'anpa_extraescolares_horario', array( 'ANPA_Socios_Extraescolares_Page', 'render' ) );
add_shortcode( 'anpa_extraescolares_ofertadas', array( 'ANPA_Socios_Extraescolares_Page', 'render_ofertadas' ) );
add_shortcode( 'anpa_socios_area_unified', array( 'ANPA_Socios_Unified_Page', 'render' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Socios_Page', 'enqueue_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Area_Page', 'enqueue_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Extraescolares_Page', 'enqueue_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Hub_Page', 'enqueue_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Unified_Page', 'enqueue_assets' ) );
ANPA_Socios_Header_Nav::register();
ANPA_Socios_Preseason_Guard::register();
ANPA_Socios_Admin_Settings::register();
