<?php
/**
 * Unit tests for the sodium-backed crypto helper.
 *
 * @since  1.7.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers ANPA_Socios_Crypto encrypt/decrypt + IBAN masking.
 */
final class Test_ANPA_Socios_Crypto extends TestCase {

	private function key(): string {
		return str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public function test_round_trip(): void {
		$key = $this->key();
		$enc = ANPA_Socios_Crypto::encrypt( 'ES9121000418450200051332', $key );
		$this->assertIsArray( $enc );
		$this->assertArrayHasKey( 'cipher', $enc );
		$this->assertArrayHasKey( 'nonce', $enc );
		$this->assertSame(
			'ES9121000418450200051332',
			ANPA_Socios_Crypto::decrypt( $enc['cipher'], $enc['nonce'], $key )
		);
	}

	public function test_each_encrypt_uses_fresh_nonce(): void {
		$key = $this->key();
		$a   = ANPA_Socios_Crypto::encrypt( 'same', $key );
		$b   = ANPA_Socios_Crypto::encrypt( 'same', $key );
		$this->assertNotSame( $a['nonce'], $b['nonce'] );
		$this->assertNotSame( $a['cipher'], $b['cipher'] );
	}

	public function test_decrypt_wrong_key_returns_null(): void {
		$enc = ANPA_Socios_Crypto::encrypt( 'secret', $this->key() );
		$this->assertNull(
			ANPA_Socios_Crypto::decrypt( $enc['cipher'], $enc['nonce'], str_repeat( 'x', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) )
		);
	}

	public function test_decrypt_tampered_cipher_returns_null(): void {
		$key = $this->key();
		$enc = ANPA_Socios_Crypto::encrypt( 'secret', $key );
		$raw = base64_decode( $enc['cipher'], true );
		$raw[0] = ( "\x00" === $raw[0] ) ? "\x01" : "\x00";
		$this->assertNull( ANPA_Socios_Crypto::decrypt( base64_encode( $raw ), $enc['nonce'], $key ) );
	}

	public function test_decrypt_bad_base64_returns_null(): void {
		$this->assertNull( ANPA_Socios_Crypto::decrypt( '!!!notbase64!!!', '###', $this->key() ) );
	}

	public function test_invalid_key_length_returns_null(): void {
		$this->assertNull( ANPA_Socios_Crypto::encrypt( 'x', 'tooshort' ) );
	}

	public function test_iban_last4_and_mask(): void {
		$this->assertSame( '1332', ANPA_Socios_Crypto::iban_last4( 'ES91 2100 0418 4502 0005 1332' ) );
		$this->assertSame( '**** **** **** 1332', ANPA_Socios_Crypto::mask_iban( 'ES9121000418450200051332' ) );
	}

	// ── fase6: sealed boxes + passphrase key-wrap ──────────────────────

	public function test_seal_unseal_round_trip(): void {
		$kp = ANPA_Socios_Crypto::generate_keypair();
		$this->assertArrayHasKey( 'public', $kp );
		$this->assertArrayHasKey( 'secret', $kp );
		$sealed = ANPA_Socios_Crypto::seal( 'ES9121000418450200051332', $kp['public'] );
		$this->assertIsString( $sealed );
		$this->assertSame(
			'ES9121000418450200051332',
			ANPA_Socios_Crypto::unseal( $sealed, $kp['public'], $kp['secret'] )
		);
	}

	public function test_seal_only_needs_public_key(): void {
		$kp = ANPA_Socios_Crypto::generate_keypair();
		// Sealing with just the public key works (no secret available at alta).
		$this->assertIsString( ANPA_Socios_Crypto::seal( 'secret-iban', $kp['public'] ) );
	}

	public function test_unseal_wrong_keypair_returns_null(): void {
		$kp  = ANPA_Socios_Crypto::generate_keypair();
		$kp2 = ANPA_Socios_Crypto::generate_keypair();
		$sealed = ANPA_Socios_Crypto::seal( 'secret', $kp['public'] );
		$this->assertNull( ANPA_Socios_Crypto::unseal( $sealed, $kp2['public'], $kp2['secret'] ) );
	}

	public function test_unseal_tampered_returns_null(): void {
		$kp = ANPA_Socios_Crypto::generate_keypair();
		$sealed = ANPA_Socios_Crypto::seal( 'secret', $kp['public'] );
		$raw = base64_decode( $sealed, true );
		$raw[ strlen( $raw ) - 1 ] = ( "\x00" === $raw[ strlen( $raw ) - 1 ] ) ? "\x01" : "\x00";
		$this->assertNull( ANPA_Socios_Crypto::unseal( base64_encode( $raw ), $kp['public'], $kp['secret'] ) );
	}

	public function test_wrap_unwrap_secret_round_trip(): void {
		$kp = ANPA_Socios_Crypto::generate_keypair();
		$wrapped = ANPA_Socios_Crypto::wrap_secret( $kp['secret'], 'correct horse battery staple' );
		$this->assertIsArray( $wrapped );
		$this->assertSame(
			$kp['secret'],
			ANPA_Socios_Crypto::unwrap_secret( $wrapped['blob'], $wrapped['salt'], $wrapped['nonce'], 'correct horse battery staple' )
		);
	}

	public function test_unwrap_wrong_passphrase_returns_null(): void {
		$kp = ANPA_Socios_Crypto::generate_keypair();
		$wrapped = ANPA_Socios_Crypto::wrap_secret( $kp['secret'], 'right-pass' );
		$this->assertNull(
			ANPA_Socios_Crypto::unwrap_secret( $wrapped['blob'], $wrapped['salt'], $wrapped['nonce'], 'wrong-pass' )
		);
	}

	public function test_full_flow_wrap_then_unseal(): void {
		// Realistic flow: seal with public key at alta; later unwrap secret with
		// passphrase and unseal.
		$kp      = ANPA_Socios_Crypto::generate_keypair();
		$wrapped = ANPA_Socios_Crypto::wrap_secret( $kp['secret'], 'admin-pass' );
		$sealed  = ANPA_Socios_Crypto::seal( 'ES9121000418450200051332', $kp['public'] );
		// later, admin supplies passphrase:
		$secret  = ANPA_Socios_Crypto::unwrap_secret( $wrapped['blob'], $wrapped['salt'], $wrapped['nonce'], 'admin-pass' );
		$this->assertSame(
			'ES9121000418450200051332',
			ANPA_Socios_Crypto::unseal( $sealed, $kp['public'], $secret )
		);
	}
}
