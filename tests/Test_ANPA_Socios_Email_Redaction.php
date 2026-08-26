<?php
/**
 * F35-SEC-001 TDD: redact_error() must redact email addresses.
 * Pure test — no WordPress bootstrap required.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Load only the class under test.
require_once __DIR__ . '/../includes/class-anpa-socios-email-queue-repo.php';

final class Test_ANPA_Socios_Email_Redaction extends TestCase {

	/** Invoke the private redact_error() method via reflection. */
	private function invoke_redact( string $raw ): string {
		$method = new ReflectionMethod( 'ANPA_Socios_Email_Queue_Repo', 'redact_error' );
		$method->setAccessible( true );
		return $method->invoke( null, $raw );
	}

	/** RED: simple email address must not persist. */
	public function test_redact_simple_email(): void {
		$result = $this->invoke_redact( 'SMTP failed for usuario@example.com' );
		$this->assertStringNotContainsString( 'usuario@example.com', $result );
		$this->assertStringContainsString( '[redacted-email]', $result );
	}

	/** RED: mixed-case email. */
	public function test_redact_mixed_case_email(): void {
		$result = $this->invoke_redact( 'Error sending to User@Example.COM' );
		$this->assertStringNotContainsString( 'User@Example.COM', $result );
		$this->assertStringNotContainsString( 'user@example.com', $result );
	}

	/** RED: multiple emails. */
	public function test_redact_multiple_emails(): void {
		$result = $this->invoke_redact( 'Failed: a@b.com and c@d.org' );
		$this->assertStringNotContainsString( '@', $result );
	}

	/** RED: email with dots and plus. */
	public function test_redact_email_with_subaddressing(): void {
		$result = $this->invoke_redact( 'Error for user+tag@sub.domain.co.uk' );
		$this->assertStringNotContainsString( 'user+tag@sub.domain.co.uk', $result );
	}

	/** Non-PII technical errors remain useful. */
	public function test_no_corruption_of_technical_message(): void {
		$result = $this->invoke_redact( 'SMTP connection timed out after 30 seconds' );
		$this->assertStringContainsString( 'SMTP', $result );
		$this->assertStringContainsString( '30', $result );
	}

	/** Whitespace normalization still works. */
	public function test_whitespace_normalized(): void {
		$result = $this->invoke_redact( "line1\nline2  spaced" );
		$this->assertStringNotContainsString( "\n", $result );
	}

	/** 255 char limit still respected. */
	public function test_length_limit_respected(): void {
		$long = str_repeat( 'x', 300 );
		$result = $this->invoke_redact( $long );
		$this->assertLessThanOrEqual( 255, strlen( $result ) );
	}

	/** Empty string stays empty. */
	public function test_empty_string(): void {
		$result = $this->invoke_redact( '' );
		$this->assertSame( '', $result );
	}
}
