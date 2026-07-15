<?php
/**
 * Unit tests for ANPA_Socios_Admin_Payload pure helpers.
 *
 * @since  1.2.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Admin_Payload extends TestCase {

	// ──────────────────────────────────────────────
	// validar_fillo
	// ──────────────────────────────────────────────

	public function test_validar_fillo_ok(): void {
		$result = ANPA_Socios_Admin_Payload::validar_fillo( array(
			'nome'           => 'María',
			'apelidos'       => 'Pérez García',
			'data_nacemento' => '2015-04-12',
			'curso'          => '3',
			'aula'           => 'A',
		) );
		$this->assertIsArray( $result );
		$this->assertSame( 'María', $result['nome'] );
	}

	public function test_validar_fillo_missing_fields(): void {
		$result = ANPA_Socios_Admin_Payload::validar_fillo( array(
			'nome' => 'María',
		) );
		$this->assertNull( $result );
	}

	public function test_validar_fillo_invalid_data(): void {
		$result = ANPA_Socios_Admin_Payload::validar_fillo( array(
			'nome'           => 'María',
			'apelidos'       => 'Pérez',
			'data_nacemento' => 'not-a-date',
			'curso'          => '3',
			'aula'           => 'A',
		) );
		$this->assertNull( $result );
	}

	public function test_validar_fillo_rejects_long_nome(): void {
		$result = ANPA_Socios_Admin_Payload::validar_fillo( array(
			'nome'           => str_repeat( 'a', 51 ),
			'apelidos'       => 'Pérez',
			'data_nacemento' => '2015-04-12',
			'curso'          => '3',
			'aula'           => 'A',
		) );
		$this->assertNull( $result );
	}

	// ──────────────────────────────────────────────
	// validar_empresa
	// ──────────────────────────────────────────────

	public function test_validar_empresa_ok(): void {
		$result = ANPA_Socios_Admin_Payload::validar_empresa( array(
			'nome'         => 'Escola de Teatro',
			'email'        => 'teatro@example.org',
			'responsable'  => 'Ana López',
			'telefono'     => '666111222',
			'url_web'      => 'https://teatro.example.com',
		) );
		$this->assertIsArray( $result );
		$this->assertSame( 'Escola de Teatro', $result['nome'] );
		$this->assertSame( 'https://teatro.example.com', $result['url_web'] );
	}

	public function test_validar_empresa_url_web_optional(): void {
		$result = ANPA_Socios_Admin_Payload::validar_empresa( array(
			'nome'         => 'Escola de Teatro',
			'email'        => 'teatro@example.org',
			'responsable'  => 'Ana López',
			'telefono'     => '666111222',
		) );
		$this->assertIsArray( $result );
		$this->assertSame( '', $result['url_web'] );
	}

	public function test_validar_empresa_invalid_email(): void {
		$result = ANPA_Socios_Admin_Payload::validar_empresa( array(
			'nome'         => 'Escola',
			'email'        => 'non-eh-un-email',
			'responsable'  => 'Ana',
			'telefono'     => '666',
		) );
		$this->assertNull( $result );
	}

	public function test_validar_empresa_missing_fields(): void {
		$result = ANPA_Socios_Admin_Payload::validar_empresa( array(
			'nome'  => 'Escola',
			'email' => 'a@b.es',
		) );
		$this->assertNull( $result );
	}

	public function test_diagnosticar_empresa_identifies_the_invalid_field(): void {
		$base = array(
			'nome'        => 'Escola',
			'email'       => 'a@b.es',
			'responsable' => 'Ana',
			'telefono'    => '666111222',
		);

		$this->assertNull( ANPA_Socios_Admin_Payload::diagnosticar_empresa( $base ) );
		$this->assertSame( 'telefono_required', ANPA_Socios_Admin_Payload::diagnosticar_empresa( array_replace( $base, array( 'telefono' => '' ) ) ) );
		$this->assertSame( 'email_invalid', ANPA_Socios_Admin_Payload::diagnosticar_empresa( array_replace( $base, array( 'email' => 'non-email' ) ) ) );
	}

	// ──────────────────────────────────────────────
	// validar_actividad
	// ──────────────────────────────────────────────

	private function validActividadInput(): array {
		return array(
			'empresa_id'  => 1,
			'nome'        => 'Teatro infantil',
			'descripcion' => 'Iniciación ao teatro',
			'cursos'      => array( '2025/2026', '2026/2027' ),
			'custo'       => '30.00',
			'estado'      => 'activo',
		);
	}

	public function test_validar_actividad_ok(): void {
		$result = ANPA_Socios_Admin_Payload::validar_actividad( $this->validActividadInput() );
		$this->assertIsArray( $result );
		$this->assertSame( 'Teatro infantil', $result['nome'] );
		$this->assertSame( '2025/2026', $result['curso_escolar'] );
		$this->assertSame( '🎒', $result['icono'] );
	}

	public function test_validar_actividad_clears_fields_that_belong_to_groups(): void {
		$input = array_merge( $this->validActividadInput(), array(
			'franxa'      => '16:45-17:45',
			'horarios'    => array( 'tarde' ),
			'horario'     => 'tarde',
			'grupos'      => array( '1-2-3' ),
			'dias'        => array( 'luns' ),
			'curso_min'   => 1,
			'curso_max'   => 6,
			'min_pupilos' => 8,
			'max_pupilos' => 15,
		) );
		$result = ANPA_Socios_Admin_Payload::validar_actividad( $input );
		$this->assertIsArray( $result );
		$this->assertNull( $result['horario'] );
		$this->assertSame( '', $result['franxa'] );
		$this->assertSame( '', $result['horarios'] );
		$this->assertSame( '', $result['grupos'] );
		$this->assertSame( '', $result['dias'] );
		$this->assertNull( $result['curso_min'] );
		$this->assertNull( $result['curso_max'] );
		$this->assertSame( 0, $result['min_pupilos'] );
		$this->assertSame( 0, $result['max_pupilos'] );
	}

	public function test_validar_actividad_accepts_custom_icono(): void {
		$input = array_merge( $this->validActividadInput(), array( 'icono' => '🌿' ) );
		$result = ANPA_Socios_Admin_Payload::validar_actividad( $input );
		$this->assertIsArray( $result );
		$this->assertSame( '🌿', $result['icono'] );
	}

	public function test_validar_actividad_invalid_empresa_id(): void {
		$input = array_merge( $this->validActividadInput(), array( 'empresa_id' => 0 ) );
		$this->assertNull( ANPA_Socios_Admin_Payload::validar_actividad( $input ) );
	}

	public function test_validar_actividad_invalid_custo(): void {
		$input = array_merge( $this->validActividadInput(), array( 'custo' => 'not-a-number' ) );
		$this->assertNull( ANPA_Socios_Admin_Payload::validar_actividad( $input ) );
	}

	public function test_validar_actividad_requires_registered_year_shape(): void {
		$input = array_merge( $this->validActividadInput(), array( 'cursos' => array() ) );
		$this->assertNull( ANPA_Socios_Admin_Payload::validar_actividad( $input ) );
		$input['cursos'] = array( '2025' );
		$this->assertNull( ANPA_Socios_Admin_Payload::validar_actividad( $input ) );
	}

	public function test_diagnosticar_actividad_identifies_current_contract_errors(): void {
		$base = $this->validActividadInput();
		$this->assertNull( ANPA_Socios_Admin_Payload::diagnosticar_actividad( $base ) );
		$this->assertSame( 'cursos_required', ANPA_Socios_Admin_Payload::diagnosticar_actividad( array_replace( $base, array( 'cursos' => array() ) ) ) );
		$this->assertSame( 'custo_invalid', ANPA_Socios_Admin_Payload::diagnosticar_actividad( array_replace( $base, array( 'custo' => 'abc' ) ) ) );
	}

	// ──────────────────────────────────────────────
	// validar_grupo
	// ──────────────────────────────────────────────

	public function test_validar_grupo_requires_group_level_franxa(): void {
		$result = ANPA_Socios_Admin_Payload::validar_grupo( array(
			'curso_range' => '4-5-6',
			'dias'        => array( 'luns' ),
			'min_pupilos' => 0,
			'max_pupilos' => 20,
			'estado'      => 'aberto',
		) );
		$this->assertNull( $result );
	}

	public function test_validar_grupo_ok_with_group_level_franxa(): void {
		$result = ANPA_Socios_Admin_Payload::validar_grupo( array(
			'curso_escolar' => '2025/2026',
			'curso_range' => '4-5-6',
			'franxa'      => '14:20-15:10',
			'dias'        => array( 'luns' ),
			'min_pupilos' => 0,
			'max_pupilos' => 20,
			'estado'      => 'aberto',
		) );
		$this->assertIsArray( $result );
		$this->assertSame( '14:20-15:10', $result['franxa'] );
	}

	// ──────────────────────────────────────────────
	// validar_matricula
	// ──────────────────────────────────────────────

	public function test_validar_matricula_ok(): void {
		$result = ANPA_Socios_Admin_Payload::validar_matricula( array(
			'fillo_id'      => 1,
			'activitad_id'  => 1,
			'comedor'       => true,
			'tarde'         => false,
		) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['comedor'] );
		$this->assertFalse( $result['tarde'] );
	}

	public function test_validar_matricula_invalid_ids(): void {
		$result = ANPA_Socios_Admin_Payload::validar_matricula( array(
			'fillo_id'     => 0,
			'activitad_id' => 1,
		) );
		$this->assertNull( $result );
	}

	// ──────────────────────────────────────────────
	// audit_row
	// ──────────────────────────────────────────────

	public function test_audit_row_returns_canonical_shape(): void {
		$row = ANPA_Socios_Admin_Payload::audit_row(
			'master@example.com',
			'master',
			'socio',
			'42',
			'update',
			'2026-06-16T10:00:00Z'
		);
		$this->assertSame( 'master@example.com', $row['actor_email'] );
		$this->assertSame( 'master', $row['actor_tipo'] );
		$this->assertSame( 'socio', $row['target_tipo'] );
		$this->assertSame( '42', $row['target_id'] );
		$this->assertSame( 'update', $row['accion'] );
		$this->assertSame( '2026-06-16T10:00:00Z', $row['timestamp'] );
	}

	public function test_audit_row_normalises_unknown_actor(): void {
		$row = ANPA_Socios_Admin_Payload::audit_row(
			'',
			'',
			'socio',
			'1',
			'list',
			''
		);
		$this->assertSame( 'unknown', $row['actor_email'] );
		$this->assertSame( 'system', $row['actor_tipo'] );
		$this->assertNotEmpty( $row['timestamp'] );
	}

	// ──────────────────────────────────────────────
	// sanitise_optional_string
	// ──────────────────────────────────────────────

	public function test_sanitise_optional_string_rejects_long(): void {
		$too_long = str_repeat( 'a', 60 );
		$this->assertNull( ANPA_Socios_Admin_Payload::sanitise_optional_string( $too_long, 50 ) );
	}

	public function test_sanitise_optional_string_accepts_within_limit(): void {
		$ok = str_repeat( 'a', 50 );
		$out = ANPA_Socios_Admin_Payload::sanitise_optional_string( $ok, 50 );
		$this->assertSame( 50, strlen( $out ) );
	}

	public function test_sanitise_optional_string_returns_null_on_null(): void {
		$this->assertNull( ANPA_Socios_Admin_Payload::sanitise_optional_string( null, 50 ) );
	}

	// ──────────────────────────────────────────────
	// data_nacemento_valida
	// ──────────────────────────────────────────────

	public function test_data_nacemento_valida_iso(): void {
		$this->assertTrue( ANPA_Socios_Admin_Payload::data_nacemento_valida( '2015-04-12' ) );
	}

	public function test_data_nacemento_valida_garbage(): void {
		$this->assertFalse( ANPA_Socios_Admin_Payload::data_nacemento_valida( 'luns' ) );
		$this->assertFalse( ANPA_Socios_Admin_Payload::data_nacemento_valida( '2015-13-40' ) );
		$this->assertFalse( ANPA_Socios_Admin_Payload::data_nacemento_valida( '' ) );
	}
}
