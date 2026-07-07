<?php
/**
 * Unit tests for ANPA_Socios_Area_Session pure helpers.
 *
 * Pure PHP tests; no WordPress bootstrap.
 *
 * @since  1.1.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Area_Session extends TestCase {

	private const TEST_KEY = 'test-auth-key-32bytes-long!!!';

	// ──────────────────────────────────────────────
	// digest()
	// ──────────────────────────────────────────────

	/**
	 * digest returns a 64-char hex string.
	 */
	public function test_digest_returns_64_hex(): void {
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = ANPA_Socios_Area_Session::digest( $token, self::TEST_KEY );

		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			$hash
		);
	}

	/**
	 * digest is deterministic: same inputs → same output.
	 */
	public function test_digest_deterministic(): void {
		$token = 'abc123';
		$a     = ANPA_Socios_Area_Session::digest( $token, self::TEST_KEY );
		$b     = ANPA_Socios_Area_Session::digest( $token, self::TEST_KEY );

		$this->assertSame( $a, $b );
	}

	/**
	 * digest with different key produces different output.
	 */
	public function test_digest_different_key_changes_output(): void {
		$token = 'abc123';
		$key_a = 'key-one-32bytes-long-key!!!01';
		$key_b = 'key-two-32bytes-long-key!!!02';

		$a = ANPA_Socios_Area_Session::digest( $token, $key_a );
		$b = ANPA_Socios_Area_Session::digest( $token, $key_b );

		$this->assertNotSame( $a, $b );
	}

	/**
	 * digest with different token produces different output.
	 */
	public function test_digest_different_token_changes_output(): void {
		$key   = self::TEST_KEY;
		$hash1 = ANPA_Socios_Area_Session::digest( 'alpha', $key );
		$hash2 = ANPA_Socios_Area_Session::digest( 'beta', $key );

		$this->assertNotSame( $hash1, $hash2 );
	}

	// ──────────────────────────────────────────────
	// assert_valid()
	// ──────────────────────────────────────────────

	/**
	 * Valid session: usage_count < max_uses, expires_at in the future.
	 */
	public function test_assert_valid_true_when_within_bounds(): void {
		$row = [
			'usage_count' => 10,
			'max_uses'    => 100,
			'expires_at'  => time() + 3600,
		];

		$this->assertTrue(
			ANPA_Socios_Area_Session::assert_valid( $row )
		);
	}

	/**
	 * usage_count equals max_uses → invalid (cap reached).
	 */
	public function test_assert_valid_false_when_usage_at_cap(): void {
		$row = [
			'usage_count' => 100,
			'max_uses'    => 100,
			'expires_at'  => time() + 3600,
		];

		$this->assertFalse(
			ANPA_Socios_Area_Session::assert_valid( $row )
		);
	}

	/**
	 * usage_count exceeds max_uses → invalid.
	 */
	public function test_assert_valid_false_when_usage_over_cap(): void {
		$row = [
			'usage_count' => 101,
			'max_uses'    => 100,
			'expires_at'  => time() + 3600,
		];

		$this->assertFalse(
			ANPA_Socios_Area_Session::assert_valid( $row )
		);
	}

	/**
	 * expires_at is in the past → invalid (TTL expired).
	 */
	public function test_assert_valid_false_when_expired(): void {
		$row = [
			'usage_count' => 5,
			'max_uses'    => 100,
			'expires_at'  => time() - 1,
		];

		$this->assertFalse(
			ANPA_Socios_Area_Session::assert_valid( $row )
		);
	}

	/**
	 * expires_at equals now → invalid (boundary: expired).
	 */
	public function test_assert_valid_false_when_expires_exactly_now(): void {
		$now = time();
		$row = [
			'usage_count' => 5,
			'max_uses'    => 100,
			'expires_at'  => $now,
		];

		$this->assertFalse(
			ANPA_Socios_Area_Session::assert_valid( $row, $now )
		);
	}

	/**
	 * Both expired AND over cap → invalid.
	 */
	public function test_assert_valid_false_when_both_expired_and_over_cap(): void {
		$row = [
			'usage_count' => 200,
			'max_uses'    => 100,
			'expires_at'  => time() - 3600,
		];

		$this->assertFalse(
			ANPA_Socios_Area_Session::assert_valid( $row )
		);
	}

	/**
	 * usage_count just under cap, expires just in future → valid (boundary).
	 */
	public function test_assert_valid_true_at_usage_boundary_one_below_cap(): void {
		$row = [
			'usage_count' => 99,
			'max_uses'    => 100,
			'expires_at'  => time() + 1,
		];

		$this->assertTrue(
			ANPA_Socios_Area_Session::assert_valid( $row )
		);
	}

	// ──────────────────────────────────────────────
	// ua_hash()
	// ──────────────────────────────────────────────

	/**
	 * ua_hash returns 64-char hex with injected key.
	 */
	public function test_ua_hash_returns_64_hex(): void {
		$hash = ANPA_Socios_Area_Session::ua_hash(
			'Mozilla/5.0 Test Browser',
			self::TEST_KEY
		);

		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			$hash
		);
	}

	/**
	 * ua_hash is deterministic.
	 */
	public function test_ua_hash_deterministic(): void {
		$ua = 'Mozilla/5.0 (X11; Linux x86_64)';
		$a  = ANPA_Socios_Area_Session::ua_hash( $ua, self::TEST_KEY );
		$b  = ANPA_Socios_Area_Session::ua_hash( $ua, self::TEST_KEY );

		$this->assertSame( $a, $b );
	}

	/**
	 * Different User-Agent produces different hash.
	 */
	public function test_ua_hash_different_ua_produces_different_hash(): void {
		$key   = self::TEST_KEY;
		$hash1 = ANPA_Socios_Area_Session::ua_hash( 'Chrome/120', $key );
		$hash2 = ANPA_Socios_Area_Session::ua_hash( 'Firefox/121', $key );

		$this->assertNotSame( $hash1, $hash2 );
	}

	// ──────────────────────────────────────────────
	// ip_hash()
	// ──────────────────────────────────────────────

	/**
	 * ip_hash returns 64-char hex with injected key.
	 */
	public function test_ip_hash_returns_64_hex(): void {
		$hash = ANPA_Socios_Area_Session::ip_hash(
			'192.168.1.1',
			self::TEST_KEY
		);

		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{64}$/',
			$hash
		);
	}

	/**
	 * ip_hash is deterministic.
	 */
	public function test_ip_hash_deterministic(): void {
		$ip = '203.0.113.42';
		$a  = ANPA_Socios_Area_Session::ip_hash( $ip, self::TEST_KEY );
		$b  = ANPA_Socios_Area_Session::ip_hash( $ip, self::TEST_KEY );

		$this->assertSame( $a, $b );
	}

	/**
	 * Different IP produces different hash.
	 */
	public function test_ip_hash_different_ip_produces_different_hash(): void {
		$key   = self::TEST_KEY;
		$hash1 = ANPA_Socios_Area_Session::ip_hash( '10.0.0.1', $key );
		$hash2 = ANPA_Socios_Area_Session::ip_hash( '10.0.0.2', $key );

		$this->assertNotSame( $hash1, $hash2 );
	}
}
