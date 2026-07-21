<?php
/**
 * Fase30 admin UI source contracts.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Fase30_Admin_Group_UI extends TestCase {

	private string $js;
	private string $css;

	protected function setUp(): void {
		$root = dirname( __DIR__ );
		$this->js  = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
		$this->css = (string) file_get_contents( $root . '/assets/css/admin-management.css' );
	}

	public function test_activity_form_has_no_manual_course_selector_and_shows_derived_current_course(): void {
		$this->assertStringContainsString( 'ten_grupo_curso_activo', $this->js );
		$this->assertStringContainsString( 'Curso activo no que se oferta', $this->js );
		$this->assertStringNotContainsString( "name = 'curso_oferta_activo'", $this->js );
		$this->assertStringNotContainsString( "payload.cursos =", $this->js );
	}

	public function test_activity_groups_panel_lists_all_groups_with_edit_action(): void {
		$this->assertStringContainsString( 'Grupos da actividade', $this->js );
		$this->assertStringNotContainsString( 'Mostrar grupos inactivos', $this->js );
		$this->assertStringNotContainsString( 'anpa-show-inactive-groups', $this->js );
		$this->assertStringContainsString( "editBtn.textContent = 'Editar'", $this->js );
	}

	public function test_group_form_edits_only_active_year_levels(): void {
		$this->assertStringContainsString( 'Curso escolar actual', $this->js );
		$this->assertStringContainsString( 'cfg.cursoactivo', $this->js );
		$this->assertStringContainsString( 'payload.nivel_ids', $this->js );
		$this->assertStringNotContainsString( "name = 'grupo_curso_'", $this->js );
		$this->assertStringNotContainsString( 'niveis_por_ano: niveisPorAno', $this->js );
	}

	public function test_previous_school_years_are_a_read_only_dark_grey_disclosure(): void {
		$this->assertStringContainsString( 'Cursos escolares anteriores e inactivos', $this->js );
		$this->assertStringContainsString( 'cursos_anteriores', $this->js );
		$this->assertStringContainsString( 'anpa-history-disclosure', $this->js );
		$this->assertStringContainsString( '.anpa-history-disclosure', $this->css );
		$this->assertStringContainsString( 'background:', $this->css );
	}
}
