<?php
/**
 * Source-inspection contract tests for PR-ES8 tasks 66-74.
 *
 * Reads the actual source of handler/DB/backup files to verify structural
 * requirements without a real database. Follows the same source-inspection
 * pattern as Test_ANPA_Socios_DB_Migration.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_PR_ES8_Second_Half extends TestCase {

	private string $grupos_handler;
	private string $matriculas_handler;
	private string $actividades_handler;
	private string $db_file;
	private string $backup_file;
	private string $export_file;

	protected function setUp(): void {
		$base = dirname( __DIR__ ) . '/includes';
		$this->grupos_handler      = $base . '/class-anpa-socios-admin-grupos-handler.php';
		$this->matriculas_handler  = $base . '/class-anpa-socios-admin-matriculas-handler.php';
		$this->actividades_handler = $base . '/class-anpa-socios-admin-actividades-handler.php';
		$this->db_file             = $base . '/class-anpa-socios-db.php';
		$this->backup_file         = $base . '/class-anpa-socios-backup.php';
		$this->export_file         = $base . '/lib/class-anpa-socios-alumnos-export.php';
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 66: Cross-year movement prevention
	// ────────────────────────────────────────────────────────────────────

	public function test_mover_fetches_curso_escolar_from_target_grupo(): void {
		$source = file_get_contents( $this->grupos_handler );
		$this->assertStringContainsString(
			'curso_escolar',
			$this->extract_method_body( $source, 'mover' ),
			'mover() must SELECT curso_escolar from the target grupo'
		);
	}

	public function test_mover_checks_cross_year_before_capacity(): void {
		$body = $this->extract_method_body( file_get_contents( $this->grupos_handler ), 'mover' );
		$pos_cross = strpos( $body, 'anpa_admin_mover_curso_escolar' );
		$pos_cap   = strpos( $body, 'anpa_admin_mover_cheo' );
		$this->assertIsInt( $pos_cross, 'Cross-year error code must exist in mover()' );
		$this->assertIsInt( $pos_cap, 'Capacity error code must exist in mover()' );
		$this->assertLessThan( $pos_cap, $pos_cross, 'Cross-year check must precede capacity check' );
	}

	public function test_mover_returns_409_for_cross_year(): void {
		$body = $this->extract_method_body( file_get_contents( $this->grupos_handler ), 'mover' );
		// The cross-year error must carry 409 status.
		$pos_error = strpos( $body, 'anpa_admin_mover_curso_escolar' );
		$this->assertIsInt( $pos_error );
		$nearby = substr( $body, $pos_error, 200 );
		$this->assertStringContainsString( '409', $nearby );
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 67: Legacy matriculas without grupo endpoint
	// ────────────────────────────────────────────────────────────────────

	public function test_matriculas_sen_grupo_route_registered(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$this->assertStringContainsString( 'matriculas/sen-grupo', $source );
	}

	public function test_matriculas_sen_grupo_callback_exists(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$this->assertStringContainsString( 'list_matriculas_sen_grupo', $source );
	}

	public function test_matriculas_sen_grupo_filters_null_grupo_id(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$body   = $this->extract_method_body( $source, 'list_matriculas_sen_grupo' );
		$this->assertStringContainsString( 'grupo_id IS NULL', $body );
	}

	public function test_matriculas_sen_grupo_no_banking_data(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$body   = $this->extract_method_body( $source, 'list_matriculas_sen_grupo' );
		$this->assertStringNotContainsString( 'iban', strtolower( $body ) );
		$this->assertStringNotContainsString( 'nif', strtolower( $body ) );
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 69: Migration failure-state
	// ────────────────────────────────────────────────────────────────────

	public function test_crear_tabelas_checks_last_error_after_steps(): void {
		$source = file_get_contents( $this->db_file );
		$body   = $this->extract_method_body( $source, 'crear_tabelas' );
		$this->assertStringContainsString( 'last_error', $body );
		$this->assertStringContainsString( 'error_log', $body );
	}

	public function test_crear_tabelas_does_not_advance_version_on_error(): void {
		$source = file_get_contents( $this->db_file );
		$body   = $this->extract_method_body( $source, 'crear_tabelas' );
		// The pattern: if error detected, return before update_option.
		$this->assertStringContainsString( "return; // Do NOT advance VERSION_OPTION", $body );
	}

	public function test_crear_tabelas_migration_chain_uses_loop(): void {
		$source = file_get_contents( $this->db_file );
		$body   = $this->extract_method_body( $source, 'crear_tabelas' );
		// The refactored chain should use a loop over $migration_steps.
		$this->assertStringContainsString( 'migration_steps', $body );
		$this->assertStringContainsString( 'foreach', $body );
	}

	public function test_crear_tabelas_logs_which_step_failed(): void {
		$source = file_get_contents( $this->db_file );
		$body   = $this->extract_method_body( $source, 'crear_tabelas' );
		// Must log the step version and method name.
		$this->assertStringContainsString( '$step_version', $body );
		$this->assertStringContainsString( '$method', $body );
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 70: Backup version bump and v1 restore compatibility
	// ────────────────────────────────────────────────────────────────────

	public function test_backup_version_is_2(): void {
		require_once $this->backup_file;
		$this->assertSame( 2, ANPA_Socios_Backup::VERSION );
	}

	public function test_restore_reads_payload_version(): void {
		$source = file_get_contents( $this->backup_file );
		$body   = $this->extract_method_body( $source, 'restore' );
		$this->assertStringContainsString( "payload['version']", $body );
	}

	public function test_restore_backfills_niveis_for_v1(): void {
		$source = file_get_contents( $this->backup_file );
		$body   = $this->extract_method_body( $source, 'restore' );
		$this->assertStringContainsString( 'backup_version', $body );
		$this->assertStringContainsString( 'INSERT IGNORE INTO', $body );
		$this->assertStringContainsString( 'niveis', $body );
	}

	public function test_restore_backfills_aulas_for_v1(): void {
		$source = file_get_contents( $this->backup_file );
		$body   = $this->extract_method_body( $source, 'restore' );
		$this->assertStringContainsString( 'aulas', $body );
		$this->assertStringContainsString( 'aula_max', $body );
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 71: Export year-scoped nivel/aula resolution
	// ────────────────────────────────────────────────────────────────────

	public function test_export_joins_fillos_cursos_scoped_by_grupo_curso_escolar(): void {
		$source = file_get_contents( $this->export_file );
		$body   = $this->extract_method_body( $source, 'rows' );
		$this->assertStringContainsString( 'fillos_cursos fc', $body );
		$this->assertStringContainsString( 'fc.curso_escolar = g.curso_escolar', $body );
	}

	public function test_export_uses_coalesce_for_curso_aula(): void {
		$source = file_get_contents( $this->export_file );
		$body   = $this->extract_method_body( $source, 'rows' );
		$this->assertStringContainsString( 'COALESCE(fc.curso, f.curso)', $body );
		$this->assertStringContainsString( 'COALESCE(fc.aula, f.aula)', $body );
	}

	public function test_export_joins_grupos_for_curso_escolar(): void {
		$source = file_get_contents( $this->export_file );
		$body   = $this->extract_method_body( $source, 'rows' );
		$this->assertStringContainsString( 'anpa_grupos g', $body );
		$this->assertStringContainsString( 'g.id = m.grupo_id', $body );
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 72+74: Actividade deletion cascade
	// ────────────────────────────────────────────────────────────────────

	public function test_delete_actividad_gate_counts_only_matriculas(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		// Must count matriculas only, NOT groups.
		$this->assertStringContainsString( 'has_matriculas', $body );
		// The old pattern counted groups too — ensure it is gone.
		$this->assertStringNotContainsString( 'has_related', $body );
	}

	public function test_delete_actividad_deletes_grupos_niveis(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		$this->assertStringContainsString( 'grupos_niveis', $body );
	}

	public function test_delete_actividad_deletes_grupos_before_actividad(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		$pos_groups  = strpos( $body, 'deleted_groups' );
		$pos_deleted = strpos( $body, 'deleted_courses' );
		$this->assertIsInt( $pos_groups );
		$this->assertIsInt( $pos_deleted );
		$this->assertLessThan( $pos_deleted, $pos_groups, 'Groups must be deleted before actividades_cursos' );
	}

	public function test_delete_actividad_cascade_order(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		// Order: grupos_niveis → grupos → actividades_cursos → actividades.
		$pos_gn      = strpos( $body, 'deleted_gn' );
		$pos_groups  = strpos( $body, 'deleted_groups' );
		$pos_courses = strpos( $body, 'deleted_courses' );
		$pos_act     = strrpos( $body, '$deleted' ); // last $deleted is the actividade itself
		$this->assertIsInt( $pos_gn );
		$this->assertIsInt( $pos_groups );
		$this->assertIsInt( $pos_courses );
		$this->assertLessThan( $pos_groups, $pos_gn );
		$this->assertLessThan( $pos_courses, $pos_groups );
	}

	public function test_delete_actividad_rolls_back_on_group_delete_failure(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		// After each step there must be a ROLLBACK on false === result.
		$this->assertGreaterThan( 3, substr_count( $body, 'ROLLBACK' ), 'Multiple rollback points required for cascade' );
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 73: Matriculas listing under actividade editor
	// ────────────────────────────────────────────────────────────────────

	public function test_actividad_matriculas_route_registered(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$this->assertStringContainsString( '/actividad/', $source );
		$this->assertStringContainsString( '/matriculas', $source );
	}

	public function test_actividad_matriculas_listing_uses_year_scoped_join(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$body   = $this->extract_method_body( $source, 'list_matriculas' );
		$this->assertStringContainsString( 'fillos_cursos fc', $body );
		$this->assertStringContainsString( 'fc.curso_escolar = g.curso_escolar', $body );
	}

	public function test_actividad_matriculas_listing_supports_pagination(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$body   = $this->extract_method_body( $source, 'list_matriculas' );
		$this->assertStringContainsString( 'LIMIT', $body );
		$this->assertStringContainsString( 'OFFSET', $body );
		$this->assertStringContainsString( 'X-WP-Total', $body );
	}

	public function test_actividad_matriculas_listing_supports_estado_filter(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$body   = $this->extract_method_body( $source, 'list_matriculas' );
		$this->assertStringContainsString( 'estado_filter', $body );
	}

	public function test_actividad_matriculas_listing_no_banking_data(): void {
		$source = file_get_contents( $this->matriculas_handler );
		$body   = $this->extract_method_body( $source, 'list_matriculas' );
		$this->assertStringNotContainsString( 'iban', strtolower( $body ) );
		$this->assertStringNotContainsString( 'nif', strtolower( $body ) );
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Extracts the body of a function/method from PHP source (first match).
	 */
	private function extract_method_body( string $source, string $method_name ): string {
		$pattern = '/function\s+' . preg_quote( $method_name, '/' ) . '\s*\(/';
		if ( ! preg_match( $pattern, $source, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$start = $m[0][1];
		// Find the opening brace.
		$brace_pos = strpos( $source, '{', $start );
		if ( false === $brace_pos ) {
			return '';
		}
		$depth = 0;
		$len   = strlen( $source );
		$body  = '';
		for ( $i = $brace_pos; $i < $len; $i++ ) {
			$ch = $source[ $i ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					$body = substr( $source, $brace_pos, $i - $brace_pos + 1 );
					break;
				}
			}
		}
		return $body;
	}
}
