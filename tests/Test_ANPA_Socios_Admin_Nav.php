<?php
/**
 * Unit tests for the admin navigation/subsection helper (fase17a).
 *
 * @since  1.32.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['anpa_socios_admin_nav_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return true;
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( ...$args ) {
		$GLOBALS['anpa_socios_admin_nav_menu_calls'][] = $args;

		return 'toplevel_page_' . (string) $args[3];
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( ...$args ) {
		$GLOBALS['anpa_socios_admin_nav_submenu_calls'][] = $args;

		return false;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-management-page.php';
require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-settings.php';

class Test_ANPA_Socios_Admin_Nav extends TestCase {

	public function test_settings_tabs_are_grouped_without_maintenance_children_as_top_level(): void {
		$this->assertSame(
			array( 'xeral', 'cursos', 'localizacion', 'actualizacions' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_tabs() )
		);

		$this->assertTrue( ANPA_Socios_Admin_Nav::is_settings_tab( 'xeral' ) );
		$this->assertTrue( ANPA_Socios_Admin_Nav::is_settings_tab( 'cursos' ) );
		$this->assertFalse( ANPA_Socios_Admin_Nav::is_settings_tab( 'verificacion' ) );
		$this->assertTrue( ANPA_Socios_Admin_Nav::is_settings_tab( 'actualizacions' ) );
		$this->assertFalse( ANPA_Socios_Admin_Nav::is_settings_tab( 'mantemento' ) );
	}

	public function test_legacy_settings_tabs_helper_is_not_loaded_by_bootstrap_anymore(): void {
		$this->assertFalse( class_exists( 'ANPA_Socios_Settings_Tabs', false ) );

		$plugin_bootstrap = file_get_contents( dirname( __DIR__ ) . '/anpa-socios.php' );
		$test_bootstrap   = file_get_contents( dirname( __DIR__ ) . '/tests/bootstrap.php' );

		$this->assertStringNotContainsString( 'includes/lib/class-anpa-socios-settings-tabs.php', $plugin_bootstrap );
		$this->assertStringNotContainsString( 'includes/lib/class-anpa-socios-settings-tabs.php', $test_bootstrap );
	}

	public function test_settings_sections_are_ordered_by_tab(): void {
		$this->assertSame(
			array( 'estado', 'mantemento', 'configuracion', 'paxinas' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'xeral' ) )
		);
		$this->assertSame(
			array( 'curso-escolar', 'crear-novo', 'estrutura' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'cursos' ) )
		);
		$this->assertSame(
			array(),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'mantemento' ) )
		);
		$this->assertSame( array(), ANPA_Socios_Admin_Nav::settings_sections( 'actualizacions' ) );
	}

	public function test_active_settings_tab_and_section_fall_back_safely(): void {
		$this->assertSame( 'xeral', ANPA_Socios_Admin_Nav::active_settings_tab( null ) );
		$this->assertSame( 'xeral', ANPA_Socios_Admin_Nav::active_settings_tab( 'verificacion' ) );
		$this->assertSame( 'actualizacions', ANPA_Socios_Admin_Nav::active_settings_tab( 'actualizacions' ) );
		$this->assertSame( 'xeral', ANPA_Socios_Admin_Nav::active_settings_tab( 'mantemento' ) );

		$this->assertSame( 'estado', ANPA_Socios_Admin_Nav::active_settings_section( 'xeral', null ) );
		$this->assertSame( 'estado', ANPA_Socios_Admin_Nav::active_settings_section( 'xeral', 'verificacion' ) );
		$this->assertSame( 'curso-escolar', ANPA_Socios_Admin_Nav::active_settings_section( 'cursos', 'curso-escolar' ) );
		$this->assertSame( '', ANPA_Socios_Admin_Nav::active_settings_section( 'mantemento', 'verificacion' ) );
		$this->assertSame( '', ANPA_Socios_Admin_Nav::active_settings_section( 'mantemento', 'unknown' ) );
	}

	public function test_management_sections_are_grouped_by_domain_and_hide_removed_visible_slugs(): void {
		$sections = ANPA_Socios_Admin_Nav::management_sections();

		$this->assertSame( array( 'socios', 'extraescolares', 'operacions' ), array_keys( $sections ) );
		$this->assertSame( 'Socios', $sections['socios']['label'] );
		$this->assertSame( 'Extraescolares', $sections['extraescolares']['label'] );
		$this->assertSame( 'Operacións', $sections['operacions']['label'] );

		$this->assertSame( array( 'socios', 'aprobacions', 'fillos', 'empresas' ), array_keys( $sections['socios']['sections'] ) );
		$this->assertSame( array( 'actividades', 'grupos-horarios', 'matriculas' ), array_keys( $sections['extraescolares']['sections'] ) );
		$this->assertSame( array( 'importar-listados', 'auditoria' ), array_keys( $sections['operacions']['sections'] ) );

		$visible_slugs = array();
		foreach ( $sections as $group ) {
			$visible_slugs = array_merge( $visible_slugs, array_keys( $group['sections'] ) );
		}

		$this->assertSame(
			array( 'socios', 'aprobacions', 'fillos', 'empresas', 'actividades', 'grupos-horarios', 'matriculas', 'importar-listados', 'auditoria' ),
			$visible_slugs
		);
		$this->assertNotContains( 'inicio', $visible_slugs );
		$this->assertNotContains( 'curso-activo', $visible_slugs );
		$this->assertNotContains( 'cursos-matriculas', $visible_slugs );
		$this->assertNotContains( 'listados', $visible_slugs );
	}

	public function test_management_page_renders_group_labels_and_visible_buttons_in_order(): void {
		ob_start();
		ANPA_Socios_Admin_Management_Page::render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<nav class="anpa-mgmt-nav" aria-label="Seccións de xestión">', $html );
		$this->assertStringContainsString( '<section class="anpa-mgmt-nav-group" aria-labelledby="anpa-mgmt-nav-group-socios">', $html );
		$this->assertStringContainsString( '<section class="anpa-mgmt-nav-group" aria-labelledby="anpa-mgmt-nav-group-extraescolares">', $html );
		$this->assertStringContainsString( '<section class="anpa-mgmt-nav-group" aria-labelledby="anpa-mgmt-nav-group-operacions">', $html );
		$this->assertStringContainsString( '<h2 id="anpa-mgmt-nav-group-socios" class="anpa-mgmt-nav-group-title">Socios</h2>', $html );
		$this->assertStringContainsString( '<h2 id="anpa-mgmt-nav-group-extraescolares" class="anpa-mgmt-nav-group-title">Extraescolares</h2>', $html );
		$this->assertStringContainsString( '<h2 id="anpa-mgmt-nav-group-operacions" class="anpa-mgmt-nav-group-title">Operacións</h2>', $html );
		$this->assertStringContainsString( 'data-section="grupos-horarios"', $html );
		$this->assertStringContainsString( 'data-section="matriculas"', $html );
		$this->assertStringNotContainsString( 'data-section="inicio"', $html );
		$this->assertStringNotContainsString( 'data-section="cursos-matriculas"', $html );
		$this->assertStringNotContainsString( 'Cursos e matrículas', $html );

		$this->assertLessThan( strpos( $html, 'data-section="aprobacions"' ), strpos( $html, 'data-section="socios"' ) );
		$this->assertLessThan( strpos( $html, 'data-section="fillos"' ), strpos( $html, 'data-section="aprobacions"' ) );
		$this->assertLessThan( strpos( $html, 'data-section="empresas"' ), strpos( $html, 'data-section="fillos"' ) );
		$this->assertLessThan( strpos( $html, 'data-section="grupos-horarios"' ), strpos( $html, 'data-section="actividades"' ) );
		$this->assertLessThan( strpos( $html, 'data-section="matriculas"' ), strpos( $html, 'data-section="grupos-horarios"' ) );
		$this->assertLessThan( strpos( $html, 'data-section="auditoria"' ), strpos( $html, 'data-section="importar-listados"' ) );
	}

	public function test_management_css_has_focus_and_mobile_rules_without_important(): void {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/admin-management.css' );

		$this->assertStringContainsString( '.anpa-mgmt-nav button:focus-visible', $css );
		$this->assertStringContainsString( '.anpa-mgmt-nav-group', $css );
		$this->assertStringContainsString( '@media (max-width: 782px)', $css );
		$this->assertStringNotContainsString( '!important', $css );
	}

	public function test_admin_management_js_uses_visible_matriculas_and_grupos_horarios_slugs(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-management.js' );

		$this->assertStringContainsString( "'matriculas': loadCursos", $js );
		$this->assertStringContainsString( "'grupos-horarios': loadGruposHorarios", $js );
		$this->assertStringContainsString( "'cursos-matriculas': 'matriculas'", $js );
		$this->assertStringNotContainsString( "'cursos-matriculas': loadCursos", $js );
	}

	public function test_matriculas_renderer_has_no_course_lifecycle_writes(): void {
		$js    = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-management.js' );
		$start = strpos( $js, 'function loadCursos()' );
		$end   = strpos( $js, '// ── Section: Auditoría', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$renderer = substr( $js, $start, $end - $start );

		$this->assertStringNotContainsString( "anpaAdminFetch('curso', { method: 'PUT'", $renderer );
		$this->assertStringNotContainsString( 'Pechar matrículas', $renderer );
		$this->assertStringNotContainsString( 'Desactivar curso', $renderer );
		$this->assertStringNotContainsString( 'Activar curso seleccionado', $renderer );
		$this->assertStringContainsString( 'admin.php?page=anpa-socios-settings&tab=cursos', $renderer );
		$this->assertStringContainsString( "anpaAdminFetch('matriculas?curso='", $renderer );
	}

	public function test_pr26s3_promotes_updates_and_moves_course_controls_to_settings(): void {
		$settings = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-settings.php' );

		$this->assertStringContainsString( "case 'actualizacions':", $settings );
		$this->assertStringContainsString( 'self::render_subsection_actualizacions(', $settings );
		$this->assertStringContainsString( "'tab' => 'actualizacions'", $settings );
		$this->assertStringContainsString( "'xeral' === \$requested_tab && 'actualizacions' === \$requested_section", $settings, 'legacy xeral section must be recognised' );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $settings, 'Xeral Estado must show the canonical active course' );
		$this->assertStringContainsString( 'name="estado"', $settings );
		$this->assertStringContainsString( 'name="matriculas_abertas"', $settings );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Cursos_Handler::update_curso', $settings, 'admin-post must delegate to the canonical lifecycle service' );
		$this->assertStringContainsString( 'Horario de comedor', $settings );
		$this->assertStringNotContainsString( 'Xestión ANPA → Cursos e matrículas', $settings );
	}

	public function test_native_management_page_metadata_is_available(): void {
		$this->assertSame(
			array(
				'slug'       => 'anpa-socios-management',
				'menu_label' => 'Xestión ANPA',
				'page_title' => 'Xestión ANPA',
			),
			ANPA_Socios_Admin_Nav::native_management_page()
		);
	}

	public function test_plugin_submenus_are_ordered_without_duplicate_parent_entry(): void {
		$this->assertSame(
			array(
				'management' => array(
					'slug'       => 'anpa-socios-management',
					'menu_label' => 'Xestión ANPA',
					'page_title' => 'Xestión ANPA',
				),
				'settings' => array(
					'slug'       => 'anpa-socios-settings',
					'menu_label' => 'Axustes',
					'page_title' => 'Axustes Xestión ANPA',
				),
				'documentation' => array(
					'slug'       => 'anpa-socios-docs',
					'menu_label' => 'Documentación',
					'page_title' => 'Documentación Xestión ANPA',
				),
			),
			ANPA_Socios_Admin_Nav::plugin_submenus()
		);
	}

	/**
	 * The top-level admin menu now uses the configurable sidebar label and
	 * renders the operational management page directly. The management, Axustes
	 * and Documentación submenu entries stay in that order under the new top
	 * level.
	 */
	public function test_register_menu_uses_configurable_sidebar_label_and_management_callback(): void {
		$settings   = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-settings.php' );
		$management = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-management-page.php' );

		$this->assertMatchesRegularExpression(
			"/add_menu_page\\(\\s*ANPA_Socios_Config::menu_name\\(\\),\\s*ANPA_Socios_Config::menu_name\\(\\),\\s*self::CAP,\\s*ANPA_Socios_Admin_Management_Page::MANAGEMENT_SLUG,\\s*array\\( ANPA_Socios_Admin_Management_Page::class, 'render_page' \\)/s",
			$settings
		);
		$this->assertStringContainsString(
			"ANPA_Socios_Admin_Management_Page::register_menu( ANPA_Socios_Admin_Management_Page::MANAGEMENT_SLUG, self::CAP );",
			$settings
		);
		$this->assertStringContainsString(
			"sprintf( esc_html__( 'Axustes — %s', 'anpa-socios' ), ANPA_Socios_Config::menu_name() )",
			$settings
		);
		$this->assertStringContainsString(
			"sprintf( esc_html__( 'Documentación — %s', 'anpa-socios' ), ANPA_Socios_Config::menu_name() )",
			$settings
		);
		$this->assertStringContainsString( "ANPA_Socios_Config::menu_name()", $management );
		$this->assertStringContainsString( "array( __CLASS__, 'render_page' )", $management );
		$this->assertStringContainsString( "self::MANAGEMENT_SLUG", $management );

		$management_pos = strpos( $settings, "ANPA_Socios_Admin_Management_Page::register_menu( ANPA_Socios_Admin_Management_Page::MANAGEMENT_SLUG, self::CAP );" );
		$settings_pos   = strpos( $settings, "sprintf( esc_html__( 'Axustes — %s', 'anpa-socios' ), ANPA_Socios_Config::menu_name() )" );
		$docs_pos       = strpos( $settings, "sprintf( esc_html__( 'Documentación — %s', 'anpa-socios' ), ANPA_Socios_Config::menu_name() )" );

		$this->assertNotFalse( $management_pos );
		$this->assertNotFalse( $settings_pos );
		$this->assertNotFalse( $docs_pos );
		$this->assertLessThan( $settings_pos, $management_pos );
		$this->assertLessThan( $docs_pos, $settings_pos );
	}

	public function test_register_menu_emits_the_final_wordpress_menu_contract(): void {
		$GLOBALS['anpa_socios_admin_nav_options']       = array();
		$GLOBALS['anpa_socios_admin_nav_menu_calls']    = array();
		$GLOBALS['anpa_socios_admin_nav_submenu_calls'] = array();

		ANPA_Socios_Admin_Settings::register_menu();

		$this->assertCount( 1, $GLOBALS['anpa_socios_admin_nav_menu_calls'] );
		$this->assertSame(
			array(
				'Xestión ANPA',
				'Xestión ANPA',
				'manage_options',
				'anpa-socios-management',
				array( ANPA_Socios_Admin_Management_Page::class, 'render_page' ),
				'dashicons-groups',
				58,
			),
			$GLOBALS['anpa_socios_admin_nav_menu_calls'][0]
		);

		$this->assertSame(
			array( 'anpa-socios-management', 'anpa-socios-settings', 'anpa-socios-docs' ),
			array_column( $GLOBALS['anpa_socios_admin_nav_submenu_calls'], 4 )
		);
		$this->assertSame(
			array( 'Xestión ANPA', 'Axustes', 'Documentación' ),
			array_column( $GLOBALS['anpa_socios_admin_nav_submenu_calls'], 2 )
		);
		$this->assertNotContains( 'ANPA Socios', array_column( $GLOBALS['anpa_socios_admin_nav_submenu_calls'], 2 ) );
	}

	public function test_management_requires_completed_setup(): void {
		$this->assertFalse( ANPA_Socios_Admin_Nav::can_access_management( false ) );
		$this->assertTrue( ANPA_Socios_Admin_Nav::can_access_management( true ) );
	}

	public function test_clean_install_management_redirect_is_wired_and_old_warning_removed(): void {
		$management = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-management-page.php' );
		$plugin     = file_get_contents( dirname( __DIR__ ) . '/anpa-socios.php' );

		$this->assertStringContainsString( 'ANPA_Socios_Banking_Key::is_configured()', $management );
		$this->assertStringContainsString( 'wp_safe_redirect', $management );
		$this->assertStringNotContainsString( 'Un admin debe executar o setup da clave', $plugin );
	}

	public function test_import_targets_are_read_only_planning_targets(): void {
		$this->assertSame(
			array( 'socios', 'fillos', 'empresas', 'actividades', 'matriculas' ),
			array_keys( ANPA_Socios_Admin_Nav::import_targets() )
		);
	}

	public function test_export_actions_are_attached_to_domain_sections_not_listados(): void {
		$actions = ANPA_Socios_Admin_Nav::management_export_actions();

		$this->assertArrayHasKey( 'socios', $actions );
		$this->assertSame( 'Socios/as CSV', $actions['socios']['csv']['label'] );
		$this->assertSame( 'Descargar Socios IBAN', $actions['socios']['sensitive_full']['label'] );
		$this->assertSame( true, $actions['socios']['sensitive_full']['requires_passphrase'] );
		$this->assertArrayNotHasKey( 'listados', $actions );
	}

	public function test_docs_sections_cover_operator_help_topics(): void {
		$this->assertSame(
			array(
				'posta-en-marcha',
				'ciclo-curso',
				'paxinas-shortcodes',
				'extraescolares',
				'exportacions-copias',
				'privacidade-seguridade',
			),
			array_keys( ANPA_Socios_Admin_Nav::docs_sections() )
		);

		$sections = ANPA_Socios_Admin_Nav::docs_sections();
		$this->assertSame( 'Posta en marcha', $sections['posta-en-marcha'] );
		$this->assertSame( 'Privacidade e seguridade', $sections['privacidade-seguridade'] );
	}
}
