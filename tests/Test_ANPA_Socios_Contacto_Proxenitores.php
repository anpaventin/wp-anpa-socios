<?php
/**
 * 1.70.0: the company and canteen listings carry both parents' contact data
 * (name, phone, email of the 1st and, when present, the 2nd parent), and the
 * data-sharing consent is always recorded as given.
 *
 * Pure column contract + source-contract tests (no DB, no DOM).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Contacto_Proxenitores extends TestCase {

	private const CONTACTO = array(
		'proxenitor1_nome',
		'proxenitor1_telefono',
		'proxenitor1_email',
		'proxenitor2_nome',
		'proxenitor2_telefono',
		'proxenitor2_email',
	);

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_panel_columns_end_with_both_parents_contact(): void {
		foreach ( array( ANPA_Socios_Alumnos_Export::columns_panel_empresa(), ANPA_Socios_Alumnos_Export::columns_panel_comedor() ) as $cols ) {
			$this->assertSame( self::CONTACTO, array_slice( $cols, -6 ), 'Contact columns close the listing, 1st parent first.' );
			$this->assertNotContains( 'socio_email', $cols, 'The loose fillo email is replaced by the 1st parent email.' );
		}
		$this->assertSame( 'empresa_nome', ANPA_Socios_Alumnos_Export::columns_panel_comedor()[0] );
	}

	public function test_legacy_admin_export_columns_are_untouched(): void {
		$this->assertCount( 8, ANPA_Socios_Alumnos_Export::columns( false ) );
		$this->assertContains( 'socio_email', ANPA_Socios_Alumnos_Export::columns( false ) );
	}

	public function test_panel_query_resolves_principal_and_secundario_of_the_family(): void {
		$lib   = $this->src( 'includes/lib/class-anpa-socios-alumnos-export.php' );
		$start = strpos( $lib, 'public static function rows_panel_empresa(' );
		$this->assertNotFalse( $start );
		$body = substr( $lib, (int) $start );

		// wpdb trap (1.56.2): the SQL must stay pure ASCII.
		$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $body ), 'rows_panel_empresa SQL must be ASCII only.' );
		$this->assertStringContainsString( "LEFT JOIN {\$socios} s0 ON s0.email = f.socio_email AND f.socio_email <> ''", $body, 'An empty child email never matches another family.' );
		$this->assertStringContainsString( "x.rol_familia = 'principal'", $body );
		$this->assertStringContainsString( "y.rol_familia = 'secundario'", $body );
		// One row per enrolment even if a family holds duplicate members.
		$this->assertSame( 2, substr_count( $body, 'SELECT MIN(' ) );
		$this->assertStringContainsString( 'y.id <> p1.id', $body, 'The 2nd parent is never the 1st one again.' );
		foreach ( self::CONTACTO as $c ) {
			$this->assertStringContainsString( " AS {$c}", $body );
		}
	}

	public function test_empresa_me_exposes_both_parents_contact(): void {
		$rest = $this->src( 'includes/class-anpa-socios-empresa-rest.php' );
		foreach ( self::CONTACTO as $c ) {
			$this->assertStringContainsString( "'{$c}'", $rest );
		}
		$this->assertStringNotContainsString( "'socio_email' => (string) \$r['socio_email']", $rest );
	}

	public function test_panel_table_shows_two_parent_columns_with_call_and_mail_links(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "{ key: 'proxenitor1', label: __( '1º proxenitor', 'anpa-socios' ) }", $js );
		$this->assertStringContainsString( "{ key: 'proxenitor2', label: __( '2º proxenitor', 'anpa-socios' ) }", $js );
		$this->assertStringContainsString( "'tel:'", $js );
		$this->assertStringContainsString( "'mailto:'", $js );
		$this->assertStringNotContainsString( "contacto: r.socio_email || ''", $js );
	}

	public function test_consent_checkbox_is_checked_by_default_and_blocks_when_unchecked(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "addCheckbox('cesion_datos_empresa', 'Autorizo a que se cedan á empresa de actividades os datos necesarios para a correcta xestión da actividade extraescolar.', true);", $js );
		$this->assertStringContainsString( 'function syncCesion()', $js );
		$this->assertStringContainsString( 'enrolBtn.disabled = !ok;', $js );
		// The server keeps rejecting a missing consent.
		$rest = $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( "'anpa_extra_cesion_datos'", $rest );
	}

	public function test_admin_paths_record_the_consent(): void {
		$import = $this->src( 'includes/class-anpa-socios-admin-import-handler.php' );
		$this->assertStringContainsString( "'cesion_datos_empresa' => 1,", $import );
		$admin = $this->src( 'includes/class-anpa-socios-admin-matriculas-handler.php' );
		$this->assertStringContainsString( "\$payload + array( 'cesion_datos_empresa' => 1 )", $admin );
		// Formats follow validar_matricula(): observaciones is a string (it was saved as 0 before 1.70.0).
		$this->assertStringContainsString( "array( '%d', '%d', '%d', '%d', '%s', '%s', '%d' )", $admin );
	}

	public function test_migration_1_45_0_marks_every_enrolment_and_defaults_to_consent(): void {
		$db = $this->src( 'includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( "const DB_VERSION = '1.49.0';", $db );
		$this->assertStringContainsString( "define( 'ANPA_SOCIOS_DB_VERSION', '1.49.0' )", $this->src( 'anpa-socios.php' ) );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.45.0', '<' ) && ! self::migrate_to_1_45_0()", $db );
		$start = strpos( $db, 'private static function migrate_to_1_45_0(): bool' );
		$this->assertNotFalse( $start );
		$body = substr( $db, (int) $start );
		$this->assertStringContainsString( 'MODIFY COLUMN cesion_datos_empresa tinyint(1) NOT NULL DEFAULT 1', $body );
		$this->assertStringContainsString( 'SET cesion_datos_empresa = 1 WHERE cesion_datos_empresa <> 1', $body );
	}
}
