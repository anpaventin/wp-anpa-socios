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

    /**
     * D94 (fase23 E2E): POST /fillos created the fillo but silently skipped
     * the fillos_cursos row because sync_current_course_assignment() gated
     * the upsert behind a redundant curso/aula re-validation and returned
     * void. The payload is already validated by validar_fillo() and the
     * upsert itself resolves nivel_id/aula_id safely (NULL when unmapped),
     * so the sync MUST always upsert for the active year (spec R5: atomic
     * annual assignment on create/edit).
     */
    public function test_sync_current_course_assignment_always_upserts_for_active_year(): void {
        $src   = file_get_contents( $this->handler_file );
        $start = strpos( $src, 'private static function sync_current_course_assignment' );
        $this->assertNotFalse( $start );
        $body = substr( $src, $start, strpos( $src, "\n\t}", $start ) - $start );

        $this->assertStringContainsString( 'upsert_fillo_curso_assignment', $body );
        $this->assertStringNotContainsString( 'curso_valido_db', $body, 'sync must not silently gate the annual upsert behind re-validation' );
        $this->assertStringNotContainsString( 'aula_valida_db', $body, 'sync must not silently gate the annual upsert behind re-validation' );
    }

    /**
     * D94 root cause: create_fillo() read $wpdb->insert_id AFTER
     * write_audit() performed its own INSERT, so the re-select targeted the
     * audit row id, returned null, skipped the fillos_cursos sync and sent
     * an empty 201 body. The fillo id MUST be captured before the audit.
     */
    public function test_create_fillo_captures_insert_id_before_audit(): void {
        $src   = file_get_contents( $this->handler_file );
        $start = strpos( $src, 'public static function create_fillo' );
        $body  = substr( $src, $start, strpos( $src, "\n\t}", $start ) - $start );

        $capture = strpos( $body, '$fillo_id = (int) $wpdb->insert_id' );
        $audit   = strpos( $body, 'ANPA_Socios_Admin_Shared::write_audit' );
        $this->assertNotFalse( $capture, 'create_fillo must capture the fillo insert_id into $fillo_id' );
        $this->assertLessThan( $audit, $capture, 'the fillo id must be captured before write_audit inserts the audit row' );
        $this->assertStringNotContainsString( 'WHERE id = %d", $wpdb->insert_id', $body, 'the re-select must use the captured id, not the post-audit insert_id' );
    }

    public function test_create_and_update_fail_closed_when_annual_sync_fails(): void {
        $src = file_get_contents( $this->handler_file );
        $create_start = strpos( $src, 'public static function create_fillo' );
        $update_start = strpos( $src, 'public static function update_fillo', $create_start );
        $next_start   = strpos( $src, 'public static function list_fillo_matriculas', $update_start );
        $create = substr( $src, $create_start, $update_start - $create_start );
        $update = substr( $src, $update_start, $next_start - $update_start );

        foreach ( array( $create, $update ) as $body ) {
            $this->assertStringContainsString( "query( 'START TRANSACTION' )", $body );
            $this->assertStringContainsString( '! self::sync_current_course_assignment(', $body );
            $this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
            $this->assertStringContainsString( "query( 'COMMIT' )", $body );
        }
    }

    public function test_delete_preflight_distinguishes_db_error_from_not_found(): void {
        $src   = file_get_contents( $this->handler_file );
        $start = strpos( $src, 'public static function delete_fillo' );
        $hard  = strpos( $src, 'if ( $hard )', $start );
        $body  = substr( $src, $start, $hard - $start );

        $clear = strpos( $body, '$wpdb->last_error = \'\'' );
        $read  = strpos( $body, 'SELECT estado FROM {$fillos_t} WHERE id = %d' );
        $error = strpos( $body, "'' !== (string) \$wpdb->last_error" );
        $missing = strpos( $body, 'null === $estado' );

        $this->assertIsInt( $clear );
        $this->assertIsInt( $read );
        $this->assertIsInt( $error );
        $this->assertIsInt( $missing );
        $this->assertLessThan( $read, $clear );
        $this->assertLessThan( $error, $read );
        $this->assertLessThan( $missing, $error );
    }

    public function test_hard_delete_locks_child_before_deleting_annual_assignments(): void {
        $src   = file_get_contents( $this->handler_file );
        $start = strpos( $src, 'public static function delete_fillo' );
        $end   = strpos( $src, 'private static function fillo_exists', $start );
        $body  = substr( $src, $start, $end - $start );
        $tx    = strpos( $body, "query( 'START TRANSACTION' )" );
        $lock  = strpos( $body, 'WHERE id = %d FOR UPDATE' );
        $annual_delete = strpos( $body, 'DELETE FROM {$fc_t} WHERE fillo_id = %d' );
        $refs = strpos( $body, 'WHERE fillo_id = %d ORDER BY id FOR UPDATE' );

        $this->assertIsInt( $tx );
        $this->assertIsInt( $lock );
        $this->assertIsInt( $refs );
        $this->assertIsInt( $annual_delete );
        $this->assertLessThan( $lock, $tx );
        $this->assertLessThan( $refs, $lock );
        $this->assertLessThan( $annual_delete, $refs );
    }
}
