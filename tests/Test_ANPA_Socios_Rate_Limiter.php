<?php
/**
 * Unit tests for ANPA_Socios_Rate_Limiter.
 *
 * Pure PHP tests; no WordPress bootstrap.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Rate_Limiter extends TestCase {

	/**
	 * No previous timestamps means the request is allowed.
	 */
	public function test_permitir_allows_empty_history(): void {
		$this->assertTrue(
			ANPA_Socios_Rate_Limiter::permitir( array(), 3, 3600 )
		);
	}

	/**
	 * Fewer than the maximum timestamps inside the window are allowed.
	 */
	public function test_permitir_allows_under_limit_inside_window(): void {
		$now        = time();
		$timestamps = array( $now - 60, $now - 120 );

		$this->assertTrue(
			ANPA_Socios_Rate_Limiter::permitir( $timestamps, 3, 3600, $now )
		);
	}

	/**
	 * Reaching the maximum timestamps inside the window blocks the request.
	 */
	public function test_permitir_blocks_at_limit_inside_window(): void {
		$now        = time();
		$timestamps = array( $now - 60, $now - 120, $now - 180 );

		$this->assertFalse(
			ANPA_Socios_Rate_Limiter::permitir( $timestamps, 3, 3600, $now )
		);
	}

	/**
	 * Old timestamps outside the window do not count against the limit.
	 */
	public function test_permitir_ignores_timestamps_outside_window(): void {
		$now        = time();
		$timestamps = array( $now - 60, $now - 120, $now - 4000 );

		$this->assertTrue(
			ANPA_Socios_Rate_Limiter::permitir( $timestamps, 3, 3600, $now )
		);
	}

	/**
	 * Non-integer timestamp values are normalised before evaluating the limit.
	 */
	public function test_permitir_normalises_timestamp_values(): void {
		$now        = time();
		$timestamps = array( (string) ( $now - 60 ), $now - 120, null );

		$this->assertTrue(
			ANPA_Socios_Rate_Limiter::permitir( $timestamps, 3, 3600, $now )
		);
	}
}
