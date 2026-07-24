<?php
/**
 * Tests for the pure academic-calendar domain (fase34): date validation,
 * trimester boundaries derived from dates, and the state value objects.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Calendario extends TestCase {

	private function datas(): array {
		return array(
			'inicio' => '2026-09-01',
			't1'     => '2026-12-15',
			't2'     => '2027-03-20',
			'peche'  => '2027-06-20',
		);
	}

	public function test_valid_dates_produce_no_errors(): void {
		$this->assertSame( array(), ANPA_Socios_Calendario::validar( $this->datas() ) );
	}

	public function test_operative_dates_present(): void {
		$this->assertTrue( ANPA_Socios_Calendario::ten_datas_operativas( $this->datas() ) );
		$d = $this->datas();
		$d['t1'] = '';
		$this->assertFalse( ANPA_Socios_Calendario::ten_datas_operativas( $d ) );
	}

	public function test_out_of_order_dates_are_rejected(): void {
		$d = $this->datas();
		$d['t2'] = '2026-11-01'; // t2 before t1
		$errs = ANPA_Socios_Calendario::validar( $d );
		$this->assertContains( 'orde_t1_t2', $errs );
	}

	public function test_dates_outside_course_range_are_rejected(): void {
		$d = $this->datas();
		$d['t1'] = '2027-07-01'; // after peche
		$errs = ANPA_Socios_Calendario::validar( $d );
		$this->assertContains( 't1_fora_rango', $errs );
	}

	public function test_inicio_after_peche_is_rejected(): void {
		$d = array( 'inicio' => '2027-06-20', 't1' => '', 't2' => '', 'peche' => '2026-09-01' );
		$this->assertContains( 'orde_inicio_peche', ANPA_Socios_Calendario::validar( $d ) );
	}

	public function test_invalid_date_format_is_rejected(): void {
		$d = $this->datas();
		$d['inicio'] = '2026-13-40';
		$this->assertContains( 'inicio_invalida', ANPA_Socios_Calendario::validar( $d ) );
	}

	public function test_trimester_boundaries_are_derived_from_dates(): void {
		$d = $this->datas();
		// Exact boundaries.
		$this->assertSame( 1, ANPA_Socios_Calendario::trimestre_para_data( '2026-09-01', $d ) );
		$this->assertSame( 1, ANPA_Socios_Calendario::trimestre_para_data( '2026-12-15', $d ) ); // last day of T1
		$this->assertSame( 2, ANPA_Socios_Calendario::trimestre_para_data( '2026-12-16', $d ) ); // first day of T2
		$this->assertSame( 2, ANPA_Socios_Calendario::trimestre_para_data( '2027-03-20', $d ) ); // last day of T2
		$this->assertSame( 3, ANPA_Socios_Calendario::trimestre_para_data( '2027-03-21', $d ) ); // first day of T3
		$this->assertSame( 3, ANPA_Socios_Calendario::trimestre_para_data( '2027-06-20', $d ) ); // last day of T3
	}

	public function test_limites_are_contiguous(): void {
		$lim = ANPA_Socios_Calendario::limites( $this->datas() );
		$this->assertSame( '2026-09-01', $lim[1]['inicio'] );
		$this->assertSame( '2026-12-15', $lim[1]['fin'] );
		$this->assertSame( '2026-12-16', $lim[2]['inicio'] );
		$this->assertSame( '2027-03-20', $lim[2]['fin'] );
		$this->assertSame( '2027-03-21', $lim[3]['inicio'] );
		$this->assertSame( '2027-06-20', $lim[3]['fin'] );
	}

	public function test_trimestre_actual_por_datas_uses_calendar_when_available(): void {
		$d = $this->datas();
		$this->assertSame( 2, ANPA_Socios_Trimestre::actual_por_datas( $d, '2027-01-10' ) );
	}

	public function test_trimestre_actual_por_datas_falls_back_to_month_model(): void {
		// No operative dates → legacy month model: October (month 10) → T1.
		$this->assertSame( 1, ANPA_Socios_Trimestre::actual_por_datas( null, '2026-10-10' ) );
		// February → T2 under the legacy month mapping.
		$this->assertSame( 2, ANPA_Socios_Trimestre::actual_por_datas( array( 'inicio' => '', 't1' => '', 't2' => '', 'peche' => '' ), '2027-02-10' ) );
	}

	public function test_trimestre_estado_transitions(): void {
		$this->assertTrue( ANPA_Socios_Trimestre_Estado::pode_transicionar( 'pendente', 'activo' ) );
		$this->assertTrue( ANPA_Socios_Trimestre_Estado::pode_transicionar( 'activo', 'pechado' ) );
		$this->assertTrue( ANPA_Socios_Trimestre_Estado::pode_transicionar( 'pechado', 'activo' ) );
		$this->assertFalse( ANPA_Socios_Trimestre_Estado::pode_transicionar( 'pendente', 'pechado' ) );
		$this->assertFalse( ANPA_Socios_Trimestre_Estado::pode_transicionar( 'activo', 'pendente' ) );
		$this->assertFalse( ANPA_Socios_Trimestre_Estado::pode_transicionar( 'x', 'activo' ) );
	}

	public function test_ventana_estado_transitions(): void {
		$this->assertTrue( ANPA_Socios_Ventana_Estado::pode_transicionar( 'pechada', 'aberta' ) );
		$this->assertTrue( ANPA_Socios_Ventana_Estado::pode_transicionar( 'aberta', 'pechada' ) );
		$this->assertFalse( ANPA_Socios_Ventana_Estado::pode_transicionar( 'aberta', 'aberta' ) );
		$this->assertFalse( ANPA_Socios_Ventana_Estado::pode_transicionar( 'x', 'aberta' ) );
	}

	public function test_trimestres_operativos_alcanzados_detecta_por_data(): void {
		$d = array( 't1' => '2026-12-15', 't2' => '2027-03-20' );
		// Before any operative close: nothing reached.
		$this->assertSame( array(), ANPA_Socios_Calendario::trimestres_operativos_alcanzados( '2026-10-01', $d ) );
		// On T1 close date (inclusive): T1 reached.
		$this->assertSame( array( 1 ), ANPA_Socios_Calendario::trimestres_operativos_alcanzados( '2026-12-15', $d ) );
		// After T1 but before T2: still only T1.
		$this->assertSame( array( 1 ), ANPA_Socios_Calendario::trimestres_operativos_alcanzados( '2027-01-10', $d ) );
		// On/after T2: both.
		$this->assertSame( array( 1, 2 ), ANPA_Socios_Calendario::trimestres_operativos_alcanzados( '2027-03-20', $d ) );
	}

	public function test_trimestres_operativos_alcanzados_ignora_datas_ausentes(): void {
		$this->assertSame( array(), ANPA_Socios_Calendario::trimestres_operativos_alcanzados( '2027-05-01', array( 't1' => '', 't2' => '' ) ) );
		// Malformed "today" yields no detection (fail-safe).
		$this->assertSame( array(), ANPA_Socios_Calendario::trimestres_operativos_alcanzados( 'nope', array( 't1' => '2026-12-15' ) ) );
	}

	// ── fase34 close-out: explicit edge-case coverage requested in review ──

	public function test_t1_close_before_course_start_is_rejected(): void {
		$d = $this->datas();
		$d['t1'] = '2026-08-01'; // before inicio 2026-09-01
		$this->assertContains( 't1_fora_rango', ANPA_Socios_Calendario::validar( $d ) );
	}

	public function test_t2_close_after_course_close_is_rejected(): void {
		$d = $this->datas();
		$d['t2'] = '2027-07-01'; // after peche 2027-06-20
		$this->assertContains( 't2_fora_rango', ANPA_Socios_Calendario::validar( $d ) );
	}

	public function test_t2_equal_or_before_t1_is_rejected(): void {
		$d = $this->datas();
		$d['t2'] = $d['t1']; // equal
		$this->assertContains( 'orde_t1_t2', ANPA_Socios_Calendario::validar( $d ) );
		$d['t2'] = '2026-12-01'; // strictly before t1
		$this->assertContains( 'orde_t1_t2', ANPA_Socios_Calendario::validar( $d ) );
	}

	public function test_operative_date_equal_to_a_course_boundary_is_rejected(): void {
		$d = $this->datas();
		// t1 exactly on inicio (boundary) — must be strictly inside (inicio, peche).
		$d['t1'] = $d['inicio'];
		$this->assertContains( 't1_fora_rango', ANPA_Socios_Calendario::validar( $d ) );
		$d = $this->datas();
		// t2 exactly on peche (boundary) — must be strictly inside.
		$d['t2'] = $d['peche'];
		$this->assertContains( 't2_fora_rango', ANPA_Socios_Calendario::validar( $d ) );
	}

	public function test_course_spanning_two_calendar_years_and_boundaries(): void {
		// The course spans Sep 2026 → Jun 2027 (two natural years). The trimester
		// mapping must be continuous across the Dec→Jan year change.
		$d = $this->datas();
		$this->assertSame( 1, ANPA_Socios_Calendario::trimestre_para_data( '2026-12-15', $d ) ); // last day of T1
		$this->assertSame( 2, ANPA_Socios_Calendario::trimestre_para_data( '2026-12-31', $d ) ); // Dec 31 > t1 → T2
		$this->assertSame( 2, ANPA_Socios_Calendario::trimestre_para_data( '2027-01-01', $d ) ); // new natural year, still T2
		$this->assertSame( 2, ANPA_Socios_Calendario::trimestre_para_data( '2026-12-16', $d ) );
	}

	public function test_leap_year_february_29_is_valid_and_maps(): void {
		// 2028 is a leap year; Feb 29 must validate and map to a trimester.
		$d = array( 'inicio' => '2027-09-01', 't1' => '2027-12-15', 't2' => '2028-03-20', 'peche' => '2028-06-20' );
		$this->assertSame( array(), ANPA_Socios_Calendario::validar( $d ) );
		$this->assertTrue( ANPA_Socios_Calendario::valida_data( '2028-02-29' ) );
		$this->assertFalse( ANPA_Socios_Calendario::valida_data( '2027-02-29' ) ); // 2027 not a leap year
		$this->assertSame( 2, ANPA_Socios_Calendario::trimestre_para_data( '2028-02-29', $d ) );
	}

	public function test_cron_detection_catches_up_after_missed_days(): void {
		// Cron not run for a while: a date well past both operative closes must
		// still report BOTH trimesters as reached (>= comparison, not ==).
		$d = array( 't1' => '2026-12-15', 't2' => '2027-03-20' );
		$this->assertSame( array( 1, 2 ), ANPA_Socios_Calendario::trimestres_operativos_alcanzados( '2027-05-30', $d ) );
	}
}
