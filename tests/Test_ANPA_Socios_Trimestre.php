<?php
/**
 * Tests for ANPA_Socios_Trimestre — actual() and rango().
 *
 * @since  1.25.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ANPA_Socios_Trimestre
 */
final class Test_ANPA_Socios_Trimestre extends TestCase {

	// ── actual() — month → trimester mapping ─────────────────────────

	/**
	 * @dataProvider month_to_trimestre_provider
	 */
	public function test_actual_maps_month_to_trimester( int $month, int $expected ): void {
		$this->assertSame( $expected, ANPA_Socios_Trimestre::actual( $month ) );
	}

	public static function month_to_trimestre_provider(): array {
		return array(
			'september' => array( 9, 1 ),
			'october'   => array( 10, 1 ),
			'november'  => array( 11, 1 ),
			'december'  => array( 12, 1 ),
			'january'   => array( 1, 2 ),
			'february'  => array( 2, 2 ),
			'march'     => array( 3, 2 ),
			'april'     => array( 4, 3 ),
			'may'       => array( 5, 3 ),
			'june'      => array( 6, 3 ),
			'july'      => array( 7, 3 ),
			'august'    => array( 8, 3 ),
		);
	}

	// ── rango() — trimester range computation ────────────────────────

	/**
	 * @dataProvider rango_provider
	 */
	public function test_rango_returns_expected_set( int $tri_alta, ?int $tri_baixa, array $expected ): void {
		$this->assertSame( $expected, ANPA_Socios_Trimestre::rango( $tri_alta, $tri_baixa ) );
	}

	public static function rango_provider(): array {
		return array(
			// Active all year from T1.
			'1_null' => array( 1, null, array( 1, 2, 3 ) ),
			// Active from T2 to end.
			'2_null' => array( 2, null, array( 2, 3 ) ),
			// Active only T3.
			'3_null' => array( 3, null, array( 3 ) ),
			// Enrolled T1, baixa T2 → active only T1.
			'1_2'    => array( 1, 2, array( 1 ) ),
			// Enrolled T1, baixa T3 → active T1+T2.
			'1_3'    => array( 1, 3, array( 1, 2 ) ),
			// Enrolled T2, baixa T2 → left within the same trimester → empty.
			'2_2'    => array( 2, 2, array() ),
			// Enrolled T3, baixa T3 → empty.
			'3_3'    => array( 3, 3, array() ),
			// Enrolled T2, baixa T3 → active T2.
			'2_3'    => array( 2, 3, array( 2 ) ),
			// Enrolled T1, baixa T1 → empty (left within the first trimester).
			'1_1'    => array( 1, 1, array() ),
		);
	}

	// ── valido() ─────────────────────────────────────────────────────

	public function test_valido_accepts_valid_trimesters(): void {
		$this->assertTrue( ANPA_Socios_Trimestre::valido( 1 ) );
		$this->assertTrue( ANPA_Socios_Trimestre::valido( 2 ) );
		$this->assertTrue( ANPA_Socios_Trimestre::valido( 3 ) );
	}

	public function test_valido_rejects_invalid_trimesters(): void {
		$this->assertFalse( ANPA_Socios_Trimestre::valido( 0 ) );
		$this->assertFalse( ANPA_Socios_Trimestre::valido( 4 ) );
		$this->assertFalse( ANPA_Socios_Trimestre::valido( -1 ) );
	}
}
