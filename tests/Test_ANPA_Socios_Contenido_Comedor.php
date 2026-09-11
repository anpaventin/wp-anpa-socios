<?php
/**
 * TDD: FASE37 M5 — Comedor (Structured Items)
 *
 * Tests for comedor UI: items CRUD, persistence, sanitization, normalization.
 * Verifies category isolation (doesn't affect libros or other categories).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── WordPress function stubs (only if not already defined by bootstrap) ──────

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

final class Test_ANPA_Socios_Contenido_Comedor extends TestCase {

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

	public function test_comedor_items_empty_by_default(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertIsArray( $config['items'] );
		$this->assertEmpty( $config['items'] );
	}

	// ── One item ────────────────────────────────────────────────────────────────

	public function test_one_menu_item_persists(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				[
					'fecha'     => '2026-09-01',
					'menu'      => 'Pasta con tomate',
					'alerxenos' => 'Gluten, lactosa',
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertCount( 1, $config['items'] );
		$this->assertSame( '2026-09-01', $config['items'][0]['fecha'] );
		$this->assertSame( 'Pasta con tomate', $config['items'][0]['menu'] );
		$this->assertSame( 'Gluten, lactosa', $config['items'][0]['alerxenos'] );
	}

	// ── Multiple items ──────────────────────────────────────────────────────────

	public function test_multiple_menu_items_persist(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				[ 'fecha' => '2026-09-01', 'menu' => 'Menu 1', 'alerxenos' => 'A' ],
				[ 'fecha' => '2026-09-02', 'menu' => 'Menu 2', 'alerxenos' => 'B' ],
				[ 'fecha' => '2026-09-03', 'menu' => 'Menu 3', 'alerxenos' => 'C' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertCount( 3, $config['items'] );
		$this->assertSame( 'Menu 1', $config['items'][0]['menu'] );
		$this->assertSame( 'Menu 2', $config['items'][1]['menu'] );
		$this->assertSame( 'Menu 3', $config['items'][2]['menu'] );
	}

	// ── Partial item ────────────────────────────────────────────────────────────

	public function test_partial_menu_item_normalized(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				[ 'menu' => 'Only Menu' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$item = $config['items'][0];
		$this->assertArrayHasKey( 'fecha', $item );
		$this->assertArrayHasKey( 'menu', $item );
		$this->assertArrayHasKey( 'alerxenos', $item );
		$this->assertSame( 'Only Menu', $item['menu'] );
	}

	// ── Malformed item ──────────────────────────────────────────────────────────

	public function test_non_array_item_ignored(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				'not-an-array',
				[ 'menu' => 'Valid Menu' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertCount( 1, $config['items'] );
		$this->assertSame( 'Valid Menu', $config['items'][0]['menu'] );
	}

	// ── Unknown fields ──────────────────────────────────────────────────────────

	public function test_unknown_fields_filtered(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				[
					'menu' => 'Menu',
					'hacker_field' => 'malicious',
					'precio' => '100',
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$item = $config['items'][0];
		$this->assertArrayNotHasKey( 'hacker_field', $item );
		$this->assertArrayNotHasKey( 'precio', $item );
		$this->assertSame( 'Menu', $item['menu'] );
	}

	// ── Category integrity ──────────────────────────────────────────────────────

	public function test_comedor_items_do_not_affect_other_categories(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Transport Info',
		] );

		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [ [ 'titulo' => 'Book 1' ] ],
		] );

		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [ [ 'menu' => 'Menu 1' ] ],
		] );

		$transport = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Transport Info', $transport['titulo'] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertSame( 'Book 1', $libros['items'][0]['titulo'] );
	}

	public function test_comedor_common_fields_preserved(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'titulo' => 'Comedor Title',
			'activo' => true,
			'orden' => 4,
		] );

		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [ [ 'menu' => 'Menu 1' ] ],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertSame( 'Comedor Title', $config['titulo'] );
		$this->assertTrue( $config['activo'] );
		$this->assertSame( 4, $config['orden'] );
	}

	// ── Sanitization ────────────────────────────────────────────────────────────

	public function test_comedor_item_sanitization(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				[
					'fecha'     => '  2026-09-01  ',
					'menu'      => '  <strong>Pasta</strong>  ',
					'alerxenos' => '  Gluten  ',
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$item = $config['items'][0];
		$this->assertSame( '2026-09-01', $item['fecha'] );
		$this->assertSame( 'Pasta', $item['menu'] );
		$this->assertSame( 'Gluten', $item['alerxenos'] );
	}

	// ── Array re-index ──────────────────────────────────────────────────────────

	public function test_items_array_reindexed(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				3 => [ 'menu' => 'Menu at index 3' ],
				7 => [ 'menu' => 'Menu at index 7' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$keys = array_keys( $config['items'] );
		$this->assertSame( [ 0, 1 ], $keys );
	}

	// ── Books regression ────────────────────────────────────────────────────────

	public function test_comedor_does_not_break_libros_schema(): void {
		// Set libros with valid fields
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				[ 'titulo' => 'Book', 'curso' => '1o EP', 'isbn' => '1234' ],
			],
		] );

		// Update comedor
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [ [ 'menu' => 'Menu' ] ],
		] );

		// Verify libros still has correct fields
		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$libro = $libros['items'][0];
		$this->assertArrayHasKey( 'titulo', $libro );
		$this->assertArrayHasKey( 'curso', $libro );
		$this->assertArrayHasKey( 'isbn', $libro );
		$this->assertArrayNotHasKey( 'fecha', $libro );
		$this->assertArrayNotHasKey( 'menu', $libro );
	}

	public function test_comedor_does_not_accept_libros_fields(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				[
					'menu' => 'Menu',
					'curso' => '1o EP',
					'isbn' => '1234',
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$item = $config['items'][0];
		$this->assertArrayNotHasKey( 'curso', $item );
		$this->assertArrayNotHasKey( 'isbn', $item );
	}

	// ── Delete ──────────────────────────────────────────────────────────────────

	public function test_delete_comedor_items(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [ [ 'menu' => 'Menu' ] ],
		] );

		ANPA_Socios_Config::delete_contenido_admin();

		$config = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertEmpty( $config['items'] );
	}
}
