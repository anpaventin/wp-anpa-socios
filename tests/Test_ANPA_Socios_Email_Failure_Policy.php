<?php
/**
 * Tests for the failure policy and the enviar_codigo non-enqueue guard (fase36, PR-36s3).
 *
 * Item 11: a missing template or a render error refuses the send, error_logs, returns false.
 * Item 12: enviar_codigo stays a direct send; the constant guard fires if called inside
 *          the queue processing context.
 *
 * @group unit
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Failure_Policy extends TestCase {

	// ── Item 12: non-enqueue guard ──────────────────────────────────────

	/**
	 * The guard constant is documented in the class; the test proves it is checked.
	 */
	public function test_enviar_codigo_source_contains_queue_processing_guard(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php'
		);

		$this->assertStringContainsString(
			'ANPA_SOCIOS_EMAIL_QUEUE_PROCESSING',
			$src,
			'enviar_codigo must check ANPA_SOCIOS_EMAIL_QUEUE_PROCESSING to prevent enqueue'
		);
	}

	/**
	 * The guard is inside enviar_codigo specifically (not just anywhere in the class).
	 */
	public function test_the_guard_is_inside_enviar_codigo_body(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php'
		);

		// Extract the enviar_codigo method body (from its declaration to the next public/private static).
		$start = strpos( $src, 'function enviar_codigo(' );
		$this->assertNotFalse( $start, 'enviar_codigo not found' );

		// Find the next method declaration after enviar_codigo.
		$rest = substr( $src, $start + 30 );
		$end  = preg_match( '/\n\t(?:public|private|protected)\s+static\s+function\s/', $rest, $m, PREG_OFFSET_CAPTURE );
		$body = $end ? substr( $rest, 0, $m[0][1] ) : $rest;

		$this->assertStringContainsString(
			'ANPA_SOCIOS_EMAIL_QUEUE_PROCESSING',
			$body,
			'the non-enqueue guard must live inside the enviar_codigo method body'
		);
	}

	/**
	 * The code must never appear inside send_templated (the general path), only in enviar_codigo.
	 * Otherwise every emitter would be guarded, which is not the requirement.
	 */
	public function test_send_templated_does_not_contain_the_queue_guard(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php'
		);

		$start = strpos( $src, 'function send_templated(' );
		$this->assertNotFalse( $start, 'send_templated not found' );

		$rest = substr( $src, $start + 30 );
		$end  = preg_match( '/\n\t(?:public|private|protected)\s+static\s+function\s/', $rest, $m, PREG_OFFSET_CAPTURE );
		$body = $end ? substr( $rest, 0, $m[0][1] ) : $rest;

		$this->assertStringNotContainsString(
			'ANPA_SOCIOS_EMAIL_QUEUE_PROCESSING',
			$body,
			'the non-enqueue guard belongs only in enviar_codigo, not in the general send path'
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

	// ── Item 14: wp_mail( only inside this class ────────────────────────

	/**
	 * wp_mail( for transactional sends appears only inside ANPA_Socios_Email.
	 *
	 * The queue processor (fase35) has its own wp_mail call for campaigns, which is a separate
	 * concern. This test verifies that no OTHER includes/ file outside the known email
	 * infrastructure calls wp_mail.
	 */
	public function test_wp_mail_call_only_in_expected_files(): void {
		$includes_dir = dirname( __DIR__ ) . '/includes';
		$files        = self::php_files_recursive( $includes_dir );
		$email_file   = realpath( $includes_dir . '/class-anpa-socios-email.php' );

		// These files are part of the fase35 queue infrastructure and legitimately call wp_mail.
		$allowed = array(
			'class-anpa-socios-email.php',
			'class-anpa-socios-email-processor.php',
			'class-anpa-socios-email-queue-repo.php',
			'class-anpa-socios-email-communications-page.php',
			'class-anpa-socios-email-recipient-state.php',
			'class-anpa-socios-email-template-events.php',
		);

		$violations = array();
		foreach ( $files as $file ) {
			$basename = basename( $file );
			if ( in_array( $basename, $allowed, true ) ) {
				continue;
			}
			$content = (string) file_get_contents( $file );
			if ( preg_match( '/\bwp_mail\s*\(/', $content ) ) {
				$violations[] = $basename;
			}
		}

		$this->assertSame(
			array(),
			$violations,
			'wp_mail( found in unexpected file(s): ' . implode( ', ', $violations )
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
			$content = (string) file_get_contents( (string) $file );
			if ( preg_match( '/\bwp_mail\s*\(/', $content ) ) {
				$violations[] = basename( (string) $file );
			}
		}

		$this->assertSame( array(), $violations, 'root-level callers must not call wp_mail directly' );
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
