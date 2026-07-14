<?php
/**
 * Source-inspection contract tests for PR-ES9 task 84: per-year
 * nivel_min_id/nivel_max_id limits on the actividades_cursos rows.
 *
 * No live wpdb harness is available in this bootstrap (see
 * Test_ANPA_Socios_List_Actividades_Contract.php for the established
 * precedent), so these tests assert the SOURCE-LEVEL contract: the new
 * `cursos_niveis` request field, its validation calls into
 * ANPA_Socios_DB::niveis_belong_to_curso()/get_niveis_ordes(), and the
 * assert_within_activity() range check added to the grupos handler.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Cursos_Niveis_Contract extends TestCase {

	private string $actividades_handler_file;
	private string $grupos_handler_file;
	private string $db_file;

	public function setUp(): void {
		parent::setUp();
		$this->actividades_handler_file = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-actividades-handler.php';
		$this->grupos_handler_file       = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-grupos-handler.php';
		$this->db_file                   = dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php';
	}

	/**
	 * @testdox validated_cursos_niveis() validates each entry belongs to the curso_escolar via niveis_belong_to_curso()
	 */
	public function test_validated_cursos_niveis_checks_curso_membership(): void {
		$body = $this->extract_method_body( file_get_contents( $this->actividades_handler_file ), 'validated_cursos_niveis' );
		$this->assertNotSame( '', $body, 'validated_cursos_niveis() must exist' );
		$this->assertStringContainsString( 'ANPA_Socios_DB::niveis_belong_to_curso(', $body );
		$this->assertStringContainsString( "'anpa_admin_nivel_min_invalid'", $body );
		$this->assertStringContainsString( "'anpa_admin_nivel_max_invalid'", $body );
	}

	/**
	 * @testdox validated_cursos_niveis() compares orde (not id/codigo) to reject an inverted mínimo/máximo pair
	 */
	public function test_validated_cursos_niveis_compares_by_orde(): void {
		$body = $this->extract_method_body( file_get_contents( $this->actividades_handler_file ), 'validated_cursos_niveis' );
		$this->assertStringContainsString( 'ANPA_Socios_DB::get_niveis_ordes(', $body );
		$this->assertStringContainsString( "'anpa_admin_nivel_range_invalid'", $body );
	}

	/**
	 * @testdox cursos_niveis is additive: create/update still validate the untouched cursos field
	 */
	public function test_cursos_niveis_is_additive_to_existing_cursos_contract(): void {
		$source = file_get_contents( $this->actividades_handler_file );
		$this->assertStringContainsString( 'self::validated_cursos_niveis( $body, $cursos )', $source );
		// The legacy `cursos` validation path must remain untouched.
		$this->assertStringContainsString( 'self::validated_cursos( $body,', $source );
	}

	/**
	 * @testdox sync_actividad_cursos persists per-year nivel_min_id/nivel_max_id, defaulting to NULL for years without an entry
	 */
	public function test_sync_actividad_cursos_persists_per_year_niveis(): void {
		$body = $this->extract_method_body( file_get_contents( $this->actividades_handler_file ), 'sync_actividad_cursos' );
		$this->assertStringContainsString( "\$cursos_niveis[ \$curso ]['nivel_min_id'] ?? null", $body );
		$this->assertStringContainsString( "\$cursos_niveis[ \$curso ]['nivel_max_id'] ?? null", $body );
	}

	/**
	 * @testdox ANPA_Socios_DB::get_niveis_ordes exists with the expected signature
	 */
	public function test_get_niveis_ordes_exists(): void {
		$this->assertTrue( method_exists( 'ANPA_Socios_DB', 'get_niveis_ordes' ) );
		$ref    = new ReflectionMethod( 'ANPA_Socios_DB', 'get_niveis_ordes' );
		$params = $ref->getParameters();
		$this->assertCount( 1, $params );
		$this->assertSame( 'nivel_ids', $params[0]->getName() );
	}

	/**
	 * @testdox assert_within_activity enforces the activity+year nivel range on grupo nivel_ids via assert_niveis_within_range()
	 */
	public function test_assert_within_activity_enforces_nivel_range(): void {
		$source = file_get_contents( $this->grupos_handler_file );
		$body   = $this->extract_method_body( $source, 'assert_within_activity' );
		$this->assertStringContainsString( 'assert_niveis_within_range(', $body );
		$this->assertStringContainsString( 'nivel_min_id', $body );
		$this->assertStringContainsString( 'nivel_max_id', $body );
	}

	/**
	 * @testdox assert_niveis_within_range compares by orde and rejects a nivel outside [min_orde, max_orde]
	 */
	public function test_assert_niveis_within_range_compares_by_orde(): void {
		$source = file_get_contents( $this->grupos_handler_file );
		$body   = $this->extract_method_body( $source, 'assert_niveis_within_range' );
		$this->assertNotSame( '', $body );
		$this->assertStringContainsString( 'ANPA_Socios_DB::get_niveis_ordes(', $body );
		$this->assertStringContainsString( "'anpa_admin_grupo_nivel_fora_rango'", $body );
	}

	/**
	 * @testdox get_activity() selects nivel_min_id/nivel_max_id so assert_within_activity has them available
	 */
	public function test_get_activity_selects_nivel_columns(): void {
		$source = file_get_contents( $this->grupos_handler_file );
		$body   = $this->extract_method_body( $source, 'get_activity' );
		$this->assertStringContainsString( 'nivel_min_id', $body );
		$this->assertStringContainsString( 'nivel_max_id', $body );
	}

	/**
	 * @testdox No activity+year range configured means no additional restriction (unchanged current behaviour)
	 */
	public function test_no_range_configured_skips_range_check(): void {
		$source = file_get_contents( $this->grupos_handler_file );
		$body   = $this->extract_method_body( $source, 'assert_within_activity' );
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*null\s*!==\s*\$nivel_min_id\s*&&\s*null\s*!==\s*\$nivel_max_id\s*\)/',
			$body,
			'The range check must be skipped entirely when either bound is unconfigured.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

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
