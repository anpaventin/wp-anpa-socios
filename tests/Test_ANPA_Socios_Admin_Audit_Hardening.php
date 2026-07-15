<?php
/**
 * Regression tests for the admin UX audit hardening.
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Admin_Audit_Hardening extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__ );
	}

	public function test_multicourse_normalization_always_contains_primary_and_is_unique(): void {
		$this->assertSame(
			array( '2025/2026', '2024/2025' ),
			ANPA_Socios_Admin_Payload::normalizar_cursos_actividad(
				array( '2024/2025', '2024/2025' ),
				'2025/2026'
			)
		);
	}

	public function test_multicourse_normalization_rejects_invalid_year(): void {
		$this->assertNull(
			ANPA_Socios_Admin_Payload::normalizar_cursos_actividad(
				array( 'curso-invalido' ),
				'2025/2026'
			)
		);
	}

	public function test_company_delete_blocks_every_linked_activity_and_hard_deletes(): void {
		$source = file_get_contents( $this->root . '/includes/class-anpa-socios-admin-empresas-handler.php' );
		$this->assertStringNotContainsString( "estado != 'inactivo'", $source );
		$this->assertStringContainsString( '$wpdb->delete(', $source );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_empresas()', $source );
	}

	public function test_activity_sync_propagates_database_errors_and_is_transactional(): void {
		$source = file_get_contents( $this->root . '/includes/class-anpa-socios-admin-actividades-handler.php' );
		$this->assertStringContainsString( 'START TRANSACTION', $source );
		$this->assertStringContainsString( 'ROLLBACK', $source );
		$this->assertStringContainsString( 'COMMIT', $source );
		$this->assertStringContainsString( 'is_wp_error( $sync_result )', $source );
		$this->assertStringContainsString( '@return int|WP_Error', $source );
	}

	public function test_activity_delete_is_real_and_cleans_course_rows(): void {
		$source = file_get_contents( $this->root . '/includes/class-anpa-socios-admin-actividades-handler.php' );
		$this->assertStringContainsString( '$wpdb->delete( ANPA_Socios_DB::tabela_actividades_cursos()', $source );
		$this->assertStringContainsString( '$wpdb->delete( ANPA_Socios_DB::tabela_actividades()', $source );
	}

	public function test_management_ui_uses_only_registered_school_years(): void {
		$source = file_get_contents( $this->root . '/assets/js/admin-management.js' );
		$page   = file_get_contents( $this->root . '/includes/class-anpa-socios-admin-management-page.php' );
		$this->assertStringContainsString( "'cursosescolares'", $page );
		$this->assertStringContainsString( 'cfg.cursosescolares', $source );
		$this->assertStringNotContainsString( 'generateCursoRange(selectedCursos', $source );
	}

	public function test_settings_initialises_master_and_redirects_updates_to_estado(): void {
		$source = file_get_contents( $this->root . '/includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Config::master_email()', $source );
		$this->assertStringContainsString( "array( 'anpa_msg' => 'updates_checked', 'tab' => 'xeral', 'section' => 'actualizacions' ),", $source );
	}

	public function test_checkbox_style_explicitly_removes_rounding(): void {
		$source = file_get_contents( $this->root . '/assets/css/admin-management.css' );
		$this->assertStringContainsString( 'border-radius: 0;', $source );
	}

	public function test_approval_history_returns_all_events_without_silencing_failures(): void {
		$php = file_get_contents( $this->root . '/includes/class-anpa-socios-admin-approvals-handler.php' );
		$js  = file_get_contents( $this->root . '/assets/js/admin-management.js' );
		$this->assertStringNotContainsString( 'MAX(id)', $php );
		$this->assertStringContainsString( 'Non se puido cargar o historial', $js );
		$this->assertStringContainsString( "solicitado_en: 'Data da solicitude'", $js );
		$this->assertStringContainsString( "resolto_en: 'Data da resolución'", $js );
		$this->assertStringContainsString( "resolto_por: 'Resolto por'", $js );
	}

	public function test_fillo_forms_use_available_course_and_group_selects(): void {
		$js   = file_get_contents( $this->root . '/assets/js/admin-management.js' );
		$page = file_get_contents( $this->root . '/includes/class-anpa-socios-admin-management-page.php' );

		// Dynamic estrutura data passed from PHP to JS.
		$this->assertStringContainsString( "'filloniveis'", $page );
		$this->assertStringContainsString( "'filloaulas'", $page );
		$this->assertStringContainsString( "var anpaNiveis = Array.isArray(cfg.filloniveis)", $js );
		$this->assertStringContainsString( "var anpaAulas  = Array.isArray(cfg.filloaulas)", $js );
		// Legacy fallback still uses buildFilloSelect for cursos/grupos (2 forms × 1 each).
		$this->assertSame( 2, substr_count( $js, 'buildFilloSelect(filloCursos,' ) );
		$this->assertSame( 2, substr_count( $js, 'buildFilloSelect(filloGrupos,' ) );
	}
}
