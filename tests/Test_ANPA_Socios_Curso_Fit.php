<?php
/**
 * Unit tests for ANPA_Socios_Curso_Fit.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Curso_Fit extends TestCase {

	/**
	 * @dataProvider fits_provider
	 */
	public function test_fits( string $curso, string $range, bool $expected ): void {
		$this->assertSame( $expected, ANPA_Socios_Curso_Fit::fits( $curso, $range ) );
	}

	public function fits_provider(): array {
		return array(
			'1 in 1-2-3'      => array( '1', '1-2-3', true ),
			'3 in 1-2-3'      => array( '3', '1-2-3', true ),
			'4 not in 1-2-3'  => array( '4', '1-2-3', false ),
			'4 in 4-5-6'      => array( '4', '4-5-6', true ),
			'6 in 4-5-6'      => array( '6', '4-5-6', true ),
			'3 not in 4-5-6'  => array( '3', '4-5-6', false ),
			'curso trimmed'   => array( ' 2 ', '1-2-3', true ),
			'unknown range'   => array( '2', '7-8-9', false ),
			'empty curso'     => array( '', '1-2-3', false ),
		);
	}

	public function test_range_for(): void {
		$this->assertSame( '1-2-3', ANPA_Socios_Curso_Fit::range_for( '2' ) );
		$this->assertSame( '4-5-6', ANPA_Socios_Curso_Fit::range_for( '5' ) );
		$this->assertSame( '', ANPA_Socios_Curso_Fit::range_for( '7' ) );
	}

	public function test_is_range(): void {
		$this->assertTrue( ANPA_Socios_Curso_Fit::is_range( '1-2-3' ) );
		$this->assertTrue( ANPA_Socios_Curso_Fit::is_range( '4-5-6' ) );
		$this->assertFalse( ANPA_Socios_Curso_Fit::is_range( '1-2' ) );
		$this->assertFalse( ANPA_Socios_Curso_Fit::is_range( '' ) );
	}
}
