<?php
/**
 * Unit tests for course settings helpers.
 *
 * @since  1.32.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Course_Settings extends TestCase {

	public function test_matriculas_abertas_from_post_returns_null_when_field_absent(): void {
		if ( ! class_exists( 'ANPA_Socios_Course_Settings' ) ) {
			$this->fail( 'ANPA_Socios_Course_Settings class is missing.' );
		}

		$this->assertNull( ANPA_Socios_Course_Settings::matriculas_abertas_from_post( array( 'curso_escolar' => '2026/2027' ) ) );
	}

	public function test_matriculas_abertas_from_post_normalizes_checkbox_values(): void {
		if ( ! class_exists( 'ANPA_Socios_Course_Settings' ) ) {
			$this->fail( 'ANPA_Socios_Course_Settings class is missing.' );
		}

		$this->assertTrue( ANPA_Socios_Course_Settings::matriculas_abertas_from_post( array( 'matriculas_abertas' => '1' ) ) );
		$this->assertFalse( ANPA_Socios_Course_Settings::matriculas_abertas_from_post( array( 'matriculas_abertas' => '0' ) ) );
		$this->assertFalse( ANPA_Socios_Course_Settings::matriculas_abertas_from_post( array( 'matriculas_abertas' => '' ) ) );
	}

	public function test_matriculas_abertas_for_display_defaults_new_courses_open(): void {
		if ( ! class_exists( 'ANPA_Socios_Course_Settings' ) ) {
			$this->fail( 'ANPA_Socios_Course_Settings class is missing.' );
		}

		$this->assertTrue( ANPA_Socios_Course_Settings::matriculas_abertas_for_display( null ) );
		$this->assertTrue( ANPA_Socios_Course_Settings::matriculas_abertas_for_display( array( 'matriculas_abertas' => '1' ) ) );
		$this->assertFalse( ANPA_Socios_Course_Settings::matriculas_abertas_for_display( array( 'matriculas_abertas' => '0' ) ) );
	}
}
