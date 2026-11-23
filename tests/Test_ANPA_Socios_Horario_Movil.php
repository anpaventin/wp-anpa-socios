<?php
/**
 * 1.96.0: timetable by day on phones and the sign-in copy of the area.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Horario_Movil extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_timetable_renders_a_per_day_list_after_the_table(): void {
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( '$html .= self::horario_por_dias_html( $grid );', $page );
		$this->assertStringContainsString( 'private static function horario_por_dias_html( array $grid ): string', $page );
		$this->assertStringContainsString( 'ANPA_Socios_Horario_Builder::DIA_LABELS as $dia => $dia_label', $page );
		// Empty days are left out of the list.
		$this->assertStringContainsString( "if ( '' === \$items ) {", $page );
	}

	public function test_phones_get_the_list_and_the_empty_notice_stays_visible(): void {
		$css = $this->src( 'assets/css/extraescolares.css' );
		$this->assertStringContainsString( '.anpa-extra-dias { display: none; }', $css );
		$this->assertMatchesRegularExpression( '/@media \(max-width: 720px\) \{\s*\.anpa-extra-horario:not\(\.anpa-extra-empty\) \{ display: none; \}\s*\.anpa-extra-dias \{ display: block; \}/', $css );
		$this->assertStringContainsString( 'ul.anpa-extra-lista > li::before', $css );
	}

	public function test_sign_in_step_explains_the_area_and_the_code(): void {
		$page = $this->src( 'includes/class-anpa-socios-unified-page.php' );
		$this->assertStringNotContainsString( 'O formulario de alta require cubrir todos os campos', $page );
		$this->assertStringNotContainsString( 'Acceder ó Área', $page );
		$this->assertStringContainsString( 'Entra na área de socios/as', $page );
		$this->assertStringContainsString( 'enviarémosche un código de 6 díxitos', $page );
		$this->assertStringContainsString( 'despois do código completas a alta', $page );
	}
}
