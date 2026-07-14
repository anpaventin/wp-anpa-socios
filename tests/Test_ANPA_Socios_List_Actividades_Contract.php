<?php
/**
 * Source-inspection contract tests for PR-ES9 task 82/83: the admin
 * actividades listing endpoint must return one row per activity (delegating
 * the collapsing logic to the pure ANPA_Socios_Actividades_Collapse helper)
 * and the admin UI must add the `cursos_ofertados` column with the exact
 * "Cursos nos que se oferta" label, per design.md §8.7.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_List_Actividades_Contract extends TestCase {

	private string $handler_file;
	private string $js_file;

	public function setUp(): void {
		parent::setUp();
		$this->handler_file = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-actividades-handler.php';
		$this->js_file       = dirname( __DIR__ ) . '/assets/js/admin-management.js';
	}

	/**
	 * @testdox list_actividades no longer JOINs actividades_cursos row-per-year without collapsing
	 */
	public function test_list_actividades_selects_base_rows_without_fanout_join(): void {
		$source = file_get_contents( $this->handler_file );
		$body   = $this->extract_method_body( $source, 'list_actividades' );

		$this->assertStringNotContainsString(
			'LEFT JOIN {$acy_t} ac ON ac.actividad_id = a.id' . "\n",
			$body,
			'Must not keep the old unfiltered fan-out JOIN'
		);
		$this->assertStringContainsString( 'ANPA_Socios_Actividades_Collapse::collapse(', $body, 'Must delegate collapsing to the pure helper' );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $body, 'Must resolve the active curso per design §8.7' );
	}

	/**
	 * @testdox Pure collapse helper file exists and is required by the plugin bootstrap
	 */
	public function test_collapse_helper_exists_and_is_required(): void {
		$lib_file = dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-actividades-collapse.php';
		$this->assertFileExists( $lib_file );
		$lib_source = file_get_contents( $lib_file );
		$this->assertStringContainsString( 'class ANPA_Socios_Actividades_Collapse', $lib_source );
		$this->assertStringContainsString( 'public static function collapse(', $lib_source );

		$plugin_source = file_get_contents( dirname( __DIR__ ) . '/anpa-socios.php' );
		$this->assertStringContainsString(
			"require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/class-anpa-socios-actividades-collapse.php'",
			$plugin_source
		);
	}

	/**
	 * @testdox ACTIV_COLS includes cursos_ofertados
	 */
	public function test_activ_cols_includes_cursos_ofertados(): void {
		$source = file_get_contents( $this->js_file );
		$this->assertMatchesRegularExpression(
			"/var ACTIV_COLS = \\[[^\\]]*'cursos_ofertados'[^\\]]*\\];/",
			$source
		);
	}

	/**
	 * @testdox Admin column label map exposes the exact required label text
	 */
	public function test_cursos_ofertados_label_is_exact(): void {
		$source = file_get_contents( $this->js_file );
		$this->assertStringContainsString( "cursos_ofertados: 'Cursos nos que se oferta'", $source );
	}

	/**
	 * @testdox buildTable renders arrays (cursos_ofertados) joined by comma+space, and em-dash when empty
	 */
	public function test_build_table_renders_arrays_with_comma_space_and_emdash_fallback(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'buildTable' );
		$this->assertStringContainsString( 'Array.isArray(v)', $body );
		$this->assertStringContainsString( "join(', ')", $body );
		$this->assertStringContainsString( '\u2014', $body, 'Empty cursos_ofertados must render as an em-dash, not blank/[]' );
	}

	/**
	 * @testdox renderActividadForm reads the multi-course selection from cursos_ofertados, not the old cursos field
	 */
	public function test_form_reads_cursos_ofertados_not_legacy_cursos_field(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'renderActividadForm' );
		$this->assertStringContainsString( 'act.cursos_ofertados', $body );
		$this->assertStringNotContainsString( 'act.cursos)', $body, 'Must not reference the old (removed) act.cursos field' );
	}

	/**
	 * @testdox Toggle Activar/Desactivar payload preserves cursos_ofertados (regression: used to wipe other years)
	 */
	public function test_toggle_estado_payload_preserves_cursos_ofertados(): void {
		$source = file_get_contents( $this->js_file );
		// The toggle handler builds its PUT payload right before this call.
		$idx = strpos( $source, "anpaAdminFetch('actividad/' + row.id, { method: 'PUT'" );
		$this->assertNotFalse( $idx, 'Toggle estado fetch call not found' );
		$window = substr( $source, max( 0, $idx - 900 ), 900 );
		$this->assertStringContainsString(
			'cursos: Array.isArray(row.cursos_ofertados) ? row.cursos_ofertados : []',
			$window,
			'Toggle payload must send cursos_ofertados back, otherwise sync_actividad_cursos() array_diff wipes every OTHER offered year'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Extracts the body of a function/method from PHP or JS source (first match).
	 */
	private function extract_method_body( string $source, string $method_name ): string {
		$pattern = '/function\s+' . preg_quote( $method_name, '/' ) . '\s*\(/';
		if ( ! preg_match( $pattern, $source, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$start     = (int) $m[0][1];
		$brace_pos = strpos( $source, '{', $start );
		if ( false === $brace_pos ) {
			return '';
		}
		$depth = 0;
		$len   = strlen( $source );
		$body  = '';
		for ( $i = $brace_pos; $i < $len; $i++ ) {
			$ch = $source[ $i ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					$body = substr( $source, $brace_pos, $i - $brace_pos + 1 );
					break;
				}
			}
		}
		return $body;
	}
}
