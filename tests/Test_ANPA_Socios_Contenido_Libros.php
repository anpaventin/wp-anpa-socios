<?php
/**
 * TDD: FASE37 M4 — Libros (Structured Items)
 *
 * Tests for books UI: items CRUD, persistence, sanitization, normalization.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── WordPress function stubs ─────────────────────────────────────────────────

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
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string ) {
		return trim( strip_tags( (string) $string ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return (string) $text;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( (string) $url, FILTER_VALIDATE_URL ) ?: '';
	}
}

require_once __DIR__ . '/../includes/class-anpa-socios-config.php';

final class Test_ANPA_Socios_Contenido_Libros extends TestCase {

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

	// ── Empty state ─────────────────────────────────────────────────────────────

	public function test_libros_items_empty_by_default(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertIsArray( $config['items'] );
		$this->assertEmpty( $config['items'] );
	}

	// ── One item ────────────────────────────────────────────────────────────────

	public function test_one_book_item_persists(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				[
					'curso'     => '1o EP',
					'nivel'     => 'Primaria',
					'materia'   => 'Lingua',
					'titulo'    => 'Libro de Texto 1',
					'editorial' => 'Xerais',
					'isbn'      => '978-84-1234-567-8',
					'prezo'     => '25.50',
					'descarga'  => 'http://example.com/libro1.pdf',
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertCount( 1, $config['items'] );
		$this->assertSame( '1o EP', $config['items'][0]['curso'] );
		$this->assertSame( '978-84-1234-567-8', $config['items'][0]['isbn'] );
	}

	// ── Multiple items ──────────────────────────────────────────────────────────

	public function test_multiple_book_items_persist(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				[ 'titulo' => 'Book 1', 'curso' => '1o EP' ],
				[ 'titulo' => 'Book 2', 'curso' => '2o EP' ],
				[ 'titulo' => 'Book 3', 'curso' => '3o EP' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertCount( 3, $config['items'] );
		$this->assertSame( 'Book 1', $config['items'][0]['titulo'] );
		$this->assertSame( 'Book 2', $config['items'][1]['titulo'] );
		$this->assertSame( 'Book 3', $config['items'][2]['titulo'] );
	}

	// ── Partial item ────────────────────────────────────────────────────────────

	public function test_partial_book_item_normalized(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				[ 'titulo' => 'Only Title' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$item = $config['items'][0];
		$this->assertArrayHasKey( 'curso', $item );
		$this->assertArrayHasKey( 'nivel', $item );
		$this->assertArrayHasKey( 'materia', $item );
		$this->assertArrayHasKey( 'titulo', $item );
		$this->assertArrayHasKey( 'editorial', $item );
		$this->assertArrayHasKey( 'isbn', $item );
		$this->assertArrayHasKey( 'prezo', $item );
		$this->assertArrayHasKey( 'descarga', $item );
		$this->assertSame( 'Only Title', $item['titulo'] );
	}

	// ── Malformed item ──────────────────────────────────────────────────────────

	public function test_non_array_item_ignored(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				'not-an-array',
				[ 'titulo' => 'Valid Book' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertCount( 1, $config['items'] );
		$this->assertSame( 'Valid Book', $config['items'][0]['titulo'] );
	}

	// ── Unknown fields ──────────────────────────────────────────────────────────

	public function test_unknown_fields_filtered(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				[
					'titulo' => 'Book',
					'hacker_field' => 'malicious',
					'another_bad' => 'data',
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$item = $config['items'][0];
		$this->assertArrayNotHasKey( 'hacker_field', $item );
		$this->assertArrayNotHasKey( 'another_bad', $item );
		$this->assertSame( 'Book', $item['titulo'] );
	}

	// ── Category integrity ──────────────────────────────────────────────────────

	public function test_libros_items_do_not_affect_other_categories(): void {
		// Set up transport
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Transport Info',
		] );

		// Update libros items
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [ [ 'titulo' => 'Book 1' ] ],
		] );

		// Verify transport is preserved
		$transport = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Transport Info', $transport['titulo'] );
	}

	public function test_libros_common_fields_preserved(): void {
		// Set common fields
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'titulo' => 'Libros Title',
			'activo' => true,
			'orden' => 2,
		] );

		// Update items
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [ [ 'titulo' => 'Book 1' ] ],
		] );

		// Verify common fields preserved
		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertSame( 'Libros Title', $config['titulo'] );
		$this->assertTrue( $config['activo'] );
		$this->assertSame( 2, $config['orden'] );
	}

	// ── Sanitization ────────────────────────────────────────────────────────────

	public function test_book_item_sanitization(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				[
					'titulo' => '  <strong>Book Title</strong>  ',
					'prezo' => ' 25.50 ',
					'descarga' => 'http://example.com/book.php?id=1&format=pdf',
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$item = $config['items'][0];
		$this->assertSame( 'Book Title', $item['titulo'] );
		$this->assertSame( '25.50', $item['prezo'] );
		$this->assertSame( 'http://example.com/book.php?id=1&format=pdf', $item['descarga'] );
	}

	// ── Array re-index ──────────────────────────────────────────────────────────

	public function test_items_array_reindexed(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				2 => [ 'titulo' => 'Book at index 2' ],
				5 => [ 'titulo' => 'Book at index 5' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$keys = array_keys( $config['items'] );
		$this->assertSame( [ 0, 1 ], $keys );
	}

	// ── Delete ──────────────────────────────────────────────────────────────────

	public function test_delete_libros_items(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [ [ 'titulo' => 'Book' ] ],
		] );

		ANPA_Socios_Config::delete_contenido_admin();

		$config = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertEmpty( $config['items'] );
	}
}
