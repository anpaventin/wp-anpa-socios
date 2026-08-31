<?php
/**
 * TDD: ANPA_Socios_Email_Templates_Page — Admin UX
 *
 * Tests for the admin page that manages email templates.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';

final class Test_ANPA_Socios_Email_Templates_Page extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'anpa_socios_email_templates' );
	}

	public function test_class_exists(): void {
		$this->assertTrue(
			class_exists( 'ANPA_Socios_Email_Templates_Page' ),
			'ANPA_Socios_Email_Templates_Page class must exist'
		);
	}

	public function test_register_menu_method_exists(): void {
		$this->assertTrue(
			method_exists( 'ANPA_Socios_Email_Templates_Page', 'register_menu' ),
			'register_menu method must exist'
		);
	}

	public function test_slug_constant(): void {
		$this->assertSame( 'anpa-socios-templates', ANPA_Socios_Email_Templates_Page::SLUG );
	}

	public function test_cap_constant(): void {
		$this->assertSame( 'manage_options', ANPA_Socios_Email_Templates_Page::CAP );
	}

	public function test_templates_list_has_expected_ids(): void {
		$templates = ANPA_Socios_Email_Template_Store::get_all();
		$this->assertCount( 10, $templates );
		$expected_ids = array(
			'verification_code', 'baixa_socio', 'reactivacion',
			'baixa_extraescolar', 'oferta_extraescolar', 'pendente_aprobacion',
			'aprobacion', 'benvida_alta', 'rexeitamento', 'send_from_master',
		);
		foreach ( $expected_ids as $id ) {
			$this->assertArrayHasKey( $id, $templates );
		}
	}

	public function test_save_subject_via_store(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return true;
			}
		}
		$result = ANPA_Socios_Email_Template_Store::save(
			'verification_code',
			'Custom Subject',
			'<p>Custom HTML</p>',
			'Custom Text'
		);
		$this->assertTrue( $result );

		$stored = get_option( 'anpa_socios_email_templates' );
		$this->assertSame( 'Custom Subject', $stored['verification_code']['subject'] );
	}

	public function test_save_html_is_sanitized(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return true;
			}
		}
		ANPA_Socios_Email_Template_Store::save(
			'verification_code',
			'Subject',
			'<p>Safe</p><script>alert(1)</script>',
			'Text'
		);
		$stored = get_option( 'anpa_socios_email_templates' );
		$this->assertStringNotContainsString( '<script>', $stored['verification_code']['html'] );
		$this->assertStringContainsString( '<p>Safe</p>', $stored['verification_code']['html'] );
	}

	public function test_restore_single_template(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return true;
			}
		}
		ANPA_Socios_Email_Template_Store::save(
			'verification_code',
			'Custom',
			'<p>Custom</p>',
			'Custom'
		);
		ANPA_Socios_Email_Template_Store::delete( 'verification_code' );
		$templates = get_option( 'anpa_socios_email_templates' );
		$this->assertFalse( $templates );
	}

	public function test_get_variables_returns_expected_keys(): void {
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'verification_code' );
		$this->assertSame( array( 'association_name' ), $vars['subject'] );
		$this->assertSame( array( 'nome', 'codigo' ), $vars['html'] );
		$this->assertSame( array( 'nome', 'codigo' ), $vars['text'] );
	}
}
