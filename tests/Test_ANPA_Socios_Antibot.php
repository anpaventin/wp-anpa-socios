<?php
/**
 * Unit tests for ANPA_Socios_Antibot.
 *
 * Pure tests — no WordPress bootstrap required.
 *
 * @since  1.4.1
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ANPA_Socios_Antibot
 */
final class Test_ANPA_Socios_Antibot extends TestCase {

	/**
	 * Honeypot filled → rejected (false).
	 */
	public function test_honeypot_filled_returns_false(): void {
		$now = 1000000;
		$this->assertFalse(
			ANPA_Socios_Antibot::passes( 'spam-content', $now - 10, $now )
		);
	}

	/**
	 * Honeypot with only whitespace → treated as empty → passes.
	 */
	public function test_honeypot_whitespace_only_passes(): void {
		$now = 1000000;
		$this->assertTrue(
			ANPA_Socios_Antibot::passes( '   ', $now - 10, $now )
		);
	}

	/**
	 * Empty honeypot + elapsed >= 3 seconds → passes.
	 */
	public function test_empty_honeypot_and_valid_elapsed_passes(): void {
		$now = 1000000;
		$this->assertTrue(
			ANPA_Socios_Antibot::passes( '', $now - 5, $now )
		);
	}

	/**
	 * render_ts null → rejected (fail-closed).
	 */
	public function test_null_render_ts_returns_false(): void {
		$now = 1000000;
		$this->assertFalse(
			ANPA_Socios_Antibot::passes( '', null, $now )
		);
	}

	/**
	 * Elapsed 1 second (< 3s threshold) → rejected.
	 */
	public function test_elapsed_1_second_returns_false(): void {
		$now = 1000000;
		$this->assertFalse(
			ANPA_Socios_Antibot::passes( '', $now - 1, $now )
		);
	}

	/**
	 * Elapsed exactly 3 seconds → passes (boundary).
	 */
	public function test_elapsed_exactly_3_seconds_passes(): void {
		$now = 1000000;
		$this->assertTrue(
			ANPA_Socios_Antibot::passes( '', $now - 3, $now )
		);
	}

	/**
	 * Elapsed > 3600 seconds → rejected (stale form).
	 */
	public function test_elapsed_over_3600_seconds_returns_false(): void {
		$now = 1000000;
		$this->assertFalse(
			ANPA_Socios_Antibot::passes( '', $now - 3601, $now )
		);
	}

	/**
	 * Elapsed exactly 3600 seconds → passes (boundary).
	 */
	public function test_elapsed_exactly_3600_seconds_passes(): void {
		$now = 1000000;
		$this->assertTrue(
			ANPA_Socios_Antibot::passes( '', $now - 3600, $now )
		);
	}

	/**
	 * Elapsed 2 seconds → rejected (too fast).
	 */
	public function test_elapsed_2_seconds_returns_false(): void {
		$now = 1000000;
		$this->assertFalse(
			ANPA_Socios_Antibot::passes( '', $now - 2, $now )
		);
	}
}
