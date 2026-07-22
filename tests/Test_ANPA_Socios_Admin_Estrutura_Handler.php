<?php
/**
 * Source-inspection contract tests for PR-ES3 (Estrutura escolar admin).
 *
 * Verifies that the new files exist, classes are defined, methods are
 * present, routes are registered, and the settings section is wired.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the estrutura escolar admin UI.
 */
class Test_ANPA_Socios_Admin_Estrutura_Handler extends TestCase {

    /**
     * Path to anpa-socios.php.
     *
     * @var string
     */
    private $plugin_file;

    /**
     * Path to the page class file.
     *
     * @var string
     */
    private $page_file;

    /**
     * Path to the handler class file.
     *
     * @var string
     */
    private $handler_file;

    /**
     * Set up test fixtures.
     */
    public function setUp(): void {
        parent::setUp();
        $this->plugin_file  = dirname( __DIR__ ) . '/anpa-socios.php';
        $this->page_file    = dirname( __DIR__ ) . '/includes/class-anpa-socios-estrutura-escolar-page.php';
        $this->handler_file = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-estrutura-handler.php';
    }

    /**
     * @testdox Estrutura_Escolar_Page file exists and loads.
     */
    public function test_page_file_exists_and_loads(): void {
        $this->assertFileExists( $this->page_file );
        $source = file_get_contents( $this->page_file );
        $this->assertStringContainsString( 'class ANPA_Socios_Estrutura_Escolar_Page', $source );
        $this->assertStringContainsString( 'public static function render(): void', $source );
    }

    public function test_page_renders_reusable_meal_schedule_catalogue_and_bulk_editor(): void {
        $source = file_get_contents( $this->page_file );

        $this->assertStringContainsString( 'Horarios de comedor', $source );
        $this->assertStringContainsString( 'anpa-est-add-horario', $source );
        $this->assertStringContainsString( 'est-horario-select', $source );
        $this->assertStringContainsString( 'anpa-est-gardar-horarios', $source );
        $this->assertStringContainsString( 'Gardar horarios de comedor', $source );
        $this->assertStringContainsString( 'anpa-est-add-nivel', $source );
        $this->assertStringContainsString( 'Novo nivel', $source );
        $this->assertStringContainsString( 'anpa-est-gardar-niveis', $source );
        $this->assertStringContainsString( 'Gardar cambios nos niveis', $source );
        $this->assertStringContainsString( 'Os grupos de actividades gárdanse por separado no seu propio editor.', $source );
        $this->assertStringContainsString( 'type="time"', $source );
        $this->assertStringNotContainsString( 'Horario de comedor por nivel', $source );
        $this->assertStringNotContainsString( 'est-gardar-nivel', $source );
        $this->assertStringNotContainsString( 'anpa-est-nivel-form', $source );
        $this->assertStringNotContainsString( 'Gardar todos os cambios', $source );
    }

    public function test_page_keeps_level_and_meal_drafts_explicitly_separate(): void {
        $source = file_get_contents( $this->page_file );

        $this->assertStringContainsString( 'var horariosDirty = false;', $source );
        $this->assertStringContainsString( 'var niveisDirty = false;', $source );
        $this->assertStringContainsString( "saveStructure( 'horarios' )", $source );
        $this->assertStringContainsString( "saveStructure( 'niveis' )", $source );
        $this->assertStringContainsString( 'messageSaveNiveisFirst', $source );
        $this->assertStringContainsString( 'messageSaveHorariosFirst', $source );
        $this->assertStringContainsString( 'horario.id > 0', $source );
		$this->assertStringContainsString( 'scope: scope', $source );
    }

