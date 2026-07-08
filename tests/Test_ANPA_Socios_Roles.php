<?php
/**
 * Unit tests for ANPA_Socios_Roles pure helpers.
 *
 * @since  1.2.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Roles extends TestCase {

	private const MASTER_EMAIL = 'admin@example.com';
	private const OTHER_EMAIL  = 'someone.else@example.org';

	// ──────────────────────────────────────────────
	// es_master (data-driven: any email + rol='master' → true)
	// ──────────────────────────────────────────────

	/**
	 * Any email with rol=master returns true.
	 */
	public function test_es_master_true_when_role_is_master(): void {
		$this->assertTrue(
			ANPA_Socios_Roles::es_master( self::MASTER_EMAIL, 'master' )
		);
	}

	/**
	 * A different email with rol=master also returns true (multi-admin).
	 */
	public function test_es_master_true_for_other_email_with_master_role(): void {
		$this->assertTrue(
			ANPA_Socios_Roles::es_master( self::OTHER_EMAIL, 'master' )
		);
	}

	/**
	 * Any email with rol=socio returns false.
	 */
	public function test_es_master_false_when_role_is_socio(): void {
		$this->assertFalse(
			ANPA_Socios_Roles::es_master( self::MASTER_EMAIL, 'socio' )
		);
		$this->assertFalse(
			ANPA_Socios_Roles::es_master( self::OTHER_EMAIL, 'socio' )
		);
	}

	/**
	 * Empty rol returns false.
	 */
	public function test_es_master_false_when_role_empty(): void {
		$this->assertFalse(
			ANPA_Socios_Roles::es_master( self::MASTER_EMAIL, '' )
		);
	}

	/**
	 * Empty email returns false.
	 */
	public function test_es_master_false_when_email_empty(): void {
		$this->assertFalse(
			ANPA_Socios_Roles::es_master( '', 'master' )
		);
	}

	// ──────────────────────────────────────────────
	// is_protected_admin
	// ──────────────────────────────────────────────

	/**
	 * MASTER_EMAIL exact case returns true.
	 */
	public function test_is_protected_admin_true_for_master_email(): void {
		$this->assertTrue(
			ANPA_Socios_Roles::is_protected_admin( self::MASTER_EMAIL )
		);
	}

	/**
	 * MASTER_EMAIL uppercase returns true.
	 */
	public function test_is_protected_admin_true_uppercase(): void {
		$this->assertTrue(
			ANPA_Socios_Roles::is_protected_admin( strtoupper( self::MASTER_EMAIL ) )
		);
	}

	/**
	 * MASTER_EMAIL mixed case returns true.
	 */
	public function test_is_protected_admin_true_mixed_case(): void {
		$this->assertTrue(
			ANPA_Socios_Roles::is_protected_admin( 'Admin@Example.Com' )
		);
	}

	/**
	 * Other valid email returns false.
	 */
	public function test_is_protected_admin_false_for_other_email(): void {
		$this->assertFalse(
			ANPA_Socios_Roles::is_protected_admin( self::OTHER_EMAIL )
		);
	}

	/**
	 * Empty string returns false.
	 */
	public function test_is_protected_admin_false_for_empty(): void {
		$this->assertFalse(
			ANPA_Socios_Roles::is_protected_admin( '' )
		);
	}

	/**
	 * Whitespace-only returns false.
	 */
	public function test_is_protected_admin_false_for_whitespace(): void {
		$this->assertFalse(
			ANPA_Socios_Roles::is_protected_admin( '   ' )
		);
	}

	// ──────────────────────────────────────────────
	// is_protected_admin with explicit master_email parameter
	// ──────────────────────────────────────────────

	/**
	 * When a custom master is passed, it matches that email (case-insensitive).
	 */
	public function test_is_protected_admin_custom_master_matches(): void {
		$custom = 'custom-admin@example.org';
		$this->assertTrue(
			ANPA_Socios_Roles::is_protected_admin( $custom, $custom )
		);
	}

	/**
	 * Custom master matches regardless of case.
	 */
	public function test_is_protected_admin_custom_master_case_insensitive(): void {
		$this->assertTrue(
			ANPA_Socios_Roles::is_protected_admin(
				'Custom-Admin@Example.Org',
				'custom-admin@example.org'
			)
		);
	}

	/**
	 * When a custom master is passed, the default MASTER_EMAIL is NOT protected.
	 */
	public function test_is_protected_admin_default_not_protected_when_custom(): void {
		$this->assertFalse(
			ANPA_Socios_Roles::is_protected_admin(
				self::MASTER_EMAIL,
				'another-master@example.org'
			)
		);
	}

	/**
	 * Omitting the second arg still matches MASTER_EMAIL (back-compat).
	 */
	public function test_is_protected_admin_omitted_arg_back_compat(): void {
		$this->assertTrue(
			ANPA_Socios_Roles::is_protected_admin( self::MASTER_EMAIL )
		);
		$this->assertFalse(
			ANPA_Socios_Roles::is_protected_admin( self::OTHER_EMAIL )
		);
	}

	/**
	 * Custom master with surrounding whitespace still matches.
	 */
	public function test_is_protected_admin_custom_master_trimmed(): void {
		$this->assertTrue(
			ANPA_Socios_Roles::is_protected_admin(
				'  admin@test.es  ',
				' admin@test.es'
			)
		);
	}

	// ──────────────────────────────────────────────
	// rol_valido
	// ──────────────────────────────────────────────

	public function test_rol_valido_true_for_known_roles(): void {
		$this->assertTrue( ANPA_Socios_Roles::rol_valido( 'socio' ) );
		$this->assertTrue( ANPA_Socios_Roles::rol_valido( 'master' ) );
	}

	public function test_rol_valido_false_for_unknown(): void {
		$this->assertFalse( ANPA_Socios_Roles::rol_valido( 'admin' ) );
		$this->assertFalse( ANPA_Socios_Roles::rol_valido( '' ) );
		$this->assertFalse( ANPA_Socios_Roles::rol_valido( 'MASTER' ) );
	}

	// ──────────────────────────────────────────────
	// estado_socio_valido
	// ──────────────────────────────────────────────

	public function test_estado_socio_valido_activo(): void {
		$this->assertTrue( ANPA_Socios_Roles::estado_socio_valido( 'activo' ) );
	}

	public function test_estado_socio_valido_pendiente_alta(): void {
		$this->assertTrue( ANPA_Socios_Roles::estado_socio_valido( 'pendiente_alta' ) );
	}

	public function test_estado_socio_valido_inactivo_baixa(): void {
		$this->assertFalse( ANPA_Socios_Roles::estado_socio_valido( 'baixa' ) );
		$this->assertFalse( ANPA_Socios_Roles::estado_socio_valido( 'inactivo' ) );
		$this->assertFalse( ANPA_Socios_Roles::estado_socio_valido( '' ) );
	}
}
