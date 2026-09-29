<?php
/**
 * 1.78.0: the login anti-abuse limits (email check at the entry, code requests
 * for members and companies/canteen) allow 10 attempts per hour instead of 3.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Limite_Acceso extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_limit_is_ten_per_hour(): void {
		$this->assertSame( 10, ANPA_Socios_Rate_Limiter::INTENTOS_ACCESO_HORA );
		$now = 1_000_000;
		$nove = array_fill( 0, 9, $now - 60 );
		$dez  = array_fill( 0, 10, $now - 60 );
		$this->assertTrue( ANPA_Socios_Rate_Limiter::permitir( $nove, ANPA_Socios_Rate_Limiter::INTENTOS_ACCESO_HORA, 3600, $now ), 'the 10th attempt passes' );
		$this->assertFalse( ANPA_Socios_Rate_Limiter::permitir( $dez, ANPA_Socios_Rate_Limiter::INTENTOS_ACCESO_HORA, 3600, $now ), 'the 11th is refused' );
	}

	public function test_every_login_limit_uses_the_shared_constant(): void {
		$files = array(
			'includes/class-anpa-socios-preflight-rest.php',
			'includes/class-anpa-socios-verificacion-rest.php',
			'includes/class-anpa-socios-empresa-rest.php',
			'includes/class-anpa-socios-rest.php',
		);
		foreach ( $files as $f ) {
			$s = $this->src( $f );
			$this->assertStringContainsString( 'ANPA_Socios_Rate_Limiter::INTENTOS_ACCESO_HORA', $s, $f );
			$this->assertSame( 0, preg_match( '/permitir\(\s*\$(timestamps|history),\s*3,/', $s ), "$f still has a hard-coded 3" );
		}
	}
}
