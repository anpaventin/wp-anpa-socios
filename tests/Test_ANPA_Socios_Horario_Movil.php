<?php
/**
 * 1.96.0: sign-in copy of the area (the per-day phone timetable was reverted in 1.98.2).
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

	public function test_sign_in_step_explains_the_area_and_the_code(): void {
		$page = $this->src( 'includes/class-anpa-socios-unified-page.php' );
		$this->assertStringNotContainsString( 'O formulario de alta require cubrir todos os campos', $page );
		$this->assertStringNotContainsString( 'Acceder ó Área', $page );
		$this->assertStringContainsString( 'Entra na área de socios/as', $page );
		$this->assertStringContainsString( 'enviarémosche un código de 6 díxitos', $page );
		$this->assertStringContainsString( 'despois do código completas a alta', $page );
	}
}
