<?php
/**
 * TDD RED: ANPA_Socios_Email_Template_Render_Provider
 *
 * Tests the FASE36 provider implements the FASE35 contract.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-render-provider.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-template-render-provider.php';

final class Test_ANPA_Socios_Email_Template_Render_Provider extends TestCase {

	public function test_provider_implements_interface(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$this->assertInstanceOf(
			ANPA_Socios_Email_Render_Provider_Interface::class,
			$provider
		);
	}

	public function test_render_with_template_ref_replaces_variables(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$result   = $provider->render(
			'verificacion',
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

	public function test_render_with_empty_template_ref_falls_back_to_passthrough(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$result   = $provider->render(
			'custom_event',
			'',
			array(
				'subject'   => 'Hardcoded Subject',
				'body_html' => '<p>Hardcoded body</p>',
				'body_text' => 'Hardcoded body',
			)
		);

		$this->assertSame( 'Hardcoded Subject', $result['subject'] );
		$this->assertSame( '<p>Hardcoded body</p>', $result['body_html'] );
		$this->assertSame( 'Hardcoded body', $result['body_text'] );
	}

	public function test_render_passthrough_derives_text_from_html(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$result   = $provider->render(
			'custom_event',
			'',
			array(
				'subject'   => 'Subject',
				'body_html' => '<p>Hello World</p>',
			)
		);

		$this->assertSame( 'Hello World', $result['body_text'] );
	}

	public function test_render_with_unknown_template_returns_empty(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$result   = $provider->render(
			'verificacion',
			'template_inexistente',
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['subject'] );
		$this->assertSame( '', $result['body_html'] );
		$this->assertSame( '', $result['body_text'] );
	}

	public function test_render_escapes_html_in_variables(): void {
		$provider = new ANPA_Socios_Email_Template_Render_Provider();
		$result   = $provider->render(
			'verificacion',
			'verification_code',
			array(
				'association_name' => '<script>alert(1)</script>',
				'nome'             => '<b>Xoan</b>',
				'codigo'           => '123456',
			)
		);

		$this->assertStringNotContainsString( '<script>', $result['subject'] );
		$this->assertStringNotContainsString( '<b>', $result['body_text'] );
	}
}
