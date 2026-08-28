<?php
/**
 * TDD: ANPA_Socios_Email_Template_Store
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';

final class Test_ANPA_Socios_Email_Template_Store extends TestCase {

	public function test_get_returns_default_when_no_option(): void {
		delete_option( 'anpa_socios_email_templates' );
		$result = ANPA_Socios_Email_Template_Store::get( 'verification_code' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'subject', $result );
		$this->assertArrayHasKey( 'html', $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertStringContainsString( '%s', $result['subject'] );
	}

	public function test_get_returns_custom_when_exists(): void {
		update_option( 'anpa_socios_email_templates', array(
			'verification_code' => array(
				'subject' => 'Custom Subject',
				'html'    => '<p>Custom HTML</p>',
				'text'    => 'Custom Text',
			),
		));
		$result = ANPA_Socios_Email_Template_Store::get( 'verification_code' );
		$this->assertSame( 'Custom Subject', $result['subject'] );
		$this->assertSame( '<p>Custom HTML</p>', $result['html'] );
		$this->assertSame( 'Custom Text', $result['text'] );
	}

	public function test_get_all_returns_all_templates(): void {
		delete_option( 'anpa_socios_email_templates' );
		$all = ANPA_Socios_Email_Template_Store::get_all();
		$this->assertIsArray( $all );
		$this->assertCount( 10, $all );
		$expected_ids = array(
			'verification_code', 'baixa_socio', 'reactivacion',
			'baixa_extraescolar', 'oferta_extraescolar', 'pendente_aprobacion',
			'aprobacion', 'benvida_alta', 'rexeitamento', 'send_from_master',
		);
		foreach ( $expected_ids as $id ) {
			$this->assertArrayHasKey( $id, $all );
		}
	}

	public function test_save_stores_template(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return true;
			}
		}
		delete_option( 'anpa_socios_email_templates' );
		ANPA_Socios_Email_Template_Store::save( 'verification_code', 'Test Subject', '<p>Test</p>', 'Test' );
		$stored = get_option( 'anpa_socios_email_templates' );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'verification_code', $stored );
		$this->assertSame( 'Test Subject', $stored['verification_code']['subject'] );
	}

	public function test_save_fails_without_capability(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return false;
			}
		}
		$result = ANPA_Socios_Email_Template_Store::save( 'verification_code', 'Subj', '<p>html</p>', 'text' );
		$this->assertFalse( $result );
	}

	public function test_delete_removes_template(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return true;
			}
		}
		update_option( 'anpa_socios_email_templates', array(
			'verification_code' => array( 'subject' => 'X', 'html' => 'Y', 'text' => 'Z' ),
		) );
		ANPA_Socios_Email_Template_Store::delete( 'verification_code' );
		$this->assertFalse( get_option( 'anpa_socios_email_templates' ) );
	}

	public function test_restore_all_clears_templates(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $cap ) {
				return true;
			}
		}
		update_option( 'anpa_socios_email_templates', array(
			'verification_code' => array( 'subject' => 'X', 'html' => 'Y', 'text' => 'Z' ),
		) );
		ANPA_Socios_Email_Template_Store::restore_all();
		$this->assertFalse( get_option( 'anpa_socios_email_templates' ) );
	}

	public function test_get_variables_returns_ordered_keys(): void {
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'verification_code' );
		$this->assertIsArray( $vars );
		$this->assertArrayHasKey( 'subject', $vars );
		$this->assertArrayHasKey( 'html', $vars );
		$this->assertArrayHasKey( 'text', $vars );
		$this->assertSame( array( 'association_name' ), $vars['subject'] );
		$this->assertSame( array( 'nome', 'codigo' ), $vars['html'] );
	}
}
