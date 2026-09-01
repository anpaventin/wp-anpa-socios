<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Define ALL WP stubs BEFORE loading the class under test
if (!function_exists('admin_url')) { function admin_url($path) { return 'http://example.test/wp-admin/' . ltrim($path, '/'); } }
if (!function_exists('wp_nonce_url')) { function wp_nonce_url($url, $action) { return add_query_arg('_wpnonce', 'test', $url); } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($action, $name) { echo '<input type="hidden" name="' . $name . '" value="x" />'; } }
if (!function_exists('current_user_can')) { function current_user_can($cap) { return true; } }
if (!function_exists('wp_die')) { function wp_die($msg) { throw new RuntimeException($msg); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string)$s)); } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$s)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return stripslashes((string)$s); } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_html__')) { function esc_html__($s, $d='default') { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_textarea')) { function esc_textarea($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($s) { return filter_var((string)$s, FILTER_VALIDATE_URL) ?: ''; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($s) { return $s; } }
if (!function_exists('__')) { function __($text, $domain='default') { return $text; } }

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-renderer.php';
require_once __DIR__ . '/../includes/class-anpa-socios-email-templates-page.php';

final class Test_ANPA_Socios_Admin_URL_Correctness extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        delete_option('anpa_socios_email_templates');
        $_GET = [];
    }

    protected function tearDown(): void {
        $_GET = [];
        parent::tearDown();
    }

    public function test_admin_post_url_does_not_exist(): void {
        $this->assertFalse(
            function_exists('admin_post_url'),
            'admin_post_url() must NOT exist in WordPress (this is the bug)'
        );
    }

    public function test_edit_form_uses_admin_post_php(): void {
        $_GET['edit'] = 'verification_code';

        ob_start();
        ANPA_Socios_Email_Templates_Page::render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'admin-post.php?action=anpa_save_template',
            $output,
            'Edit form action must use admin-post.php?action=anpa_save_template'
        );
    }

    public function test_restore_action_uses_admin_post_php(): void {
        ANPA_Socios_Email_Template_Store::save(
            'verification_code',
            'Custom Subject',
            '<p>Custom</p>',
            'Custom Text'
        );

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
