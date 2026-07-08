<?php
/**
 * Unit tests for the native settings tabs helper (fase13a).
 *
 * @since  1.25.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Settings_Tabs extends TestCase {

	public function test_default_tab_is_xeral(): void {
		$this->assertSame( 'xeral', ANPA_Socios_Settings_Tabs::default_tab() );
	}

	public function test_all_has_the_expected_tabs_in_order(): void {
		$this->assertSame(
			array( 'xeral', 'localizacion', 'verificacion', 'actualizacions', 'mantemento' ),
			array_keys( ANPA_Socios_Settings_Tabs::all() )
		);
	}

	public function test_localizacion_tab_is_valid(): void {
		$this->assertTrue( ANPA_Socios_Settings_Tabs::is_valid( 'localizacion' ) );
		$this->assertSame( 'Localización e idioma', ANPA_Socios_Settings_Tabs::label( 'localizacion' ) );
	}

	public function test_is_valid_accepts_known_and_rejects_unknown(): void {
		$this->assertTrue( ANPA_Socios_Settings_Tabs::is_valid( 'verificacion' ) );
		$this->assertTrue( ANPA_Socios_Settings_Tabs::is_valid( 'mantemento' ) );
		$this->assertFalse( ANPA_Socios_Settings_Tabs::is_valid( 'unknown' ) );
		$this->assertFalse( ANPA_Socios_Settings_Tabs::is_valid( '' ) );
		$this->assertFalse( ANPA_Socios_Settings_Tabs::is_valid( null ) );
		$this->assertFalse( ANPA_Socios_Settings_Tabs::is_valid( 123 ) );
	}

	public function test_active_returns_requested_when_valid(): void {
		$this->assertSame( 'actualizacions', ANPA_Socios_Settings_Tabs::active( 'actualizacions' ) );
	}

	public function test_active_falls_back_to_default_when_invalid(): void {
		$this->assertSame( 'xeral', ANPA_Socios_Settings_Tabs::active( 'nope' ) );
		$this->assertSame( 'xeral', ANPA_Socios_Settings_Tabs::active( null ) );
		$this->assertSame( 'xeral', ANPA_Socios_Settings_Tabs::active( '' ) );
	}

	public function test_label_returns_label_or_empty(): void {
		$this->assertSame( 'Verificación', ANPA_Socios_Settings_Tabs::label( 'verificacion' ) );
		$this->assertSame( '', ANPA_Socios_Settings_Tabs::label( 'unknown' ) );
	}
}
