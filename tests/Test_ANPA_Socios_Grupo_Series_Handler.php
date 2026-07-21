<?php
/**
 * Contracts for revised fase24 activity-owned group series.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupo_Series_Handler extends TestCase {

	private string $db;
	private string $handler;
	private string $gate;
	private string $js;

	protected function setUp(): void {
		$root          = dirname( __DIR__ );
		$this->db      = (string) file_get_contents( $root . '/includes/class-anpa-socios-db.php' );
		$this->handler = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-grupos-handler.php' );
		$gate_file     = $root . '/includes/lib/class-anpa-socios-grupo-comedor-gate.php';
		$this->gate    = file_exists( $gate_file ) ? (string) file_get_contents( $gate_file ) : '';
		$this->js      = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
	}

	public function test_schema_adds_group_series_fields_and_backfill(): void {
		$this->assertStringContainsString( "const DB_VERSION = '1.33.0'", $this->db );
		$this->assertStringContainsString( 'function migrate_to_1_29_0', $this->db );
		$this->assertStringContainsString( 'ADD COLUMN serie_uid', $this->db );
		$this->assertStringContainsString( 'ADD COLUMN nome', $this->db );
		$this->assertStringContainsString( "ADD COLUMN horario enum('manha','tarde')", $this->db );
		$this->assertStringContainsString( 'SET serie_uid = UUID()', $this->db );
	}

	public function test_schema_accepts_mana_comedor_and_tarde_horarios(): void {
		$this->assertStringContainsString( 'function migrate_to_1_30_0', $this->db );
		$this->assertStringContainsString(
			"MODIFY COLUMN horario enum('maña','manha','tarde') NULL DEFAULT NULL",
			$this->db
		);
	}

	public function test_handler_persists_series_transactionally_and_blocks_history_loss(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Grupo_Serie::normalize', $this->handler );
		$this->assertStringContainsString( 'private static function persist_series', $this->handler );
		$this->assertStringContainsString( "START TRANSACTION", $this->handler );
		$this->assertStringContainsString( "'anpa_admin_grupo_year_in_use'", $this->handler );
		$this->assertStringContainsString( "'anpa_admin_grupo_legacy'", $this->handler );
		$this->assertStringContainsString( 'WHERE grupo_id IN', $this->handler );
		$this->assertStringContainsString( 'niveis_por_ano', $this->handler );
	}

	public function test_series_removal_locks_current_years_and_enrolment_references_before_deciding(): void {
		$start   = strpos( $this->handler, 'private static function persist_series' );
		$end     = strpos( $this->handler, 'public static function confirm_baixa', $start );
		$persist = substr( $this->handler, $start, $end - $start );

		$this->assertStringContainsString( 'SELECT id, curso_escolar FROM {$table}', $persist );
		$this->assertStringContainsString( 'serie_uid = %s ORDER BY id FOR UPDATE', $persist );
		$this->assertStringContainsString( 'SELECT id FROM {$mat} WHERE grupo_id = %d ORDER BY id FOR UPDATE', $persist );
		$this->assertStringContainsString( "'' !== (string) \$wpdb->last_error", $persist );
		$this->assertLessThan( strpos( $persist, 'SELECT id, curso_escolar FROM {$table}' ), strpos( $persist, "query( 'START TRANSACTION' )" ) );
	}

	public function test_open_group_series_is_blocked_by_meal_conflicts_inside_the_transaction(): void {
		$persist_start = strpos( $this->handler, 'private static function persist_series' );
		$persist_end   = strpos( $this->handler, 'public static function confirm_baixa', $persist_start );
		$persist       = substr( $this->handler, $persist_start, $persist_end - $persist_start );
		$this->assertStringContainsString( 'ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series', $persist );
		$this->assertStringContainsString( "'anpa_admin_grupo_comedor_conflict'", $persist );
		$this->assertStringContainsString( "'conflicts'", $persist );
		$this->assertLessThan( strpos( $persist, 'ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series' ), strpos( $persist, "query( 'START TRANSACTION' )" ) );
		$this->assertLessThan( strpos( $persist, '$wpdb->update(' ), strpos( $persist, 'ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series' ) );
	}

	public function test_shared_meal_gate_resolves_and_locks_the_canonical_catalogue(): void {
		$this->assertStringContainsString( 'final class ANPA_Socios_Grupo_Comedor_Gate', $this->gate );
		$this->assertStringContainsString( 'public static function conflicts_for_series', $this->gate );
		$this->assertStringContainsString( 'ANPA_Socios_Disponibilidade_Horaria::conflicts', $this->gate );
		$this->assertStringContainsString( 'tabela_horarios_comedor', $this->gate );
		$this->assertStringContainsString( 'tabela_niveis', $this->gate );
		$this->assertStringContainsString( 'FOR UPDATE', $this->gate );
	}

	public function test_reopening_a_series_uses_the_same_meal_gate_and_rolls_back(): void {
		$start  = strpos( $this->handler, 'public static function set_estado' );
		$end    = strpos( $this->handler, '// ──────────────────────────────────────────────', $start );
		$method = substr( $this->handler, $start, $end - $start );

		$this->assertStringContainsString( "query( 'START TRANSACTION' )", $method );
		$this->assertStringContainsString( 'ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series', $method );
		$this->assertStringContainsString( "'anpa_admin_grupo_comedor_conflict'", $method );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $method );
		$this->assertStringContainsString( "query( 'COMMIT' )", $method );
	}

	public function test_series_delete_rolls_back_when_level_relations_cannot_be_deleted(): void {
		$this->assertStringContainsString( '! ANPA_Socios_DB::delete_grupo_niveis( $row_id )', $this->handler );
		$this->assertStringContainsString( '$wpdb->query( \'ROLLBACK\' )', $this->handler );
	}

	public function test_series_delete_locks_rows_and_history_before_deciding(): void {
		$start  = strpos( $this->handler, 'public static function delete_grupo' );
		$end    = strpos( $this->handler, 'public static function set_estado', $start );
		$method = substr( $this->handler, $start, $end - $start );
		$tx     = strpos( $method, "query( 'START TRANSACTION' )" );
		$series = strpos( $method, 'serie_uid = %s ORDER BY id FOR UPDATE' );
		$refs   = strpos( $method, 'WHERE grupo_id IN ({$in}) ORDER BY id FOR UPDATE' );
		$delete = strpos( $method, '$wpdb->delete(' );

		$this->assertIsInt( $tx );
		$this->assertIsInt( $series );
		$this->assertIsInt( $refs );
		$this->assertIsInt( $delete );
		$this->assertLessThan( $series, $tx );
		$this->assertLessThan( $refs, $series );
		$this->assertLessThan( $delete, $refs );
		$this->assertStringContainsString( "'' !== (string) \$wpdb->last_error", $method );
	}

	public function test_move_is_atomic_and_rechecks_open_capacity_levels_and_meal_gate(): void {
		$start  = strpos( $this->handler, 'public static function mover' );
		$end    = strpos( $this->handler, '// ──────────────────────────────────────────────', $start );
		$method = substr( $this->handler, $start, $end - $start );
		$tx     = strpos( $method, "query( 'START TRANSACTION' )" );
		$target = strpos( $method, 'WHERE id IN ({$group_in}) ORDER BY id FOR UPDATE' );
		$mat    = strpos( $method, "WHERE id = %d AND estado <> 'baixa' FOR UPDATE" );
		$count  = strpos( $method, "estado = 'activo' AND id <> %d ORDER BY id FOR UPDATE" );
		$write  = strpos( $method, 'UPDATE {$mat_t} SET grupo_id' );

		$this->assertIsInt( $tx );
		$this->assertIsInt( $target );
		$this->assertIsInt( $mat );
		$this->assertIsInt( $count );
		$this->assertIsInt( $write );
		$this->assertLessThan( $target, $tx );
		$this->assertLessThan( $mat, $target );
		$this->assertLessThan( $count, $mat );
		$this->assertLessThan( $write, $count );
		$this->assertStringContainsString( "'aberto' !== (string) \$grupo['estado']", $method );
		$this->assertStringContainsString( 'ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series', $method );
		$this->assertStringContainsString( 'WHERE grupo_id = %d ORDER BY nivel_id FOR UPDATE', $method );
		$this->assertStringContainsString( "'' !== (string) \$wpdb->last_error", $method );
		$this->assertStringContainsString( "query( 'COMMIT' )", $method );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $method );
	}

	public function test_activity_list_has_no_primary_course_or_franxa_columns(): void {
		$this->assertStringContainsString(
			"var ACTIV_COLS = ['_empresa_nome', 'nome', 'custo', 'estado'];",
			$this->js
		);
	}

	public function test_activity_form_only_keeps_general_fields_and_group_access(): void {
		$start = strpos( $this->js, 'function renderActividadForm' );
		$end   = strpos( $this->js, '// ── Grupos sub-panel', $start );
		$form  = substr( $this->js, $start, $end - $start );
		$this->assertStringContainsString( "activeCourse", $form );
		$this->assertStringContainsString( "Cursos nos que se ofertou", $form );
		$this->assertStringContainsString( "Grupos da actividade", $form );
		$this->assertStringNotContainsString( "Niveis mínimo/máximo", $form );
		$this->assertStringNotContainsString( "Límites antigos", $form );
		$this->assertStringNotContainsString( "horarios:", $form );
		$this->assertStringNotContainsString( "curso_min:", $form );
	}

	public function test_group_form_contains_all_operational_fields(): void {
		$start = strpos( $this->js, 'function renderGrupoForm' );
		$end   = strpos( $this->js, 'function renderGrupoMatriculas', $start );
		$form  = substr( $this->js, $start, $end - $start );
		foreach ( array( 'Nome do grupo', 'Cursos escolares', 'niveis_por_ano', 'Comedor', 'Franxa horaria', 'Días', 'Mínimo de alumnos/as', 'Máximo de alumnos/as' ) as $label ) {
			$this->assertStringContainsString( $label, $form );
		}
	}

	public function test_group_payload_collects_only_school_year_checkboxes(): void {
		$start = strpos( $this->js, 'function renderGrupoForm' );
		$end   = strpos( $this->js, 'function renderGrupoMatriculas', $start );
		$form  = substr( $this->js, $start, $end - $start );

		$this->assertStringContainsString( "chk.className = 'anpa-mgmt-ano-check'", $form );
		$this->assertStringContainsString( "yearsWrap.querySelectorAll('.anpa-mgmt-ano-check:checked')", $form );
		$this->assertStringNotContainsString( "yearsWrap.querySelectorAll('input:checked')", $form );
	}
}
