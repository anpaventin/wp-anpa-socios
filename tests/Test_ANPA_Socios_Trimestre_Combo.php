<?php
/**
 * 1.68.0: the two combos of Xestión → Matrículas («Trimestre activo» and
 * «Matrículas abertas para») are planned by a pure helper on top of the
 * trimester rows and the existing transition rules.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Trimestre_Combo extends TestCase {

	private function rows( array $estados, array $ventanas = array( 'pechada', 'pechada', 'pechada' ), bool $presente = true ): array {
		$out = array();
		foreach ( array( 1, 2, 3 ) as $tri ) {
			$out[ $tri ] = array( 'estado' => $estados[ $tri - 1 ], 'ventana_estado' => $ventanas[ $tri - 1 ], 'presente' => $presente );
		}
		return $out;
	}

	// ── resumo ──────────────────────────────────────────────────────────

	public function test_resumo_reads_the_active_trimester_and_the_open_window(): void {
		$r = ANPA_Socios_Trimestre_Combo::resumo( $this->rows( array( 'pechado', 'activo', 'pendente' ), array( 'pechada', 'aberta', 'pechada' ) ) );
		$this->assertSame( 2, $r['trimestre_activo'] );
		$this->assertSame( 2, $r['ventana_aberta'] );
		$this->assertTrue( $r['inicializado'] );
	}

	public function test_resumo_all_closed_means_curso_pechado_and_no_window(): void {
		$r = ANPA_Socios_Trimestre_Combo::resumo( $this->rows( array( 'pechado', 'pechado', 'pechado' ) ) );
		$this->assertSame( ANPA_Socios_Trimestre_Combo::CURSO_PECHADO, $r['trimestre_activo'] );
		$this->assertSame( 0, $r['ventana_aberta'] );
	}

	public function test_resumo_fails_closed_when_rows_are_missing(): void {
		$r = ANPA_Socios_Trimestre_Combo::resumo( $this->rows( array( '', '', '' ), array( 'pechada', 'pechada', 'pechada' ), false ) );
		$this->assertSame( '', $r['trimestre_activo'] );
		$this->assertSame( 0, $r['ventana_aberta'] );
		$this->assertFalse( $r['inicializado'] );
	}

	public function test_resumo_all_pending_has_no_active_trimester(): void {
		$r = ANPA_Socios_Trimestre_Combo::resumo( $this->rows( array( 'pendente', 'pendente', 'pendente' ) ) );
		$this->assertSame( '', $r['trimestre_activo'] );
	}

	// ── plan_estado ─────────────────────────────────────────────────────

	public function test_plan_estado_moving_forward_closes_previous_and_activates_target(): void {
		$plan = ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( 'activo', 'pendente', 'pendente' ) ), 2 );
		$this->assertSame( array( array( 1, 'activo', 'pechado' ), array( 2, 'pendente', 'activo' ) ), $plan );
	}

	public function test_plan_estado_skipping_a_trimester_closes_it_too(): void {
		// T2 must become «pechado», but pendente → pechado is not a legal transition:
		// it goes through activo first (two steps), so the log stays truthful.
		$plan = ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( 'activo', 'pendente', 'pendente' ) ), 3 );
		$this->assertSame( array( array( 1, 'activo', 'pechado' ), array( 2, 'pendente', 'activo' ), array( 2, 'activo', 'pechado' ), array( 3, 'pendente', 'activo' ) ), $plan );
	}

	public function test_plan_estado_going_back_reopens_the_target_and_closes_the_later_active_one(): void {
		$plan = ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( 'pechado', 'pechado', 'activo' ) ), 2 );
		$this->assertSame( array( array( 3, 'activo', 'pechado' ), array( 2, 'pechado', 'activo' ) ), $plan );
	}

	public function test_plan_estado_curso_pechado_closes_every_trimester(): void {
		$plan = ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( 'pechado', 'activo', 'pendente' ) ), ANPA_Socios_Trimestre_Combo::CURSO_PECHADO );
		$this->assertSame( array( array( 2, 'activo', 'pechado' ), array( 3, 'pendente', 'activo' ), array( 3, 'activo', 'pechado' ) ), $plan );
	}

	public function test_plan_estado_is_a_noop_when_already_there(): void {
		$this->assertSame( array(), ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( 'pechado', 'activo', 'pendente' ) ), 2 ) );
		$this->assertSame( array(), ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( 'pechado', 'pechado', 'pechado' ) ), ANPA_Socios_Trimestre_Combo::CURSO_PECHADO ) );
	}

	public function test_plan_estado_refuses_invalid_targets_and_uninitialised_rows(): void {
		$this->assertNull( ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( 'activo', 'pendente', 'pendente' ) ), 4 ) );
		$this->assertNull( ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( 'activo', 'pendente', 'pendente' ) ), 'calquera' ) );
		$this->assertNull( ANPA_Socios_Trimestre_Combo::plan_estado( $this->rows( array( '', '', '' ), array( 'pechada', 'pechada', 'pechada' ), false ), 1 ) );
	}

	// ── plan_ventana ────────────────────────────────────────────────────

	public function test_plan_ventana_opens_the_target_and_closes_any_other_open_window(): void {
		$plan = ANPA_Socios_Trimestre_Combo::plan_ventana( $this->rows( array( 'pechado', 'activo', 'pendente' ), array( 'aberta', 'pechada', 'pechada' ) ), 2 );
		$this->assertSame( array( array( 1, 'aberta', 'pechada' ), array( 2, 'pechada', 'aberta' ) ), $plan );
	}

	public function test_plan_ventana_zero_closes_everything(): void {
		$plan = ANPA_Socios_Trimestre_Combo::plan_ventana( $this->rows( array( 'activo', 'pendente', 'pendente' ), array( 'aberta', 'aberta', 'pechada' ) ), 0 );
		$this->assertSame( array( array( 1, 'aberta', 'pechada' ), array( 2, 'aberta', 'pechada' ) ), $plan );
		$this->assertSame( array(), ANPA_Socios_Trimestre_Combo::plan_ventana( $this->rows( array( 'activo', 'pendente', 'pendente' ) ), 0 ) );
	}

	public function test_plan_ventana_refuses_invalid_targets_and_uninitialised_rows(): void {
		$this->assertNull( ANPA_Socios_Trimestre_Combo::plan_ventana( $this->rows( array( 'activo', 'pendente', 'pendente' ) ), 7 ) );
		$this->assertNull( ANPA_Socios_Trimestre_Combo::plan_ventana( $this->rows( array( '', '', '' ), array( 'pechada', 'pechada', 'pechada' ), false ), 1 ) );
	}

	public function test_labels_are_in_galician(): void {
		$this->assertSame( 'Curso pechado', ANPA_Socios_Trimestre_Combo::etiqueta_destino( ANPA_Socios_Trimestre_Combo::CURSO_PECHADO ) );
		$this->assertSame( '2º trimestre', ANPA_Socios_Trimestre_Combo::etiqueta_destino( 2 ) );
		$this->assertSame( 'Matrículas pechadas', ANPA_Socios_Trimestre_Combo::etiqueta_ventana( 0 ) );
		$this->assertSame( 'Abertas para o 3º trimestre', ANPA_Socios_Trimestre_Combo::etiqueta_ventana( 3 ) );
	}
}
