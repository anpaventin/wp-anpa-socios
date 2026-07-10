<?php
/**
 * Security invariant tests for fase17 admin UX restructure.
 *
 * These are PURE, runnable tests that lock in the security guarantees
 * introduced by fase17 at the source level. They use either the pure
 * `decide()` / crypto methods or source-file scanning to assert invariants
 * without needing a WordPress bootstrap or REST dispatch.
 *
 * Live HTTP-level verification (actual 403/404 from WP_REST_Server) is
 * deferred to PR-17s8 E2E testing on staging.
 *
 * @since  1.32.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PR-17s6 — Security tests locking fase17 invariants.
 */
final class Test_ANPA_Socios_Security_Fase17 extends TestCase {

	// ─────────────────────────────────────────────────────────────────
	// Task 35: Admin gate is capability-based (pure decide() tests)
	// HTTP-level 403 verified in PR-17s8 E2E.
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Unauthenticated/no-capability user gets 403 + anpa_admin_forbidden.
	 */
	public function test_unauthenticated_admin_returns_403(): void {
		$r = ANPA_Socios_Admin_Auth::decide( false, 'x@example.com', false, null, '' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 403, $r['status'] );
		$this->assertSame( 'anpa_admin_forbidden', $r['code'] );
	}

	/**
	 * Authenticated user with manage_options capability passes the gate.
	 */
	public function test_authenticated_admin_with_capability_passes(): void {
		$r = ANPA_Socios_Admin_Auth::decide( true, 'a@example.com', false, null, '' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 200, $r['status'] );
		$this->assertSame( 'a@example.com', $r['email'] );
	}

	// ─────────────────────────────────────────────────────────────────
	// Task 36: Removed public endpoints are not registered (source-scan)
	// HTTP-level 404 verified in PR-17s8 E2E.
	// ─────────────────────────────────────────────────────────────────

	/**
	 * /area/me/admin-auth route must not be registered.
	 */
	public function test_removed_endpoint_admin_auth(): void {
		$file = dirname( __DIR__ ) . '/includes/class-anpa-socios-area-rest.php';
		$this->assertFileExists( $file );
		$src = file_get_contents( $file );
		$this->assertStringNotContainsString( '/area/me/admin-auth', $src );
	}

	/**
	 * /area/me/admin-password route must not be registered.
	 */
	public function test_removed_endpoint_admin_password(): void {
		$file = dirname( __DIR__ ) . '/includes/class-anpa-socios-area-rest.php';
		$src  = file_get_contents( $file );
		$this->assertStringNotContainsString( '/area/me/admin-password', $src );
	}

	/**
	 * /area/master/init-status route must not be registered.
	 */
	public function test_removed_endpoint_master_init_status(): void {
		$file = dirname( __DIR__ ) . '/includes/class-anpa-socios-area-rest.php';
		$src  = file_get_contents( $file );
		$this->assertStringNotContainsString( '/area/master/init-status', $src );
	}

	/**
	 * /area/master/init route must not be registered.
	 */
	public function test_removed_endpoint_master_init(): void {
		$file = dirname( __DIR__ ) . '/includes/class-anpa-socios-area-rest.php';
		$src  = file_get_contents( $file );
		$this->assertStringNotContainsString( '/area/master/init', $src );
	}

	// ─────────────────────────────────────────────────────────────────
	// Task 37: Admin gate is NOT area-session dependent (source-scan)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Admin auth gate must not reference authenticate_area_session.
	 */
	public function test_admin_auth_not_area_session_dependent(): void {
		$file = dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-admin-auth.php';
		$this->assertFileExists( $file );
		$src = file_get_contents( $file );
		$this->assertStringNotContainsString(
			'authenticate_area_session',
			$src,
			'Admin gate must not depend on the area session mechanism.'
		);
	}

	/**
	 * Admin auth gate must use current_user_can (WP native capability).
	 */
	public function test_admin_auth_uses_current_user_can(): void {
		$file = dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-admin-auth.php';
		$src  = file_get_contents( $file );
		$this->assertStringContainsString(
			'current_user_can',
			$src,
			'Admin gate must use WP native current_user_can for authorization.'
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Task 38: Front-end has no admin sections (source-scan)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * area.js must not contain admin-section or admin-entry markup refs.
	 */
	public function test_area_js_has_no_admin_sections(): void {
		$file = dirname( __DIR__ ) . '/assets/js/area.js';
		$this->assertFileExists( $file );
		$src = file_get_contents( $file );
		$this->assertStringNotContainsString( 'data-admin-section', $src );
		$this->assertStringNotContainsString( 'data-admin-entry', $src );
		$this->assertStringNotContainsString( 'admin-auth', $src );
		$this->assertStringNotContainsString( 'master-init', $src );
	}

	/**
	 * area-page.php must not contain admin-section or admin-entry markup.
	 */
	public function test_area_page_has_no_admin_sections(): void {
		$file = dirname( __DIR__ ) . '/includes/class-anpa-socios-area-page.php';
		$this->assertFileExists( $file );
		$src = file_get_contents( $file );
		$this->assertStringNotContainsString( 'data-admin-section', $src );
		$this->assertStringNotContainsString( 'data-admin-entry', $src );
		$this->assertStringNotContainsString( 'admin-auth', $src );
		$this->assertStringNotContainsString( 'master-init', $src );
	}

	// ─────────────────────────────────────────────────────────────────
	// Task 39: Banking passphrase still works (pure crypto round-trip)
	// Confirms fase17 did not weaken the banking encryption.
	// ─────────────────────────────────────────────────────────────────

	/**
	 * wrap_secret + unwrap_secret round-trip with correct passphrase.
	 */
	public function test_banking_passphrase_correct_roundtrip(): void {
		$passphrase = 'correct horse battery staple word';
		$secret_b64 = base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );

		$wrapped = ANPA_Socios_Crypto::wrap_secret( $secret_b64, $passphrase );
		$this->assertIsArray( $wrapped, 'wrap_secret must return array.' );
		$this->assertArrayHasKey( 'blob', $wrapped );
		$this->assertArrayHasKey( 'salt', $wrapped );
		$this->assertArrayHasKey( 'nonce', $wrapped );

		$recovered = ANPA_Socios_Crypto::unwrap_secret(
			$wrapped['blob'],
			$wrapped['salt'],
			$wrapped['nonce'],
			$passphrase
		);
		$this->assertSame( $secret_b64, $recovered, 'Correct passphrase must recover original secret.' );
	}

	/**
	 * wrap_secret + unwrap_secret with WRONG passphrase returns null.
	 */
	public function test_banking_passphrase_wrong_returns_null(): void {
		$passphrase = 'correct horse battery staple word';
		$secret_b64 = base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );

		$wrapped = ANPA_Socios_Crypto::wrap_secret( $secret_b64, $passphrase );
		$this->assertIsArray( $wrapped );

		$recovered = ANPA_Socios_Crypto::unwrap_secret(
			$wrapped['blob'],
			$wrapped['salt'],
			$wrapped['nonce'],
			'totally wrong passphrase here'
		);
		$this->assertNull( $recovered, 'Wrong passphrase must return null.' );
	}

	// ─────────────────────────────────────────────────────────────────
	// Task 40 note: master-auth class removal is already covered by
	// tests/Test_ANPA_Socios_Master_Auth_Removed.php (5 assertions).
	// Full PHPUnit suite + php -l verified as part of this PR.
	// ─────────────────────────────────────────────────────────────────
}
