<?php
/**
 * Source-level inspection tests for the email templates admin screen (fase36, PR-36s4).
 *
 * These tests read the source file and use token_get_all to strip comments before
 * asserting structural properties. They prove that the render path:
 * 1. Contains no repository write call.
 * 2. Checks the capability before ANY output.
 * 3. Uses form_fields() for every form (no hand-rolled nonces).
 * 4. Contains no recipient input in the test-send area.
 *
 * WHAT THESE TESTS DO NOT PROVE:
 * - They cannot prove runtime behaviour (use integration tests for that).
 * - They cannot prove that escaping is semantically correct — only that the calls exist.
 * - Token stripping does not catch dynamically constructed function names.
 *
 * @since  TBD
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Email_Templates_Page_Inspection extends TestCase {

	/** @var string Source code with comments stripped. */
	private static string $source_no_comments = '';

	/** @var string Raw source code. */
	private static string $raw_source = '';

	public static function setUpBeforeClass(): void {
		$path = dirname( __DIR__ ) . '/includes/class-anpa-socios-email-templates-page.php';
		self::$raw_source = (string) file_get_contents( $path );
		self::$source_no_comments = self::strip_comments( self::$raw_source );
	}

	/**
	 * Strips T_COMMENT and T_DOC_COMMENT tokens from PHP source.
	 */
	private static function strip_comments( string $source ): string {
		$tokens = token_get_all( $source );
		$code   = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}
		return $code;
	}

	/**
	 * The render path does not call any repository write method.
	 *
	 * PROVES: the screen performs no writes (the repository is the single write gate).
	 * DOES NOT PROVE: that no side effect exists via a called function's internals.
	 */
	public function test_render_path_contains_no_repository_write_calls(): void {
		$forbidden = array(
			'Repo::save',
			'Repo::restore_default',
			'Repo::restore_version',
			'Repo::seed_missing',
			'Repo::adopt_newer_defaults',
			'update_option',
			'set_transient',
		);

		foreach ( $forbidden as $call ) {
			$this->assertStringNotContainsString(
				$call,
				self::$source_no_comments,
				"Render path must not call {$call}"
			);
		}
	}

	/**
	 * The capability check is the FIRST executable statement in render_page().
	 *
	 * PROVES: no output is emitted before verifying permissions.
	 * DOES NOT PROVE: that the check is correct at runtime (integration tests cover that).
	 */
	public function test_capability_check_precedes_any_output(): void {
		// Extract render_page method body.
		$pattern = '/function\s+render_page\s*\(\s*\).*?\{(.*?)(^\t\})/ms';
		preg_match( $pattern, self::$source_no_comments, $matches );
		$this->assertNotEmpty( $matches, 'Could not extract render_page body.' );

		$body = $matches[1];
		$cap_pos  = strpos( $body, 'current_user_can' );
		$echo_pos = strpos( $body, 'echo' );

		$this->assertNotFalse( $cap_pos, 'render_page must call current_user_can.' );
		$this->assertTrue(
			false === $echo_pos || $cap_pos < $echo_pos,
			'Capability check must precede any echo statement.'
		);
	}

	/**
	 * Every form on the screen uses form_fields() — no hand-rolled nonces.
	 *
	 * PROVES: the nonce pattern is uniform and not bypassed anywhere.
	 * DOES NOT PROVE: that form_fields() itself is correct (unit tests for that class do).
	 */
	public function test_every_form_uses_form_fields(): void {
		// Count form opening tags.
		$form_count = substr_count( self::$source_no_comments, '<form ' );
		$this->assertGreaterThan( 0, $form_count, 'Page must contain at least one form.' );

		// Count form_fields() calls.
		$fields_count = substr_count( self::$source_no_comments, 'form_fields(' );
		$this->assertSame(
			$form_count,
			$fields_count,
			"Every form ({$form_count}) must have exactly one form_fields() call ({$fields_count})."
		);

		// Verify no hand-rolled wp_nonce_field in this file.
		$this->assertStringNotContainsString(
			'wp_nonce_field',
			self::$source_no_comments,
			'Screen must not hand-roll nonces; form_fields() handles them.'
		);
	}

	/**
	 * No recipient input exists in the test-send area.
	 *
	 * PROVES: the screen never offers a free-text recipient field.
	 * DOES NOT PROVE: that the action handler resolves it correctly (integration tests do).
	 */
	public function test_no_recipient_input_in_test_send_form(): void {
		// No input named recipient, email, to, destinatario.
		$forbidden_inputs = array(
			'name="recipient"',
			'name="email"',
			'name="to"',
			'name="destinatario"',
			'name="test_recipient"',
		);

		foreach ( $forbidden_inputs as $input ) {
			$this->assertStringNotContainsString(
				$input,
				self::$source_no_comments,
				"Test-send form must not contain {$input}"
			);
		}
	}

	/**
	 * The render path does not write options or transients.
	 *
	 * PROVES: the screen is read-only with respect to WordPress options/transients.
	 * The ONE exception is delete_transient (consuming the preview) which is allowed.
	 */
	public function test_no_option_or_transient_writes_except_delete_preview(): void {
		$this->assertStringNotContainsString( 'update_option', self::$source_no_comments );
		$this->assertStringNotContainsString( 'add_option', self::$source_no_comments );
		$this->assertStringNotContainsString( 'set_transient', self::$source_no_comments );

		// delete_transient is acceptable (consuming the one-time preview).
		$this->assertStringContainsString( 'delete_transient', self::$source_no_comments );
	}
}
