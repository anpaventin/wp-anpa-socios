<?php
/**
 * 1.57.0: Xestión → Socios → Baixas solicitadas — the junta can see and resolve the withdrawal
 * requests families open from the area (member baixa + activity baixa).
 *
 * Before 1.57.0 the confirm endpoints existed but no screen called them and the documentation pointed
 * to a step that was not in the interface.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Admin_Baixas_Panel extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_handler_is_loaded_and_registered(): void {
		$this->assertStringContainsString( "require_once ANPA_SOCIOS_PLUGIN_DIR . 'includes/class-anpa-socios-admin-baixas-handler.php';", $this->src( 'anpa-socios.php' ) );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Baixas_Handler::register_routes();', $this->src( 'includes/class-anpa-socios-admin-rest.php' ) );
	}

	public function test_routes_are_master_only_and_cover_list_and_both_rejects(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-baixas-handler.php' );
		foreach ( array( "'/baixas-pendentes'", "'/socio/(?P<email>[^/]+)/baixa/reject'", "'/matricula/(?P<id>\d+)/baixa/reject'" ) as $route ) {
			$this->assertStringContainsString( $route, $h );
		}
		$this->assertSame( 3, substr_count( $h, "'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' )" ) );
		// Confirmation stays where the side effects live.
		$this->assertStringContainsString( "'/socio/(?P<email>[^/]+)/baixa/confirm'", $this->src( 'includes/class-anpa-socios-admin-socios-handler.php' ) );
		$this->assertStringContainsString( "'/matricula/(?P<id>\d+)/baixa/confirm'", $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' ) );
	}

	public function test_rejects_are_guarded_by_the_pending_state_and_audited(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-baixas-handler.php' );
		// Socio: only an active member with a pending request; flag cleared, estado untouched.
		$this->assertStringContainsString( "'baixa_estado' => 'solicitada',", $h );
		$this->assertStringContainsString( "'estado'       => 'activo',", $h );
		$this->assertStringContainsString( "'baixa_estado'   => 'none',", $h );
		// Matrícula: back to activo only from baixa_solicitada.
		$this->assertStringContainsString( "array( 'estado' => 'activo', 'actualizado_en' => current_time( 'mysql' ) ),\n			array( 'id' => \$id, 'estado' => 'baixa_solicitada' ),", $h );
		$this->assertSame( 2, substr_count( $h, "'anpa_admin_no_baixa_request'" ) );
		$this->assertSame( 2, substr_count( $h, "'baixa_reject' )" ) );
	}

	public function test_list_sql_is_pure_ascii_and_builds_the_label_in_php(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-baixas-handler.php' );
		$this->assertGreaterThan( 0, preg_match_all( '/"\s*SELECT\b.*?"/s', $h, $m ) );
		foreach ( $m[0] as $sql ) {
			$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $sql ), 'non-ASCII SQL literal: ' . substr( $sql, 0, 100 ) );
		}
		$this->assertStringContainsString( "\$row['curso_completo'] = ANPA_Socios_Admin_Shared::curso_completo(", $h );
		$this->assertStringContainsString( "WHERE s.baixa_estado = 'solicitada' AND s.estado = 'activo' AND s.rol <> 'master'", $h );
		$this->assertStringContainsString( "WHERE m.estado = 'baixa_solicitada'", $h );
	}

	public function test_nav_exposes_the_section_next_to_aprobacions(): void {
		require_once dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-admin-nav.php';
		$sections = ANPA_Socios_Admin_Nav::management_sections();
		$this->assertSame( 'Baixas solicitadas', $sections['socios']['sections']['baixas'] );
		$keys = array_keys( $sections['socios']['sections'] );
		$this->assertSame( array_search( 'aprobacions', $keys, true ) + 1, array_search( 'baixas', $keys, true ) );
		$this->assertSame( 'baixas', ANPA_Socios_Admin_Nav::active_management_section( 'baixas' ) );
	}

	public function test_js_routes_the_section_and_calls_the_four_endpoints(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "'baixas': loadBaixas,", $js );
		$this->assertStringContainsString( "anpaAdminFetch('baixas-pendentes')", $js );
		$this->assertStringContainsString( "var path = 'socio/' + encodeURIComponent(s.email || '') + '/baixa/';", $js );
		$this->assertStringContainsString( "var path = 'matricula/' + m.id + '/baixa/';", $js );
		$this->assertSame( 2, substr_count( $js, "act(path + 'confirm'," ) );
		$this->assertSame( 2, substr_count( $js, "act(path + 'reject'," ) );
		$this->assertStringContainsString( 'Baixas de socios/as pendentes (', $js );
		$this->assertStringContainsString( 'Baixas de actividades pendentes (', $js );
		// Every resolution asks for confirmation first.
		$start = strpos( $js, 'function renderBaixas(data)' );
		$end   = strpos( $js, '// ── Section: Fillos', $start );
		$this->assertSame( 4, substr_count( substr( $js, $start, $end - $start ), 'window.confirm(' ) );
	}

	public function test_docs_point_to_the_new_panel(): void {
		$docs = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertSame( 2, substr_count( $docs, 'Xestión → Socios → Baixas solicitadas' ) );
		$this->assertStringNotContainsString( 'A xunta confírmaa en Xestión → Socios/as;', $docs );
	}
}
