<?php
/**
 * Source-inspection tests for PR-ES4 (fillos dynamic validation).
 *
 * @package ANPA_Socios
 */
use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Fillos_Dinamicos extends TestCase {

    private $payload_file;

    public function setUp(): void {
        $d = dirname( __DIR__ );
        $this->payload_file = $d . '/includes/lib/class-anpa-socios-admin-payload.php';
    }

    /** @testdox Admin_Payload has curso_valido_db method */
    public function test_curso_valido_db_exists(): void {
        $src = file_get_contents( $this->payload_file );
        $this->assertStringContainsString( 'curso_valido_db', $src );
    }

    /** @testdox Admin_Payload has aula_valida_db method */
    public function test_aula_valida_db_exists(): void {
        $src = file_get_contents( $this->payload_file );
        $this->assertStringContainsString( 'aula_valida_db', $src );
    }

    /** @testdox Admin_Payload has dynamic_curso_validos method */
    public function test_dynamic_curso_validos_exists(): void {
        $src = file_get_contents( $this->payload_file );
        $this->assertStringContainsString( 'dynamic_curso_validos', $src );
    }

    /** @testdox Admin_Payload has dynamic_aula_validos method */
    public function test_dynamic_aula_validos_exists(): void {
        $src = file_get_contents( $this->payload_file );
        $this->assertStringContainsString( 'dynamic_aula_validos', $src );
    }

    /** @testdox CURSO_VALIDOS and GRUPO_VALIDOS constants retained */
    public function test_constants_retained(): void {
        $src = file_get_contents( $this->payload_file );
        $this->assertStringContainsString( 'CURSO_VALIDOS', $src );
        $this->assertStringContainsString( 'GRUPO_VALIDOS', $src );
    }

    /** @testdox validar_fillo still works without curso_escolar (backward compat) */
    public function test_validar_fillo_legacy_still_works(): void {
        $this->assertTrue( method_exists( 'ANPA_Socios_Admin_Payload', 'validar_fillo' ) );
    }

    /** @testdox validar_fillo now accepts curso_escolar as second param */
    public function test_validar_fillo_accepts_curso_escolar(): void {
        $src = file_get_contents( $this->payload_file );
        $this->assertStringContainsString( 'validar_fillo( array $input, string $curso_escolar =', $src );
    }

    /** @testdox Callers pass curso_escolar: admin-fillos-handler */
    public function test_admin_fillos_handler_passes_curso_escolar(): void {
        $d = dirname( __DIR__ );
        $src = file_get_contents( $d . '/includes/class-anpa-socios-admin-fillos-handler.php' );
        $this->assertStringContainsString( 'validar_fillo( $body, $curso_escolar )', $src );
    }

    /** @testdox Callers pass curso_escolar: fillos-rest */
    public function test_fillos_rest_passes_curso_escolar(): void {
        $d = dirname( __DIR__ );
        $src = file_get_contents( $d . '/includes/class-anpa-socios-fillos-rest.php' );
        $this->assertStringContainsString( 'validar_fillo( $body, $curso_escolar )', $src );
    }

    /** @testdox Callers pass curso_escolar: alta-payload */
    public function test_alta_payload_passes_curso_escolar(): void {
        $d = dirname( __DIR__ );
        $src = file_get_contents( $d . '/includes/lib/class-anpa-socios-alta-payload.php' );
        $this->assertStringContainsString( 'validar_fillo( $raw_fillo, $fillo_curso_escolar )', $src );
    }
}
