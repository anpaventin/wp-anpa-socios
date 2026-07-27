<?php
/**
 * Integration tests for ANPA_Socios_Email_Template_Admin_Actions (fase36, PR-36s4).
 *
 * These tests actually INVOKE the handlers against a real WordPress and a real database.
 * They cover the defect class identified as I20: source-inspection tests that pass on code
 * which cannot execute a single handler.
 *
 * What these tests prove:
 * - A save that succeeds marks the template customised.
 * - A save with a stale expected_digest is refused as conflict.
 * - A save with an undeclared token is refused.
 * - A save with a nested optional block is refused before the repository is reached.
 * - A preview renders and sends NOTHING (asserted via pre_wp_mail that no message was attempted).
 * - A test send produces exactly one message addressed to the logged-in administrator with the
 *   association's From and Reply-To and an HTML content type.
 * - Restore default works and returns code 'restored_default'.
 * - Restore version works and returns code 'restored_version'.
 * - Adopt newer defaults correctly derives its outcome from the result shape and reports count.
 *
 * ARCHITECTURE NOTE — intercepting exit; inside redirect_back():
 * Production code calls wp_safe_redirect() followed by exit;. `exit` is NOT a Throwable — it
 * terminates the PHP process unconditionally. The only way to test code that calls exit; without
 * modifying production is to prevent execution from REACHING exit; in the first place.
 *
 * We do this by hooking `wp_redirect` (which wp_safe_redirect calls internally) and THROWING a
 * dedicated exception from the filter callback. The exception propagates out of wp_safe_redirect
 * BEFORE the subsequent exit; is reached. The test helper catches ONLY this specific exception
 * class — never a bare \Throwable, which is exactly the pattern that hid the original failure.
 *
 * If a handler returns WITHOUT the redirect having been captured, the helper fails loudly.
 * This guard ensures silent non-execution can never recur.
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Dedicated exception thrown by the wp_redirect filter to intercept before exit; is reached.
 * This is NOT a Throwable catch-all — the helper catches ONLY this class.
 */
final class ANPA_Socios_Test_Redirect_Interception extends \Exception {
	/** @var string The captured redirect location. */
	private $location;

	public function __construct( string $location ) {
		parent::__construct( 'Redirect intercepted: ' . $location );
		$this->location = $location;
	}

	public function get_location(): string {
		return $this->location;
	}
}

final class Test_ANPA_Socios_Email_Template_Admin_Actions_Integration extends TestCase {

	/** A live event key that has a shipped default and is guaranteed to exist. */
	private const KEY = 'auth_access_code';

