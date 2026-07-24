<?php
/**
 * Contract tests for the fase31 per-course comedor pivot (wp_anpa_niveis_curso).
 *
 * Source-inspection style (matching the other schema/glue contract tests): the
 * migration + wpdb glue are verified by reading the source, not by hitting a DB.
 *
 * Guards:
 *  - DB_VERSION bumped to 1.36.0 and the step wired into the migration chain.
 *  - migrate_to_1_36_0 is ADDITIVE: creates the pivot + backfills, never drops
 *    the legacy global comedor columns (that is the later 1.37.0 migration).
 *  - Comedor read/write helpers exist.
 *  - The estrutura handler dual-writes the pivot and the page reads it per course.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Niveis_Curso_Pivot extends TestCase {

	private string $db_file;
	private string $handler_file;
	private string $page_file;

	public function setUp(): void {
		parent::setUp();
		$this->db_file      = dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php';
		$this->handler_file = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-estrutura-handler.php';
		$this->page_file    = dirname( __DIR__ ) . '/includes/class-anpa-socios-estrutura-escolar-page.php';
	}

	private function migration_body(): string {
		$source = file_get_contents( $this->db_file );
		$start  = strpos( $source, 'private static function migrate_to_1_36_0' );
		$this->assertNotFalse( $start, 'migrate_to_1_36_0 must exist.' );
		// The method right after 1.36.0 is the destructive 1.37.0 migration; the
		// 1.36.0 body ends there (do NOT run into 1.37.0's DROP COLUMN).
		$end = strpos( $source, 'private static function migrate_to_1_37_0', $start );
		$this->assertNotFalse( $end );
		return substr( $source, $start, $end - $start );
	}

	public function test_db_version_bumped_and_1_36_0_wired_in_chain(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( "const DB_VERSION = '1.38.0';", $source );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.36.0', '<' ) && ! self::migrate_to_1_36_0()", $source );
	}

	public function test_pivot_table_helper_and_ddl_exist(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'public static function tabela_niveis_curso', $source );
		$this->assertStringContainsString( "anpa_niveis_curso", $source );
		$body = $this->migration_body();
		$this->assertStringContainsString( 'CREATE TABLE', $body );
		$this->assertStringContainsString( 'UNIQUE KEY nivel_curso (nivel_id, curso_escolar)', $body );
	}

	public function test_migration_backfills_from_legacy_global_column_with_postcondition(): void {
		$body = $this->migration_body();
		$this->assertStringContainsString( 'INSERT INTO', $body );
		$this->assertStringContainsString( 'h.curso_escolar', $body );
		$this->assertStringContainsString( 'n.horario_comedor_id', $body );
		// Fail-closed postcondition: unrepresented assignments abort the migration.
		$this->assertStringContainsString( 'p.id IS NULL', $body );
		$this->assertStringContainsString( 'return false', $body );
	}

	public function test_migration_1_36_0_is_additive_never_drops_legacy_columns(): void {
		$body = $this->migration_body();
		$this->assertStringNotContainsString( 'DROP COLUMN', $body );
		$this->assertStringNotContainsString( 'DROP TABLE', $body );
	}

	public function test_comedor_helpers_exist(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'public static function get_niveis_comedor_curso', $source );
		$this->assertStringContainsString( 'public static function set_nivel_comedor', $source );
		$this->assertStringContainsString( 'public static function get_nivel_comedor_interval', $source );
	}

	public function test_handler_dual_writes_pivot_and_reads_current_per_course(): void {
		$source = file_get_contents( $this->handler_file );
		// Dual-write in both the batch save and the single-level comedor endpoint.
		$this->assertGreaterThanOrEqual( 2, substr_count( $source, 'ANPA_Socios_DB::set_nivel_comedor(' ) );
		// Current assignment read from the pivot (per course), not the global column.
		$this->assertStringContainsString( "FROM {\$pivot_t} WHERE nivel_id = %d AND curso_escolar = %s", $source );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_niveis_curso()', $source );
	}

	public function test_page_prefills_comedor_from_pivot_per_course(): void {
		$source = file_get_contents( $this->page_file );
		$this->assertStringContainsString( 'ANPA_Socios_DB::get_niveis_comedor_curso( $sel )', $source );
		$this->assertStringContainsString( '$comedor_por_nivel[ $nid ]', $source );
	}
}
