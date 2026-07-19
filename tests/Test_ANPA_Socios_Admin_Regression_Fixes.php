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

	public function test_socio_family_delete_requires_explicit_server_confirmation(): void {
		$this->assertStringContainsString( "'methods'             => WP_REST_Server::DELETABLE", $this->socios );
		$this->assertStringContainsString( "'callback'            => array( __CLASS__, 'eliminar_socio' )", $this->socios );
		$this->assertStringContainsString( "get_param( 'cascade_family' )", $this->socios );
		$this->assertStringContainsString( "get_param( 'confirm' )", $this->socios );
		$this->assertStringContainsString( "'ELIMINAR_FAMILIA'", $this->socios );
		$this->assertStringContainsString( 'anpa_admin_family_confirmation_required', $this->socios );
		$this->assertStringContainsString( "'requires_family_confirmation' => true", $this->socios );
		$this->assertStringContainsString( "'summary'", $this->socios );
		$this->assertStringContainsString( "'other_parents'", $this->socios );
		$this->assertStringContainsString( "'children'", $this->socios );
		$this->assertStringContainsString( "'other_parents'      => max( 0, count( \$members ) - 1 )", $this->socios );
		$this->assertStringContainsString( "'children'           => count( \$children )", $this->socios );
		$this->assertStringContainsString( "'banking'            => \$banking", $this->socios );
		$this->assertStringContainsString( "'sessions'           => \$sessions", $this->socios );
		$this->assertStringContainsString( "'verification_codes' => \$verification_codes", $this->socios );
		$this->assertStringNotContainsString( '\$other_parents[]', $this->socios );
		$this->assertStringNotContainsString( '\$child_summary', $this->socios );
	}

	public function test_socio_family_delete_is_complete_transactional_and_audited(): void {
		$this->assertStringContainsString( "'baixa' !== \$socio['estado']", $this->socios );
		$this->assertStringContainsString( "'master' === \$member['rol']", $this->socios );
		$this->assertStringContainsString( "false === \$wpdb->query( 'START TRANSACTION' )", $this->socios );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_matriculas()', $this->socios );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_fillos_cursos()', $this->socios );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_fillos()', $this->socios );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_domiciliacions()', $this->socios );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_sesions()', $this->socios );
		$this->assertStringContainsString( "'anpa_codigos_verificacion'", $this->socios );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_socios()', $this->socios );
		$this->assertStringContainsString( "\$wpdb->query( 'ROLLBACK' )", $this->socios );
		$this->assertStringContainsString( "false === \$wpdb->query( 'COMMIT' )", $this->socios );
		$this->assertStringContainsString( "'delete_family'", $this->socios );
	}

	public function test_socio_family_delete_locks_dependent_rows_before_deleting(): void {
		$this->assertStringContainsString( 'count_by_ids( string $table, string $column, array $ids, bool $for_update', $this->socios );
		$this->assertStringContainsString( '$for_update ? \' FOR UPDATE\' : \'\'', $this->socios );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_matriculas(), \'fillo_id\', $fillo_ids, $for_update', $this->socios );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_fillos_cursos(), \'fillo_id\', $fillo_ids, $for_update', $this->socios );
		$this->assertStringContainsString( "ANPA_Socios_DB::tabela_domiciliacions(), 'familia_id', array( \$familia_id ), '%d', \$for_update", $this->socios );
		$this->assertStringContainsString( "ANPA_Socios_DB::tabela_sesions(), 'email', \$emails, '%s', \$for_update", $this->socios );
		$this->assertStringContainsString( "\$codes_table, 'email', \$emails, '%s', \$for_update", $this->socios );
	}

	public function test_socio_family_delete_audit_is_atomic_with_the_deletion(): void {
		$audit = strpos( $this->socios, 'if ( ! self::write_delete_audit(' );
		$commit = strpos( $this->socios, "if ( false === \$wpdb->query( 'COMMIT' ) )" );
		$this->assertNotFalse( $audit );
		$this->assertNotFalse( $commit );
		$this->assertLessThan( $commit, $audit );
		$this->assertStringContainsString( "ANPA_Socios_DB::tabela_audit_log()", $this->socios );
		$this->assertStringContainsString( "return false !== \$wpdb->insert(", $this->socios );
		$this->assertStringContainsString( "sprintf( 'familia:%d/socio:%d'", $this->socios );
	}

	public function test_socio_family_delete_ui_preserves_rest_details_and_requires_phrase(): void {
		$this->assertStringContainsString( 'error.code = err.code', $this->js );
		$this->assertStringContainsString( 'error.data = err.data', $this->js );
		$this->assertStringContainsString( 'requires_family_confirmation', $this->js );
		$this->assertStringContainsString( 'Number(summary.other_parents || 0)', $this->js );
		$this->assertStringContainsString( 'Number(summary.children || 0)', $this->js );
		$this->assertStringContainsString( "'- Domiciliacións bancarias: ' +", $this->js );
		$this->assertStringContainsString( "'- Sesións abertas: ' +", $this->js );
		$this->assertStringContainsString( "'- Códigos de verificación: ' +", $this->js );
		$this->assertStringContainsString( "window.prompt(", $this->js );
		$this->assertStringContainsString( "'ELIMINAR_FAMILIA'", $this->js );
		$this->assertStringContainsString( "cascade_family=1&confirm=ELIMINAR_FAMILIA", $this->js );
	}
}
