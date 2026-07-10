<?php
/**
 * Tests for ANPA_Socios_Familia pure helper.
 *
 * @since 1.21.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ANPA_Socios_Familia
 */
class Test_ANPA_Socios_Familia extends TestCase {

	/**
	 * When familia_id is a positive integer, it is returned as-is.
	 */
	public function test_resolve_returns_familia_id_when_positive(): void {
		$this->assertSame( 42, ANPA_Socios_Familia::resolve_familia_id( 42, 7 ) );
	}

	/**
	 * When familia_id is null, falls back to socio_id.
	 */
	public function test_resolve_falls_back_to_socio_id_when_null(): void {
		$this->assertSame( 7, ANPA_Socios_Familia::resolve_familia_id( null, 7 ) );
	}

	/**
	 * When familia_id is 0, falls back to socio_id.
	 */
	public function test_resolve_falls_back_to_socio_id_when_zero(): void {
		$this->assertSame( 5, ANPA_Socios_Familia::resolve_familia_id( 0, 5 ) );
	}

	/**
	 * Negative familia_id is treated as unset (falls back to socio_id).
	 */
	public function test_resolve_falls_back_to_socio_id_when_negative(): void {
		$this->assertSame( 10, ANPA_Socios_Familia::resolve_familia_id( -1, 10 ) );
	}

	/**
	 * When familia_id equals socio_id, it still returns familia_id (identity case).
	 */
	public function test_resolve_identity_case(): void {
		$this->assertSame( 3, ANPA_Socios_Familia::resolve_familia_id( 3, 3 ) );
	}

	/**
	 * Both parents share a familia_id: second parent resolves to the shared id.
	 */
	public function test_resolve_shared_family(): void {
		// parent1.id=1, parent1.familia_id=1
		// parent2.id=2, parent2.familia_id=1 (linked)
		$this->assertSame( 1, ANPA_Socios_Familia::resolve_familia_id( 1, 2 ) );
	}

	// ──────────────────────────────────────────────
	// resolve_from_profile tests
	// ──────────────────────────────────────────────

	/**
	 * Profile with valid familia_id returns it directly.
	 */
	public function test_from_profile_uses_familia_id(): void {
		$profile = array( 'id' => '5', 'familia_id' => '42', 'email' => 'a@example.com' );
		$this->assertSame( 42, ANPA_Socios_Familia::resolve_from_profile( $profile ) );
	}

	/**
	 * Profile with empty familia_id falls back to socio id.
	 */
	public function test_from_profile_fallback_empty_familia(): void {
		$profile = array( 'id' => '7', 'familia_id' => '', 'email' => 'b@example.com' );
		$this->assertSame( 7, ANPA_Socios_Familia::resolve_from_profile( $profile ) );
	}

	/**
	 * Profile with familia_id = '0' falls back to socio id.
	 */
	public function test_from_profile_fallback_zero_familia(): void {
		$profile = array( 'id' => '9', 'familia_id' => '0', 'email' => 'c@example.com' );
		$this->assertSame( 9, ANPA_Socios_Familia::resolve_from_profile( $profile ) );
	}

	/**
	 * Profile missing id returns 0 (defensive guard).
	 */
	public function test_from_profile_returns_zero_without_id(): void {
		$profile = array( 'familia_id' => '5', 'email' => 'd@example.com' );
		$this->assertSame( 0, ANPA_Socios_Familia::resolve_from_profile( $profile ) );
	}

	/**
	 * Empty profile returns 0.
	 */
	public function test_from_profile_returns_zero_on_empty(): void {
		$this->assertSame( 0, ANPA_Socios_Familia::resolve_from_profile( array() ) );
	}

	/**
	 * Profile with null familia_id key falls back to socio id.
	 */
	public function test_from_profile_null_familia_id(): void {
		$profile = array( 'id' => '3', 'familia_id' => null, 'email' => 'e@example.com' );
		$this->assertSame( 3, ANPA_Socios_Familia::resolve_from_profile( $profile ) );
	}
}
