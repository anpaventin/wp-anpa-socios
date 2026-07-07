<?php
/**
 * Unit tests for fillo curso/grupo enum validation.
 *
 * Covers the canonical enum sets CURSO_VALIDOS and GRUPO_VALIDOS
 * enforced by ANPA_Socios_Admin_Payload::validar_fillo().
 *
 * @since  1.5.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Fillo_Curso_Grupo extends TestCase {

	/**
	 * Base valid fillo payload for tests.
	 *
	 * @return array<string,string>
	 */
	private function base_fillo(): array {
		return array(
			'nome'           => 'Ana',
			'apelidos'       => 'López García',
			'data_nacemento' => '2017-03-15',
			'curso'          => '3',
			'aula'           => 'B',
		);
	}

	// ──────────────────────────────────────────────
	// Curso validation
	// ──────────────────────────────────────────────

	/**
	 * @dataProvider valid_curso_provider
	 */
	public function test_valid_curso_accepted( string $curso ): void {
		$input = $this->base_fillo();
		$input['curso'] = $curso;
		$result = ANPA_Socios_Admin_Payload::validar_fillo( $input );
		$this->assertIsArray( $result );
		$this->assertSame( $curso, $result['curso'] );
	}

	public function valid_curso_provider(): array {
		return array(
			'curso 1' => array( '1' ),
			'curso 2' => array( '2' ),
			'curso 3' => array( '3' ),
			'curso 4' => array( '4' ),
			'curso 5' => array( '5' ),
			'curso 6' => array( '6' ),
		);
	}

	/**
	 * @dataProvider invalid_curso_provider
	 */
	public function test_invalid_curso_rejected( string $curso ): void {
		$input = $this->base_fillo();
		$input['curso'] = $curso;
		$result = ANPA_Socios_Admin_Payload::validar_fillo( $input );
		$this->assertNull( $result, "curso '$curso' should be rejected" );
	}

	public function invalid_curso_provider(): array {
		return array(
			'out of range 7'   => array( '7' ),
			'out of range 0'   => array( '0' ),
			'free text'        => array( 'tercero' ),
			'ordinal suffix'   => array( '3º' ),
			'full text'        => array( '3º EP' ),
			'negative'         => array( '-1' ),
			'empty string'     => array( '' ),
			'double digit'     => array( '12' ),
			'infantil'         => array( 'infantil' ),
		);
	}

	// ──────────────────────────────────────────────
	// Grupo/aula validation
	// ──────────────────────────────────────────────

	/**
	 * @dataProvider valid_grupo_provider
	 */
	public function test_valid_grupo_accepted( string $grupo ): void {
		$input = $this->base_fillo();
		$input['aula'] = $grupo;
		$result = ANPA_Socios_Admin_Payload::validar_fillo( $input );
		$this->assertIsArray( $result );
		$this->assertSame( $grupo, $result['aula'] );
	}

	public function valid_grupo_provider(): array {
		return array(
			'grupo A' => array( 'A' ),
			'grupo B' => array( 'B' ),
			'grupo C' => array( 'C' ),
			'grupo D' => array( 'D' ),
		);
	}

	/**
	 * @dataProvider invalid_grupo_provider
	 */
	public function test_invalid_grupo_rejected( string $grupo ): void {
		$input = $this->base_fillo();
		$input['aula'] = $grupo;
		$result = ANPA_Socios_Admin_Payload::validar_fillo( $input );
		$this->assertNull( $result, "grupo/aula '$grupo' should be rejected" );
	}

	public function invalid_grupo_provider(): array {
		return array(
			'out of range E'   => array( 'E' ),
			'lowercase a'      => array( 'a' ),
			'lowercase b'      => array( 'b' ),
			'lowercase c'      => array( 'c' ),
			'lowercase d'      => array( 'd' ),
			'number'           => array( '1' ),
			'empty string'     => array( '' ),
			'multi-char'       => array( 'AB' ),
			'free text'        => array( 'primero' ),
		);
	}

	// ──────────────────────────────────────────────
	// Combined: valid fillo with valid curso + grupo
	// ──────────────────────────────────────────────

	public function test_full_valid_fillo_returns_canonical_values(): void {
		$input = array(
			'nome'           => 'Xoán',
			'apelidos'       => 'Fernández Rivas',
			'data_nacemento' => '2016-09-01',
			'curso'          => '5',
			'aula'           => 'D',
		);
		$result = ANPA_Socios_Admin_Payload::validar_fillo( $input );
		$this->assertIsArray( $result );
		$this->assertSame( '5', $result['curso'] );
		$this->assertSame( 'D', $result['aula'] );
		$this->assertSame( 'activo', $result['estado'] );
	}

	public function test_constants_are_defined(): void {
		$this->assertSame( array( '1', '2', '3', '4', '5', '6' ), ANPA_Socios_Admin_Payload::CURSO_VALIDOS );
		$this->assertSame( array( 'A', 'B', 'C', 'D' ), ANPA_Socios_Admin_Payload::GRUPO_VALIDOS );
	}
}
