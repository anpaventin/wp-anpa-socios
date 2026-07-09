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
			array( 'xeral', 'cursos', 'localizacion', 'mantemento' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_tabs() )
		);

		$this->assertFalse( ANPA_Socios_Admin_Nav::is_settings_tab( 'verificacion' ) );
		$this->assertFalse( ANPA_Socios_Admin_Nav::is_settings_tab( 'actualizacions' ) );
	}

	public function test_settings_sections_are_ordered_by_tab(): void {
		$this->assertSame(
			array( 'estado', 'configuracion', 'paxinas' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'xeral' ) )
		);
		$this->assertSame(
			array( 'curso-escolar', 'matriculas', 'crear-novo' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'cursos' ) )
		);
		$this->assertSame(
			array( 'contrasinais', 'copias', 'verificacion', 'actualizacions', 'ferramentas' ),
			array_keys( ANPA_Socios_Admin_Nav::settings_sections( 'mantemento' ) )
		);
	}

	public function test_active_settings_tab_and_section_fall_back_safely(): void {
		$this->assertSame( 'xeral', ANPA_Socios_Admin_Nav::active_settings_tab( null ) );
		$this->assertSame( 'xeral', ANPA_Socios_Admin_Nav::active_settings_tab( 'verificacion' ) );
		$this->assertSame( 'mantemento', ANPA_Socios_Admin_Nav::active_settings_tab( 'mantemento' ) );

		$this->assertSame( 'estado', ANPA_Socios_Admin_Nav::active_settings_section( 'xeral', null ) );
		$this->assertSame( 'estado', ANPA_Socios_Admin_Nav::active_settings_section( 'xeral', 'verificacion' ) );
		$this->assertSame( 'verificacion', ANPA_Socios_Admin_Nav::active_settings_section( 'mantemento', 'verificacion' ) );
		$this->assertSame( 'contrasinais', ANPA_Socios_Admin_Nav::active_settings_section( 'mantemento', 'unknown' ) );
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
		$this->assertArrayHasKey( 'administradores', $sections );
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

	public function test_import_targets_are_read_only_planning_targets(): void {
		$this->assertSame(
			array( 'socios', 'fillos', 'empresas', 'actividades', 'matriculas' ),
			array_keys( ANPA_Socios_Admin_Nav::import_targets() )
		);
	}
}
