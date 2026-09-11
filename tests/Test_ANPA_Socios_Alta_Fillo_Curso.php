<?php
/**
 * 1.51.2 regression: public alta with children must validate levels against the
 * active course (codes 1º…6º), never against the legacy static digits only.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Alta_Fillo_Curso extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function fillo( string $curso, string $aula = 'A' ): array {
		return array( 'nome' => 'Uxía', 'apelidos' => 'Pérez Souto', 'data_nacemento' => '2019-03-12', 'curso' => $curso, 'aula' => $aula );
	}

	public function test_static_fallback_accepts_canonical_codes_and_legacy_digits(): void {
		foreach ( array( '1º', '2º', '6º', '1', '6' ) as $curso ) {
			$out = ANPA_Socios_Admin_Payload::validar_fillo( $this->fillo( $curso ), '' );
			$this->assertIsArray( $out, "curso {$curso} debe aceptarse sen curso_escolar" );
			$this->assertSame( $curso, $out['curso'] );
		}
		foreach ( array( '7º', '0', '2o', '2 º', 'segundo' ) as $curso ) {
			$this->assertNull( ANPA_Socios_Admin_Payload::validar_fillo( $this->fillo( $curso ), '' ), "curso {$curso} debe rexeitarse" );
		}
		$this->assertSame( array( '1º', '2º', '3º', '4º', '5º', '6º' ), ANPA_Socios_Admin_Payload::CURSO_VALIDOS_CANONICOS );
	}

	public function test_alta_payload_with_canonical_code_and_no_school_year_is_valid(): void {
		$body = array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López Vila', 'email' => 'ana@example.com', 'telefono' => '600000000', 'nif' => '12345678Z' ),
			'fillos'  => array( $this->fillo( '2º', 'B' ) ),
		);
		$clean = ANPA_Socios_Alta_Payload::validar( $body );
		$this->assertIsArray( $clean, 'a alta cun fillo de 2º debe validarse: ' . wp_json_encode( ANPA_Socios_Alta_Payload::$errors ) );
		$this->assertSame( '2º', $clean['fillos'][0]['curso'] );
		$this->assertSame( 'B', $clean['fillos'][0]['aula'] );
	}

	public function test_rest_normalises_the_school_year_before_validating(): void {
		$r = $this->src( 'includes/class-anpa-socios-rest.php' );
		$this->assertStringContainsString( 'public static function normalizar_fillos_alta( array $body ): array', $r );
		$norm = strpos( $r, "\$body = self::normalizar_fillos_alta( \$body );" );
		$val  = strpos( $r, "\$clean = ANPA_Socios_Alta_Payload::validar( \$body );" );
		$this->assertNotFalse( $norm );
		$this->assertNotFalse( $val );
		$this->assertLessThan( $val, $norm, 'a normalización debe ir antes da validación' );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', substr( $r, strpos( $r, 'function normalizar_fillos_alta' ) ) );
	}

	public function test_form_sends_the_school_year_and_uses_canonical_codes(): void {
		$js = $this->src( 'assets/js/asociarse.js' );
		$this->assertStringContainsString( "curso_escolar: String((window.anpaAltaEstrutura || {}).curso_escolar || '')", $js );
		$this->assertStringContainsString( "buildOptions(curso, ['1º', '2º', '3º', '4º', '5º', '6º'], ['1º', '2º', '3º', '4º', '5º', '6º']);", $js );
		$this->assertStringNotContainsString( "buildOptions(curso, ['1', '2', '3', '4', '5', '6']", $js );
		$this->assertStringContainsString( "errEl.parentElement.scrollIntoView(", $js );

		$page = $this->src( 'includes/class-anpa-socios-unified-page.php' );
		$this->assertStringContainsString( "'curso_escolar' => \$curso_escolar,", $page );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get() ?? ANPA_Socios_Curso_Escolar::current()', $page );
	}
	public function test_child_field_errors_are_explained_per_field_e8(): void {
		$checked = ANPA_Socios_Admin_Payload::validar_fillo_con_erros( array( 'nome' => 'Uxía', 'apelidos' => '', 'data_nacemento' => '12/03/2019', 'curso' => '2º', 'aula' => '' ), '' );
		$this->assertNull( $checked['fillo'] );
		$this->assertSame( array( 'apelidos', 'data_nacemento', 'aula' ), array_keys( $checked['errors'] ) );
		$this->assertStringContainsString( 'ano-mes-día', $checked['errors']['data_nacemento'] );
		$this->assertStringContainsString( 'aula', $checked['errors']['aula'] );

		$ok = ANPA_Socios_Admin_Payload::validar_fillo_con_erros( $this->fillo( '2º' ), '' );
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( '2º', $ok['fillo']['curso'] );
		// validar_fillo() keeps its contract.
		$this->assertNull( ANPA_Socios_Admin_Payload::validar_fillo( array( 'nome' => 'X' ), '' ) );
	}

	public function test_alta_reports_child_errors_with_row_prefixed_keys(): void {
		$body = array(
			'rgpd'    => true,
			'parent1' => array( 'nome' => 'Ana', 'apelidos' => 'López Vila', 'email' => 'ana@example.com', 'telefono' => '600000000', 'nif' => '12345678Z' ),
			'fillos'  => array( $this->fillo( '2º' ), array( 'nome' => 'Brais', 'apelidos' => 'López', 'data_nacemento' => '2020-05-05', 'curso' => '1º', 'aula' => '' ) ),
		);
		$this->assertNull( ANPA_Socios_Alta_Payload::validar( $body ) );
		$this->assertArrayHasKey( 'fillo_1_aula', ANPA_Socios_Alta_Payload::$errors );
		$this->assertArrayNotHasKey( 'fillo_0_aula', ANPA_Socios_Alta_Payload::$errors );
		$this->assertStringContainsString( 'fillo/a 2', ANPA_Socios_Alta_Payload::$errors['fillos'] );
	}

	public function test_frontends_mark_fields_and_focus_the_first_invalid_one(): void {
		$js = $this->src( 'assets/js/asociarse.js' );
		$this->assertStringContainsString( "/^fillo_(\d+)_(.+)$/.exec(key)", $js );
		$this->assertStringContainsString( "first.focus({ preventScroll: true })", $js );
		$area = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'function applyFieldErrors(root, fields)', $area );
		$this->assertStringContainsString( "applyFieldErrors(root, body && body.data ? body.data.fields : null);", $area );
		$rest = $this->src( 'includes/class-anpa-socios-fillos-rest.php' );
		$this->assertSame( 2, substr_count( $rest, 'self::invalid_payload_error( $checked[' . "'errors'" . '] )' ) );
		$this->assertStringContainsString( "'Escolle a actividade e o grupo (día e hora) antes de confirmar.'", $this->src( 'includes/class-anpa-socios-extraescolares-rest.php' ) );
	}
}
