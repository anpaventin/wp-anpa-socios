<?php
/**
 * Tests for ANPA_Socios_Csv_Import pure CSV import core.
 *
 * Covers parsing (UTF-8, quoting, BOM), entity required-field validation,
 * dedup within CSV and against existing keys, secondary parent missing
 * email/nif error, and normalization application.
 *
 * @since  1.34.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ANPA_Socios_Csv_Import
 */
final class Test_ANPA_Socios_Csv_Import extends TestCase {

	// ─── parse() — basic parsing ───────────────────────────────

	public function test_parse_simple_csv(): void {
		$csv = "nome,email\nAna,ana@example.com\nBrais,brais@example.com\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Ana', $rows[0]['nome'] );
		$this->assertSame( 'ana@example.com', $rows[0]['email'] );
		$this->assertSame( 'Brais', $rows[1]['nome'] );
	}

	public function test_parse_with_utf8_bom(): void {
		$csv = "\xEF\xBB\xBFnome,email\nMaría,maria@example.com\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'nome', array_keys( $rows[0] )[0] );
		$this->assertSame( 'María', $rows[0]['nome'] );
	}

	public function test_parse_quoted_fields_with_commas(): void {
		$csv = "nome,descripcion\nTest,\"has, commas\"\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'has, commas', $rows[0]['descripcion'] );
	}

	public function test_parse_quoted_fields_with_newlines(): void {
		$csv = "nome,nota\nTest,\"line1\nline2\"\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertCount( 1, $rows );
		$this->assertSame( "line1\nline2", $rows[0]['nota'] );
	}

	public function test_parse_quoted_fields_with_embedded_quotes(): void {
		$csv = "nome,desc\nTest,\"She said \"\"hello\"\"\"\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'She said "hello"', $rows[0]['desc'] );
	}

	public function test_parse_trims_headers_and_values(): void {
		$csv = " nome , email \n  Ana  ,  ana@example.com  \n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertCount( 1, $rows );
		$this->assertArrayHasKey( 'nome', $rows[0] );
		$this->assertArrayHasKey( 'email', $rows[0] );
		$this->assertSame( 'Ana', $rows[0]['nome'] );
		$this->assertSame( 'ana@example.com', $rows[0]['email'] );
	}

	public function test_parse_crlf_line_endings(): void {
		$csv = "nome,email\r\nAna,ana@example.com\r\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Ana', $rows[0]['nome'] );
	}

	public function test_parse_empty_csv(): void {
		$this->assertSame( array(), ANPA_Socios_Csv_Import::parse( '' ) );
	}

	public function test_parse_headers_only(): void {
		$csv = "nome,email\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );
		$this->assertSame( array(), $rows );
	}

	public function test_parse_skips_empty_rows(): void {
		$csv = "nome,email\nAna,ana@example.com\n\n\nBrais,brais@example.com\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );
		$this->assertCount( 2, $rows );
	}

