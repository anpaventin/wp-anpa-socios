<?php
/**
 * Unit tests for ANPA_Socios_Flow pure helpers.
 *
 * @since  1.2.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Flow extends TestCase {

	// ──────────────────────────────────────────────
	// next() — privacy-preserving route decision
	// ──────────────────────────────────────────────

	/**
	 * Active socio -> area.
	 */
	public function test_next_returns_area_for_active_socio(): void {
		$flags = array( 'socio' => 'activo' );
		$this->assertSame(
			'area',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	/**
	 * pendiente_alta socio -> alta (resume).
	 */
	public function test_next_returns_alta_for_pendiente_alta_socio(): void {
		$flags = array( 'socio' => 'pendiente_alta' );
		$this->assertSame(
			'alta',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	/**
	 * baixa socio -> inactivo.
	 */
	public function test_next_returns_inactivo_for_baixa_socio(): void {
		$flags = array( 'socio' => 'baixa' );
		$this->assertSame(
			'inactivo',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	/**
	 * Active socio with a pending baixa request -> baixa_pendente.
	 */
	public function test_next_returns_baixa_pendente_for_active_with_pending_baixa(): void {
		$flags = array( 'socio' => 'activo', 'socio_baixa' => 'solicitada' );
		$this->assertSame(
			'baixa_pendente',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	/**
	 * socio_baixa is ignored unless the socio is active (no false positives).
	 */
	public function test_next_baixa_pendente_requires_active_socio(): void {
		$flags = array( 'socio' => 'baixa', 'socio_baixa' => 'solicitada' );
		$this->assertSame(
			'inactivo',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	/**
	 * No socio, no empresa -> alta (as if new).
	 */
	public function test_next_returns_alta_when_nothing_found(): void {
		$this->assertSame(
			'alta',
			ANPA_Socios_Flow::next( array() )
		);
	}

	/**
	 * Active empresa -> empresa.
	 */
	public function test_next_returns_empresa_for_active_empresa(): void {
		$flags = array( 'empresa' => 'activo' );
		$this->assertSame(
			'empresa',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	/**
	 * Inactive empresa -> falls through to alta (no info leak).
	 */
	public function test_next_returns_alta_for_inactive_empresa(): void {
		$flags = array( 'empresa' => 'inactivo' );
		$this->assertSame(
			'alta',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	/**
	 * Precedence: socio wins over empresa in the mixed case. The SDD
	 * says each email is socio OR empresa, not both. The helper still
	 * produces a deterministic decision in case both flags arrive
	 * (defensive, no info leak).
	 */
	public function test_next_prefers_socio_over_empresa(): void {
		$flags = array(
			'socio'   => 'activo',
			'empresa' => 'activo',
		);
		$this->assertSame(
			'area',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	/**
	 * Invalid flag value is treated as not found (privacy).
	 */
	public function test_next_treats_unknown_socio_value_as_alta(): void {
		$flags = array( 'socio' => 'misterioso' );
		$this->assertSame(
			'alta',
			ANPA_Socios_Flow::next( $flags )
		);
	}

	// ──────────────────────────────────────────────
	// sanitise_flags()
	// ──────────────────────────────────────────────

	public function test_sanitise_flags_keeps_only_known_states(): void {
		$input = array(
			'socio'   => 'activo',
			'empresa' => 'inactivo',
		);
		$this->assertSame(
			$input,
			ANPA_Socios_Flow::sanitise_flags( $input )
		);
	}

	public function test_sanitise_flags_drops_unknown_keys(): void {
		$input = array(
			'socio'   => 'activo',
			'admin'   => 'super',
		);
		$this->assertSame(
			array( 'socio' => 'activo' ),
			ANPA_Socios_Flow::sanitise_flags( $input )
		);
	}
}
