<?php
/**
 * Unit tests for the admin navigation/subsection helper (fase17a).
 *
 * @since  1.32.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Admin_Nav extends TestCase {

	public function test_settings_tabs_are_grouped_without_maintenance_children_as_top_level(): void {
		$this->assertSame(
			array( 'xeral', 'cursos', 'localizacion' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_tabs() )
		);

		$this->assertTrue( ANPA_Socios_Admin_Nav::is_settings_tab( 'xeral' ) );
		$this->assertTrue( ANPA_Socios_Admin_Nav::is_settings_tab( 'cursos' ) );
		$this->assertFalse( ANPA_Socios_Admin_Nav::is_settings_tab( 'verificacion' ) );
		$this->assertFalse( ANPA_Socios_Admin_Nav::is_settings_tab( 'actualizacions' ) );
		$this->assertFalse( ANPA_Socios_Admin_Nav::is_settings_tab( 'mantemento' ) );
	}

	public function test_settings_sections_are_ordered_by_tab(): void {
		$this->assertSame(
			array( 'estado', 'mantemento', 'configuracion', 'paxinas' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'xeral' ) )
		);
		$this->assertSame(
			array( 'estrutura' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'cursos' ) )
		);
		$this->assertSame(
			array(),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'mantemento' ) )
		);
	}

	public function test_active_settings_tab_and_section_fall_back_safely(): void {
		$this->assertSame( 'xeral', ANPA_Socios_Admin_Nav::active_settings_tab( null ) );
		$this->assertSame( 'xeral', ANPA_Socios_Admin_Nav::active_settings_tab( 'verificacion' ) );
		$this->assertSame( 'xeral', ANPA_Socios_Admin_Nav::active_settings_tab( 'mantemento' ) );

		$this->assertSame( 'estado', ANPA_Socios_Admin_Nav::active_settings_section( 'xeral', null ) );
		$this->assertSame( 'estado', ANPA_Socios_Admin_Nav::active_settings_section( 'xeral', 'verificacion' ) );
		$this->assertSame( 'estrutura', ANPA_Socios_Admin_Nav::active_settings_section( 'cursos', 'curso-escolar' ) );
		$this->assertSame( '', ANPA_Socios_Admin_Nav::active_settings_section( 'mantemento', 'verificacion' ) );
		$this->assertSame( '', ANPA_Socios_Admin_Nav::active_settings_section( 'mantemento', 'unknown' ) );
	}

	public function test_management_sections_replace_listados_with_safe_imports(): void {
		$sections = ANPA_Socios_Admin_Nav::management_sections();

		$this->assertArrayHasKey( 'inicio', $sections );
		$this->assertArrayHasKey( 'socios', $sections );
		$this->assertArrayHasKey( 'aprobacions', $sections );
		$this->assertArrayHasKey( 'fillos', $sections );
		$this->assertArrayHasKey( 'empresas', $sections );
		$this->assertArrayHasKey( 'actividades', $sections );
		$this->assertArrayHasKey( 'cursos-matriculas', $sections );
		$this->assertArrayHasKey( 'importar-listados', $sections );
		$this->assertArrayHasKey( 'auditoria', $sections );
		$this->assertArrayNotHasKey( 'listados', $sections );
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
				'settings' => array(
					'slug'       => 'anpa-socios-settings',
					'menu_label' => 'Axustes',
					'page_title' => 'Axustes ANPA Socios',
				),
				'management' => array(
					'slug'       => 'anpa-socios-management',
					'menu_label' => 'Xestión ANPA',
					'page_title' => 'Xestión ANPA',
				),
				'documentation' => array(
					'slug'       => 'anpa-socios-docs',
					'menu_label' => 'Documentación',
					'page_title' => 'Documentación ANPA Socios',
				),
			),
			ANPA_Socios_Admin_Nav::plugin_submenus()
		);
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
