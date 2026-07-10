<?php
/**
 * Tests for ANPA_Socios_Csv_Import socios_iban entity support.
 *
 * Covers parsing, validation, dedup, masking helpers, and normalization
 * for the IBAN import CSV flow. Pure tests — no WP, no DB, no crypto.
 *
 * @since  1.35.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ANPA_Socios_Csv_Import
 */
final class Test_ANPA_Socios_Csv_Import_Iban extends TestCase {

	// ─── socios_iban entity definition ─────────────────────────

	public function test_socios_iban_required_fields_defined(): void {
		$this->assertArrayHasKey( 'socios_iban', ANPA_Socios_Csv_Import::REQUIRED_FIELDS );
		$required = ANPA_Socios_Csv_Import::REQUIRED_FIELDS['socios_iban'];
		$this->assertContains( 'id_familia', $required );
		$this->assertContains( 'titular_nome', $required );
		$this->assertContains( 'titular_apelidos', $required );
		$this->assertContains( 'titular_nif', $required );
		$this->assertContains( 'iban', $required );
	}

	public function test_socios_iban_entity_headers_defined(): void {
		$this->assertArrayHasKey( 'socios_iban', ANPA_Socios_Csv_Import::ENTITY_HEADERS );
		$headers = ANPA_Socios_Csv_Import::ENTITY_HEADERS['socios_iban'];
		$this->assertContains( 'id_familia', $headers );
		$this->assertContains( 'titular_nome', $headers );
		$this->assertContains( 'titular_apelidos', $headers );
		$this->assertContains( 'titular_nif', $headers );
		$this->assertContains( 'iban', $headers );
		$this->assertContains( 'entidade_bancaria', $headers );
		$this->assertContains( 'autorizacion', $headers );
	}

	// ─── parse + analyze socios_iban ───────────────────────────

	public function test_parse_socios_iban_csv(): void {
		$csv = "id_familia,titular_nome,titular_apelidos,titular_nif,iban,entidade_bancaria,autorizacion\n"
			. "1,ANA,GARCIA LOPEZ,12345678Z,ES12 3456 7890 1234 5678 9012,Banco Ficticio,1\n"
			. "2,PEDRO,LOPEZ FERNANDEZ,87654321X,ES98 7654 3210 9876 5432 1098,Caixa Ficticia,1\n";

		$rows = ANPA_Socios_Csv_Import::parse( $csv );
		$this->assertCount( 2, $rows );
		$this->assertSame( '1', $rows[0]['id_familia'] );
		$this->assertSame( 'ANA', $rows[0]['titular_nome'] );
		$this->assertSame( 'ES12 3456 7890 1234 5678 9012', $rows[0]['iban'] );
	}

