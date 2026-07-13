<?php
/**
 * Unit tests for ANPA_Socios_Grupo_Niveis.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupo_Niveis extends TestCase {
	public function test_normalize_accepts_legacy_ranges_and_arrays(): void {
		$this->assertSame( array( '1', '2', '3' ), ANPA_Socios_Grupo_Niveis::normalize( '1-2-3' ) );
		$this->assertSame( array( '1', '2', '3', '4', '5', '6' ), ANPA_Socios_Grupo_Niveis::normalize( '1-2-3,4-5-6' ) );
		$this->assertSame( array( '1', '2', '4' ), ANPA_Socios_Grupo_Niveis::normalize( array( '4', '1', '1', '', '2' ) ) );
	}

	public function test_normalize_preserves_dynamic_codes_containing_hyphens(): void {
		$this->assertSame( array( 'INF-3' ), ANPA_Socios_Grupo_Niveis::normalize( 'INF-3' ) );
	}

	public function test_fits_uses_normalized_levels(): void {
		$this->assertTrue( ANPA_Socios_Grupo_Niveis::fits( '2', '1-2-3' ) );
		$this->assertTrue( ANPA_Socios_Grupo_Niveis::fits( '5', array( '4', '5', '6' ) ) );
		$this->assertFalse( ANPA_Socios_Grupo_Niveis::fits( '4', array( '1', '2', '3' ) ) );
		$this->assertFalse( ANPA_Socios_Grupo_Niveis::fits( 'E', array( '1', '2', '3' ) ) );
	}
}
