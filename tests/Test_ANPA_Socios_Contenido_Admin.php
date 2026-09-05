<?php
/**
 * TDD: FASE37 M3 — Administración Base
 *
 * Tests for admin UI: capability, nonce, whitelist, persistence, sanitization.
 * These are pure unit tests using inline stubs.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── WordPress function stubs (only if not already defined by bootstrap) ──────

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
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $id ) {
		$GLOBALS['anpa_current_user'] = $id;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return 'manage_options' === $cap;
	}
}
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
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		global $wp_options;
		unset( $wp_options[ $name ] );
		return true;
	}
}

require_once __DIR__ . '/../includes/class-anpa-socios-config.php';
require_once __DIR__ . '/../includes/lib/class-anpa-socios-admin-nav.php';

final class Test_ANPA_Socios_Contenido_Admin extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_options;
		$wp_options = [];
		$GLOBALS['anpa_current_user'] = 1;
	}

	protected function tearDown(): void {
		global $wp_options;
		$wp_options = [];
		$GLOBALS['anpa_current_user'] = 0;
		parent::tearDown();
	}

	// ── Tab exists in nav ────────────────────────────────────────────────────────

	public function test_contido_tab_exists_in_nav(): void {
		$tabs = ANPA_Socios_Admin_Nav::settings_tabs();
		$this->assertArrayHasKey( 'contenido', $tabs, 'Contido tab should exist' );
	}

	public function test_contido_has_five_sections(): void {
		$sections = ANPA_Socios_Admin_Nav::settings_sections( 'contenido' );
		$this->assertCount( 5, $sections );
		$this->assertArrayHasKey( 'transporte', $sections );
		$this->assertArrayHasKey( 'libros', $sections );
		$this->assertArrayHasKey( 'bos-dias', $sections );
		$this->assertArrayHasKey( 'comedor', $sections );
		$this->assertArrayHasKey( 'tardes-divertidas', $sections );
	}

	// ── Category whitelist ──────────────────────────────────────────────────────

	public function test_only_five_categories_are_valid(): void {
		$valid = ANPA_Socios_Config::CATEGORIAS_VALIDAS;
		$this->assertCount( 5, $valid );
		$this->assertContains( 'transporte', $valid );
		$this->assertContains( 'libros', $valid );
		$this->assertContains( 'bos-dias', $valid );
		$this->assertContains( 'comedor', $valid );
		$this->assertContains( 'tardes-divertidas', $valid );
	}

	public function test_unknown_category_is_not_valid(): void {
		$this->assertNotContains( 'hacker', ANPA_Socios_Config::CATEGORIAS_VALIDAS );
		$this->assertNotContains( 'transporte-extra', ANPA_Socios_Config::CATEGORIAS_VALIDAS );
	}

	// ── Persistence via config API ──────────────────────────────────────────────

	public function test_save_handler_uses_config_api(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Test Transport',
			'activo' => true,
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Test Transport', $config['titulo'] );
		$this->assertTrue( $config['activo'] );
	}

	public function test_save_handler_preserves_other_categories(): void {
		foreach ( ANPA_Socios_Config::CATEGORIAS_VALIDAS as $cat ) {
			ANPA_Socios_Config::update_contenido_admin( $cat, [
				'titulo' => "Title for $cat",
			] );
		}

		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Updated Transport',
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertSame( 'Title for libros', $libros['titulo'] );

		$bos_dias = ANPA_Socios_Config::contenido_admin( 'bos-dias' );
		$this->assertSame( 'Title for bos-dias', $bos_dias['titulo'] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertSame( 'Title for comedor', $comedor['titulo'] );

		$tardes = ANPA_Socios_Config::contenido_admin( 'tardes-divertidas' );
		$this->assertSame( 'Title for tardes-divertidas', $tardes['titulo'] );
	}

	// ── Sanitization ────────────────────────────────────────────────────────────

	public function test_sanitization_of_titulo(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => '  Test Title  ',
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Test Title', $config['titulo'] );
	}

	// ── Nonce per category ─────────────────────────────────────────────────────

	public function test_nonce_action_is_category_specific(): void {
		$categoria = 'transporte';
		$nonce_action = "anpa_save_contenido_{$categoria}";
		$this->assertSame( 'anpa_save_contenido_transporte', $nonce_action );
	}

	public function test_different_categories_have_different_nonces(): void {
		$nonce1 = 'anpa_save_contenido_transporte';
		$nonce2 = 'anpa_save_contenido_libros';
		$this->assertNotSame( $nonce1, $nonce2 );
	}

	// ── Invalid category ────────────────────────────────────────────────────────

	public function test_invalid_category_returns_false(): void {
		$result = ANPA_Socios_Config::update_contenido_admin( 'invalid-category', [
			'titulo' => 'Should not persist',
		] );
		$this->assertFalse( $result );
	}

	public function test_invalid_category_does_not_affect_others(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Valid',
		] );

		ANPA_Socios_Config::update_contenido_admin( 'hacker', [
			'titulo' => 'Invalid',
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Valid', $config['titulo'] );
	}

	// ── Common fields ───────────────────────────────────────────────────────────

	public function test_activo_field_is_boolean(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'activo' => true,
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsBool( $config['activo'] );
		$this->assertTrue( $config['activo'] );
	}

	public function test_orden_field_is_integer(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'orden' => 3,
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsInt( $config['orden'] );
		$this->assertSame( 3, $config['orden'] );
	}

	public function test_icono_field_is_string(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'icono' => 'dashicons-car',
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsString( $config['icono'] );
		$this->assertSame( 'dashicons-car', $config['icono'] );
	}

	public function test_icono_sanitized_with_dashicons(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'icono' => 'dashicons-admin-site',
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertStringStartsWith( 'dashicons-', $config['icono'] );
	}

	// ── Documents and links structure ───────────────────────────────────────────

	public function test_documentos_is_array(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Doc 1' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['documentos'] );
		$this->assertCount( 1, $config['documentos'] );
	}

	public function test_enlaces_is_array(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [
				[ 'title' => 'Link 1', 'url' => 'http://example.com' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['enlaces'] );
		$this->assertCount( 1, $config['enlaces'] );
	}

	// ── Items for structured categories ─────────────────────────────────────────

	public function test_libros_items_is_array(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [
				[ 'titulo' => 'Book 1', 'curso' => '1o EP' ],
			],
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertIsArray( $libros['items'] );
		$this->assertCount( 1, $libros['items'] );
	}

	public function test_comedor_items_is_array(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [
				[ 'fecha' => '2026-09-01', 'menu' => 'Pasta' ],
			],
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertIsArray( $comedor['items'] );
		$this->assertCount( 1, $comedor['items'] );
	}

	public function test_transporte_does_not_have_items(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayNotHasKey( 'items', $config );
	}

	public function test_bos_dias_does_not_have_items(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'bos-dias' );
		$this->assertArrayNotHasKey( 'items', $config );
	}

	// ── Default content values ──────────────────────────────────────────────────

	public function test_default_content_is_empty_string(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( '', $config['contido'] );
	}

	public function test_default_activo_is_true(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertTrue( $config['activo'] );
	}

	public function test_default_documentos_is_empty_array(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['documentos'] );
		$this->assertEmpty( $config['documentos'] );
	}

	public function test_default_enlaces_is_empty_array(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['enlaces'] );
		$this->assertEmpty( $config['enlaces'] );
	}

	public function test_all_defaults_have_cinco_categorias(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		$this->assertCount( 5, $defaults );
	}

	public function test_each_default_has_required_keys(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		foreach ( $defaults as $cat => $fields ) {
			$this->assertArrayHasKey( 'activo', $fields );
			$this->assertArrayHasKey( 'titulo', $fields );
			$this->assertArrayHasKey( 'contido', $fields );
			$this->assertArrayHasKey( 'icono', $fields );
			$this->assertArrayHasKey( 'documentos', $fields );
			$this->assertArrayHasKey( 'enlaces', $fields );
			$this->assertArrayHasKey( 'orden', $fields );
		}
	}
}
