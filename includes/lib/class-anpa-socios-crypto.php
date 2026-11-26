<?php
/**
 * Authenticated encryption helpers for sensitive data at rest.
 *
 * Uses libsodium's crypto_secretbox (XSalsa20-Poly1305, AEAD). The
 * encrypt/decrypt/masking core is pure (the 32-byte key is passed in,
 * so it is unit-testable without WordPress). Only key() touches the
 * WordPress boundary (wp-config constant or wp_salt fallback).
 *
 * Used by fase5 PR-D to store IBAN and titular NIF encrypted in
 * wp_anpa_domiciliacions, decrypted on demand only for the master.
 *
 * @since  1.7.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * sodium-backed encrypt/decrypt + IBAN masking.
 *
 * @since 1.7.0
 */
final class ANPA_Socios_Crypto {

	/**
	 * Encrypts plaintext with the given 32-byte key.
	 *
	 * @since  1.7.0
	 * @param  string $plaintext Data to encrypt.
	 * @param  string $key       Raw 32-byte key.
	 * @return array{cipher:string,nonce:string}|null Base64 cipher+nonce, or null on failure.
	 */
	public static function encrypt( string $plaintext, string $key ): ?array {
		if ( ! self::valid_key( $key ) ) {
			return null;
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}

		return array(
			'cipher' => base64_encode( $cipher ),
			'nonce'  => base64_encode( $nonce ),
		);
	}

	/**
	 * Decrypts base64 cipher+nonce with the given 32-byte key.
	 *
	 * Returns null on any failure (bad key, tampered ciphertext, malformed
	 * input) and never throws or leaks ciphertext.
	 *
	 * @since  1.7.0
	 * @param  string $cipher_b64 Base64 ciphertext.
	 * @param  string $nonce_b64  Base64 nonce.
	 * @param  string $key        Raw 32-byte key.
	 * @return string|null Plaintext, or null on failure.
	 */
	public static function decrypt( string $cipher_b64, string $nonce_b64, string $key ): ?string {
		if ( ! self::valid_key( $key ) ) {
			return null;
		}
		$cipher = base64_decode( $cipher_b64, true );
		$nonce  = base64_decode( $nonce_b64, true );
		if ( false === $cipher || false === $nonce
			|| strlen( $nonce ) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}

		return ( false === $plain ) ? null : $plain;
	}

	/**
	 * Returns the last 4 characters of a normalized IBAN.
	 *
	 * @since  1.7.0
	 * @param  string $iban Raw IBAN.
	 * @return string
	 */
	public static function iban_last4( string $iban ): string {
		$clean = strtoupper( (string) preg_replace( '/\s+/', '', $iban ) );

		return strlen( $clean ) >= 4 ? substr( $clean, -4 ) : $clean;
	}

	/**
	 * Returns a masked IBAN for admin lists (only the last 4 shown).
	 *
	 * @since  1.7.0
	 * @param  string $iban Raw IBAN.
	 * @return string
	 */
	public static function mask_iban( string $iban ): string {
		return '**** **** **** ' . self::iban_last4( $iban );
	}

