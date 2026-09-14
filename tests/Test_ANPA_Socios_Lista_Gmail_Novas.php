<?php
/**
 * 1.61.0: «Lista Gmail» — export of the members that joined since the last export
 * (to add them to the Google Contacts label without deleting it first).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Lista_Gmail_Novas extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function socio( string $email, string $nome = 'Nome', string $apelidos = 'Apelidos' ): array {
		return array( 'email' => $email, 'nome' => $nome, 'apelidos' => $apelidos );
	}

	public function test_new_members_are_those_missing_from_the_snapshot_in_listing_order(): void {
		$snapshot = array( 'socios' => array( $this->socio( 'A@Example.org', 'Ana', 'Alonso' ), $this->socio( 'b@example.org' ) ) );
		$previos  = ANPA_Socios_Admin_Contactos_Google_Handler::socios_do_snapshot( $snapshot );
		$this->assertSame( array( 'a@example.org', 'b@example.org' ), array_keys( $previos ), 'snapshot emails are lower-cased' );

		$actuais = array( $this->socio( 'c@example.org', 'Carla' ), $this->socio( 'a@example.org', 'Ana' ), $this->socio( 'd@example.org', 'Dani' ) );
		$novos   = ANPA_Socios_Admin_Contactos_Google_Handler::socios_novos( $actuais, $previos );
		$this->assertSame( array( 'c@example.org', 'd@example.org' ), array_column( $novos, 'email' ) );
		$this->assertSame( 'Carla', $novos[0]['nome'] );
	}

	public function test_without_a_previous_export_everyone_is_new(): void {
		$previos = ANPA_Socios_Admin_Contactos_Google_Handler::socios_do_snapshot( null );
		$this->assertSame( array(), $previos );
		$actuais = array( $this->socio( 'a@example.org' ), $this->socio( 'b@example.org' ) );
		$this->assertCount( 2, ANPA_Socios_Admin_Contactos_Google_Handler::socios_novos( $actuais, $previos ) );
	}

	public function test_snapshot_after_a_partial_export_keeps_the_previous_members_and_adds_the_new_ones(): void {
		$previos    = ANPA_Socios_Admin_Contactos_Google_Handler::socios_do_snapshot( array( 'socios' => array( $this->socio( 'a@example.org' ), $this->socio( 'baixa@example.org' ) ) ) );
		$exportados = array( $this->socio( 'c@example.org' ) );

		$tras_novas = ANPA_Socios_Admin_Contactos_Google_Handler::socios_tras_exportacion( true, $exportados, $previos );
		$this->assertSame( array( 'a@example.org', 'baixa@example.org', 'c@example.org' ), array_column( $tras_novas, 'email' ), 'the baixa stays recorded: it is still in Google' );

		$tras_completa = ANPA_Socios_Admin_Contactos_Google_Handler::socios_tras_exportacion( false, array( $this->socio( 'a@example.org' ), $this->socio( 'c@example.org' ) ), $previos );
		$this->assertSame( array( 'a@example.org', 'c@example.org' ), array_column( $tras_completa, 'email' ), 'a full export replaces the label, so the snapshot is exactly what was exported' );
	}

	public function test_baixas_keep_showing_after_a_partial_export(): void {
		// Simulates estado() after a "novas" export: previous snapshot ∪ new members vs. current actives.
		$previos = ANPA_Socios_Admin_Contactos_Google_Handler::socios_do_snapshot( array( 'socios' => array( $this->socio( 'a@example.org' ), $this->socio( 'baixa@example.org' ), $this->socio( 'c@example.org' ) ) ) );
		$actuais = array( $this->socio( 'a@example.org' ), $this->socio( 'c@example.org' ) );
		$this->assertSame( array(), ANPA_Socios_Admin_Contactos_Google_Handler::socios_novos( $actuais, $previos ) );
		$baixas = array_values( array_diff_key( $previos, ANPA_Socios_Admin_Contactos_Google_Handler::por_email( $actuais ) ) );
		$this->assertSame( array( 'baixa@example.org' ), array_column( $baixas, 'email' ) );
	}

	public function test_export_endpoint_handles_the_ambito_and_records_the_type(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "\$so_novas = 'novas' === sanitize_key( (string) \$request->get_param( 'ambito' ) );", $h );
		$this->assertStringContainsString( '$socios   = $so_novas ? self::socios_novos( $actuais, $previos ) : $actuais;', $h );
		$this->assertStringContainsString( '$gardados = self::socios_tras_exportacion( $so_novas, $socios, $previos );', $h );
		$this->assertStringContainsString( "'tipo'         => \$so_novas ? 'novas' : 'completa',", $h );
		$this->assertStringContainsString( "\$so_novas ? 'export_csv_novas' : 'export_csv'", $h );
		$this->assertStringContainsString( "( \$so_novas ? 'novas-' : '' ) . gmdate( 'Y-m-d' )", $h );
		$this->assertStringContainsString( "'tipo'         => (string) ( \$snapshot['tipo'] ?? 'completa' ),", $h );
		// estado() reuses the same pure helpers as export(), so both agree on who is new.
		$this->assertStringContainsString( '$altas  = self::socios_novos( $actuais, $previos );', $h );
	}

	public function test_js_has_the_second_button_disabled_without_new_members_and_the_skip_step_4_note(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "anpaAdminFetch('contactos-google/export?ambito=novas')", $js );
		$this->assertStringContainsString( "'Descargar só as altas novas (' + altas.length + ') (CSV)'", $js );
		$this->assertStringContainsString( 'dlNovas.disabled = !ultima || !altas.length;', $js );
		$this->assertStringContainsString( "'socios-web-anpa-google-novas-' + new Date().toISOString().slice(0, 10) + '.csv'", $js );
		$this->assertStringContainsString( 'SEN eliminar a etiqueta (salta o paso 4)', $js );
		$this->assertStringContainsString( 'acts.appendChild(dl); acts.appendChild(dlNovas); acts.appendChild(open);', $js );
		// The full export button and its literal fetch stay untouched (1.58.0 contract).
		$this->assertStringContainsString( "anpaAdminFetch('contactos-google/export')", $js );

		$docs = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( '«Descargar só as altas novas» dá un CSV cos correos novos para importalos SEN eliminar a etiqueta', $docs );
	}
}