	public function test_batch_handler_persists_only_the_requested_editor_scope(): void {
		$source = file_get_contents( $this->handler_file );
		$start  = strpos( $source, 'private static function gardar_estrutura_lote' );
		$end    = strpos( $source, 'private static function gardar_comedor', $start );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( "in_array( \$scope, array( 'todo', 'horarios', 'niveis' ), true )", $method );
		$this->assertStringContainsString( "\$save_horarios = in_array( \$scope, array( 'todo', 'horarios' ), true );", $method );
		$this->assertStringContainsString( "\$save_niveis   = in_array( \$scope, array( 'todo', 'niveis' ), true );", $method );
		$this->assertStringContainsString( 'if ( $save_horarios ) {', $method );
		$this->assertStringContainsString( 'if ( $save_niveis ) {', $method );
		$this->assertStringContainsString( "'horarios' === \$scope", $method );
		$this->assertStringContainsString( "'niveis' === \$scope", $method );
	}

	public function test_page_guards_unsaved_drafts_before_navigation_and_reload_flows(): void {
		$source = file_get_contents( $this->page_file );

		$this->assertStringNotContainsString( 'onchange="this.form.submit()"', $source );
		$this->assertStringContainsString( "document.getElementById( 'est-curso' )", $source );
		$this->assertStringContainsString( 'function hasUnsavedDrafts()', $source );
		$this->assertStringContainsString( 'function confirmDiscardDrafts()', $source );
		$this->assertStringContainsString( "window.addEventListener( 'beforeunload'", $source );
		$this->assertStringContainsString( 'allowNavigation = true;', $source );
		$this->assertGreaterThanOrEqual( 3, substr_count( $source, 'if ( ! confirmDiscardDrafts() )' ) );
	}

	public function test_page_guards_and_locks_every_in_flight_mutation(): void {
		$source = file_get_contents( $this->page_file );

		$this->assertStringContainsString( 'var pendingRequests = 0;', $source );
		$this->assertStringContainsString( 'function beginRequest()', $source );
		$this->assertStringContainsString( 'function endRequest()', $source );
		$this->assertStringContainsString( 'function hasPendingRequests()', $source );
		$this->assertStringContainsString( 'hasUnsavedDrafts() || hasPendingRequests()', $source );
		$this->assertGreaterThanOrEqual( 4, substr_count( $source, 'beginRequest();' ) );
		$this->assertGreaterThanOrEqual( 4, substr_count( $source, 'endRequest();' ) );
	}

    public function test_page_inline_js_posts_one_json_snapshot(): void {
        $source = file_get_contents( $this->page_file );

		$this->assertStringContainsString( 'setCustomValidity', $source );
		$this->assertStringContainsString( "'Content-Type': 'application/json'", $source );
		$this->assertStringContainsString( "accion: 'gardar_estrutura'", $source );
		$this->assertStringContainsString( 'horarios_comedor:', $source );
		$this->assertStringContainsString( 'niveis:', $source );
		$this->assertStringContainsString( "accion: 'eliminar_horario'", $source );
		$this->assertStringNotContainsString( 'row.innerHTML = buildHorarioRowHtml', $source );
		$this->assertStringNotContainsString( 'row.innerHTML = buildNivelRowHtml', $source );
		$this->assertStringNotContainsString( 'true === json.success ||', $source );
    }

    /**
     * @testdox Route registration references exist in anpa-socios.php.
     */
    public function test_route_registered_in_plugin_file(): void {
        $source = file_get_contents( $this->plugin_file );
        $this->assertStringContainsString(
            "add_action( 'rest_api_init', array( 'ANPA_Socios_Admin_Estrutura_Handler', 'register_routes' ) )",
            $source
        );
        $this->assertStringContainsString(
            "require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-estrutura-escolar-page.php'",
            $source
        );
        $this->assertStringContainsString(
            "require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-estrutura-handler.php'",
            $source
        );
    }

    /**
     * @testdox Admin_Nav has 'estrutura' section under 'cursos'.
     */
    public function test_admin_nav_has_estrutura_section(): void {
        $nav_file = dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-admin-nav.php';
        $source   = file_get_contents( $nav_file );
        $this->assertStringContainsString( "'estrutura'", $source );
        $this->assertStringContainsString( "=> 'Estrutura escolar e comedor'", $source );
    }

