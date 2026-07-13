<?php
/**
 * Source-inspection tests for ES4 handler updates.
 *
 * Verifies that handlers pass curso_escolar to validar_fillo.
 *
 * @package ANPA_Socios
 */
use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Fillos_Handlers_ES4 extends TestCase {

    private $handler_file;
    private $fillos_rest_file;
    private $fillo_cursos_file;

    public function setUp(): void {
        $d = dirname( __DIR__ );
        $this->handler_file     = $d . '/includes/class-anpa-socios-admin-fillos-handler.php';
        $this->fillos_rest_file = $d . '/includes/class-anpa-socios-fillos-rest.php';
        $this->fillo_cursos_file = $d . '/includes/class-anpa-socios-fillo-cursos-rest.php';
    }

    /** @testdox Admin_Fillos_Handler passes curso_escolar to validar_fillo */
    public function test_admin_fillos_handler_passes_curso_escolar(): void {
        $src = file_get_contents( $this->handler_file );
        $this->assertStringContainsString( 'curso_escolar', $src );
        $this->assertStringContainsString( "validar_fillo( \$body, \$curso_escolar )", $src );
    }

    /** @testdox Fillos_REST passes curso_escolar to validar_fillo */
    public function test_fillos_rest_passes_curso_escolar(): void {
        $src = file_get_contents( $this->fillos_rest_file );
        $this->assertStringContainsString( 'curso_escolar', $src );
        $this->assertStringContainsString( "validar_fillo( \$body, \$curso_escolar )", $src );
    }

    /** @testdox Admin_Payload::validar_fillo accepts curso_escolar parameter */
    public function test_validar_fillo_accepts_curso_escolar(): void {
        $this->assertTrue(
            method_exists( 'ANPA_Socios_Admin_Payload', 'validar_fillo' )
        );

        $ref = new ReflectionMethod( 'ANPA_Socios_Admin_Payload', 'validar_fillo' );
        $params = $ref->getParameters();
        $this->assertCount( 2, $params );
        $this->assertSame( 'curso_escolar', $params[1]->getName() );
        $this->assertTrue( $params[1]->isOptional() );
    }

    /** @testdox Admin_Payload::validar_fillo validates against DB when curso_escolar provided */
    public function test_validar_fillo_uses_db_when_curso_escolar_provided(): void {
        $src = file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-admin-payload.php' );
        // The method should call curso_valido_db when curso_escolar is set
        $this->assertStringContainsString( 'curso_valido_db', $src );
        $this->assertStringContainsString( 'aula_valida_db', $src );
        $this->assertStringContainsString( '\'\' !== $curso_escolar', $src );
    }
}
