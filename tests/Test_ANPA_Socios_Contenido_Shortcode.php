<?php
/**
 * TDD: FASE37 M7 — Shortcode y Render Común
 *
 * Tests para [anpa_contenido] shortcode.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		global $wp_options;
		return $wp_options[ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		global $wp_options;
		$wp_options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) {
		$allowed = array( 'p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'table', 'tr', 'td', 'th', 'img', 'div', 'span' );
		return strip_tags( $html, '<' . implode( '><', $allowed ) . '>' );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return stripslashes( $value );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( (string) $url, FILTER_VALIDATE_URL ) ?: '';
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_VALIDATE_URL ) ?: '#';
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $id ) {
		return 'http://example.com/wp-content/uploads/doc.pdf';
	}
}
if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( $content, $tag ) {
		return strpos( $content, "[{$tag}" ) !== false;
	}
}
if ( ! function_exists( 'is_a' ) ) {
	function is_a( $obj, $class ) {
		return is_object( $obj ) && get_class( $obj ) === $class;
	}
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_content = '';
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts ) {
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}
		return array_merge( $pairs, $atts );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters() {
		return null;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
	}
}

// Shortcode tags registry.
global $shortcode_tags;
$shortcode_tags = array();

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		global $shortcode_tags;
		$shortcode_tags[ $tag ] = $callback;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $content ) {
		global $shortcode_tags;
		if ( ! empty( $shortcode_tags['anpa_contenido'] ) ) {
			$callback = $shortcode_tags['anpa_contenido'];
			// Parse attributes from shortcode tag.
			$atts = array();
			if ( preg_match( '/\[anpa_contenido\s+categoria="([^"]+)"/', $content, $matches ) ) {
				$atts['categoria'] = $matches[1];
			}
			return $callback( $atts );
		}
		return $content;
	}
}

require_once __DIR__ . '/../includes/class-anpa-socios-config.php';
require_once __DIR__ . '/../includes/class-anpa-socios-contenido-shortcode.php';

final class Test_ANPA_Socios_Contenido_Shortcode extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_options, $shortcode_tags;
		$wp_options = array();
		$shortcode_tags = array();
		$this->config = array(
			'transporte' => array(
				'activo' => true,
				'titulo' => 'Transporte Escolar',
				'contido' => '<p>Info transporte</p>',
				'icono' => 'dashicons-car',
				'documentos' => array(),
				'enlaces' => array(),
				'orden' => 1,
			),
		);
		update_option( 'anpa_socios_contenido_admin', $this->config );
		ANPA_Socios_Contenido_Shortcode::register();
	}

	protected function tearDown(): void {
		global $wp_options, $shortcode_tags;
		$wp_options = array();
		$shortcode_tags = array();
		parent::tearDown();
	}

	// ── Shortcode registration ───────────────────────────────────────────────────

	public function test_shortcode_exists(): void {
		global $shortcode_tags;
		$this->assertArrayHasKey( 'anpa_contenido', $shortcode_tags );
	}

	// ── Valid category ──────────────────────────────────────────────────────────

	public function test_valid_category_returns_html(): void {
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringContainsString( 'Transporte Escolar', $output );
	}

	// ── Invalid category ────────────────────────────────────────────────────────

	public function test_invalid_category_returns_empty(): void {
		$output = do_shortcode( '[anpa_contenido categoria="hacker"]' );
		$this->assertSame( '', $output );
	}

	// ── Missing category ────────────────────────────────────────────────────────

	public function test_missing_category_returns_empty(): void {
		$output = do_shortcode( '[anpa_contenido]' );
		$this->assertSame( '', $output );
	}

	// ── Category not configured ─────────────────────────────────────────────────

	public function test_unconfigured_category_returns_empty(): void {
		$output = do_shortcode( '[anpa_contenido categoria="bos-dias"]' );
		$this->assertSame( '', $output );
	}

	// ── Inactive category ───────────────────────────────────────────────────────

	public function test_inactive_category_returns_empty(): void {
		$this->config['transporte']['activo'] = false;
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertSame( '', $output );
	}

	// ── Title escaping ──────────────────────────────────────────────────────────

	public function test_title_escaped(): void {
		$this->config['transporte']['titulo'] = 'Título <script>alert(1)</script>';
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringNotContainsString( '<script>', $output );
	}

	// ── Content HTML allowed ────────────────────────────────────────────────────

	public function test_content_html_allowed(): void {
		$this->config['transporte']['contido'] = '<p>Safe</p><strong>Bold</strong>';
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringContainsString( '<p>Safe</p>', $output );
		$this->assertStringContainsString( '<strong>Bold</strong>', $output );
	}

	// ── Content dangerous filtered ──────────────────────────────────────────────

	public function test_content_dangerous_filtered(): void {
		$this->config['transporte']['contido'] = '<script>alert(1)</script><p>Safe</p>';
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '<p>Safe</p>', $output );
	}

	// ── Links rendering ─────────────────────────────────────────────────────────

	public function test_links_rendered_escaped(): void {
		$this->config['transporte']['enlaces'] = array(
			array( 'title' => 'Link 1', 'url' => 'http://example.com' ),
		);
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringContainsString( 'Link 1', $output );
		$this->assertStringContainsString( 'http://example.com', $output );
	}

	// ── Link invalid URL not rendered ──────────────────────────────────────────

	public function test_link_invalid_url_not_rendered(): void {
		$this->config['transporte']['enlaces'] = array(
			array( 'title' => 'Bad', 'url' => 'javascript:alert(1)' ),
		);
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringNotContainsString( 'javascript:', $output );
	}

	// ── Documents rendering ─────────────────────────────────────────────────────

	public function test_documents_rendered(): void {
		$this->config['transporte']['documentos'] = array(
			array( 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc 1' ),
		);
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringContainsString( 'Doc 1', $output );
		$this->assertStringContainsString( 'http://example.com/wp-content/uploads/doc.pdf', $output );
	}

	// ── Document title escaping ─────────────────────────────────────────────────

	public function test_document_title_escaped(): void {
		$this->config['transporte']['documentos'] = array(
			array( 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => '<b>Doc</b>' ),
		);
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringNotContainsString( '<b>Doc</b>', $output );
	}

	// ── Icon rendering ──────────────────────────────────────────────────────────

	public function test_icon_rendered_safe(): void {
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringContainsString( 'dashicons-car', $output );
	}

	// ── No icon no break ────────────────────────────────────────────────────────

	public function test_no_icon_no_break(): void {
		$this->config['transporte']['icono'] = '';
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertStringContainsString( 'Transporte Escolar', $output );
	}

	// ── No warnings on incomplete data ─────────────────────────────────────────

	public function test_no_warnings_on_incomplete_data(): void {
		$this->config['transporte']['titulo'] = '';
		$this->config['transporte']['contido'] = '';
		update_option( 'anpa_socios_contenido_admin', $this->config );
		$output = do_shortcode( '[anpa_contenido categoria="transporte"]' );
		$this->assertIsString( $output );
	}
}
