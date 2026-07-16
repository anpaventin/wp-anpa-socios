<?php
/**
 * Unit tests for the revised fase24 group-series contract.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupos_Curriculares extends TestCase {

	private function validInput(): array {
		return array(
			'nome'            => 'Grupo comedor 1º-3º',
			'cursos'          => array( '2026/2027', '2027/2028' ),
			'niveis_por_ano'  => array(
				'2026/2027' => array( 1, 2, 3 ),
				'2027/2028' => array( 11, 12, 13 ),
			),
			'horario'         => 'manha',
			'franxa'          => '14:10-15:10',
			'dias'            => array( 'luns', 'mercores' ),
			'min_pupilos'     => 8,
			'max_pupilos'     => 15,
			'estado'          => 'aberto',
		);
	}

	public function test_normalizes_a_multi_year_series(): void {
		$out = ANPA_Socios_Grupo_Serie::normalize( $this->validInput() );
		$this->assertSame( 'Grupo comedor 1º-3º', $out['nome'] );
		$this->assertSame( array( '2026/2027', '2027/2028' ), $out['cursos'] );
		$this->assertSame( array( 1, 2, 3 ), $out['niveis_por_ano']['2026/2027'] );
		$this->assertSame( 'manha', $out['horario'] );
		$this->assertSame( 'luns,mercores', $out['dias'] );
	}

	/** @dataProvider invalidInputProvider */
	public function test_rejects_invalid_series( callable $mutate ): void {
		$input = $this->validInput();
		$mutate( $input );
		$this->assertSame( array(), ANPA_Socios_Grupo_Serie::normalize( $input ) );
	}

	public static function invalidInputProvider(): array {
		return array(
			'empty name'          => array( static function ( &$v ) { $v['nome'] = ' '; } ),
			'no years'            => array( static function ( &$v ) { $v['cursos'] = array(); } ),
			'invalid year'        => array( static function ( &$v ) { $v['cursos'] = array( '2026' ); } ),
			'missing year levels' => array( static function ( &$v ) { unset( $v['niveis_por_ano']['2027/2028'] ); } ),
			'foreign year levels' => array( static function ( &$v ) { $v['niveis_por_ano']['2028/2029'] = array( 99 ); } ),
			'no levels'           => array( static function ( &$v ) { $v['niveis_por_ano']['2026/2027'] = array(); } ),
			'both horarios'       => array( static function ( &$v ) { $v['horario'] = 'manha,tarde'; } ),
			'invalid franxa'      => array( static function ( &$v ) { $v['franxa'] = '15:10-14:10'; } ),
			'no days'             => array( static function ( &$v ) { $v['dias'] = array(); } ),
			'max below min'       => array( static function ( &$v ) { $v['min_pupilos'] = 16; $v['max_pupilos'] = 15; } ),
			'invalid state'       => array( static function ( &$v ) { $v['estado'] = 'activo'; } ),
		);
	}

	public function test_horario_labels_are_family_facing(): void {
		$this->assertSame( 'Mañá', ANPA_Socios_Grupo_Serie::horario_label( 'maña' ) );
		$this->assertSame( 'Comedor', ANPA_Socios_Grupo_Serie::horario_label( 'manha' ) );
		$this->assertSame( 'Tarde', ANPA_Socios_Grupo_Serie::horario_label( 'tarde' ) );
		$this->assertSame( '', ANPA_Socios_Grupo_Serie::horario_label( 'noite' ) );
	}
}
