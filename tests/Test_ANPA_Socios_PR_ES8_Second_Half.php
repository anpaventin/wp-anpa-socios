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

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

final class Test_ANPA_Socios_PR_ES8_Second_Half extends TestCase {

	private string $grupos_handler;
	private string $matriculas_handler;
	private string $actividades_handler;
	private string $db_file;
	private string $backup_file;
	private string $export_file;
	private string $extraescolares_rest;

	protected function setUp(): void {
		$base = dirname( __DIR__ ) . '/includes';
		$this->grupos_handler      = $base . '/class-anpa-socios-admin-grupos-handler.php';
		$this->matriculas_handler  = $base . '/class-anpa-socios-admin-matriculas-handler.php';
		$this->actividades_handler = $base . '/class-anpa-socios-admin-actividades-handler.php';
		$this->db_file             = $base . '/class-anpa-socios-db.php';
		$this->backup_file         = $base . '/class-anpa-socios-backup.php';
		$this->export_file         = $base . '/lib/class-anpa-socios-alumnos-export.php';
		$this->extraescolares_rest = $base . '/class-anpa-socios-extraescolares-rest.php';
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

	public function test_enrol_capacity_transaction_fails_closed_on_db_errors(): void {
		$body = $this->extract_method_body( file_get_contents( $this->extraescolares_rest ), 'enrol' );

		$this->assertStringContainsString( "false === \$wpdb->query( 'START TRANSACTION' )", $body );
		$this->assertGreaterThanOrEqual( 5, substr_count( $body, "\$wpdb->last_error = '';" ) );
		$this->assertStringContainsString( "'' !== (string) \$wpdb->last_error", $body );
		$this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $body );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
	}

	public function test_enrol_rechecks_the_shared_meal_gate_under_lock(): void {
		$body = $this->extract_method_body( file_get_contents( $this->extraescolares_rest ), 'enrol' );
		$gate = strpos( $body, 'ANPA_Socios_Grupo_Comedor_Gate::conflicts_for_series' );

		$this->assertIsInt( $gate );
		$this->assertStringContainsString( "'anpa_extra_grupo_comedor_conflict'", $body );
		$this->assertStringContainsString( 'tabela_grupos_niveis', $body );
		$this->assertStringContainsString( 'WHERE grupo_id = %d ORDER BY nivel_id FOR UPDATE', $body );
		$this->assertLessThan( $gate, strpos( $body, "query( 'START TRANSACTION' )" ) );
		$this->assertLessThan( strpos( $body, '$wpdb->insert(' ), $gate );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
	}

	public function test_enrol_authorisations_prefer_the_canonical_group_period_over_the_legacy_cutoff(): void {
		$source = file_get_contents( $this->extraescolares_rest );
		$enrol  = $this->extract_method_body( $source, 'enrol' );
		$auth   = $this->extract_method_body( $source, 'validate_authorisations' );
		$slot   = $this->extract_method_body( $source, 'is_comedor_slot' );

		$this->assertStringContainsString( "validate_authorisations( \$body, (string) \$locked['horario'], (string) \$locked['franxa'] )", $enrol );
		$this->assertStringContainsString( "'manha' === \$horario", $slot );
		$this->assertStringContainsString( "array( 'maña', 'tarde' )", $slot );
		$this->assertStringContainsString( 'is_comedor_slot( $horario, $franxa )', $auth );
		$this->assertStringNotContainsString( 'ANPA_Socios_Disponibilidade_Horaria', $slot );
	}

	public function test_area_js_uses_the_real_group_object_to_choose_canonical_authorisation_period(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/assets/js/area.js' );

		$this->assertStringContainsString( 'function selectedGroup()', $source );
		$this->assertStringContainsString( "const act = oferta.find((a) => String(a.id) === actSel.value);", $source );
		$this->assertStringContainsString( "return act ? (act.grupos || []).find((g) => String(g.id) === grupoSel.value) : null;", $source );
		$this->assertStringContainsString( 'function selectedGroupHorario()', $source );
		$this->assertStringContainsString( "return grupo ? String(grupo.horario || '') : '';", $source );
		$this->assertStringContainsString( "if ('manha' === horario) { return true; }", $source );
		$this->assertStringContainsString( "if ('maña' === horario || 'tarde' === horario) { return false; }", $source );
		$this->assertStringContainsString( 'return isComedorFranja(selectedGroupFranja());', $source );
	}

	public function test_course_enrolment_gate_fails_closed_on_missing_row_or_db_error(): void {
		$source = file_get_contents( $this->extraescolares_rest );
		$body   = $this->extract_method_body( $source, 'course_is_open' );

		$this->assertStringContainsString( "\$wpdb->last_error = '';", $body );
		$this->assertStringContainsString( "'' !== (string) \$wpdb->last_error", $body );
		$this->assertStringContainsString( '! is_array( $row )', $body );
		$this->assertStringNotContainsString( 'default open', $body );
		$this->assertStringNotContainsString( 'return true;', $body );
	}

