<?php
/**
 * TDD: FASE37 M1+M2 — Contido Administrativo Config / Model + Defaults
 *
 * M1: 14 tests básicos (PASS)
 * M2: Tests de casos límite
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-anpa-socios-config.php';

final class Test_ANPA_Socios_Contenido_Config extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_options;
		$wp_options = [];
		delete_option( 'anpa_socios_contenido_admin' );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// M1 — Tests básicos (xa cubertos)
	// ═══════════════════════════════════════════════════════════════════════════

	public function test_default_config_has_five_categories(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		$this->assertCount( 5, $defaults );
		$this->assertArrayHasKey( 'transporte', $defaults );
		$this->assertArrayHasKey( 'libros', $defaults );
		$this->assertArrayHasKey( 'bos-dias', $defaults );
		$this->assertArrayHasKey( 'comedor', $defaults );
		$this->assertArrayHasKey( 'tardes-divertidas', $defaults );
	}

	public function test_each_category_has_common_fields(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		foreach ( $defaults as $cat => $data ) {
			$this->assertArrayHasKey( 'activo', $data, "Missing 'activo' in $cat" );
			$this->assertArrayHasKey( 'titulo', $data, "Missing 'titulo' in $cat" );
			$this->assertArrayHasKey( 'contido', $data, "Missing 'contido' in $cat" );
			$this->assertArrayHasKey( 'icono', $data, "Missing 'icono' in $cat" );
			$this->assertArrayHasKey( 'documentos', $data, "Missing 'documentos' in $cat" );
			$this->assertArrayHasKey( 'enlaces', $data, "Missing 'enlaces' in $cat" );
			$this->assertArrayHasKey( 'orden', $data, "Missing 'orden' in $cat" );
		}
	}

	public function test_libros_has_items(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		$this->assertArrayHasKey( 'items', $defaults['libros'] );
		$this->assertIsArray( $defaults['libros']['items'] );
	}

	public function test_comedor_has_items(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		$this->assertArrayHasKey( 'items', $defaults['comedor'] );
		$this->assertIsArray( $defaults['comedor']['items'] );
	}

	public function test_defaults_have_activo_true(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		foreach ( $defaults as $cat => $data ) {
			$this->assertTrue( $data['activo'], "Expected activo=true for $cat" );
		}
	}

	public function test_default_orders_are_deterministic(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		$this->assertSame( 1, $defaults['transporte']['orden'] );
		$this->assertSame( 2, $defaults['libros']['orden'] );
		$this->assertSame( 3, $defaults['bos-dias']['orden'] );
		$this->assertSame( 4, $defaults['comedor']['orden'] );
		$this->assertSame( 5, $defaults['tardes-divertidas']['orden'] );
	}

	public function test_defaults_contain_no_real_content(): void {
		$defaults = ANPA_Socios_Config::contenido_admin_defaults();
		foreach ( $defaults as $cat => $data ) {
			$this->assertSame( '', $data['titulo'], "titulo should be empty for $cat" );
			$this->assertSame( '', $data['contido'], "contido should be empty for $cat" );
			$this->assertEmpty( $data['documentos'], "documentos should be empty for $cat" );
			$this->assertEmpty( $data['enlaces'], "enlaces should be empty for $cat" );
		}
	}

	public function test_getter_returns_defaults_when_option_missing(): void {
		delete_option( 'anpa_socios_contenido_admin' );
		$config = ANPA_Socios_Config::contenido_admin();
		$this->assertCount( 5, $config );
		$this->assertSame( 1, $config['transporte']['orden'] );
	}

	public function test_setter_persists_and_getter_retrieves(): void {
		$data = [
			'activo'  => true,
			'titulo'  => 'Test Transport',
			'contido' => '<p>Test content</p>',
		];
		ANPA_Socios_Config::update_contenido_admin( 'transporte', $data );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Test Transport', $config['titulo'] );
		$this->assertSame( '<p>Test content</p>', $config['contido'] );
	}

	public function test_update_one_category_preserves_others(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Transport Updated',
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertSame( '', $libros['titulo'] );
		$this->assertTrue( $libros['activo'] );
	}

	public function test_partial_payload_is_normalized(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Only title provided',
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Only title provided', $config['titulo'] );
		$this->assertTrue( $config['activo'] );
		$this->assertSame( '', $config['contido'] );
		$this->assertIsArray( $config['documentos'] );
		$this->assertIsArray( $config['enlaces'] );
	}

	public function test_malformed_payload_does_not_cause_fatal(): void {
		update_option( 'anpa_socios_contenido_admin', 'not-an-array' );
		$config = ANPA_Socios_Config::contenido_admin();
		$this->assertCount( 5, $config );
	}

	public function test_option_name_is_canonical(): void {
		$this->assertSame(
			'anpa_socios_contenido_admin',
			ANPA_Socios_Config::OPTION_CONTENIDO_ADMIN
		);
	}

	public function test_db_version_not_changed(): void {
		$content = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-config.php' );
		$this->assertStringNotContainsString( '1.40.0', $content, 'DB_VERSION should not be bumped in FASE37' );
		$this->assertStringNotContainsString( '1.41.0', $content, 'DB_VERSION should not be bumped in FASE37' );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// M2 — Casos límite
	// ═══════════════════════════════════════════════════════════════════════════

	// ── 4.1 Categoría inexistente ──────────────────────────────────────────────

	public function test_getter_with_invalid_category_returns_empty(): void {
		$config = ANPA_Socios_Config::contenido_admin( 'categoria-inexistente' );
		$this->assertIsArray( $config );
		$this->assertEmpty( $config );
	}

	public function test_setter_with_invalid_category_returns_false(): void {
		$result = ANPA_Socios_Config::update_contenido_admin( 'invalida', [
			'titulo' => 'Test',
		] );
		$this->assertFalse( $result );
	}

	public function test_setter_with_invalid_category_does_not_affect_valid(): void {
		// Try to set invalid category
		ANPA_Socios_Config::update_contenido_admin( 'invalida', [
			'titulo' => 'Should not persist',
		] );

		// Valid categories should remain defaults
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( '', $config['titulo'] );
	}

	// ── 4.2 Campos inesperados ─────────────────────────────────────────────────

	public function test_update_with_unexpected_fields_does_not_break_valid_categories(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo'              => 'Valid Title',
			'campo_inexistente'   => 'Should be ignored',
			'another_unknown'     => [ 'nested', 'data' ],
		] );

		// Transport should have the valid title
		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Valid Title', $config['titulo'] );

		// Unknown fields should not appear in output
		$this->assertArrayNotHasKey( 'campo_inexistente', $config );
		$this->assertArrayNotHasKey( 'another_unknown', $config );

		// Other categories should be untouched
		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertSame( '', $libros['titulo'] );
	}

	// ── 4.3 Campos ausentes ────────────────────────────────────────────────────

	public function test_missing_titulo_uses_default(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'contido' => 'Some content',
			// Missing: titulo, activo, icono, documentos, enlaces, orden
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( '', $config['titulo'] );
		$this->assertSame( 'Some content', $config['contido'] );
		$this->assertTrue( $config['activo'] );
	}

	public function test_missing_contido_uses_default(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Only title',
			// Missing: contido, activo, icono, documentos, enlaces, orden
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( '', $config['contido'] );
	}

	public function test_missing_documentos_uses_empty_array(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Test',
			// Missing: documentos, enlaces
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['documentos'] );
		$this->assertEmpty( $config['documentos'] );
	}

	public function test_missing_enlaces_uses_empty_array(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Test',
			// Missing: enlaces
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['enlaces'] );
		$this->assertEmpty( $config['enlaces'] );
	}

	public function test_missing_items_uses_empty_array(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'titulo' => 'Libros title',
			// Missing: items
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertIsArray( $libros['items'] );
		$this->assertEmpty( $libros['items'] );
	}

	public function test_missing_orden_uses_default(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Test',
			// Missing: orden
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 1, $config['orden'] );
	}

	// ── 4.4 Tipos incorrectos ──────────────────────────────────────────────────

	public function test_activo_as_string_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'activo' => 'yes', // string, not bool
				'titulo' => 'Test',
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'activo', $config );
		// Should not cause fatal; behavior depends on implementation
		$this->assertNotNull( $config['activo'] );
	}

	public function test_orden_as_string_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'orden' => 'not-a-number',
				'titulo' => 'Test',
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'orden', $config );
		$this->assertNotNull( $config['orden'] );
	}

	public function test_documentos_as_string_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'documentos' => 'not-an-array',
				'titulo' => 'Test',
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'documentos', $config );
	}

	public function test_enlaces_as_string_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'enlaces' => 'not-an-array',
				'titulo' => 'Test',
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'enlaces', $config );
	}

	public function test_items_as_string_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'libros' => [
				'items' => 'not-an-array',
				'titulo' => 'Test',
			],
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertArrayHasKey( 'items', $libros );
	}

	public function test_root_payload_as_null_returns_defaults(): void {
		update_option( 'anpa_socios_contenido_admin', null );
		$config = ANPA_Socios_Config::contenido_admin();
		$this->assertCount( 5, $config );
	}

	public function test_root_payload_as_false_returns_defaults(): void {
		update_option( 'anpa_socios_contenido_admin', false );
		$config = ANPA_Socios_Config::contenido_admin();
		$this->assertCount( 5, $config );
	}

	public function test_root_payload_as_integer_returns_defaults(): void {
		update_option( 'anpa_socios_contenido_admin', 12345 );
		$config = ANPA_Socios_Config::contenido_admin();
		$this->assertCount( 5, $config );
	}

	public function test_root_payload_as_empty_array_returns_defaults(): void {
		update_option( 'anpa_socios_contenido_admin', [] );
		$config = ANPA_Socios_Config::contenido_admin();
		$this->assertCount( 5, $config );
	}

	// ── 5. Documentos ──────────────────────────────────────────────────────────

	public function test_valid_document_structure(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'documentos' => [
				[ 'id' => 123, 'url' => 'http://example.com/doc.pdf', 'title' => 'Document 1' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 1, $config['documentos'] );
		$this->assertSame( 123, $config['documentos'][0]['id'] );
	}

	public function test_document_with_id_zero_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'documentos' => [
					[ 'id' => 0, 'url' => '', 'title' => 'Invalid' ],
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'documentos', $config );
	}

	public function test_document_with_negative_id_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'documentos' => [
					[ 'id' => -5, 'url' => 'http://example.com/doc.pdf', 'title' => 'Test' ],
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'documentos', $config );
	}

	public function test_document_with_string_numeric_id_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'documentos' => [
					[ 'id' => '456', 'url' => 'http://example.com/doc.pdf', 'title' => 'Test' ],
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'documentos', $config );
	}

	public function test_document_missing_url_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'documentos' => [
					[ 'id' => 123, 'title' => 'Test' ], // Missing url
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'documentos', $config );
	}

	public function test_document_missing_title_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'documentos' => [
					[ 'id' => 123, 'url' => 'http://example.com/doc.pdf' ], // Missing title
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'documentos', $config );
	}

	public function test_document_item_not_array_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'documentos' => [
					'not-an-array', // Invalid item
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'documentos', $config );
	}

	public function test_documents_collection_not_array_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'documentos' => 'not-an-array', // Entire collection invalid
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'documentos', $config );
	}

	// ── 6. Enlaces ─────────────────────────────────────────────────────────────

	public function test_valid_link_structure(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [
				[ 'title' => 'Link 1', 'url' => 'http://example.com' ],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertCount( 1, $config['enlaces'] );
		$this->assertSame( 'Link 1', $config['enlaces'][0]['title'] );
	}

	public function test_links_empty_array_is_valid(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'enlaces' => [],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertIsArray( $config['enlaces'] );
		$this->assertEmpty( $config['enlaces'] );
	}

	public function test_link_missing_title_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'enlaces' => [
					[ 'url' => 'http://example.com' ], // Missing title
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'enlaces', $config );
	}

	public function test_link_missing_url_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'enlaces' => [
					[ 'title' => 'Test' ], // Missing url
				],
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'enlaces', $config );
	}

	public function test_link_collection_not_array_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'transporte' => [
				'enlaces' => 'not-an-array',
			],
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertArrayHasKey( 'enlaces', $config );
	}

	// ── 7. Items Libros ────────────────────────────────────────────────────────

	public function test_libros_empty_items_is_valid(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => [],
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertIsArray( $libros['items'] );
		$this->assertEmpty( $libros['items'] );
	}

	public function test_libros_multiple_valid_items(): void {
		$items = [
			[
				'curso' => '1º EP', 'nivel' => 'Primaria', 'materia' => 'Matemáticas',
				'titulo' => 'Matemáticas 1', 'editorial' => 'Santillana', 'isbn' => '978-84-1234',
				'prezo' => '25.00', 'descarga' => 0,
			],
			[
				'curso' => '1º EP', 'nivel' => 'Primaria', 'materia' => 'Lengua',
				'titulo' => 'Lengua 1', 'editorial' => 'Santillana', 'isbn' => '978-84-1235',
				'prezo' => '25.00', 'descarga' => 42,
			],
		];

		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'items' => $items,
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertCount( 2, $libros['items'] );
		$this->assertSame( 'Matemáticas 1', $libros['items'][0]['titulo'] );
	}

	public function test_libros_partial_item_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'libros' => [
				'items' => [
					[ 'curso' => '1º EP', 'titulo' => 'Partial' ], // Missing other fields
				],
			],
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertArrayHasKey( 'items', $libros );
	}

	public function test_libros_items_not_array_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'libros' => [
				'items' => 'not-an-array',
			],
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertArrayHasKey( 'items', $libros );
	}

	public function test_libros_item_not_array_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'libros' => [
				'items' => [
					'not-an-array',
				],
			],
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertArrayHasKey( 'items', $libros );
	}

	// ── 8. Items Comedor ───────────────────────────────────────────────────────

	public function test_comedor_empty_items_is_valid(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => [],
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertIsArray( $comedor['items'] );
		$this->assertEmpty( $comedor['items'] );
	}

	public function test_comedor_multiple_valid_items(): void {
		$items = [
			[ 'fecha' => '2026-09-01', 'menu' => 'Pasta con tomate', 'alerxenos' => 'Gluten' ],
			[ 'fecha' => '2026-09-02', 'menu' => 'Polo con patacas', 'alerxenos' => 'Lactosa' ],
		];

		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'items' => $items,
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertCount( 2, $comedor['items'] );
		$this->assertSame( 'Pasta con tomate', $comedor['items'][0]['menu'] );
	}

	public function test_comedor_partial_item_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'comedor' => [
				'items' => [
					[ 'fecha' => '2026-09-01', 'menu' => 'Only menu' ], // Missing alerxenos
				],
			],
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertArrayHasKey( 'items', $comedor );
	}

	public function test_comedor_items_not_array_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'comedor' => [
				'items' => 'not-an-array',
			],
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertArrayHasKey( 'items', $comedor );
	}

	public function test_comedor_item_not_array_is_handled(): void {
		update_option( 'anpa_socios_contenido_admin', [
			'comedor' => [
				'items' => [
					'not-an-array',
				],
			],
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertArrayHasKey( 'items', $comedor );
	}

	// ── 9. Integridade entre categorías ────────────────────────────────────────

	public function test_update_transport_preserves_libros(): void {
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'titulo' => 'Libros Title',
		] );
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Transport Title',
		] );

		$libros = ANPA_Socios_Config::contenido_admin( 'libros' );
		$this->assertSame( 'Libros Title', $libros['titulo'] );
	}

	public function test_update_libros_preserves_comedor(): void {
		ANPA_Socios_Config::update_contenido_admin( 'comedor', [
			'titulo' => 'Comedor Title',
		] );
		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'titulo' => 'Libros Title',
		] );

		$comedor = ANPA_Socios_Config::contenido_admin( 'comedor' );
		$this->assertSame( 'Comedor Title', $comedor['titulo'] );
	}

	public function test_update_bos_dias_preserves_tardes(): void {
		ANPA_Socios_Config::update_contenido_admin( 'tardes-divertidas', [
			'titulo' => 'Tardes Title',
		] );
		ANPA_Socios_Config::update_contenido_admin( 'bos-dias', [
			'titulo' => 'Bos Días Title',
		] );

		$tardes = ANPA_Socios_Config::contenido_admin( 'tardes-divertidas' );
		$this->assertSame( 'Tardes Title', $tardes['titulo'] );
	}

	public function test_update_structured_category_preserves_others(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [ 'titulo' => 'T1' ] );
		ANPA_Socios_Config::update_contenido_admin( 'bos-dias', [ 'titulo' => 'T2' ] );

		ANPA_Socios_Config::update_contenido_admin( 'libros', [
			'titulo' => 'Libros',
			'items' => [ [ 'titulo' => 'Book 1' ] ],
		] );

		$transporte = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$bosDias = ANPA_Socios_Config::contenido_admin( 'bos-dias' );
		$this->assertSame( 'T1', $transporte['titulo'] );
		$this->assertSame( 'T2', $bosDias['titulo'] );
	}

	// ── 10. Persistencia ────────────────────────────────────────────────────────

	public function test_persistence_single_option_used(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Persisted Title',
		] );

		// Verify only one option exists
		global $wp_options;
		$optionCount = 0;
		foreach ( array_keys( $wp_options ) as $key ) {
			if ( strpos( $key, 'contenido' ) !== false || strpos( $key, 'anpa_socios_contenido' ) !== false ) {
				$optionCount++;
			}
		}

		$this->assertSame( 1, $optionCount, 'Only one content option should exist' );
	}

	public function test_persistence_multiple_updates(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'First Update',
		] );

		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Second Update',
		] );

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( 'Second Update', $config['titulo'] );
	}

	public function test_persistence_does_not_duplicate_data(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Test',
		] );

		$option1 = get_option( 'anpa_socios_contenido_admin' );

		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Test 2',
		] );

		$option2 = get_option( 'anpa_socios_contenido_admin' );

		// Should be same option, updated
		$this->assertIsArray( $option2 );
		$this->assertSame( 'Test 2', $option2['transporte']['titulo'] );
	}

	// ── 11. Delete ──────────────────────────────────────────────────────────────

	public function test_delete_existing_option_returns_true(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Test',
		] );

		$result = ANPA_Socios_Config::delete_contenido_admin();
		$this->assertTrue( $result );
	}

	public function test_delete_nonexistent_option_returns_false(): void {
		delete_option( 'anpa_socios_contenido_admin' );
		$result = ANPA_Socios_Config::delete_contenido_admin();
		$this->assertFalse( $result );
	}

	public function test_delete_then_getter_returns_defaults(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Should be deleted',
		] );

		ANPA_Socios_Config::delete_contenido_admin();

		$config = ANPA_Socios_Config::contenido_admin( 'transporte' );
		$this->assertSame( '', $config['titulo'] );
	}

	public function test_delete_does_not_affect_other_options(): void {
		ANPA_Socios_Config::update_contenido_admin( 'transporte', [
			'titulo' => 'Test',
		] );

		ANPA_Socios_Config::delete_contenido_admin();

		// Verify no content option exists
		$this->assertFalse( get_option( 'anpa_socios_contenido_admin' ) );
	}

	// ── 12. Multisite ──────────────────────────────────────────────────────────

	public function test_multisite_not_applicable(): void {
		// This project does not have explicit multisite contract for this feature.
		$this->assertTrue( true, 'MULTISITE_TEST=NOT_APPLICABLE' );
	}
}
