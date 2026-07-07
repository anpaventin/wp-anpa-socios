<?php
/**
 * Unit tests for the course season lifecycle helper (fase12).
 *
 * @since  1.18.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Season extends TestCase {

	public function test_default_data_inicio_is_sept_1_of_start_year(): void {
		$this->assertSame( '2026-09-01', ANPA_Socios_Season::default_data_inicio( '2026/2027' ) );
		$this->assertSame( '2025-09-01', ANPA_Socios_Season::default_data_inicio( '2025/2026' ) );
	}

	public function test_default_data_peche_is_jun_20_of_end_year(): void {
		$this->assertSame( '2027-06-20', ANPA_Socios_Season::default_data_peche( '2026/2027' ) );
		$this->assertSame( '2026-06-20', ANPA_Socios_Season::default_data_peche( '2025/2026' ) );
	}

	public function test_should_close_true_on_or_after_data_peche_when_activo(): void {
		$this->assertTrue( ANPA_Socios_Season::should_close( '2026-06-20', 'activo', '2026-06-20' ) );
		$this->assertTrue( ANPA_Socios_Season::should_close( '2026-07-01', 'activo', '2026-06-20' ) );
	}

	public function test_should_close_false_before_data_peche(): void {
		$this->assertFalse( ANPA_Socios_Season::should_close( '2026-06-19', 'activo', '2026-06-20' ) );
	}

	public function test_should_close_false_when_not_activo(): void {
		$this->assertFalse( ANPA_Socios_Season::should_close( '2026-07-01', 'pendente', '2026-06-20' ) );
		$this->assertFalse( ANPA_Socios_Season::should_close( '2026-07-01', 'pechado', '2026-06-20' ) );
	}

	public function test_should_activate_true_on_or_after_inicio_when_pendente(): void {
		$this->assertTrue( ANPA_Socios_Season::should_activate( '2026-09-01', 'pendente', '2026-09-01' ) );
		$this->assertTrue( ANPA_Socios_Season::should_activate( '2026-09-15', 'pendente', '2026-09-01' ) );
	}

	public function test_should_activate_false_before_inicio(): void {
		$this->assertFalse( ANPA_Socios_Season::should_activate( '2026-08-31', 'pendente', '2026-09-01' ) );
	}

	public function test_should_activate_false_when_not_pendente(): void {
		$this->assertFalse( ANPA_Socios_Season::should_activate( '2026-09-01', 'activo', '2026-09-01' ) );
	}

	public function test_estado_for_pendente_before_inicio(): void {
		$this->assertSame(
			'pendente',
			ANPA_Socios_Season::estado_for( '2026-07-06', '2026-09-01', '2027-06-20' )
		);
	}

	public function test_estado_for_activo_in_season(): void {
		$this->assertSame(
			'activo',
			ANPA_Socios_Season::estado_for( '2026-10-01', '2026-09-01', '2027-06-20' )
		);
	}

	public function test_estado_for_pechado_after_peche(): void {
		$this->assertSame(
			'pechado',
			ANPA_Socios_Season::estado_for( '2027-06-20', '2026-09-01', '2027-06-20' )
		);
	}

	public function test_next_curso(): void {
		$this->assertSame( '2026/2027', ANPA_Socios_Season::next_curso( '2025/2026' ) );
	}
}
