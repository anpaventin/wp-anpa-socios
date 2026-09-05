<?php
/**
 * TDD: FASE37 M6 — Documentos e Enlaces
 *
 * Tests for documents (Media Library) and links admin UI.
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
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return stripslashes( $value );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_VALIDATE_URL ) ?: '';
	}
}

require_once __DIR__ . '/../includes/class-anpa-socios-config.php';

final class Test_ANPA_Socios_Contenido_Docs_Links extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_options;
		$wp_options = [];
	}

	protected function tearDown(): void {
		global $wp_options;
		$wp_options = [];
		parent::tearDown();
	}

	// ── Documents structure ─────────────────────────────────────────────────────

	public function test_documents_empty_by_default(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['documentos'] );
		$this->assertEmpty( $config['documentos'] );
	}

	public function test_one_document_persists(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc 1' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 1, $config['documentos'] );
		$this->assertSame( 123, $config['documentos'][0]['id'] );
		$this->assertSame( 'http://example.com/doc.pdf', $config['documentos'][0]['url'] );
		$this->assertSame( 'Doc 1', $config['documentos'][0]['title'] );
	}

	public function test_multiple_documents_persist(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => 1, 'url' => 'http://a.com/1.pdf', 'title' => 'A' ],
				[ 'id' => 2, 'url' => 'http://b.com/2.pdf', 'title' => 'B' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 2, $config['documentos'] );
	}

	// ── Document ID validation ───────────────────────────────────────────────────

	public function test_document_id_zero_ignored(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => 0, 'url' => 'http://example.com/doc.pdf', 'title' => 'Invalid' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertEmpty( $config['documentos'] );
	}

	public function test_document_id_negative_converted(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => -5, 'url' => 'http://example.com/doc.pdf', 'title' => 'Converted' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 1, $config['documentos'] );
		$this->assertSame( 5, $config['documentos'][0]['id'] );
	}

	public function test_document_id_string_numeric_converted(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => '456', 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 456, $config['documentos'][0]['id'] );
	}

	// ── Document sanitization ───────────────────────────────────────────────────

	public function test_document_url_sanitized(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => 123, 'url' => 'javascript:alert(1)', 'title' => 'XSS' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( '', $config['documentos'][0]['url'] );
	}

	public function test_document_title_sanitized(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => '  <strong>Doc</strong>  ' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Doc', $config['documentos'][0]['title'] );
	}

	public function test_document_non_array_ignored(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				'not-an-array',
				[ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Valid' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 1, $config['documentos'] );
	}

	public function test_document_unknown_fields_filtered(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc', 'hacker' => 'malicious' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayNotHasKey( 'hacker', $config['documentos'][0] );
	}

	// ── Links ────────────────────────────────────────────────────────────────────

	public function test_links_empty_by_default(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['enlaces'] );
		$this->assertEmpty( $config['enlaces'] );
	}

	public function test_one_link_persists(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [
				[ 'title' => 'Link 1', 'url' => 'http://example.com/page' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 1, $config['enlaces'] );
		$this->assertSame( 'Link 1', $config['enlaces'][0]['title'] );
		$this->assertSame( 'http://example.com/page', $config['enlaces'][0]['url'] );
	}

	public function test_link_url_invalid_sanitized(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [
				[ 'title' => 'Bad URL', 'url' => 'javascript:alert(1)' ],
				[ 'title' => 'Good URL', 'url' => 'http://example.com' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 1, $config['enlaces'] );
		$this->assertSame( 'Good URL', $config['enlaces'][0]['title'] );
	}

	public function test_link_title_sanitized(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [
				[ 'title' => '  <em>Link</em>  ', 'url' => 'http://example.com' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Link', $config['enlaces'][0]['title'] );
	}

	public function test_link_non_array_ignored(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [
				'not-an-array',
				[ 'title' => 'Valid', 'url' => 'http://example.com' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 1, $config['enlaces'] );
	}

	public function test_link_unknown_fields_filtered(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [
				[ 'title' => 'Link', 'url' => 'http://example.com', 'bad' => 'data' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayNotHasKey( 'bad', $config['enlaces'][0] );
	}

	// ── Category integrity ──────────────────────────────────────────────────────

	public function test_documents_do_not_affect_other_categories(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'titulo' => 'Libros Title',
		] );

		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [ [ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc' ] ],
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertSame( 'Libros Title', $libros['titulo'] );
		$this->assertEmpty( $libros['documentos'] );
	}

	public function test_links_do_not_affect_other_categories(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'titulo' => 'Comedor Title',
		] );

		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [ [ 'title' => 'Link', 'url' => 'http://example.com' ] ],
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertSame( 'Comedor Title', $comedor['titulo'] );
		$this->assertEmpty( $comedor['enlaces'] );
	}

	// ── Books regression ────────────────────────────────────────────────────────

	public function test_documents_do_not_break_libros(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [ [ 'titulo' => 'Book 1' ] ],
		] );

		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [ [ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc' ] ],
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertSame( 'Book 1', $libros['items'][0]['titulo'] );
	}

	public function test_links_do_not_break_comedor(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [ [ 'menu' => 'Menu 1' ] ],
		] );

		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [ [ 'title' => 'Link', 'url' => 'http://example.com' ] ],
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertSame( 'Menu 1', $comedor['items'][0]['menu'] );
	}

	// ── Array re-index ──────────────────────────────────────────────────────────

	public function test_documents_array_reindexed(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				2 => [ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$keys = array_keys( $config['documentos'] );
		$this->assertSame( [ 0 ], $keys );
	}

	public function test_links_array_reindexed(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [
				3 => [ 'title' => 'Link', 'url' => 'http://example.com' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$keys = array_keys( $config['enlaces'] );
		$this->assertSame( [ 0 ], $keys );
	}

	// ── Delete ──────────────────────────────────────────────────────────────────

	public function test_delete_documents(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [ [ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc' ] ],
		] );

		ANPA_Socios_Config::delete_contenido_admin();

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertEmpty( $config['documentos'] );
	}
}
