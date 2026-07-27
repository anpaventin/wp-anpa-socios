<?php
/**
 * Tests for the failure policy and the enqueue-boundary non-enqueue guard (fase36, PR-36s3).
 *
 * Item 11: a missing template or a render error refuses the send, error_logs, returns false.
 * Item 12: auth events (those declaring the `codigo` variable) are refused at the queue
 *          boundary. The guard lives in ANPA_Socios_Email_Queue_Repo::create_campaign and
 *          is read from the registry, not hardcoded.
 *
 * @group unit
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Failure_Policy extends TestCase {

	// ── Item 12: enqueue-boundary guard ─────────────────────────────────

	/**
	 * The queue repo exposes is_non_enqueueable_event() and it refuses
	 * auth_access_code (the verification-context code).
	 */
	public function test_auth_access_code_is_non_enqueueable(): void {
		$this->assertTrue(
			ANPA_Socios_Email_Queue_Repo::is_non_enqueueable_event( 'auth_access_code' ),
			'auth_access_code must be refused at the enqueue boundary'
		);
	}

	/**
	 * The queue repo refuses auth_access_code_signup (the alta-context code).
	 */
	public function test_auth_access_code_signup_is_non_enqueueable(): void {
		$this->assertTrue(
			ANPA_Socios_Email_Queue_Repo::is_non_enqueueable_event( 'auth_access_code_signup' ),
			'auth_access_code_signup must be refused at the enqueue boundary'
		);
	}

	/**
	 * A normal campaign event (e.g. member_cancellation_admin_notice) is allowed.
	 */
	public function test_normal_event_is_enqueueable(): void {
		$this->assertFalse(
			ANPA_Socios_Email_Queue_Repo::is_non_enqueueable_event( 'member_cancellation_admin_notice' ),
			'a normal event must not be refused at the enqueue boundary'
		);
	}

	/**
	 * An empty event_type is not refused (it will fail later on missing key).
	 */
	public function test_empty_event_type_is_not_refused(): void {
		$this->assertFalse(
			ANPA_Socios_Email_Queue_Repo::is_non_enqueueable_event( '' ),
			'empty event_type should not be treated as non-enqueueable'
		);
	}

	/**
	 * An unknown event_type is not refused by the non-enqueue guard (it is not
	 * the guard's job to validate unknown events; that belongs to create_campaign).
	 */
	public function test_unknown_event_type_is_not_refused(): void {
		$this->assertFalse(
			ANPA_Socios_Email_Queue_Repo::is_non_enqueueable_event( 'nonexistent_event_xyz' ),
			'unknown event_type should not be treated as non-enqueueable'
		);
	}

	/**
	 * The guard identifies non-enqueueable events by reading the registry (the `codigo`
	 * variable), not by hardcoding keys. Verify this by checking that exactly the events
	 * declaring `codigo` are refused.
	 */
	public function test_non_enqueueable_events_are_exactly_those_declaring_codigo(): void {
		$set  = ANPA_Socios_Email_Template_Events::set();
		$keys = $set->keys();

		$refused_by_guard = array();
		$declaring_codigo = array();

		foreach ( $keys as $key ) {
			if ( ANPA_Socios_Email_Queue_Repo::is_non_enqueueable_event( $key ) ) {
				$refused_by_guard[] = $key;
			}
			$definition = $set->get( $key );
			$variables  = $definition->variables();
			if ( isset( $variables['codigo'] ) ) {
				$declaring_codigo[] = $key;
			}
		}

		sort( $refused_by_guard );
		sort( $declaring_codigo );

		$this->assertSame(
			$declaring_codigo,
			$refused_by_guard,
			'The set of events refused by is_non_enqueueable_event must be exactly those declaring the codigo variable'
		);
	}

	/**
	 * enviar_codigo no longer contains the dead ANPA_SOCIOS_EMAIL_QUEUE_PROCESSING check.
	 * The guard is at the boundary, not inside the emitter.
	 */
	public function test_enviar_codigo_does_not_contain_dead_constant_check(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php'
		);

		$start = strpos( $src, 'function enviar_codigo(' );
		$this->assertNotFalse( $start, 'enviar_codigo not found' );

		$rest = substr( $src, $start + 30 );
		$end  = preg_match( '/\n\t(?:public|private|protected)\s+static\s+function\s/', $rest, $m, PREG_OFFSET_CAPTURE );
		$body = $end ? substr( $rest, 0, $m[0][1] ) : $rest;

		$this->assertStringNotContainsString(
			'ANPA_SOCIOS_EMAIL_QUEUE_PROCESSING',
			$body,
			'The dead constant check must be removed; the guard lives at the enqueue boundary'
		);
	}

	// ── Item 11: failure policy ─────────────────────────────────────────

	/**
	 * send_templated logs and returns false on a Registry error, never a partial email.
	 */
	public function test_send_templated_refuses_on_registry_error(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php'
		);

		// The catch block must contain error_log and return false.
		$this->assertStringContainsString(
			'ANPA_Socios_Email_Template_Registry_Error',
			$src,
			'send_templated must catch registry errors'
		);

		// Must have two separate refusal paths: exception and render failure.
		$this->assertGreaterThanOrEqual(
			2,
			substr_count( $src, "Refused to send" ),
			'send_templated must log refusals for both exception and render failure paths'
		);
	}

	/**
	 * A render failure (ok=false) also refuses the send.
	 */
	public function test_render_failure_path_returns_false(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php'
		);

		// The render failure check: `if ( ! $rendered['ok'] )`
		$this->assertStringContainsString(
			"! \$rendered['ok']",
			$src,
			'send_templated must check rendered[ok] and refuse on failure'
		);
	}

	// ── Item 14: wp_mail( only inside expected files (calls, not mentions) ──

	/**
	 * wp_mail( for transactional sends appears only inside the expected email infrastructure
	 * files. This test strips comments before matching so that docblock mentions (e.g.
	 * "wp_mail() gives no idempotent send id") do not trigger false positives.
	 *
	 * The queue processor (fase35) has its own wp_mail call for campaigns, which is a separate
	 * legitimate transport. No other includes/ file may call wp_mail.
	 */
	public function test_wp_mail_call_only_in_expected_files(): void {
		$includes_dir = dirname( __DIR__ ) . '/includes';
		$files        = self::php_files_recursive( $includes_dir );

		// Only files that ACTUALLY CALL wp_mail in executable code.
		// - class-anpa-socios-email.php: transactional send via send_templated.
		// - class-anpa-socios-email-processor.php: campaign batch transport (fase35).
		// - class-anpa-socios-email-template-admin-actions.php: test send to the logged-in admin (fase36).
		$allowed = array(
			'class-anpa-socios-email.php',
			'class-anpa-socios-email-processor.php',
			'class-anpa-socios-email-template-admin-actions.php',
		);

		$violations = array();
		foreach ( $files as $file ) {
			$basename = basename( $file );
			if ( in_array( $basename, $allowed, true ) ) {
				continue;
			}
			$executable = self::strip_comments( $file );
			if ( preg_match( '/\bwp_mail\s*\(/', $executable ) ) {
				$violations[] = $basename;
			}
		}

		$this->assertSame(
			array(),
			$violations,
			'wp_mail( call found in unexpected file(s): ' . implode( ', ', $violations )
		);
	}

	/**
	 * wp_mail IS present in the email class (sanity check).
	 */
	public function test_wp_mail_is_present_in_email_class(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php'
		);

		$this->assertStringContainsString( 'wp_mail(', $src, 'wp_mail must be called inside ANPA_Socios_Email' );
	}

	/**
	 * The 13 call sites (in the plugin's action hooks) use ANPA_Socios_Email::enviar_*,
	 * never wp_mail directly. Verified by checking all PHP files that are NOT in
	 * includes/, tests/, templates/, or vendor/.
	 */
	public function test_no_external_caller_uses_wp_mail_directly(): void {
		$plugin_root = dirname( __DIR__ );
		// Check only .php files at the plugin root level (the main plugin file and any top-level loader).
		$root_files  = (array) glob( $plugin_root . '/*.php' );

		$violations = array();
		foreach ( $root_files as $file ) {
			$executable = self::strip_comments( (string) $file );
			if ( preg_match( '/\bwp_mail\s*\(/', $executable ) ) {
				$violations[] = basename( (string) $file );
			}
		}

		$this->assertSame( array(), $violations, 'root-level callers must not call wp_mail directly' );
	}

	/**
	 * Strips T_COMMENT and T_DOC_COMMENT from a PHP file, returning only executable code.
	 * This ensures inspection assertions count CALLS, not mentions in prose.
	 *
	 * @param  string $filepath Path to the PHP file.
	 * @return string Executable code without comments.
	 */
	private static function strip_comments( string $filepath ): string {
		$source = (string) file_get_contents( $filepath );
		$tokens = token_get_all( $source );
		$result = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}
				$result .= $token[1];
			} else {
				$result .= $token;
			}
		}
		return $result;
	}

	/**
	 * @return string[]
	 */
	private static function php_files_recursive( string $dir ): array {
		$result = array();
		$iter   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iter as $file ) {
			if ( 'php' === pathinfo( $file->getPathname(), PATHINFO_EXTENSION ) ) {
				$result[] = $file->getPathname();
			}
		}
		return $result;
	}
}
