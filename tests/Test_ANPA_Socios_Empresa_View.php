<?php
/**
 * Unit tests for ANPA_Socios_Empresa_View pure presenters.
 *
 * Pure PHP tests; no WordPress bootstrap.
 *
 * @since  1.4.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Empresa_View extends TestCase {

	// ──────────────────────────────────────────────
	// public_empresa()
	// ──────────────────────────────────────────────

	/**
	 * public_empresa returns only whitelisted fields.
	 */
	public function test_public_empresa_returns_whitelisted_fields(): void {
		$row = array(
			'id'             => '7',
			'nome'           => 'Empresa Test SL',
			'email'          => 'test@empresa.com',
			'responsable'    => 'Ana García',
			'telefono'       => '600111222',
			'estado'         => 'activo',
			'creado_en'      => '2024-01-01 10:00:00',
			'actualizado_en' => '2024-06-01 12:00:00',
			'socio_email'    => 'socio@example.com',
			'secret_field'   => 'should_not_appear',
		);

		$result = ANPA_Socios_Empresa_View::public_empresa( $row );

		$expected_keys = array( 'id', 'nome', 'email', 'responsable', 'telefono', 'estado' );
		$this->assertSame( $expected_keys, array_keys( $result ) );
	}

	/**
	 * public_empresa does NOT expose socio_email.
	 */
	public function test_public_empresa_excludes_socio_email(): void {
		$row = array(
			'id'          => '1',
			'nome'        => 'X',
			'email'       => 'x@y.com',
			'responsable' => '',
			'telefono'    => '',
			'estado'      => 'activo',
			'socio_email' => 'private@socio.com',
		);

		$result = ANPA_Socios_Empresa_View::public_empresa( $row );

		$this->assertArrayNotHasKey( 'socio_email', $result );
	}

	/**
	 * public_empresa casts id to int.
	 */
	public function test_public_empresa_casts_id_to_int(): void {
		$row = array( 'id' => '42', 'nome' => '', 'email' => '', 'responsable' => '', 'telefono' => '', 'estado' => '' );

		$result = ANPA_Socios_Empresa_View::public_empresa( $row );

		$this->assertSame( 42, $result['id'] );
	}

	/**
	 * public_empresa handles empty row gracefully.
	 */
	public function test_public_empresa_empty_row(): void {
		$result = ANPA_Socios_Empresa_View::public_empresa( array() );

		$this->assertSame( 0, $result['id'] );
		$this->assertSame( '', $result['nome'] );
		$this->assertSame( '', $result['email'] );
		$this->assertSame( '', $result['responsable'] );
		$this->assertSame( '', $result['telefono'] );
		$this->assertSame( '', $result['estado'] );
	}

	/**
	 * public_empresa does not leak extra keys from the row.
	 */
	public function test_public_empresa_no_extra_keys_leak(): void {
		$row = array(
			'id'          => '1',
			'nome'        => 'A',
			'email'       => 'a@b.com',
			'responsable' => 'R',
			'telefono'    => '123',
			'estado'      => 'activo',
			'internal_1'  => 'secret',
			'internal_2'  => 'hidden',
		);

		$result = ANPA_Socios_Empresa_View::public_empresa( $row );

		$this->assertCount( 6, $result );
	}

	// ──────────────────────────────────────────────
	// alumno_row()
	// ──────────────────────────────────────────────

	/**
	 * alumno_row returns only whitelisted fields.
	 */
	public function test_alumno_row_returns_whitelisted_fields(): void {
		$row = array(
			'id'             => '15',
			'nome'           => 'María',
			'apelidos'       => 'López',
			'data_nacemento' => '2016-05-12',
			'curso'          => '3º EP',
			'aula'           => 'B',
			'socio_email'    => 'parent@example.com',
			'comedor'        => '1',
			'tarde'          => '0',
			'estado'         => 'activo',
			'extra'          => 'should_not_appear',
		);

		$result = ANPA_Socios_Empresa_View::alumno_row( $row );

		$expected_keys = array( 'id', 'nome', 'apelidos', 'data_nacemento', 'curso', 'aula', 'socio_email' );
		$this->assertSame( $expected_keys, array_keys( $result ) );
	}

	/**
	 * alumno_row includes socio_email (cesión de datos consent).
	 */
	public function test_alumno_row_includes_socio_email(): void {
		$row = array(
			'id'             => '1',
			'nome'           => 'N',
			'apelidos'       => 'A',
			'data_nacemento' => '2015-01-01',
			'curso'          => '1',
			'aula'           => 'A',
			'socio_email'    => 'socio@test.com',
		);

		$result = ANPA_Socios_Empresa_View::alumno_row( $row );

		$this->assertSame( 'socio@test.com', $result['socio_email'] );
	}

	/**
	 * alumno_row does NOT include comedor or tarde.
	 */
	public function test_alumno_row_excludes_comedor_and_tarde(): void {
		$row = array(
			'id'             => '1',
			'nome'           => 'N',
			'apelidos'       => 'A',
			'data_nacemento' => '2015-01-01',
			'curso'          => '1',
			'aula'           => 'A',
			'socio_email'    => 'x@y.com',
			'comedor'        => '1',
			'tarde'          => '1',
		);

		$result = ANPA_Socios_Empresa_View::alumno_row( $row );

		$this->assertArrayNotHasKey( 'comedor', $result );
		$this->assertArrayNotHasKey( 'tarde', $result );
	}

	/**
	 * alumno_row casts id to int.
	 */
	public function test_alumno_row_casts_id_to_int(): void {
		$row = array(
			'id'             => '99',
			'nome'           => 'N',
			'apelidos'       => 'A',
			'data_nacemento' => '2015-01-01',
			'curso'          => '1',
			'aula'           => 'A',
			'socio_email'    => 'x@y.com',
		);

		$result = ANPA_Socios_Empresa_View::alumno_row( $row );

		$this->assertSame( 99, $result['id'] );
	}

	/**
	 * alumno_row handles empty row gracefully.
	 */
	public function test_alumno_row_empty_row(): void {
		$result = ANPA_Socios_Empresa_View::alumno_row( array() );

		$this->assertSame( 0, $result['id'] );
		$this->assertSame( '', $result['nome'] );
		$this->assertSame( '', $result['socio_email'] );
	}

	/**
	 * alumno_row does not leak extra keys.
	 */
	public function test_alumno_row_no_extra_keys_leak(): void {
		$row = array(
			'id'             => '1',
			'nome'           => 'N',
			'apelidos'       => 'A',
			'data_nacemento' => '2015-01-01',
			'curso'          => '1',
			'aula'           => 'A',
			'socio_email'    => 'x@y.com',
			'secret_1'       => 'private',
			'secret_2'       => 'hidden',
		);

		$result = ANPA_Socios_Empresa_View::alumno_row( $row );

		$this->assertCount( 7, $result );
	}

	// ──────────────────────────────────────────────
	// EDITABLE_ALUMNO_FIELDS
	// ──────────────────────────────────────────────

	/**
	 * EDITABLE_ALUMNO_FIELDS is exactly nome and apelidos.
	 */
	public function test_editable_fields_is_nome_and_apelidos(): void {
		$this->assertSame(
			array( 'nome', 'apelidos' ),
			ANPA_Socios_Empresa_View::EDITABLE_ALUMNO_FIELDS
		);
	}

	/**
	 * EDITABLE_ALUMNO_FIELDS has exactly 2 entries.
	 */
	public function test_editable_fields_count(): void {
		$this->assertCount( 2, ANPA_Socios_Empresa_View::EDITABLE_ALUMNO_FIELDS );
	}

	/**
	 * socio_email is NOT in EDITABLE_ALUMNO_FIELDS.
	 */
	public function test_socio_email_not_editable(): void {
		$this->assertNotContains( 'socio_email', ANPA_Socios_Empresa_View::EDITABLE_ALUMNO_FIELDS );
	}

	/**
	 * socio_email present in alumno_row but absent in public_empresa.
	 */
	public function test_socio_email_in_alumno_not_in_empresa(): void {
		$empresa_row = array(
			'id'          => '1',
			'nome'        => 'E',
			'email'       => 'e@e.com',
			'responsable' => '',
			'telefono'    => '',
			'estado'      => 'activo',
			'socio_email' => 'leak@attempt.com',
		);

		$alumno_row = array(
			'id'             => '1',
			'nome'           => 'N',
			'apelidos'       => 'A',
			'data_nacemento' => '2015-01-01',
			'curso'          => '1',
			'aula'           => 'A',
			'socio_email'    => 'parent@real.com',
		);

		$empresa_result = ANPA_Socios_Empresa_View::public_empresa( $empresa_row );
		$alumno_result  = ANPA_Socios_Empresa_View::alumno_row( $alumno_row );

		$this->assertArrayNotHasKey( 'socio_email', $empresa_result );
		$this->assertArrayHasKey( 'socio_email', $alumno_result );
		$this->assertSame( 'parent@real.com', $alumno_result['socio_email'] );
	}
}
