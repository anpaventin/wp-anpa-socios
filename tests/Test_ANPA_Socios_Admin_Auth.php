<?php
/**
 * Unit tests for the native WordPress admin authorization gate.
 *
 * Targets the PURE `decide()` core (no WordPress bootstrap). The thin
 * `permission_*` adapters are WP glue verified via php -l + staging E2E.
 *
 * @since  1.31.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers ANPA_Socios_Admin_Auth::decide() (fase17 R6a).
 */
final class Test_ANPA_Socios_Admin_Auth extends TestCase {

	/**
	 * Builds a real wrapped banking secret for passphrase tests.
	 *
	 * @param  string $passphrase Passphrase to wrap with.
	 * @return array{blob:string,salt:string,nonce:string}
	 */
	private function wrapped( string $passphrase ): array {
		$secret_b64 = base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$wrapped    = ANPA_Socios_Crypto::wrap_secret( $secret_b64, $passphrase );
		$this->assertIsArray( $wrapped );

		return $wrapped;
	}

	public function test_without_capability_is_forbidden(): void {
		$r = ANPA_Socios_Admin_Auth::decide( false, 'admin@example.com', false, null, '' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 403, $r['status'] );
		$this->assertSame( 'anpa_admin_forbidden', $r['code'] );
		$this->assertSame( '', $r['email'], 'No identity leaks when forbidden.' );
	}

	public function test_valid_admin_passes_and_lowercases_email(): void {
		$r = ANPA_Socios_Admin_Auth::decide( true, '  Admin@Example.COM ', false, null, '' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 200, $r['status'] );
		$this->assertSame( 'admin@example.com', $r['email'] );
		$this->assertNull( $r['secret'] );
	}

	public function test_sensitive_requires_passphrase(): void {
		$r = ANPA_Socios_Admin_Auth::decide( true, 'admin@example.com', true, $this->wrapped( 'correct horse battery staple word' ), '' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 400, $r['status'] );
		$this->assertSame( 'anpa_admin_passphrase', $r['code'] );
	}

	public function test_sensitive_without_configured_key(): void {
		$r = ANPA_Socios_Admin_Auth::decide( true, 'admin@example.com', true, null, 'some passphrase here now' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 400, $r['status'] );
		$this->assertSame( 'anpa_admin_no_key', $r['code'] );
	}

	public function test_sensitive_with_wrong_passphrase(): void {
		$wrapped = $this->wrapped( 'the real passphrase five words' );
		$r       = ANPA_Socios_Admin_Auth::decide( true, 'admin@example.com', true, $wrapped, 'a wrong passphrase entirely' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 403, $r['status'] );
		$this->assertSame( 'anpa_admin_bad_passphrase', $r['code'] );
		$this->assertNull( $r['secret'] );
	}

	public function test_sensitive_with_correct_passphrase_returns_secret(): void {
		$passphrase = 'the real passphrase five words';
		$wrapped    = $this->wrapped( $passphrase );
		$r          = ANPA_Socios_Admin_Auth::decide( true, 'admin@example.com', true, $wrapped, $passphrase );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 200, $r['status'] );
		$this->assertNotNull( $r['secret'] );
		$this->assertNotSame( '', (string) $r['secret'] );
	}
}
