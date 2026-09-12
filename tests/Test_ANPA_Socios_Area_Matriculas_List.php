<?php
/**
 * 1.56.1: the area must read the family's enrolments from the { matriculas } envelope, and the canteen
 * account (id 0) must be allowed to export.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Area_Matriculas_List extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_area_js_unwraps_the_matriculas_envelope_everywhere(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'function matriculasList(mats)', $js );
		$this->assertStringContainsString( 'return mats && Array.isArray(mats.matriculas) ? mats.matriculas : [];', $js );
		$this->assertSame( 2, substr_count( $js, 'renderMatriculas(' ) - substr_count( $js, 'function renderMatriculas(' ), 'two call sites' );
		$this->assertSame( 2, substr_count( $js, 'matriculasList(mats))' ), 'both call sites use the helper' );
		$this->assertStringNotContainsString( 'Array.isArray(mats) ? mats : []', $js );
		// Server contract this relies on.
		$rest = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( "'matriculas'          => \$data,", $rest );
	}

	public function test_curso_completo_label_does_not_double_the_degree_sign(): void {
		$expr = "CONCAT(TRIM(TRAILING 'º' FROM COALESCE(fc.curso, f.curso, '')), 'º', COALESCE(fc.aula, f.aula, '')) AS curso_completo";
		foreach ( array( 'includes/class-anpa-socios-admin-grupos-handler.php', 'includes/class-anpa-socios-admin-matriculas-handler.php' ) as $file ) {
			$src = $this->src( $file );
			$this->assertStringContainsString( $expr, $src, $file );
			$this->assertStringNotContainsString( "CONCAT(COALESCE(fc.curso, f.curso), 'º'", $src, $file );
		}
		// No other PHP/JS builder appends a degree sign to a course value.
		foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $php ) {
			if ( false !== strpos( $php, 'admin-grupos-handler' ) || false !== strpos( $php, 'admin-matriculas-handler' ) || false !== strpos( $php, 'class-anpa-socios-db.php' ) || false !== strpos( $php, 'class-anpa-socios-backup.php' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( "'º'", (string) file_get_contents( $php ), basename( $php ) );
		}
	}

	public function test_canteen_account_may_export_despite_id_zero(): void {
		$rest = $this->src( 'includes/class-anpa-socios-empresa-rest.php' );
		$this->assertStringContainsString( "if ( \$empresa_id <= 0 && ! self::is_comedor_profile( \$profile ) ) {", $rest );
	}
}
