<?php
/**
 * Native wp-admin page for ANPA management operations.
 *
 * Renders the "Xestión ANPA" submenu page with section navigation and
 * enqueues admin-management.js which drives the UI via WP REST + nonce.
 *
 * @since  1.33.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native wp-admin management page (post-fase17 rewrite).
 *
 * @since 1.33.0
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
		$hook = add_submenu_page(
			$parent_slug,
			esc_html__( 'Xestión', 'anpa-socios' ),
			esc_html__( 'Xestión', 'anpa-socios' ),
			$capability,
			self::MANAGEMENT_SLUG,
			array( __CLASS__, 'render_page' )
		);
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'maybe_redirect_to_setup' ) );
		}
	}

	/**
	 * Redirects clean installations to the setup wizard before output starts.
	 *
	 * @since  1.34.0
	 * @return void
	 */
	public static function maybe_redirect_to_setup(): void {
		if ( ANPA_Socios_Admin_Nav::can_access_management( ANPA_Socios_Banking_Key::is_configured() ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=anpa-socios-settings' ) );
		exit;
	}

	/**
	 * Renders the native management page.
	 *
	 * Outputs navigation tabs and an empty container that admin-management.js
	 * populates via REST API calls with X-WP-Nonce authentication.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		$groups         = ANPA_Socios_Admin_Nav::management_sections();
		$active_section = self::active_section_from_request();

		echo '<div class="wrap anpa-mgmt-wrap">';
		echo '<h1>' . esc_html__( 'Xestión', 'anpa-socios' ) . '</h1>';
		echo '<div id="anpa-mgmt-message" class="anpa-mgmt-message"></div>';

		// Section navigation grouped by domain.
		echo '<nav class="anpa-mgmt-nav" aria-label="' . esc_attr__( 'Seccións de xestión', 'anpa-socios' ) . '">';
		foreach ( $groups as $group_slug => $group ) {
			$group_id    = 'anpa-mgmt-nav-group-' . $group_slug;
			$group_label = isset( $group['label'] ) ? (string) $group['label'] : ucfirst( (string) $group_slug );
			$sections    = isset( $group['sections'] ) && is_array( $group['sections'] ) ? $group['sections'] : array();

			echo '<section class="anpa-mgmt-nav-group" aria-labelledby="' . esc_attr( $group_id ) . '">';
			echo '<h2 id="' . esc_attr( $group_id ) . '" class="anpa-mgmt-nav-group-title">' . esc_html( $group_label ) . '</h2>';
			echo '<div class="anpa-mgmt-nav-buttons" role="tablist" aria-label="' . esc_attr( $group_label ) . '">';
			foreach ( $sections as $slug => $label ) {
				printf(
					'<button type="button" role="tab" data-section="%s" aria-controls="anpa-management-root" aria-selected="%s">%s</button>',
					esc_attr( $slug ),
					$active_section === $slug ? 'true' : 'false',
					esc_html( (string) $label )
				);
			}
			echo '</div>';
			echo '</section>';
		}
		echo '</nav>';

		echo '<div id="anpa-management-root" tabindex="-1"></div>';
		echo '</div>';
	}

	/**
	 * Enqueues assets only on the native management page.
	 *
	 * Loads admin-table.js, anpa-utils.js, admin-management.js and
	 * admin-management.css. Does NOT load area.js.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ANPA_Socios_Admin_Nav::native_management_page()['slug'] !== $page ) {
			return;
		}

		$plugin_url  = plugins_url( '', ANPA_SOCIOS_PLUGIN_FILE );
		$plugin_dir  = ANPA_SOCIOS_PLUGIN_DIR;

		// admin-table.js (sort + pagination helpers).
		$table_path = $plugin_dir . 'assets/js/admin-table.js';
		wp_enqueue_script(
			'anpa-socios-admin-table',
			$plugin_url . '/assets/js/admin-table.js',
			array(),
			file_exists( $table_path ) ? (string) filemtime( $table_path ) : ANPA_SOCIOS_VERSION,
			true
		);

		// anpa-utils.js (labels, filter, CSV helpers).
		$utils_path = $plugin_dir . 'assets/js/anpa-utils.js';
		wp_enqueue_script(
			'anpa-socios-utils',
			$plugin_url . '/assets/js/anpa-utils.js',
			array(),
			file_exists( $utils_path ) ? (string) filemtime( $utils_path ) : ANPA_SOCIOS_VERSION,
			true
		);

		// admin-management.js (main page driver).
		$mgmt_path = $plugin_dir . 'assets/js/admin-management.js';
		wp_enqueue_script(
			'anpa-socios-admin-management',
			$plugin_url . '/assets/js/admin-management.js',
			array( 'wp-i18n', 'anpa-socios-admin-table', 'anpa-socios-utils' ),
			file_exists( $mgmt_path ) ? (string) filemtime( $mgmt_path ) : ANPA_SOCIOS_VERSION,
			true
		);

		// Localize: REST root + nonce + dynamic estrutura data.
		global $wpdb;
		$curso_escolar = ANPA_Socios_Curso_Escolar::current();
		$niveis = ANPA_Socios_DB::get_niveis_for_curso( $curso_escolar );
		$nivel_ids = array();
		foreach ( $niveis as $n ) {
			$nivel_ids[] = (int) $n['id'];
		}
		$aulas = ANPA_Socios_DB::get_aulas_for_niveis( $nivel_ids );
		$cursos_escolares = $wpdb->get_col(
			'SELECT curso_escolar FROM ' . ANPA_Socios_DB::tabela_cursos() . ' ORDER BY curso_escolar DESC'
		);
		$cursos_escolares = is_array( $cursos_escolares ) ? array_values( array_unique( array_map( 'strval', $cursos_escolares ) ) ) : array();
		$curso_activo      = ANPA_Socios_Curso_Activo::get();

		wp_localize_script( 'anpa-socios-admin-management', 'anpaAdminMgmt', array(
			'root'       => esc_url_raw( rest_url( 'anpa-socios/v1/admin/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'filloniveis' => $niveis,
			'filloaulas'  => $aulas,
			'cursosescolares' => $cursos_escolares,
			'cursoactivo' => null === $curso_activo ? '' : $curso_activo,
			'section'     => self::active_section_from_request(),
		) );

		// admin-management.css.
		$css_path = $plugin_dir . 'assets/css/admin-management.css';
		wp_enqueue_style(
			'anpa-socios-admin-management',
			$plugin_url . '/assets/css/admin-management.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : ANPA_SOCIOS_VERSION
		);
	}

	/**
	 * Resolves the current management deep-link without exposing aliases to UI.
	 *
	 * @return string
	 */
	private static function active_section_from_request(): string {
		$requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return ANPA_Socios_Admin_Nav::active_management_section( $requested );
	}
}
