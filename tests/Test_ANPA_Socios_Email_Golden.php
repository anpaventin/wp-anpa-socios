<?php
/**
 * GOLDEN ORACLE for the live transactional emails (fase36, before 36s1b/36s1c).
 *
 * Captured from the CURRENT hardcoded implementation, BEFORE any template
 * refactoring touches it. From here on these files are the oracle: fase36 must
 * keep producing byte-identical subjects, headers and bodies, so a single
 * changed character in a family-facing email fails CI.
 *
 * This is the whole point of capturing now. Once `ANPA_Socios_Email` delegates
 * to editable templates the original output is gone, and "the wording did not
 * change" becomes an unverifiable claim.
 *
 * Two modes, one file:
 *   - `ANPA_GOLDEN_CAPTURE=1` writes `tests/golden/*.txt` (run once, deliberately);
 *   - otherwise it asserts literal equality against them.
 *
 * Nothing is ever sent: `pre_wp_mail` short-circuits the transport and captures
 * what would have gone out. Every input is fictitious.
 *
 * Requires WordPress (option storage, filters, `admin_url`), so the ten capture
 * tests self-skip outside the integration harness. The two guards at the bottom
 * are pure file/source checks and DO run in the unit suite.
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

	/** @var array<int,array<string,mixed>> Captured wp_mail() calls. */
	private array $sent = array();

	/** @var callable|null Installed `pre_wp_mail` capture callback. */
	private $transport = null;

	protected function setUp(): void {
		if ( ! self::has_wordpress() ) {
			return; // Each capture test skips itself; the static guards still run.
		}

		// Pin every value the email class reads, or the golden files would encode
		// whatever this machine happened to have configured.
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

	/**
	 * @return bool Whether a real WordPress + integration DB is loaded.
	 */
	private static function has_wordpress(): bool {
		return defined( 'ANPA_SOCIOS_IT_DB' ) && function_exists( 'update_option' );
	}

	/**
	 * Skips a capture test when running outside the integration harness.
	 */
	private function require_wordpress(): void {
		if ( ! self::has_wordpress() ) {
			$this->markTestSkipped( 'Golden capture needs WordPress (run phpunit-integration.xml).' );
		}
	}

	/**
	 * @return string Directory holding the golden files.
	 */
	private function golden_dir(): string {
		return __DIR__ . '/golden';
	}

	/**
	 * @return bool Whether this run writes the files instead of asserting.
	 */
	private function capturing(): bool {
		return '1' === (string) getenv( 'ANPA_GOLDEN_CAPTURE' );
	}

	/**
	 * Records or verifies one captured email.
	 *
	 * @param string $key   Golden file stem, e.g. `enviar_aprobacion`.
	 * @param int    $index Which captured call to inspect (0 for the only one).
	 */
	private function assert_golden( string $key, int $index = 0 ): void {
		$this->assertArrayHasKey( $index, $this->sent, "no email captured for {$key}" );
		$mail = $this->sent[ $index ];

		$to      = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
		$headers = is_array( $mail['headers'] ) ? $mail['headers'] : array( (string) $mail['headers'] );
		$headers = array_values( array_filter( array_map( 'strval', $headers ) ) );
		sort( $headers ); // Header order is not part of the contract; content is.

		// One file per email, so a diff shows exactly what changed.
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

		$path = $this->golden_dir() . '/' . $key . '.txt';

		if ( $this->capturing() ) {
			if ( ! is_dir( $this->golden_dir() ) ) {
				mkdir( $this->golden_dir(), 0755, true );
			}
			file_put_contents( $path, $actual );
			$this->assertFileExists( $path );
			return;
		}

		$this->assertFileExists( $path, "golden file missing for {$key}; capture with ANPA_GOLDEN_CAPTURE=1" );
		$this->assertSame(
			(string) file_get_contents( $path ),
			$actual,
			"the wording of {$key} changed; families would receive something different"
		);
	}

	// ── The live emails, with fixed fictitious input ────────────────────

	public function test_golden_enviar_codigo_verificacion(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_codigo( 'nai@example.com', '123456', 'verificacion' );
		$this->assert_golden( 'enviar_codigo_verificacion' );
	}

	public function test_golden_enviar_codigo_alta(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_codigo( 'nai@example.com', '123456', 'alta' );
		$this->assert_golden( 'enviar_codigo_alta' );
	}

	public function test_golden_enviar_aviso_pendente_aprobacion(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_pendente_aprobacion( 'nai@example.com', 'Uxía Exemplo Ficticio' );
		$this->assert_golden( 'enviar_aviso_pendente_aprobacion' );
	}

	public function test_golden_enviar_aprobacion(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aprobacion( 'nai@example.com', 'https://example.org/area-socios/' );
		$this->assert_golden( 'enviar_aprobacion' );
	}

	public function test_golden_enviar_benvida_alta(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_benvida_alta( 'nai@example.com', 'https://example.org/area-socios/' );
		$this->assert_golden( 'enviar_benvida_alta' );
	}

	public function test_golden_enviar_rexeitamento(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_rexeitamento( 'nai@example.com' );
		$this->assert_golden( 'enviar_rexeitamento' );
	}

	public function test_golden_enviar_aviso_baixa_socio(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_baixa_socio( 'nai@example.com', 'Uxía', 'Exemplo Ficticio' );
		$this->assert_golden( 'enviar_aviso_baixa_socio' );
	}

	public function test_golden_enviar_aviso_reactivacion(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_reactivacion( 'nai@example.com' );
		$this->assert_golden( 'enviar_aviso_reactivacion' );
	}

	public function test_golden_enviar_aviso_baixa_extraescolar(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_aviso_baixa_extraescolar( 'nai@example.com', 'Antía Exemplo', 'Robótica' );
		$this->assert_golden( 'enviar_aviso_baixa_extraescolar' );
	}

	public function test_golden_enviar_oferta_extraescolar(): void {
		$this->require_wordpress();
		ANPA_Socios_Email::enviar_oferta_extraescolar( 'nai@example.com', 'Robótica', 3 );
		$this->assert_golden( 'enviar_oferta_extraescolar' );
	}

	// ── Guards on the oracle itself (run in the unit suite too) ─────────

	/**
	 * A new family-facing email must not slip in without an oracle entry,
	 * otherwise fase36 could silently rewrite it later.
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
	 * The oracle is read as a diff far more often than it is read as data, so the file
	 * listing must be in a stable, sorted order. `glob()` sorts by default, but that is
	 * a default worth pinning rather than assuming: an unstable listing turns a
	 * one-line wording change into an unreadable reordered diff.
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
	 * The golden files live in a public repository, so they must never contain
	 * a real address, a real family name or the deploying association's name.
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
