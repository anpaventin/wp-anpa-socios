<?php
/**
 * 1.101.0: event date of posts and the «Próximos eventos» block.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Eventos extends TestCase {

	public function test_limpar_validates_and_drops_a_bad_end(): void {
		$this->assertSame( array( 'data' => '2026-12-18', 'hora' => '16:30', 'fin' => '', 'ata' => '2026-12-18' ), ANPA_Socios_Eventos::limpar( '2026-12-18', '16:30', '2026-12-18' ) );
		$this->assertSame( array( 'data' => '2026-12-14', 'hora' => '', 'fin' => '2026-12-18', 'ata' => '2026-12-18' ), ANPA_Socios_Eventos::limpar( '2026-12-14', '25:00', '2026-12-18' ) );
		$this->assertSame( '', ANPA_Socios_Eventos::limpar( '2026-02-30', '10:00', '' )['data'] );
		$this->assertSame( '', ANPA_Socios_Eventos::limpar( '', '', '2026-12-18' )['ata'] );
	}

	public function test_proximos_keeps_coming_and_running_events_in_order(): void {
		$rows = array(
			array( 'id' => 1, 'data' => '2026-11-20', 'ata' => '2026-11-20' ),
			array( 'id' => 2, 'data' => '2026-12-18', 'hora' => '17:00', 'ata' => '2026-12-18' ),
			array( 'id' => 3, 'data' => '2026-11-23', 'ata' => '2026-11-27' ),
			array( 'id' => 4, 'data' => '2026-12-18', 'hora' => '10:00', 'ata' => '2026-12-18' ),
			array( 'id' => 5, 'data' => '2027-01-10', 'ata' => '2027-01-10' ),
		);
		$ids = array_column( ANPA_Socios_Eventos::proximos( $rows, '2026-11-24', 3 ), 'id' );
		$this->assertSame( array( 3, 4, 2 ), $ids, 'past one out, running one in, same day by time, capped at 3' );
	}

	public function test_labels_in_galician(): void {
		$this->assertSame( array( 'dia' => 'ven', 'num' => '30', 'mes' => 'out' ), ANPA_Socios_Eventos::chip( '2026-10-30' ) );
		$this->assertSame( 'venres 30 de outubro, ás 16:30', ANPA_Socios_Eventos::etiqueta( '2026-10-30', '16:30' ) );
		$this->assertSame( 'do luns 14 ao venres 18 de decembro', ANPA_Socios_Eventos::etiqueta( '2026-12-14', '', '2026-12-18' ) );
		$this->assertSame( 'do luns 28 de decembro ao sábado 2 de xaneiro', ANPA_Socios_Eventos::etiqueta( '2026-12-28', '', '2027-01-02' ) );
	}

	public function test_wiring_and_editor_box_safety(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-eventos-page.php' );
		$this->assertStringContainsString( "wp_verify_nonce( sanitize_text_field( wp_unslash( \$_POST['anpa_evento_nonce'] ) ), 'anpa_evento_' . \$post_id )", $src );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$post_id )", $src );
		$this->assertStringContainsString( "'key' => ANPA_Socios_Eventos::META_ATA, 'value' => \$hoxe, 'compare' => '>='", $src );
		$this->assertStringContainsString( "if ( array() === \$eventos ) {\n\t\t\treturn '';", $src, 'no events = nothing printed' );
		$this->assertStringContainsString( 'ANPA_Socios_Eventos_Page::register();', (string) file_get_contents( dirname( __DIR__ ) . '/anpa-socios.php' ) );
		$this->assertFileExists( dirname( __DIR__ ) . '/assets/css/eventos.css' );
	}
}
