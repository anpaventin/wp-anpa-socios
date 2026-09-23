<?php
/**
 * 1.58.0: Xestión → Socios → Lista Gmail — Google Contacts CSV export + comparison with the last export.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Lista_Gmail extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function load_handler(): void {
		if ( ! class_exists( 'ANPA_Socios_Csv', false ) ) {
			require_once dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-csv.php';
		}
		require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-contactos-google-handler.php';
	}

	public function test_csv_uses_googles_own_header_row_without_bom_and_crlf(): void {
		$this->load_handler();
		$csv   = ANPA_Socios_Admin_Contactos_Google_Handler::build_csv( array() );
		$lines = explode( "\r\n", $csv );
		$this->assertStringStartsNotWith( "\xEF\xBB\xBF", $csv, 'a BOM in front of "First Name" breaks Google\'s header matching' );
		$this->assertSame(
			'"First Name","Middle Name","Last Name","Phonetic First Name","Phonetic Middle Name","Phonetic Last Name","Name Prefix","Name Suffix","Nickname","File As","Organization Name","Organization Title","Organization Department","Birthday","Notes","Photo","Labels","E-mail 1 - Label","E-mail 1 - Value","Phone 1 - Label","Phone 1 - Value"',
			$lines[0]
		);
		$this->assertSame( '', $lines[1], 'document ends with CRLF' );
		$this->assertCount( 21, ANPA_Socios_Admin_Contactos_Google_Handler::GOOGLE_HEADERS );
	}

	public function test_csv_rows_put_name_surname_email_and_label_in_the_right_columns(): void {
		$this->load_handler();
		$csv  = ANPA_Socios_Admin_Contactos_Google_Handler::build_csv( array(
			array( 'email' => 'nai@example.org', 'nome' => 'María', 'apelidos' => 'Pérez Souto' ),
			array( 'email' => 'pai@example.org', 'nome' => 'Xoán', 'apelidos' => 'Vidal' ),
		) );
		$rows = array_values( array_filter( explode( "\r\n", $csv ) ) );
		$this->assertCount( 3, $rows );
		$r1 = str_getcsv( $rows[1] );
		$this->assertCount( 21, $r1 );
		$this->assertSame( 'María', $r1[0] );
		$this->assertSame( '', $r1[1] );
		$this->assertSame( 'Pérez Souto', $r1[2] );
		$this->assertSame( 'Socios Web ANPA ::: * myContacts', $r1[16] );
		$this->assertSame( '', $r1[17] );
		$this->assertSame( 'nai@example.org', $r1[18] );
		$this->assertSame( 'pai@example.org', str_getcsv( $rows[2] )[18] );
		$this->assertSame( 'Socios Web ANPA', ANPA_Socios_Admin_Contactos_Google_Handler::LABEL );
	}

	public function test_only_active_non_master_members_are_listed_and_pending_baixas_still_count(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "WHERE estado = 'activo' AND rol <> 'master' AND email <> ''", $h );
		$this->assertStringContainsString( "WHERE estado = 'activo' AND baixa_estado = 'solicitada' AND rol <> 'master'", $h );
		$this->assertStringContainsString( "'baixas_sen_confirmar' => \$pendentes,", $h );
		$this->assertStringContainsString( "update_option(\n			self::OPTION_PENDENTE,", $h ); // 1.66.0: the download is pending until confirmed; only confirmar_exportacion() writes OPTION_SNAPSHOT.
		$this->assertStringContainsString( "write_audit( \$request, 'export', 'contactos-google', \$so_novas ? 'export_csv_novas' : 'export_csv' )", $h );
		// SQL literals stay ASCII (wpdb invalid-text parser, see 1.56.3).
		preg_match_all( '/"\s*SELECT\b.*?"/s', $h, $m );
		foreach ( $m[0] as $sql ) {
			$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $sql ) );
		}
	}

	public function test_handler_routes_nav_and_bootstrap(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "'/contactos-google/estado'", $h );
		$this->assertStringContainsString( "'/contactos-google/export'", $h );
		// estado + export + inicio-curso (1.62.0) + exportacion/confirmar + exportacion/descartar (1.66.0): master only, all of them.
		$this->assertSame( 5, substr_count( $h, "'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' )" ) );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Contactos_Google_Handler::register_routes();', $this->src( 'includes/class-anpa-socios-admin-rest.php' ) );
		$this->assertStringContainsString( "includes/class-anpa-socios-admin-contactos-google-handler.php';", $this->src( 'anpa-socios.php' ) );
		require_once dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-admin-nav.php';
		$keys = array_keys( ANPA_Socios_Admin_Nav::management_sections()['socios']['sections'] );
		$this->assertSame( array_search( 'baixas', $keys, true ) + 1, array_search( 'lista-gmail', $keys, true ) );
	}

	public function test_google_url_pins_the_configured_account(): void {
		$cfg = $this->src( 'includes/class-anpa-socios-config.php' );
		$this->assertStringContainsString( "const OPTION_GOOGLE_CONTACTS_EMAIL = 'anpa_socios_google_contacts_email';", $cfg );
		$this->assertStringContainsString( 'public static function google_contacts_email(): string {', $cfg );
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "'https://contacts.google.com/' . ( '' !== \$account ? '?authuser=' . rawurlencode( \$account ) : '' )", $h );
		$settings = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( 'name="google_contacts_email"', $settings );
		$this->assertStringContainsString( "array_key_exists( 'google_contacts_email', \$_POST )", $settings );
		$this->assertStringContainsString( "'google_email_invalid'", $settings );
	}

	public function test_js_section_has_the_buttons_the_steps_and_the_unconfirmed_baixas_warning(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "'lista-gmail': loadListaGmail,", $js );
		$this->assertStringContainsString( "anpaAdminFetch('contactos-google/estado')", $js );
		$this->assertStringContainsString( "anpaAdminFetch('contactos-google/export')", $js );
		$this->assertStringContainsString( "'Descargar CSV para Google Contactos'", $js );
		$this->assertStringContainsString( "'Abrir Google Contactos'", $js );
		$this->assertStringContainsString( "window.open(d.google_url || 'https://contacts.google.com/', '_blank', 'noopener')", $js );
		// The instructions the junta asked for.
		$this->assertStringContainsString( 'Confirma primeiro as baixas pendentes en Xestión → Socios → Baixas de socios. Se unha baixa non se confirma, esa persoa segue sendo socio/a activo/a e o seu correo NON sae da lista.', $js );
		$this->assertStringContainsString( '«Eliminar etiqueta» → «Eliminar todos os contactos e a etiqueta»', $js );
		$this->assertStringContainsString( 'Pulsa «Importar» → «Seleccionar ficheiro» → escolle o CSV descargado → «Importar».', $js );
		$this->assertStringContainsString( 'seguen sendo socios activos e IRÁN na lista exportada', $js );
		$this->assertStringContainsString( "'Altas desde a última exportación'", $js );
		$this->assertStringContainsString( "'Baixas desde a última exportación'", $js );
	}

	public function test_docs_describe_the_procedure(): void {
		$docs = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( 'Lista de correo en Gmail («Socios Web ANPA»)', $docs );
		$this->assertStringContainsString( 'se non se confirman, eses correos seguen na lista', $docs );
	}
}
