<?php
/**
 * Inspection tests for ANPA_Socios_Email_Template_Admin_Actions.
 *
 * These tests examine source code to prove properties that hold REGARDLESS of WordPress
 * runtime state. Each docblock states what it DOES and DOES NOT prove.
 *
 * What inspection CAN prove:
 * - Structural ordering (authorize before any write call).
 * - Absence of dangerous patterns (no recipient field, no wp_mail in preview).
 * - Presence of required calls (audit hook, nonce, capability constant).
 * - Registration completeness (every ACTION constant is wired).
 *
 * What inspection CANNOT prove (covered by the integration suite):
 * - That the handlers actually run and produce correct results.
 * - That the renderer returns the right content.
 * - That the database ends up in the right state.
 *
 * @since  TBD
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Admin_Actions extends TestCase {

	/** @var string Raw source. */
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

	/**
	 * Proves: every handler calls authorize/authorize_simple as its FIRST action.
	 * Does NOT prove: that WordPress actually enforces the capability at runtime.
	 */
	public function test_every_handler_calls_authorize_before_any_write(): void {
		$handlers = array(
			'handle_save',
			'handle_preview',
			'handle_test_send',
			'handle_restore_default',
			'handle_restore_version',
			'handle_adopt_defaults',
		);

		foreach ( $handlers as $handler ) {
			$pattern = '/function\s+' . preg_quote( $handler, '/' ) . '\s*\([^)]*\)\s*:\s*void\s*\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s';
			$this->assertMatchesRegularExpression( $pattern, $this->code, "Could not find {$handler} body" );

			preg_match( $pattern, $this->code, $m );
			$body = $m[1];

			$this->assertMatchesRegularExpression(
				'/^\s*\$?\w*\s*=?\s*self::authorize(?:_simple)?\s*\(/',
				$body,
				"Handler {$handler} does not call authorize as its first action"
			);
		}
	}

	/**
	 * Proves: the declared capability constant is manage_options.
	 * Does NOT prove: that it is actually used at runtime.
	 */
	public function test_cap_is_manage_options(): void {
		$this->assertStringContainsString( "const CAP = 'manage_options'", $this->source );
	}

	/**
	 * Proves: in the authorize method, current_user_can appears before check_admin_referer.
	 * Does NOT prove: that WordPress is running or that the check actually fires.
	 */
	public function test_authorize_checks_capability_before_nonce(): void {
		$cap_pos   = strpos( $this->code, 'current_user_can' );
		$nonce_pos = strpos( $this->code, 'check_admin_referer' );
		$this->assertNotFalse( $cap_pos );
		$this->assertNotFalse( $nonce_pos );
		$this->assertLessThan( $nonce_pos, $cap_pos, 'Capability must be checked before nonce' );
	}

	// ─── No free-text recipient ──────────────────────────────────────────────

	/**
	 * Proves: the executable source never reads a dangerous recipient field name from
	 * the request superglobals.
	 * Does NOT prove: that the actual send works or goes to the right address (integration).
	 */
	public function test_no_recipient_field_in_request(): void {
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

	/**
	 * Proves: handle_test_send resolves its recipient from wp_get_current_user().
	 * Does NOT prove: that the actual email arrives at the right address (integration).
	 */
	public function test_test_send_recipient_from_current_user(): void {
		preg_match( '/function\s+handle_test_send[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_test_send body' );
		$body = $m[1];

		$this->assertStringContainsString( 'wp_get_current_user()', $body );
		$this->assertStringContainsString( '->user_email', $body );
	}

	// ─── Preview sends nothing ───────────────────────────────────────────────

	/**
	 * Proves: handle_preview does not contain a wp_mail call.
	 * Does NOT prove: that some indirect path cannot send (integration covers that).
	 */
	public function test_preview_does_not_call_wp_mail(): void {
		preg_match( '/function\s+handle_preview[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_preview body' );
		$body = $m[1];

		$this->assertStringNotContainsString( 'wp_mail', $body );
	}

	// ─── Audit ───────────────────────────────────────────────────────────────

	/**
	 * Proves: every handler calls self::audit() somewhere in its body.
	 * Does NOT prove: that the audit hook fires at runtime.
	 */
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

	/**
	 * Proves: the audit method never mentions content, html, text, subject or recipient
	 * keys in its do_action payload.
	 * Does NOT prove: that the action payload is correct at runtime.
	 */
	public function test_audit_never_includes_content_or_addresses(): void {
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

	/**
	 * Proves: handle_save passes expected_digest as 4th argument to Repo::save.
	 * Does NOT prove: that the concurrency check works (integration covers that).
	 */
	public function test_save_passes_expected_digest_to_repo(): void {
		preg_match( '/function\s+handle_save[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_save body' );
		$body = $m[1];

		$this->assertStringContainsString( 'expected_digest', $body );
		$this->assertStringContainsString( '::save( $template_key, $content, $actor, $expected_digest )', $body );
	}

	// ─── Nesting guard ───────────────────────────────────────────────────────

	/**
	 * Proves: nesting guard call appears BEFORE Repo::save in handle_save.
	 * Does NOT prove: that the guard detects all nested patterns (unit tests on the guard).
	 */
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

	/**
	 * Proves: handle_restore_default references confirm_restore and restore_not_confirmed.
	 * Does NOT prove: that an unconfirmed restore is actually refused (integration).
	 */
	public function test_restore_default_requires_confirmation(): void {
		preg_match( '/function\s+handle_restore_default[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_restore_default body' );
		$body = $m[1];

		$this->assertStringContainsString( 'confirm_restore', $body );
		$this->assertStringContainsString( 'restore_not_confirmed', $body );
	}

	// ─── No delete action exists ─────────────────────────────────────────────

	/**
	 * Proves: the string "delete" never appears in executable code.
	 * Does NOT prove: that templates cannot be deleted through another path.
	 */
	public function test_no_delete_template_action(): void {
		$this->assertStringNotContainsString( 'delete', strtolower( $this->code ) );
	}

	// ─── Action constants are also nonce names ───────────────────────────────

	/**
	 * Proves: every ACTION_* constant value appears somewhere in the source.
	 * Does NOT prove: that wp_nonce_field/check_admin_referer actually use them.
	 */
	public function test_action_constants_used_as_nonce_names(): void {
		$ref = new ReflectionClass( 'ANPA_Socios_Email_Template_Admin_Actions' );
		$constants = $ref->getConstants();

		$actions = array_filter( $constants, static function ( $key ) {
			return strpos( $key, 'ACTION_' ) === 0;
		}, ARRAY_FILTER_USE_KEY );

		foreach ( $actions as $name => $value ) {
			$this->assertStringContainsString(
				$value,
				$this->source,
				"Action constant {$name} value not found as nonce name in the source"
			);
		}
	}

	// ─── Register wires all handlers ─────────────────────────────────────────

	/**
	 * Proves: register() references every ACTION_* constant.
	 * Does NOT prove: that WordPress actually fires them (integration).
	 */
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

	// ─── No nopriv handler registered ────────────────────────────────────────

	/**
	 * Proves: register() does not contain 'nopriv' — unauthenticated users never reach
	 * these handlers.
	 * Does NOT prove: that an unregistered hook is unreachable at runtime (WP guarantees that).
	 */
	public function test_no_nopriv_handler_registered(): void {
		preg_match( '/function\s+register[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract register method' );
		$this->assertStringNotContainsString( 'nopriv', $m[1] );
	}

	// ─── Test send marks message as test ─────────────────────────────────────

	/**
	 * Proves: handle_test_send prepends [PROBA] to the subject.
	 * Does NOT prove: that the actual email carries it (integration).
	 */
	public function test_test_send_marks_subject_as_test(): void {
		preg_match( '/function\s+handle_test_send[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_test_send body' );
		$body = $m[1];

		$this->assertStringContainsString( '[PROBA]', $body );
	}

	// ─── Test send uses association sender identity ──────────────────────────

	/**
	 * Proves: handle_test_send delegates to ANPA_Socios_Email::send_test() rather than
	 * hand-building headers, so it uses the same From/Reply-To/Content-Type as production.
	 * Does NOT prove: that send_test() actually sets the headers (integration covers that).
	 */
	public function test_test_send_uses_association_sender_identity(): void {
		preg_match( '/function\s+handle_test_send[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_test_send body' );
		$body = $m[1];

		$this->assertStringContainsString( 'ANPA_Socios_Email::send_test(', $body );
		// Must NOT hand-build Content-Type headers.
		$this->assertStringNotContainsString( 'Content-Type:', $body );
	}

	// ─── Save does not pre-sanitise content ──────────────────────────────────

	/**
	 * Proves: handle_save does not call sanitize_text_field or sanitize_textarea_field on
	 * the template content (the repository owns sanitisation).
	 * Does NOT prove: that the repository actually sanitises (its own tests cover that).
	 */
	public function test_save_does_not_pre_sanitise_content(): void {
		preg_match( '/function\s+handle_save[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_save body' );
		$body = $m[1];

		// sanitize_text_field is allowed for expected_digest (a hash), but not for content.
		// Look for it after the $content array is built: between content and Repo::save.
		$content_end = strpos( $body, "expected_digest" );
		$after_content = substr( $body, 0, $content_end );
		$this->assertStringNotContainsString( 'sanitize_text_field', $after_content );
		$this->assertStringNotContainsString( 'sanitize_textarea_field', $after_content );
	}

	// ─── Content keys match the repository vocabulary ────────────────────────

	/**
	 * Proves: handle_save builds a content array with keys subject, body_html, body_text —
	 * the same vocabulary Repo::save() and Stored_Custom_Template::from_request() expect.
	 * Does NOT prove: that the save actually works (integration).
	 */
	public function test_save_content_keys_match_repository_vocabulary(): void {
		preg_match( '/function\s+handle_save[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_save body' );
		$body = $m[1];

		$this->assertStringContainsString( "'subject'", $body );
		$this->assertStringContainsString( "'body_html'", $body );
		$this->assertStringContainsString( "'body_text'", $body );
		// Must NOT use the old wrong keys.
		$this->assertStringNotContainsString( "'html'", $body );
		$this->assertStringNotContainsString( "'text'", $body );
	}

	// ─── Preview and test send use Validator::render, not Renderer directly ──

	/**
	 * Proves: handle_preview uses Validator::render (the production gate) not Renderer::render.
	 * Does NOT prove: that the render actually succeeds (integration).
	 */
	public function test_preview_routes_through_validator(): void {
		preg_match( '/function\s+handle_preview[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_preview body' );
		$body = $m[1];

		$this->assertStringContainsString( 'Validator::render(', $body );
		$this->assertStringNotContainsString( 'Renderer::render(', $body );
	}

	/**
	 * Proves: handle_test_send uses Validator::render (the production gate) not Renderer directly.
	 * Does NOT prove: that the render actually succeeds (integration).
	 */
	public function test_test_send_routes_through_validator(): void {
		preg_match( '/function\s+handle_test_send[^{]*\{(.*?)\n\t\}/s', $this->source, $m );
		$this->assertNotEmpty( $m[1], 'Could not extract handle_test_send body' );
		$body = $m[1];

		$this->assertStringContainsString( 'Validator::render(', $body );
		$this->assertStringNotContainsString( 'Renderer::render(', $body );
	}

	// ─── Uses Events::set() — the correct entry point ────────────────────────

	/**
	 * Proves: the code uses ANPA_Socios_Email_Template_Events::set() — the memoised validated
	 * entry point — and does NOT use Registry::build() or Events::definitions() (which does
	 * not exist).
	 * Does NOT prove: that the set is valid at runtime (the registry engine tests cover that).
	 */
	public function test_uses_events_set_not_fabricated_registry_build(): void {
		$this->assertStringContainsString( 'Events::set()', $this->code );
		$this->assertStringNotContainsString( 'Registry::build(', $this->code );
		$this->assertStringNotContainsString( 'Events::definitions()', $this->code );
	}
}
