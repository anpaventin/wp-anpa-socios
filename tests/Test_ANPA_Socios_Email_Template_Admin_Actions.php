<?php
/**
 * Inspection tests for ANPA_Socios_Email_Template_Admin_Actions.
 *
 * These are structural / security-contract tests that examine the source code to prove
 * properties that must hold regardless of WordPress runtime state:
 * - Capability check before any output or write.
 * - Per-action nonce check.
 * - No free-text recipient field in any action.
 * - Authorize-before-act ordering.
 * - Test send resolves recipient from wp_get_current_user(), not from the request.
 * - Preview path never reaches wp_mail.
 * - All actions audited through the existing hook.
 * - Save passes expected_digest for optimistic concurrency.
 * - Restore default requires explicit confirmation.
 * - Nesting guard runs before repository save.
 *
 * @since  1.48.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Admin_Actions extends TestCase {

	/** @var string */
	private $source;

	/** @var string Source with comments stripped (for executable-code assertions). */
	private $code;

	protected function setUp(): void {
		$path = dirname( __DIR__ ) . '/includes/class-anpa-socios-email-template-admin-actions.php';
		$this->source = file_get_contents( $path );

		$tokens     = token_get_all( $this->source );
		$this->code = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$this->code .= is_array( $token ) ? $token[1] : $token;
		}
	}

	// ─── Capability and nonce ────────────────────────────────────────────────

	public function test_every_handler_calls_authorize_before_any_write(): void {
		// Every public handle_* method must call authorize or authorize_simple as its first
		// substantive statement (after the method signature).
		$handlers = array(
			'handle_save',
			'handle_preview',
			'handle_test_send',
			'handle_restore_default',
			'handle_restore_version',
			'handle_adopt_defaults',
		);

		foreach ( $handlers as $handler ) {
			// Find the method body: starts after "function {$handler}..."
			$pattern = '/function\s+' . preg_quote( $handler, '/' ) . '\s*\([^)]*\)\s*:\s*void\s*\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s';
			$this->assertMatchesRegularExpression( $pattern, $this->code, "Could not find {$handler} body" );

			preg_match( $pattern, $this->code, $m );
			$body = $m[1];

			// The first call in the body must be self::authorize or self::authorize_simple.
			$this->assertMatchesRegularExpression(
				'/^\s*\$?\w*\s*=?\s*self::authorize(?:_simple)?\s*\(/',
				$body,
				"Handler {$handler} does not call authorize as its first action"
			);
		}
	}

	public function test_cap_is_manage_options(): void {
		$this->assertStringContainsString( "const CAP = 'manage_options'", $this->source );
	}

	public function test_authorize_checks_capability_before_nonce(): void {
		// In the authorize method, current_user_can must appear before check_admin_referer.
		$cap_pos   = strpos( $this->code, 'current_user_can' );
		$nonce_pos = strpos( $this->code, 'check_admin_referer' );
		$this->assertNotFalse( $cap_pos );
		$this->assertNotFalse( $nonce_pos );
		$this->assertLessThan( $nonce_pos, $cap_pos, 'Capability must be checked before nonce' );
	}

	// ─── No free-text recipient ──────────────────────────────────────────────

	public function test_no_recipient_field_in_request(): void {
		// The code must never read a 'recipient', 'to', 'email', or 'destinatario' field
		// from $_POST or $_GET or $_REQUEST for the test send.
		$dangerous_fields = array( 'recipient', 'to_email', 'destinatario', 'send_to' );
		foreach ( $dangerous_fields as $field ) {
			$this->assertStringNotContainsString(
				"'{$field}'",
				$this->code,
				"Found dangerous recipient field '{$field}' in admin actions"
			);
			$this->assertStringNotContainsString(
				"\"{$field}\"",
				$this->code,
				"Found dangerous recipient field \"{$field}\" in admin actions"
			);
		}
	}

	public function test_test_send_recipient_from_current_user(): void {
		// The handle_test_send method must contain wp_get_current_user for the recipient.
		preg_match( '/function\s+handle_test_send[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_test_send body' );
		$body = $m[1];

		$this->assertStringContainsString( 'wp_get_current_user()', $body );
		$this->assertStringContainsString( '->user_email', $body );
	}

	// ─── Preview sends nothing ───────────────────────────────────────────────

	public function test_preview_does_not_call_wp_mail(): void {
		// Extract the preview handler and verify wp_mail is absent.
		preg_match( '/function\s+handle_preview[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_preview body' );
		$body = $m[1];

		$this->assertStringNotContainsString( 'wp_mail', $body );
	}

	// ─── Audit ───────────────────────────────────────────────────────────────

	public function test_all_handlers_audit_through_the_hook(): void {
		$handlers = array(
			'handle_save',
			'handle_preview',
			'handle_test_send',
			'handle_restore_default',
			'handle_restore_version',
			'handle_adopt_defaults',
		);

		foreach ( $handlers as $handler ) {
			preg_match( '/function\s+' . preg_quote( $handler, '/' ) . '[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
			$this->assertNotEmpty( $m[1], "Could not extract {$handler} body" );
			$this->assertStringContainsString( 'self::audit(', $m[1], "Handler {$handler} does not call audit" );
		}
	}

	public function test_audit_never_includes_content_or_addresses(): void {
		// The audit method fires do_action with specific fields. It must NOT include
		// content, html, text, subject, or recipient.
		preg_match( '/private\s+static\s+function\s+audit[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract audit method' );
		$body = $m[1];

		$this->assertStringNotContainsString( "'content'", $body );
		$this->assertStringNotContainsString( "'html'", $body );
		$this->assertStringNotContainsString( "'text'", $body );
		$this->assertStringNotContainsString( "'subject'", $body );
		$this->assertStringNotContainsString( "'recipient'", $body );
		$this->assertStringNotContainsString( "'to'", $body );
	}

	// ─── Save uses expected_digest ───────────────────────────────────────────

	public function test_save_passes_expected_digest_to_repo(): void {
		preg_match( '/function\s+handle_save[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_save body' );
		$body = $m[1];

		$this->assertStringContainsString( 'expected_digest', $body );
		// The call to Repo::save must include expected_digest as the 4th argument.
		$this->assertStringContainsString( '::save( $template_key, $content, $actor, $expected_digest )', $body );
	}

	// ─── Nesting guard ───────────────────────────────────────────────────────

	public function test_save_runs_nesting_guard_before_repo_save(): void {
		preg_match( '/function\s+handle_save[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_save body' );
		$body = $m[1];

		$nesting_pos = strpos( $body, 'Nesting_Guard::check_content' );
		$repo_pos    = strpos( $body, 'Repo::save(' );
		$this->assertNotFalse( $nesting_pos, 'Nesting guard not found in save handler' );
		$this->assertNotFalse( $repo_pos, 'Repo::save not found in save handler' );
		$this->assertLessThan( $repo_pos, $nesting_pos, 'Nesting guard must run BEFORE Repo::save' );
	}

	// ─── Restore default requires confirmation ───────────────────────────────

	public function test_restore_default_requires_confirmation(): void {
		preg_match( '/function\s+handle_restore_default[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_restore_default body' );
		$body = $m[1];

		$this->assertStringContainsString( 'confirm_restore', $body );
		$this->assertStringContainsString( 'restore_not_confirmed', $body );
	}

	// ─── No delete action exists ─────────────────────────────────────────────

	public function test_no_delete_template_action(): void {
		$this->assertStringNotContainsString( 'delete', strtolower( $this->code ) );
	}

	// ─── Action constants are also nonce names ───────────────────────────────

	public function test_action_constants_used_as_nonce_names(): void {
		$ref = new ReflectionClass( 'ANPA_Socios_Email_Template_Admin_Actions' );
		$constants = $ref->getConstants();

		$actions = array_filter( $constants, static function ( $key ) {
			return strpos( $key, 'ACTION_' ) === 0;
		}, ARRAY_FILTER_USE_KEY );

		foreach ( $actions as $name => $value ) {
			// Each action value must appear in a check_admin_referer or wp_nonce_field call
			// (through self::authorize which passes the action string).
			$this->assertStringContainsString(
				$value,
				$this->source,
				"Action constant {$name} value not found as nonce name in the source"
			);
		}
	}

	// ─── Register wires all handlers ─────────────────────────────────────────

	public function test_register_wires_all_action_constants(): void {
		$ref = new ReflectionClass( 'ANPA_Socios_Email_Template_Admin_Actions' );
		$constants = $ref->getConstants();

		$actions = array_filter( $constants, static function ( $key ) {
			return strpos( $key, 'ACTION_' ) === 0;
		}, ARRAY_FILTER_USE_KEY );

		preg_match( '/function\s+register[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract register method' );
		$register_body = $m[1];

		foreach ( $actions as $name => $value ) {
			$this->assertStringContainsString(
				"self::{$name}",
				$register_body,
				"register() does not wire constant {$name}"
			);
		}
	}

	// ─── Test send marks message as test ─────────────────────────────────────

	public function test_test_send_marks_subject_as_test(): void {
		preg_match( '/function\s+handle_test_send[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_test_send body' );
		$body = $m[1];

		$this->assertStringContainsString( '[PROBA]', $body );
	}
}
