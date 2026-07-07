<?php
/**
 * Unit tests for the pre-season access gate (fase12).
 *
 * @since  1.18.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Preseason_Gate extends TestCase {

	public function test_code_allowed_true_for_admin_even_pendente(): void {
		$this->assertTrue( ANPA_Socios_Preseason_Gate::code_allowed( 'pendente', true ) );
	}

	public function test_code_blocked_for_nonadmin_pendente(): void {
		$this->assertFalse( ANPA_Socios_Preseason_Gate::code_allowed( 'pendente', false ) );
	}

	public function test_code_allowed_for_nonadmin_activo(): void {
		$this->assertTrue( ANPA_Socios_Preseason_Gate::code_allowed( 'activo', false ) );
	}

	public function test_code_allowed_for_nonadmin_pechado(): void {
		// Pre-season gate only blocks the `pendente` state; `pechado` handling
		// is governed by course_is_open, not the login-code gate.
		$this->assertTrue( ANPA_Socios_Preseason_Gate::code_allowed( 'pechado', false ) );
	}
}
