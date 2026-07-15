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

    /**
     * @testdox Admin_Estrutura_Handler file exists and loads.
     */
    public function test_handler_file_exists_and_loads(): void {
        $this->assertFileExists( $this->handler_file );
        $source = file_get_contents( $this->handler_file );
        $this->assertStringContainsString( 'class ANPA_Socios_Admin_Estrutura_Handler', $source );
        $this->assertStringContainsString( 'public static function register_routes(): void', $source );
        $this->assertStringContainsString( 'public static function get_estrutura', $source );
        $this->assertStringContainsString( 'public static function post_estrutura', $source );
        $this->assertStringContainsString( 'public static function delete_nivel', $source );
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
        $this->assertStringContainsString( "=> 'Estrutura escolar'", $source );
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
        $this->assertMatchesRegularExpression(
            "/if\\s*\\(\\s*'estrutura'\\s*===\\s*\\\$section\\s*\\)\\s*\\{\\s*\\n\\s*ANPA_Socios_Estrutura_Escolar_Page::render\\(\\);/",
            $source,
            "render_tab_cursos() must dispatch section='estrutura' to ANPA_Socios_Estrutura_Escolar_Page::render() before the legacy curso-escolar/aula_max editor."
        );
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
