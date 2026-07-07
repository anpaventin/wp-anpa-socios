<?php
/**
 * Unit tests for curso escolar helper (fase10).
 *
 * @since  1.10.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Curso_Escolar extends TestCase {

	public function test_june_30_belongs_to_previous_course(): void {
		if ( ! class_exists( 'ANPA_Socios_Curso_Escolar' ) ) {
			$this->fail( 'ANPA_Socios_Curso_Escolar class is missing.' );
		}

		$this->assertSame( '2025/2026', ANPA_Socios_Curso_Escolar::from_date( '2026-06-30' ) );
	}

	public function test_july_1_starts_new_course(): void {
		if ( ! class_exists( 'ANPA_Socios_Curso_Escolar' ) ) {
			$this->fail( 'ANPA_Socios_Curso_Escolar class is missing.' );
		}

		$this->assertSame( '2026/2027', ANPA_Socios_Curso_Escolar::from_date( '2026-07-01' ) );
	}

	public function test_validates_canonical_course_format(): void {
		if ( ! class_exists( 'ANPA_Socios_Curso_Escolar' ) ) {
			$this->fail( 'ANPA_Socios_Curso_Escolar class is missing.' );
		}

		$this->assertTrue( ANPA_Socios_Curso_Escolar::is_valid( '2026/2027' ) );
		$this->assertFalse( ANPA_Socios_Curso_Escolar::is_valid( '2026-2027' ) );
		$this->assertFalse( ANPA_Socios_Curso_Escolar::is_valid( '2026/2028' ) );
	}

	public function test_list_around_current_course(): void {
		if ( ! class_exists( 'ANPA_Socios_Curso_Escolar' ) ) {
			$this->fail( 'ANPA_Socios_Curso_Escolar class is missing.' );
		}

		$this->assertSame(
			array( '2024/2025', '2025/2026', '2026/2027' ),
			ANPA_Socios_Curso_Escolar::around( '2025/2026', 1, 1 )
		);
	}
}
