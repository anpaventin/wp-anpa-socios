<?php
/**
 * Unit tests for ANPA_Socios_Normalize.
 *
 * Covers all pure normalization helpers: title_case, email, telefono,
 * nif, iban, curso_escolar.
 *
 * @since  1.34.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ANPA_Socios_Normalize
 */
final class Test_ANPA_Socios_Normalize extends TestCase {

	// ─── title_case ─────────────────────────────────────────────────────

	public function test_title_case_basic(): void {
		$this->assertSame( 'María José', ANPA_Socios_Normalize::title_case( 'maría JOSÉ' ) );
	}

	public function test_title_case_particles(): void {
		$this->assertSame( 'Ruiz de la Prada', ANPA_Socios_Normalize::title_case( 'RUIZ DE LA PRADA' ) );
	}

	public function test_title_case_first_word_particle(): void {
		// A particle at the start stays capitalised.
		$this->assertSame( 'De la Fuente', ANPA_Socios_Normalize::title_case( 'DE LA FUENTE' ) );
	}

	public function test_title_case_hyphen(): void {
		$this->assertSame( 'María-José', ANPA_Socios_Normalize::title_case( 'MARÍA-JOSÉ' ) );
	}

	public function test_title_case_trims_and_collapses_spaces(): void {
		$this->assertSame( 'María José', ANPA_Socios_Normalize::title_case( '  maría   josé  ' ) );
	}

	public function test_title_case_empty(): void {
		$this->assertSame( '', ANPA_Socios_Normalize::title_case( '' ) );
		$this->assertSame( '', ANPA_Socios_Normalize::title_case( '   ' ) );
	}

	// ─── email ──────────────────────────────────────────────────────────

	public function test_email_valid(): void {
		$this->assertSame( 'socio@example.com', ANPA_Socios_Normalize::email( ' Socio@Example.COM  ' ) );
	}

	public function test_email_invalid_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::email( 'not-an-email' ) );
	}

	public function test_email_empty_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::email( '' ) );
	}

	// ─── telefono ───────────────────────────────────────────────────────

	public function test_telefono_valid(): void {
		$this->assertSame( '666123456', ANPA_Socios_Normalize::telefono( '+34 666 123 456' ) );
	}

	public function test_telefono_invalid_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::telefono( '123' ) );
	}

	public function test_telefono_with_dots_and_dashes(): void {
		$this->assertSame( '912345678', ANPA_Socios_Normalize::telefono( '91.234.56.78' ) );
	}

	// ─── nif ────────────────────────────────────────────────────────────

	public function test_nif_valid(): void {
		// 12345678Z is valid mod-23.
		$this->assertSame( '12345678Z', ANPA_Socios_Normalize::nif( ' 12345678z ' ) );
	}

	public function test_nif_invalid_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::nif( '00000000A' ) );
	}

	public function test_nif_empty_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::nif( '' ) );
	}

	// ─── iban ───────────────────────────────────────────────────────────

	public function test_iban_canonical(): void {
		$this->assertSame(
			'ES9121000418450200051332',
			ANPA_Socios_Normalize::iban( 'es91 2100 0418 4502 0005 1332' )
		);
	}

	public function test_iban_already_canonical(): void {
		$this->assertSame(
			'GB29NWBK60161331926819',
			ANPA_Socios_Normalize::iban( 'GB29NWBK60161331926819' )
		);
	}

	public function test_iban_trims_and_uppercases(): void {
		$this->assertSame(
			'DE89370400440532013000',
			ANPA_Socios_Normalize::iban( '  de89 3704 0044 0532 0130 00  ' )
		);
	}

	public function test_iban_tabs_and_newlines(): void {
		$this->assertSame(
			'FR7630006000011234567890189',
			ANPA_Socios_Normalize::iban( "FR76\t3000 6000\n0112 3456 7890 189" )
		);
	}

	public function test_iban_empty_stays_empty(): void {
		$this->assertSame( '', ANPA_Socios_Normalize::iban( '' ) );
		$this->assertSame( '', ANPA_Socios_Normalize::iban( '   ' ) );
	}

	// ─── curso_escolar ──────────────────────────────────────────────────

	public function test_curso_escolar_slash(): void {
		$this->assertSame( '2025/2026', ANPA_Socios_Normalize::curso_escolar( '2025/2026' ) );
	}

	public function test_curso_escolar_dash(): void {
		$this->assertSame( '2025/2026', ANPA_Socios_Normalize::curso_escolar( '2025-2026' ) );
	}

	public function test_curso_escolar_spaces_around_slash(): void {
		$this->assertSame( '2025/2026', ANPA_Socios_Normalize::curso_escolar( '2025 / 2026' ) );
	}

	public function test_curso_escolar_just_space(): void {
		$this->assertSame( '2025/2026', ANPA_Socios_Normalize::curso_escolar( '2025 2026' ) );
	}

	public function test_curso_escolar_non_consecutive_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::curso_escolar( '2025-2027' ) );
	}

	public function test_curso_escolar_single_year_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::curso_escolar( '2025' ) );
	}

	public function test_curso_escolar_invalid_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::curso_escolar( 'abc' ) );
	}

	public function test_curso_escolar_empty_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::curso_escolar( '' ) );
	}

	public function test_curso_escolar_short_years_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::curso_escolar( '25/26' ) );
	}

	public function test_curso_escolar_reversed_returns_null(): void {
		$this->assertNull( ANPA_Socios_Normalize::curso_escolar( '2026/2025' ) );
	}
}
