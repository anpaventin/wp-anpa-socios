<?php
/**
 * Source-inspection contracts for the sanitiser (fase36, PR-36s2).
 *
 * The sanitiser needs `wp_kses`, so its behaviour is proved against a real engine in the integration
 * suite. What can be asserted here, without WordPress, is the shape of the thing: that it enforces
 * the declared policy instead of an inline list, that it restores the CSS filter even when the call
 * throws, and that it never runs on read.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Sanitizer_Contracts extends TestCase {

	private string $src;

	protected function setUp(): void {
		$this->src = (string) file_get_contents(
			dirname( __DIR__ ) . '/includes/class-anpa-socios-email-template-sanitizer.php'
		);
	}

	public function test_it_enforces_the_declared_policy_rather_than_an_inline_list(): void {
		// A second allowlist written inside the sanitiser is a second allowlist until somebody edits
		// one of them.
		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Html_Policy::allowed_tags()', $this->src );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Html_Policy::allowed_protocols()', $this->src );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Html_Policy::allowed_css_properties()', $this->src );
		$this->assertStringNotContainsString( "'script'", $this->src );
	}

	public function test_it_reuses_the_frozen_subject_rule(): void {
		// Header injection was already solved once, in the renderer. Restating the rule here would
		// mean two rules that agree until one is edited.
		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Renderer::collapse_subject', $this->src );
	}

	public function test_it_widens_the_css_allowlist_only_for_the_duration_of_the_call(): void {
		// A failed save must not leave the site's CSS policy widened for every other consumer, so
		// the filter is removed in a finally block rather than after the call.
		$this->assertStringContainsString( "add_filter( 'safe_style_css'", $this->src );
		$this->assertStringContainsString( "remove_filter( 'safe_style_css'", $this->src );

		$start = strpos( $this->src, 'private static function with_css_policy' );
		$this->assertIsInt( $start );
		$body = substr( $this->src, (int) $start );

		$this->assertStringContainsString( '} finally {', $body );
		$this->assertLessThan(
			strpos( $body, "remove_filter( 'safe_style_css'" ),
			strpos( $body, '} finally {' ),
			'the filter must be removed inside finally, not after the call'
		);
	}

	public function test_it_declares_that_it_runs_on_write_only(): void {
		// Sanitising on read means the database holds content nobody checked and every consumer has
		// to remember to clean it.
		$this->assertStringContainsString( 'Runs on WRITE, never on read', $this->src );
	}

	public function test_it_does_not_truncate_a_subject(): void {
		// A subject silently cut at the column width is a subject nobody proof-read. The repository
		// refuses it instead.
		$this->assertStringNotContainsString( 'substr( $subject', $this->src );
		$this->assertStringNotContainsString( 'mb_substr( $subject', $this->src );
	}

	public function test_it_never_sends_or_renders(): void {
		$this->assertStringNotContainsString( 'wp_mail', $this->src );
		$this->assertStringNotContainsString( 'Renderer::render', $this->src );
	}

	public function test_it_is_registered_in_the_plugin_bootstrap(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/anpa-socios.php' );

		$this->assertStringContainsString( 'class-anpa-socios-email-template-sanitizer.php', $bootstrap );
		$this->assertStringContainsString( 'class-anpa-socios-email-template-html-policy.php', $bootstrap );
	}

	public function test_the_glue_guards_direct_access(): void {
		$this->assertStringContainsString( "if ( ! defined( 'ABSPATH' ) ) {", $this->src );
	}
}