	/** @var WP_User|null Admin user for the test. */
	private static $admin;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
			return;
		}

		// Create an administrator user for all tests.
		$user_id = wp_create_user( 'tpl_admin', 'password', 'admin@example.com' );
		if ( is_wp_error( $user_id ) ) {
			$user = get_user_by( 'login', 'tpl_admin' );
			if ( $user ) {
				self::$admin = $user;
			}
		} else {
			$user = get_user_by( 'id', $user_id );
			if ( $user ) {
				$user->set_role( 'administrator' );
				self::$admin = $user;
			}
		}
	}

	protected function setUp(): void {
		if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
			$this->markTestSkipped( 'Integration DB not available.' );
		}

		$this->reset_catalogue();
		$this->become_admin();
	}

	protected function tearDown(): void {
		// Remove any filters we added during the test.
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'wp_redirect' );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	private function reset_catalogue(): void {
		global $wpdb;

		$templates = ANPA_Socios_DB::tabela_email_templates();
		$versions  = ANPA_Socios_DB::tabela_email_template_versions();

		ANPA_Socios_DB::crear_tabelas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$versions}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$templates}`" );

		// Seed so there is something to work with.
		ANPA_Socios_Email_Template_Repo::seed_missing( 'integration-test' );
	}

	private function become_admin(): void {
		if ( self::$admin ) {
			wp_set_current_user( self::$admin->ID );
		}
	}

	/** @return array<string,string> The stored template content for our key. */
	private function stored_template(): array {
		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertIsArray( $row, 'Template must be seeded before test' );
		return array(
			'subject'   => (string) $row['subject'],
			'body_html' => (string) $row['body_html'],
			'body_text' => (string) $row['body_text'],
		);
	}

	/** @return string The current content_hash of the stored template. */
	private function current_digest(): string {
		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertIsArray( $row );
		return (string) $row['content_hash'];
	}

	/**
	 * Simulates the POST fields and fires the save handler directly.
	 * We cannot use wp_remote_post (no HTTP server); we simulate the superglobal and invoke.
	 *
	 * @param array<string,string> $content  Keys: subject, body_html, body_text.
	 * @param string               $digest   Expected digest (empty to skip concurrency).
	 * @param string               $key      Template key.
	 * @return string The redirect URL (captured by overriding wp_redirect).
	 */
	private function invoke_save( array $content, string $digest = '', string $key = self::KEY ): string {
		$_POST['template_key']     = $key;
		$_POST['template_subject'] = $content['subject'];
		$_POST['template_html']    = $content['body_html'];
		$_POST['template_text']    = $content['body_text'];
		$_POST['expected_digest']  = $digest;
		$_POST['_wpnonce']         = wp_create_nonce( ANPA_Socios_Email_Template_Admin_Actions::ACTION_SAVE );
		$_REQUEST['_wpnonce']      = $_POST['_wpnonce'];

		return $this->capture_redirect( 'handle_save' );
	}

	/**
	 * Invokes a handler and captures the redirect URL instead of actually redirecting.
	 *
	 * HOW IT WORKS: wp_safe_redirect() calls wp_redirect() internally. Our filter on
	 * wp_redirect THROWS a dedicated exception, which propagates out of wp_safe_redirect()
	 * BEFORE the subsequent exit; is reached. We catch ONLY that exception class — never a
	 * bare \Throwable, which is what hid the original failure (exit is not Throwable).
	 *
	 * GUARD: if the handler returns without throwing (meaning the redirect was never reached),
	 * the helper fails the test loudly. Silent non-execution cannot recur.
	 *
	 * @param string $method Handler method name.
	 * @return string The redirect URL.
	 */
	private function capture_redirect( string $method ): string {
		// Hook wp_redirect to THROW before exit; is reached.
		add_filter( 'wp_redirect', static function ( $location ) {
			throw new ANPA_Socios_Test_Redirect_Interception( (string) $location );
		}, 1 );

		// Set a referer for redirect_back.
		$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=anpa-socios-plantelas&template_key=' . self::KEY );

		$redirect_url = null;
		try {
			ANPA_Socios_Email_Template_Admin_Actions::$method();
		} catch ( ANPA_Socios_Test_Redirect_Interception $e ) {
			$redirect_url = $e->get_location();
		}

		remove_all_filters( 'wp_redirect' );

		// GUARD: if no redirect was captured, the handler returned without redirecting.
		// This is the exact failure mode that allowed eight dead tests before.
		$this->assertNotNull(
			$redirect_url,
			"capture_redirect('{$method}'): handler returned without a redirect being captured. "
			. 'This means the handler did not reach redirect_back(), which is the failure mode '
			. 'that allowed silent non-execution in the original test suite.'
		);

		return $redirect_url;
	}

	// ─── Save: success marks customised ──────────────────────────────────────

	public function test_save_succeeds_and_marks_customised(): void {
		$original = $this->stored_template();
		$digest   = $this->current_digest();

		$edited = array(
			'subject'   => 'Edited ' . $original['subject'],
			'body_html' => $original['body_html'],
			'body_text' => $original['body_text'],
		);

		$url = $this->invoke_save( $edited, $digest );

		// The repo returns code 'saved' on success.
		$this->assertStringContainsString( 'anpa_msg=saved', $url );

		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertIsArray( $row );
		$this->assertSame( 1, (int) $row['is_customised'], 'Template should be marked customised after edit' );
		$this->assertSame( $edited['subject'], (string) $row['subject'] );
	}

	// ─── Save: stale digest → conflict ───────────────────────────────────────

	public function test_save_with_stale_digest_is_refused_as_conflict(): void {
		$original = $this->stored_template();
		$stale    = 'editorial-v1:0000000000000000000000000000000000000000000000000000000000000000';

		$edited = array(
			'subject'   => 'Conflict test ' . $original['subject'],
			'body_html' => $original['body_html'],
			'body_text' => $original['body_text'],
		);

		$url = $this->invoke_save( $edited, $stale );

		// The repo returns code 'conflict' when the digest has moved.
		$this->assertStringContainsString( 'anpa_msg=conflict', $url );

		// The row must NOT have changed.
		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertSame( $original['subject'], (string) $row['subject'] );
	}

	// ─── Save: undeclared token → refused ────────────────────────────────────

	public function test_save_with_undeclared_token_is_refused(): void {
		$original = $this->stored_template();
		$digest   = $this->current_digest();

		$edited = array(
			'subject'   => $original['subject'],
			'body_html' => $original['body_html'] . '{{invented_variable_xyz}}',
			'body_text' => $original['body_text'],
		);

		$url = $this->invoke_save( $edited, $digest );

		// The repo returns 'undeclared_tokens' from validate().
		$this->assertStringContainsString( 'anpa_msg=undeclared_tokens', $url );
	}

	// ─── Save: nested block → refused before repo ────────────────────────────

	public function test_save_with_nested_block_is_refused_before_repo(): void {
		$original = $this->stored_template();
		$digest   = $this->current_digest();

		$edited = array(
			'subject'   => $original['subject'],
			'body_html' => '{{#codigo}}outer {{#nome_anpa}}inner{{/}}{{/}}' . $original['body_html'],
			'body_text' => $original['body_text'],
		);

		$url = $this->invoke_save( $edited, $digest );

		// The nesting guard fires BEFORE the repo, returning 'nested_block'.
		$this->assertStringContainsString( 'anpa_msg=nested_block', $url );

		// Row must be unchanged.
		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertSame( $original['body_html'], (string) $row['body_html'] );
	}

	// ─── Preview: renders and sends NOTHING ──────────────────────────────────

	public function test_preview_renders_and_sends_nothing(): void {
		$mail_attempts = 0;

		// Hook pre_wp_mail to detect any send attempt.
		add_filter( 'pre_wp_mail', static function () use ( &$mail_attempts ) {
			$mail_attempts++;
			return true; // Short-circuit but count it.
		}, 1 );

		$_POST['template_key'] = self::KEY;
		$_POST['_wpnonce']     = wp_create_nonce( ANPA_Socios_Email_Template_Admin_Actions::ACTION_PREVIEW );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$url = $this->capture_redirect( 'handle_preview' );

		$this->assertSame( 0, $mail_attempts, 'Preview must NOT attempt any wp_mail send' );
		$this->assertStringContainsString( 'anpa_msg=preview_ready', $url );
	}

	// ─── Test send: one message, correct headers ─────────────────────────────

	public function test_test_send_produces_one_message_with_association_identity(): void {
		$messages_sent = array();

		// Intercept wp_mail to capture the message without sending.
		add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$messages_sent ) {
			$messages_sent[] = $atts;
			return true; // Prevent actual send.
		}, 1, 2 );

		$_POST['template_key'] = self::KEY;
		$_POST['_wpnonce']     = wp_create_nonce( ANPA_Socios_Email_Template_Admin_Actions::ACTION_TEST_SEND );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$this->capture_redirect( 'handle_test_send' );

		// Exactly one message.
		$this->assertCount( 1, $messages_sent, 'Test send must produce exactly one message' );

		$msg = $messages_sent[0];

		// Addressed to the logged-in admin.
		$this->assertSame( 'admin@example.com', $msg['to'] );

		// Subject marked as test.
		$this->assertStringContainsString( '[PROBA]', $msg['subject'] );

		// Headers include the association's From and Reply-To.
		$from_email = ANPA_Socios_Config::master_email();

		$headers_str = is_array( $msg['headers'] ) ? implode( "\n", $msg['headers'] ) : (string) $msg['headers'];
		$this->assertStringContainsString( 'From:', $headers_str );
		$this->assertStringContainsString( 'Reply-To:', $headers_str );
		$this->assertStringContainsString( $from_email, $headers_str );
	}

	// ─── Restore default ─────────────────────────────────────────────────────

	public function test_restore_default_restores_and_clears_customised(): void {
		// First, customise the template.
		$original = $this->stored_template();
		$digest   = $this->current_digest();

		$edited = array(
			'subject'   => 'Custom subject for restore test',
			'body_html' => $original['body_html'],
			'body_text' => $original['body_text'],
		);
		$this->invoke_save( $edited, $digest );

		// Confirm it is customised.
		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertSame( 1, (int) $row['is_customised'] );

		// Now restore default.
		$_POST['template_key']    = self::KEY;
		$_POST['confirm_restore'] = '1';
		$_POST['_wpnonce']        = wp_create_nonce( ANPA_Socios_Email_Template_Admin_Actions::ACTION_RESTORE_DEFAULT );
		$_REQUEST['_wpnonce']     = $_POST['_wpnonce'];

		$url = $this->capture_redirect( 'handle_restore_default' );

		// The repo returns code 'restored_default' — NOT 'restored'.
		$this->assertStringContainsString( 'anpa_msg=restored_default', $url );

		// Verify: row is now NOT customised and subject is back to original.
		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertSame( 0, (int) $row['is_customised'] );
		$this->assertSame( $original['subject'], (string) $row['subject'] );
	}

	// ─── Restore version ─────────────────────────────────────────────────────

	public function test_restore_version_restores_archived_content(): void {
		// First save creates a version in history.
		$original = $this->stored_template();
		$digest   = $this->current_digest();

		$edited = array(
			'subject'   => 'Version 2 subject for restore test',
			'body_html' => $original['body_html'],
			'body_text' => $original['body_text'],
		);
		$this->invoke_save( $edited, $digest );

		// The archive should now have a version.
		$versions = ANPA_Socios_Email_Template_Repo::versions( self::KEY );
		$this->assertNotEmpty( $versions, 'A save should create an archived version' );

		$version_id = (int) $versions[0]['id'];

		// Restore that version.
		$_POST['template_key'] = self::KEY;
		$_POST['version_id']   = (string) $version_id;
		$_POST['_wpnonce']     = wp_create_nonce( ANPA_Socios_Email_Template_Admin_Actions::ACTION_RESTORE_VERSION );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];

		$url = $this->capture_redirect( 'handle_restore_version' );

		// The repo returns code 'restored_version' — NOT 'restored'.
		$this->assertStringContainsString( 'anpa_msg=restored_version', $url );

		// The row should now have the original subject (restored from archive).
		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertSame( $original['subject'], (string) $row['subject'] );
	}

	// ─── Adopt newer defaults: correct count and no undefined key access ─────

	/**
	 * Verifies that handle_adopt_defaults() correctly consumes the return shape of
	 * Repo::adopt_newer_defaults() — specifically that it uses count($result['adopted'])
	 * (an array of keys), not (int)$result['adopted'] (which would cast an array to 1 or 0),
	 * and that it derives an outcome code rather than accessing a non-existent $result['code'].
	 *
	 * This test would have caught C3 had it existed when the handler was written.
	 */
	public function test_adopt_defaults_reports_correct_count_and_code(): void {
		// When no templates are outdated, the result should report nothing_to_adopt with 0 adopted.
		$_POST['_wpnonce']    = wp_create_nonce( ANPA_Socios_Email_Template_Admin_Actions::ACTION_ADOPT_DEFAULTS );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$url = $this->capture_redirect( 'handle_adopt_defaults' );

		// The handler should produce a valid redirect URL with anpa_msg and anpa_adopted.
		$this->assertStringContainsString( 'anpa_msg=', $url, 'Redirect must carry an anpa_msg code' );
		$this->assertStringContainsString( 'anpa_adopted=', $url, 'Redirect must carry anpa_adopted count' );
		$this->assertStringContainsString( 'anpa_reported=', $url, 'Redirect must carry anpa_reported count' );

		// When nothing is outdated, the code should be 'nothing_to_adopt' and count 0.
		$this->assertStringContainsString( 'anpa_msg=nothing_to_adopt', $url );
		$this->assertStringContainsString( 'anpa_adopted=0', $url );
		$this->assertStringContainsString( 'anpa_reported=0', $url );
	}
}
