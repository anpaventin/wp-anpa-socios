<?php
/**
 * TDD: FASE37 M8 — Variantes frontend: Libros e Comedor
 *
 * Tests for structured tables in [anpa_contenido] shortcode.
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
		return strip_tags( $html, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
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
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_VALIDATE_URL ) ?: '#';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( (string) $url, FILTER_VALIDATE_URL ) ?: '';
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
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		global $shortcode_tags;
		$shortcode_tags[ $tag ] = $callback;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

// Shortcode tags registry.
global $shortcode_tags;
$shortcode_tags = array();

if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $content ) {
		global $shortcode_tags;
		if ( ! empty( $shortcode_tags['anpa_contenido'] ) ) {
			$callback = $shortcode_tags['anpa_contenido'];
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

final class Test_ANPA_Socios_Contenido_Variantes extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_options, $shortcode_tags;
		$wp_options = array();
		$shortcode_tags = array();
		update_option( 'anpa_socios_contenido_admin', $this->get_default_config() );
		ANPA_Socios_Contenido_Shortcode::register();
	}

	protected function tearDown(): void {
		global $wp_options, $shortcode_tags;
		$wp_options = array();
		$shortcode_tags = array();
		parent::tearDown();
	}

	private function get_default_config(): array {
		return array(
			'libros' => array(
				'activo' => true,
				'titulo' => 'Libros de Texto',
				'contido' => '<p>Lista de libros</p>',
				'icono' => 'dashicons-book',
				'documentos' => array(),
				'enlaces' => array(),
				'items' => array(
					array(
						'curso' => '1o EP', 'nivel' => 'Primaria', 'materia' => 'Lingua',
						'titulo' => 'Libro 1', 'editorial' => 'Xerais', 'isbn' => '978-84-1234-567-8',
						'prezo' => '25.50', 'descarga' => 'http://example.com/libro1.pdf',
					),
					array(
						'curso' => '2o EP', 'nivel' => 'Primaria', 'materia' => 'Matemáticas',
						'titulo' => 'Libro 2', 'editorial' => 'SM', 'isbn' => '978-84-2345-678-9',
						'prezo' => '30.00', 'descarga' => '',
					),
				),
				'orden' => 2,
			),
			'comedor' => array(
				'activo' => true,
				'titulo' => 'Menú do Comedor',
				'contido' => '<p>Menús semanais</p>',
				'icono' => 'dashicons-carrot',
				'documentos' => array(),
				'enlaces' => array(),
				'items' => array(
					array( 'fecha' => '2026-09-01', 'menu' => 'Pasta con tomate', 'alerxenos' => 'Gluten, lactosa' ),
					array( 'fecha' => '2026-09-02', 'menu' => 'Polo ao forno', 'alerxenos' => '' ),
				),
				'orden' => 4,
			),
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
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// LIBROS
	// ═══════════════════════════════════════════════════════════════════════════

	public function test_libros_render_with_items(): void {
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringContainsString( 'Libro 1', $output );
		$this->assertStringContainsString( 'Libro 2', $output );
	}

	public function test_libros_table_exists(): void {
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringContainsString( '<table', $output );
		$this->assertStringContainsString( '</table>', $output );
	}

	public function test_libros_table_caption(): void {
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringContainsString( '<caption', $output );
	}

	public function test_libros_table_headers(): void {
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringContainsString( '<thead', $output );
		$this->assertStringContainsString( '<th scope="col"', $output );
	}

	public function test_libros_eight_columns(): void {
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		// Count scope="col" occurrences.
		$this->assertSame( 8, substr_count( $output, 'scope="col"' ) );
	}

	public function test_libros_all_fields_render(): void {
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringContainsString( '1o EP', $output );
		$this->assertStringContainsString( 'Primaria', $output );
		$this->assertStringContainsString( 'Lingua', $output );
		$this->assertStringContainsString( 'Xerais', $output );
		$this->assertStringContainsString( '978-84-1234-567-8', $output );
		$this->assertStringContainsString( '25.50', $output );
	}

	public function test_libros_valid_download_link(): void {
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringContainsString( 'http://example.com/libro1.pdf', $output );
		$this->assertStringContainsString( '<a href=', $output );
	}

	public function test_libros_invalid_download_no_unsafe(): void {
		// Update with malicious URL.
		$config = $this->get_default_config();
		$config['libros']['items'][0]['descarga'] = 'javascript:alert(1)';
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringNotContainsString( 'javascript:', $output );
	}

	public function test_libros_xss_escaped(): void {
		$config = $this->get_default_config();
		$config['libros']['items'][0]['titulo'] = '<script>alert(1)</script>';
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringNotContainsString( '<script>', $output );
	}

	public function test_libros_incomplete_item_no_warnings(): void {
		$config = $this->get_default_config();
		$config['libros']['items'] = array(
			array( 'titulo' => 'Only Title' ),
		);
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringContainsString( 'Only Title', $output );
		$this->assertStringNotContainsArray( 'Array', $output );
	}

	private function assertStringNotContainsArray( string $needle, string $haystack ): void {
		$this->assertStringNotContainsString( $needle, $haystack );
	}

	public function test_libros_multiple_items_preserve_order(): void {
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$pos1 = strpos( $output, 'Libro 1' );
		$pos2 = strpos( $output, 'Libro 2' );
		$this->assertLessThan( $pos2, $pos1 );
	}

	public function test_libros_empty_items_no_table(): void {
		$config = $this->get_default_config();
		$config['libros']['items'] = array();
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringNotContainsString( '<table', $output );
		$this->assertStringContainsString( 'Libros de Texto', $output );
	}

	public function test_libros_documents_still_work(): void {
		$config = $this->get_default_config();
		$config['libros']['documentos'] = array(
			array( 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc' ),
		);
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertStringContainsString( 'Doc', $output );
	}

	public function test_libros_inactive_returns_empty(): void {
		$config = $this->get_default_config();
		$config['libros']['activo'] = false;
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="libros"]' );
		$this->assertSame( '', $output );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// COMEDOR
	// ═══════════════════════════════════════════════════════════════════════════

	public function test_comedor_render_with_items(): void {
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringContainsString( 'Pasta con tomate', $output );
		$this->assertStringContainsString( 'Polo ao forno', $output );
	}

	public function test_comedor_table_exists(): void {
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringContainsString( '<table', $output );
	}

	public function test_comedor_table_caption(): void {
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringContainsString( '<caption', $output );
	}

	public function test_comedor_table_headers(): void {
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringContainsString( '<thead', $output );
		$this->assertStringContainsString( '<th scope="col"', $output );
	}

	public function test_comedor_three_columns(): void {
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertSame( 3, substr_count( $output, 'scope="col"' ) );
	}

	public function test_comedor_all_fields_render(): void {
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringContainsString( '2026-09-01', $output );
		$this->assertStringContainsString( 'Pasta con tomate', $output );
		$this->assertStringContainsString( 'Gluten, lactosa', $output );
	}

	public function test_comedor_xss_escaped(): void {
		$config = $this->get_default_config();
		$config['comedor']['items'][0]['menu'] = '<script>alert(1)</script>';
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringNotContainsString( '<script>', $output );
	}

	public function test_comedor_incomplete_item_no_warnings(): void {
		$config = $this->get_default_config();
		$config['comedor']['items'] = array(
			array( 'menu' => 'Only Menu' ),
		);
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringContainsString( 'Only Menu', $output );
	}

	public function test_comedor_multiple_items_preserve_order(): void {
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$pos1 = strpos( $output, 'Pasta con tomate' );
		$pos2 = strpos( $output, 'Polo ao forno' );
		$this->assertLessThan( $pos2, $pos1 );
	}

	public function test_comedor_empty_items_no_table(): void {
		$config = $this->get_default_config();
		$config['comedor']['items'] = array();
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringNotContainsString( '<table', $output );
		$this->assertStringContainsString( 'Menú do Comedor', $output );
	}

	public function test_comedor_documents_still_work(): void {
		$config = $this->get_default_config();
		$config['comedor']['documentos'] = array(
			array( 'id' => 456, 'url' => 'http://example.com/menu.pdf', 'title' => 'Menu PDF' ),
		);
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertStringContainsString( 'Menu PDF', $output );
	}

	public function test_comedor_inactive_returns_empty(): void {
		$config = $this->get_default_config();
		$config['comedor']['activo'] = false;
		update_option( 'anpa_socios_contenido_admin', $config );
		ANPA_Socios_Contenido_Shortcode::register();
		$output = do_shortcode( '[anpa_contenido categoria="comedor"]' );
		$this->assertSame( '', $output );
	}
}