	public function test_locked_course_gate_runtime_fails_closed_and_uses_for_update(): void {
		if ( ! class_exists( 'ANPA_Socios_Extraescolares_REST' ) ) {
			require_once $this->extraescolares_rest;
		}
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		$original = $GLOBALS['wpdb'];
		$fake = new class() {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public $row = null;
			public string $last_query = '';
			public bool $error_on_read = false;

			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			public function get_row( string $query, $output ) {
				$this->last_query = $query;
				if ( $this->error_on_read ) {
					$this->last_error = 'db failure';
				}
				return $this->row;
			}
		};

		try {
			$GLOBALS['wpdb'] = $fake;
			$method = new ReflectionMethod( ANPA_Socios_Extraescolares_REST::class, 'lock_open_course' );
			$method->setAccessible( true );

			$missing = $method->invoke( null, '2026/2027' );
			$this->assertTrue( is_wp_error( $missing ) );
			$this->assertSame( 'anpa_extra_curso_pechado', $missing->get_error_code() );
			$this->assertStringContainsString( 'FOR UPDATE', $fake->last_query );

			$fake->row = array( 'estado' => 'activo', 'matriculas_abertas' => '1' );
			$this->assertNull( $method->invoke( null, '2026/2027' ) );

			$fake->error_on_read = true;
			$db_error = $method->invoke( null, '2026/2027' );
			$this->assertTrue( is_wp_error( $db_error ) );
			$this->assertSame( 'anpa_extra_db', $db_error->get_error_code() );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	public function test_all_family_course_mutations_revalidate_under_transaction_lock(): void {
		$source = file_get_contents( $this->extraescolares_rest );
		foreach ( array( 'enrol', 'request_baixa', 'cancel_baixa', 'accept_oferta' ) as $name ) {
			$body = $this->extract_method_body( $source, $name );
			$tx   = strpos( $body, "query( 'START TRANSACTION' )" );
			$gate = strpos( $body, 'lock_open_course' );
			$write = min( array_filter( array(
				strpos( $body, '$wpdb->insert(' ),
				strpos( $body, '$wpdb->update(' ),
				strpos( $body, 'UPDATE {$mat_t} SET' ),
			), static fn( $position ): bool => false !== $position ) );

			$this->assertIsInt( $tx, $name . ' must start a transaction' );
			$this->assertIsInt( $gate, $name . ' must lock the course' );
			$this->assertLessThan( $gate, $tx, $name . ' must start the transaction before course lock' );
			$this->assertLessThan( $write, $gate, $name . ' must lock the course before mutation' );
			$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
			$this->assertStringContainsString( "query( 'COMMIT' )", $body );

			if ( 'enrol' === $name ) {
				$group_lock = strpos( $body, 'WHERE id = %d FOR UPDATE' );
				$this->assertLessThan( $group_lock, $gate, 'enrol must lock course before group' );
			} else {
				$group_lock = strpos( $body, 'lock_group_course' );
				$mat_lock   = strpos( $body, 'fetch_owned_matricula( ', $group_lock );
				$this->assertIsInt( $group_lock );
				$this->assertIsInt( $mat_lock );
				$this->assertLessThan( $group_lock, $gate, $name . ' must lock course before group' );
				$this->assertLessThan( $mat_lock, $group_lock, $name . ' must lock group before matricula' );
			}
		}
	}

	public function test_locked_owned_matricula_query_does_not_join_or_lock_groups(): void {
		$source = file_get_contents( $this->extraescolares_rest );
		$body   = $this->extract_method_body( $source, 'fetch_owned_matricula' );

		$this->assertStringContainsString( 'if ( $for_update )', $body );
		$this->assertStringContainsString( 'FROM {$mat_t} m INNER JOIN {$fil_t} f', $body );
		$this->assertStringContainsString( 'LIMIT 1 FOR UPDATE', $body );
		$this->assertStringContainsString( 'LEFT JOIN', $body );
		$this->assertStringContainsString( 'if ( $for_update )', $body );
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

	public function test_backup_version_is_7_with_per_course_comedor_pivot(): void {
		require_once $this->backup_file;
		$this->assertSame( 7, ANPA_Socios_Backup::VERSION );
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

	public function test_delete_actividad_gate_locks_direct_and_group_matriculas(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		// Must count matriculas only, NOT groups.
		$this->assertStringContainsString( 'ORDER BY id FOR UPDATE', $body );
		$this->assertStringContainsString( 'OR grupo_id IN', $body );
	}

	public function test_delete_actividad_deletes_grupos_niveis(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		$this->assertStringContainsString( 'grupos_niveis', $body );
	}

	public function test_delete_actividad_has_no_retired_activity_course_delete(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		$this->assertStringNotContainsString( 'actividades_cursos', $body );
		$this->assertStringContainsString( "DELETE FROM {\$groups}", $body );
	}

	public function test_delete_actividad_cascade_order(): void {
		$source = file_get_contents( $this->actividades_handler );
		$body   = $this->extract_method_body( $source, 'delete_actividad' );
		// Order: grupos_niveis → grupos → actividade.
		$pos_gn      = strpos( $body, "DELETE FROM {\$relations}" );
		$pos_groups  = strpos( $body, "DELETE FROM {\$groups}" );
		$pos_act     = strpos( $body, '$wpdb->delete( $table' );
		$this->assertIsInt( $pos_gn );
		$this->assertIsInt( $pos_groups );
		$this->assertLessThan( $pos_groups, $pos_gn );
		$this->assertLessThan( $pos_act, $pos_groups );
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
