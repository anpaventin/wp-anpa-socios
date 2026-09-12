<?php
/**
 * 1.55.0: one email, one role (socio ↔ empresa), company panel, area navigation, default cesión.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Ownership extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_normaliser_and_messages(): void {
		$this->assertSame( 'ana@example.com', ANPA_Socios_Email_Ownership::normalizar( '  Ana@Example.com ' ) );
		$this->assertStringContainsString( 'empresa', ANPA_Socios_Email_Ownership::MSG_EMAIL_DE_EMPRESA );
		$this->assertStringContainsString( 'socio/a', ANPA_Socios_Email_Ownership::MSG_EMAIL_DE_SOCIO );
	}

	public function test_every_email_write_path_asks_the_ownership_guard(): void {
		$expect = array(
			'includes/class-anpa-socios-admin-empresas-handler.php' => array( 'ANPA_Socios_Email_Ownership::conflito_para_empresa(', 2 ),
			'includes/class-anpa-socios-admin-import-handler.php'   => array( '_por_email( (string) $email )', 2 ),
			'includes/class-anpa-socios-rest.php'                   => array( "ANPA_Socios_Email_Ownership::conflito_para_socio( \$email, 'p1_email' )", 1 ),
			'includes/class-anpa-socios-area-rest.php'              => array( 'ANPA_Socios_Email_Ownership::conflito_para_socio(', 3 ),
		);
		foreach ( $expect as $file => [ $needle, $count ] ) {
			$this->assertSame( $count, substr_count( $this->src( $file ), $needle ), "{$file} debe chamar ao guard {$count} veces" );
		}
		// The alta guard runs before the transaction starts (no dangling BEGIN).
		$rest  = $this->src( 'includes/class-anpa-socios-rest.php' );
		$guard = strpos( $rest, "conflito_para_socio( \$email, 'p1_email' )" );
		$tx    = strpos( $rest, "\$wpdb->query( 'START TRANSACTION' );", strpos( $rest, 'public static function handle_alta(' ) );
		$this->assertLessThan( $tx, $guard );
		// The public alta form bridges company emails to the area instead of showing the form.
		$js = $this->src( 'assets/js/asociarse.js' );
		$this->assertStringContainsString( "pf.next === 'empresa'", $js );
		$this->assertStringContainsString( 'pertence a unha empresa de actividades', $js );
	}

	public function test_company_panel_exposes_offer_pupils_and_two_export_scopes(): void {
		$cols = ANPA_Socios_Alumnos_Export::columns_panel_empresa();
		foreach ( array( 'actividade_nome', 'grupo_nome', 'nome', 'apelidos', 'curso', 'aula', 'estado', 'trimestre', 'socio_email' ) as $c ) {
			$this->assertContains( $c, $cols );
		}
		// Legacy contract untouched.
		$this->assertCount( 8, ANPA_Socios_Alumnos_Export::columns( false ) );

		$rest = $this->src( 'includes/class-anpa-socios-empresa-rest.php' );
		$this->assertStringContainsString( "\$out['actividades']", $rest );
		$this->assertStringContainsString( "\$out['alumnos'][]", $rest );
		$this->assertStringContainsString( "'todos' === \$ambito", $rest );
		$this->assertStringContainsString( "'alumnos-empresa-todos.csv' : 'alumnos-empresa-activos.csv'", $rest );
		$this->assertStringContainsString( "'export_alumnos_empresa_todos' : 'export_alumnos_empresa'", $rest );

		$lib = $this->src( 'includes/lib/class-anpa-socios-alumnos-export.php' );
		$this->assertStringContainsString( "m.estado IN ('activo','lista_espera','oferta','baixa_solicitada','baixa')", $lib );

		$tpl = $this->src( 'includes/class-anpa-socios-area-page.php' );
		$this->assertStringContainsString( 'data-ambito="activos"', $tpl );
		$this->assertStringContainsString( 'data-ambito="todos"', $tpl );
		$this->assertStringContainsString( 'data-empresa-alumnos', $tpl );

		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( "'ambito=' + ambito", $js );
		$this->assertStringContainsString( 'const EMPRESA_ESTADO_LABELS', $js );
	}

	public function test_unified_entry_routes_company_emails_to_the_company_panel(): void {
		$tpl = $this->src( 'includes/class-anpa-socios-unified-page.php' );
		$this->assertStringContainsString( 'data-empresa-request-code-url=', $tpl );
		$this->assertStringContainsString( 'data-empresa-session-url=', $tpl );
		$this->assertStringContainsString( "rest_url( 'anpa-socios/v1/empresa/solicitar-codigo' )", $tpl );
		$this->assertStringContainsString( "rest_url( 'anpa-socios/v1/empresa/session' )", $tpl );

		$unified = $this->src( 'assets/js/unified.js' );
		$this->assertStringContainsString( "if (next === 'empresa') {", $unified );
		$this->assertStringContainsString( "localStorage.setItem('anpa_unified_flow', 'empresa')", $unified );
		$this->assertStringContainsString( 'async function exchangeVerifiedEmpresaSession(cfg, verificationToken)', $unified );
		$this->assertStringContainsString( "if (flow === 'empresa') {", $unified );
		// The company branch is decided before the socio session exchange and before the alta hand-off.
		$this->assertLessThan( strpos( $unified, 'if (await exchangeVerifiedAreaSession(cfg, result.token))' ), strpos( $unified, "if (flow === 'empresa') {" ) );
		$this->assertLessThan( strpos( $unified, "if (next === 'inactivo') {" ), strpos( $unified, "if (next === 'empresa') {" ) );

		$area = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'root.anpaOpenEmpresa = async function (sessionToken)', $area );
		$this->assertStringContainsString( 'openEmpresa: function (root, sessionToken)', $area );
	}

	public function test_area_navigation_scrolls_to_sections_and_cesion_is_prechecked(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'function scrollToStep(root, step)', $js );
		$this->assertStringContainsString( "scrollToStep(root, step);\n\t\t}", $js );
		$this->assertStringContainsString( "scrollToEl(root.querySelector('[data-fillos-form-title]'));", $js );
		$this->assertStringContainsString( "scrollToStep(root, 'banking');", $js );
		$this->assertStringContainsString( "scrollToStep(root, 'empresa');", $js );
		$this->assertSame( 2, substr_count( $js, "scrollToStep(root, 'fillos');" ), 'gardar e cancelar volven ao inicio da lista' );
		$this->assertStringContainsString( "function addCheckbox(name, label, checked)", $js );
		$this->assertStringContainsString( "para a correcta xestión da actividade extraescolar.', true);", $js );
	}
}
