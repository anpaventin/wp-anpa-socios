<?php
/**
 * Source-inspection contract tests for PR-ES9 task 82/83: the admin
 * actividades listing endpoint must return one row per activity. Fase30
 * supersedes the old actividades_cursos collapse: annual presence is now a
 * projection of the activity-owned annual groups.
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
	 * @testdox list_actividades derives annual presence from groups without an actividades_cursos join
	 */
	public function test_list_actividades_selects_base_rows_without_fanout_join(): void {
		$source = file_get_contents( $this->handler_file );
		$body   = $this->extract_method_body( $source, 'list_actividades' );

		$this->assertStringNotContainsString(
			'LEFT JOIN {$acy_t} ac ON ac.actividad_id = a.id' . "\n",
			$body,
			'Must not keep the old unfiltered fan-out JOIN'
		);
		$this->assertStringContainsString( 'ANPA_Socios_Activity_Group_Projection::build(', $body, 'Must delegate annual presence to the group projection' );
		$this->assertStringNotContainsString( 'tabela_actividades_cursos', $body, 'Fase30 forbids the retired annual-offer table in this reader' );
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
	 * @testdox ACTIV_COLS exposes Empresa, Nome, Custo and Estado only
	 */
	public function test_activ_cols_matches_the_active_course_listing_contract(): void {
		$source = file_get_contents( $this->js_file );
		$this->assertStringContainsString(
			"var ACTIV_COLS = ['_empresa_nome', 'nome', 'custo', 'estado'];",
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
	public function test_form_reads_group_derived_course_history(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'renderActividadForm' );
		$this->assertStringContainsString( 'act.cursos_con_grupos', $body );
		$this->assertStringContainsString( 'act.ten_grupo_curso_activo', $body );
		$this->assertStringNotContainsString( 'act.cursos)', $body, 'Must not reference the old (removed) act.cursos field' );
	}

	/**
	 * @testdox Toggle Activar/Desactivar payload preserves cursos_ofertados (regression: used to wipe other years)
	 */
	public function test_toggle_estado_payload_does_not_write_courses(): void {
		$source = file_get_contents( $this->js_file );
		// The toggle handler builds its PUT payload right before this call.
		$idx = strpos( $source, "anpaAdminFetch('actividad/' + row.id, { method: 'PUT'" );
		$this->assertNotFalse( $idx, 'Toggle estado fetch call not found' );
		$window = substr( $source, max( 0, $idx - 900 ), 900 );
		$this->assertStringNotContainsString( 'cursos:', $window );
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
