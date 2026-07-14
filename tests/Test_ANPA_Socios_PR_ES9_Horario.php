<?php
/**
 * Tests for PR-ES9: días subset validation, provisional horario slot, and
 * horario diagnostic helper.
 *
 * Combines tasks 78, 80, and 81 into a single test file following the
 * source-inspection and behavioral patterns established in this codebase.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_PR_ES9_Horario extends TestCase {

	// ────────────────────────────────────────────────────────────────────
	// Task 78: Grupo días must be subset of activity días (source inspection)
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @testdox assert_within_activity validates grupo días are subset of activity días
	 */
	public function test_assert_within_activity_validates_dias_subset(): void {
		$src  = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-grupos-handler.php' );
		$body = $this->extract_method_body( $src, 'assert_within_activity' );

		// Must iterate grupo_dias and check each against act_dias.
		$this->assertStringContainsString( '$act_dias', $body, 'Must parse activity días' );
		$this->assertStringContainsString( '$grupo_dias', $body, 'Must parse grupo días' );
		$this->assertStringContainsString( 'in_array', $body, 'Must check membership' );
		$this->assertStringContainsString( 'anpa_admin_grupo_dias', $body, 'Must return error code for invalid días' );
	}

	/**
	 * @testdox assert_within_activity dias check uses ANPA_Socios_Actividade_Options::parse
	 */
	public function test_assert_within_activity_uses_options_parse_for_dias(): void {
		$src  = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-grupos-handler.php' );
		$body = $this->extract_method_body( $src, 'assert_within_activity' );

		// Both sides (activity's días and grupo's días) must use the canonical parser.
		$this->assertGreaterThanOrEqual(
			2,
			substr_count( $body, 'ANPA_Socios_Actividade_Options::parse' ),
			'Both act_dias and grupo_dias must use ANPA_Socios_Actividade_Options::parse'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 80: Horario diagnostic helper (behavioral tests)
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @testdox diagnose returns incluida_por_grupo when groups exist
	 */
	public function test_diagnose_incluida_por_grupo(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => 'luns,martes', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array( array( 'estado' => 'aberto' ) ),
			true
		);
		$this->assertSame( 'incluida_por_grupo', $result );
	}

	/**
	 * @testdox diagnose returns incluida_por_horario_anual_provisional when activity has valid franxa/dias but ZERO groups
	 */
	public function test_diagnose_incluida_por_horario_anual_provisional(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => 'luns,martes', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array(), // No groups at all
			true
		);
		$this->assertSame( 'incluida_por_horario_anual_provisional', $result );
	}

	/**
	 * @testdox diagnose returns sen_franxa when franxa is empty/invalid
	 */
	public function test_diagnose_sen_franxa(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '', 'dias' => 'luns', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array(),
			true
		);
		$this->assertSame( 'sen_franxa', $result );

		$result2 = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => 'tarde', 'dias' => 'luns', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array(),
			true
		);
		$this->assertSame( 'sen_franxa', $result2 );
	}

	/**
	 * @testdox diagnose returns sen_dias when dias is empty
	 */
	public function test_diagnose_sen_dias(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => '', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array(),
			true
		);
		$this->assertSame( 'sen_dias', $result );
	}

	/**
	 * @testdox diagnose returns sen_grupo_aberto when groups exist but none is aberto
	 */
	public function test_diagnose_sen_grupo_aberto(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => 'luns', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array( array( 'estado' => 'pechado' ) ),
			true
		);
		$this->assertSame( 'sen_grupo_aberto', $result );
	}

	/**
	 * @testdox diagnose returns estado_inactivo when activity estado is not activo
	 */
	public function test_diagnose_estado_inactivo(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => 'luns', 'estado' => 'inactivo', 'curso_estado' => 'activo' ),
			array(),
			true
		);
		$this->assertSame( 'estado_inactivo', $result );
	}

	/**
	 * @testdox diagnose returns curso_non_activo when curso is not active
	 */
	public function test_diagnose_curso_non_activo(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => 'luns', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array(),
			false
		);
		$this->assertSame( 'curso_non_activo', $result );
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 81: Horario builder with provisional slots
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @testdox Provisional activity (no grupos field or empty) builds into the grid normally
	 */
	public function test_provisional_activity_with_empty_grupos_builds_correctly(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Xadrez', 'franxa' => '16:00-17:00', 'grupos' => '', 'dias' => 'luns,mercores' ),
		) );

		$this->assertCount( 1, $grid );
		$this->assertSame( 'Xadrez', $grid[0]['dias']['luns'][0]['nome'] );
		$this->assertSame( array(), $grid[0]['dias']['luns'][0]['grupos'] );
		$this->assertSame( 'Xadrez', $grid[0]['dias']['mercores'][0]['nome'] );
	}

	/**
	 * @testdox Provisional slot mixed with real-group activities does not duplicate
	 */
	public function test_no_duplication_when_provisional_mixed_with_real_groups(): void {
		$rows = array(
			// Real group activity
			array( 'nome' => 'Robótica', 'franxa' => '16:00-17:00', 'grupos' => '1-2-3', 'dias' => 'luns' ),
			// Provisional activity (no groups)
			array( 'nome' => 'Xadrez', 'franxa' => '16:00-17:00', 'grupos' => '', 'dias' => 'luns' ),
		);
		$grid = ANPA_Socios_Horario_Builder::build( $rows );

		$this->assertCount( 1, $grid ); // Same franxa → single row
		$luns = $grid[0]['dias']['luns'];
		$names = array_column( $luns, 'nome' );
		$this->assertCount( 2, $names );
		$this->assertContains( 'Robótica', $names );
		$this->assertContains( 'Xadrez', $names );
		// No duplicates
		$this->assertCount( 2, array_unique( $names ) );
	}

	/**
	 * @testdox Activity with pechado group should NOT get a provisional slot via diagnose
	 */
	public function test_pechado_group_suppresses_provisional_slot(): void {
		// A pechado (closed) group EXISTS — the provisional fallback must NOT be used.
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => 'luns', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array( array( 'estado' => 'pechado' ) ),
			true
		);
		// Must NOT return 'incluida_por_horario_anual_provisional' — groups exist.
		$this->assertSame( 'sen_grupo_aberto', $result );
	}

	/**
	 * @testdox Activity with invalid franxa never gets provisional slot
	 */
	public function test_invalid_franxa_prevents_provisional_slot(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => 'tardes', 'dias' => 'luns,martes', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array(),
			true
		);
		$this->assertSame( 'sen_franxa', $result );
	}

	/**
	 * @testdox Activity with empty días never gets provisional slot
	 */
	public function test_empty_dias_prevents_provisional_slot(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => '', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array(),
			true
		);
		$this->assertSame( 'sen_dias', $result );
	}

	/**
	 * @testdox Activity with aberto group returns incluida_por_grupo (never provisional)
	 */
	public function test_activity_with_open_group_never_provisional(): void {
		$result = ANPA_Socios_Horario_Builder::diagnose(
			array( 'franxa' => '16:00-17:00', 'dias' => 'luns', 'estado' => 'activo', 'curso_estado' => 'activo' ),
			array( array( 'estado' => 'aberto' ) ),
			true
		);
		$this->assertSame( 'incluida_por_grupo', $result );
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 79: Provisional slot query (source inspection of extraescolares page)
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @testdox active_group_slots includes NOT EXISTS query for provisional rows
	 */
	public function test_active_group_slots_has_provisional_query(): void {
		$src  = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-extraescolares-page.php' );
		$body = $this->extract_method_body( $src, 'active_group_slots' );
		$this->assertStringContainsString( 'NOT EXISTS', $body, 'Provisional slot uses NOT EXISTS subquery' );
		$this->assertStringContainsString( "'' AS grupos", $body, 'Provisional row synthesizes empty grupos field' );
	}

	/**
	 * @testdox active_group_slots provisional query validates franxa and dias non-empty in SQL
	 */
	public function test_active_group_slots_provisional_validates_franxa_dias(): void {
		$src  = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-extraescolares-page.php' );
		$body = $this->extract_method_body( $src, 'active_group_slots' );
		$this->assertStringContainsString( "ac.franxa IS NOT NULL", $body );
		$this->assertStringContainsString( "ac.dias IS NOT NULL", $body );
	}

	/**
	 * @testdox Diagnostic route is registered under /actividad/{id}/horario-diagnostic
	 */
	public function test_horario_diagnostic_route_registered(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-actividades-handler.php' );
		$this->assertStringContainsString( 'horario-diagnostic', $src );
		$this->assertStringContainsString( 'horario_diagnostic', $src );
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Extracts the body of a function/method from PHP source (first match).
	 */
	private function extract_method_body( string $source, string $method_name ): string {
		$pattern = '/function\s+' . preg_quote( $method_name, '/' ) . '\s*\(/';
		if ( ! preg_match( $pattern, $source, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$start = $m[0][1];
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
