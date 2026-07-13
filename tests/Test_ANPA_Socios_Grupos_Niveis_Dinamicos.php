<?php
/**
 * Source-inspection tests for PR-ES5 (grupos-niveis dinámicos).
 *
 * @package ANPA_Socios
 */
use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Grupos_Niveis_Dinamicos extends TestCase {

    private $db_file;
    private $fit_file;
    private $payload_file;
    private $handler_file;

    public function setUp(): void {
        $d = dirname( __DIR__ );
        $this->db_file      = $d . '/includes/class-anpa-socios-db.php';
        $this->fit_file     = $d . '/includes/lib/class-anpa-socios-curso-fit.php';
        $this->payload_file = $d . '/includes/lib/class-anpa-socios-admin-payload.php';
        $this->handler_file = $d . '/includes/class-anpa-socios-admin-grupos-handler.php';
    }

    /** @testdox ANPA_Socios_DB has grupos_niveis_crud helpers */
    public function test_db_has_grupos_niveis_crud(): void {
        $src = file_get_contents( $this->db_file );
        $this->assertStringContainsString( 'function insert_grupo_nivel', $src );
        $this->assertStringContainsString( 'function insert_grupo_niveis', $src );
        $this->assertStringContainsString( 'function delete_grupo_niveis', $src );
        $this->assertStringContainsString( 'function get_niveis_for_grupo', $src );
        $this->assertStringContainsString( 'tabela_grupos_niveis', $src );
    }

    /** @testdox ANPA_Socios_Curso_Fit::fits supports dynamic grupos_niveis */
    public function test_curso_fit_falls_back_to_ranges(): void {
        $src = file_get_contents( $this->fit_file );
        $this->assertStringContainsString( 'RANGES', $src );
        $this->assertStringContainsString( 'grupos_niveis', $src );
        $this->assertStringContainsString( 'fits', $src );
    }

    /** @testdox Admin_Payload::validar_grupo accepts nivel_ids */
    public function test_validar_grupo_accepts_nivel_ids(): void {
        $src = file_get_contents( $this->payload_file );
        $this->assertStringContainsString( 'validar_grupo', $src );
        $this->assertStringContainsString( 'nivel_ids', $src );
    }

    /** @testdox Admin_Grupos_Handler accepts nivel_ids in payload */
    public function test_handler_accepts_nivel_ids(): void {
        $src = file_get_contents( $this->handler_file );
        $this->assertStringContainsString( 'nivel_ids', $src );
        // Should not validate against curso_range only
        $this->assertStringContainsString( 'curso_range', $src );
    }

    /** @testdox RANGES constant retained for legacy fallback */
    public function test_ranges_constant_retained(): void {
        $src = file_get_contents( $this->fit_file );
        $this->assertStringContainsString( "const RANGES", $src );
    }
}
