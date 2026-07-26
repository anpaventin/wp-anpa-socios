<?php
/**
 * GOLDEN ORACLE for the live transactional emails (fase36).
 *
 * Captured from the CURRENT hardcoded implementation, BEFORE any template refactoring touches
 * it. From here on these files are the oracle: fase36 must keep producing byte-identical
 * subjects, headers and bodies, so a single changed character in a family-facing email fails CI.
 *
 * Files are named `<event_key>.<variant>.txt` and the variants are DECLARED in
 * `ANPA_Socios_Golden_Manifest`, not inferred from the directory listing. Two of these emails
 * branch on whether a members'-area URL was supplied, with different wording each way, so one
 * capture per event would leave the other path free to change silently.
 *
 * Two modes, one file:
 *   - `ANPA_GOLDEN_CAPTURE=1` writes `tests/golden/*.txt` (run once, deliberately);
 *   - otherwise it asserts literal equality against them.
 *
 * Nothing is ever sent: `pre_wp_mail` short-circuits the transport and captures what would have
 * gone out. Every input is fictitious.
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Golden extends TestCase {

	/** Fixed fictitious configuration, so the captured output is deterministic. */
	private const ASSOCIATION = 'ANPA Exemplo';
	private const MASTER      = 'xunta@example.org';
	private const CONTACT     = 'contacto@example.org';
	private const SIGNATURE   = "A Xunta Directiva\nANPA Exemplo";
	private const AREA_URL    = 'https://example.org/area-socios/';

	/** @var array<int,array<string,mixed>> Captured wp_mail() calls. */
	private array $sent = array();

	/** @var callable|null Installed `pre_wp_mail` capture callback. */
	private $transport = null;

	protected function setUp(): void {
		if ( ! self::has_wordpress() ) {
			return; // Each capture test skips itself; the static guards still run.
		}

		update_option( ANPA_Socios_Config::OPTION_ASSOCIATION, self::ASSOCIATION );
		update_option( ANPA_Socios_Config::OPTION, self::MASTER );
		update_option( ANPA_Socios_Config::OPTION_CONTACT_EMAIL, self::CONTACT );
		update_option( ANPA_Socios_Config::OPTION_SIGNATURE, self::SIGNATURE );

		$this->sent      = array();
		$this->transport = function ( $short_circuit, $atts ) {
			unset( $short_circuit );
			$this->sent[] = $atts;
			return true; // Captured, never handed to a transport.
		};
		add_filter( 'pre_wp_mail', $this->transport, 10, 2 );
	}

	protected function tearDown(): void {
		if ( null !== $this->transport ) {
			remove_filter( 'pre_wp_mail', $this->transport, 10 );
			$this->transport = null;
		}
	}

	/** @return bool Whether a real WordPress + integration DB is loaded. */
	private static function has_wordpress(): bool {
		return defined( 'ANPA_SOCIOS_IT_DB' ) && function_exists( 'update_option' );
	}

	private function require_wordpress(): void {
		if ( ! self::has_wordpress() ) {
			$this->markTestSkipped( 'Golden capture needs WordPress (run phpunit-integration.xml).' );
		}
	}

	/** @return string Directory holding the golden files. */
	private function golden_dir(): string {
		return __DIR__ . '/golden';
	}

	/** @return bool Whether this run writes the files instead of asserting. */
	private function capturing(): bool {
		return '1' === (string) getenv( 'ANPA_GOLDEN_CAPTURE' );
	}

	/**
	 * Records or verifies one captured email.
	 *
	 * @param string $event_key Registered event key.
	 * @param string $variant   Declared variant name.
	 * @param int    $index     Which captured call to inspect.
	 */
	private function assert_golden( string $event_key, string $variant, int $index = 0 ): void {
		$this->assertContains(
			$variant,
			ANPA_Socios_Golden_Manifest::variants( $event_key ),
			"variant '{$variant}' is not declared for '{$event_key}'"
		);

		$this->assertArrayHasKey( $index, $this->sent, "no email captured for {$event_key}.{$variant}" );
		$mail = $this->sent[ $index ];

		$to      = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
		$headers = is_array( $mail['headers'] ) ? $mail['headers'] : array( (string) $mail['headers'] );
		$headers = array_values( array_filter( array_map( 'strval', $headers ) ) );
		sort( $headers ); // Header order is not part of the contract; content is.

		$actual = implode(
			"\n",
			array(
				'TO: ' . $to,
				'SUBJECT: ' . (string) $mail['subject'],
				'HEADERS: ' . implode( ' | ', $headers ),
				'---BODY---',
				(string) $mail['message'],
			)
		);

		$path = $this->golden_dir() . '/' . ANPA_Socios_Golden_Manifest::stem( $event_key, $variant ) . '.txt';

		if ( $this->capturing() ) {
			if ( ! is_dir( $this->golden_dir() ) ) {
				mkdir( $this->golden_dir(), 0755, true );
			}
			file_put_contents( $path, $actual );
			$this->assertFileExists( $path );
			return;
		}

		$this->assertFileExists( $path, "golden missing for {$event_key}.{$variant}; capture with ANPA_GOLDEN_CAPTURE=1" );
		$this->assertSame(
			(string) file_get_contents( $path ),
			$actual,
			"the wording of {$event_key}.{$variant} changed; families would receive something different"
		);
	}

	// ── The live emails, with fixed fictitious input ────────────────────

	public function test_golden_auth_access_code(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_codigo( 'nai@example.com', '123456', 'verificacion' );
		$this->assert_golden( 'auth_access_code', ANPA_Socios_Golden_Manifest::VARIANT_DEFAULT );
	}

	public function test_golden_auth_access_code_signup(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_codigo( 'nai@example.com', '123456', 'alta' );
		$this->assert_golden( 'auth_access_code_signup', ANPA_Socios_Golden_Manifest::VARIANT_DEFAULT );
	}

	public function test_golden_member_application_admin_pending(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_pendente_aprobacion( 'nai@example.com', 'Uxía Exemplo Ficticio' );
		$this->assert_golden( 'member_application_admin_pending', ANPA_Socios_Golden_Manifest::VARIANT_DEFAULT );
	}

	public function test_golden_member_application_approved_with_url(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aprobacion( 'nai@example.com', self::AREA_URL );
		$this->assert_golden( 'member_application_approved', ANPA_Socios_Golden_Manifest::VARIANT_WITH_URL );
	}

	public function test_golden_member_application_approved_without_url(): void {
		// The branch nobody had captured. Its wording differs from the with-URL one, so leaving
		// it unpinned would let fase36 change it without a single test failing.
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aprobacion( 'nai@example.com' );
		$this->assert_golden( 'member_application_approved', ANPA_Socios_Golden_Manifest::VARIANT_WITHOUT_URL );
	}

	public function test_golden_member_application_completed_with_url(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_benvida_alta( 'nai@example.com', self::AREA_URL );
		$this->assert_golden( 'member_application_completed', ANPA_Socios_Golden_Manifest::VARIANT_WITH_URL );
	}

	public function test_golden_member_application_completed_without_url(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_benvida_alta( 'nai@example.com' );
		$this->assert_golden( 'member_application_completed', ANPA_Socios_Golden_Manifest::VARIANT_WITHOUT_URL );
	}

	public function test_golden_member_application_changes_required(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_rexeitamento( 'nai@example.com' );
		$this->assert_golden( 'member_application_changes_required', ANPA_Socios_Golden_Manifest::VARIANT_DEFAULT );
	}

	public function test_golden_member_cancellation_admin_notice(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_baixa_socio( 'nai@example.com', 'Uxía', 'Exemplo Ficticio' );
		$this->assert_golden( 'member_cancellation_admin_notice', ANPA_Socios_Golden_Manifest::VARIANT_DEFAULT );
	}

	public function test_golden_member_reactivation_admin_notice(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_reactivacion( 'nai@example.com' );
		$this->assert_golden( 'member_reactivation_admin_notice', ANPA_Socios_Golden_Manifest::VARIANT_DEFAULT );
	}

	public function test_golden_activity_cancellation_admin_notice(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_baixa_extraescolar( 'nai@example.com', 'Antía Exemplo', 'Robótica' );
		$this->assert_golden( 'activity_cancellation_admin_notice', ANPA_Socios_Golden_Manifest::VARIANT_DEFAULT );
	}

	public function test_golden_waitlist_place_offer(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_oferta_extraescolar( 'nai@example.com', 'Robótica', 3 );
		$this->assert_golden( 'waitlist_place_offer', ANPA_Socios_Golden_Manifest::VARIANT_DEFAULT );
	}

	// ── Guards on the oracle itself (run in the unit suite too) ─────────

	/**
	 * A new family-facing email must not slip in without an oracle entry.
	 */
	public function test_the_oracle_covers_every_live_send_method(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php' );
		preg_match_all( '/public static function (enviar_[a-z_]+)\s*\(/', $src, $matches );

		$methods = array_unique( $matches[1] );
		$this->assertNotEmpty( $methods, 'no live send methods found; the regex or the class moved' );

		$mine = (string) file_get_contents( __FILE__ );
		foreach ( $methods as $method ) {
			$this->assertStringContainsString(
				'ANPA_Socios_Email::' . $method . '(',
				$mine,
				"live send method {$method} has no golden coverage"
			);
		}
	}

	/**
	 * The manifest and the registry must agree about which events are live.
	 */
	public function test_every_live_event_declares_at_least_one_variant(): void {
		$live = ANPA_Socios_Email_Template_Events::set()->live_keys();
		sort( $live, SORT_STRING );

		$declared = ANPA_Socios_Golden_Manifest::events();
		sort( $declared, SORT_STRING );

		$this->assertSame( $live, $declared, 'the manifest and the live registry describe different events' );

		foreach ( $declared as $event_key ) {
			$variants = ANPA_Socios_Golden_Manifest::variants( $event_key );
			$this->assertNotSame( array(), $variants, "{$event_key} declares no variant" );
			$this->assertSame( array_unique( $variants ), $variants, "{$event_key} repeats a variant name" );
		}
	}

	/**
	 * Every declared variant is captured, and no capture is undeclared.
	 */
	public function test_the_declared_variants_and_the_captured_files_match_exactly(): void {
		$declared = ANPA_Socios_Golden_Manifest::stems();

		$captured = array_map(
			static function ( $file ): string {
				return basename( (string) $file, '.txt' );
			},
			(array) glob( $this->golden_dir() . '/*.txt' )
		);
		sort( $captured, SORT_STRING );

		$this->assertSame(
			$declared,
			$captured,
			'declared variants and captured files must match in both directions'
		);
	}

	/**
	 * The oracle is read as a diff far more often than as data, so the listing must be sorted.
	 */
	public function test_the_golden_files_are_listed_in_a_stable_sorted_order(): void {
		$files = (array) glob( $this->golden_dir() . '/*.txt' );
		if ( array() === $files ) {
			$this->markTestSkipped( 'golden files not captured yet (ANPA_GOLDEN_CAPTURE=1).' );
		}

		$stems = array_map(
			static function ( $file ): string {
				return basename( (string) $file, '.txt' );
			},
			$files
		);

		$sorted = $stems;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $sorted, $stems, 'the golden listing must be sorted so diffs stay readable' );
		$this->assertSame( array_unique( $stems ), $stems, 'two golden files cannot share a stem' );
	}

	/**
	 * The two branching events must not have produced identical captures: if they had, the
	 * branch would be decorative and the manifest would be claiming a distinction that does not
	 * exist.
	 */
	public function test_the_two_members_area_branches_really_differ(): void {
		foreach ( ANPA_Socios_Email_Template_Context::events_requiring_area_link() as $event_key ) {
			$with    = $this->golden_dir() . '/' . $event_key . '.with-url.txt';
			$without = $this->golden_dir() . '/' . $event_key . '.without-url.txt';

			if ( ! is_readable( $with ) || ! is_readable( $without ) ) {
				$this->markTestSkipped( 'branch variants not captured yet.' );
			}

			$this->assertNotSame(
				(string) file_get_contents( $with ),
				(string) file_get_contents( $without ),
				"{$event_key}: the two branches produce identical output, so the branch is not real"
			);
		}
	}

	/**
	 * The golden files live in a public repository.
	 */
	public function test_no_golden_file_contains_real_personal_data(): void {
		$files = (array) glob( $this->golden_dir() . '/*.txt' );

		if ( array() === $files ) {
			$this->markTestSkipped( 'golden files not captured yet (ANPA_GOLDEN_CAPTURE=1).' );
		}

		foreach ( $files as $file ) {
			$content = strtolower( (string) file_get_contents( (string) $file ) );
			$this->assertMatchesRegularExpression(
				'/example\.(com|org)/',
				$content,
				'golden files must use fictitious addresses only'
			);
			foreach ( array( 'asbranas', 'as brañas', 'ventin', 'ventín', 'anpaventin' ) as $forbidden ) {
				$this->assertStringNotContainsString(
					$forbidden,
					$content,
					"golden file {$file} leaks a real association identifier"
				);
			}
		}
	}
}
