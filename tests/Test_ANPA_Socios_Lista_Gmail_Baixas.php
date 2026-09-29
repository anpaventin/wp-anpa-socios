<?php
/**
 * 1.82.0: Lista Gmail shows the deregistered emails comma-separated (to delete a
 * few by hand in Google) and «poñer as baixas a 0» once they were deleted.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Lista_Gmail_Baixas extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_baixas_leave_the_snapshot_and_the_rest_stays(): void {
		require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-contactos-google-handler.php';
		$snapshot = array(
			'exportado_en' => '2026-09-20 10:00:00',
			'tipo'         => 'completa',
			'total'        => 3,
			'socios'       => array(
				array( 'email' => 'a@example.com', 'nome' => 'A', 'apelidos' => '' ),
				array( 'email' => 'B@example.com', 'nome' => 'B', 'apelidos' => '' ),
				array( 'email' => 'c@example.com', 'nome' => 'C', 'apelidos' => '' ),
			),
		);
		$r = ANPA_Socios_Admin_Contactos_Google_Handler::snapshot_sen_baixas( $snapshot, array( 'a@example.com', 'b@example.com', 'nova@example.com' ), '2026-09-30 12:00:00' );
		$this->assertSame( 1, $r['eliminadas'] );
		$this->assertSame( array( 'a@example.com', 'B@example.com' ), array_column( $r['snapshot']['socios'], 'email' ) );
		$this->assertSame( 2, $r['snapshot']['total'] );
		$this->assertSame( '2026-09-20 10:00:00', $r['snapshot']['exportado_en'], 'the export date does not change' );
		$this->assertSame( '2026-09-30 12:00:00', $r['snapshot']['baixas_eliminadas_en'] );
		// After it, the counter is 0 and the new alta is still pending.
		$previos = ANPA_Socios_Admin_Contactos_Google_Handler::socios_do_snapshot( $r['snapshot'] );
		$this->assertSame( 1, ANPA_Socios_Admin_Contactos_Google_Handler::conta_cambios( array( 'a@example.com', 'b@example.com', 'nova@example.com' ), $previos ) );
	}

	public function test_route_panel_and_audit(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$i = strpos( $h, "'/contactos-google/baixas/eliminadas'" );
		$this->assertNotFalse( $i );
		$this->assertStringContainsString( "'permission_master'", substr( $h, (int) $i, 300 ) );
		$this->assertStringContainsString( "'baixas_eliminadas_google'", $h );
		$js = $this->src( 'assets/js/admin-management.js' );
		// 1.84.0: the comma list is copied from the button under the baixas table.
		$this->assertStringContainsString( "var txtBaixas = baixas.map(function (b) { return b.email; }).join(', ');", $js );
		$this->assertStringContainsString( "anpaAdminFetch('contactos-google/baixas/eliminadas', { method: 'POST' })", $js );
		$this->assertSame( 'Baixas eliminadas a man en Google Contactos', ANPA_Socios_Auditoria::etiqueta( 'baixas_eliminadas_google', 'export' ) );
	}
}
