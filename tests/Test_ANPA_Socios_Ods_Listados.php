<?php
/**
 * 1.74.0: spreadsheet (.ods) downloads, the company / canteen sheet in the same
 * format as the web panel, descriptive file names, the CSV column guide and the
 * Monday 10:00 reminder of pending approvals. Pure helpers (no WordPress state).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Ods_Listados extends TestCase {

	// ── ODS writer ──

	public function test_content_xml_has_one_table_per_sheet_with_string_cells(): void {
		$xml = ANPA_Socios_Ods::content_xml( array(
			array( 'nome' => 'Xadrez', 'cabeceira' => array( 'Alumno/a', 'Contacto' ), 'filas' => array( array( 'Ana & Uxía', "Liña 1\nLiña 2" ) ) ),
			array( 'nome' => 'Robótica', 'cabeceira' => array( 'Alumno/a' ), 'filas' => array() ),
		) );
		$this->assertSame( 2, substr_count( $xml, '<table:table ' ) );
		$this->assertStringContainsString( 'table:name="Xadrez"', $xml );
		$this->assertStringContainsString( 'table:name="Robótica"', $xml );
		// Values are always text (no formulas can run) and XML-escaped.
		$this->assertStringContainsString( '<text:p>Ana &amp; Uxía</text:p>', $xml );
		$this->assertStringNotContainsString( 'table:formula', $xml );
		$this->assertStringContainsString( 'office:value-type="string"', $xml );
		// A line break in a value becomes two paragraphs in the same cell.
		$this->assertStringContainsString( '<text:p>Liña 1</text:p><text:p>Liña 2</text:p>', $xml );
		// Header row in bold.
		$this->assertStringContainsString( 'table:style-name="cabeceira"', $xml );
		$this->assertNotFalse( simplexml_load_string( $xml ), 'content.xml must be well-formed' );
	}

	public function test_invalid_utf8_does_not_wipe_the_cell(): void {
		$xml = ANPA_Socios_Ods::content_xml( array( array( 'nome' => 'F', 'cabeceira' => array( 'A' ), 'filas' => array( array( "Nome \xC3 roto" ) ) ) ) );
		$this->assertStringContainsString( 'Nome ', $xml );
		$this->assertStringContainsString( 'roto', $xml );
		$this->assertNotFalse( simplexml_load_string( $xml ) );
	}

	public function test_sheet_names_are_made_safe(): void {
		$this->assertSame( 'A-B C', ANPA_Socios_Ods::nome_folla( 'A/B: C' ) );
		$this->assertSame( 'Folla', ANPA_Socios_Ods::nome_folla( '  ' ) );
		$this->assertSame( 31, mb_strlen( ANPA_Socios_Ods::nome_folla( str_repeat( 'x', 50 ) ) ) );
	}

	public function test_document_is_a_valid_ods_package(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not available' );
		}
		$bytes = ANPA_Socios_Ods::documento( array( array( 'nome' => 'Folla', 'cabeceira' => array( 'A' ), 'filas' => array( array( '1' ) ) ) ) );
		$this->assertIsString( $bytes );
		$this->assertSame( 'PK', substr( $bytes, 0, 2 ) );
		// ODS rule: the first entry is «mimetype», stored uncompressed.
		$this->assertSame( 'mimetype', substr( $bytes, 30, 8 ) );
		$this->assertSame( ANPA_Socios_Ods::MIME, substr( $bytes, 38, strlen( ANPA_Socios_Ods::MIME ) ) );
		$tmp = tempnam( sys_get_temp_dir(), 'ods' );
		file_put_contents( $tmp, $bytes );
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $tmp ) );
		foreach ( array( 'mimetype', 'META-INF/manifest.xml', 'content.xml', 'styles.xml' ) as $entry ) {
			$this->assertNotFalse( $zip->locateName( $entry ), $entry );
		}
		$zip->close();
		unlink( $tmp );
	}

	// ── Company / canteen sheet (same format as the web panel) ──

	private function fila(): array {
		return array(
			'actividade_nome' => 'Xadrez', 'empresa_nome' => 'Empresa Exemplo', 'grupo_nome' => 'Luns', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'luns',
			'nome' => 'Uxía', 'apelidos' => 'Pérez Souto', 'curso' => '3º', 'aula' => 'A', 'estado' => 'activo', 'trimestre' => '1',
			'autorizacion_comedor' => 'na', 'tarde_transicion' => 'comedor', 'tardes_divertidas_continua' => '0', 'recollida_autorizada' => '1',
			'proxenitor1_nome' => 'Ana Souto', 'proxenitor1_telefono' => '600000001', 'proxenitor1_email' => 'ana@example.com',
			'proxenitor2_nome' => '', 'proxenitor2_telefono' => '', 'proxenitor2_email' => '',
		);
	}

	public function test_sheet_columns_mirror_the_web_panel(): void {
		$this->assertSame( array( 'Actividade', 'Grupo', 'Alumno/a', 'Curso', 'Estado', 'Opcións e autorizacións', '1º proxenitor', '2º proxenitor' ), ANPA_Socios_Listado_Empresa::cabeceira( false ) );
		$this->assertSame( array( 'Actividade', 'Empresa', 'Grupo', 'Alumno/a', 'Curso', 'Estado', 'Opcións e autorizacións', '1º proxenitor', '2º proxenitor' ), ANPA_Socios_Listado_Empresa::cabeceira( true ) );
	}

	public function test_sheet_row_is_human_readable(): void {
		$f = ANPA_Socios_Listado_Empresa::fila( $this->fila(), false );
		$this->assertSame( 'Xadrez', $f[0] );
		$this->assertSame( 'Luns · Tarde 16:00-17:00', $f[1] );
		$this->assertSame( 'Uxía Pérez Souto', $f[2] );
		$this->assertSame( '3º A', $f[3] );
		$this->assertSame( 'Activa', $f[4] );
		$this->assertSame( 'Tras o comedor pasa á actividade · Recollida por persoa autorizada', $f[5] );
		$this->assertSame( "Ana Souto\n600000001\nana@example.com", $f[6] );
		$this->assertSame( '—', $f[7] );
		$c = ANPA_Socios_Listado_Empresa::fila( $this->fila(), true );
		$this->assertSame( 'Empresa Exemplo', $c[1] );
		$this->assertCount( 9, $c );
	}

	public function test_every_state_has_a_label(): void {
		foreach ( array( 'activo' => 'Activa', 'lista_espera' => 'Lista de espera', 'oferta' => 'Oferta de praza', 'baixa_solicitada' => 'Baixa solicitada', 'pendente_aprobacion' => 'Pendente de aprobación', 'baixa' => 'Baixa' ) as $e => $l ) {
			$this->assertSame( $l, ANPA_Socios_Listado_Empresa::estado_label( $e ) );
		}
	}

	public function test_descriptive_file_names(): void {
		$this->assertSame( 'Empresa Exemplo - Xadrez - 2026-09-29.ods', ANPA_Socios_Listado_Empresa::nome_ficheiro( array( 'Empresa Exemplo', 'Xadrez' ), '2026-09-29', 'ods' ) );
		$this->assertSame( 'Comedor - Listado completo - 2026-09-29.ods', ANPA_Socios_Listado_Empresa::nome_ficheiro( array( 'Comedor', 'Listado completo' ), '2026-09-29', 'ods' ) );
		// Characters that break file names on Windows / macOS are removed; spaces collapse.
		$this->assertSame( 'A-B C - D - 2026-09-29.csv', ANPA_Socios_Listado_Empresa::nome_ficheiro( array( 'A/B:  C?', 'D*' ), '2026-09-29', 'csv' ) );
		$this->assertSame( 'Listado - 2026-09-29.ods', ANPA_Socios_Listado_Empresa::nome_ficheiro( array( '', '  ' ), '2026-09-29', 'ods' ) );
		$this->assertLessThanOrEqual( 150, mb_strlen( ANPA_Socios_Listado_Empresa::nome_ficheiro( array( str_repeat( 'Empresa ', 30 ), 'X' ), '2026-09-29', 'ods' ) ) );
	}

	public function test_csv_guide_explains_every_exported_column_and_its_codes(): void {
		$guia = ANPA_Socios_Listado_Empresa::guia_csv( true );
		foreach ( ANPA_Socios_Alumnos_Export::columns_panel_comedor() as $col ) {
			$this->assertArrayHasKey( $col, $guia, "guide misses $col" );
			$this->assertNotSame( '', trim( $guia[ $col ] ) );
		}
		$this->assertArrayNotHasKey( 'empresa_nome', ANPA_Socios_Listado_Empresa::guia_csv( false ) );
		$this->assertStringContainsString( 'na', $guia['autorizacion_comedor'] );
		$this->assertStringContainsString( 'familia', $guia['tarde_transicion'] );
		$this->assertStringContainsString( '1', $guia['recollida_autorizada'] );
	}

	// ── Monday 10:00 reminder ──

	public function test_next_monday_at_ten_in_the_site_timezone(): void {
		$tz = new DateTimeZone( 'Europe/Madrid' );
		$at = static function ( string $s ) use ( $tz ): int { return ( new DateTimeImmutable( $s, $tz ) )->getTimestamp(); };
		// Tuesday → next Monday.
		$this->assertSame( $at( '2026-10-05 10:00:00' ), ANPA_Socios_Aviso_Semanal::proximo_luns( $at( '2026-09-29 12:00:00' ), $tz ) );
		// Monday before 10:00 → same day.
		$this->assertSame( $at( '2026-10-05 10:00:00' ), ANPA_Socios_Aviso_Semanal::proximo_luns( $at( '2026-10-05 09:59:00' ), $tz ) );
		// Monday at/after 10:00 → the following Monday.
		$this->assertSame( $at( '2026-10-12 10:00:00' ), ANPA_Socios_Aviso_Semanal::proximo_luns( $at( '2026-10-05 10:00:00' ), $tz ) );
		// Across the end of summer time the hour stays 10:00 local.
		$this->assertSame( $at( '2026-10-26 10:00:00' ), ANPA_Socios_Aviso_Semanal::proximo_luns( $at( '2026-10-20 08:00:00' ), $tz ) );
	}

	public function test_reminder_only_when_something_is_pending(): void {
		$this->assertTrue( ANPA_Socios_Aviso_Semanal::debe_enviar( array( 'total' => 2 ) ) );
		$this->assertFalse( ANPA_Socios_Aviso_Semanal::debe_enviar( array( 'total' => 0 ) ) );
		$this->assertFalse( ANPA_Socios_Aviso_Semanal::debe_enviar( array() ) );
	}

	// ── Wiring (source contracts) ──

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_company_ods_is_scoped_to_its_own_activity_and_canteen_gets_the_full_list(): void {
		$rest = $this->src( 'includes/class-anpa-socios-empresa-rest.php' );
		$this->assertStringContainsString( "if ( 'ods' === sanitize_key( (string) \$request->get_param( 'formato' ) ) ) {", $rest );
		$this->assertStringContainsString( "' WHERE id = %d AND empresa_id = %d', \$act_id, \$empresa_id", $rest, 'a company only downloads its own activity' );
		$this->assertStringContainsString( "ANPA_Socios_Alumnos_Export::rows_panel_empresa( 0, \$curso, true );", $rest );
		$this->assertStringContainsString( "(int) ( \$r['actividad_id'] ?? 0 ) === \$act_id", $rest );
		$this->assertStringContainsString( 'ANPA_Socios_Descarga::resposta( $bytes, ANPA_Socios_Ods::MIME, ANPA_Socios_Listado_Empresa::nome_ficheiro( $partes,', $rest );
		$this->assertStringContainsString( "a.id AS actividad_id, ", $this->src( 'includes/lib/class-anpa-socios-alumnos-export.php' ) );
	}

	public function test_xestion_listings_offer_ods_and_matriculas_carry_the_family_options(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-export-handler.php' );
		$this->assertSame( 2, substr_count( $h, "'ods' === sanitize_key( (string) \$request->get_param( 'formato' ) )" ) );
		$this->assertStringContainsString( 'private const ENTITY_LABELS = array(', $h );
		$this->assertStringContainsString( 'm.autorizacion_comedor, m.tarde_transicion, m.tardes_divertidas_continua, m.recollida_autorizada, m.cesion_datos_empresa', $h );
		foreach ( array( 'autorizacion_comedor', 'tarde_transicion', 'tardes_divertidas_continua', 'recollida_autorizada', 'cesion_datos_empresa' ) as $c ) {
			$this->assertContains( $c, ANPA_Socios_Csv_Import::ENTITY_HEADERS['matriculas'] );
		}
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "ods.textContent = 'Exportar folla (.ods)';", $js );
		$this->assertStringContainsString( "anpaAdminFetch('export/' + section + '?formato=ods')", $js );
		$this->assertStringContainsString( "ct.indexOf('opendocument') !== -1", $js );
	}

	public function test_panel_buttons_names_and_csv_guide(): void {
		$tpl = $this->src( 'includes/class-anpa-socios-area-page.php' );
		$this->assertStringContainsString( 'data-action="empresa-export-ods-comedor" hidden', $tpl );
		$this->assertStringContainsString( 'ANPA_Socios_Listado_Empresa::guia_csv( true )', $tpl );
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "descargarEmpresa('formato=ods&actividad_id=' + encodeURIComponent(String(a.id)), nomeFicheiro([profile.nome, a.nome], 'ods'));", $js );
		$this->assertStringContainsString( "descargarEmpresa('formato=ods', nomeFicheiro([__( 'Comedor', 'anpa-socios' ), __( 'Listado completo', 'anpa-socios' )], 'ods'));", $js );
		$this->assertStringContainsString( "/filename\*=UTF-8''([^;]+)/i", $js );
		$this->assertStringNotContainsString( "a.download = 'alumnos-empresa-' + ambito + '.csv';", $js );
	}

	public function test_weekly_reminder_is_scheduled_on_update_and_sends_only_with_pending(): void {
		$main = $this->src( 'anpa-socios.php' );
		$this->assertStringContainsString( "register_activation_hook( __FILE__, array( 'ANPA_Socios_Aprobacions_Semanal', 'programar' ) );", $main );
		$this->assertStringContainsString( "\tANPA_Socios_Aprobacions_Semanal::programar();\n} );", $main, 'also on admin_init (updates do not fire activation)' );
		$this->assertStringContainsString( "register_deactivation_hook( __FILE__, array( 'ANPA_Socios_Aprobacions_Semanal', 'desprogramar' ) );", $main );
		$this->assertStringContainsString( "add_action( ANPA_Socios_Aprobacions_Semanal::CRON_HOOK, array( 'ANPA_Socios_Aprobacions_Semanal', 'executar' ) );", $main );
		$c = $this->src( 'includes/class-anpa-socios-aprobacions-semanal.php' );
		$this->assertStringContainsString( 'wp_schedule_single_event( ANPA_Socios_Aviso_Semanal::proximo_luns( time(), wp_timezone() ), self::CRON_HOOK );', $c );
		$this->assertStringContainsString( 'if ( ANPA_Socios_Aviso_Semanal::debe_enviar( $contas ) ) {', $c );
		$this->assertStringContainsString( 'self::programar();', $c );
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'aprobacions_pendentes_semanal' );
		$this->assertSame( array( 'socios', 'matriculas', 'baixas', 'xestion_url', 'contact_email' ), $vars['html'] );
		$this->assertContains( 'association_name', $vars['subject'] );
		$this->assertStringContainsString( "'&section=aprobacions'", $this->src( 'includes/class-anpa-socios-email.php' ) );
	}
}