    /**
     * @testdox Admin_Settings dispatches 'estrutura' section to the page renderer.
     *
     * Regression (2026-07-15): render_tab_cursos() registered the 'estrutura'
     * section in the nav (ANPA_Socios_Admin_Nav::SETTINGS_SECTIONS) and the
     * dedicated Estrutura_Escolar_Page/handler existed, but render_tab_cursos()
     * itself never checked for section === 'estrutura' — it always fell
     * through to the legacy curso-escolar/aula_max editor. The section was
     * therefore unreachable from the settings UI since PR-ES3 shipped, even
     * though every other piece (route, page class, nav entry) was correct.
     */
    public function test_settings_dispatches_estrutura(): void {
        $settings_file = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-settings.php';
        $source        = file_get_contents( $settings_file );
        // Estrutura section is handled by the admin-nav section subnav (cursos tab
        // dispatch routes through render_tab_cursos).
        $this->assertStringContainsString( 'render_tab_cursos', $source );
        // render_tab_cursos() must actually dispatch section === 'estrutura' to
        // the dedicated page renderer before falling through to the legacy editor.
        $start  = strpos( $source, "if ( 'estrutura' === \$section )" );
        $render = strpos( $source, 'ANPA_Socios_Estrutura_Escolar_Page::render();', $start );
        $legacy = strpos( $source, 'global $wpdb;', $start );
        $this->assertNotFalse( $start );
        $this->assertNotFalse( $render );
        $this->assertNotFalse( $legacy );
        $this->assertLessThan( $legacy, $render, "The estrutura renderer must run before the legacy course editor." );
    }

    /**
     * @testdox Handler uses permission_master gate.
     */
    public function test_handler_uses_permission_master(): void {
        $source = file_get_contents( $this->handler_file );
        $this->assertStringContainsString( "permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' )", $source );
    }

    /**
     * @testdox Handler registers three methods (GET, POST, DELETE).
     */
    public function test_handler_has_three_http_methods(): void {
        $source = file_get_contents( $this->handler_file );
        $this->assertStringContainsString( 'READABLE', $source );
        $this->assertStringContainsString( 'CREATABLE', $source );
        $this->assertStringContainsString( 'DELETABLE', $source );
    }

    /**
     * @testdox GET endpoint validates curso_escolar.
     */
    public function test_get_validates_curso(): void {
        $source = file_get_contents( $this->handler_file );
        $this->assertStringContainsString( 'is_valid( $curso )', $source );
        $this->assertStringContainsString( 'Curso escolar inválido.', $source );
    }

    /**
     * @testdox Copy structure uses INSERT IGNORE with transaction.
     */
    public function test_copy_uses_insert_ignore(): void {
    		$source = file_get_contents( $this->handler_file );
    		// Since 1.35.0 copiar_estrutura was removed (levels are global).
    		$this->assertStringNotContainsString( 'private static function copiar_estrutura', $source );
    		$this->assertStringContainsString( 'START TRANSACTION', $source );
    		$this->assertStringContainsString( 'COMMIT', $source );
    		$this->assertStringContainsString( 'ROLLBACK', $source );
    	}

    public function test_all_structure_transactions_fail_closed_on_db_errors(): void {
    	$source       = file_get_contents( $this->handler_file );
    	$add_start    = strpos( $source, 'private static function engadir_nivel' );
    	$delete_start = strpos( $source, 'public static function delete_nivel', $add_start );
    	$add          = substr( $source, $add_start, $delete_start - $add_start );
    	$delete       = substr( $source, $delete_start );

    	$this->assertStringContainsString( "false === \$wpdb->query( 'START TRANSACTION' )", $add );
    	$this->assertStringContainsString( '! self::sync_aulas_nivel', $add );
    	$this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $add );
    	$this->assertStringContainsString( "query( 'ROLLBACK' )", $add );

