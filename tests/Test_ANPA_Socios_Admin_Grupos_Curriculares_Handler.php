<?php
/**
 * Source-inspection contract tests for fase24 PR-GC3 (curricular groups admin).
 *
 * Verifies the page/handler files exist, the class + methods are present,
 * routes are registered, the nav section exists AND is actually dispatched
 * (regression guard against the 2026-07-15 'estrutura' routing bug), and the
 * delete path blocks in-use groups.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Admin_Grupos_Curriculares_Handler extends TestCase {

	private string $plugin_file;
	private string $page_file;
	private string $handler_file;
	private string $nav_file;
	private string $settings_file;

	protected function setUp(): void {
		$this->plugin_file   = dirname( __DIR__ ) . '/anpa-socios.php';
		$this->page_file     = dirname( __DIR__ ) . '/includes/class-anpa-socios-grupos-curriculares-page.php';
		$this->handler_file  = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-grupos-curriculares-handler.php';
		$this->nav_file      = dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-admin-nav.php';
		$this->settings_file = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-settings.php';
	}

	public function test_page_file_exists_and_defines_class(): void {
		$this->assertFileExists( $this->page_file );
		$src = file_get_contents( $this->page_file );
		$this->assertStringContainsString( 'class ANPA_Socios_Grupos_Curriculares_Page', $src );
		$this->assertStringContainsString( 'public static function render(): void', $src );
	}

	public function test_handler_file_exists_and_defines_routes(): void {
		$this->assertFileExists( $this->handler_file );
		$src = file_get_contents( $this->handler_file );
		$this->assertStringContainsString( 'class ANPA_Socios_Admin_Grupos_Curriculares_Handler', $src );
		$this->assertStringContainsString( 'public static function register_routes(): void', $src );
		$this->assertStringContainsString( 'public static function get_grupos', $src );
		$this->assertStringContainsString( 'public static function post_grupo', $src );
		$this->assertStringContainsString( 'public static function delete_grupo', $src );
	}

	public function test_handler_uses_permission_master(): void {
		$src = file_get_contents( $this->handler_file );
		$this->assertStringContainsString( "permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' )", $src );
	}

	public function test_routes_and_requires_registered_in_plugin_file(): void {
		$src = file_get_contents( $this->plugin_file );
		$this->assertStringContainsString(
			"add_action( 'rest_api_init', array( 'ANPA_Socios_Admin_Grupos_Curriculares_Handler', 'register_routes' ) )",
			$src
		);
		$this->assertStringContainsString(
			"require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-grupos-curriculares-page.php'",
			$src
		);
		$this->assertStringContainsString(
			"require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-grupos-curriculares-handler.php'",
			$src
		);
	}

	public function test_nav_has_grupos_curriculares_section_under_cursos(): void {
		$src = file_get_contents( $this->nav_file );
		$this->assertStringContainsString( "'grupos-curriculares' => 'Grupos curriculares'", $src );
	}

	/**
	 * Regression guard: the section must be actually dispatched in
	 * render_tab_cursos(), not merely registered in the nav (the exact class
	 * of bug that left 'estrutura' unreachable for several releases).
	 */
	public function test_settings_dispatches_grupos_curriculares_section(): void {
		$src = file_get_contents( $this->settings_file );
		$this->assertMatchesRegularExpression(
			"/if\\s*\\(\\s*'grupos-curriculares'\\s*===\\s*\\\$section\\s*\\)\\s*\\{\\s*\\n\\s*ANPA_Socios_Grupos_Curriculares_Page::render\\(\\);/",
			$src,
			"render_tab_cursos() must dispatch section='grupos-curriculares' to ANPA_Socios_Grupos_Curriculares_Page::render()."
		);
	}

	public function test_delete_blocks_in_use_group(): void {
		$src = file_get_contents( $this->handler_file );
		$this->assertStringContainsString( 'grupo_curricular_in_use', $src );
		$this->assertStringContainsString( '409', $src );
	}

	public function test_post_validates_niveis_belong_to_curso(): void {
		$src = file_get_contents( $this->handler_file );
		$this->assertStringContainsString( 'niveis_belong_to_curso', $src );
		$this->assertStringContainsString( 'normalize_snapshot', $src );
	}

	public function test_db_helpers_exist(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( 'function get_grupos_curriculares', $src );
		$this->assertStringContainsString( 'function get_grupo_curricular', $src );
		$this->assertStringContainsString( 'function grupo_curricular_in_use', $src );
	}
}
