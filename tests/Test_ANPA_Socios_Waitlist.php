<?php
/**
 * Unit tests for ANPA_Socios_Waitlist and ANPA_Socios_Trimestre.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Waitlist extends TestCase {

	public function test_next_position_empty_is_one(): void {
		$this->assertSame( 1, ANPA_Socios_Waitlist::next_position( array() ) );
	}

	public function test_next_position_after_max(): void {
		$this->assertSame( 4, ANPA_Socios_Waitlist::next_position( array( 1, 2, 3 ) ) );
		// Non-contiguous: still max+1.
		$this->assertSame( 6, ANPA_Socios_Waitlist::next_position( array( 5, 2 ) ) );
		$this->assertSame( 3, ANPA_Socios_Waitlist::next_position( array( '1', '2' ) ) );
	}

	public function test_renumber_contiguous(): void {
		$map = ANPA_Socios_Waitlist::renumber( array( 10, 7, 22 ) );
		$this->assertSame( array( 10 => 1, 7 => 2, 22 => 3 ), $map );
	}

	public function test_renumber_empty(): void {
		$this->assertSame( array(), ANPA_Socios_Waitlist::renumber( array() ) );
	}

	public function test_first_offerable_smallest_position(): void {
		$rows = array(
			array( 'id' => 1, 'posicion' => 3 ),
			array( 'id' => 2, 'posicion' => 1 ),
			array( 'id' => 3, 'posicion' => 2 ),
		);
		$best = ANPA_Socios_Waitlist::first_offerable( $rows );
		$this->assertSame( 2, $best['id'] );
	}

	public function test_first_offerable_empty_is_null(): void {
		$this->assertNull( ANPA_Socios_Waitlist::first_offerable( array() ) );
	}

	// ── Trimestre ──────────────────────────────────────────────

	public function test_trimestre_mapping(): void {
		$this->assertSame( 1, ANPA_Socios_Trimestre::actual( 9 ) );
		$this->assertSame( 1, ANPA_Socios_Trimestre::actual( 12 ) );
		$this->assertSame( 2, ANPA_Socios_Trimestre::actual( 1 ) );
		$this->assertSame( 2, ANPA_Socios_Trimestre::actual( 3 ) );
		$this->assertSame( 3, ANPA_Socios_Trimestre::actual( 4 ) );
		$this->assertSame( 3, ANPA_Socios_Trimestre::actual( 8 ) );
	}

	public function test_trimestre_valido(): void {
		$this->assertTrue( ANPA_Socios_Trimestre::valido( 1 ) );
		$this->assertTrue( ANPA_Socios_Trimestre::valido( 3 ) );
		$this->assertFalse( ANPA_Socios_Trimestre::valido( 0 ) );
		$this->assertFalse( ANPA_Socios_Trimestre::valido( 4 ) );
	}
}
