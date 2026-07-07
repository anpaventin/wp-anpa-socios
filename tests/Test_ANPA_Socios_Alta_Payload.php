<?php
/**
 * Unit tests for the transactional /alta payload validator.
 *
 * @since  1.7.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers ANPA_Socios_Alta_Payload::validar.
 */
final class Test_ANPA_Socios_Alta_Payload extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function fillo( array $over = array() ): array {
		return array_merge(
			array(
				'nome'           => 'Martina',
				'apelidos'       => 'Pérez',
				'data_nacemento' => '2015-09-01',
				'curso'          => '3',
				'aula'           => 'B',
			),
			$over
		);
	}

	public function test_minimal_valid_parent1_only(): void {
		$out = ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
		) );

		$this->assertIsArray( $out );
		$this->assertNull( $out['parent2'] );
		$this->assertSame( array(), $out['fillos'] );
		$this->assertSame( '666555444', $out['parent1']['telefono'] );
		$this->assertSame( '12345678Z', $out['parent1']['nif'] );
	}

	public function test_full_valid_with_parent2_and_fillo(): void {
		$out = ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '+34 666 555 444', 'nif' => '12345678Z' ),
			'parent2' => array( 'nome' => 'Xoán', 'apelidos' => 'Pérez', 'email' => 'XOAN@EXAMPLE.COM', 'nif' => '12345678Z' ),
			'fillos'  => array( $this->fillo( array( 'image_consent' => true ) ) ),
		) );

		$this->assertIsArray( $out );
		$this->assertSame( '666555444', $out['parent1']['telefono'] );
		$this->assertSame( '12345678Z', $out['parent1']['nif'] );
		$this->assertSame( 'xoan@example.com', $out['parent2']['email'] );
		$this->assertNull( $out['parent2']['telefono'] );
		$this->assertCount( 1, $out['fillos'] );
		$this->assertSame( 1, $out['fillos'][0]['image_consent'] );
		$this->assertSame( '3', $out['fillos'][0]['curso'] );
	}

	public function test_image_consent_defaults_to_zero(): void {
		$out = ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'fillos'  => array( $this->fillo() ),
		) );

		$this->assertSame( 0, $out['fillos'][0]['image_consent'] );
	}

	public function test_rgpd_missing_returns_null(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
		) ) );
	}

	public function test_rgpd_false_returns_null(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => false,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
		) ) );
	}

	public function test_parent1_without_phone_returns_null(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'nif' => '12345678Z' ),
		) ) );
	}

	public function test_parent1_invalid_nif_returns_null(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678A' ),
		) ) );
	}

	public function test_parent2_invalid_email_returns_null(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'parent2' => array( 'nome' => 'Xoán', 'apelidos' => 'Pérez', 'email' => 'not-an-email', 'nif' => '12345678Z' ),
		) ) );
	}

	public function test_invalid_fillo_curso_returns_null(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'fillos'  => array( $this->fillo( array( 'curso' => '7' ) ) ),
		) ) );
	}

	public function test_too_many_fillos_returns_null(): void {
		$fillos = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$fillos[] = $this->fillo();
		}
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'fillos'  => $fillos,
		) ) );
	}

	public function test_parent2_without_data_is_ignored(): void {
		$out = ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'parent2' => array( 'nome' => '', 'apelidos' => '', 'email' => '' ),
		) );

		$this->assertIsArray( $out );
		$this->assertNull( $out['parent2'] );
	}

	private function sepa( array $over = array() ): array {
		return array_merge(
			array(
				'iban'              => 'ES9121000418450200051332',
				'titular_nif'       => '12345678Z',
				'titular_nome'      => 'Ana',
				'titular_apelidos'  => 'López',
				'enderezo'          => 'Rúa Exemplo 1',
				'poboacion'         => 'Ames',
				'codigo_postal'     => '15895',
				'entidade_bancaria' => 'Abanca',
				'lugar_data'        => 'Ames, 2026-06-28',
				'autorizacion'      => true,
			),
			$over
		);
	}

	public function test_no_sepa_block_is_null(): void {
		$out = ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
		) );
		$this->assertIsArray( $out );
		$this->assertNull( $out['sepa'] );
	}

	public function test_valid_sepa_block(): void {
		$out = ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'sepa'    => $this->sepa(),
		) );
		$this->assertIsArray( $out );
		$this->assertIsArray( $out['sepa'] );
		$this->assertSame( 'ES9121000418450200051332', $out['sepa']['iban'] );
		$this->assertSame( '12345678Z', $out['sepa']['titular_nif'] );
		$this->assertSame( 1, $out['sepa']['autorizacion'] );
	}

	public function test_sepa_invalid_iban_rejects_alta(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'sepa'    => $this->sepa( array( 'iban' => 'ES0000000000000000000000' ) ),
		) ) );
	}

	public function test_sepa_without_autorizacion_rejects_alta(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'sepa'    => $this->sepa( array( 'autorizacion' => false ) ),
		) ) );
	}

	public function test_sepa_bad_cp_rejects_alta(): void {
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López', 'telefono' => '666555444', 'nif' => '12345678Z' ),
			'sepa'    => $this->sepa( array( 'codigo_postal' => 'ABCDE' ) ),
		) ) );
	}
}
