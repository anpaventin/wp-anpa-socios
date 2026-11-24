<?php
/**
 * 1.100.0: board roles kept in the plugin and rendered as cards.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Xunta_Directiva extends TestCase {

	public function test_sanitizar_drops_empty_roles_sorts_and_trims(): void {
		$rows = ANPA_Socios_Xunta_Directiva::sanitizar( array(
			array( 'orde' => '20', 'cargo' => '  Secretaría ', 'nome' => 'Lucía  B.' ),
			array( 'orde' => '10', 'cargo' => 'Presidencia', 'nome' => '<b>Noemí</b>' ),
			array( 'orde' => '', 'cargo' => '', 'nome' => 'sen cargo' ),
			array( 'orde' => '', 'cargo' => 'Vogal', 'nome' => '' ),
			'lixo',
		) );
		$this->assertSame( array(
			array( 'cargo' => 'Presidencia', 'nome' => 'Noemí' ),
			array( 'cargo' => 'Secretaría', 'nome' => 'Lucía B.' ),
			array( 'cargo' => 'Vogal', 'nome' => '' ),
		), $rows );
	}

	public function test_sanitizar_caps_the_list(): void {
		$many = array();
		for ( $i = 0; $i < 40; $i++ ) { $many[] = array( 'cargo' => 'Vogal', 'nome' => "N$i" ); }
		$this->assertCount( ANPA_Socios_Xunta_Directiva::MAX, ANPA_Socios_Xunta_Directiva::sanitizar( $many ) );
	}

	public function test_icon_by_role(): void {
		$this->assertSame( 'user-round', ANPA_Socios_Xunta_Directiva::icona( 'Vicepresidencia' ) );
		$this->assertSame( 'clipboard-list', ANPA_Socios_Xunta_Directiva::icona( 'Secretaría' ) );
		$this->assertSame( 'notebook-pen', ANPA_Socios_Xunta_Directiva::icona( 'Vicesecretaría' ) );
		$this->assertSame( 'wallet', ANPA_Socios_Xunta_Directiva::icona( 'Tesourería' ) );
		$this->assertSame( 'credit-card', ANPA_Socios_Xunta_Directiva::icona( 'Vicetesourería' ) );
		$this->assertSame( 'users', ANPA_Socios_Xunta_Directiva::icona( 'Vogal 1' ) );
		$this->assertSame( 'user', ANPA_Socios_Xunta_Directiva::icona( 'Coordinación de comedor' ) );
	}

	public function test_cards_markup(): void {
		$this->assertSame( '', ANPA_Socios_Xunta_Directiva::html( array() ) );
		$html = ANPA_Socios_Xunta_Directiva::html( array( array( 'cargo' => 'Vogal', 'nome' => 'Ana <x>' ), array( 'cargo' => 'Tesourería', 'nome' => '' ) ) );
		$this->assertStringStartsWith( '<div class="anpa-card-grid anpa-xunta-directiva"><div class="anpa-card anpa-xunta-cargo"><p class="anpa-icon-circle"><svg class="anpa-icon anpa-icon--users"', $html );
		$this->assertStringContainsString( '<h4 class="wp-block-heading">Vogal</h4><p>Ana &lt;x&gt;</p>', $html );
		$this->assertStringContainsString( '<h4 class="wp-block-heading">Tesourería</h4></div>', $html, 'no empty name paragraph' );
	}

	public function test_admin_section_and_wiring(): void {
		$this->assertArrayHasKey( 'xunta-directiva', ANPA_Socios_Admin_Nav::settings_sections( 'contenido' ) );
		$this->assertNotContains( 'xunta-directiva', ANPA_Socios_Config::CATEGORIAS_VALIDAS );
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-xunta-directiva-page.php' );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $src );
		$this->assertStringContainsString( "check_admin_referer( 'anpa_save_xunta_directiva' )", $src );
		$main = (string) file_get_contents( dirname( __DIR__ ) . '/anpa-socios.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Xunta_Directiva_Page::register();', $main );
	}
}
