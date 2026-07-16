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
	private string $js;

	protected function setUp(): void {
		$root          = dirname( __DIR__ );
		$this->db      = (string) file_get_contents( $root . '/includes/class-anpa-socios-db.php' );
		$this->handler = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-grupos-handler.php' );
		$this->js      = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
	}

	public function test_schema_adds_group_series_fields_and_backfill(): void {
		$this->assertStringContainsString( "const DB_VERSION = '1.29.0'", $this->db );
		$this->assertStringContainsString( 'function migrate_to_1_29_0', $this->db );
		$this->assertStringContainsString( 'ADD COLUMN serie_uid', $this->db );
		$this->assertStringContainsString( 'ADD COLUMN nome', $this->db );
		$this->assertStringContainsString( "ADD COLUMN horario enum('manha','tarde')", $this->db );
		$this->assertStringContainsString( 'SET serie_uid = UUID()', $this->db );
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

	public function test_series_delete_rolls_back_when_level_relations_cannot_be_deleted(): void {
		$this->assertStringContainsString( '! ANPA_Socios_DB::delete_grupo_niveis( $row_id )', $this->handler );
		$this->assertStringContainsString( "$wpdb->query( 'ROLLBACK' )", $this->handler );
	}

	public function test_activity_list_has_no_primary_course_or_franxa_columns(): void {
		$this->assertStringContainsString(
			"var ACTIV_COLS = ['nome', '_empresa_nome', 'cursos_ofertados', 'estado'];",
			$this->js
		);
	}

	public function test_activity_form_only_keeps_general_fields_and_group_access(): void {
		$start = strpos( $this->js, 'function renderActividadForm' );
		$end   = strpos( $this->js, '// ── Grupos sub-panel', $start );
		$form  = substr( $this->js, $start, $end - $start );
		$this->assertStringContainsString( "cursosescolares", $form );
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
}
