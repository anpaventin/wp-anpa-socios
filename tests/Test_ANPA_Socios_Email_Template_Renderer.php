<?php
/**
 * TDD RED: ANPA_Socios_Email_Template_Renderer
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';

final class Test_ANPA_Socios_Email_Template_Renderer extends TestCase {

	/**
	 * RED: render() returns subject, html, text with variables replaced.
	 */
	public function test_render_replaces_variables(): void {
		delete_option( 'anpa_socios_email_templates' );
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', array(
			'nome'               => 'Xoan',
			'codigo'             => '123456',
			'association_name'   => 'ANPA As Brañas',
		) );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'subject', $result );
		$this->assertArrayHasKey( 'html', $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertStringContainsString( 'Xoan', $result['subject'] );
		$this->assertStringContainsString( '123456', $result['html'] );
	}

	/**
	 * RED: unknown variables are replaced with empty string.
	 */
	public function test_render_handles_unknown_variables(): void {
		delete_option( 'anpa_socios_email_templates' );
		$template = array(
			'subject' => 'Hello {{unknown}}',
			'html'    => '<p>{{unknown}}</p>',
			'text'    => '{{unknown}}',
		);
		$result = ANPA_Socios_Email_Template_Renderer::render( 'custom', array(), $template );
		$this->assertStringNotContainsString( '{{unknown}}', $result['subject'] );
	}

	/**
	 * RED: HTML is escaped.
	 */
	public function test_render_escapes_html(): void {
		delete_option( 'anpa_socios_email_templates' );
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', array(
			'nome' => '<script>alert(1)</script>',
		) );
		$this->assertStringNotContainsString( '<script>', $result['html'] );
	}

	/**
	 * RED: URL variables use esc_url.
	 */
	public function test_render_escapes_url(): void {
		delete_option( 'anpa_socios_email_templates' );
		$result = ANPA_Socios_Email_Template_Renderer::render( 'pendente_aprobacion', array(
			'login_url' => 'http://example.org/wp-admin"><script>',
		) );
		$this->assertStringNotContainsString( '<script>', $result['html'] );
	}

	/**
	 * RED: plain text is derived from HTML.
	 */
	public function test_render_derives_text_from_html(): void {
		delete_option( 'anpa_socios_email_templates' );
		$template = array(
			'subject' => 'Test',
			'html'    => '<p>Hello <strong>World</strong></p>',
			'text'    => '',
		);
		$result = ANPA_Socios_Email_Template_Renderer::render( 'custom', array(), $template );
		$this->assertStringContainsString( 'Hello World', $result['text'] );
		$this->assertStringNotContainsString( '<strong>', $result['text'] );
	}

	/**
	 * RED: custom text is preserved if provided.
	 */
	public function test_render_preserves_custom_text(): void {
		delete_option( 'anpa_socios_email_templates' );
		$template = array(
			'subject' => 'Test',
			'html'    => '<p>HTML</p>',
			'text'    => 'Custom plain text',
		);
		$result = ANPA_Socios_Email_Template_Renderer::render( 'custom', array(), $template );
		$this->assertSame( 'Custom plain text', $result['text'] );
	}

	/**
	 * RED: empty context still works.
	 */
	public function test_render_with_empty_context(): void {
		delete_option( 'anpa_socios_email_templates' );
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', array() );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['subject'] );
	}
}
