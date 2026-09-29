<?php
/**
 * 1.77.0: a company / canteen email always opens its panel, even if the same
 * address is also a member (the server says so when the code is verified, so the
 * flow no longer depends on this browser's localStorage), and the Monday email
 * reviews members using a company, canteen or junta address.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Prioridade_Empresa extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_active_company_or_canteen_wins_over_member(): void {
		$this->assertSame( 'empresa', ANPA_Socios_Flow::next( array( 'socio' => 'activo', 'empresa' => 'activo' ) ) );
		$this->assertSame( 'empresa', ANPA_Socios_Flow::next( array( 'socio' => 'baixa', 'empresa' => 'activo' ) ) );
		$this->assertSame( 'empresa', ANPA_Socios_Flow::next( array( 'socio' => 'activo', 'socio_baixa' => 'solicitada', 'empresa' => 'activo' ) ) );
		// An inactive company does not take over a member.
		$this->assertSame( 'area', ANPA_Socios_Flow::next( array( 'socio' => 'activo', 'empresa' => 'baixa' ) ) );
	}

	public function test_review_text_lists_each_conflict_or_says_all_is_fine(): void {
		$this->assertSame( 'Ningún socio/a usa o correo dunha empresa, do comedor ou da xunta directiva.', ANPA_Socios_Aviso_Semanal::revision_correos( array() ) );
		$txt = ANPA_Socios_Aviso_Semanal::revision_correos( array(
			array( 'email' => 'vitae@example.com', 'motivo' => 'empresa', 'nome' => 'Empresa Exemplo' ),
			array( 'email' => 'comedor@example.com', 'motivo' => 'comedor', 'nome' => '' ),
			array( 'email' => 'xunta@example.com', 'motivo' => 'xunta', 'nome' => '' ),
		) );
		$this->assertStringStartsWith( 'OLLO: 3 socio/a(s) usan un correo reservado', $txt );
		$this->assertStringContainsString( 'vitae@example.com (correo da empresa «Empresa Exemplo»)', $txt );
		$this->assertStringContainsString( 'comedor@example.com (correo do comedor)', $txt );
		$this->assertStringContainsString( 'xunta@example.com (correo da xunta directiva)', $txt );
	}

	public function test_weekly_email_goes_also_when_only_the_review_finds_something(): void {
		$this->assertTrue( ANPA_Socios_Aviso_Semanal::debe_enviar( array( 'total' => 0 ), array( array( 'email' => 'x@example.com', 'motivo' => 'comedor', 'nome' => '' ) ) ) );
		$this->assertTrue( ANPA_Socios_Aviso_Semanal::debe_enviar( array( 'total' => 1 ), array() ) );
		$this->assertFalse( ANPA_Socios_Aviso_Semanal::debe_enviar( array( 'total' => 0 ), array() ) );
	}

	public function test_verify_code_tells_the_flow_and_unified_uses_it(): void {
		$v = $this->src( 'includes/class-anpa-socios-verificacion-rest.php' );
		$this->assertStringContainsString( "'fluxo'   => self::fluxo_verificado( \$email )", $v );
		$this->assertStringContainsString( 'ANPA_Socios_Config::is_comedor_email( $email )', $v );
		$js = $this->src( 'assets/js/unified.js' );
		$this->assertStringContainsString( "if (result.fluxo === 'empresa') { flow = 'empresa'; }", $js );
		// Fallback when the verify route comes from the legacy plugin (no «fluxo»).
		$after_area = strpos( $js, 'if (await exchangeVerifiedAreaSession(cfg, result.token)) {' );
		$fallback   = strpos( $js, "if (typeof result.fluxo === 'undefined' && await exchangeVerifiedEmpresaSession(cfg, result.token)) {", (int) $after_area );
		$this->assertNotFalse( $after_area );
		$this->assertNotFalse( $fallback, 'a company/canteen email is tried before falling back to the alta form' );
	}

	public function test_weekly_review_queries_companies_canteen_and_junta(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-approvals-handler.php' );
		$this->assertStringContainsString( 'public static function conflitos_correos(): array', $h );
		$this->assertStringContainsString( 'INNER JOIN {$emp_t} e ON LOWER(TRIM(e.email)) = LOWER(TRIM(s.email))', $h );
		$this->assertStringContainsString( 'ANPA_Socios_Config::comedor_email()', $h );
		$this->assertStringContainsString( 'ANPA_Socios_Config::master_email()', $h );
		$this->assertStringContainsString( "s.rol <> 'master'", $h );
		$c = $this->src( 'includes/class-anpa-socios-aprobacions-semanal.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Aviso_Semanal::debe_enviar( $contas, $conflitos )', $c );
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'aprobacions_pendentes_semanal' );
		$this->assertContains( 'revision_correos', $vars['html'] );
		$this->assertContains( 'revision_correos', $vars['text'] );
	}
}
