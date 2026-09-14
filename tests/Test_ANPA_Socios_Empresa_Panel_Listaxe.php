<?php
/**
 * Company / canteen panel: download buttons first, pupils list before the offer, and the pupils
 * table searchable and sortable with the very same helpers the Xestión listings use.
 *
 * Source-contract tests (no DOM): they pin the template order, the script dependencies and the
 * shared building blocks so the two surfaces cannot drift apart again.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Empresa_Panel_Listaxe extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/** Position of a needle inside the empresa card of the area template. */
	private function pos( string $tpl, string $needle ): int {
		$pos = strpos( $tpl, $needle );
		$this->assertNotFalse( $pos, "Template must contain: {$needle}" );
		return (int) $pos;
	}

	public function test_panel_puts_downloads_first_and_the_offer_after_the_pupils_list(): void {
		$tpl  = $this->src( 'includes/class-anpa-socios-area-page.php' );
		$card = strpos( $tpl, 'data-step="empresa"' );
		$this->assertNotFalse( $card );
		$tpl = substr( $tpl, (int) $card );

		$toolbar    = $this->pos( $tpl, 'class="anpa-area-actions anpa-empresa-toolbar"' );
		$export     = $this->pos( $tpl, 'data-action="empresa-export" data-ambito="activos"' );
		$datos      = $this->pos( $tpl, 'class="anpa-empresa-datos"' );
		$alumnos    = $this->pos( $tpl, 'data-empresa-alumnos' );
		$actividade = $this->pos( $tpl, 'data-empresa-actividades' );

		$this->assertLessThan( $datos, $toolbar, 'The download buttons come before the company data.' );
		$this->assertLessThan( $datos, $export );
		$this->assertLessThan( $alumnos, $toolbar, 'The download buttons are above the pupils list.' );
		$this->assertLessThan( $actividade, $alumnos, 'The offered activities come after the pupils list.' );
		$this->assertSame( 1, substr_count( $tpl, 'anpa-area-actions' ), 'Only one actions bar in the panel (no buttons left at the bottom).' );
	}

	public function test_area_script_depends_on_the_shared_list_helpers_on_both_pages(): void {
		$area = $this->src( 'includes/class-anpa-socios-area-page.php' );
		$this->assertStringContainsString( 'public static function area_script_deps(): array', $area );
		$this->assertStringContainsString( "'anpa-socios-admin-table' => 'admin-table.js', 'anpa-socios-utils' => 'anpa-utils.js'", $area );
		$this->assertStringContainsString( "return array( 'wp-i18n', 'anpa-socios-admin-table', 'anpa-socios-utils' );", $area );
		$this->assertStringContainsString( "'anpa-socios-area',\n\t\t\tplugins_url( 'assets/js/area.js', ANPA_SOCIOS_PLUGIN_FILE ),\n\t\t\tself::area_script_deps(),", $area );

		$unified = $this->src( 'includes/class-anpa-socios-unified-page.php' );
		$this->assertStringContainsString( "'anpa-socios-area',\n\t\t\tplugins_url( 'assets/js/area.js', ANPA_SOCIOS_PLUGIN_FILE ),\n\t\t\tANPA_Socios_Area_Page::area_script_deps(),", $unified );

		// The admin page keeps registering the same handles: same file, same helpers.
		$admin = $this->src( 'includes/class-anpa-socios-admin-management-page.php' );
		$this->assertStringContainsString( "'anpa-socios-admin-table',\n\t\t\t\$plugin_url . '/assets/js/admin-table.js'", $admin );
		$this->assertStringContainsString( "'anpa-socios-utils',\n\t\t\t\$plugin_url . '/assets/js/anpa-utils.js'", $admin );
	}

	public function test_pupils_table_searches_and_sorts_with_the_xestion_helpers(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "const { __, sprintf } = wp.i18n;", $js );
		$this->assertStringContainsString( 'const listTbl = window.AnpaAdminTable ||', $js );
		$this->assertStringContainsString( 'const listUtils = window.AnpaUtils ||', $js );
		$this->assertStringContainsString( 'function renderEmpresaAlumnos(alHost, rawRows, comedor)', $js );
		$this->assertStringContainsString( "const filtered = listUtils.filterRows(rows, st.searchQuery || '', keys);", $js );
		$this->assertStringContainsString( 'const sorted = listTbl.sortRows(filtered, st.sort.key, st.sort.dir);', $js );
		// Same affordances as buildTable() in admin-management.js.
		$this->assertStringContainsString( "th.className = 'anpa-sortable';", $js );
		$this->assertStringContainsString( "label += st.sort.dir === 'asc' ? ' ▲' : ' ▼';", $js );
		$this->assertStringContainsString( "search.setAttribute('aria-label', __( 'Buscar na listaxe', 'anpa-socios' ));", $js );
		$this->assertStringContainsString( "__( 'Sen resultados.', 'anpa-socios' )", $js );
		// The canteen still gets its extra column and the row colour by state.
		$this->assertStringContainsString( "if (comedor) { cols.push({ key: 'empresa', label: __( 'Empresa', 'anpa-socios' ) }); }", $js );
		$this->assertStringContainsString( "tr.className = 'anpa-empresa-estado-' + row._estado;", $js );
		// The old static table is gone.
		$this->assertStringNotContainsString( 'headers.forEach(function (t) { const th = document.createElement', $js );

		$css = $this->src( 'assets/css/area.css' );
		$this->assertStringContainsString( '#anpa-area .anpa-empresa-alumnos th.anpa-sortable { cursor: pointer; user-select: none; }', $css );
		$this->assertStringContainsString( '#anpa-area .anpa-empresa-filter-bar input[type="search"]', $css );
	}

	public function test_display_columns_are_never_hidden_by_the_shared_label_map(): void {
		// AnpaUtils.filterRows() skips columns whose label maps to '' (hidden). Every key used by the
		// panel must stay searchable, so none of them may be hidden in COLUMN_LABELS.
		$utils = $this->src( 'assets/js/anpa-utils.js' );
		foreach ( array( 'actividade', 'empresa', 'grupo', 'alumno', 'curso', 'estado', 'opcions', 'contacto' ) as $key ) {
			$this->assertStringNotContainsString( "'{$key}': ''", $utils, "Column {$key} would be excluded from the panel search." );
		}
	}

	public function test_admin_listings_share_one_render_pipeline(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		// Every listing: filter → sort → wire the search box → empty branch → page → table → pagination.
		// The search box is wired exactly once per render (before the empty-result return), never twice.
		$this->assertSame( 6, preg_match_all( '/wireSearchInput\(bar, (?:st|matSt), (?:render|renderMat)\);/', $js ) );
		$this->assertSame( 6, preg_match_all( '/if \(!sorted\.length\) \{ [^\n]*appendChild\(emptyEl\(/', $js ), 'Empty results use emptyEl() in every listing.' );
		$this->assertStringNotContainsString( "emptyP.textContent = 'Sen resultados.';", $js );
		// Focus restore is one-shot: a sort/pagination re-render must not grab the focus.
		$this->assertStringContainsString( "st._searchFocused = false;\n\t\t\tinput.focus();", $js );
	}
}