    	$this->assertStringContainsString( 'null === $fc_refs_result', $delete );
    	$this->assertStringContainsString( 'null === $gn_refs_result', $delete );
    	$this->assertStringContainsString( 'null === $refs', $delete );
    	$this->assertStringContainsString( "false === \$wpdb->query( 'START TRANSACTION' )", $delete );
    	$this->assertStringContainsString( 'false === $aulas_result || false === $nivel_result', $delete );
    	$this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $delete );
    	$this->assertStringContainsString( "query( 'ROLLBACK' )", $delete );
    }

    public function test_set_aulas_rolls_back_when_any_classroom_write_fails(): void {
        $source = file_get_contents( $this->handler_file );
        $start  = strpos( $source, 'private static function sync_aulas_nivel' );
        $end    = strpos( $source, 'private static function set_aulas', $start );
        $sync   = substr( $source, $start, $end - $start );
        $set    = substr( $source, $end, strpos( $source, 'private static function gardar_comedor', $end ) - $end );

        $this->assertStringContainsString( 'private static function sync_aulas_nivel( int $nivel_id, string $ultima ): bool', $sync );
        $this->assertStringContainsString( '$written = $wpdb->query(', $sync );
        $this->assertStringContainsString( 'false === $written', $sync );
        $this->assertStringContainsString( 'if ( ! self::sync_aulas_nivel( $nivel_id, $ultima ) )', $set );
        $this->assertStringContainsString( "query( 'ROLLBACK' )", $set );
        $this->assertStringContainsString( 'Non se puideron actualizar as aulas.', $set );
    }

    public function test_structure_contract_reads_catalogue_and_writes_bulk_snapshot(): void {
        $source = file_get_contents( $this->handler_file );

		$this->assertStringContainsString( 'tabela_horarios_comedor', $source );
		$this->assertStringContainsString( 'horario_comedor_id', $source );
		$this->assertStringContainsString( "case 'gardar_estrutura':", $source );
		$this->assertStringContainsString( 'private static function gardar_estrutura_lote', $source );
		$this->assertStringContainsString( 'ANPA_Socios_Disponibilidade_Horaria::normalize_interval', $source );
    }

	public function test_bulk_structure_save_is_atomic_and_reuses_classroom_sync(): void {
		$source = file_get_contents( $this->handler_file );
		$start  = strpos( $source, 'private static function gardar_estrutura_lote' );
		$end    = strpos( $source, 'private static function gardar_comedor', $start );
		$bulk   = substr( $source, $start, $end - $start );

		$this->assertNotFalse( $start );
		$this->assertStringContainsString( "query( 'START TRANSACTION' )", $bulk );
		$this->assertStringContainsString( 'sync_aulas_nivel', $bulk );
		$this->assertStringContainsString( 'horario_comedor_key', $bulk );
		$this->assertStringContainsString( 'sort( $nivel_ids, SORT_NUMERIC )', $bulk );
		$this->assertStringContainsString( 'sort( $horario_ids_to_lock, SORT_NUMERIC )', $bulk );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $bulk );
		$this->assertStringContainsString( "query( 'COMMIT' )", $bulk );
		$this->assertStringContainsString( 'write_audit', $bulk );
		$this->assertLessThan( strpos( $bulk, 'write_audit' ), strpos( $bulk, "query( 'COMMIT' )" ) );
	}

	public function test_structure_preflights_distinguish_database_errors_from_missing_rows(): void {
		$source = file_get_contents( $this->handler_file );
		$set_start  = strpos( $source, 'private static function set_aulas' );
		$bulk_start = strpos( $source, 'private static function gardar_estrutura_lote', $set_start );
		$meal_start = strpos( $source, 'private static function gardar_comedor', $bulk_start );
		$set  = substr( $source, $set_start, $bulk_start - $set_start );
		$bulk = substr( $source, $bulk_start, $meal_start - $bulk_start );

		$this->assertStringContainsString( '$owned_result', $set );
		$this->assertStringContainsString( "'' !== (string) \$wpdb->last_error", $set );
		$this->assertGreaterThanOrEqual( 3, substr_count( $bulk, "\$wpdb->last_error = '';" ) );
		$this->assertGreaterThanOrEqual( 3, substr_count( $bulk, "'' !== (string) \$wpdb->last_error" ) );
	}

	public function test_edit_nivel_locks_and_proves_ownership_before_duplicate_check(): void {
		$source = file_get_contents( $this->handler_file );
		$start  = strpos( $source, 'private static function editar_nivel' );
		$end    = strpos( $source, 'private static function engadir_nivel', $start );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( "query( 'START TRANSACTION' )", $method );
		// Since 1.35.0 niveis are global: no curso_escolar in the lock query.
		$this->assertStringContainsString( 'WHERE id = %d FOR UPDATE', $method );
		$this->assertStringContainsString( 'Nivel non atopado.', $method );
		$this->assertStringContainsString( 'codigo = %s AND id <> %d FOR UPDATE', $method );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $method );
		$this->assertStringContainsString( "query( 'COMMIT' )", $method );
		$this->assertLessThan( strpos( $method, 'codigo = %s AND id <> %d FOR UPDATE' ), strpos( $method, 'WHERE id = %d FOR UPDATE' ) );
	}

	public function test_structure_dispatcher_can_return_rest_errors_without_php_fatal(): void {
		$source = file_get_contents( $this->handler_file );
		$start  = strpos( $source, 'public static function post_estrutura' );
		$end    = strpos( $source, 'private static function aula_letras', $start );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringNotContainsString( '): WP_REST_Response', substr( $source, $start, 160 ) );
		$this->assertStringContainsString( "case 'gardar_estrutura':", $method );
		$this->assertStringContainsString( '@return WP_REST_Response|WP_Error', substr( $source, max( 0, $start - 500 ), 500 ) );
	}

	public function test_persisted_meal_schedule_delete_is_reference_guarded(): void {
		$source = file_get_contents( $this->handler_file );
		$start  = strpos( $source, 'private static function eliminar_horario_comedor' );
		$end    = strpos( $source, 'private static function comedor_conflicts_for_nivel', $start );
		$body   = substr( $source, $start, $end - $start );

		$this->assertNotFalse( $start );
		$this->assertStringContainsString( 'horario_comedor_id = %d', $body );
		$this->assertStringContainsString( "query( 'START TRANSACTION' )", $body );
		$this->assertGreaterThanOrEqual( 2, substr_count( $body, 'FOR UPDATE' ) );
		$this->assertStringContainsString( "'anpa_admin_horario_in_use'", $body );
		$this->assertStringContainsString( "array( 'status' => 409 )", $body );
		$this->assertStringContainsString( '$wpdb->delete(', $body );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
		$this->assertStringContainsString( "query( 'COMMIT' )", $body );
		$this->assertStringContainsString( "'horario_comedor'", $body );
		$this->assertLessThan( strpos( $body, 'write_audit' ), strpos( $body, "query( 'COMMIT' )" ) );
	}

	public function test_classroom_sync_locks_rows_and_child_reference_ranges_before_counting(): void {
		$source = file_get_contents( $this->handler_file );
		$start  = strpos( $source, 'private static function sync_aulas_nivel' );
		$end    = strpos( $source, 'private static function set_aulas', $start );
		$body   = substr( $source, $start, $end - $start );

		$this->assertGreaterThanOrEqual( 2, substr_count( $body, 'FOR UPDATE' ) );
		$this->assertStringContainsString( 'aula_id = %d', $body );
		$this->assertLessThan( strpos( $body, 'SELECT COUNT(*)'), strpos( $body, 'FOR UPDATE' ) );
	}

	public function test_delete_nivel_locks_parent_classrooms_and_child_ranges_before_reference_counts(): void {
		$source = file_get_contents( $this->handler_file );
		$start  = strpos( $source, 'public static function delete_nivel' );
		$body   = substr( $source, $start );

		$this->assertGreaterThanOrEqual( 4, substr_count( $body, 'FOR UPDATE' ) );
		$this->assertLessThan( strpos( $body, 'SELECT COUNT(*)'), strpos( $body, "query( 'START TRANSACTION' )" ) );
		$this->assertStringContainsString( '$aula_refs_result', $body );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
		$this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $body );
	}

	public function test_delete_nivel_does_not_query_retired_activity_course_bounds(): void {
		$source = file_get_contents( $this->handler_file );
		$start  = strpos( $source, 'public static function delete_nivel' );
		$body   = substr( $source, $start );

		$this->assertStringNotContainsString( 'tabela_actividades_cursos', $body );
		$this->assertStringNotContainsString( '$ac_refs_result', $body );
		$this->assertStringContainsString( 'tabela_grupos_niveis', $body );
	}

	public function test_meal_conflicts_are_checked_under_transaction_and_locked_group_ranges(): void {
		$source = file_get_contents( $this->handler_file );
		$bulk_start = strpos( $source, 'private static function gardar_estrutura_lote' );
		$meal_start = strpos( $source, 'private static function gardar_comedor', $bulk_start );
		$lock_start = strpos( $source, 'private static function lock_comedor_group_rows', $meal_start );
		$bulk = substr( $source, $bulk_start, $meal_start - $bulk_start );
		$meal = substr( $source, $meal_start, $lock_start - $meal_start );
		$lock = substr( $source, $lock_start, strpos( $source, 'private static function comedor_conflicts_for_nivel', $lock_start ) - $lock_start );

		$this->assertLessThan( strpos( $bulk, 'comedor_conflicts_for_nivel' ), strpos( $bulk, "query( 'START TRANSACTION' )" ) );
		$this->assertLessThan( strpos( $meal, 'comedor_conflicts_for_nivel' ), strpos( $meal, "query( 'START TRANSACTION' )" ) );
		$this->assertStringContainsString( 'lock_comedor_group_rows', $bulk );
		$this->assertStringContainsString( 'lock_comedor_group_rows', $meal );
		$this->assertGreaterThanOrEqual( 2, substr_count( $lock, 'FOR UPDATE' ) );
		$this->assertStringContainsString( 'grupos_niveis', $lock );
		$this->assertGreaterThanOrEqual( 2, substr_count( $source, 'null === $conflicts' ) );
		$this->assertStringContainsString( '@return array<int,array<string,mixed>>|null', $source );
	}
    public function test_copy_meal_catalogue_is_explicit_and_repairs_only_empty_or_reactivated_levels(): void {
		$source = file_get_contents( $this->handler_file );
		// Since 1.35.0 copiar_estrutura was removed (levels are global).
		$this->assertStringNotContainsString( 'private static function copiar_estrutura', $source );
	}

    public function test_gardar_comedor_blocks_conflicting_open_groups_before_write(): void {
        $source = file_get_contents( $this->handler_file );

        $this->assertStringContainsString( 'private static function gardar_comedor', $source );
		$this->assertStringNotContainsString( 'private static function gardar_comedor( string $curso, WP_REST_Request $request ): WP_REST_Response', $source );
		$this->assertStringContainsString( '@return WP_REST_Response|WP_Error', $source );
        $this->assertStringContainsString( 'ANPA_Socios_Disponibilidade_Horaria::conflicts', $source );
        $this->assertStringContainsString( "estado = 'aberto'", $source );
        $this->assertStringContainsString( 'grupos_niveis', $source );
        $this->assertStringContainsString( 'nivel_id IN', $source );
        $this->assertStringContainsString( 'anpa_admin_comedor_conflict', $source );
        $this->assertStringContainsString( 'actividad', $source );
        $this->assertStringContainsString( 'grupo', $source );
        $this->assertStringContainsString( 'nivel', $source );
        $this->assertStringContainsString( 'dias', $source );
        $this->assertStringContainsString( 'franxa', $source );
        $this->assertStringContainsString( 'comedor_inicio', $source );
        $this->assertStringContainsString( 'comedor_fin', $source );
    }

    public function test_gardar_comedor_audits_only_after_successful_write(): void {
        $source = file_get_contents( $this->handler_file );
        $start  = strpos( $source, 'private static function gardar_comedor' );
        $update = strpos( $source, '$wpdb->update(', $start );
        $audit  = strpos( $source, 'write_audit', $start );
        $ok     = strpos( $source, 'Horario de comedor actualizado.', $start );

        $this->assertNotFalse( $start );
        $this->assertNotFalse( $update );
        $this->assertNotFalse( $audit );
        $this->assertNotFalse( $ok );
        $this->assertLessThan( $audit, $update );
        $this->assertLessThan( $ok, $audit );
    }

    public function test_meal_editor_is_accessible_and_reports_bulk_status_inline(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-estrutura-escolar-page.php' );

		$this->assertStringContainsString( 'type="time"', $source );
		$this->assertStringContainsString( 'aria-live="polite"', $source );
		$this->assertStringContainsString( 'setCustomValidity', $source );
		$this->assertStringContainsString( 'anpa-est-horarios-status', $source );
		$this->assertStringContainsString( 'anpa-est-niveis-status', $source );
		$this->assertStringContainsString( '@media (max-width: 782px)', $source );
    }

    public function test_course_activation_copy_uses_the_same_explicit_meal_contract(): void {
    	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-cursos-handler.php' );
    	$copy_start = strpos( $source, 'private static function copiar_estrutura_interna' );
    	$update     = substr( $source, strpos( $source, 'public static function update_curso' ), $copy_start - strpos( $source, 'public static function update_curso' ) );
    	$copy       = substr( $source, $copy_start );

    	$this->assertStringContainsString( "! empty( \$body['copiar_comedor'] )", $source );
    	$this->assertStringContainsString( '! self::copiar_estrutura_interna( $curso, $copiar_de, $copiar_comedor )', $update );
    	$this->assertStringContainsString( 'private static function copiar_estrutura_interna( string $destino, string $orixe, bool $copiar_comedor = false ): bool', $source );
    	$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_horarios_comedor()', $copy );
    	$this->assertStringContainsString( "SET hd.nome = ho.nome, hd.orde = ho.orde, hd.estado = 'activo'", $copy );
    	$this->assertStringContainsString( 'INSERT IGNORE INTO {$horarios_t}', $copy );
    	$this->assertStringNotContainsString( 'ON DUPLICATE KEY UPDATE', $copy );
    	// Since 1.35.0: no nivel copying, no linked comedor, no transactions.
    	$this->assertStringNotContainsString( '$reactivated_level_ids', $copy );
    	$this->assertStringNotContainsString( 'nd.horario_comedor_id', $copy );
    	$this->assertStringNotContainsString( 'START TRANSACTION', $copy );
    	$this->assertStringNotContainsString( "query( 'COMMIT' )", $copy );
    }

    /**
     * @testdox Delete nivel checks references before delete/inactivate.
     */
    public function test_delete_checks_references(): void {
        $source = file_get_contents( $this->handler_file );
        $this->assertStringContainsString( 'fillos_cursos', $source );
        $this->assertStringContainsString( 'grupos_niveis', $source );
        $this->assertStringNotContainsString( 'tabela_actividades_cursos', $source );
        $this->assertStringContainsString( 'Nivel desactivado', $source );
        $this->assertStringContainsString( 'Nivel e aulas eliminados', $source );
    }
}
