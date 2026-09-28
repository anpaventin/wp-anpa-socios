<?php
/**
 * 1.75.0: the Google Contacts CSV (Lista Gmail) carries each parent's phone and a
 * note «1º/2º proxenitor/a de: Alumno/a (curso aula); …». Pure + source contracts.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Lista_Gmail_Contacto extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-contactos-google-handler.php';
	}

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_phone_and_note_go_in_googles_columns(): void {
		$csv  = ANPA_Socios_Admin_Contactos_Google_Handler::build_csv( array(
			array( 'email' => 'nai@example.org', 'nome' => 'María', 'apelidos' => 'Pérez Souto', 'telefono' => '600 000 001', 'notas' => '1º proxenitor/a de: Uxía Pérez Souto (3º A)' ),
			array( 'email' => 'pai@example.org', 'nome' => 'Xoán', 'apelidos' => 'Vidal' ),
		) );
		$rows = array_values( array_filter( explode( "\r\n", $csv ) ) );
		$r1   = str_getcsv( $rows[1] );
		$this->assertSame( '1º proxenitor/a de: Uxía Pérez Souto (3º A)', $r1[14], 'Notes' );
		$this->assertSame( 'Mobile', $r1[19], 'Phone 1 - Label' );
		$this->assertSame( '600 000 001', $r1[20], 'Phone 1 - Value' );
		$this->assertSame( 'nai@example.org', $r1[18] );
		// Without phone or note the columns stay empty (older snapshots, parents without phone).
		$r2 = str_getcsv( $rows[2] );
		$this->assertSame( '', $r2[14] );
		$this->assertSame( '', $r2[19] );
		$this->assertSame( '', $r2[20] );
	}

	public function test_international_prefix_survives_the_formula_guard(): void {
		// A leading «+» would get the CSV formula guard («'+34…»), which Google imports literally.
		$this->assertSame( '0034 600 000 001', ANPA_Socios_Admin_Contactos_Google_Handler::telefono_google( ' +34 600 000 001 ' ) );
		$this->assertSame( '600000001', ANPA_Socios_Admin_Contactos_Google_Handler::telefono_google( '600000001' ) );
		$this->assertSame( '', ANPA_Socios_Admin_Contactos_Google_Handler::telefono_google( '  ' ) );
		$csv = ANPA_Socios_Admin_Contactos_Google_Handler::build_csv( array( array( 'email' => 'a@example.org', 'nome' => 'A', 'apelidos' => 'B', 'telefono' => '+34600000001' ) ) );
		$this->assertStringContainsString( '"0034600000001"', $csv );
		$this->assertStringNotContainsString( "'+34", $csv );
	}

	public function test_note_names_the_parent_role_and_every_child_with_course_and_letter(): void {
		$fillos = array(
			array( 'nome' => 'Uxía', 'apelidos' => 'Pérez Souto', 'curso' => '3º', 'aula' => 'A' ),
			array( 'nome' => 'Brais', 'apelidos' => 'Pérez Souto', 'curso' => '1º', 'aula' => '' ),
		);
		$this->assertSame( '1º proxenitor/a de: Uxía Pérez Souto (3º A); Brais Pérez Souto (1º)', ANPA_Socios_Admin_Contactos_Google_Handler::nota_proxenitor( 'principal', $fillos ) );
		$this->assertSame( '2º proxenitor/a de: Uxía Pérez Souto (3º A); Brais Pérez Souto (1º)', ANPA_Socios_Admin_Contactos_Google_Handler::nota_proxenitor( 'secundario', $fillos ) );
		$this->assertSame( '', ANPA_Socios_Admin_Contactos_Google_Handler::nota_proxenitor( 'principal', array() ) );
		// A child with no course yet: just the name.
		$this->assertSame( '1º proxenitor/a de: Lúa Exemplo', ANPA_Socios_Admin_Contactos_Google_Handler::nota_proxenitor( 'principal', array( array( 'nome' => 'Lúa', 'apelidos' => 'Exemplo', 'curso' => '', 'aula' => '' ) ) ) );
	}

	public function test_members_query_brings_phone_role_and_the_familys_active_children(): void {
		$h     = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$start = strpos( $h, 'private static function socios_activos(): array' );
		$this->assertNotFalse( $start );
		$body = substr( $h, (int) $start, (int) strpos( $h, "\n\t/**", (int) $start ) - (int) $start );
		$this->assertStringContainsString( 'SELECT id, email, nome, apelidos, telefono, rol_familia, familia_id FROM', $body );
		$this->assertStringContainsString( "WHERE estado = 'activo' AND rol <> 'master' AND email <> ''", $body );
		$this->assertStringContainsString( "WHERE f.estado = 'activo'", $body );
		$this->assertStringContainsString( 'LEFT JOIN {$fc_t} fc ON fc.fillo_id = f.id AND fc.curso_escolar = %s', $body );
		$this->assertStringContainsString( "'notas'    => self::nota_proxenitor(", $body );
		$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $body ), 'SQL and code in socios_activos stay ASCII' );
	}

	public function test_stored_export_list_keeps_no_phones_or_children(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "return array( 'email' => (string) \$x['email'], 'nome' => (string) \$x['nome'], 'apelidos' => (string) \$x['apelidos'] );", $h );
	}
}
