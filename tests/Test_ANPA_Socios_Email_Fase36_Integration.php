<?php
/**
 * TDD: FASE36 Integration — ANPA_Socios_Email + Template Render Provider.
 *
 * Verifies the template system integrates with existing transactional emails
 * through the FASE35 render provider contract.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-render-provider.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-template-render-provider.php';

final class Test_ANPA_Socios_Email_Fase36_Integration extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'anpa_socios_email_templates' );
	}

	/**
	 * The template provider produces a valid render for known templates.
	 */
	public function test_template_provider_renders_known_template(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$result   = $provider->render(
			'verification_code',
			'verification_code',
			array(
				'association_name' => 'ANPA Test',
				'nome'             => 'Xoan',
				'codigo'           => '123456',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'subject', $result );
		$this->assertArrayHasKey( 'body_html', $result );
		$this->assertArrayHasKey( 'body_text', $result );
		$this->assertStringContainsString( 'ANPA Test', $result['subject'] );
		$this->assertStringContainsString( 'Xoan', $result['body_html'] );
		$this->assertStringContainsString( '123456', $result['body_html'] );
	}

	/**
	 * The passthrough fallback for empty template_ref preserves legacy content.
	 */
	public function test_template_provider_passthrough_for_empty_ref(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$result   = $provider->render(
			'custom_event',
			'',
			array(
				'subject'   => 'Legacy Subject',
				'body_html' => '<p>Legacy body</p>',
				'body_text' => 'Legacy body',
			)
		);

		$this->assertSame( 'Legacy Subject', $result['subject'] );
		$this->assertSame( '<p>Legacy body</p>', $result['body_html'] );
		$this->assertSame( 'Legacy body', $result['body_text'] );
	}

	/**
	 * Freeze with template produces immutable snapshot + hash.
	 */
	public function test_freeze_with_template_produces_snapshot_and_hash(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();

		$frozen = $this->render_and_freeze( $provider, 'verification_code', 'verification_code', array(
			'association_name' => 'ANPA Test',
			'nome'             => 'Xoan',
			'codigo'           => '123456',
		) );

		$this->assertArrayHasKey( 'subject', $frozen );
		$this->assertArrayHasKey( 'snapshot', $frozen );
		$this->assertArrayHasKey( 'payload_hash', $frozen );
		$this->assertNotEmpty( $frozen['payload_hash'] );
		$this->assertStringContainsString( 'ANPA Test', $frozen['subject'] );

		// Verify snapshot is valid JSON.
		$snapshot = json_decode( $frozen['snapshot'], true );
		$this->assertIsArray( $snapshot );
		$this->assertSame( 'verification_code', $snapshot['event'] );
		$this->assertSame( 'verification_code', $snapshot['template'] );
	}

	/**
	 * Freeze is idempotent for same context.
	 */
	public function test_freeze_is_idempotent_for_same_context(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();

		$ctx = array(
			'association_name' => 'ANPA Test',
			'nome'             => 'Xoan',
			'codigo'           => '123456',
		);

		$first  = $this->render_and_freeze( $provider, 'verification_code', 'verification_code', $ctx );
		$second = $this->render_and_freeze( $provider, 'verification_code', 'verification_code', $ctx );

		$this->assertSame( $first['payload_hash'], $second['payload_hash'] );
	}

	/**
	 * Template edit does not affect already-frozen snapshot.
	 */
	public function test_template_edit_does_not_affect_frozen_snapshot(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();

		$ctx = array(
			'association_name' => 'ANPA Test',
			'nome'             => 'Xoan',
			'codigo'           => '123456',
		);

		// Freeze the message first.
		$frozen = $this->render_and_freeze( $provider, 'verification_code', 'verification_code', $ctx );
		$frozen_json = $frozen['snapshot'];

		// Customize the template AFTER freeze via option (bypassing capability check).
		update_option( 'anpa_socios_email_templates', array(
			'verification_code' => array(
				'subject' => 'CUSTOM SUBJECT',
				'html'    => '<p>CUSTOM BODY</p>',
				'text'    => 'CUSTOM TEXT',
			),
		) );

		// The frozen snapshot should still contain the original content.
		$decoded = json_decode( $frozen_json, true );
		$this->assertStringContainsString( 'ANPA Test', $decoded['subject'] );
		$this->assertStringNotContainsString( 'CUSTOM', $decoded['subject'] );
	}

	/**
	 * The template provider implements the FASE35 interface.
	 */
	public function test_provider_implements_fase35_interface(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$this->assertInstanceOf(
			ANPA_Socios_Email_Render_Provider_Interface::class,
			$provider
		);
	}

	/**
	 * Helper: render and freeze a message snapshot.
	 */
	private function render_and_freeze( $provider, $event, $template_ref, $ctx ): array {
		$rendered = $provider->render( $event, $template_ref, $ctx );
		$snapshot = array(
			'v'         => 1,
			'event'     => $event,
			'template'  => $template_ref,
			'subject'   => $rendered['subject'],
			'body_html' => $rendered['body_html'],
			'body_text' => $rendered['body_text'],
		);
		$json = wp_json_encode( $snapshot );
		return array(
			'subject'      => $snapshot['subject'],
			'snapshot'     => $json,
			'payload_hash' => hash( 'sha256', $json ),
		);
	}
}