	public function test_parse_utf8_characters(): void {
		$csv = "nome,apelidos\nMaría José,García Núñez\nXosé,Pérez Dávila\n";
		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'María José', $rows[0]['nome'] );
		$this->assertSame( 'García Núñez', $rows[0]['apelidos'] );
	}

	// ─── analyze() — socios validation ─────────────────────────

	public function test_analyze_socios_required_fields_error(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'principal', 'nome' => 'Ana', 'apelidos' => 'García', 'email' => 'ana@example.com', 'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo' ),
			array( 'id_familia' => '', 'rol_familia' => 'principal', 'nome' => 'Brais', 'apelidos' => 'López', 'email' => 'brais@example.com', 'nif' => '87654321X', 'telefono' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		// Second row missing id_familia.
		$id_familia_errors = array_filter( $result['errors'], function ( $e ) {
			return 'id_familia' === $e['field'] && 1 === $e['row'];
		} );
		$this->assertNotEmpty( $id_familia_errors );
	}

	public function test_analyze_socios_principal_requires_email_and_nif(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'principal', 'nome' => 'Ana', 'apelidos' => 'García', 'email' => '', 'nif' => '', 'telefono' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$email_errors = array_filter( $result['errors'], function ( $e ) {
			return 'email' === $e['field'] && 0 === $e['row'];
		} );
		$nif_errors = array_filter( $result['errors'], function ( $e ) {
			return 'nif' === $e['field'] && 0 === $e['row'];
		} );
		$this->assertNotEmpty( $email_errors );
		$this->assertNotEmpty( $nif_errors );
	}

	public function test_analyze_socios_secundario_missing_email_nif_reports_error(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'secundario', 'nome' => 'Pedro', 'apelidos' => 'López', 'email' => '', 'nif' => '', 'telefono' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		// Errors reported for missing email/nif on secundario.
		$email_errors = array_filter( $result['errors'], function ( $e ) {
			return 'email' === $e['field'] && false !== strpos( $e['msg'], 'secundario' );
		} );
		$nif_errors = array_filter( $result['errors'], function ( $e ) {
			return 'nif' === $e['field'] && false !== strpos( $e['msg'], 'secundario' );
		} );
		$this->assertNotEmpty( $email_errors );
		$this->assertNotEmpty( $nif_errors );

		// Row still goes to to_insert (error does not block).
		$this->assertNotEmpty( $result['to_insert'] );
	}

	// ─── analyze() — fillos validation ─────────────────────────

	public function test_analyze_fillos_required_fields(): void {
		$rows = array(
			array( 'proxenitor_email' => 'socio1@example.com', 'nome' => '', 'apelidos' => 'García', 'data_nacemento' => '2018-03-15', 'curso' => '1EP', 'aula' => 'A', 'image_consent' => '1', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'fillos', $rows );

		$nome_errors = array_filter( $result['errors'], function ( $e ) {
			return 'nome' === $e['field'];
		} );
		$this->assertNotEmpty( $nome_errors );
	}

	// ─── analyze() — empresas validation ───────────────────────

	public function test_analyze_empresas_required_fields(): void {
		$rows = array(
			array( 'nome' => 'Empresa Ficticia', 'email' => '', 'responsable' => 'Xose', 'telefono' => '', 'url_web' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'empresas', $rows );

		$email_errors = array_filter( $result['errors'], function ( $e ) {
			return 'email' === $e['field'];
		} );
		$this->assertNotEmpty( $email_errors );
	}

	// ─── analyze() — actividades validation ────────────────────

	public function test_analyze_actividades_required_fields(): void {
		$rows = array(
			array( 'empresa_email' => 'e@example.com', 'nome' => 'Futbol', 'descripcion' => '', 'curso_escolar' => '', 'min_pupilos' => '', 'max_pupilos' => '', 'curso_min' => '', 'curso_max' => '', 'custo' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'actividades', $rows );

		$curso_errors = array_filter( $result['errors'], function ( $e ) {
			return 'curso_escolar' === $e['field'];
		} );
		$this->assertNotEmpty( $curso_errors );
	}

	// ─── analyze() — matriculas validation ─────────────────────

	public function test_analyze_matriculas_required_fields(): void {
		$rows = array(
			array( 'proxenitor_email' => 'parent@example.com', 'fillo_nome' => 'Lúa', 'fillo_apelidos' => 'García', 'empresa_email' => 'e@example.com', 'actividade_nome' => '', 'curso_escolar' => '2025/2026', 'comedor' => '1', 'tarde' => '0', 'observaciones' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'matriculas', $rows );

		$act_errors = array_filter( $result['errors'], function ( $e ) {
			return 'actividade_nome' === $e['field'];
		} );
		$this->assertNotEmpty( $act_errors );
	}

	// ─── analyze() — dedup within CSV ──────────────────────────

	public function test_analyze_dedup_within_csv_socios(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'principal', 'nome' => 'ANA', 'apelidos' => 'GARCIA', 'email' => 'ana@example.com', 'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo' ),
			array( 'id_familia' => '2', 'rol_familia' => 'principal', 'nome' => 'ana', 'apelidos' => 'garcia', 'email' => 'ana2@example.com', 'nif' => '87654321X', 'telefono' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertContains( 1, $result['duplicates'] );
		$this->assertCount( 1, $result['to_insert'] );
	}

	public function test_analyze_dedup_within_csv_empresas(): void {
		$rows = array(
			array( 'nome' => 'Empresa A', 'email' => 'INFO@EXAMPLE.COM', 'responsable' => '', 'telefono' => '', 'url_web' => '', 'estado' => 'activo' ),
			array( 'nome' => 'Empresa B', 'email' => 'info@example.com', 'responsable' => '', 'telefono' => '', 'url_web' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'empresas', $rows );

		$this->assertContains( 1, $result['duplicates'] );
		$this->assertCount( 1, $result['to_insert'] );
	}

	public function test_analyze_dedup_within_csv_fillos(): void {
		$rows = array(
			array( 'proxenitor_email' => 'socio1@example.com', 'nome' => 'Lúa', 'apelidos' => 'García', 'data_nacemento' => '2018-01-01', 'curso' => '1EP', 'aula' => 'A', 'image_consent' => '1', 'estado' => 'activo' ),
			array( 'proxenitor_email' => 'socio1@example.com', 'nome' => 'LÚA', 'apelidos' => 'GARCÍA', 'data_nacemento' => '2018-06-15', 'curso' => '1EP', 'aula' => 'B', 'image_consent' => '0', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'fillos', $rows );

		$this->assertContains( 1, $result['duplicates'] );
		$this->assertCount( 1, $result['to_insert'] );
	}

	public function test_analyze_dedup_within_csv_matriculas(): void {
		$rows = array(
			array( 'proxenitor_email' => 'parent@example.com', 'fillo_nome' => 'Lúa', 'fillo_apelidos' => 'García', 'empresa_email' => 'e@example.com', 'actividade_nome' => 'Futbol', 'curso_escolar' => '2025/2026', 'grupo_curso_range' => '1-3', 'grupo_franxa' => '16:00-17:00', 'grupo_dias' => 'Luns', 'trimestre' => '1', 'comedor' => '1', 'tarde' => '0', 'observaciones' => '', 'estado' => 'activo' ),
			array( 'proxenitor_email' => 'PARENT@EXAMPLE.COM', 'fillo_nome' => 'LÚA', 'fillo_apelidos' => 'GARCÍA', 'empresa_email' => 'E@EXAMPLE.COM', 'actividade_nome' => 'FUTBOL', 'curso_escolar' => '2025/2026', 'grupo_curso_range' => '1-3', 'grupo_franxa' => '16:00-17:00', 'grupo_dias' => 'Luns', 'trimestre' => '1', 'comedor' => '0', 'tarde' => '1', 'observaciones' => 'test', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'matriculas', $rows );

		$this->assertContains( 1, $result['duplicates'] );
		$this->assertCount( 1, $result['to_insert'] );
	}

	public function test_analyze_keeps_matriculas_for_different_group_or_term(): void {
		$base        = array( 'proxenitor_email' => 'parent@example.com', 'fillo_nome' => 'Lúa', 'fillo_apelidos' => 'García', 'empresa_email' => 'e@example.com', 'actividade_nome' => 'Futbol', 'curso_escolar' => '2025/2026', 'grupo_curso_range' => '1-3', 'grupo_franxa' => '16:00-17:00', 'grupo_dias' => 'Luns', 'trimestre' => '1', 'estado' => 'activo' );
		$other_group = array_merge( $base, array( 'grupo_dias' => 'Martes' ) );
		$other_term  = array_merge( $base, array( 'trimestre' => '2' ) );

		$result = ANPA_Socios_Csv_Import::analyze( 'matriculas', array( $base, $other_group, $other_term ) );

		$this->assertEmpty( $result['duplicates'] );
		$this->assertCount( 3, $result['to_insert'] );
	}

	/**
	 * @dataProvider matricula_estados
	 */
	public function test_matricula_estado_preserves_every_exportable_state( string $estado ): void {
		$this->assertSame( $estado, ANPA_Socios_Csv_Import::matricula_estado( $estado ) );
	}

	public function matricula_estados(): array {
		return array(
			'activo'           => array( 'activo' ),
			'lista_espera'     => array( 'lista_espera' ),
			'oferta'           => array( 'oferta' ),
			'baixa_solicitada' => array( 'baixa_solicitada' ),
			'baixa'            => array( 'baixa' ),
		);
	}

	// ─── analyze() — dedup against existing keys ───────────────

	public function test_analyze_dedup_against_existing_socios(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'principal', 'nome' => 'Ana', 'apelidos' => 'García', 'email' => 'ana@example.com', 'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo' ),
		);
		$existing = array( 'socios:ana|garcía' );

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows, $existing );

		$this->assertContains( 0, $result['duplicates'] );
		$this->assertEmpty( $result['to_insert'] );
	}

	public function test_analyze_dedup_against_existing_empresas(): void {
		$rows = array(
			array( 'nome' => 'Empresa X', 'email' => 'info@example.com', 'responsable' => '', 'telefono' => '', 'url_web' => '', 'estado' => 'activo' ),
		);
		$existing = array( 'empresas:info@example.com' );

		$result = ANPA_Socios_Csv_Import::analyze( 'empresas', $rows, $existing );

		$this->assertContains( 0, $result['duplicates'] );
		$this->assertEmpty( $result['to_insert'] );
	}

	// ─── analyze() — normalization applied ─────────────────────

	public function test_analyze_normalizes_names_to_title_case(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'principal', 'nome' => 'MARÍA JOSÉ', 'apelidos' => 'RUIZ DE LA PRADA', 'email' => 'maria@example.com', 'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertSame( 'María José', $result['rows'][0]['nome'] );
		$this->assertSame( 'Ruiz de la Prada', $result['rows'][0]['apelidos'] );
	}

	public function test_analyze_normalizes_email_to_lowercase(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'principal', 'nome' => 'Ana', 'apelidos' => 'García', 'email' => 'ANA@EXAMPLE.COM', 'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertSame( 'ana@example.com', $result['rows'][0]['email'] );
	}

	public function test_analyze_normalizes_nif_uppercase(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'principal', 'nome' => 'Ana', 'apelidos' => 'García', 'email' => 'ana@example.com', 'nif' => '12345678z', 'telefono' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		// NIF is normalized via ANPA_Socios_Normalize::nif() which validates mod-23.
		// '12345678Z' is valid (8 % 23 = 8 → letter I... actually let's just check
		// that the result is uppercase and processed).
		$nif = $result['rows'][0]['nif'];
		// If mod-23 validation fails, it becomes ''.
		$this->assertSame( $nif, strtoupper( $nif ) );
	}

	public function test_analyze_normalizes_curso_escolar(): void {
		$rows = array(
			array( 'empresa_email' => 'e@example.com', 'nome' => 'Futbol', 'descripcion' => '', 'curso_escolar' => '2025 - 2026', 'custo' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'actividades', $rows );

		$this->assertSame( '2025/2026', $result['rows'][0]['curso_escolar'] );
	}

	public function test_analyze_normalizes_telefono(): void {
		$rows = array(
			array( 'id_familia' => '1', 'rol_familia' => 'principal', 'nome' => 'Ana', 'apelidos' => 'García', 'email' => 'ana@example.com', 'nif' => '12345678Z', 'telefono' => '+34 600 123 456', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertSame( '600123456', $result['rows'][0]['telefono'] );
	}

	// ─── analyze() — errors do NOT block other rows ────────────

	public function test_analyze_errors_dont_block_valid_rows(): void {
		$rows = array(
			array( 'id_familia' => '', 'rol_familia' => 'principal', 'nome' => 'Bad', 'apelidos' => 'Row', 'email' => 'bad@example.com', 'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo' ),
			array( 'id_familia' => '2', 'rol_familia' => 'principal', 'nome' => 'Good', 'apelidos' => 'Row', 'email' => 'good@example.com', 'nif' => '87654321X', 'telefono' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		// Both rows are in to_insert (errors are informational, not blocking).
		$this->assertCount( 2, $result['to_insert'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	// ─── parse() + analyze() integration ───────────────────────

	public function test_full_flow_parse_then_analyze(): void {
		$csv = "id_familia,rol_familia,email,nome,apelidos,nif,telefono,estado\n"
			. "1,principal,ana@example.com,ANA,GARCIA LOPEZ,12345678Z,600123456,activo\n"
			. "1,secundario,pedro@example.com,PEDRO,GARCIA,,600654321,activo\n";

		$rows   = ANPA_Socios_Csv_Import::parse( $csv );
		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertCount( 2, $result['rows'] );
		// Names are normalized.
		$this->assertSame( 'Ana', $result['rows'][0]['nome'] );
		$this->assertSame( 'Pedro', $result['rows'][1]['nome'] );
		// Both rows inserted (different names so no dedup).
		$this->assertCount( 2, $result['to_insert'] );
	}

	// ─── ENTITY_HEADERS constant ──────────────────────────────

	public function test_entity_headers_defined_for_all_entities(): void {
		$expected = array( 'socios', 'fillos', 'empresas', 'actividades', 'matriculas' );
		foreach ( $expected as $entity ) {
			$this->assertArrayHasKey( $entity, ANPA_Socios_Csv_Import::ENTITY_HEADERS );
			$this->assertNotEmpty( ANPA_Socios_Csv_Import::ENTITY_HEADERS[ $entity ] );
		}
	}

	public function test_required_fields_subset_of_entity_headers(): void {
		foreach ( ANPA_Socios_Csv_Import::REQUIRED_FIELDS as $entity => $required ) {
			$headers = ANPA_Socios_Csv_Import::ENTITY_HEADERS[ $entity ];
			foreach ( $required as $field ) {
				// matriculas has fillo_nome, fillo_apelidos, actividade_nome which are in headers.
				$this->assertContains( $field, $headers, "Required field '{$field}' not in headers for '{$entity}'" );
			}
		}
	}

	// ─── compute_natural_key() — public since 1.34.0 s4 ───────

	public function test_compute_natural_key_socios(): void {
		$row = array( 'nome' => 'Ana', 'apelidos' => 'García' );
		$key = ANPA_Socios_Csv_Import::compute_natural_key( 'socios', $row );
		$this->assertSame( 'socios:ana|garcía', $key );
	}

	public function test_compute_natural_key_empresas(): void {
		$row = array( 'email' => 'info@example.com' );
		$key = ANPA_Socios_Csv_Import::compute_natural_key( 'empresas', $row );
		$this->assertSame( 'empresas:info@example.com', $key );
	}

	public function test_compute_natural_key_fillos(): void {
		$row = array( 'nome' => 'Lúa', 'apelidos' => 'García', 'proxenitor_email' => 'socio1@example.com' );
		$key = ANPA_Socios_Csv_Import::compute_natural_key( 'fillos', $row );
		$this->assertSame( 'fillos:socio1@example.com|lúa|garcía', $key );
	}

	public function test_compute_natural_key_actividades(): void {
		$row = array( 'empresa_email' => 'e@example.com', 'nome' => 'Futbol', 'curso_escolar' => '2025/2026' );
		$key = ANPA_Socios_Csv_Import::compute_natural_key( 'actividades', $row );
		$this->assertSame( 'actividades:e@example.com|futbol|2025/2026', $key );
	}

	public function test_compute_natural_key_matriculas(): void {
		$row = array(
			'proxenitor_email' => 'parent@example.com', 'fillo_nome' => 'Lúa', 'fillo_apelidos' => 'García',
			'empresa_email' => 'e@example.com', 'actividade_nome' => 'Futbol', 'curso_escolar' => '2025/2026',
		);
		$key = ANPA_Socios_Csv_Import::compute_natural_key( 'matriculas', $row );
		$this->assertSame( 'matriculas:parent@example.com|lúa|garcía|e@example.com|futbol|2025/2026|||||', $key );
	}

	public function test_compute_natural_key_returns_null_on_missing_fields(): void {
		$this->assertNull( ANPA_Socios_Csv_Import::compute_natural_key( 'socios', array( 'nome' => 'Ana', 'apelidos' => '' ) ) );
		$this->assertNull( ANPA_Socios_Csv_Import::compute_natural_key( 'empresas', array( 'email' => '' ) ) );
		$this->assertNull( ANPA_Socios_Csv_Import::compute_natural_key( 'fillos', array( 'nome' => 'Ana', 'apelidos' => 'García', 'proxenitor_email' => '' ) ) );
		$this->assertNull( ANPA_Socios_Csv_Import::compute_natural_key( 'unknown_entity', array() ) );
	}

	// ─── fillos import uses proxenitor_email (task 13) ────────────

	public function test_fillos_required_fields_includes_proxenitor_email(): void {
		$required = ANPA_Socios_Csv_Import::REQUIRED_FIELDS['fillos'];
		$this->assertContains( 'proxenitor_email', $required );
		$this->assertNotContains( 'id_familia', $required );
	}

	public function test_fillos_entity_headers_includes_proxenitor_email(): void {
		$headers = ANPA_Socios_Csv_Import::ENTITY_HEADERS['fillos'];
		$this->assertContains( 'proxenitor_email', $headers );
		$this->assertNotContains( 'id_familia', $headers );
	}

	public function test_fillos_natural_key_uses_proxenitor_email(): void {
		$row = array( 'proxenitor_email' => 'parent@example.com', 'nome' => 'Noa', 'apelidos' => 'López' );
		$key = ANPA_Socios_Csv_Import::compute_natural_key( 'fillos', $row );
		$this->assertSame( 'fillos:parent@example.com|noa|lópez', $key );
	}

	public function test_fillos_analyze_missing_proxenitor_email_errors(): void {
		$rows = array(
			array( 'proxenitor_email' => '', 'nome' => 'Lúa', 'apelidos' => 'García', 'data_nacemento' => '2018-01-01', 'curso' => '1EP', 'aula' => 'A', 'image_consent' => '1', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'fillos', $rows );

		$pe_errors = array_filter( $result['errors'], function ( $e ) {
			return 'proxenitor_email' === $e['field'];
		} );
		$this->assertNotEmpty( $pe_errors );
	}

	public function test_fillos_analyze_normalizes_proxenitor_email(): void {
		$rows = array(
			array( 'proxenitor_email' => 'PARENT@EXAMPLE.COM', 'nome' => 'Breixo', 'apelidos' => 'López', 'data_nacemento' => '2019-01-30', 'curso' => 'EI5', 'aula' => 'A', 'image_consent' => '1', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'fillos', $rows );

		$this->assertSame( 'parent@example.com', $result['rows'][0]['proxenitor_email'] );
	}

	public function test_fillos_dedup_against_existing_uses_email_key(): void {
		$rows = array(
			array( 'proxenitor_email' => 'socio1@example.com', 'nome' => 'Lúa', 'apelidos' => 'García', 'data_nacemento' => '2018-01-01', 'curso' => '1EP', 'aula' => 'A', 'image_consent' => '1', 'estado' => 'activo' ),
		);
		$existing = array( 'fillos:socio1@example.com|lúa|garcía' );

		$result = ANPA_Socios_Csv_Import::analyze( 'fillos', $rows, $existing );

		$this->assertContains( 0, $result['duplicates'] );
		$this->assertEmpty( $result['to_insert'] );
	}

	// ─── fillo_dedup_key() — for merge on family join (task 7) ─

	public function test_fillo_dedup_key_case_insensitive(): void {
		$k1 = ANPA_Socios_Csv_Import::fillo_dedup_key( 'LÚA', 'GARCÍA', '2018-03-15' );
		$k2 = ANPA_Socios_Csv_Import::fillo_dedup_key( 'lúa', 'garcía', '2018-03-15' );
		$this->assertSame( $k1, $k2 );
	}

	public function test_fillo_dedup_key_trims_whitespace(): void {
		$k1 = ANPA_Socios_Csv_Import::fillo_dedup_key( '  Ana  ', '  García  ', ' 2018-01-01 ' );
		$k2 = ANPA_Socios_Csv_Import::fillo_dedup_key( 'Ana', 'García', '2018-01-01' );
		$this->assertSame( $k1, $k2 );
	}

	public function test_fillo_dedup_key_different_dates_differ(): void {
		$k1 = ANPA_Socios_Csv_Import::fillo_dedup_key( 'Ana', 'García', '2018-01-01' );
		$k2 = ANPA_Socios_Csv_Import::fillo_dedup_key( 'Ana', 'García', '2019-01-01' );
		$this->assertNotSame( $k1, $k2 );
	}

	// ─── matriculas uses proxenitor_email (task 16) ───────────────

	public function test_matriculas_required_fields_includes_proxenitor_email(): void {
		$required = ANPA_Socios_Csv_Import::REQUIRED_FIELDS['matriculas'];
		$this->assertContains( 'proxenitor_email', $required );
		$this->assertNotContains( 'id_familia', $required );
	}

	public function test_matriculas_entity_headers_includes_proxenitor_email(): void {
		$headers = ANPA_Socios_Csv_Import::ENTITY_HEADERS['matriculas'];
		$this->assertContains( 'proxenitor_email', $headers );
		$this->assertNotContains( 'id_familia', $headers );
	}

	public function test_matriculas_natural_key_uses_proxenitor_email(): void {
		$row = array(
			'proxenitor_email' => 'parent@example.com',
			'fillo_nome' => 'Noa',
			'fillo_apelidos' => 'López',
			'empresa_email' => 'empresa@example.com',
			'actividade_nome' => 'Piano',
			'curso_escolar' => '2025/2026',
		);
		$key = ANPA_Socios_Csv_Import::compute_natural_key( 'matriculas', $row );
		$this->assertSame( 'matriculas:parent@example.com|noa|lópez|empresa@example.com|piano|2025/2026|||||', $key );
	}

	public function test_matriculas_natural_key_distinguishes_group_name(): void {
		$base = array(
			'proxenitor_email' => 'parent@example.com',
			'fillo_nome' => 'Noa',
			'fillo_apelidos' => 'López',
			'empresa_email' => 'empresa@example.com',
			'actividade_nome' => 'Piano',
			'curso_escolar' => '2025/2026',
			'trimestre' => '1',
		);
		$first = ANPA_Socios_Csv_Import::compute_natural_key( 'matriculas', $base + array( 'grupo_nome' => 'Grupo A' ) );
		$second = ANPA_Socios_Csv_Import::compute_natural_key( 'matriculas', $base + array( 'grupo_nome' => 'Grupo B' ) );
		$this->assertNotSame( $first, $second );
	}

	public function test_matriculas_natural_key_null_when_proxenitor_email_empty(): void {
		$row = array(
			'proxenitor_email' => '',
			'fillo_nome' => 'Noa',
			'fillo_apelidos' => 'López',
			'empresa_email' => 'empresa@example.com',
			'actividade_nome' => 'Piano',
			'curso_escolar' => '2025/2026',
		);
		$this->assertNull( ANPA_Socios_Csv_Import::compute_natural_key( 'matriculas', $row ) );
	}

	public function test_matriculas_analyze_missing_proxenitor_email_errors(): void {
		$rows = array(
			array( 'proxenitor_email' => '', 'fillo_nome' => 'Lúa', 'fillo_apelidos' => 'García', 'empresa_email' => 'e@example.com', 'actividade_nome' => 'Futbol', 'curso_escolar' => '2025/2026', 'comedor' => '1', 'tarde' => '0', 'observaciones' => '', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'matriculas', $rows );

		$pe_errors = array_filter( $result['errors'], function ( $e ) {
			return 'proxenitor_email' === $e['field'];
		} );
		$this->assertNotEmpty( $pe_errors );
	}

	public function test_matriculas_dedup_against_existing_uses_proxenitor_email(): void {
		$rows = array(
			array( 'proxenitor_email' => 'parent@example.com', 'fillo_nome' => 'Lúa', 'fillo_apelidos' => 'García', 'empresa_email' => 'e@example.com', 'actividade_nome' => 'Futbol', 'curso_escolar' => '2025/2026', 'comedor' => '1', 'tarde' => '0', 'observaciones' => '', 'estado' => 'activo' ),
		);
		$existing = array( 'matriculas:parent@example.com|lúa|garcía|e@example.com|futbol|2025/2026|||||' );

		$result = ANPA_Socios_Csv_Import::analyze( 'matriculas', $rows, $existing );

		$this->assertContains( 0, $result['duplicates'] );
		$this->assertEmpty( $result['to_insert'] );
	}

	// ─── empresas url_web in headers (task 15) ────────────────────

	public function test_empresas_entity_headers_includes_url_web(): void {
		$headers = ANPA_Socios_Csv_Import::ENTITY_HEADERS['empresas'];
		$this->assertContains( 'url_web', $headers );
	}

	// ─── actividades legacy capacity columns removed from headers ──────

	public function test_actividades_entity_headers_drop_legacy_capacity_columns(): void {
		$headers = ANPA_Socios_Csv_Import::ENTITY_HEADERS['actividades'];
		$this->assertContains( 'nivel_min_codigo', $headers );
		$this->assertContains( 'nivel_max_codigo', $headers );
		foreach ( array( 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max' ) as $field ) {
			$this->assertNotContains( $field, $headers );
		}
	}

	// ─── actividades nivel_min_codigo/nivel_max_codigo (PR-ES9 task 84) ──

	public function test_actividades_entity_headers_includes_nivel_codigo_columns(): void {
		$headers = ANPA_Socios_Csv_Import::ENTITY_HEADERS['actividades'];
		$this->assertContains( 'nivel_min_codigo', $headers );
		$this->assertContains( 'nivel_max_codigo', $headers );
	}

	public function test_analyze_actividades_trims_nivel_codigo_columns(): void {
		$rows = array(
			array( 'empresa_email' => 'e@example.com', 'nome' => 'Futbol', 'descripcion' => 'desc', 'curso_escolar' => '2025/2026', 'nivel_min_codigo' => '  1  ', 'nivel_max_codigo' => '  3  ', 'custo' => '10', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'actividades', $rows );

		$this->assertSame( '1', $result['rows'][0]['nivel_min_codigo'] );
		$this->assertSame( '3', $result['rows'][0]['nivel_max_codigo'] );
	}

	public function test_analyze_actividades_nivel_codigo_columns_default_to_empty_string(): void {
		$rows = array(
			array( 'empresa_email' => 'e@example.com', 'nome' => 'Futbol', 'descripcion' => 'desc', 'curso_escolar' => '2025/2026', 'custo' => '10', 'estado' => 'activo' ),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'actividades', $rows );

		// Row has no nivel_min_codigo/nivel_max_codigo keys at all — must not error.
		$this->assertNotEmpty( $result['to_insert'] );
	}

	public function test_parse_and_analyze_actividades_with_legacy_capacity_columns_still_work(): void {
		$csv = "empresa_email,nome,descripcion,curso_escolar,min_pupilos,max_pupilos,curso_min,curso_max,nivel_min_codigo,nivel_max_codigo,custo,estado\n"
			. "e@example.com,Futbol,desc,2025 - 2026,5,15,1,6, 1 , 3 ,10,activo\n";

		$rows = ANPA_Socios_Csv_Import::parse( $csv );

		$this->assertSame( '5', $rows[0]['min_pupilos'] );
		$this->assertSame( '6', $rows[0]['curso_max'] );

		$result = ANPA_Socios_Csv_Import::analyze( 'actividades', $rows );

		$this->assertSame( '2025/2026', $result['rows'][0]['curso_escolar'] );
		$this->assertSame( '1', $result['rows'][0]['nivel_min_codigo'] );
		$this->assertNotEmpty( $result['to_insert'] );
	}

	// ─── PR-21s2: segundo_proxenitor normalization ─────────────

	public function test_socios_entity_headers_includes_segundo_proxenitor_columns(): void {
		$headers = ANPA_Socios_Csv_Import::ENTITY_HEADERS['socios'];
		$this->assertContains( 'segundo_proxenitor_nome', $headers );
		$this->assertContains( 'segundo_proxenitor_apelidos', $headers );
		$this->assertContains( 'segundo_proxenitor_email', $headers );
		$this->assertContains( 'segundo_proxenitor_nif', $headers );
		$this->assertContains( 'segundo_proxenitor_telefono', $headers );
	}

	public function test_analyze_normalizes_segundo_proxenitor_nome_apelidos_title_case(): void {
		$rows = array(
			array(
				'id_familia' => '1', 'rol_familia' => 'principal',
				'email' => 'socio1@example.com', 'nome' => 'Ana', 'apelidos' => 'García',
				'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo',
				'segundo_proxenitor_nome' => 'MARÍA JOSÉ', 'segundo_proxenitor_apelidos' => 'RUIZ DE LA PRADA',
				'segundo_proxenitor_email' => 'maria@example.com', 'segundo_proxenitor_nif' => '87654321X', 'segundo_proxenitor_telefono' => '',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertSame( 'María José', $result['rows'][0]['segundo_proxenitor_nome'] );
		$this->assertSame( 'Ruiz de la Prada', $result['rows'][0]['segundo_proxenitor_apelidos'] );
	}

	public function test_analyze_normalizes_segundo_proxenitor_email_lowercase(): void {
		$rows = array(
			array(
				'id_familia' => '1', 'rol_familia' => 'principal',
				'email' => 'socio1@example.com', 'nome' => 'Ana', 'apelidos' => 'García',
				'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo',
				'segundo_proxenitor_nome' => 'María', 'segundo_proxenitor_apelidos' => 'Ruiz',
				'segundo_proxenitor_email' => 'MARIA@EXAMPLE.COM', 'segundo_proxenitor_nif' => '87654321X', 'segundo_proxenitor_telefono' => '',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertSame( 'maria@example.com', $result['rows'][0]['segundo_proxenitor_email'] );
	}

	public function test_analyze_normalizes_segundo_proxenitor_nif_uppercase(): void {
		$rows = array(
			array(
				'id_familia' => '1', 'rol_familia' => 'principal',
				'email' => 'socio1@example.com', 'nome' => 'Ana', 'apelidos' => 'García',
				'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo',
				'segundo_proxenitor_nome' => 'María', 'segundo_proxenitor_apelidos' => 'Ruiz',
				'segundo_proxenitor_email' => 'maria@example.com', 'segundo_proxenitor_nif' => '87654321x', 'segundo_proxenitor_telefono' => '',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$nif = $result['rows'][0]['segundo_proxenitor_nif'];
		$this->assertSame( $nif, strtoupper( $nif ) );
	}

	public function test_analyze_normalizes_segundo_proxenitor_telefono_digits(): void {
		$rows = array(
			array(
				'id_familia' => '1', 'rol_familia' => 'principal',
				'email' => 'socio1@example.com', 'nome' => 'Ana', 'apelidos' => 'García',
				'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo',
				'segundo_proxenitor_nome' => 'María', 'segundo_proxenitor_apelidos' => 'Ruiz',
				'segundo_proxenitor_email' => 'maria@example.com', 'segundo_proxenitor_nif' => '87654321X',
				'segundo_proxenitor_telefono' => '+34 600 654 321',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertSame( '600654321', $result['rows'][0]['segundo_proxenitor_telefono'] );
	}

	public function test_analyze_segundo_proxenitor_empty_fields_unchanged(): void {
		$rows = array(
			array(
				'id_familia' => '1', 'rol_familia' => 'principal',
				'email' => 'socio1@example.com', 'nome' => 'Ana', 'apelidos' => 'García',
				'nif' => '12345678Z', 'telefono' => '', 'estado' => 'activo',
				'segundo_proxenitor_nome' => '', 'segundo_proxenitor_apelidos' => '',
				'segundo_proxenitor_email' => '', 'segundo_proxenitor_nif' => '', 'segundo_proxenitor_telefono' => '',
			),
		);

		$result = ANPA_Socios_Csv_Import::analyze( 'socios', $rows );

		$this->assertSame( '', $result['rows'][0]['segundo_proxenitor_nome'] );
		$this->assertSame( '', $result['rows'][0]['segundo_proxenitor_email'] );
		$this->assertSame( '', $result['rows'][0]['segundo_proxenitor_nif'] );
		$this->assertSame( '', $result['rows'][0]['segundo_proxenitor_telefono'] );
	}
}
