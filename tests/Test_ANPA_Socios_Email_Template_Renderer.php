<?php
/**
 * TDD: ANPA_Socios_Email_Template_Renderer
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';

final class Test_ANPA_Socios_Email_Template_Renderer extends TestCase {

	public function test_render_replaces_single_variable(): void {
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'verification_code' );
		$context = array_combine( $vars['subject'], array( 'ANPA As Brañas' ) );
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', $context );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'ANPA As Brañas', $result['subject'] );
	}

	public function test_render_replaces_multiple_variables(): void {
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'verification_code' );
		$context = array(
			'association_name' => 'ANPA',
			'nome'             => 'Xoan',
			'codigo'           => '123456',
		);
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', $context );
		$this->assertStringContainsString( 'Xoan', $result['subject'] );
		$this->assertStringContainsString( 'Xoan', $result['html'] );
		$this->assertStringContainsString( '123456', $result['html'] );
		$this->assertStringContainsString( '123456', $result['text'] );
	}

	public function test_render_handles_unknown_variables(): void {
		$context = array( 'unknown_var' => 'value' );
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', $context );
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( 'value', $result['subject'] );
	}

	public function test_render_escapes_html(): void {
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'verification_code' );
		$context = array(
			'association_name' => '<script>alert(1)</script>',
			'nome'             => '<b>Xoan</b>',
			'codigo'           => '123456',
		);
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', $context );
		$this->assertStringNotContainsString( '<script>', $result['html'] );
		$this->assertStringNotContainsString( '<b>', $result['text'] );
	}

	public function test_render_with_empty_context(): void {
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', array() );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['subject'] );
	}

	public function test_render_text_contains_plain_text(): void {
		$context = array(
			'association_name' => 'ANPA',
			'nome'             => 'Xoan',
			'codigo'           => '123456',
		);
		$result = ANPA_Socios_Email_Template_Renderer::render( 'verification_code', $context );
		$this->assertStringNotContainsString( '<p>', $result['text'] );
		$this->assertStringContainsString( 'Xoan', $result['text'] );
	}

	public function test_all_templates_render_without_error(): void {
		$templates = ANPA_Socios_Email_Template_Store::get_all();
		foreach ( $templates as $id => $template ) {
			$vars = ANPA_Socios_Email_Template_Store::get_variables( $id );
			$context = array();
			foreach ( $vars as $field => $keys ) {
				foreach ( $keys as $key ) {
					$context[ $key ] = 'test_' . $key;
				}
			}
			$result = ANPA_Socios_Email_Template_Renderer::render( $id, $context );
			$this->assertIsArray( $result, "Template $id should return array" );
			$this->assertArrayHasKey( 'subject', $result, "Template $id missing subject" );
			$this->assertArrayHasKey( 'html', $result, "Template $id missing html" );
			$this->assertArrayHasKey( 'text', $result, "Template $id missing text" );
		}
	}
}
