<?php
/**
 * Adversarial regression tests added by PR-ES7 audit.
 *
 * Covers edge cases not already tested in existing suites:
 * - Negative/zero orde in normalize_snapshot.
 * - Empty/overlong codigo rejection.
 * - Curso_Fit with non-string and empty curso_range.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Adversarial_ES7 extends TestCase {

	/**
	 * @testdox normalize_snapshot rejects niveis with zero or negative orde
	 */
	public function test_normalize_snapshot_rejects_invalid_orde(): void {
		$snapshot = ANPA_Socios_Estrutura_Escolar::normalize_snapshot( array(
			'curso_escolar' => '2026/2027',
			'niveis'        => array(
				array( 'codigo' => '1', 'etiqueta' => '1º', 'orde' => 0, 'aulas' => array() ),
				array( 'codigo' => '2', 'etiqueta' => '2º', 'orde' => -5, 'aulas' => array() ),
				array( 'codigo' => '3', 'etiqueta' => '3º', 'orde' => 30, 'aulas' => array() ),
			),
		) );

		// Only the valid orde=30 nivel survives.
		$this->assertCount( 1, $snapshot['niveis'] );
		$this->assertSame( '3', $snapshot['niveis'][0]['codigo'] );
	}

	/**
	 * @testdox normalize_snapshot rejects aulas with empty or overlong codigo
	 */
	public function test_normalize_snapshot_rejects_invalid_codigo(): void {
		$snapshot = ANPA_Socios_Estrutura_Escolar::normalize_snapshot( array(
			'curso_escolar' => '2026/2027',
			'niveis'        => array(
				array(
					'codigo'   => '1',
					'etiqueta' => '1º',
					'orde'     => 10,
					'aulas'    => array(
						array( 'codigo' => '', 'etiqueta' => 'empty', 'orde' => 10 ),
						array( 'codigo' => str_repeat( 'X', 21 ), 'etiqueta' => 'overlong', 'orde' => 20 ),
						array( 'codigo' => 'A', 'etiqueta' => 'A', 'orde' => 30 ),
					),
				),
			),
		) );

		// Only the valid aula survives.
		$this->assertCount( 1, $snapshot['niveis'][0]['aulas'] );
		$this->assertSame( 'A', $snapshot['niveis'][0]['aulas'][0]['codigo'] );
	}

	/**
	 * @testdox Curso_Fit::fits returns false for empty string curso_range
	 */
	public function test_curso_fit_empty_string_returns_false(): void {
		$this->assertFalse( ANPA_Socios_Curso_Fit::fits( '1', '' ) );
	}

	/**
	 * @testdox Curso_Fit::fits returns false for a non-numeric, non-range curso_range
	 */
	public function test_curso_fit_garbage_range_returns_false(): void {
		$this->assertFalse( ANPA_Socios_Curso_Fit::fits( '1', 'abc-def' ) );
		$this->assertFalse( ANPA_Socios_Curso_Fit::fits( '3', 'garbage' ) );
		$this->assertFalse( ANPA_Socios_Curso_Fit::fits( '5', '999-888-777' ) );
	}
}
