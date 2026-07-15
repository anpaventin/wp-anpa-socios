<?php
/**
 * Unit tests for the pure ANPA_Socios_Prazas summary (fase22 S8.10).
 *
 * @since  1.41.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Prazas extends TestCase {

	public function test_no_groups_reports_no_capacity(): void {
		$s = ANPA_Socios_Prazas::summary( array() );
		$this->assertFalse( $s['has_groups'] );
		$this->assertFalse( $s['completo'] );
		$this->assertFalse( $s['espera_visible'] );
	}

	public function test_single_group_with_free_places(): void {
		$s = ANPA_Socios_Prazas::summary( array(
			array( 'activos' => 8, 'max_pupilos' => 15, 'espera' => 0 ),
		) );
		$this->assertTrue( $s['has_groups'] );
		$this->assertSame( 8, $s['activos'] );
		$this->assertSame( 15, $s['max_pupilos'] );
		$this->assertFalse( $s['completo'] );
		$this->assertFalse( $s['espera_visible'] );
		$this->assertSame( 'anpa-extra-prazas-ok', ANPA_Socios_Prazas::activos_class( $s ) );
	}

	public function test_full_group_shows_espera_even_when_zero(): void {
		// S8.6: a full activity shows "+ 0 en espera" — espera_visible tracks
		// "completo", not "espera > 0".
		$s = ANPA_Socios_Prazas::summary( array(
			array( 'activos' => 10, 'max_pupilos' => 10, 'espera' => 0 ),
		) );
		$this->assertTrue( $s['completo'] );
		$this->assertTrue( $s['espera_visible'] );
		$this->assertSame( 0, $s['espera'] );
		$this->assertSame( 'anpa-extra-prazas-completo', ANPA_Socios_Prazas::activos_class( $s ) );
	}

	public function test_full_group_with_waitlist_shows_espera(): void {
		$s = ANPA_Socios_Prazas::summary( array(
			array( 'activos' => 15, 'max_pupilos' => 15, 'espera' => 5 ),
		) );
		$this->assertTrue( $s['completo'] );
		$this->assertTrue( $s['espera_visible'] );
		$this->assertSame( 5, $s['espera'] );
	}

	public function test_not_full_hides_espera_even_when_present(): void {
		// espera > 0 but not full → waitlist part must NOT be shown.
		$s = ANPA_Socios_Prazas::summary( array(
			array( 'activos' => 9, 'max_pupilos' => 15, 'espera' => 3 ),
		) );
		$this->assertFalse( $s['completo'] );
		$this->assertFalse( $s['espera_visible'] );
		$this->assertSame( 3, $s['espera'] );
	}

	public function test_multiple_groups_aggregate_and_full_when_all_full(): void {
		$s = ANPA_Socios_Prazas::summary( array(
			array( 'activos' => 10, 'max_pupilos' => 10, 'espera' => 2 ),
			array( 'activos' => 15, 'max_pupilos' => 15, 'espera' => 1 ),
		) );
		$this->assertSame( 25, $s['activos'] );
		$this->assertSame( 25, $s['max_pupilos'] );
		$this->assertSame( 3, $s['espera'] );
		$this->assertTrue( $s['completo'] );
		$this->assertTrue( $s['espera_visible'] );
	}

	public function test_multiple_groups_not_full_when_one_has_room(): void {
		$s = ANPA_Socios_Prazas::summary( array(
			array( 'activos' => 10, 'max_pupilos' => 10, 'espera' => 2 ),
			array( 'activos' => 8, 'max_pupilos' => 15, 'espera' => 0 ),
		) );
		// Aggregate 18/25 → not full.
		$this->assertSame( 18, $s['activos'] );
		$this->assertSame( 25, $s['max_pupilos'] );
		$this->assertFalse( $s['completo'] );
		$this->assertFalse( $s['espera_visible'] );
	}

	public function test_numeric_string_inputs_are_coerced(): void {
		$s = ANPA_Socios_Prazas::summary( array(
			array( 'activos' => '15', 'max_pupilos' => '15', 'espera' => '5' ),
		) );
		$this->assertSame( 15, $s['activos'] );
		$this->assertTrue( $s['espera_visible'] );
	}

	public function test_zero_capacity_group_is_never_completo(): void {
		// max_pupilos 0 → no real capacity → never "completo".
		$s = ANPA_Socios_Prazas::summary( array(
			array( 'activos' => 0, 'max_pupilos' => 0, 'espera' => 0 ),
		) );
		$this->assertTrue( $s['has_groups'] );
		$this->assertFalse( $s['completo'] );
	}
}