	// ─────────────────────────────────────────────────────────────────
	// fase6: asymmetric sealed boxes (server can write, only key-holder reads)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Generates an X25519 keypair for sealed-box banking encryption.
	 *
	 * The public key is stored on the server (encrypts at alta); the secret
	 * key is NEVER stored in clear — it is wrapped under an admin passphrase
	 * (see wrap_secret) and shown once for offline escrow.
	 *
	 * @since  1.8.0
	 * @return array{public:string,secret:string} Base64 keypair, or empty on failure.
	 */
	public static function generate_keypair(): array {
		try {
			$kp = sodium_crypto_box_keypair();
			return array(
				'public' => base64_encode( sodium_crypto_box_publickey( $kp ) ),
				'secret' => base64_encode( sodium_crypto_box_secretkey( $kp ) ),
			);
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Seals plaintext to the given public key (anonymous public-key encryption).
	 *
	 * No admin/secret is needed to seal; only the matching secret key can open it.
	 *
	 * @since  1.8.0
	 * @param  string $plaintext      Data to seal.
	 * @param  string $public_key_b64 Base64 X25519 public key.
	 * @return string|null Base64 sealed ciphertext, or null on failure.
	 */
	public static function seal( string $plaintext, string $public_key_b64 ): ?string {
		$pk = base64_decode( $public_key_b64, true );
		if ( false === $pk || strlen( $pk ) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ) {
			return null;
		}
		try {
			$cipher = sodium_crypto_box_seal( $plaintext, $pk );
		} catch ( \Throwable $e ) {
			return null;
		}

		return base64_encode( $cipher );
	}

	/**
	 * Opens a sealed ciphertext with the keypair (public + secret).
	 *
	 * Returns null on any failure (bad key, tampered ciphertext, malformed
	 * input); never throws or leaks ciphertext.
	 *
	 * @since  1.8.0
	 * @param  string $cipher_b64     Base64 sealed ciphertext.
	 * @param  string $public_key_b64 Base64 X25519 public key.
	 * @param  string $secret_key_b64 Base64 X25519 secret key.
	 * @return string|null Plaintext, or null on failure.
	 */
	public static function unseal( string $cipher_b64, string $public_key_b64, string $secret_key_b64 ): ?string {
		$cipher = base64_decode( $cipher_b64, true );
		$pk     = base64_decode( $public_key_b64, true );
		$sk     = base64_decode( $secret_key_b64, true );
		if ( false === $cipher || false === $pk || false === $sk
			|| strlen( $pk ) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
			|| strlen( $sk ) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES ) {
			return null;
		}
		try {
			$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey( $sk, $pk );
			$plain   = sodium_crypto_box_seal_open( $cipher, $keypair );
		} catch ( \Throwable $e ) {
			return null;
		}

		return ( false === $plain ) ? null : $plain;
	}

	/**
	 * Wraps (encrypts) the secret key under a passphrase for at-rest storage.
	 *
	 * Uses Argon2id (crypto_pwhash) to derive a key from the passphrase and a
	 * random salt, then secretbox-encrypts the base64 secret key. The server
	 * stores only the wrapped blob; it cannot unwrap without the passphrase.
	 *
	 * @since  1.8.0
	 * @param  string $secret_key_b64 Base64 secret key to protect.
	 * @param  string $passphrase     Admin passphrase.
	 * @return array{blob:string,salt:string,nonce:string}|null
	 */
	public static function wrap_secret( string $secret_key_b64, string $passphrase ): ?array {
		if ( '' === $passphrase ) {
			return null;
		}
		try {
			$salt  = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key   = self::derive_key( $passphrase, $salt );
			$blob  = sodium_crypto_secretbox( $secret_key_b64, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}

		return array(
			'blob'  => base64_encode( $blob ),
			'salt'  => base64_encode( $salt ),
			'nonce' => base64_encode( $nonce ),
		);
	}

	/**
	 * 1.102.0: wraps the SAME secret key under a new passphrase (passphrase
	 * change). The key pair does not change, so every sealed IBAN stays readable
	 * and nothing has to be re-encrypted. The new wrap is opened again before it
	 * is returned, so a wrap that cannot be read back is never stored.
	 *
	 * @since  1.102.0
	 * @param  array{blob:string,salt:string,nonce:string} $wrapped Current wrapped secret.
	 * @param  string                                      $vella   Current passphrase.
	 * @param  string                                      $nova    New passphrase.
	 * @return array{blob:string,salt:string,nonce:string}|null Null on a wrong current passphrase or any failure.
	 */
	public static function rewrap_secret( array $wrapped, string $vella, string $nova ): ?array {
		if ( '' === $nova || ! isset( $wrapped['blob'], $wrapped['salt'], $wrapped['nonce'] ) ) {
			return null;
		}
		$secret = self::unwrap_secret( (string) $wrapped['blob'], (string) $wrapped['salt'], (string) $wrapped['nonce'], $vella );
		if ( null === $secret ) {
			return null;
		}
		$novo = self::wrap_secret( $secret, $nova );
		if ( null === $novo ) {
			return null;
		}
		$comprobado = self::unwrap_secret( $novo['blob'], $novo['salt'], $novo['nonce'], $nova );

		return ( null !== $comprobado && hash_equals( $secret, $comprobado ) ) ? $novo : null;
	}

	/**
	 * Unwraps the secret key with the passphrase.
	 *
	 * Returns null on wrong passphrase, tampering, or malformed input.
	 *
	 * @since  1.8.0
	 * @param  string $blob_b64   Base64 wrapped blob.
	 * @param  string $salt_b64   Base64 pwhash salt.
	 * @param  string $nonce_b64  Base64 secretbox nonce.
	 * @param  string $passphrase Admin passphrase.
	 * @return string|null Base64 secret key, or null on failure.
	 */
	public static function unwrap_secret( string $blob_b64, string $salt_b64, string $nonce_b64, string $passphrase ): ?string {
		$blob  = base64_decode( $blob_b64, true );
		$salt  = base64_decode( $salt_b64, true );
		$nonce = base64_decode( $nonce_b64, true );
		if ( false === $blob || false === $salt || false === $nonce
			|| strlen( $salt ) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
			|| strlen( $nonce ) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$key    = self::derive_key( $passphrase, $salt );
			$secret = sodium_crypto_secretbox_open( $blob, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}

		return ( false === $secret ) ? null : $secret;
	}

	/**
	 * Derives a 32-byte key from a passphrase + salt via Argon2id.
	 *
	 * @since  1.8.0
	 * @param  string $passphrase Admin passphrase.
	 * @param  string $salt       Raw pwhash salt (SODIUM_CRYPTO_PWHASH_SALTBYTES).
	 * @return string Raw 32-byte key.
	 */
	private static function derive_key( string $passphrase, string $salt ): string {
		return sodium_crypto_pwhash(
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			$passphrase,
			$salt,
			SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_ALG_DEFAULT
		);
	}

	/**
	 * Resolves the 32-byte data key (WordPress boundary).
	 *
	 * Prefers the ANPA_SOCIOS_DATA_KEY constant (base64-44, hex-64, or raw
	 * 32 bytes). Falls back to a key derived from wp_salt so the feature
	 * works before the constant is configured (operators SHOULD set the
	 * constant in wp-config.php for key rotation independence).
	 *
	 * @since  1.7.0
	 * @return string Raw 32-byte key.
	 */
	public static function key(): string {
		if ( defined( 'ANPA_SOCIOS_DATA_KEY' ) ) {
			$raw     = (string) constant( 'ANPA_SOCIOS_DATA_KEY' );
			$decoded = base64_decode( $raw, true );
			if ( false !== $decoded && self::valid_key( $decoded ) ) {
				return $decoded;
			}
			if ( 64 === strlen( $raw ) && ctype_xdigit( $raw ) ) {
				return (string) hex2bin( $raw );
			}
			if ( self::valid_key( $raw ) ) {
				return $raw;
			}
		}

		// Fallback: derive a stable 32-byte key from wp_salt.
		return substr(
			hash( 'sha256', wp_salt( 'secure_auth' ) . '|anpa_socios_data_v1', true ),
			0,
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	/**
	 * Returns whether the wp_salt fallback key is in use.
	 *
	 * True when ANPA_SOCIOS_DATA_KEY is undefined or does not resolve to a
	 * valid 32-byte key. Used to surface an admin warning, since changing
	 * WordPress salts would then make encrypted data unreadable.
	 *
	 * @since  1.7.0
	 * @return bool
	 */
	public static function using_fallback_key(): bool {
		if ( ! defined( 'ANPA_SOCIOS_DATA_KEY' ) ) {
			return true;
		}
		$raw     = (string) constant( 'ANPA_SOCIOS_DATA_KEY' );
		$decoded = base64_decode( $raw, true );
		if ( false !== $decoded && self::valid_key( $decoded ) ) {
			return false;
		}
		if ( 64 === strlen( $raw ) && ctype_xdigit( $raw ) ) {
			return false;
		}

		return ! self::valid_key( $raw );
	}

	/**
	 * Validates that a key is exactly the required length.
	 *
	 * @since  1.7.0
	 * @param  string $key Candidate key.
	 * @return bool
	 */
	private static function valid_key( string $key ): bool {
		return strlen( $key ) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
	}

	/**
	 * Number of words in a suggested passphrase.
	 *
	 * @since 1.102.0
	 * @var int
	 */
	const PASSPHRASE_WORD_COUNT = 6;

	/**
	 * Word list for suggested passphrases: exactly 128 distinct, short, common
	 * Galician words, lowercase ASCII only (no accents or ñ, so the phrase is
	 * easy to type on any keyboard). 128 = 2^7, so each word adds 7 bits.
	 *
	 * @since 1.102.0
	 * @var string[]
	 */
	const PASSPHRASE_WORDS = array(
		'ceu', 'mar', 'sol', 'lua', 'nube', 'monte', 'rio', 'ponte',
		'casa', 'horta', 'rosa', 'pino', 'carballo', 'pomba', 'gaivota', 'paxaro',
		'grilo', 'pera', 'uvas', 'froita', 'millo', 'auga', 'lume', 'vento',
		'neve', 'verde', 'azul', 'branco', 'negro', 'roxo', 'danza', 'canto',
		'risa', 'amor', 'paz', 'ben', 'luz', 'vida', 'praia', 'area',
		'onda', 'porto', 'barco', 'rede', 'peixe', 'polbo', 'xouba', 'cunca',
		'pan', 'leite', 'queixo', 'mel', 'noz', 'souto', 'bosque', 'folla',
		'flor', 'herba', 'campo', 'eira', 'lobo', 'raposo', 'cabalo', 'vaca',
		'ovella', 'galo', 'pita', 'porco', 'coello', 'cervo', 'corvo', 'moucho',
		'merlo', 'lagarto', 'sapo', 'troita', 'cebola', 'allo', 'nabo', 'berza',
		'fabas', 'trigo', 'centeo', 'regato', 'fonte', 'pedra', 'seixo', 'illa',
		'cabo', 'faro', 'val', 'outeiro', 'vila', 'aldea', 'lar', 'porta',
		'xanela', 'cadeira', 'mesa', 'libro', 'papel', 'lapis', 'escola', 'festa',
		'gaita', 'tambor', 'baile', 'cantiga', 'conto', 'xogo', 'amigo', 'avoa',
		'neno', 'nena', 'lingua', 'verba', 'chuvia', 'orballo', 'xeada', 'inverno',
		'outono', 'abril', 'maio', 'xullo', 'agosto', 'estrela', 'cometa', 'serra',
	);

	/**
	 * Generates a random 6-word passphrase in Galician.
	 *
	 * Used by the setup wizard to suggest a banking key passphrase. Six words
	 * drawn uniformly (with repetition) from the 128-word list give
	 * 6 × 7 = 42 bits of entropy, enough to resist offline brute force of the
	 * wrapped key given the Argon2id cost.
	 *
	 * @since  1.32.0
	 * @since  1.102.0 Six words from a 128-word ASCII list (was 5 from 45).
	 * @return string Hyphen-separated words.
	 */
	public static function generate_passphrase(): string {
		// Use a CSPRNG (random_int) rather than a non-cryptographic PRNG. This is
		// only a suggested passphrase (editable by the admin), but a suggestion
		// should not be predictable. Words may repeat; that keeps the selection
		// uniform.
		$words    = self::PASSPHRASE_WORDS;
		$count    = count( $words );
		$selected = array();
		for ( $i = 0; $i < self::PASSPHRASE_WORD_COUNT; $i++ ) {
			$selected[] = $words[ random_int( 0, $count - 1 ) ];
		}

		return implode( '-', $selected );
	}
}
