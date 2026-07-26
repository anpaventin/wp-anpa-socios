<?php
/**
 * Tests for the default-template loader (fase36, PR-36s1c-1).
 *
 * The loader is exercised against fixture files written into a temporary directory, so the
 * error paths are covered without shipping a broken default. The shipped set is checked with
 * properties that tighten automatically as templates land: no count, no list to keep in sync.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Defaults extends TestCase {

	/** @var string Temporary fixture directory. */
	private string $dir = '';

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/anpa-defaults-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
	}

	/**
	 * Writes one fixture default.
	 *
	 * @param string      $stem    Stem.
	 * @param string|null $subject Subject, null to omit the file.
	 * @param string|null $html    HTML body, null to omit the file.
	 * @param string|null $text    Text body, null to omit the file.
	 */
	private function write( string $stem, ?string $subject = 'Asunto de proba', ?string $html = '<p>Ola</p>', ?string $text = 'Ola' ): void {
		$map = array(
			ANPA_Socios_Email_Template_Defaults::SUFFIX_SUBJECT => $subject,
			ANPA_Socios_Email_Template_Defaults::SUFFIX_HTML    => $html,
			ANPA_Socios_Email_Template_Defaults::SUFFIX_TEXT    => $text,
		);

		foreach ( $map as $suffix => $content ) {
			if ( null === $content ) {
				continue;
			}
			file_put_contents( $this->dir . '/' . $stem . $suffix, $content );
		}
	}

	/**
	 * @param string   $fragment Expected substring of the error message.
	 * @param callable $act      Operation that must throw.
	 */
	private function assert_rejected( string $fragment, callable $act ): void {
		try {
			$act();
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( $fragment, $e->getMessage() );
			return;
		}

		$this->fail( "expected rejection for: {$fragment}" );
	}

	// ── Loading ─────────────────────────────────────────────────────────

	public function test_a_complete_default_loads_with_its_version_and_hash(): void {
		$this->write( 'member_application_approved', 'A túa alta foi aprobada — {{nome_anpa}}' );

		$default = ANPA_Socios_Email_Template_Defaults::load( 'member_application_approved', $this->dir );

		$this->assertSame( 'A túa alta foi aprobada — {{nome_anpa}}', $default['subject'] );
		$this->assertSame( '<p>Ola</p>', $default['body_html'] );
		$this->assertSame( 'Ola', $default['body_text'] );
		$this->assertSame( 1, $default['default_version'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+:[0-9a-f]{64}$/', $default['default_sha256'] );
	}

	public function test_the_subject_is_collapsed_on_load(): void {
		// Not merely validated: a newline in a subject can forge headers, so it must be
		// impossible for a shipped file to introduce one.
		$this->write( 'algo', "Primeira liña\nsegunda liña" );

		$this->assertSame(
			'Primeira liña segunda liña',
			ANPA_Socios_Email_Template_Defaults::load( 'algo', $this->dir )['subject']
		);
	}

	public function test_windows_line_endings_do_not_change_the_content_hash(): void {
		// A checkout on Windows must not produce a different digest from the same content on
		// Linux, or "has this been customised?" would answer yes on half the installations.
		$this->write( 'unix', 'Asunto', "<p>unha</p>\n<p>dúas</p>", "unha\ndúas" );
		$unix = ANPA_Socios_Email_Template_Defaults::load( 'unix', $this->dir );

		$this->write( 'windows', 'Asunto', "<p>unha</p>\r\n<p>dúas</p>", "unha\r\ndúas" );
		$windows = ANPA_Socios_Email_Template_Defaults::load( 'windows', $this->dir );

		$this->assertSame( $unix['default_sha256'], $windows['default_sha256'] );
	}

	public function test_moving_a_sentence_between_channels_changes_the_hash(): void {
		// The parts are joined with a separator that cannot occur in the content, so the
		// change cannot cancel itself out.
		$this->write( 'a', 'Asunto', '<p>unha dúas</p>', '' );
		$this->write( 'b', 'Asunto', '<p>unha</p>', 'dúas' );

		$first = ANPA_Socios_Email_Template_Defaults::content_hash( 'Asunto', '<p>unha dúas</p>', '' );
		$then  = ANPA_Socios_Email_Template_Defaults::content_hash( 'Asunto', '<p>unha</p>', 'dúas' );

		$this->assertNotSame( $first, $then );
	}

	public function test_the_content_hash_names_its_scheme(): void {
		$hash = ANPA_Socios_Email_Template_Defaults::content_hash( 'a', 'b', 'c' );

		$this->assertStringStartsWith( ANPA_Socios_Email_Template_Defaults::CONTENT_SCHEME . ':', $hash );
	}

	public function test_stems_are_listed_sorted_and_only_when_complete(): void {
		$this->write( 'zulu' );
		$this->write( 'alpha' );
		$this->write( 'incomplete', 'Asunto', '<p>x</p>', null );

		$this->assertSame( array( 'alpha', 'zulu' ), ANPA_Socios_Email_Template_Defaults::stems( $this->dir ) );
		$this->assertFalse( ANPA_Socios_Email_Template_Defaults::exists( 'incomplete', $this->dir ) );
	}

	// ── Rejections ──────────────────────────────────────────────────────

	public function test_a_missing_part_is_rejected_by_name(): void {
		$this->write( 'algo', 'Asunto', '<p>x</p>', null );

		$this->assert_rejected(
			"'algo' is missing its text file",
			fn() => ANPA_Socios_Email_Template_Defaults::load( 'algo', $this->dir )
		);
	}

	public function test_an_empty_subject_is_rejected(): void {
		$this->write( 'algo', "   " );

		$this->assert_rejected(
			'empty subject',
			fn() => ANPA_Socios_Email_Template_Defaults::load( 'algo', $this->dir )
		);
	}

	public function test_an_empty_body_is_rejected(): void {
		// A default with no body renders an empty email, which is worse than refusing.
		$this->write( 'algo', 'Asunto', '   ', 'Ola' );

		$this->assert_rejected(
			'empty body',
			fn() => ANPA_Socios_Email_Template_Defaults::load( 'algo', $this->dir )
		);
	}

	public function test_a_byte_order_mark_is_rejected(): void {
		$this->write( 'algo' );
		file_put_contents(
			$this->dir . '/algo' . ANPA_Socios_Email_Template_Defaults::SUFFIX_HTML,
			"\xEF\xBB\xBF<p>Ola</p>"
		);

		$this->assert_rejected(
			'UTF-8 BOM',
			fn() => ANPA_Socios_Email_Template_Defaults::load( 'algo', $this->dir )
		);
	}

	public function test_invalid_utf8_is_rejected(): void {
		// This project has already had one real accent-corruption incident; a mangled
		// Galician accent in a shipped default reaches families before anybody notices.
		$this->write( 'algo' );
		file_put_contents(
			$this->dir . '/algo' . ANPA_Socios_Email_Template_Defaults::SUFFIX_HTML,
			"<p>d\xFAas</p>"
		);

		$this->assert_rejected(
			'not valid UTF-8',
			fn() => ANPA_Socios_Email_Template_Defaults::load( 'algo', $this->dir )
		);
	}

	public function test_an_invalid_stem_is_rejected(): void {
		$this->assert_rejected(
			"invalid template stem",
			fn() => ANPA_Socios_Email_Template_Defaults::load( '../../etc/passwd', $this->dir )
		);
	}

	// ── The shipped set ─────────────────────────────────────────────────

	public function test_every_shipped_default_belongs_to_a_registered_event(): void {
		// Tightens automatically as templates land: an orphan file is caught the moment it
		// appears, and no list has to be kept in sync.
		$registered = array();
		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $definition ) {
			$registered[] = $definition->default_template();
		}

		foreach ( ANPA_Socios_Email_Template_Defaults::stems() as $stem ) {
			$this->assertContains( $stem, $registered, "shipped default '{$stem}' has no registered event" );
		}
	}

	public function test_every_shipped_default_loads_without_error(): void {
		foreach ( ANPA_Socios_Email_Template_Defaults::stems() as $stem ) {
			$default = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$this->assertNotSame( '', $default['subject'], "{$stem} has no subject" );
		}
	}

	public function test_every_shipped_default_only_uses_tokens_its_event_declares(): void {
		$set = ANPA_Socios_Email_Template_Events::set();

		foreach ( ANPA_Socios_Email_Template_Defaults::stems() as $stem ) {
			$definition = null;
			foreach ( $set->all() as $candidate ) {
				if ( $candidate->default_template() === $stem ) {
					$definition = $candidate;
					break;
				}
			}
			$this->assertNotNull( $definition, "no event owns '{$stem}'" );

			$default    = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$undeclared = ANPA_Socios_Email_Template_Renderer::undeclared_tokens(
				$default['subject'] . "\n" . $default['body_html'] . "\n" . $default['body_text'],
				$definition->declared_tokens()
			);

			$this->assertSame( array(), $undeclared, "{$stem} uses undeclared tokens: " . implode( ', ', $undeclared ) );
		}
	}

	public function test_every_declared_version_has_files(): void {
		foreach ( ANPA_Socios_Email_Template_Defaults::VERSIONS as $stem => $version ) {
			$this->assertTrue(
				ANPA_Socios_Email_Template_Defaults::exists( (string) $stem ),
				"version declared for '{$stem}' but no files shipped"
			);
			$this->assertGreaterThanOrEqual( 1, (int) $version );
		}
	}

	public function test_no_shipped_default_mentions_payments(): void {
		// The plugin handles no charges and must never claim anything about one.
		foreach ( ANPA_Socios_Email_Template_Defaults::stems() as $stem ) {
			$default  = ANPA_Socios_Email_Template_Defaults::load( $stem );
			$haystack = strtolower( $default['subject'] . ' ' . $default['body_html'] . ' ' . $default['body_text'] );

			foreach ( array( 'cobro', 'cobrar', 'cuota', 'cota anual', 'pagamento', 'pago', 'domiciliaci', 'iban', 'recibo' ) as $forbidden ) {
				$this->assertStringNotContainsString(
					$forbidden,
					$haystack,
					"{$stem} mentions payments, which this plugin does not handle"
				);
			}
		}
	}

	// ── Purity ──────────────────────────────────────────────────────────

	public function test_the_loader_does_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-defaults.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}
}
