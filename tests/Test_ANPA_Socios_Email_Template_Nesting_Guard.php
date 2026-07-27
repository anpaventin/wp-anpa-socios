<?php
/**
 * Tests for ANPA_Socios_Email_Template_Nesting_Guard.
 *
 * @since  1.48.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Nesting_Guard extends TestCase {

	// ─── Single-channel detection ────────────────────────────────────────────

	public function test_empty_body_is_not_nested(): void {
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( '' );
		$this->assertFalse( $result['nested'] );
		$this->assertSame( '', $result['error'] );
	}

	public function test_body_without_blocks_is_not_nested(): void {
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( 'Hello {{nome_socio}}, welcome!' );
		$this->assertFalse( $result['nested'] );
	}

	public function test_single_optional_block_is_not_nested(): void {
		$body = '{{#data_limite}}Prazo: {{data_limite}}{{/}}';
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( $body );
		$this->assertFalse( $result['nested'] );
	}

	public function test_sequential_blocks_are_not_nested(): void {
		$body = '{{#data_limite}}Prazo: {{data_limite}}{{/}} {{#motivo_cambio}}Motivo: {{motivo_cambio}}{{/}}';
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( $body );
		$this->assertFalse( $result['nested'] );
	}

	public function test_nested_block_is_detected(): void {
		$body = '{{#ligazon_area_socios}}Welcome {{#data_limite}}Prazo: {{data_limite}}{{/}}{{/}}';
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( $body );
		$this->assertTrue( $result['nested'] );
		$this->assertStringContainsString( 'data_limite', $result['error'] );
		$this->assertStringContainsString( 'ligazon_area_socios', $result['error'] );
	}

	public function test_nested_block_with_named_closers_is_detected(): void {
		$body = '{{#outer}}before{{#inner}}content{{/inner}}after{{/outer}}';
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( $body );
		$this->assertTrue( $result['nested'] );
		$this->assertStringContainsString( 'inner', $result['error'] );
		$this->assertStringContainsString( 'outer', $result['error'] );
	}

	public function test_opener_after_close_at_depth_zero_is_not_nesting(): void {
		// An unmatched closer followed by an opener is not nesting (renderer handles orphan closers).
		$body = '{{/}}{{#data_limite}}Prazo{{/}}';
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( $body );
		$this->assertFalse( $result['nested'] );
	}

	public function test_multiple_sequential_blocks_with_content_between(): void {
		$body = '<p>{{#ligazon_area_socios}}Link: {{ligazon_area_socios}}{{/}}</p>'
			. '<p>{{#sen_ligazon_area_socios}}No link available.{{/}}</p>'
			. '<p>{{#data_limite}}Deadline: {{data_limite}}{{/}}</p>';
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( $body );
		$this->assertFalse( $result['nested'] );
	}

	public function test_error_message_names_both_tokens(): void {
		$body = '{{#parent_token}}text {{#child_token}}nested{{/}}{{/}}';
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check( $body );
		$this->assertTrue( $result['nested'] );
		$this->assertStringContainsString( 'child_token', $result['error'] );
		$this->assertStringContainsString( 'parent_token', $result['error'] );
		$this->assertStringContainsString( 'does not support nesting', $result['error'] );
	}

	// ─── Multi-channel detection ─────────────────────────────────────────────

	public function test_check_content_with_no_nesting_anywhere(): void {
		$content = array(
			'subject' => 'Hello {{nome_socio}}',
			'html'    => '<p>{{#data_limite}}Prazo{{/}}</p>',
			'text'    => '{{#data_limite}}Prazo{{/}}',
		);
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check_content( $content );
		$this->assertFalse( $result['nested'] );
		$this->assertSame( '', $result['channel'] );
	}

	public function test_check_content_detects_nesting_in_html(): void {
		$content = array(
			'subject' => 'Hello',
			'html'    => '<p>{{#outer}}{{#inner}}nested{{/}}{{/}}</p>',
			'text'    => 'clean text',
		);
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check_content( $content );
		$this->assertTrue( $result['nested'] );
		$this->assertSame( 'html', $result['channel'] );
	}

	public function test_check_content_detects_nesting_in_text(): void {
		$content = array(
			'subject' => 'Hello',
			'html'    => '<p>clean</p>',
			'text'    => '{{#a}}before {{#b}}nested{{/}}{{/}}',
		);
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check_content( $content );
		$this->assertTrue( $result['nested'] );
		$this->assertSame( 'text', $result['channel'] );
	}

	public function test_check_content_detects_nesting_in_subject(): void {
		$content = array(
			'subject' => '{{#a}}{{#b}}bad{{/}}{{/}}',
			'html'    => 'clean',
			'text'    => 'clean',
		);
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check_content( $content );
		$this->assertTrue( $result['nested'] );
		$this->assertSame( 'subject', $result['channel'] );
	}

	public function test_check_content_reports_first_channel_with_nesting(): void {
		// Subject is checked first, so if both subject and html have nesting, subject wins.
		$content = array(
			'subject' => '{{#a}}{{#b}}x{{/}}{{/}}',
			'html'    => '{{#c}}{{#d}}y{{/}}{{/}}',
			'text'    => 'clean',
		);
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check_content( $content );
		$this->assertTrue( $result['nested'] );
		$this->assertSame( 'subject', $result['channel'] );
	}

	public function test_check_content_skips_missing_channels(): void {
		$content = array(
			'subject' => '',
			'html'    => '',
			'text'    => '',
		);
		$result = ANPA_Socios_Email_Template_Nesting_Guard::check_content( $content );
		$this->assertFalse( $result['nested'] );
	}

	// ─── Purity: does not touch WordPress ────────────────────────────────────

	/**
	 * The class source must not call any WordPress function. This is the same inspection
	 * pattern the sibling pure classes use.
	 */
	public function test_class_does_not_call_wordpress_functions(): void {
		$source = file_get_contents(
			dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-nesting-guard.php'
		);
		// Strip comments before matching, so a mention in a docblock does not trigger.
		$tokens  = token_get_all( $source );
		$code    = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		$wp_functions = array(
			'esc_html', 'esc_attr', 'esc_url', 'wp_kses', 'sanitize_text_field',
			'get_option', 'update_option', 'delete_option',
			'apply_filters', 'do_action', 'add_filter', 'add_action',
			'wp_die', 'wp_mail', 'wp_redirect', 'wp_safe_redirect',
			'current_user_can', 'check_admin_referer', 'wp_nonce_field',
			'__\\(', '_e\\(', 'esc_html__', 'esc_attr__',
		);
		foreach ( $wp_functions as $fn ) {
			$pattern = '/\b' . $fn . '\s*\(/';
			$this->assertDoesNotMatchRegularExpression(
				$pattern,
				$code,
				"Pure class must not call WordPress function matching: {$fn}"
			);
		}

		// Also reject $wpdb usage.
		$this->assertStringNotContainsString( '$wpdb', $code );
	}
}
