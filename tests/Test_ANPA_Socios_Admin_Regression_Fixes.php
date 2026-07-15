<?php
/**
 * Regression contracts for the admin fixes completed after v1.41.1.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Admin_Regression_Fixes extends TestCase {

	private string $js;
	private string $fillos;
	private string $socios;

	protected function setUp(): void {
		$root         = dirname( __DIR__ );
		$this->js     = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
		$this->fillos = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-fillos-handler.php' );
		$this->socios = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-eliminar-handler.php' );
	}

	public function test_search_helper_restores_focus_and_navigation_clears_query(): void {
		$this->assertStringContainsString( 'function wireSearchInput', $this->js );
		$this->assertStringContainsString( 'input.setSelectionRange', $this->js );
		$this->assertStringContainsString( "sectionState[section].searchQuery = '';", $this->js );
	}

	public function test_fillo_editor_loads_dynamic_structure_and_matriculas(): void {
		$this->assertStringContainsString( 'var anpaNiveis = Array.isArray(cfg.filloniveis)', $this->js );
		$this->assertStringContainsString( "anpaAdminFetch('fillo/' + fillo.id + '/matriculas')", $this->js );
		$this->assertStringContainsString( '/fillo/(?P<id>\\d+)/matriculas', $this->fillos );
		$this->assertStringContainsString( 'ORDER BY g.curso_escolar DESC, m.creado_en DESC', $this->fillos );
	}

	public function test_fillo_hard_delete_is_guarded_and_transactional(): void {
		$this->assertStringContainsString( "'baixa' !== \$estado", $this->fillos );
		$this->assertStringContainsString( 'WHERE fillo_id = %d', $this->fillos );
		$this->assertStringContainsString( "false === \$wpdb->query( 'START TRANSACTION' )", $this->fillos );
		$this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $this->fillos );
		$this->assertStringContainsString( "'delete_hard'", $this->fillos );
	}

	public function test_socio_hard_delete_route_and_dependency_guards_exist(): void {
		$this->assertStringContainsString( "'methods'             => WP_REST_Server::DELETABLE", $this->socios );
		$this->assertStringContainsString( "'callback'            => array( __CLASS__, 'eliminar_socio' )", $this->socios );
		$this->assertStringContainsString( 'public static function eliminar_socio', $this->socios );
		$this->assertStringContainsString( "'baixa' !== \$socio['estado']", $this->socios );
		$this->assertStringContainsString( 'anpa_admin_socio_has_family', $this->socios );
		$this->assertStringContainsString( 'anpa_admin_socio_has_fillos', $this->socios );
		$this->assertStringContainsString( 'anpa_admin_socio_has_domiciliacion', $this->socios );
		$this->assertStringContainsString( "array( 'email' => \$email )", $this->socios );
		$this->assertStringNotContainsString( "array( 'socio_email' => \$email )", $this->socios );
		$this->assertStringContainsString( "'delete'", $this->socios );
		$this->assertStringContainsString( "if (socio.estado === 'baixa')", $this->js );
		$this->assertStringContainsString( "anpaAdminFetch('socio/' + encodeURIComponent(socio.email), { method: 'DELETE' })", $this->js );
	}
}
