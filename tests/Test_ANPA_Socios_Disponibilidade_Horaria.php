<?php
/**
 * Tests for ANPA_Socios_Disponibilidade_Horaria (fase26 PR-26s4, t27).
 *
 * Pure helper: no WordPress, no DB. Semi-open intervals [start, end):
 * contiguous slots never overlap; `a_start < b_end AND a_end > b_start`.
 *
 * @package ANPA_Socios
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-disponibilidade-horaria.php';

final class Test_ANPA_Socios_Disponibilidade_Horaria extends TestCase {

	// -- normalize_time ------------------------------------------------

	/** @dataProvider valid_times */
	public function test_normalize_time_accepts_and_canonicalises( string $raw, string $expected ): void {
		$this->assertSame( $expected, ANPA_Socios_Disponibilidade_Horaria::normalize_time( $raw ) );
	}

	/** @return array<string,array{string,string}> */
	public static function valid_times(): array {
		return array(
			'canonical'      => array( '13:00', '13:00' ),
			'pad hour'       => array( '9:05', '09:05' ),
			'whitespace'     => array( ' 14:20 ', '14:20' ),
			'midnight'       => array( '0:00', '00:00' ),
			'last minute'    => array( '23:59', '23:59' ),
		);
	}

	/** @dataProvider invalid_times */
	public function test_normalize_time_rejects_garbage( string $raw ): void {
		$this->assertNull( ANPA_Socios_Disponibilidade_Horaria::normalize_time( $raw ) );
	}

	/** @return array<string,array{string}> */
	public static function invalid_times(): array {
		return array(
			'empty'        => array( '' ),
			'hour 24'      => array( '24:00' ),
			'minute 60'    => array( '12:60' ),
			'no colon'     => array( '1300' ),
			'letters'      => array( 'xx:yy' ),
			'negative'     => array( '-1:00' ),
			'seconds'      => array( '13:00:00' ),
		);
	}

	// -- normalize_interval --------------------------------------------

	public function test_normalize_interval_both_empty_is_empty(): void {
		$this->assertSame( array(), ANPA_Socios_Disponibilidade_Horaria::normalize_interval( '', '' ) );
		$this->assertSame( array(), ANPA_Socios_Disponibilidade_Horaria::normalize_interval( null, null ) );
	}

	public function test_normalize_interval_valid_pair(): void {
		$this->assertSame(
			array( 'inicio' => '13:00', 'fin' => '14:00' ),
			ANPA_Socios_Disponibilidade_Horaria::normalize_interval( '13:00', '14:00' )
		);
	}

	/** @dataProvider broken_intervals */
	public function test_normalize_interval_rejects_broken_pairs( ?string $a, ?string $b ): void {
		$this->assertNull( ANPA_Socios_Disponibilidade_Horaria::normalize_interval( $a, $b ) );
	}

	public function test_normalize_interval_rejects_non_scalar_input_without_throwing(): void {
		$this->assertNull( ANPA_Socios_Disponibilidade_Horaria::normalize_interval( array( '13:00' ), '14:00' ) );
		$this->assertNull( ANPA_Socios_Disponibilidade_Horaria::normalize_interval( '13:00', array( '14:00' ) ) );
	}

	/** @return array<string,array{?string,?string}> */
	public static function broken_intervals(): array {
		return array(
			'only start'      => array( '13:00', '' ),
			'only end'        => array( '', '14:00' ),
			'start after end' => array( '15:00', '14:00' ),
			'zero length'     => array( '14:00', '14:00' ),
			'bad time'        => array( '25:00', '26:00' ),
		);
	}

	// -- overlaps: semi-open [inicio, fin) -----------------------------

	public function test_overlapping_intervals_conflict(): void {
		$this->assertTrue( ANPA_Socios_Disponibilidade_Horaria::overlaps( '13:00', '14:00', '13:30', '15:00' ) );
		$this->assertTrue( ANPA_Socios_Disponibilidade_Horaria::overlaps( '13:30', '13:45', '13:00', '14:00' ), 'contained interval overlaps' );
		$this->assertTrue( ANPA_Socios_Disponibilidade_Horaria::overlaps( '13:00', '14:00', '13:00', '14:00' ), 'identical intervals overlap' );
	}

	public function test_contiguous_intervals_do_not_conflict(): void {
		$this->assertFalse( ANPA_Socios_Disponibilidade_Horaria::overlaps( '13:00', '14:00', '14:00', '15:00' ), 'group starting when meals end is valid' );
		$this->assertFalse( ANPA_Socios_Disponibilidade_Horaria::overlaps( '12:00', '13:00', '13:00', '14:00' ), 'group ending when meals start is valid' );
		$this->assertFalse( ANPA_Socios_Disponibilidade_Horaria::overlaps( '09:00', '10:00', '16:00', '17:00' ), 'disjoint intervals' );
	}

	// -- conflicts: group vs per-level meal windows --------------------

	public function test_conflicts_reports_each_clashing_level(): void {
		$group = array( 'horario' => '13:30-14:30', 'dias' => array( 'luns', 'mercores' ) );
		$meals = array(
			7 => array( 'inicio' => '13:00', 'fin' => '14:00' ), // clashes
			8 => array( 'inicio' => '14:30', 'fin' => '15:30' ), // contiguous: fine
			9 => array(),                                        // no meal window: fine
		);

		$conflicts = ANPA_Socios_Disponibilidade_Horaria::conflicts( $group, $meals );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 7, $conflicts[0]['nivel_id'] );
		$this->assertSame( '13:00', $conflicts[0]['comedor_inicio'] );
		$this->assertSame( '14:00', $conflicts[0]['comedor_fin'] );
	}

	public function test_conflicts_without_group_schedule_never_invents_blocks(): void {
		$meals = array( 7 => array( 'inicio' => '13:00', 'fin' => '14:00' ) );
		$this->assertSame( array(), ANPA_Socios_Disponibilidade_Horaria::conflicts( array( 'horario' => '', 'dias' => array( 'luns' ) ), $meals ) );
		$this->assertSame( array(), ANPA_Socios_Disponibilidade_Horaria::conflicts( array( 'horario' => 'garbage', 'dias' => array( 'luns' ) ), $meals ) );
	}

	public function test_conflicts_empty_meal_map_is_empty(): void {
		$group = array( 'horario' => '13:30-14:30', 'dias' => array( 'luns' ) );
		$this->assertSame( array(), ANPA_Socios_Disponibilidade_Horaria::conflicts( $group, array() ) );
	}
}
