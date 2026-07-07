<?php
/**
 * Tests for ANPA_Socios_Csv pure helper.
 *
 * Covers formula-injection defense, RFC 4180 encoding, and row building.
 *
 * @since  1.4.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ANPA_Socios_Csv
 */
final class Test_ANPA_Socios_Csv extends TestCase {

	// ─── cell() — formula injection defense ────────────────────

	public function test_cell_prefixes_equals_sign(): void {
		$this->assertSame( "\"'=SUM(A1:A10)\"", ANPA_Socios_Csv::cell( '=SUM(A1:A10)' ) );
	}

	public function test_cell_prefixes_plus_sign(): void {
		$this->assertSame( "\"'+cmd|' /C calc'!A0\"", ANPA_Socios_Csv::cell( "+cmd|' /C calc'!A0" ) );
	}

	public function test_cell_prefixes_minus_sign(): void {
		$this->assertSame( "\"'-1+1\"", ANPA_Socios_Csv::cell( '-1+1' ) );
	}

	public function test_cell_prefixes_at_sign(): void {
		$this->assertSame( "\"'@SUM(A1)\"", ANPA_Socios_Csv::cell( '@SUM(A1)' ) );
	}

	public function test_cell_prefixes_tab(): void {
		$this->assertSame( "\"'\t=cmd\"", ANPA_Socios_Csv::cell( "\t=cmd" ) );
	}

	public function test_cell_prefixes_cr(): void {
		$this->assertSame( "\"'\r=cmd\"", ANPA_Socios_Csv::cell( "\r=cmd" ) );
	}

	// ─── cell() — embedded quotes ─────────────────────────────

	public function test_cell_doubles_embedded_quotes(): void {
		$this->assertSame( '"She said ""hello"""', ANPA_Socios_Csv::cell( 'She said "hello"' ) );
	}

	public function test_cell_doubles_multiple_quotes(): void {
		$this->assertSame( '"""a"" ""b"""', ANPA_Socios_Csv::cell( '"a" "b"' ) );
	}

	// ─── cell() — commas and newlines inside fields ───────────

	public function test_cell_wraps_field_with_comma(): void {
		$this->assertSame( '"one,two"', ANPA_Socios_Csv::cell( 'one,two' ) );
	}

	public function test_cell_wraps_field_with_newline(): void {
		$this->assertSame( "\"line1\nline2\"", ANPA_Socios_Csv::cell( "line1\nline2" ) );
	}

	// ─── cell() — normal values unchanged ─────────────────────

	public function test_cell_normal_value(): void {
		$this->assertSame( '"hello"', ANPA_Socios_Csv::cell( 'hello' ) );
	}

	public function test_cell_empty_string(): void {
		$this->assertSame( '""', ANPA_Socios_Csv::cell( '' ) );
	}

	public function test_cell_numeric_string(): void {
		$this->assertSame( '"12345"', ANPA_Socios_Csv::cell( '12345' ) );
	}

	// ─── row() ────────────────────────────────────────────────

	public function test_row_joins_with_comma_and_crlf(): void {
		$result = ANPA_Socios_Csv::row( array( 'a', 'b', 'c' ) );
		$this->assertSame( "\"a\",\"b\",\"c\"\r\n", $result );
	}

	public function test_row_sanitizes_each_cell(): void {
		$result = ANPA_Socios_Csv::row( array( '=evil', 'normal', '+bad' ) );
		$this->assertSame( "\"'=evil\",\"normal\",\"'+bad\"\r\n", $result );
	}

	public function test_row_empty_array(): void {
		$this->assertSame( "\r\n", ANPA_Socios_Csv::row( array() ) );
	}

	// ─── document() ───────────────────────────────────────────

	public function test_document_builds_complete_csv(): void {
		$headers = array( 'nome', 'email' );
		$rows = array(
			array( 'nome' => 'Ana', 'email' => 'ana@example.com' ),
			array( 'nome' => 'Brais', 'email' => 'brais@test.org' ),
		);
		$expected = "\"nome\",\"email\"\r\n\"Ana\",\"ana@example.com\"\r\n\"Brais\",\"brais@test.org\"\r\n";
		$this->assertSame( ANPA_Socios_Csv::UTF8_BOM . $expected, ANPA_Socios_Csv::document( $headers, $rows ) );
	}

	public function test_document_handles_missing_columns(): void {
		$headers = array( 'nome', 'email', 'telefono' );
		$rows = array(
			array( 'nome' => 'Xose', 'email' => 'x@x.com' ),
		);
		$expected = "\"nome\",\"email\",\"telefono\"\r\n\"Xose\",\"x@x.com\",\"\"\r\n";
		$this->assertSame( ANPA_Socios_Csv::UTF8_BOM . $expected, ANPA_Socios_Csv::document( $headers, $rows ) );
	}
}
