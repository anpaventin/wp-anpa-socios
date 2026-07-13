<?php
/**
 * Characterisation tests for the current legacy hardcodes.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Legacy_Hardcodes extends TestCase {
	public function test_legacy_hardcodes_are_frozen(): void {
		$this->assertSame( 'D', ANPA_Socios_Config::DEFAULT_AULA_MAX );
		$this->assertSame( array( '1', '2', '3', '4', '5', '6' ), ANPA_Socios_Admin_Payload::CURSO_VALIDOS );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' ), ANPA_Socios_Admin_Payload::GRUPO_VALIDOS );
		$this->assertSame(
			array(
				'1-2-3' => array( '1', '2', '3' ),
				'4-5-6' => array( '4', '5', '6' ),
			),
			ANPA_Socios_Curso_Fit::RANGES
		);
		$this->assertSame( array( '1-2-3', '4-5-6' ), ANPA_Socios_Actividade_Options::GRUPOS );
	}
}
