<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-templates-page.php';

/**
 * TDD: FASE36 Hotfix - admin_post_url() → admin-url('admin-post.php')
 *
 * These tests MUST fail BEFORE the fix and PASS AFTER the fix.
 */
final class Test_ANPA_Socios_Admin_URL_Correctness extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'anpa_socios_email_templates' );

		// Stub WP functions if not running inside WP.
		if ( ! function_exists( 'admin_url' ) ) {
			function admin_url( $path ) {
				return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
			}
		}
		if ( ! function_exists( 'wp_nonce_url' ) ) {
			function wp_nonce_url( $url, $action ) {
				return add_query_arg( '_wpnonce', 'test_nonce', $url );
			}
		}
	}

	/**
	 * RED TEST: The fatal admin_post_url() does NOT exist in WP.
	 */
	public function test_admin_post_url_does_not_exist(): void {
		$this->assertFalse(
			function_exists( 'admin_post_url' ),
			'admin_post_url() must NOT exist in WordPress (this is the bug)'
		);
	}

	/**
	 * GREEN TEST: Edit form action must use admin-post.php URL.
	 */
	public function test_edit_form_uses_admin_post_php(): void {
		if ( ! function_exists( 'current_user_can' ) ) {
			eval( 'function current_user_can( $cap ) { return true; }' );
		}
		if ( ! function_exists( 'wp_nonce_field' ) ) {
			eval( 'function wp_nonce_field( $action, $name ) { echo "<input type=\"hidden\" name=\"$name\" value=\"x\" />"; }' );
		}
		if ( ! function_exists( 'esc_html' ) ) {
			eval( 'function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, "UTF-8" ); }' );
		}
		if ( ! function_exists( 'esc_attr' ) ) {
			eval( 'function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES, "UTF-8" ); }' );
		}
		if ( ! function_exists( 'esc_textarea' ) ) {
			eval( 'function esc_textarea( $s ) { return htmlspecialchars( $s, ENT_QUOTES, "UTF-8" ); }' );
		}
		if ( ! function_exists( 'esc_url' ) ) {
			eval( 'function esc_url( $s ) { return $s; }' );
		}
		if ( ! function_exists( 'wp_kses_post' ) ) {
			eval( 'function wp_kses_post( $s ) { return $s; }' );
		}
		if ( ! function_exists( '__' ) ) {
			eval( 'function __( $text, $domain = "default" ) { return $text; }' );
		}
		if ( ! function_exists( 'sprintf' ) ) {
			// sprintf exists natively.
		}
		if ( ! function_exists( 'method_exists' ) ) {
			// method_exists exists natively.
		}

		ob_start();
		ANPA_Socios_Email_Templates_Page::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'admin-post.php?action=anpa_save_template',
			$output,
			'Edit form action must use admin-post.php?action=anpa_save_template'
		);
	}

	/**
	 * GREEN TEST: Restore action must use admin-post.php URL.
	 */
	public function test_restore_action_uses_admin_post_php(): void {
		// Seed a custom template so the "Restaurar" button appears.
		ANPA_Socios_Email_Template_Store::save(
			'verification_code',
			'Custom Subject',
			'<p>Custom</p>',
			'Custom Text'
		);

		if ( ! function_exists( 'current_user_can' ) ) {
			eval( 'function current_user_can( $cap ) { return true; }' );
		}
		if ( ! function_exists( 'esc_html' ) ) {
			eval( 'function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, "UTF-8" ); }' );
		}
		if ( ! function_exists( 'esc_url' ) ) {
			eval( 'function esc_url( $s ) { return $s; }' );
		}
		if ( ! function_exists( '__' ) ) {
			eval( 'function __( $text, $domain = "default" ) { return $text; }' );
		}

		ob_start();
		ANPA_Socios_Email_Templates_Page::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'admin-post.php?action=anpa_restore_template_verification_code',
			$output,
			'Restore link must use admin-post.php?action=anpa_restore_template_<id>'
		);
	}
}
