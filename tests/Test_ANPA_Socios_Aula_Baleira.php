<?php
/**
 * 1.54.0: the classroom letter may be empty; level changes clear it; enrolment requires it.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Aula_Baleira extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function levels(): array {
		return array(
			array( 'id' => 71, 'codigo' => '1º', 'etiqueta' => '1º', 'orde' => 7 ),
			array( 'id' => 72, 'codigo' => '2º', 'etiqueta' => '2º', 'orde' => 8 ),
			array( 'id' => 73, 'codigo' => '3º', 'etiqueta' => '3º', 'orde' => 9 ),
		);
	}

	private function child( int $id, string $born, string $aula, int $nivel, string $curso ): array {
		return array( 'fillo_id' => $id, 'data_nacemento' => $born, 'aula' => $aula, 'principal_email' => 'fam' . $id . '@example.com', 'principal_count' => 1, 'nivel_id' => $nivel, 'curso' => $curso );
	}

	public function test_plan_clears_the_letter_only_when_the_level_changes(): void {
		// School year 2026/2027: age reached in 2027. Born 2019 → 8 → 2º; born 2018 → 9 → 3º.
		$plan = ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $this->levels(), array(
			$this->child( 1, '2019-05-01', 'B', 71, '1º' ), // moves 1º → 2º: letter cleared
			$this->child( 2, '2018-05-01', 'C', 73, '3º' ), // already right: letter kept
			$this->child( 3, '2019-05-01', '', 72, '2º' ),  // no letter yet, level right: no error
		) );
		$this->assertSame( 'ready', $plan['status'] );
		$this->assertSame( array( 'update', 'unchanged', 'unchanged' ), array_column( $plan['items'], 'action' ) );
		$this->assertSame( array( '', 'C', '' ), array_column( $plan['items'], 'aula' ) );
	}

	public function test_plan_never_fails_because_of_a_missing_letter(): void {
		$plan = ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $this->levels(), array(
			$this->child( 9, '2020-02-02', '', 0, '' ),
		) );
		$this->assertSame( 'ready', $plan['status'] );
		$this->assertStringNotContainsString( "'missing_classroom'", $this->src( 'includes/lib/class-anpa-socios-nivel-promotion.php' ) );
	}

	public function test_assignment_writer_accepts_an_empty_letter_and_service_reads_it_as_is(): void {
		$db = $this->src( 'includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( "if ( \$fillo_id <= 0 || '' === \$curso_escolar ) {\n\t\t\treturn false;", $db );
		$this->assertStringNotContainsString( "'' === \$curso_escolar || '' === \$aula", $db );
		$svc = $this->src( 'includes/class-anpa-socios-nivel-promotion-service.php' );
		$this->assertStringContainsString( "COALESCE(fc.aula, f.aula, '') AS aula", $svc );
		$this->assertStringNotContainsString( 'previous.aula', $svc );
	}

	public function test_enrolment_requires_the_letter_and_area_warns(): void {
		$rest = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( "'anpa_extra_sen_aula'", $rest );
		$gate = strpos( $rest, "'anpa_extra_sen_aula'" );
		$tx   = strpos( $rest, "query( 'START TRANSACTION' )", strpos( $rest, 'public static function enrol(' ) );
		$this->assertLessThan( $tx, $gate, 'the letter gate runs before the enrolment transaction' );

		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "anpa-fillo-sen-aula", $js );
		$this->assertStringContainsString( "if (!f.aula) { o.disabled = true; }", $js );
		$this->assertStringContainsString( "navigateArea('fillos')", $js );
		$this->assertStringContainsString( "Falta indicar a aula (letra da clase) de:", $js );

		$settings = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( 'bórraselles a letra da aula', $settings );
		$this->assertStringContainsString( "'— (a familia indicará a letra)'", $settings );
	}
}
