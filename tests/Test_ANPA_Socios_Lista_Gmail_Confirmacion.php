<?php
/**
 * 1.66.0: «Lista Gmail» — the export is only RECORDED once the junta confirms the browser saved the
 * file. Downloading leaves a pending export (option); «Si, gardouse» promotes it to the snapshot,
 * «Non se descargou» discards it. Before 1.66.0 a blocked download (corporate browser policies) still
 * reset altas/baixas to zero and showed «CSV descargado».
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Lista_Gmail_Confirmacion extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function pendente(): array {
		return array(
			'exportado_en' => '2026-09-16 19:00:00',
			'por'          => 'xunta@example.com',
			'tipo'         => 'novas',
			'total'        => 3,
			'exportados'   => 1,
			'socios'       => array(
				array( 'email' => 'a@example.com', 'nome' => 'A', 'apelidos' => 'Alfa' ),
				array( 'email' => 'b@example.com', 'nome' => 'B', 'apelidos' => 'Beta' ),
				array( 'email' => 'c@example.com', 'nome' => 'C', 'apelidos' => 'Gamma' ),
			),
		);
	}

	public function test_snapshot_is_built_from_the_pending_export_and_keeps_the_download_time(): void {
		$snap = ANPA_Socios_Admin_Contactos_Google_Handler::snapshot_desde_pendente( $this->pendente(), '2026-09-16 19:05:00' );
		$this->assertIsArray( $snap );
		$this->assertSame( '2026-09-16 19:00:00', $snap['exportado_en'], 'the export time is when the file was produced' );
		$this->assertSame( '2026-09-16 19:05:00', $snap['confirmado_en'] );
		$this->assertSame( 'xunta@example.com', $snap['por'] );
		$this->assertSame( 'novas', $snap['tipo'] );
		$this->assertSame( 3, $snap['total'] );
		$this->assertSame( 1, $snap['exportados'] );
		$this->assertSame( array( 'a@example.com', 'b@example.com', 'c@example.com' ), array_column( $snap['socios'], 'email' ) );
	}

	public function test_snapshot_is_null_without_a_pending_export(): void {
		$this->assertNull( ANPA_Socios_Admin_Contactos_Google_Handler::snapshot_desde_pendente( null, '2026-09-16 19:05:00' ) );
		$this->assertNull( ANPA_Socios_Admin_Contactos_Google_Handler::snapshot_desde_pendente( array(), '2026-09-16 19:05:00' ) );
		$this->assertNull( ANPA_Socios_Admin_Contactos_Google_Handler::snapshot_desde_pendente( array( 'tipo' => 'completa' ), '2026-09-16 19:05:00' ), 'no socios → nothing to record' );
	}

	public function test_snapshot_defaults_are_neutral(): void {
		$snap = ANPA_Socios_Admin_Contactos_Google_Handler::snapshot_desde_pendente( array( 'socios' => array( array( 'email' => 'a@example.com' ) ) ), '2026-09-16 19:05:00' );
		$this->assertSame( 'completa', $snap['tipo'] );
		$this->assertSame( 1, $snap['total'] );
		$this->assertSame( 1, $snap['exportados'] );
		$this->assertSame( '', $snap['por'] );
	}

	public function test_download_stores_a_pending_export_and_never_the_snapshot(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "const OPTION_PENDENTE = 'anpa_socios_contactos_google_pendente';", $h );
		$start = strpos( $h, 'public static function export(' );
		$end   = strpos( $h, 'public static function', $start + 10 );
		$this->assertNotFalse( $start );
		$body = substr( $h, $start, false === $end ? null : $end - $start );
		$this->assertMatchesRegularExpression( '/update_option\(\s*self::OPTION_PENDENTE/', $body );
		$this->assertDoesNotMatchRegularExpression( '/update_option\(\s*self::OPTION_SNAPSHOT/', $body, 'export() must not record the snapshot: only the confirmation does' );
	}

	public function test_confirm_and_discard_routes_are_master_only(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		foreach ( array( "'/contactos-google/exportacion/confirmar'", "'/contactos-google/exportacion/descartar'" ) as $route ) {
			$this->assertStringContainsString( $route, $h );
		}
		$this->assertSame( 5, preg_match_all( '/\'permission_callback\' => array\( \'ANPA_Socios_Admin_Shared\', \'permission_master\' \)/', $h ), 'estado, export, inicio-curso, confirmar, descartar' );
		$this->assertStringContainsString( "'exportacion_pendente'", $h, 'estado() tells the panel about an unconfirmed download' );
	}

	public function test_panel_asks_for_confirmation_instead_of_announcing_the_download(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "'contactos-google/exportacion/confirmar'", $js );
		$this->assertStringContainsString( "'contactos-google/exportacion/descartar'", $js );
		$this->assertStringNotContainsString( "'CSV descargado. Agora impórtao en Google Contactos", $js, 'the panel cannot know the browser saved the file' );
		$this->assertStringNotContainsString( "showMessage('CSV coas altas novas descargado.", $js );
	}
}
