<?php
/**
 * Unit tests for ANPA_Socios_Codigo_Generator.
 *
 * Pure PHP tests; no WordPress bootstrap.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Codigo_Generator extends TestCase {

	/**
	 * Generated codes are exactly six decimal digits.
	 */
	public function test_generate_returns_six_digit_code(): void {
		$code = ANPA_Socios_Codigo_Generator::generate();

		$this->assertMatchesRegularExpression( '/^[0-9]{6}$/', $code );
	}

	/**
	 * Hashes are compatible with PHP password_verify / bcrypt verification.
	 */
	public function test_hash_code_creates_verifiable_hash(): void {
		$code = '123456';
		$hash = ANPA_Socios_Codigo_Generator::hash_code( $code );

		$this->assertIsString( $hash );
		$this->assertTrue( password_verify( $code, $hash ) );
		$this->assertFalse( password_verify( '654321', $hash ) );
	}

	/**
	 * Expiry is exactly 15 minutes after the reference timestamp.
	 */
	public function test_expiry_returns_timestamp_plus_15_minutes(): void {
		$now = 1_700_000_000;

		$this->assertSame(
			$now + ( 15 * 60 ),
			ANPA_Socios_Codigo_Generator::expiry( $now )
		);
	}
}
