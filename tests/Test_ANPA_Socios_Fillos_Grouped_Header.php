<?php
/**
 * Fase 28 (PR-28s2): the Fillos listing gets a multilevel header grouping the
 * proxenitor columns and the fillo columns, without changing FILLOS_COLS, sort,
 * search, pagination or the CSV contract. Source-inspection style (admin glue).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Fillos_Grouped_Header extends TestCase {

	private string $js;
	private string $css;

	public function setUp(): void {
		parent::setUp();
		$root       = dirname( __DIR__ );
		$this->js   = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
		$this->css  = (string) file_get_contents( $root . '/assets/css/admin-management.css' );
	}

	public function test_fillos_cols_contract_unchanged(): void {
		// The data contract (order + keys) must stay byte-identical: the grouped
		// header is purely presentational and must not alter columns/CSV.
		$this->assertStringContainsString(
			"var FILLOS_COLS = ['proxenitor_apelidos', 'proxenitor_nome', 'socio_email', 'apelidos', 'nome', 'data_nacemento', 'curso', 'aula', 'estado'];",
			$this->js
		);
	}

	public function test_grouped_header_helper_exists(): void {
		$this->assertStringContainsString( 'function prependGroupedHeader(table, groups)', $this->js );
		$this->assertStringContainsString( "row.className = 'anpa-mgmt-colgroup';", $this->js );
		$this->assertStringContainsString( 'thead.insertBefore(row, thead.firstChild);', $this->js );
	}

	public function test_render_fillos_uses_grouped_header_with_matching_spans(): void {
		$start = strpos( $this->js, 'function renderFillos(rows)' );
		$this->assertNotFalse( $start );
		$body = substr( $this->js, $start, 2200 );

		$this->assertStringContainsString( 'prependGroupedHeader(table, [', $body );
		$this->assertStringContainsString( "label: 'Datos do proxenitor', span: 3", $body );
		$this->assertStringContainsString( "label: 'Datos do/a fillo/a', span: 6", $body );
		// 3 proxenitor + 6 fillo + 1 actions column === FILLOS_COLS.length + 1.
		$this->assertStringContainsString( "label: '', span: 1", $body );
	}

	public function test_grouped_header_has_scoped_css(): void {
		$this->assertStringContainsString( '.anpa-mgmt-table thead tr.anpa-mgmt-colgroup th', $this->css );
	}
}
