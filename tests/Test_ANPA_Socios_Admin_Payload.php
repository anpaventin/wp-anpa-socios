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

	public function test_validar_actividad_ok(): void {
		$result = ANPA_Socios_Admin_Payload::validar_actividad( array(
			'empresa_id'    => 1,
			'nome'          => 'Teatro infantil',
			'descripcion'   => 'Iniciación ao teatro',
			'curso_escolar' => '2025/2026',
			'franxa'        => '16:45-17:45',
			'horarios'      => array( 'tarde' ),
			'grupos'        => array( '1-2-3' ),
			'dias'          => array( 'luns' ),
			'idade_min'     => 6,
			'idade_max'     => 10,
			'custo'         => '30.00',
		) );
		$this->assertIsArray( $result );
		$this->assertSame( 'Teatro infantil', $result['nome'] );
		$this->assertSame( '🎒', $result['icono'] );
	}

	public function test_validar_actividad_captures_exclusive_horario(): void {
		// fase24: an offer can carry an exclusive morning/afternoon horario.
		$result = ANPA_Socios_Admin_Payload::validar_actividad( array(
			'empresa_id'    => 1,
			'nome'          => 'Teatro',
			'descripcion'   => 'desc',
			'curso_escolar' => '2025/2026',
			'franxa'        => '16:45-17:45',
			'horarios'      => array( 'tarde' ),
			'grupos'        => array( '1-2-3' ),
			'dias'          => array( 'luns' ),
			'custo'         => '30.00',
			'horario'       => 'tarde',
		) );
		$this->assertIsArray( $result );
		$this->assertSame( 'tarde', $result['horario'] );
	}

	public function test_validar_actividad_rejects_non_exclusive_horario_to_null(): void {
		// A non-exclusive/absent horario resolves to null (not both).
		$base = array(
			'empresa_id'    => 1,
			'nome'          => 'Teatro',
			'descripcion'   => 'desc',
			'curso_escolar' => '2025/2026',
			'franxa'        => '16:45-17:45',
			'horarios'      => array( 'tarde' ),
			'grupos'        => array( '1-2-3' ),
			'dias'          => array( 'luns' ),
			'custo'         => '30.00',
		);

		$absent = ANPA_Socios_Admin_Payload::validar_actividad( $base );
		$this->assertNull( $absent['horario'] );

		$both = ANPA_Socios_Admin_Payload::validar_actividad( array_merge( $base, array( 'horario' => array( 'manha', 'tarde' ) ) ) );
		$this->assertNull( $both['horario'] );

		$bogus = ANPA_Socios_Admin_Payload::validar_actividad( array_merge( $base, array( 'horario' => 'noite' ) ) );
		$this->assertNull( $bogus['horario'] );
	}

	public function test_validar_actividad_accepts_custom_icono(): void {
		$result = ANPA_Socios_Admin_Payload::validar_actividad( array(
			'empresa_id'    => 1,
			'nome'          => 'Ecoarte',
			'icono'         => '🌿',
			'descripcion'   => 'Arte e natureza',
			'curso_escolar' => '2025/2026',
			'franxa'        => '14:20-15:10',
			'horarios'      => array( 'manha' ),
			'grupos'        => array( '1-2-3' ),
			'dias'          => array( 'luns' ),
			'custo'         => '11.50',
		) );
		$this->assertIsArray( $result );
		$this->assertSame( '🌿', $result['icono'] );
	}

	public function test_validar_actividad_invalid_empresa_id(): void {
		$result = ANPA_Socios_Admin_Payload::validar_actividad( array(
			'empresa_id'    => 0,
			'nome'          => 'X',
			'descripcion'   => 'X',
			'curso_escolar' => '2025/2026',
			'franxa'        => '16:45-17:45',
			'horarios'      => array( 'tarde' ),
			'grupos'        => array( '1-2-3' ),
			'dias'          => array( 'luns' ),
		) );
		$this->assertNull( $result );
	}

	public function test_validar_actividad_invalid_custo(): void {
		$result = ANPA_Socios_Admin_Payload::validar_actividad( array(
			'empresa_id'    => 1,
			'nome'          => 'X',
			'descripcion'   => 'X',
			'curso_escolar' => '2025/2026',
			'franxa'        => '16:45-17:45',
			'horarios'      => array( 'tarde' ),
			'grupos'        => array( '1-2-3' ),
			'dias'          => array( 'luns' ),
			'custo'         => 'not-a-number',
		) );
		$this->assertNull( $result );
	}

	public function test_diagnosticar_actividad_identifies_option_and_value_errors(): void {
		$base = array(
			'empresa_id'    => 1,
			'nome'          => 'Teatro',
			'descripcion'   => 'Teatro infantil',
			'curso_escolar' => '2025/2026',
			'horarios'      => array( 'tarde' ),
			'grupos'        => array( '1-2-3' ),
			'dias'          => array( 'luns' ),
			'custo'         => '30.00',
		);

		$this->assertNull( ANPA_Socios_Admin_Payload::diagnosticar_actividad( $base ) );
		$this->assertSame( 'horarios_required', ANPA_Socios_Admin_Payload::diagnosticar_actividad( array_replace( $base, array( 'horarios' => array() ) ) ) );
		$this->assertSame( 'grupos_required', ANPA_Socios_Admin_Payload::diagnosticar_actividad( array_replace( $base, array( 'grupos' => array() ) ) ) );
		$this->assertSame( 'dias_required', ANPA_Socios_Admin_Payload::diagnosticar_actividad( array_replace( $base, array( 'dias' => array() ) ) ) );
		$this->assertSame( 'curso_escolar_invalid', ANPA_Socios_Admin_Payload::diagnosticar_actividad( array_replace( $base, array( 'curso_escolar' => '2025' ) ) ) );
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
