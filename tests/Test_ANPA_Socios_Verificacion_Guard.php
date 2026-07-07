<?php
/**
 * Unit tests for the verification double-registration guard (fase13b).
 *
 * @since  1.26.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Verificacion_Guard extends TestCase {

	public function test_registers_when_legacy_absent(): void {
		$this->assertTrue( ANPA_Socios_Verificacion_Guard::should_register( false ) );
	}

	public function test_does_not_register_when_legacy_active(): void {
		$this->assertFalse( ANPA_Socios_Verificacion_Guard::should_register( true ) );
	}
}
