<?php
/**
 * Source-inspection contract tests for additive and retirement DB migrations.
 *
 * Reads the actual source of class-anpa-socios-db.php and anpa-socios.php
 * to verify schema contracts without a real database. These tests assert
 * the structural requirements introduced from schema 1.27.0 through 1.31.0.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_DB_Migration extends TestCase {

	private string $db_file;
	private string $plugin_file;

	protected function setUp(): void {
		$this->db_file     = dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php';
		$this->plugin_file = dirname( __DIR__ ) . '/anpa-socios.php';
	}

	public function test_db_version_constant_is_1_32_0(): void {
		$this->assertSame( '1.32.0', ANPA_Socios_DB::DB_VERSION );
	}

	public function test_anpa_socios_db_version_is_1_32_0(): void {
		$source = file_get_contents( $this->plugin_file );
		$this->assertIsString( $source );
		$this->assertStringContainsString( "define( 'ANPA_SOCIOS_DB_VERSION', '1.32.0' )", $source );
	}

	// ── fase26 PR-26s4: annual meal availability (1.32.0) ────────────

	public function test_clean_install_niveis_schema_has_nullable_meal_window(): void {
		$source = file_get_contents( $this->db_file );
		$start  = strpos( $source, 'CREATE TABLE {$niveis}' );
		$end    = strpos( $source, '$aulas = self::tabela_aulas()', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$create = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( 'comedor_inicio char(5) NULL DEFAULT NULL', $create );
		$this->assertStringContainsString( 'comedor_fin char(5) NULL DEFAULT NULL', $create );
	}

	public function test_migrate_to_1_32_0_is_gated_and_retry_safe(): void {
		$source = file_get_contents( $this->db_file );

		$this->assertStringContainsString( 'if ( ! self::migrate_to_1_32_0() )', $source );
		$this->assertStringContainsString( 'Migration halted at step 1.32.0', $source );
		$this->assertStringContainsString( 'private static function migrate_to_1_32_0(): bool', $source );
		$this->assertStringContainsString( "tem_columna( \$niveis, 'comedor_inicio' )", $source );
		$this->assertStringContainsString( "tem_columna( \$niveis, 'comedor_fin' )", $source );
		$this->assertStringContainsString( 'ADD COLUMN comedor_inicio char(5) NULL DEFAULT NULL', $source );
		$this->assertStringContainsString( 'ADD COLUMN comedor_fin char(5) NULL DEFAULT NULL', $source );
		$this->assertStringContainsString( '1.32.0 comedor postcondition failed', $source );
	}

	// ── fase24 PR-GC2: curricular-groups migration (1.28.0) ───────────

	public function test_tabela_grupos_curriculares_helpers_exist(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'tabela_grupos_curriculares', $source );
		$this->assertStringContainsString( 'anpa_grupos_curriculares', $source );
		$this->assertStringContainsString( 'tabela_grupos_curriculares_niveis', $source );
		$this->assertStringContainsString( 'tabela_actividades_cursos_grupos_curriculares', $source );
	}

	public function test_migrate_to_1_28_0_exists_and_is_gated(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'migrate_to_1_28_0', $source );
		$this->assertStringContainsString( 'if ( ! self::migrate_to_1_28_0() )', $source );
	}

	public function test_migrate_to_1_28_0_adds_exclusive_horario_enum(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( "ADD COLUMN horario enum('manha','tarde')", $source );
	}

	public function test_migrate_to_1_28_0_adds_grupo_curricular_id_to_grupos(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'ADD COLUMN grupo_curricular_id', $source );
	}

	public function test_migrate_to_1_28_0_creates_curricular_group_tables(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'franxa_manha', $source );
		$this->assertStringContainsString( 'franxa_tarde', $source );
		$this->assertStringContainsString( 'UNIQUE KEY curso_etiqueta (curso_escolar, etiqueta)', $source );
		$this->assertStringContainsString( 'anpa_actividades_cursos_grupos_curriculares', $source );
	}

	public function test_migrate_to_1_28_0_backfills_horario_non_destructively(): void {
		$source = file_get_contents( $this->db_file );
		// manha-only and tarde-only inference, leaving ambiguous rows NULL.
		$this->assertStringContainsString( "SET horario = 'manha'", $source );
		$this->assertStringContainsString( "SET horario = 'tarde'", $source );
		$this->assertStringContainsString( "horarios NOT LIKE '%tarde%'", $source );
		$this->assertStringContainsString( "horarios NOT LIKE '%manha%'", $source );
	}

	// ── fase24 PR-GC7: destructive retirement migration (1.31.0) ─────

	public function test_migrate_to_1_31_0_exists_and_is_gated(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'migrate_to_1_31_0', $source );
		$this->assertStringContainsString( 'if ( ! self::migrate_to_1_31_0() )', $source );
	}

	public function test_migrate_to_1_31_0_drops_curricular_tables_with_if_exists(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $source );
		$this->assertStringContainsString( 'anpa_grupos_curriculares', $source );
		$this->assertStringContainsString( 'anpa_grupos_curriculares_niveis', $source );
		$this->assertStringContainsString( 'anpa_actividades_cursos_grupos_curriculares', $source );
		$this->assertStringContainsString( 'table_missing', $source );
	}

	public function test_migrate_to_1_31_0_drops_grupo_curricular_id_with_index_cleanup(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( "SHOW COLUMNS FROM", $source );
		$this->assertStringContainsString( "grupo_curricular_id", $source );
		$this->assertStringContainsString( 'information_schema.statistics', $source );
		$this->assertStringContainsString( 'DROP INDEX grupo_curricular_id', $source );
		$this->assertStringContainsString( 'DROP COLUMN grupo_curricular_id', $source );
	}

	public function test_migrate_to_1_31_0_drops_legacy_activity_columns_with_guards(): void {
		$source = file_get_contents( $this->db_file );
		// The retirement loop guards each column and drops it dynamically.
		$this->assertStringContainsString( "foreach ( array( 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max' ) as \$column )", $source );
		$this->assertStringContainsString( 'if ( ! self::tem_columna( $actividades, $column ) )', $source );
		$this->assertStringContainsString( 'DROP COLUMN {$column}', $source );
		// And the postconditions verify every column is really gone.
		foreach ( array( 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max' ) as $column ) {
			$this->assertStringContainsString( sprintf( '! self::tem_columna( $actividades, \'%s\' )', $column ), $source );
		}
	}

	public function test_migrate_to_1_31_0_keeps_actividades_curso_escolar(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'tem_columna( $actividades, \'curso_escolar\' )', $source );
		$this->assertStringNotContainsString( 'DROP COLUMN curso_escolar', $source );
	}

	public function test_migrate_to_1_31_0_checks_postconditions_before_returning_true(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'self::table_missing( $gc )', $source );
		$this->assertStringContainsString( 'self::table_missing( $gc_niv )', $source );
		$this->assertStringContainsString( 'self::table_missing( $acy_gc )', $source );
		$this->assertStringContainsString( '! self::tem_columna( $grupos, \'grupo_curricular_id\' )', $source );
		$this->assertStringContainsString( '! self::tem_columna( $act_cursos, \'horario\' )', $source );
		$this->assertStringContainsString( '! self::tem_columna( $actividades, \'min_pupilos\' )', $source );
		$this->assertStringContainsString( '! self::tem_columna( $actividades, \'max_pupilos\' )', $source );
		$this->assertStringContainsString( '! self::tem_columna( $actividades, \'curso_min\' )', $source );
		$this->assertStringContainsString( '! self::tem_columna( $actividades, \'curso_max\' )', $source );
		$this->assertStringContainsString( 'return true;', $source );
	}

	public function test_clean_install_create_sql_no_longer_creates_retired_activity_columns(): void {
		$source = file_get_contents( $this->db_file );
		$start  = strpos( $source, '$actividades_sql = ' );
		$end    = strpos( $source, '$matriculas_sql = ', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$create = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( 'curso_escolar varchar(20) not null default', $create );
		$this->assertStringNotContainsString( 'min_pupilos smallint(5) unsigned not null default 10', $create );
		$this->assertStringNotContainsString( 'max_pupilos smallint(5) unsigned not null default 15', $create );
		$this->assertStringNotContainsString( 'curso_min tinyint(3) unsigned null', $create );
		$this->assertStringNotContainsString( 'curso_max tinyint(3) unsigned null', $create );
	}

	public function test_migrate_to_1_28_0_has_backfill_niveis(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'INSERT IGNORE', $source );
		$this->assertStringContainsString( 'aula_max', $source );
	}

	public function test_migrate_to_1_28_0_has_backfill_grupos_niveis(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( '1-2-3', $source );
		$this->assertStringContainsString( '4-5-6', $source );
	}

	public function test_tabela_niveis_returns_string_with_anpa_niveis(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'tabela_niveis', $source );
		$this->assertStringContainsString( 'anpa_niveis', $source );
	}

	public function test_tabela_aulas_returns_string_with_anpa_aulas(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'tabela_aulas', $source );
		$this->assertStringContainsString( 'anpa_aulas', $source );
	}

	public function test_tabela_grupos_niveis_returns_string_with_anpa_grupos_niveis(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'tabela_grupos_niveis', $source );
		$this->assertStringContainsString( 'anpa_grupos_niveis', $source );
	}

	public function test_migrate_to_1_27_0_exists_and_returns_bool(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'migrate_to_1_27_0', $source );
		$this->assertStringContainsString( 'return false', $source );
	}

	public function test_migrate_to_1_27_0_has_start_transaction(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'START TRANSACTION', $source );
		$this->assertStringContainsString( 'COMMIT', $source );
		$this->assertStringContainsString( 'ROLLBACK', $source );
	}

	public function test_migrate_to_1_27_0_creates_niveis_table(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'anpa_niveis', $source );
		$this->assertStringContainsString( 'curso_escolar', $source );
		$this->assertStringContainsString( 'UNIQUE KEY curso_nivel (curso_escolar, codigo)', $source );
		$this->assertStringContainsString( 'INDEX curso_estado_orde (curso_escolar, estado, orde)', $source );
	}

	public function test_migrate_to_1_27_0_creates_aulas_table(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'anpa_aulas', $source );
		$this->assertStringContainsString( 'nivel_id', $source );
		$this->assertStringContainsString( 'UNIQUE KEY nivel_aula (nivel_id, codigo)', $source );
		$this->assertStringContainsString( 'INDEX nivel_estado_orde (nivel_id, estado, orde)', $source );
	}

	public function test_migrate_to_1_27_0_creates_grupos_niveis_table(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'anpa_grupos_niveis', $source );
		$this->assertStringContainsString( 'grupo_id', $source );
		$this->assertStringContainsString( 'nivel_id', $source );
		$this->assertStringContainsString( 'PRIMARY KEY', $source );
	}

	public function test_migrate_to_1_27_0_alters_fillos_cursos(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'fillos_cursos', $source );
		$this->assertStringContainsString( 'varchar(30)', $source );
		$this->assertStringContainsString( 'varchar(20)', $source );
	}

	public function test_migrate_to_1_27_0_alters_grupos_curso_range(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'curso_range', $source );
		$this->assertStringContainsString( 'varchar(20)', $source );
	}

	public function test_migrate_to_1_27_0_alters_actividades_cursos(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'nivel_min_id', $source );
		$this->assertStringContainsString( 'nivel_max_id', $source );
	}

	public function test_crear_tabelas_calls_migrate_to_1_27_0_with_gate(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'migrate_to_1_26_0', $source );
		$this->assertStringContainsString( 'migrate_to_1_27_0', $source );
		// The gate: if migrate_to_1_27_0 returns false, there must be a return
		// before update_option.
		$lines = explode( "\n", $source );
		$found_gate = false;
		foreach ( $lines as $line ) {
			if ( false !== strpos( $line, 'migrate_to_1_27_0' ) ) {
				$found_gate = true;
			}
		}
		$this->assertTrue( $found_gate, 'migrate_to_1_27_0 call must exist in crear_tabelas' );
		$this->assertStringContainsString( 'if ( ! self::migrate_to_1_27_0() )', $source );
	}

	public function test_migrate_to_1_27_0_has_backfill_niveis(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'INSERT IGNORE', $source );
		$this->assertStringContainsString( 'aula_max', $source );
	}

	public function test_migrate_to_1_27_0_has_backfill_grupos_niveis(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( '1-2-3', $source );
		$this->assertStringContainsString( '4-5-6', $source );
	}
}
