<?php
/**
 * Native wp-admin page for ANPA management operations.
 *
 * @since  1.32.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the existing protected management panel into wp-admin.
 *
 * The REST security boundary remains unchanged: every admin operation still
 * goes through ANPA_Socios_Admin_Shared::permission_master().
 *
 * @since 1.32.0
 */
final class ANPA_Socios_Admin_Management_Page {

	/**
	 * Native wp-admin submenu slug.
	 *
	 * @since 1.32.0
	 * @var string
	 */
	public const MANAGEMENT_SLUG = 'anpa-socios-management';

	/**
	 * Registers hooks owned by this page.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Registers the submenu entry.
	 *
	 * @param  string $parent_slug Parent menu slug.
	 * @param  string $capability  Capability required by wp-admin.
	 * @return void
	 */
	public static function register_menu( string $parent_slug, string $capability ): void {
		$page = ANPA_Socios_Admin_Nav::native_management_page();
		add_submenu_page(
			$parent_slug,
			$page['page_title'],
			$page['menu_label'],
			$capability,
			$page['slug'],
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renders the native management page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		$page = ANPA_Socios_Admin_Nav::native_management_page();
		echo '<div class="wrap anpa-management-page">';
		echo '<h1>' . esc_html( $page['page_title'] ) . '</h1>';
		echo '<p class="description" style="max-width:820px">' . esc_html__( 'Panel operativo para a directiva. Esta pantalla carga o panel de xestión existente dentro de wp-admin; as operacións seguen protexidas polo mesmo token de área, rol master e contrasinal de administración que xa usa a área pública.', 'anpa-socios' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Se aínda non tes unha sesión master aberta neste navegador, irás á páxina de socios para iniciar sesión e logo poderás volver aquí. Mantense a área pública como fallback durante a migración.', 'anpa-socios' ) . '</p></div>';
		echo ANPA_Socios_Area_Page::render( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes its own markup.
		echo '</div>';
	}

	/**
	 * Enqueues existing area/admin assets only on the native management page.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ANPA_Socios_Admin_Nav::native_management_page()['slug'] !== $page ) {
			return;
		}

		$area_js_path      = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/area.js';
		$area_css_path     = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/area.css';
		$table_path        = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/admin-table.js';
		$normalize_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/anpa-normalize.js';
		$utils_path        = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/anpa-utils.js';
		$compact_css_path  = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/admin-compact.css';
		$admin_css_version = file_exists( $compact_css_path ) ? (int) filemtime( $compact_css_path ) : ANPA_SOCIOS_VERSION;

		wp_enqueue_script( 'anpa-socios-admin-table', plugins_url( 'assets/js/admin-table.js', ANPA_SOCIOS_PLUGIN_FILE ), array(), file_exists( $table_path ) ? (int) filemtime( $table_path ) : ANPA_SOCIOS_VERSION, true );
		wp_enqueue_script( 'anpa-socios-normalize', plugins_url( 'assets/js/anpa-normalize.js', ANPA_SOCIOS_PLUGIN_FILE ), array(), file_exists( $normalize_path ) ? (int) filemtime( $normalize_path ) : ANPA_SOCIOS_VERSION, true );
		wp_enqueue_script( 'anpa-socios-utils', plugins_url( 'assets/js/anpa-utils.js', ANPA_SOCIOS_PLUGIN_FILE ), array(), file_exists( $utils_path ) ? (int) filemtime( $utils_path ) : ANPA_SOCIOS_VERSION, true );
		wp_enqueue_script( 'anpa-socios-area', plugins_url( 'assets/js/area.js', ANPA_SOCIOS_PLUGIN_FILE ), array( 'wp-i18n', 'anpa-socios-admin-table', 'anpa-socios-normalize', 'anpa-socios-utils' ), file_exists( $area_js_path ) ? (int) filemtime( $area_js_path ) : ANPA_SOCIOS_VERSION, true );
		wp_set_script_translations( 'anpa-socios-area', 'anpa-socios', ANPA_SOCIOS_PLUGIN_DIR . 'languages' );
		wp_enqueue_style( 'anpa-socios-area', plugins_url( 'assets/css/area.css', ANPA_SOCIOS_PLUGIN_FILE ), array(), file_exists( $area_css_path ) ? (int) filemtime( $area_css_path ) : ANPA_SOCIOS_VERSION );
		wp_enqueue_style( 'anpa-socios-admin-compact', plugins_url( 'assets/css/admin-compact.css', ANPA_SOCIOS_PLUGIN_FILE ), array( 'anpa-socios-area' ), $admin_css_version );
	}
}
