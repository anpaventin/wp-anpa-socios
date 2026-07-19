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

    public function test_page_renders_accessible_meal_block_per_level(): void {
        $source = file_get_contents( $this->page_file );

        $this->assertStringContainsString( 'Horario de comedor por nivel', $source );
        $this->assertStringContainsString( '<fieldset', $source );
        $this->assertStringContainsString( '<legend', $source );
        $this->assertStringContainsString( 'type="time"', $source );
        $this->assertStringContainsString( 'aria-describedby', $source );
        $this->assertStringContainsString( 'est-comedor-limpar', $source );
        $this->assertStringContainsString( 'data-comedor-row', $source );
    }

    public function test_page_inline_js_validates_meal_window_pair_as_a_helpful_client_hint(): void {
        $source = file_get_contents( $this->page_file );

        $this->assertStringContainsString( 'setCustomValidity', $source );
        $this->assertStringContainsString( 'reportValidity', $source );
        $this->assertStringContainsString( 'clearMealPair', $source );
        $this->assertStringContainsString( 'document.querySelectorAll(\'[data-comedor-row]\')', $source );
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
        $this->assertStringContainsString( 'INSERT IGNORE', $source );
        $this->assertStringContainsString( 'START TRANSACTION', $source );
        $this->assertStringContainsString( 'COMMIT', $source );
        $this->assertStringContainsString( 'ROLLBACK', $source );
    }

    public function test_all_structure_transactions_fail_closed_on_db_errors(): void {
        $source       = file_get_contents( $this->handler_file );
        $add_start    = strpos( $source, 'private static function engadir_nivel' );
        $copy_start   = strpos( $source, 'private static function copiar_estrutura', $add_start );
        $delete_start = strpos( $source, 'public static function delete_nivel', $copy_start );
        $add          = substr( $source, $add_start, $copy_start - $add_start );
        $copy         = substr( $source, $copy_start, $delete_start - $copy_start );
        $delete       = substr( $source, $delete_start );

        $this->assertStringContainsString( "false === \$wpdb->query( 'START TRANSACTION' )", $add );
        $this->assertStringContainsString( '! self::sync_aulas_nivel', $add );
        $this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $add );
        $this->assertStringContainsString( "query( 'ROLLBACK' )", $add );

        $this->assertStringContainsString( "false === \$wpdb->query( 'START TRANSACTION' )", $copy );
        $this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $copy );
        $this->assertStringContainsString( "query( 'ROLLBACK' )", $copy );

        $this->assertStringContainsString( 'null === $fc_refs_result || null === $gn_refs_result', $delete );
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

    public function test_structure_contract_reads_and_writes_meal_window(): void {
        $source = file_get_contents( $this->handler_file );

		$this->assertStringContainsString( 'comedor_inicio', $source );
		$this->assertStringContainsString( 'comedor_fin', $source );
		$this->assertStringContainsString( "case 'gardar_comedor':", $source );
		$this->assertStringContainsString( 'ANPA_Socios_Disponibilidade_Horaria::normalize_interval', $source );
    }

    public function test_copy_meal_window_is_explicit_and_does_not_overwrite_destination(): void {
        $source = file_get_contents( $this->handler_file );

		$this->assertStringContainsString( "get_param( 'copiar_comedor' )", $source );
		$this->assertStringContainsString( 'SET nd.comedor_inicio = no.comedor_inicio,', $source );
		$this->assertStringContainsString( 'nd.comedor_fin = no.comedor_fin', $source );
		$this->assertStringContainsString( 'AND %d = 1 AND nd.comedor_inicio IS NULL AND nd.comedor_fin IS NULL', $source );
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

    public function test_meal_editor_is_accessible_and_reports_repairable_conflicts_inline(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-estrutura-escolar-page.php' );

        $this->assertStringContainsString( '<fieldset class="est-comedor-fieldset">', $source );
        $this->assertStringContainsString( 'type="time"', $source );
        $this->assertStringContainsString( 'aria-describedby="est-comedor-help-', $source );
        $this->assertStringContainsString( 'aria-live="polite" tabindex="-1"', $source );
        $this->assertStringContainsString( 'setCustomValidity', $source );
        $this->assertStringContainsString( 'reportValidity', $source );
        $this->assertStringContainsString( 'reportMealError(fields', $source );
        $this->assertStringContainsString( "fields.status.setAttribute('role', 'alert')", $source );
        $this->assertStringContainsString( 'fields.status.focus()', $source );
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
		$this->assertStringContainsString( 'AND %d = 1 AND nd.comedor_inicio IS NULL AND nd.comedor_fin IS NULL', $source );
		$this->assertStringContainsString( "false === \$wpdb->query( 'START TRANSACTION' )", $update );
		$this->assertStringContainsString( '! is_array( $locked_rows )', $update );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $update );
		$this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $update );
		$this->assertLessThan( strpos( $update, "query( 'COMMIT' )" ), strpos( $update, '! self::copiar_estrutura_interna' ) );
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
        $this->assertStringContainsString( 'actividades_cursos', $source );
        $this->assertStringContainsString( 'Nivel desactivado', $source );
        $this->assertStringContainsString( 'Nivel e aulas eliminados', $source );
    }
}
