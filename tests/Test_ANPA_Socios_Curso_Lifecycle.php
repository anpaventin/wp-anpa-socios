<?php
/**
 * TDD tests for the pure course lifecycle policy (fase22).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Curso_Lifecycle extends TestCase {

	private function plan( string $target, string $estado, bool $open, ?string $active, bool $replace ): array {
		if ( ! class_exists( 'ANPA_Socios_Curso_Lifecycle' ) ) {
			$this->fail( 'ANPA_Socios_Curso_Lifecycle class is missing.' );
		}

		return ANPA_Socios_Curso_Lifecycle::plan( $target, $estado, $open, $active, $replace );
	}

	public function test_activates_course_when_no_other_course_is_active(): void {
		$plan = $this->plan( '2026/2027', 'activo', true, null, false );

		$this->assertTrue( $plan['allowed'] );
		$this->assertNull( $plan['deactivate'] );
		$this->assertSame( 'activo', $plan['target_estado'] );
		$this->assertTrue( $plan['target_open'] );
	}

	public function test_refuses_to_replace_active_course_without_confirmation(): void {
		$plan = $this->plan( '2026/2027', 'activo', true, '2025/2026', false );

		$this->assertFalse( $plan['allowed'] );
		$this->assertSame( 'active_course_conflict', $plan['error'] );
		$this->assertNull( $plan['deactivate'] );
	}

	public function test_replaces_active_course_when_confirmed(): void {
		$plan = $this->plan( '2026/2027', 'activo', false, '2025/2026', true );

		$this->assertTrue( $plan['allowed'] );
		$this->assertSame( '2025/2026', $plan['deactivate'] );
		$this->assertSame( 'activo', $plan['target_estado'] );
		$this->assertFalse( $plan['target_open'] );
	}

	public function test_refuses_open_enrolment_for_inactive_course(): void {
		$plan = $this->plan( '2026/2027', 'pendente', true, null, false );

		$this->assertFalse( $plan['allowed'] );
		$this->assertSame( 'inactive_course_cannot_open', $plan['error'] );
	}

	public function test_rejects_unknown_state_instead_of_deactivating_course(): void {
		$plan = $this->plan( '2026/2027', 'actvio', false, '2026/2027', false );

		$this->assertFalse( $plan['allowed'] );
		$this->assertSame( 'invalid_state', $plan['error'] );
	}

	public function test_closing_course_always_closes_enrolment(): void {
		$plan = $this->plan( '2026/2027', 'pechado', true, '2026/2027', false );

		$this->assertTrue( $plan['allowed'] );
		$this->assertSame( 'pechado', $plan['target_estado'] );
		$this->assertFalse( $plan['target_open'] );
	}

	public function test_updating_current_active_course_needs_no_replacement_confirmation(): void {
		$plan = $this->plan( '2026/2027', 'activo', false, '2026/2027', false );

		$this->assertTrue( $plan['allowed'] );
		$this->assertNull( $plan['deactivate'] );
		$this->assertFalse( $plan['target_open'] );
	}

	public function test_season_rollover_never_activates_or_opens_next_course(): void {
		$plan = ANPA_Socios_Curso_Lifecycle::season_rollover( '2026/2027' );

		$this->assertSame( 'pechado', $plan['current_estado'] );
		$this->assertFalse( $plan['current_matriculas_abertas'] );
		$this->assertSame( '2027/2028', $plan['next_curso'] );
		$this->assertSame( 'pendente', $plan['next_estado'] );
		$this->assertFalse( $plan['next_matriculas_abertas'] );
	}
}