	public function test_analyze_socios_iban_normalizes_fields(): void {
		$rows = array(
			array(
				'id_familia'        => '1',
				'titular_nome'      => 'ANA MARÍA',
				'titular_apelidos'  => 'GARCIA DE LA FUENTE',
				'titular_nif'       => '12345678z',
				'iban'              => 'es12 3456 7890 1234 5678 9012',
				'entidade_bancaria' => 'Banco Ficticio',
				'autorizacion'      => '1',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		// Names normalized to title_case.
		$this->assertSame( 'Ana María', $result['rows'][0]['titular_nome'] );
		$this->assertSame( 'Garcia de la Fuente', $result['rows'][0]['titular_apelidos'] );
		// IBAN normalized (uppercase, no spaces).
		$this->assertSame( 'ES1234567890123456789012', $result['rows'][0]['iban'] );
	}

	public function test_analyze_socios_iban_required_fields_error(): void {
		$rows = array(
			array(
				'id_familia'        => '',
				'titular_nome'      => 'Ana',
				'titular_apelidos'  => 'García',
				'titular_nif'       => '12345678Z',
				'iban'              => 'ES1234567890123456789012',
				'entidade_bancaria' => '',
				'autorizacion'      => '1',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		$id_errors = array_filter( $result['errors'], function ( $e ) {
			return 'id_familia' === $e['field'];
		} );
		$this->assertNotEmpty( $id_errors );
	}

	public function test_analyze_socios_iban_validates_iban_length(): void {
		$rows = array(
			array(
				'id_familia'        => '1',
				'titular_nome'      => 'Ana',
				'titular_apelidos'  => 'García',
				'titular_nif'       => '12345678Z',
				'iban'              => 'ES12',
				'entidade_bancaria' => '',
				'autorizacion'      => '1',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		$iban_errors = array_filter( $result['errors'], function ( $e ) {
			return 'iban' === $e['field'] && str_contains( $e['msg'], 'too short' );
		} );
		$this->assertNotEmpty( $iban_errors );
	}

	public function test_analyze_socios_iban_validates_titular_nif_required(): void {
		$rows = array(
			array(
				'id_familia'        => '1',
				'titular_nome'      => 'Ana',
				'titular_apelidos'  => 'García',
				'titular_nif'       => '',
				'iban'              => 'ES1234567890123456789012',
				'entidade_bancaria' => '',
				'autorizacion'      => '1',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		$nif_errors = array_filter( $result['errors'], function ( $e ) {
			return 'titular_nif' === $e['field'];
		} );
		$this->assertNotEmpty( $nif_errors );
	}

	// ─── dedup by id_familia ───────────────────────────────────

	public function test_analyze_socios_iban_dedup_within_csv(): void {
		$rows = array(
			array(
				'id_familia'        => '1',
				'titular_nome'      => 'Ana',
				'titular_apelidos'  => 'García',
				'titular_nif'       => '12345678Z',
				'iban'              => 'ES1234567890123456789012',
				'entidade_bancaria' => 'Banco A',
				'autorizacion'      => '1',
			),
			array(
				'id_familia'        => '1',
				'titular_nome'      => 'Pedro',
				'titular_apelidos'  => 'López',
				'titular_nif'       => '87654321X',
				'iban'              => 'ES9876543210987654321098',
				'entidade_bancaria' => 'Banco B',
				'autorizacion'      => '1',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		// Second row is a duplicate (same id_familia).
		$this->assertContains( 1, $result['duplicates'] );
		$this->assertCount( 1, $result['to_insert'] );
	}

	public function test_analyze_socios_iban_dedup_against_existing(): void {
		$rows = array(
			array(
				'id_familia'        => '7',
				'titular_nome'      => 'Ana',
				'titular_apelidos'  => 'García',
				'titular_nif'       => '12345678Z',
				'iban'              => 'ES1234567890123456789012',
				'entidade_bancaria' => '',
				'autorizacion'      => '1',
			),
		);

		$existing = array( 'socios_iban:7' );
		$result   = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows, $existing );

		$this->assertContains( 0, $result['duplicates'] );
		$this->assertEmpty( $result['to_insert'] );
	}

	// ─── compute_natural_key for socios_iban ───────────────────

	public function test_compute_natural_key_socios_iban(): void {
		$row = array( 'id_familia' => '42' );
		$key = ANPA_Socios_Csv_Import::compute_natural_key( 'socios_iban', $row );
		$this->assertSame( 'socios_iban:42', $key );
	}

	public function test_compute_natural_key_socios_iban_empty_returns_null(): void {
		$row = array( 'id_familia' => '' );
		$this->assertNull( ANPA_Socios_Csv_Import::compute_natural_key( 'socios_iban', $row ) );
	}

	// ─── masking helpers ───────────────────────────────────────

	public function test_mask_iban_for_report_shows_last4(): void {
		$this->assertSame( '****9012', ANPA_Socios_Csv_Import::mask_iban_for_report( 'ES1234567890123456789012' ) );
	}

	public function test_mask_iban_for_report_handles_spaces(): void {
		$this->assertSame( '****9012', ANPA_Socios_Csv_Import::mask_iban_for_report( 'ES12 3456 7890 1234 5678 9012' ) );
	}

	public function test_mask_iban_for_report_short_iban(): void {
		$this->assertSame( '****', ANPA_Socios_Csv_Import::mask_iban_for_report( 'ES' ) );
	}

	public function test_mask_iban_for_report_empty(): void {
		$this->assertSame( '****', ANPA_Socios_Csv_Import::mask_iban_for_report( '' ) );
	}

	public function test_mask_nif_for_report_shows_last5(): void {
		$this->assertSame( '****5678Z', ANPA_Socios_Csv_Import::mask_nif_for_report( '12345678Z' ) );
	}

	public function test_mask_nif_for_report_short_nif(): void {
		$this->assertSame( '****678Z', ANPA_Socios_Csv_Import::mask_nif_for_report( '678Z' ) );
	}

	public function test_mask_nif_for_report_empty(): void {
		$this->assertSame( '', ANPA_Socios_Csv_Import::mask_nif_for_report( '' ) );
	}

	// ─── full flow: parse → analyze for socios_iban ────────────

	public function test_full_flow_socios_iban(): void {
		$csv = "id_familia,titular_nome,titular_apelidos,titular_nif,iban,entidade_bancaria,autorizacion\n"
			. "1,ANA MARIA,GARCIA DE LA FUENTE,12345678Z,ES12 3456 7890 1234 5678 9012,Banco Ficticio,1\n"
			. "2,pedro,lopez,87654321X,ES98 7654 3210 9876 5432 1098,Caixa Ficticia,1\n"
			. "3,,,,,Outro Banco,0\n";

		$rows   = ANPA_Socios_Csv_Import::parse( $csv );
		$result = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		// 3 rows parsed.
		$this->assertCount( 3, $result['rows'] );
		// First two normalized.
		$this->assertSame( 'Ana Maria', $result['rows'][0]['titular_nome'] );
		$this->assertSame( 'Pedro', $result['rows'][1]['titular_nome'] );
		// Third row has errors (missing required fields) but still in to_insert.
		$this->assertNotEmpty( $result['errors'] );
		// All 3 go to to_insert (errors are informational).
		$this->assertCount( 3, $result['to_insert'] );
	}

	// ─── strict `valid` excludes error rows (banking hardening) ─

	public function test_analyze_socios_iban_valid_excludes_error_rows(): void {
		$csv = "id_familia,titular_nome,titular_apelidos,titular_nif,iban,entidade_bancaria,autorizacion\n"
			. "1,ANA,GARCIA,12345678Z,ES1234567890123456789012,Banco,1\n"   // valid
			. "2,,,,,Banco,1\n";                                             // missing required → error
		$rows   = ANPA_Socios_Csv_Import::parse( $csv );
		$result = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		$this->assertArrayHasKey( 'valid', $result );
		// The error row stays in to_insert (for the report) but is NOT in valid.
		$this->assertCount( 2, $result['to_insert'] );
		$this->assertCount( 1, $result['valid'], 'valid must exclude rows with validation errors.' );
		$this->assertSame( '1', $result['valid'][0]['id_familia'] );
	}

	// ─── titular_nif normalization ─────────────────────────────

	public function test_analyze_socios_iban_normalizes_titular_nif(): void {
		$rows = array(
			array(
				'id_familia'        => '1',
				'titular_nome'      => 'Ana',
				'titular_apelidos'  => 'García',
				'titular_nif'       => ' 12345678z ',
				'iban'              => 'ES1234567890123456789012',
				'entidade_bancaria' => '',
				'autorizacion'      => '1',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios_iban', $rows );

		// NIF should be normalized (uppercase, trimmed) via ANPA_Socios_Normalize::nif().
		$nif = $result['rows'][0]['titular_nif'];
		$this->assertSame( $nif, strtoupper( trim( $nif ) ) );
	}
}
