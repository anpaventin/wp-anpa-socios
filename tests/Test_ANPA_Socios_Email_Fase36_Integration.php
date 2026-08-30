<?php
/**
 * TDD RED: FASE36 Integration — ANPA_Socios_Email + Template Render Provider.
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

/**
 * Stub WordPress filter system for testing provider registration.
 */
$GLOBALS['wp_filters'] = array();

function apply_filters() {
	$args = func_get_args();
	$tag  = array_shift( $args );
	if ( ! isset( $GLOBALS['wp_filters'][ $tag ] ) ) {
		return $args[0] ?? null;
	}
	foreach ( $GLOBALS['wp_filters'][ $tag ] as $callback ) {
		$result = call_user_func_array( $callback, $args );
		if ( null !== $result ) {
			return $result;
		}
	}
	return $args[0] ?? null;
}

function add_filter( $tag, $callback ) {
	$GLOBALS['wp_filters'][ $tag ][] = $callback;
}

function reset_filters() {
	$GLOBALS['wp_filters'] = array();
}

final class Test_ANPA_Socios_Email_Fase36_Integration extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		reset_filters();
		delete_option( 'anpa_socios_email_templates' );
	}

	public function test_template_provider_registered_via_filter(): void {
		// Register provider (this is what plugin init would do).
		add_filter(
			'anpa_socios_email_render_provider',
			function () {
				return new ANPA_Socios_Email_Template_Render_Provider();
			}
		);

		$provider = ANPA_Socios_Email_Render::provider();
		$this->assertInstanceOf(
			ANPA_Socios_Email_Template_Render_Provider::class,
			$provider
		);
	}

	public function test_freeze_with_template_produces_snapshot_and_hash(): void {
		add_filter(
			'anpa_socios_email_render_provider',
			function () {
				return new ANPA_Socios_Email_Template_Render_Provider();
			}
		);

		$frozen = ANPA_Socios_Email_Render::freeze(
			'verification_code',
			'verification_code',
			array(
				'association_name' => 'ANPA Test',
				'nome'             => 'Xoan',
				'codigo'           => '123456',
			)
		);

		$this->assertArrayHasKey( 'subject', $frozen );
		$this->assertArrayHasKey( 'snapshot', $frozen );
		$this->assertArrayHasKey( 'payload_hash', $frozen );
		$this->assertArrayHasKey( 'payload_version', $frozen );
		$this->assertSame( 1, $frozen['payload_version'] );
		$this->assertNotEmpty( $frozen['payload_hash'] );
		$this->assertStringContainsString( 'ANPA Test', $frozen['subject'] );

		// Verify snapshot is valid JSON.
		$snapshot = json_decode( $frozen['snapshot'], true );
		$this->assertIsArray( $snapshot );
		$this->assertSame( 'verification_code', $snapshot['event'] );
		$this->assertSame( 'verification_code', $snapshot['template'] );
	}

	public function test_freeze_with_empty_template_ref_uses_passthrough(): void {
		add_filter(
			'anpa_socios_email_render_provider',
			function () {
				return new ANPA_Socios_Email_Template_Render_Provider();
			}
		);

		$frozen = ANPA_Socios_Email_Render::freeze(
			'custom_event',
			'',
			array(
				'subject'   => 'Hardcoded Subject',
				'body_html' => '<p>Hardcoded body</p>',
				'body_text' => 'Hardcoded body',
			)
		);

		$this->assertSame( 'Hardcoded Subject', $frozen['subject'] );
		$snapshot = json_decode( $frozen['snapshot'], true );
		$this->assertSame( '<p>Hardcoded body</p>', $snapshot['body_html'] );
		$this->assertSame( 'Hardcoded body', $snapshot['body_text'] );
	}

	public function test_thaw_reverses_freeze(): void {
		add_filter(
			'anpa_socios_email_render_provider',
			function () {
				return new ANPA_Socios_Email_Template_Render_Provider();
			}
		);

		$context = array(
			'association_name' => 'ANPA Test',
			'nome'             => 'Xoan',
			'codigo'           => '123456',
		);

		$frozen = ANPA_Socios_Email_Render::freeze( 'verification_code', 'verification_code', $context );
		$thawed = ANPA_Socios_Email_Render::thaw( $frozen['snapshot'] );

		$this->assertIsArray( $thawed );
		$this->assertStringContainsString( 'ANPA Test', $thawed['subject'] );
		$this->assertStringContainsString( 'Xoan', $thawed['body_html'] );
	}

	public function test_freeze_is_idempotent_for_same_context(): void {
		add_filter(
			'anpa_socios_email_render_provider',
			function () {
				return new ANPA_Socios_Email_Template_Render_Provider();
			}
		);

		$context = array(
			'association_name' => 'ANPA Test',
			'nome'             => 'Xoan',
			'codigo'           => '123456',
		);

		$first  = ANPA_Socios_Email_Render::freeze( 'verification_code', 'verification_code', $context );
		$second = ANPA_Socios_Email_Render::freeze( 'verification_code', 'verification_code', $context );

		$this->assertSame( $first['payload_hash'], $second['payload_hash'] );
	}

	public function test_template_edit_does_not_affect_frozen_message(): void {
		add_filter(
			'anpa_socios_email_render_provider',
			function () {
				return new ANPA_Socios_Email_Template_Render_Provider();
			}
		);

		$context = array(
			'association_name' => 'ANPA Test',
			'nome'             => 'Xoan',
			'codigo'           => '123456',
		);

		$frozen = ANPA_Socios_Email_Render::freeze( 'verification_code', 'verification_code', $context );

		// Customize the template AFTER freeze.
		ANPA_Socios_Email_Template_Store::save(
			'verification_code',
			'CUSTOM SUBJECT',
			'<p>CUSTOM BODY</p>',
			'CUSTOM TEXT'
		);

		// The frozen snapshot should still contain the original content.
		$thawed = ANPA_Socios_Email_Render::thaw( $frozen['snapshot'] );
		$this->assertStringContainsString( 'ANPA Test', $thawed['subject'] );
		$this->assertStringNotContainsString( 'CUSTOM', $thawed['subject'] );
	}
}
