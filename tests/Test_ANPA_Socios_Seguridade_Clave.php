<?php
/**
 * 1.102.0 security hardening: stronger suggested banking passphrase, one
 * shared failed-passphrase lockout, and a pinned self-update download source.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Minimal transient stubs backed by a global array (only if WordPress is absent).
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['anpa_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['anpa_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['anpa_test_transients'][ $key ] );
		return true;
	}
}

final class Test_ANPA_Socios_Seguridade_Clave extends TestCase {

	public static function setUpBeforeClass(): void {
		$root = dirname( __DIR__ );
		if ( ! class_exists( 'ANPA_Socios_Banking_Key' ) ) {
			require_once $root . '/includes/class-anpa-socios-banking-key.php';
		}
		if ( ! class_exists( 'ANPA_Socios_Updater' ) ) {
			require_once $root . '/includes/class-anpa-socios-updater.php';
		}
	}

	protected function setUp(): void {
		$GLOBALS['anpa_test_transients'] = array();
	}

	// ── Passphrase generator ──────────────────────────────────────

	public function test_word_list_has_128_unique_short_ascii_words(): void {
		$words = ANPA_Socios_Crypto::PASSPHRASE_WORDS;
		$this->assertCount( 128, $words );
		$this->assertCount( 128, array_unique( $words ) );
		foreach ( $words as $w ) {
			$this->assertMatchesRegularExpression( '/^[a-z]{3,8}$/', $w, "Bad word: {$w}" );
		}
	}

	public function test_generated_passphrase_is_six_listed_words(): void {
		$this->assertSame( 6, ANPA_Socios_Crypto::PASSPHRASE_WORD_COUNT );
		for ( $i = 0; $i < 20; $i++ ) {
			$parts = explode( '-', ANPA_Socios_Crypto::generate_passphrase() );
			$this->assertCount( 6, $parts );
			foreach ( $parts as $w ) {
				$this->assertContains( $w, ANPA_Socios_Crypto::PASSPHRASE_WORDS );
			}
		}
		// ~42 bits of entropy.
		$this->assertGreaterThanOrEqual( 42.0, 6 * log( count( ANPA_Socios_Crypto::PASSPHRASE_WORDS ), 2 ) );
	}

	// ── Shared lockout ────────────────────────────────────────────

	public function test_lockout_after_five_failures_and_reset_on_success(): void {
		$actor = 'Admin@Example.com';
		for ( $i = 0; $i < 4; $i++ ) {
			ANPA_Socios_Banking_Key::passphrase_failed( $actor );
			$this->assertFalse( ANPA_Socios_Banking_Key::passphrase_locked( $actor ) );
		}
		ANPA_Socios_Banking_Key::passphrase_failed( $actor );
		$this->assertTrue( ANPA_Socios_Banking_Key::passphrase_locked( $actor ) );
		// Same counter regardless of case/whitespace.
		$this->assertTrue( ANPA_Socios_Banking_Key::passphrase_locked( ' admin@example.com ' ) );
		// Other admins are not affected.
		$this->assertFalse( ANPA_Socios_Banking_Key::passphrase_locked( 'outra@example.com' ) );

		ANPA_Socios_Banking_Key::passphrase_succeeded( $actor );
		$this->assertFalse( ANPA_Socios_Banking_Key::passphrase_locked( $actor ) );
	}

	public function test_lock_message_matches_the_reports_handler_text(): void {
		$this->assertSame(
			'Demasiados intentos co contrasinal. Téntao de novo en 15 minutos.',
			ANPA_Socios_Banking_Key::passphrase_locked_message()
		);
		$this->assertSame( 5, ANPA_Socios_Banking_Key::PASSPHRASE_MAX_FAILS );
		$this->assertSame( 900, ANPA_Socios_Banking_Key::PASSPHRASE_LOCK_SECONDS );
	}

	public function test_every_passphrase_entry_point_uses_the_shared_lockout(): void {
		$root  = dirname( __DIR__ );
		$files = array(
			'includes/class-anpa-socios-admin-reports-handler.php',
			'includes/class-anpa-socios-admin-domiciliacion-handler.php',
			'includes/lib/class-anpa-socios-admin-auth.php',
		);
		foreach ( $files as $rel ) {
			$src = (string) file_get_contents( $root . '/' . $rel );
			$this->assertStringContainsString( 'ANPA_Socios_Banking_Key::passphrase_locked(', $src, $rel );
			$this->assertStringContainsString( 'ANPA_Socios_Banking_Key::passphrase_failed(', $src, $rel );
			$this->assertStringContainsString( 'ANPA_Socios_Banking_Key::passphrase_succeeded(', $src, $rel );
		}
		$settings = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-settings.php' );
		foreach ( array( 'function handle_backup', 'function handle_restore' ) as $fn ) {
			$start = strpos( $settings, $fn );
			$this->assertNotFalse( $start );
			$body = substr( $settings, $start, 2000 );
			$this->assertStringContainsString( 'passphrase_locked(', $body, $fn );
			$this->assertStringContainsString( 'passphrase_failed(', $body, $fn );
			$this->assertStringContainsString( 'passphrase_succeeded(', $body, $fn );
		}
	}

	public function test_permission_with_passphrase_ignores_the_query_string(): void {
		$src   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-admin-auth.php' );
		$start = strpos( $src, 'function permission_with_passphrase' );
		$this->assertNotFalse( $start );
		$end  = strpos( $src, 'function current_wp_email', $start );
		$body = substr( $src, $start, $end - $start );
		$this->assertStringNotContainsString( "get_param( 'passphrase' )", $body );
		$this->assertStringNotContainsString( 'get_query_params', $body );
		$this->assertStringContainsString( 'get_json_params()', $body );
		$this->assertStringContainsString( 'get_body_params()', $body );
	}

	// ── Update download pin ───────────────────────────────────────

	public function test_real_release_urls_are_allowed(): void {
		$this->assertTrue( ANPA_Socios_Updater::is_allowed_download_url(
			'https://github.com/anpaventin/wp-anpa-socios/releases/download/v1.101.1/wp-anpa-socios-1.101.1.zip'
		) );
		foreach ( array( 'details.json', 'details-prerelease.json' ) as $file ) {
			$json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/' . $file ), true );
			$this->assertTrue(
				ANPA_Socios_Updater::is_allowed_download_url( (string) ( $json['download_url'] ?? '' ) ),
				$file
			);
		}
	}

	/**
	 * @dataProvider rejected_urls
	 */
	public function test_foreign_or_tricky_urls_are_rejected( string $url ): void {
		$this->assertFalse( ANPA_Socios_Updater::is_allowed_download_url( $url ) );
	}

	public function rejected_urls(): array {
		return array(
			'empty'          => array( '' ),
			'prefix only'    => array( 'https://github.com/anpaventin/wp-anpa-socios/releases/download/' ),
			'http'           => array( 'http://github.com/anpaventin/wp-anpa-socios/releases/download/v1/x.zip' ),
			'other repo'     => array( 'https://github.com/evil/wp-anpa-socios/releases/download/v1/x.zip' ),
			'lookalike repo' => array( 'https://github.com/anpaventin/wp-anpa-socios-evil/releases/download/v1/x.zip' ),
			'other host'     => array( 'https://evil.example/anpaventin/wp-anpa-socios/releases/download/v1/x.zip' ),
			'userinfo'       => array( 'https://github.com@evil.example/anpaventin/wp-anpa-socios/releases/download/v1/x.zip' ),
			'traversal'      => array( 'https://github.com/anpaventin/wp-anpa-socios/releases/download/../../../evil/x.zip' ),
			'encoded'        => array( 'https://github.com/anpaventin/wp-anpa-socios/releases/download/%2e%2e/x.zip' ),
			'backslash'      => array( 'https://github.com/anpaventin/wp-anpa-socios/releases/download/v1\\x.zip' ),
			'query'          => array( 'https://github.com/anpaventin/wp-anpa-socios/releases/download/v1/x.zip?u=evil' ),
			'whitespace'     => array( 'https://github.com/anpaventin/wp-anpa-socios/releases/download/v1/x .zip' ),
			'raw main'       => array( 'https://raw.githubusercontent.com/anpaventin/wp-anpa-socios/main/x.zip' ),
		);
	}

	public function test_pin_filter_drops_bad_info_and_keeps_good_info(): void {
		$good               = new stdClass();
		$good->download_url = 'https://github.com/anpaventin/wp-anpa-socios/releases/download/v2.0.0/wp-anpa-socios-2.0.0.zip';
		$this->assertSame( $good, ANPA_Socios_Updater::pin_download_url( $good, array() ) );

		$bad               = new stdClass();
		$bad->download_url = 'https://evil.example/wp-anpa-socios.zip';
		$this->assertNull( ANPA_Socios_Updater::pin_download_url( $bad, array() ) );

		$missing = new stdClass();
		$this->assertNull( ANPA_Socios_Updater::pin_download_url( $missing, array() ) );

		$this->assertNull( ANPA_Socios_Updater::pin_download_url( null, array() ) );
	}

	public function test_pin_is_hooked_only_without_the_operator_override(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-updater.php' );
		$this->assertStringContainsString( "add_filter( 'puc_request_info_result-' . self::SLUG, array( __CLASS__, 'pin_download_url' ), 10, 2 );", $src );
		$this->assertStringContainsString( 'if ( ! $override ) {', $src );
		// The library really applies this filter with (info, httpResult).
		$lib = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/plugin-update-checker/Puc/v5p7/Plugin/UpdateChecker.php' );
		$this->assertStringContainsString( "apply_filters(\$this->getUniqueName('request_info_result'), \$pluginInfo, \$result)", $lib );
	}

	// ── Passphrase change (re-wrap) ───────────────────────────────

	public function test_rewrap_keeps_the_secret_and_moves_it_to_the_new_passphrase(): void {
		$kp      = ANPA_Socios_Crypto::generate_keypair();
		$secret  = (string) ( $kp['secret'] ?? $kp['secret_key'] ?? $kp['sk'] ?? array_values( $kp )[1] );
		$wrapped = ANPA_Socios_Crypto::wrap_secret( $secret, 'frase-vella-de-proba' );
		$this->assertIsArray( $wrapped );
		$novo = ANPA_Socios_Crypto::rewrap_secret( $wrapped, 'frase-vella-de-proba', 'frase-nova-de-proba' );
		$this->assertIsArray( $novo );
		$this->assertSame( $secret, ANPA_Socios_Crypto::unwrap_secret( $novo['blob'], $novo['salt'], $novo['nonce'], 'frase-nova-de-proba' ) );
		$this->assertNull( ANPA_Socios_Crypto::unwrap_secret( $novo['blob'], $novo['salt'], $novo['nonce'], 'frase-vella-de-proba' ), 'the old phrase no longer opens it' );
		$this->assertNull( ANPA_Socios_Crypto::rewrap_secret( $wrapped, 'frase-errada', 'frase-nova-de-proba' ), 'wrong current phrase' );
		$this->assertNull( ANPA_Socios_Crypto::rewrap_secret( $wrapped, 'frase-vella-de-proba', '' ), 'empty new phrase' );
	}

	public function test_new_passphrase_rules(): void {
		$this->assertSame( '', ANPA_Socios_Banking_Key::erro_frase_nova( 'vella-vella-vella', 'cinco-palabras-ao-chou-aqui', 'cinco-palabras-ao-chou-aqui', 12 ) );
		$this->assertSame( 'frase_distinta', ANPA_Socios_Banking_Key::erro_frase_nova( 'v', 'abcdefghijklm', 'abcdefghijklX', 12 ) );
		$this->assertSame( 'frase_espazos', ANPA_Socios_Banking_Key::erro_frase_nova( 'v', ' abcdefghijklm', ' abcdefghijklm', 12 ) );
		$this->assertSame( 'frase_curta', ANPA_Socios_Banking_Key::erro_frase_nova( 'v', 'curta', 'curta', 12 ) );
		$this->assertSame( 'frase_igual', ANPA_Socios_Banking_Key::erro_frase_nova( 'abcdefghijklm', 'abcdefghijklm', 'abcdefghijklm', 12 ) );
	}

	public function test_passphrase_change_only_rewrites_the_wrapped_secret(): void {
		$s    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-settings.php' );
		$from = (int) strpos( $s, 'public static function handle_cambiar_frase' );
		$body = substr( $s, $from, (int) strpos( $s, 'public static function handle_wipe' ) - $from );
		$this->assertStringContainsString( "self::guard( 'anpa_socios_cambiar_frase' );", $body );
		$this->assertStringContainsString( 'ANPA_Socios_Banking_Key::passphrase_locked( $actor )', $body );
		$this->assertStringContainsString( 'update_option( ANPA_Socios_Banking_Key::OPTION_SECKEY', $body );
		$this->assertStringNotContainsString( 'OPTION_PUBKEY', $body, 'the key pair is not replaced' );
		$this->assertStringContainsString( "'frase_cambiada' );", $body );
		$this->assertStringContainsString( "add_action( 'admin_post_anpa_socios_cambiar_frase'", $s );
	}
}
