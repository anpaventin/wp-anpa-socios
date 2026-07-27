<?php
/**
 * Plugin Name: ANPA Socios
 * Description: Xestión de socios para asociacións de nais e pais (ANPA/AMPA): área de socios sen contrasinal, fillos e actividades extraescolares, domiciliación SEPA cifrada, ciclo de curso, panel de administración e actualizacións self-hosted. Configurable para calquera asociación.
 * Version: 1.48.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ANPA Socios
 * License: GPL-2.0-or-later
 * Text Domain: anpa-socios
 * Domain Path: /languages
 *
 * The email-verification module (formerly the standalone anpa-verificacion
 * plugin) is now built in; no companion plugin is required.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANPA_SOCIOS_VERSION', '1.48.0' );
define( 'ANPA_SOCIOS_DB_VERSION', '1.41.0' );
define( 'ANPA_SOCIOS_PLUGIN_FILE', __FILE__ );
define( 'ANPA_SOCIOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-payload.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-sepa.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-normalize.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-familia.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-crypto.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-banking-key.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-domiciliacion.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-codigo-generator.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-rate-limiter.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-area-session.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-roles.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-admin-auth.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-config.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-flow.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-admin-payload.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-actividade-options.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-disponibilidade-horaria.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-grupo-comedor-gate.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-estrutura-escolar.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-curso-fit.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-grupo-niveis.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-grupo-serie.php';

// WP-CLI commands (only loaded when running from CLI).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-cleanup-estrutura-cli.php';
}
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-prazas.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-curso-escolar.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-nivel-promotion.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-grupos-horarios.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-curso-lifecycle.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-calendario.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-trimestre-estado.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-ventana-estado.php';
// fase35: email queue pure domain (value objects + policies).
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-campaign-state.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-recipient-state.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-backoff.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-recipients.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-batch-planner.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-retention.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-renderer.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-registry-error.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-phase.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-variable.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-definition.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-set.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-registry.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-events.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-defaults.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-context.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-editorial.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-actions.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-validator.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-scenarios.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-nesting-guard.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-preview-context.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-html-policy.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-stored-custom-template.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-email-template-packaged-default.php';
// fase36 I16: reusable failure policy for $wpdb writes.
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-db-write-policy.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-course-settings.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-season.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-preseason-gate.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-horario-builder.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-waitlist.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-trimestre.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-alta-payload.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-actividades-collapse.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-activity-group-projection.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-db.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-curso-activo.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-trimestre-repo.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-nivel-promotion-service.php';
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
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-export-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-import-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-iban-import-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-domiciliacion-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-banking-key-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-reports-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-eliminar-handler.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-csv.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-csv-import.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-antibot.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-alumnos-export.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-fillos-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-fillo-cursos-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-extraescolares-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-extraescolar-offers.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-season-service.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-template-sanitizer.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-template-repo.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-render-provider.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-queue-repo.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-queue.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-processor.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-admin-actions.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-template-admin-actions.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-purge.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-cron.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-communications-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-email-templates-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-empresa-view.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-empresa-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-area-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-hub-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-extraescolares-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-unified-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-preseason-guard.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-admin-nav.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-verificacion-guard.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-verificacion-rest.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-settings.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-management-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-estrutura-escolar-page.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-estrutura-handler.php';

require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-backup.php';
require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-updater.php';

register_activation_hook( __FILE__, array( 'ANPA_Socios_DB', 'crear_tabelas' ) );
register_activation_hook( __FILE__, array( 'ANPA_Socios_Extraescolar_Offers', 'programar' ) );
register_activation_hook( __FILE__, array( 'ANPA_Socios_Season_Service', 'programar' ) );
register_activation_hook( __FILE__, array( 'ANPA_Socios_Email_Cron', 'schedule' ) );

// fase35: custom 5-min recurrence for the email queue tick (filterable, bounded).
add_filter( 'cron_schedules', array( 'ANPA_Socios_Email_Cron', 'add_schedule' ) );
add_action( ANPA_Socios_Email_Cron::HOOK, array( 'ANPA_Socios_Email_Cron', 'tick' ) );
// fase35: daily retention pass (purges payload first, then minimal metadata).
add_action( ANPA_Socios_Email_Cron::PURGE_HOOK, array( 'ANPA_Socios_Email_Cron', 'purge_tick' ) );
// Recover the schedule if the event disappears (idempotent).
add_action( 'admin_init', array( 'ANPA_Socios_Email_Cron', 'ensure_scheduled' ) );
// fase35: admin write actions (process now / pause / resume / cancel / retry).
// Capability + nonce are checked inside every handler; no nopriv variants.
ANPA_Socios_Email_Admin_Actions::register();
// fase36: template admin write actions (save / preview / test send / restore / adopt).
ANPA_Socios_Email_Template_Admin_Actions::register();

// Run schema migrations on plugin update (activation hook does not fire on auto-update).
add_action( 'admin_init', static function () {
	$installed = (string) get_option( ANPA_Socios_DB::VERSION_OPTION, '0.0.0' );
	if ( version_compare( $installed, ANPA_Socios_DB::DB_VERSION, '<' ) ) {
		ANPA_Socios_DB::crear_tabelas();
	}
} );

// fase36: seed any missing email template from its shipped default. SEPARATE from the migration on
// purpose — a schema step that failed halfway must never leave half a catalogue behind — and
// idempotent: it only ever writes rows that do not exist, so a board's customised wording is never
// overwritten by an update.
//
// Gated on the plugin version rather than run on every request. New events only arrive with a new
// version, so the common case costs one option read instead of a table scan on every admin page.
add_action( 'admin_init', static function () {
	if ( ! class_exists( 'ANPA_Socios_Email_Template_Repo' ) ) {
		return;
	}

	$option = 'anpa_socios_email_templates_seeded';
	if ( ANPA_SOCIOS_VERSION === (string) get_option( $option, '' ) ) {
		return;
	}

	$result = ANPA_Socios_Email_Template_Repo::seed_missing( 'system' );
	if ( $result['ok'] ) {
		update_option( $option, ANPA_SOCIOS_VERSION, false );
		return;
	}

	// A declared event with no shipped default is a packaging bug. Do NOT record the version, so the
	// next request tries again and the problem stays visible instead of being marked done.
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( '[anpa-socios] Email templates missing shipped defaults: ' . implode( ', ', $result['missing_defaults'] ) );
}, 20 );
register_deactivation_hook( __FILE__, array( 'ANPA_Socios_DB', 'desprogramar_limpeza_sesions' ) );
register_deactivation_hook( __FILE__, array( 'ANPA_Socios_Extraescolar_Offers', 'desprogramar' ) );
register_deactivation_hook( __FILE__, array( 'ANPA_Socios_Season_Service', 'desprogramar' ) );
// fase35: cancel the email queue tick on deactivation (never deletes data).
register_deactivation_hook( __FILE__, array( 'ANPA_Socios_Email_Cron', 'unschedule' ) );

add_action( 'rest_api_init', array( 'ANPA_Socios_REST', 'register_routes' ) );
// fase13b: serve the anpa/v1 verification routes ourselves, but only when the
// legacy anpa-verificacion plugin is NOT active (avoids double registration).
if ( ANPA_Socios_Verificacion_Guard::should_register( defined( 'ANPA_VERIFICACION_VERSION' ) ) ) {
	add_action( 'rest_api_init', array( 'ANPA_Socios_Verificacion_REST', 'register_routes' ) );
}
add_action( 'rest_api_init', array( 'ANPA_Socios_Area_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Preflight_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Admin_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Fillos_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Fillo_Cursos_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Extraescolares_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Empresa_REST', 'register_routes' ) );
add_action( 'rest_api_init', array( 'ANPA_Socios_Admin_Estrutura_Handler', 'register_routes' ) );

add_action( ANPA_Socios_DB::CLEANUP_HOOK, array( 'ANPA_Socios_DB', 'borrar_sesions_expiradas' ) );
add_action( ANPA_Socios_Extraescolar_Offers::CRON_HOOK, array( 'ANPA_Socios_Extraescolar_Offers', 'expire_stale' ) );
add_action( ANPA_Socios_Season_Service::CRON_HOOK, array( 'ANPA_Socios_Season_Service', 'run_check' ) );

// fase34: persistent admin notice for trimesters that reached their operative
// close date but are still pending a manual transition.
add_action( 'admin_notices', array( 'ANPA_Socios_Season_Service', 'render_admin_notice' ) );

add_shortcode( 'anpa_socios_asociarse', array( 'ANPA_Socios_Socios_Page', 'render' ) );
add_shortcode( 'anpa_socios_area_persoal', array( 'ANPA_Socios_Area_Page', 'render' ) );
add_shortcode( 'anpa_socios_hub', array( 'ANPA_Socios_Hub_Page', 'render' ) );
add_shortcode( 'anpa_socios_area_link', array( 'ANPA_Socios_Hub_Page', 'render_area_link' ) );
add_shortcode( 'anpa_extraescolares_horario', array( 'ANPA_Socios_Extraescolares_Page', 'render' ) );
add_shortcode( 'anpa_extraescolares_ofertadas', array( 'ANPA_Socios_Extraescolares_Page', 'render_ofertadas' ) );
add_shortcode( 'anpa_socios_area', array( 'ANPA_Socios_Unified_Page', 'render' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Socios_Page', 'enqueue_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Area_Page', 'enqueue_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Extraescolares_Page', 'enqueue_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Hub_Page', 'enqueue_assets' ) );
add_action( 'wp_enqueue_scripts', array( 'ANPA_Socios_Unified_Page', 'enqueue_assets' ) );
ANPA_Socios_Preseason_Guard::register();
ANPA_Socios_Admin_Settings::register();
ANPA_Socios_Admin_Management_Page::register();

// Self-hosted updates from the public Gitea repo (read-only, GET-based).
ANPA_Socios_Updater::init();

// Internationalisation: the plugin follows the WordPress site language
// (Axustes → Xerais no WordPress). No custom language setting. Translations
// ship as .mo files under /languages named `anpa-socios-{locale}.mo`
// (Galician is the source language). WordPress loads the right one for the
// active site locale.
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'anpa-socios', false, dirname( plugin_basename( ANPA_SOCIOS_PLUGIN_FILE ) ) . '/languages' );
	}
);
