<?php
/**
 * Unit tests for ANPA_Socios_Alumnos_Export::columns().
 *
 * @since  1.5.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Alumnos_Export_Columns extends TestCase {

	/**
	 * The empresa column list has exactly 8 columns in the expected order.
	 */
	public function test_empresa_columns_are_correct(): void {
		$expected = array(
			'actividade_nome',
			'nome',
			'apelidos',
			'curso',
			'aula',
			'comedor',
			'tarde',
			'socio_email',
		);

		$this->assertSame( $expected, ANPA_Socios_Alumnos_Export::columns( false ) );
	}

	/**
	 * The admin column list prepends empresa_nome to the empresa list.
	 */
	public function test_admin_columns_prepend_empresa_nome(): void {
		$empresa_cols = ANPA_Socios_Alumnos_Export::columns( false );
		$admin_cols   = ANPA_Socios_Alumnos_Export::columns( true );

		$this->assertSame(
			array_merge( array( 'empresa_nome' ), $empresa_cols ),
			$admin_cols
		);
	}

	/**
	 * Admin list has 9 columns (empresa_nome + 8 base).
	 */
	public function test_admin_columns_count(): void {
		$this->assertCount( 9, ANPA_Socios_Alumnos_Export::columns( true ) );
	}
}
