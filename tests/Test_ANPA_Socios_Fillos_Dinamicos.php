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

    /**
     * Regression test for a real SQL bug found by live auditing (2026-07-14):
     * dynamic_curso_validos()/dynamic_aula_validos() used to hand-roll their
     * own SQL against `anpa_niveis`/`anpa_aulas` with a wrong `order` column
     * (the real column is `orde`) and a nonexistent `anpa_aulas.curso_escolar`
     * column. WordPress swallows the resulting SQL error and the method
     * silently fell back to the static CURSO_VALIDOS/GRUPO_VALIDOS constants,
     * so the dynamic-structure validation added in PR-ES4 never actually ran
     * against real data despite the suite staying green (prior tests only
     * grepped for method names, never executed SQL).
     *
     * These methods MUST delegate to ANPA_Socios_DB::get_niveis_for_curso()
     * and ::get_aulas_for_niveis() — the only place that queries these
     * tables with the correct schema — instead of re-implementing SQL here.
     *
     * @testdox dynamic_curso_validos/dynamic_aula_validos delegate to ANPA_Socios_DB (no inline SQL)
     */
    public function test_dynamic_validos_delegate_to_db_helpers_not_inline_sql(): void {
        $src = file_get_contents( $this->payload_file );

        $this->assertStringContainsString( 'ANPA_Socios_DB::get_niveis_for_curso', $src );
        $this->assertStringContainsString( 'ANPA_Socios_DB::get_aulas_for_niveis', $src );

        // Must not re-introduce the ad-hoc buggy SQL pattern here (the wrong
        // `order` column and direct FROM {$wpdb->prefix}anpa_niveis/anpa_aulas
        // queries). Only assert on the literal buggy fragments, not on
        // any mention of the table names (the docblock legitimately names
        // them when explaining why delegation is required).
        $this->assertStringNotContainsString( 'ORDER BY `order`', $src );
        $this->assertStringNotContainsString( 'FROM {$wpdb->prefix}anpa_niveis', $src );
        $this->assertStringNotContainsString( 'FROM {$wpdb->prefix}anpa_aulas', $src );
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
