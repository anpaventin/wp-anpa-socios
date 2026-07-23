<?php
/**
 * Fase 28 (PR-28s3): the Actividades listing shows the EFFECTIVE state of each
 * activity for the active course ("Sen grupo" when active but with no group in
 * the active course), treats such activities as inactive for the show/hide
 * filter, and drops the per-row "Grupos" button. The DB estado and the CSV
 * export contract (ACTIV_COLS) must stay unchanged. Source-inspection style.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Actividades_Effective_State extends TestCase {

	private string $js;
	private string $css;

	public function setUp(): void {
		parent::setUp();
		$root      = dirname( __DIR__ );
		$this->js  = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
		$this->css = (string) file_get_contents( $root . '/assets/css/extraescolares.css' );
	}

	public function test_activ_cols_contract_unchanged(): void {
		// CSV export uses ACTIV_COLS; it must keep the raw estado column.
		$this->assertStringContainsString(
			"var ACTIV_COLS = ['_empresa_nome', 'nome', 'custo', 'estado'];",
			$this->js
		);
	}

	public function test_effective_state_helper_defines_three_states(): void {
		$this->assertStringContainsString( 'function activityEffectiveState(row)', $this->js );
		$this->assertStringContainsString( "token: 'inactivo', label: 'Inactiva'", $this->js );
		$this->assertStringContainsString( "token: 'sen_grupo', label: 'Sen grupo'", $this->js );
		$this->assertStringContainsString( "token: 'activo', label: 'Activa'", $this->js );
		// "sen grupo" derives from having no group in the active course.
		$this->assertStringContainsString( "row.ten_grupo_curso_activo !== true", $this->js );
	}

	public function test_active_filter_uses_effective_state(): void {
		$this->assertStringContainsString(
			"activityEffectiveState(r).token === 'activo'",
			$this->js
		);
	}

	public function test_estado_column_shows_effective_label_without_mutating_row(): void {
		// A presentation-only display column drives the table (display + sort +
		// search); the CSV export keeps the raw estado column.
		$this->assertStringContainsString(
			"var ACTIV_DISPLAY_COLS = ['_empresa_nome', 'nome', 'custo', '_estado_efectivo'];",
			$this->js
		);
		$this->assertStringContainsString( 'r._estado_efectivo = activityEffectiveState(r).label;', $this->js );
		// buildTable() strips the leading underscore before colLabel(), so the
		// label map key is WITHOUT the underscore. The visible header is "Estado".
		$this->assertStringContainsString( "estado_efectivo: 'Estado',", $this->js );
		$this->assertStringContainsString( 'buildTable(paged, ACTIV_DISPLAY_COLS', $this->js );
		$this->assertStringContainsString( 'filterRows(visible, query, ACTIV_DISPLAY_COLS)', $this->js );
		// CSV export keeps the raw estado column (canonical contract): the CSV
		// column list is ACTIV_COLS (raw estado), separate from the display list.
		$this->assertStringContainsString( "addCsvExportBtn(bar, 'actividades', visible, ACTIV_COLS)", $this->js );
		$this->assertStringContainsString( "var ACTIV_COLS = ['_empresa_nome', 'nome', 'custo', 'estado'];", $this->js );
	}

	public function test_grupos_button_removed_from_listing(): void {
		// The per-row "Grupos" button was removed from the listing, and the
		// redundant edit-form "Xestionar grupos" button was also removed: groups
		// are managed inline via renderGroupSeriesList (list + "Novo grupo" +
		// edit/create per row).
		$this->assertStringNotContainsString( 'gruposBtn', $this->js );
		$this->assertStringNotContainsString( "manage.textContent = 'Xestionar grupos'", $this->js );
		$this->assertStringContainsString( "renderGroupSeriesList(groupsList, act, { scope: 'activity-inline' })", $this->js );
	}

	public function test_public_cards_grid_wider_min_and_max_four_columns(): void {
		$this->assertStringContainsString( 'minmax(260px, 1fr)', $this->css );
		$this->assertStringContainsString( 'repeat(4, 1fr)', $this->css );
	}
}
