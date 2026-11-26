<?php
/**
 * 1.102.0 (security audit): the session token never goes in a URL, a member's
 * new email is proved with a code, the second parent cannot rewrite the first,
 * login-code guesses are counted atomically and capped per address, and the
 * duplicate-child error says nothing about the other family.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-anpa-socios-cambio-email.php';

final class Test_ANPA_Socios_Seguridade_Area extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_session_token_is_never_put_in_the_url(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringNotContainsString( '_csrf', $js );
		$this->assertStringContainsString( "'X-Anpa-Area-Token': token", $js );
	}

	public function test_pending_email_change_rules(): void {
		$agora    = 1_000_000;
		$pendente = array( 'email' => 'novo@example.com', 'hash' => 'x', 'expira' => $agora + 60, 'intentos' => 0 );
		$this->assertTrue( ANPA_Socios_Cambio_Email::pendente_vixente( $pendente, 'Novo@Example.com ', $agora ) );
		$this->assertFalse( ANPA_Socios_Cambio_Email::pendente_vixente( $pendente, 'outro@example.com', $agora ), 'the code belongs to one address' );
		$this->assertFalse( ANPA_Socios_Cambio_Email::pendente_vixente( $pendente, 'novo@example.com', $agora + 60 ), 'expired' );
		$pendente['intentos'] = ANPA_Socios_Cambio_Email::CODIGO_INTENTOS;
		$this->assertFalse( ANPA_Socios_Cambio_Email::pendente_vixente( $pendente, 'novo@example.com', $agora ), 'no guesses left' );
		$this->assertFalse( ANPA_Socios_Cambio_Email::pendente_vixente( false, 'novo@example.com', $agora ), 'nothing pending' );
		$this->assertFalse( ANPA_Socios_Cambio_Email::pendente_vixente( array( 'email' => 'novo@example.com' ), 'novo@example.com', $agora ) );
	}

	public function test_own_email_changes_only_after_the_code(): void {
		$a = $this->src( 'includes/class-anpa-socios-area-rest.php' );
		$pedir     = strpos( $a, 'ANPA_Socios_Cambio_Email::solicitar_codigo( (int) $profile[\'id\'], $new_email )' );
		$comprobar = strpos( $a, 'ANPA_Socios_Cambio_Email::comprobar_codigo( (int) $profile[\'id\'], $new_email, $codigo_email )' );
		$escribe   = strpos( $a, "\$update_data['email'] = \$new_email;" );
		$this->assertNotFalse( $pedir );
		$this->assertNotFalse( $comprobar );
		$this->assertLessThan( (int) $escribe, (int) $comprobar, 'the email is written only after the code is checked' );
		$this->assertSame( 1, substr_count( $a, "\$update_data['email'] = \$new_email;" ), 'one single place writes the new email' );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_aviso_cambio_correo( (string) $profile[\'email\']', $a, 'the old address is told' );
		$c = $this->src( 'includes/class-anpa-socios-cambio-email.php' );
		$this->assertLessThan( strpos( $c, 'ANPA_Socios_Codigo_Generator::verify(' ), strpos( $c, "++\$pendente['intentos'];" ), 'the guess is counted before it is checked' );
	}

	public function test_both_parents_edit_each_other_and_the_other_is_told(): void {
		$a = $this->src( 'includes/class-anpa-socios-area-rest.php' );
		$this->assertStringNotContainsString( '$e_principal', $a, '1.103.0: both adults may correct each other' );
		$this->assertStringContainsString( 'ANPA_Socios_Cambio_Email::cambios( $p2_antes, $p2_despois )', $a );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_aviso_datos_modificados(', $a );
		$this->assertStringContainsString( "'' !== \$email_antes ? \$email_antes : \$email_agora", $a, 'a changed login address is told at the OLD address' );
		$this->assertStringNotContainsString( '_anpaP2SoLectura', $this->src( 'assets/js/area.js' ) );
		$e = $this->src( 'includes/class-anpa-socios-email.php' );
		$this->assertStringContainsString( 'Se é un erro, ponte en contacto canto antes coa administración da ANPA en %s.', $e );
	}

	public function test_changes_between_parents_are_listed_with_the_nif_masked(): void {
		$antes   = array( 'nome' => 'Ana', 'apelidos' => 'Souto Rei', 'nif' => '12345678Z', 'telefono' => '600111222', 'email' => 'Ana@Example.com' );
		$despois = array( 'nome' => 'Ana', 'apelidos' => 'Souto Rey', 'nif' => '87654321X', 'telefono' => '600111222', 'email' => 'ana@example.com' );
		$c = ANPA_Socios_Cambio_Email::cambios( $antes, $despois );
		$this->assertCount( 2, $c, 'same email in another case is not a change' );
		$this->assertSame( array( 'campo' => 'Apelidos', 'antes' => 'Souto Rei', 'despois' => 'Souto Rey' ), $c[0] );
		$this->assertSame( array( 'campo' => 'NIF/NIE', 'antes' => '*****678Z', 'despois' => '*****321X' ), $c[1] );
		$this->assertSame( array(), ANPA_Socios_Cambio_Email::cambios( $antes, $antes ) );
		$despois['email'] = 'outra@example.com';
		$c = ANPA_Socios_Cambio_Email::cambios( $antes, $despois );
		$this->assertSame( 'Correo electrónico', $c[2]['campo'] );
		$this->assertSame( '', ANPA_Socios_Cambio_Email::nif_oculto( '' ) );
	}

	public function test_code_guesses_are_counted_atomically_and_capped_per_address(): void {
		$v = $this->src( 'includes/class-anpa-socios-verificacion-rest.php' );
		$conta = strpos( $v, 'SET intentos = intentos + 1 WHERE id = %d AND usado = 0 AND intentos < 5' );
		$this->assertNotFalse( $conta );
		$this->assertLessThan( strpos( $v, 'ANPA_Socios_Codigo_Generator::verify( $codigo' ), $conta, 'counted before the comparison' );
		$this->assertStringContainsString( 'SET usado = 1 WHERE id = %d AND usado = 0', $v, 'single use' );
		$this->assertSame( 20, ANPA_Socios_Verificacion_REST_Limites::codigos_email_hora( $v ) );
		foreach ( array( 'includes/class-anpa-socios-verificacion-rest.php', 'includes/class-anpa-socios-rest.php', 'includes/class-anpa-socios-empresa-rest.php' ) as $f ) {
			$this->assertStringContainsString( 'limite_email_superado( $email )', $this->src( $f ), $f );
		}
		$p = $this->src( 'includes/class-anpa-socios-preflight-rest.php' );
		$this->assertStringContainsString( "'anpa_preflight_ip_' . md5( \$ip )", $p, 'a cap per IP whatever the address' );
	}

	public function test_duplicate_child_error_reveals_nothing_about_the_other_family(): void {
		$f    = $this->src( 'includes/class-anpa-socios-fillos-rest.php' );
		$from = (int) strpos( $f, 'function check_duplicate_fillo' );
		$body = substr( $f, $from, (int) strpos( $f, "\n\t}\n", $from ) - $from );
		$this->assertStringNotContainsString( 'socio_email', str_replace( 'ON s.email = f.socio_email', '', $body ) );
		$this->assertStringNotContainsString( 'socio_nome', $body );
		$this->assertStringNotContainsString( '***', $body );
	}
}

/** Reads the per-address cap from the source (the REST class needs WordPress to load). */
final class ANPA_Socios_Verificacion_REST_Limites {
	public static function codigos_email_hora( string $src ): int {
		return 1 === preg_match( '/const CODIGOS_EMAIL_HORA = (\d+);/', $src, $m ) ? (int) $m[1] : 0;
	}
}
