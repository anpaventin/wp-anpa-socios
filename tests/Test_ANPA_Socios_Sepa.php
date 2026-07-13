<?php
/**
 * Tests for ANPA_Socios_Sepa (NIF/NIE + IBAN validation).
 *
 * Pure module — no WordPress dependency.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Sepa extends TestCase {

	/* ────────────────────────────── NIF/NIE ────────────────────────────── */

	/** @testdox validar_nif_nie returns canonical NIF for a valid DNI */
	public function test_valid_dni(): void {
		// 12345678 → 12345678 % 23 = 14 → NIF_LETTERS[14] = Z
		$this->assertSame( '12345678Z', ANPA_Socios_Sepa::validar_nif_nie( '12345678Z' ) );
		$this->assertSame( '12345678Z', ANPA_Socios_Sepa::validar_nif_nie( '12345678z' ) );  // lower case.
	}

	/** @testdox validar_nif_nie returns null for a DNI with wrong control letter */
	public function test_invalid_dni_wrong_letter(): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_nif_nie( '12345678A' ) );
	}

	/** @testdox validar_nif_nie returns canonical NIE for a valid foreign ID */
	public function test_valid_nie(): void {
		// X1234567 → (0)1234567 → 1234567 % 23 = 10 → NIF_LETTERS[10] = L.
		$this->assertSame( 'X1234567L', ANPA_Socios_Sepa::validar_nif_nie( 'X1234567L' ) );
		$this->assertSame( 'X1234567L', ANPA_Socios_Sepa::validar_nif_nie( 'x1234567l' ) );
	}

	/** @testdox validar_nif_nie returns null for a NIE with wrong control letter */
	public function test_invalid_nie_wrong_letter(): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_nif_nie( 'X1234567A' ) );
	}

	/** @testdox validar_nif_nie returns null for empty string */
	public function test_empty_returns_null(): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_nif_nie( '' ) );
	}

	/** @testdox validar_nif_nie returns null for too-short input */
	public function test_short_input_returns_null(): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_nif_nie( '1234' ) );
	}

	/** @testdox validar_nif_nie returns null for non-alphanumeric garbage */
	public function test_garbage_returns_null(): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_nif_nie( 'NOTANIF' ) );
		$this->assertNull( ANPA_Socios_Sepa::validar_nif_nie( '1234567$A' ) );
	}

	/** @testdox validar_nif_nie trims whitespace */
	public function test_trimmed(): void {
		$this->assertSame( '12345678Z', ANPA_Socios_Sepa::validar_nif_nie( '  12345678Z  ' ) );
	}

	/* ────────────────────────────── IBAN ────────────────────────────── */

	/** @testdox validar_iban returns canonical IBAN for a valid Spanish IBAN */
	public function test_valid_iban_es(): void {
		// ES91 2100 0418 4502 0005 1332.
		$iban = 'ES9121000418450200051332';
		$this->assertSame( $iban, ANPA_Socios_Sepa::validar_iban( $iban ) );
	}

	/** @testdox validar_iban normalises spaces and lower-case */
	public function test_iban_normalises(): void {
		$this->assertSame(
			'ES9121000418450200051332',
			ANPA_Socios_Sepa::validar_iban( 'es91 2100 0418 4502 0005 1332' )
		);
	}

	/** @testdox validar_iban returns null for mod-97 mismatch */
	public function test_invalid_iban_mod97(): void {
		// Change last digit to break mod-97.
		$this->assertNull( ANPA_Socios_Sepa::validar_iban( 'ES9121000418450200051333' ) );
	}

	/** @testdox validar_iban returns null for too-short IBAN */
	public function test_iban_too_short(): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_iban( 'ES12' ) );
	}

	/** @testdox validar_iban returns null for empty string */
	public function test_iban_empty(): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_iban( '' ) );
	}

	/** @testdox validar_iban returns null for non-IBAN garbage */
	public function test_iban_garbage(): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_iban( 'holaquetal' ) );
		$this->assertNull( ANPA_Socios_Sepa::validar_iban( '1234567890123456' ) );
	}
}
