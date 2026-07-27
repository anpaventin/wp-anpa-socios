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

	public function test_db_version_constant_is_1_41_0(): void {
		$this->assertSame( '1.41.0', ANPA_Socios_DB::DB_VERSION );
	}

	public function test_anpa_socios_db_version_is_1_41_0(): void {
		$source = file_get_contents( $this->plugin_file );
		$this->assertIsString( $source );
		$this->assertStringContainsString( "define( 'ANPA_SOCIOS_DB_VERSION', '1.41.0' )", $source );
	}

	/**
	 * The two constants must never disagree. They are written in different files, and a bump that
	 * updates one and forgets the other produces a plugin that believes it migrated.
	 */
	public function test_the_two_version_declarations_agree(): void {
		$source = (string) file_get_contents( $this->plugin_file );

		$this->assertStringContainsString(
			"define( 'ANPA_SOCIOS_DB_VERSION', '" . ANPA_Socios_DB::DB_VERSION . "' )",
			$source
		);
	}

	public function test_migrate_to_1_37_0_drops_legacy_comedor_columns_guarded(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( 'private static function migrate_to_1_37_0(): bool', $source );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.37.0', '<' ) && ! self::migrate_to_1_37_0()", $source );
		$start  = strpos( $source, 'private static function migrate_to_1_37_0' );
		$end    = strpos( $source, 'public static function get_niveis_comedor_curso', $start );
		$method = substr( $source, $start, $end - $start );
		$this->assertStringContainsString( "array( 'horario_comedor_id', 'comedor_inicio', 'comedor_fin' )", $method );
		$this->assertStringContainsString( 'self::tem_columna( $niveis, $column )', $method );
		$this->assertStringContainsString( 'DROP COLUMN {$column}', $method );
		$this->assertStringContainsString( 'postcondition failed', $method );
	}

	// ── fase26 correction: reusable meal schedules (1.33.0) ──────────

	public function test_migrate_to_1_33_0_normalizes_meal_schedules_and_removes_global_option(): void {
		$source = (string) file_get_contents( $this->db_file );

		$this->assertStringContainsString( 'tabela_horarios_comedor', $source );
		$this->assertStringContainsString( 'anpa_horarios_comedor', $source );
		$this->assertStringContainsString( 'horario_comedor_id bigint(20) unsigned NULL DEFAULT NULL', $source );
		$this->assertStringContainsString( 'UNIQUE KEY curso_franxa (curso_escolar, inicio, fin)', $source );
		$this->assertStringContainsString( 'private static function migrate_to_1_33_0(): bool', $source );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.33.0', '<' ) && ! self::migrate_to_1_33_0()", $source );
		$this->assertStringContainsString( 'Migration halted at step 1.33.0', $source );
		$this->assertStringContainsString( 'comedor_inicio', $source );
		$this->assertStringContainsString( 'comedor_fin', $source );
		$this->assertStringContainsString( 'SET n.horario_comedor_id = h.id', $source );
		$this->assertStringContainsString( "delete_option( 'anpa_socios_aula_max' )", $source );
		$this->assertStringContainsString( '1.33.0 comedor postcondition failed', $source );
	}

	public function test_migrate_to_1_33_0_preserves_an_existing_schedule_state_on_retry(): void {
		$source = (string) file_get_contents( $this->db_file );
		$start  = strpos( $source, 'function backfill_legacy_horarios_comedor' );
		$end    = strpos( $source, "\n	/**", $start );

		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $source, $start, $end - $start );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', $method );
		$this->assertStringNotContainsString( 'estado = VALUES(estado)', $method );
	}

	public function test_migration_runner_skips_every_schema_step_already_applied(): void {
		$source = (string) file_get_contents( $this->db_file );

		$this->assertStringContainsString(
			"if ( version_compare( \$installed_version, \$step_version, '>=' ) )",
			$source
		);
		foreach ( array( '1.26.0', '1.27.0', '1.28.0', '1.29.0', '1.30.0', '1.31.0', '1.32.0', '1.33.0' ) as $version ) {
			$this->assertStringContainsString(
				"version_compare( \$installed_version, '{$version}', '<' )",
				$source,
				"O paso {$version} debe saltarse cando xa foi aplicado."
			);
		}
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

		$this->assertStringContainsString( "version_compare( \$installed_version, '1.32.0', '<' ) && ! self::migrate_to_1_32_0()", $source );
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
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.28.0', '<' ) && ! self::migrate_to_1_28_0()", $source );
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
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.31.0', '<' ) && ! self::migrate_to_1_31_0()", $source );
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
		// 1.35.0 drops curso_escolar from niveis, but NOT from actividades.
		$this->assertStringContainsString( 'DROP COLUMN curso_escolar', $source );
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
		$this->assertStringContainsString( 'UNIQUE KEY codigo_unico (codigo)', $source );
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
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.27.0', '<' ) && ! self::migrate_to_1_27_0()", $source );
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

	public function test_migrate_to_1_34_0_drops_activity_course_offers_only_after_group_equivalence_preflight(): void {
		$source = file_get_contents( $this->db_file );
		$this->assertStringContainsString( "const DB_VERSION = '" . ANPA_Socios_DB::DB_VERSION . "'", $source );
		$this->assertStringContainsString( 'private static function migrate_to_1_34_0(): bool', $source );
		$this->assertStringContainsString( 'SHOW TABLES LIKE %s', $source );
		$this->assertStringContainsString( "'' !== (string) \$wpdb->last_error", $source );
		$this->assertStringContainsString( 'NOT EXISTS', $source );
		$this->assertStringContainsString( 'INNER JOIN {$relations}', $source );
		$this->assertStringContainsString( 'ac.custo <> a.custo', $source );
		$this->assertStringContainsString( 'ac.estado <> a.estado', $source );
		$this->assertStringContainsString( "COALESCE(ac.franxa, '') = ''", $source );
		$this->assertStringContainsString( 'COALESCE(ac.min_pupilos, 0) = 0', $source );
		$this->assertStringContainsString( 'DROP TABLE {$offers}', $source );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.34.0', '<' )", $source );
	}

	// ── fase34: academic calendar migration (1.38.0) ─────────────────

	public function test_migrate_to_1_38_0_adds_calendar_schema_gated_and_additive(): void {
		$source = (string) file_get_contents( $this->db_file );
		// Gated in the runner and halts without advancing the version on error.
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.38.0', '<' ) && ! self::migrate_to_1_38_0()", $source );
		$this->assertStringContainsString( 'Migration halted at step 1.38.0', $source );
		$this->assertStringContainsString( 'private static function migrate_to_1_38_0(): bool', $source );
		// Operative trimester close dates on cursos, guarded (idempotent).
		$this->assertStringContainsString( "tem_columna( \$cursos, 't1_peche_operativo' )", $source );
		$this->assertStringContainsString( "tem_columna( \$cursos, 't2_peche_operativo' )", $source );
		$this->assertStringContainsString( 'ADD COLUMN t1_peche_operativo DATE NULL', $source );
		$this->assertStringContainsString( 'ADD COLUMN t2_peche_operativo DATE NULL', $source );
		// New tables + helpers.
		$this->assertStringContainsString( 'tabela_curso_trimestres', $source );
		$this->assertStringContainsString( 'anpa_curso_trimestres', $source );
		$this->assertStringContainsString( 'tabela_transicions', $source );
		$this->assertStringContainsString( 'anpa_transicions', $source );
		$this->assertStringContainsString( 'UNIQUE KEY curso_trimestre (curso_escolar, trimestre)', $source );
		// Postconditions + additive (no DROP in this migration).
		$this->assertStringContainsString( '1.38.0 table creation postcondition failed', $source );
		$this->assertStringContainsString( '1.38.0 cursos column postcondition failed', $source );
		// The transicions audit table carries the correlation id + reason columns.
		$this->assertStringContainsString( 'correlacion varchar(64)', $source );
		$this->assertStringContainsString( 'motivo varchar(255)', $source );
	}

	public function test_migrate_to_1_38_1_extends_audit_log_gated_and_additive(): void {
		$source = (string) file_get_contents( $this->db_file );
		// Gated in the runner and halts without advancing the version on error.
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.38.1', '<' ) && ! self::migrate_to_1_38_1()", $source );
		$this->assertStringContainsString( 'Migration halted at step 1.38.1', $source );
		$this->assertStringContainsString( 'private static function migrate_to_1_38_1(): bool', $source );
		// Additive columns guarded by existence checks (idempotent).
		$this->assertStringContainsString( "tem_columna( \$tr, 'correlacion' )", $source );
		$this->assertStringContainsString( "tem_columna( \$tr, 'motivo' )", $source );
		$this->assertStringContainsString( "ADD COLUMN correlacion varchar(64) NOT NULL DEFAULT '' AFTER orixe", $source );
		$this->assertStringContainsString( "ADD COLUMN motivo varchar(255) NOT NULL DEFAULT '' AFTER correlacion", $source );
		// Additive only — never drops anything in this step.
		$start  = strpos( $source, 'private static function migrate_to_1_38_1' );
		$end    = strpos( $source, 'private static function migrate_to_1_37_0', $start );
		$method = substr( $source, $start, $end - $start );
		$this->assertStringNotContainsString( 'DROP COLUMN', $method );
		$this->assertStringNotContainsString( 'DROP TABLE', $method );
		$this->assertStringContainsString( '1.38.1 transicions audit columns postcondition failed', $method );
	}

	// ── fase35: email queue tables migration (1.39.0) ────────────────

	public function test_migrate_to_1_39_0_creates_email_queue_tables_gated_and_additive(): void {
		$source = (string) file_get_contents( $this->db_file );
		// Gated in the runner and halts without advancing the version on error.
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.39.0', '<' ) && ! self::migrate_to_1_39_0()", $source );
		$this->assertStringContainsString( 'Migration halted at step 1.39.0', $source );
		$this->assertStringContainsString( 'private static function migrate_to_1_39_0(): bool', $source );

		// Table-name helpers (approved English names).
		$this->assertStringContainsString( 'public static function tabela_email_campaigns', $source );
		$this->assertStringContainsString( 'public static function tabela_email_recipients', $source );
		$this->assertStringContainsString( 'public static function tabela_email_attempts', $source );
		$this->assertStringContainsString( 'anpa_email_campaigns', $source );
		$this->assertStringContainsString( 'anpa_email_recipients', $source );
		$this->assertStringContainsString( 'anpa_email_attempts', $source );

		// Dedup enforced by UNIQUE(idempotency_key) on recipients (index-length safe);
		// NOT a UNIQUE on the varchar email.
		$start = strpos( $source, 'private static function migrate_to_1_39_0' );
		$end   = strpos( $source, 'private static function migrate_to_1_37_0', $start );
		$body  = substr( $source, $start, $end - $start );
		$this->assertStringContainsString( 'idempotency_key char(64) NOT NULL', $body );
		$this->assertStringContainsString( 'UNIQUE KEY idempotency_key (idempotency_key)', $body );
		$this->assertStringNotContainsString( 'UNIQUE KEY campaign_email', $body );
		$this->assertStringContainsString( 'UNIQUE KEY recipient_attempt (recipient_id, attempt_no)', $body );
		$this->assertStringContainsString( 'KEY claimable (state, next_attempt_at_utc)', $body );
		$this->assertStringContainsString( 'lease_token char(36)', $body );
		// UTC convention: no session-tz CURRENT_TIMESTAMP defaults; UTC columns.
		$this->assertStringNotContainsString( 'DEFAULT CURRENT_TIMESTAMP', $body );
		$this->assertStringContainsString( 'created_at_utc datetime NOT NULL', $body );
		$this->assertStringContainsString( 'next_attempt_at_utc datetime NULL', $body );
		// Email up to RFC max, message_key present for logical dedup.
		$this->assertStringContainsString( 'email varchar(254) NOT NULL', $body );
		$this->assertStringContainsString( 'message_key varchar(190)', $body );

		// Additive only + postcondition; never drops; never sends.
		$this->assertStringNotContainsString( 'DROP TABLE', $body );
		$this->assertStringNotContainsString( 'DROP COLUMN', $body );
		$this->assertStringContainsString( '1.39.0 email queue table creation postcondition failed', $body );
		$this->assertStringNotContainsString( 'wp_mail', $body );
	}

	public function test_migration_1_39_0_does_not_schedule_or_send(): void {
		$source = (string) file_get_contents( $this->db_file );
		$start  = strpos( $source, 'private static function migrate_to_1_39_0' );
		$end    = strpos( $source, 'private static function migrate_to_1_37_0', $start );
		$body   = substr( $source, $start, $end - $start );
		// The migration must not create campaigns, schedule sends or send email.
		$this->assertStringNotContainsString( 'wp_schedule_event', $body );
		$this->assertStringNotContainsString( 'wp_mail', $body );
		$this->assertStringNotContainsString( 'INSERT INTO', $body );
	}

	// ── fase36: email template tables migration (1.40.0) ─────────────

	/**
	 * The body of migrate_to_1_40_0, sliced from the source.
	 *
	 * @return string
	 */
	private function migration_1_40_0_body(): string {
		$source = (string) file_get_contents( $this->db_file );
		$start  = strpos( $source, 'private static function migrate_to_1_40_0' );
		$end    = strpos( $source, 'Migration 1.39.0 (fase35)', (int) $start );

		$this->assertIsInt( $start, 'migrate_to_1_40_0 is not declared' );
		$this->assertIsInt( $end, 'the 1.39.0 doc block no longer follows 1.40.0; the slice is stale' );

		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	public function test_migrate_to_1_40_0_creates_the_template_tables_gated_and_additive(): void {
		$source = (string) file_get_contents( $this->db_file );

		// Gated in the runner and halts without advancing the version on error.
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.40.0', '<' ) && ! self::migrate_to_1_40_0()", $source );
		$this->assertStringContainsString( 'Migration halted at step 1.40.0', $source );
		$this->assertStringContainsString( 'private static function migrate_to_1_40_0(): bool', $source );

		// Table-name helpers (approved English names).
		$this->assertStringContainsString( 'public static function tabela_email_templates', $source );
		$this->assertStringContainsString( 'public static function tabela_email_template_versions', $source );
		$this->assertStringContainsString( 'anpa_email_templates', $source );
		$this->assertStringContainsString( 'anpa_email_template_versions', $source );

		$body = $this->migration_1_40_0_body();

		// One row per event, keyed by the stable English key.
		$this->assertStringContainsString( 'template_key varchar(64) NOT NULL', $body );
		$this->assertStringContainsString( 'UNIQUE KEY template_key (template_key)', $body );

		// What protects a site's wording from an update.
		$this->assertStringContainsString( 'is_customised tinyint(1) unsigned NOT NULL DEFAULT 0', $body );
		$this->assertStringContainsString( 'default_version smallint(5) unsigned NOT NULL DEFAULT 1', $body );

		// History indexed for "the last N versions of this template".
		$this->assertStringContainsString( 'KEY template_history (template_id, id)', $body );
		$this->assertStringContainsString( 'was_customised tinyint(1)', $body );
		$this->assertStringContainsString( 'archived_reason varchar(20)', $body );

		// UTC convention: no session-tz defaults, explicit _utc columns.
		$this->assertStringNotContainsString( 'DEFAULT CURRENT_TIMESTAMP', $body );
		$this->assertStringContainsString( 'created_at_utc datetime NOT NULL', $body );
		$this->assertStringContainsString( 'archived_at_utc datetime NOT NULL', $body );
	}

	public function test_the_hash_columns_fit_a_real_scheme_qualified_digest(): void {
		// Derived from the actual digest, not from a round number picked by eye. A first attempt at
		// varchar(80) was three characters too narrow for `template-sha256-v1:` plus 64 hex, and the
		// only symptom was every insert being refused on a real engine — the unit suite could not see
		// it. This makes the column width answerable from the code.
		$digest = ANPA_Socios_Email_Template_Defaults::content_hash( 'Asunto', '<p>html</p>', 'texto' );
		$body   = $this->migration_1_40_0_body();

		preg_match_all( '/(?:default|content)_hash varchar\((\d+)\)/', $body, $widths );
		$this->assertNotEmpty( $widths[1], 'the hash columns are not declared as varchar' );

		foreach ( $widths[1] as $width ) {
			$this->assertGreaterThanOrEqual(
				strlen( $digest ),
				(int) $width,
				"a {$width}-character column cannot hold a " . strlen( $digest ) . '-character digest'
			);
		}
	}

	public function test_the_event_type_column_fits_the_longest_declared_event_key(): void {
		// The queue's own event_type is varchar(40) and the longest template key is 42 characters, so
		// copying that width would have refused two inserts outright.
		$longest = 0;
		foreach ( ANPA_Socios_Email_Template_Events::set()->keys() as $key ) {
			$longest = max( $longest, strlen( $key ) );
		}

		$body = $this->migration_1_40_0_body();
		preg_match( '/event_type varchar\((\d+)\)/', $body, $width );

		$this->assertNotEmpty( $width, 'event_type is not declared as varchar' );
		$this->assertGreaterThanOrEqual( $longest, (int) $width[1] );
	}

	public function test_migration_1_40_0_is_additive_and_checks_its_postcondition(): void {
		$body = $this->migration_1_40_0_body();

		$this->assertStringNotContainsString( 'DROP TABLE', $body );
		$this->assertStringNotContainsString( 'DROP COLUMN', $body );
		$this->assertStringNotContainsString( 'TRUNCATE', $body );
		$this->assertStringContainsString( '1.40.0 email template table creation postcondition failed', $body );
	}

	public function test_migration_1_40_0_seeds_nothing_and_sends_nothing(): void {
		// Schema and content are separate steps on purpose: a schema step that failed halfway must
		// never leave half a catalogue of templates behind, and a migration has no business
		// sending mail.
		$body = $this->migration_1_40_0_body();

		$this->assertStringNotContainsString( 'INSERT INTO', $body );
		$this->assertStringNotContainsString( 'wp_mail', $body );
		$this->assertStringNotContainsString( 'wp_schedule_event', $body );
		$this->assertStringNotContainsString( 'Defaults::', $body );
	}

	public function test_the_history_table_declares_no_foreign_key(): void {
		// Deliberate: history must stay readable after a live row is gone, so it carries its own
		// denormalised template_key instead of depending on a join.
		$body = $this->migration_1_40_0_body();

		$this->assertStringNotContainsString( 'FOREIGN KEY', $body );
		$this->assertStringNotContainsString( 'REFERENCES', $body );

		$versions_start = strpos( $body, "CREATE TABLE {\$versions}" );
		$this->assertIsInt( $versions_start, 'the version table is not created in this migration' );
		$this->assertStringContainsString(
			'template_key varchar(64) NOT NULL',
			substr( $body, (int) $versions_start )
		);
	}

	// ── migrate_to_1_41_0 (I15: widen queue event_type) ──────────────────────

	private function migration_1_41_0_body(): string {
		$source = (string) file_get_contents( $this->db_file );
		$start  = strpos( $source, 'private static function migrate_to_1_41_0' );
		$this->assertIsInt( $start, 'migrate_to_1_41_0 must exist' );
		$next   = strpos( $source, 'private static function migrate_to_1_39_0', (int) $start );
		$this->assertIsInt( $next );
		return substr( $source, (int) $start, (int) $next - (int) $start );
	}

	public function test_migration_1_41_0_widens_queue_event_type_to_64(): void {
		$body = $this->migration_1_41_0_body();

		$this->assertStringContainsString( 'MODIFY COLUMN event_type varchar(64)', $body );
	}

	public function test_migration_1_41_0_is_additive_no_drop(): void {
		$body = $this->migration_1_41_0_body();

		$this->assertStringNotContainsString( 'DROP TABLE', $body );
		$this->assertStringNotContainsString( 'DROP COLUMN', $body );
		$this->assertStringNotContainsString( 'TRUNCATE', $body );
	}

	public function test_migration_1_41_0_checks_its_postcondition(): void {
		$body = $this->migration_1_41_0_body();

		$this->assertStringContainsString( '1.41.0 event_type column widening postcondition failed', $body );
		$this->assertStringContainsString( 'CHARACTER_MAXIMUM_LENGTH', $body );
	}

	public function test_migration_1_41_0_is_wired_into_the_chain(): void {
		$source = (string) file_get_contents( $this->db_file );

		$this->assertStringContainsString(
			"version_compare( \$installed_version, '1.41.0', '<' ) && ! self::migrate_to_1_41_0()",
			$source
		);
	}

	public function test_migration_1_41_0_target_width_fits_the_longest_event_key(): void {
		$longest = 0;
		foreach ( ANPA_Socios_Email_Template_Events::set()->keys() as $key ) {
			$longest = max( $longest, strlen( $key ) );
		}

		$body = $this->migration_1_41_0_body();
		preg_match( '/MODIFY COLUMN event_type varchar\((\d+)\)/', $body, $width );

		$this->assertNotEmpty( $width, 'migrate_to_1_41_0 must widen event_type' );
		$this->assertGreaterThanOrEqual( $longest, (int) $width[1] );
	}
}
