<?php
/**
 * 1.80.0: one family-baixa service for the three ways a member leaves (confirm a
 * request, «Dar de baixa» from the edit panel, course close in cascade), and
 * «Lista Gmail (N)» on the Xestión nav.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Baixa_Familia extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	private function body( string $src, string $fn ): string {
		$i = strpos( $src, 'function ' . $fn . '(' );
		$this->assertNotFalse( $i, $fn );
		$j = strpos( $src, "\n\t}\n", (int) $i );
		return substr( $src, (int) $i, (int) $j - (int) $i );
	}

	public function test_service_is_loaded_by_the_plugin(): void {
		$this->assertStringContainsString( "includes/class-anpa-socios-baixa-familia.php'", $this->src( 'anpa-socios.php' ) );
	}

	public function test_manual_baixa_refuses_with_current_enrolments_and_cascade_gives_them_baixa(): void {
		$s = $this->src( 'includes/class-anpa-socios-baixa-familia.php' );
		$x = $this->body( $s, 'executar' );
		$this->assertStringContainsString( 'if ( ! $cascada_matriculas && array() !== $ctx[\'matriculas\'] ) {', $x );
		$this->assertStringContainsString( 'self::ERRO_MATRICULAS', $x );
		$this->assertStringContainsString( "'status' => 409", $x );
		// Enrolments, both parents and the children go to baixa; each parent gets the email.
		$this->assertStringContainsString( "SET estado = 'baixa', baixa_en = %s, oferta_token = NULL", $x );
		$this->assertStringContainsString( "UPDATE {\$soc_t} SET estado = 'baixa', baixa_estado = 'none'", $x );
		$this->assertStringContainsString( "( id = %d OR familia_id = %d )", $x );
		$this->assertStringContainsString( "UPDATE {\$fil_t} SET estado = 'baixa'", $x );
		$this->assertStringContainsString( 'enviar_baixa_socio_confirmada', $x );
		// Current enrolments = the same states the rest of the plugin treats as current.
		$this->assertStringContainsString( 'ANPA_Socios_Matricula_Estado::VIXENTES', $this->body( $s, 'contexto' ) );
		$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', implode( '', array_map( static function ( $m ) { return $m[0]; }, self::sql( $s ) ) ) ), 'SQL stays ASCII' );
	}

	/** @return array<int,array<int,string>> */
	private static function sql( string $s ): array {
		preg_match_all( '/"(?:SELECT|UPDATE)[^"]*"/', $s, $m, PREG_SET_ORDER );
		return $m;
	}

	public function test_confirm_and_direct_baixa_share_the_service_without_cascade(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-socios-handler.php' );
		$c = $this->body( $h, 'confirm_baixa' );
		$this->assertStringContainsString( "ANPA_Socios_Baixa_Familia::executar( \$request, \$email, \$excepcion ? 'baixa_confirm_excepcion' : 'baixa_confirm', false )", $c );
		$this->assertStringNotContainsString( 'UPDATE', $c, 'the inline family update moved to the service' );
		$d = $this->body( $h, 'baixa_directa' );
		$this->assertStringContainsString( "'/socio/(?P<email>[^/]+)/baixa/directa'", $h );
		$this->assertStringContainsString( 'is_protected_admin', $d );
		$this->assertStringContainsString( "'activo' !== \$estado", $d );
		$this->assertStringContainsString( "'anpa_baixa_curso_en_marcha'", $d );
		$this->assertStringContainsString( "\$curso['en_marcha'] && ! \$excepcion", $d );
		$this->assertStringContainsString( "ANPA_Socios_Baixa_Familia::executar( \$request, \$email, \$excepcion ? 'baixa_directa_excepcion' : 'baixa_directa', false )", $d );
	}

	public function test_course_close_confirms_pending_baixas_in_cascade(): void {
		$s = $this->src( 'includes/class-anpa-socios-baixa-familia.php' );
		$p = $this->body( $s, 'confirmar_pendentes_fin_curso' );
		$this->assertStringContainsString( "baixa_estado = 'solicitada' AND estado = 'activo'", $p );
		$this->assertStringContainsString( "self::executar( \$request, (string) \$email, 'baixa_fin_curso', true )", $p );
		$t = $this->src( 'includes/class-anpa-socios-admin-trimestres-handler.php' );
		$this->assertSame( 2, substr_count( $t, 'ANPA_Socios_Baixa_Familia::confirmar_pendentes_fin_curso( $request )' ), 'course close and end-of-course notice' );
		$this->assertStringContainsString( "'baixas_socios'    => \$baixas_socios", $this->body( $t, 'aviso_fin_curso' ) );
	}

	public function test_audit_labels_for_the_new_decisions(): void {
		$a = static function ( string $accion ): ?array { return ANPA_Socios_Auditoria::aprobacion( $accion, 'socio' ); };
		$this->assertSame( 'Dada de baixa dende a ficha', $a( 'baixa_directa' )['decision'] );
		$this->assertSame( 'Confirmada no peche do curso', $a( 'baixa_fin_curso' )['decision'] );
		$this->assertNotNull( $a( 'baixa_directa_excepcion' ) );
		$this->assertSame( 'Baixa pola baixa da familia', ANPA_Socios_Auditoria::etiqueta( 'baixa_familia', 'fillo' ) );
		foreach ( array( 'baixa_directa_excepcion', 'baixa_fin_curso', 'baixa_confirm_excepcion' ) as $acc ) {
			$this->assertLessThanOrEqual( 40, strlen( $acc ), 'audit accion is varchar(40)' );
		}
	}

	public function test_lista_gmail_counts_altas_plus_baixas(): void {
		require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-contactos-google-handler.php';
		$f       = array( 'ANPA_Socios_Admin_Contactos_Google_Handler', 'conta_cambios' );
		$previos = array(
			'a@example.com' => array( 'email' => 'a@example.com', 'nome' => 'A', 'apelidos' => '' ),
			'b@example.com' => array( 'email' => 'b@example.com', 'nome' => 'B', 'apelidos' => '' ),
		);
		$this->assertSame( 0, $f( array( 'A@example.com ', 'b@example.com' ), $previos ), 'case and spaces ignored' );
		$this->assertSame( 2, $f( array( 'a@example.com', 'c@example.com' ), $previos ), 'one alta + one baixa' );
		$this->assertSame( 2, $f( array( 'a@example.com', 'c@example.com', '' ), array() ), 'no export yet: everyone is new (empty emails ignored)' );
		$this->assertSame( 2, $f( array(), $previos ) );
	}

	public function test_nav_button_shows_lista_gmail_count_and_stands_out(): void {
		$page = $this->src( 'includes/class-anpa-socios-admin-management-page.php' );
		$this->assertStringContainsString( "'lista-gmail' === \$slug", $page );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Contactos_Google_Handler::cambios_pendentes()', $page );
		$this->assertStringContainsString( "' class=\"anpa-mgmt-nav-pendente\"'", $page );
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "'cambios_pendentes' => count( \$altas ) + count( \$baixas )", $h );
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "setNavCount('lista-gmail', 'Lista Gmail'", $js );
		$this->assertStringContainsString( 'function refreshListaGmailCount()', $js );
	}

	public function test_edit_panel_button_and_empty_yellow_box(): void {
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "'/baixa/directa'", $js );
		$this->assertStringContainsString( 'NON se pode desfacer', $js );
		$this->assertStringContainsString( "e.code === 'anpa_baixa_curso_en_marcha' && !body", $js );
		// The download confirmation box is hidden for real until there is something to confirm.
		$this->assertStringContainsString( "confirmBox.style.display = 'none';", $js );
		// 1.82.0: the copy button moved next to «Baixas desde entón», with the list itself.
		$this->assertStringContainsString( 'Xa as eliminei en Google: poñer as baixas a 0', $js );
	}
}
