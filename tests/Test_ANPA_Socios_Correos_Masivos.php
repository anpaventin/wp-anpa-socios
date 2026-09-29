<?php
/**
 * 1.84.0: «Cambiar correo» in Xestión (the email follows everywhere), and the
 * «Correos masivos» section (catalogue + send log from the audit rows); Lista
 * Gmail shows altas/baixas first and no longer carries the start-of-year email.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Correos_Masivos extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	private function body( string $src, string $fn ): string {
		$i = strpos( $src, 'function ' . $fn . '(' );
		$this->assertNotFalse( $i, $fn );
		$j = strpos( $src, "\n\t}\n", (int) $i );
		return substr( $src, (int) $i, (int) $j - (int) $i );
	}

	public function test_log_rows_come_from_the_audit_tags(): void {
		$f = array( 'ANPA_Socios_Correos_Masivos', 'fila' );
		$r = $f( array( 'accion' => 'masivo', 'target_id' => 'prazo_matriculas:3/120/2', 'actor_email' => 'xunta@example.com', 'timestamp' => '2026-09-30 08:00:00' ) );
		$this->assertSame( 'Prazo de matrículas', $r['titulo'] );
		$this->assertSame( array( 3, 120, 2 ), array( $r['lotes'], $r['enviados'], $r['fallidos'] ) );
		$this->assertSame( 'xunta@example.com', $r['por'] );
		$this->assertSame( 'Grupo creado: lista de espera', $f( array( 'accion' => 'masivo', 'target_id' => 'grupo_espera:1/4/0' ) )['titulo'] );
		$this->assertSame( 'Inicio de curso (á conta da xunta)', $f( array( 'accion' => 'inicio_curso_enviado', 'target_id' => 'inicio_curso' ) )['titulo'] );
		$this->assertSame( 1, $f( array( 'accion' => 'inicio_curso_erro', 'target_id' => 'inicio_curso' ) )['fallidos'] );
		$this->assertNull( $f( array( 'accion' => 'masivo', 'target_id' => 'sen formato' ) ) );
		$this->assertNull( $f( array( 'accion' => 'update', 'target_id' => 'x:1/1/1' ) ) );
		$this->assertSame( 'plantilla_nova', ANPA_Socios_Correos_Masivos::titulo_tag( 'plantilla_nova' ), 'unknown tags are shown as they are' );
	}

	public function test_catalogue_sends_here_only_what_changes_nothing(): void {
		$onde = array();
		foreach ( ANPA_Socios_Correos_Masivos::catalogo() as $c ) {
			$onde[ $c['id'] ] = $c['onde'];
			$this->assertNotSame( '', $c['titulo'] );
			$this->assertNotEmpty( $c['plantillas'] );
		}
		$this->assertSame( 'aqui', $onde['inicio_curso_xunta'] );
		$this->assertSame( 'aqui', $onde['prazo_matriculas'] );
		foreach ( array( 'comezo_curso', 'ventas', 'fin_curso' ) as $id ) {
			$this->assertSame( 'matriculas', $onde[ $id ], $id . ' changes the course: sent from Matrículas' );
		}
		$this->assertSame( 'grupos-horarios', $onde['grupo_creado'] );
		$this->assertSame( 'grupos-horarios', $onde['grupo_minimo'] );
	}

	public function test_section_route_nav_and_js(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-correos-handler.php' );
		$this->assertStringContainsString( "'/correos-masivos'", $h );
		$this->assertStringContainsString( "'permission_master'", $h );
		$this->assertStringContainsString( "WHERE target_tipo = 'email' AND accion IN ({\$in}) ORDER BY id DESC LIMIT %d", $h );
		$this->assertStringContainsString( 'get_date_from_gmt(', $h, 'audit timestamps are UTC' );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Correos_Handler::register_routes();', $this->src( 'includes/class-anpa-socios-admin-rest.php' ) );
		$this->assertSame( 'Correos masivos', ANPA_Socios_Admin_Nav::management_sections()['operacions']['sections']['correos'] );
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "'correos': loadCorreos,", $js );
		$c = $this->body( $js, 'renderCorreos' );
		$this->assertStringContainsString( "anpaAdminFetch('contactos-google/inicio-curso', { method: 'POST' })", $c );
		$this->assertStringContainsString( "anpaAdminFetch('avisos/prazo-matriculas', { method: 'POST'", $c );
		$this->assertStringContainsString( 'Rexistro de envíos', $c );
	}

	public function test_lista_gmail_shows_the_differences_first_and_no_box_under_estado(): void {
		$g = $this->body( $this->src( 'assets/js/admin-management.js' ), 'renderListaGmail' );
		$this->assertLessThan( strpos( $g, "card.appendChild(el('h3', 'Estado'));" ), strpos( $g, "diffTable('Altas desde a última exportación'" ), 'altas/baixas above «Estado»' );
		$this->assertStringNotContainsString( 'textarea', $g, 'the box under «Baixas desde entón» is gone' );
		$this->assertStringContainsString( 'Xa as eliminei en Google: poñer as baixas a 0', $g, 'kept, under the baixas table' );
		$this->assertStringNotContainsString( "contactos-google/inicio-curso", $g, 'moved to «Correos masivos»' );
		$this->assertStringContainsString( "navigateTo('correos')", $g );
	}

	public function test_change_email_route_checks_and_follow_up(): void {
		$s = $this->src( 'includes/class-anpa-socios-admin-socios-handler.php' );
		$this->assertStringContainsString( "'/socio/(?P<email>[^/]+)/email'", $s );
		$b = $this->body( $s, 'cambiar_email' );
		$this->assertStringContainsString( 'is_protected_admin', $b );
		$this->assertStringContainsString( 'ANPA_Socios_Cambio_Email::validar(', $b );
		$this->assertStringContainsString( "START TRANSACTION", $b );
		$this->assertStringContainsString( 'ANPA_Socios_Cambio_Email::aplicar( $vello, $novo, ANPA_Socios_Familia::resolve_familia_id(', $b );
		$this->assertStringContainsString( "'email_substituido'", $b, 'the replaced address stays in the audit' );
		$this->assertStringContainsString( "'email_cambiado'", $b );
		$c = $this->src( 'includes/class-anpa-socios-cambio-email.php' );
		$v = $this->body( $c, 'validar' );
		foreach ( array( 'ANPA_Socios_Normalize::email(', 'strlen( $email ) > 100', 'is_protected_admin', 'conflito_para_socio', 'WHERE email = %s AND id <> %d' ) as $needle ) {
			$this->assertStringContainsString( $needle, $v, $needle );
		}
		$a = $this->body( $c, 'aplicar' );
		$this->assertStringContainsString( 'WHERE socio_email = %s AND ( familia_id = %d{$extra} )', $a, 'only the children of this family follow the new email' );
		$this->assertStringContainsString( 'tabela_sesions()', $a );
		$this->assertStringContainsString( 'anpa_codigos_verificacion', $a );
		$area = $this->src( 'includes/class-anpa-socios-area-rest.php' );
		$this->assertSame( 2, substr_count( $area, 'ANPA_Socios_Cambio_Email::aplicar(' ), 'own email and 2nd parent email in the area too' );
		$this->assertSame( 2, substr_count( $area, 'ANPA_Socios_Cambio_Email::validar(' ), 'same checks as Xestión' );
		$this->assertStringContainsString( "(int) \$fam['familia_id'] ) ) {", $area, 'own family only' );
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "'/email', { method: 'POST', body: { novo: novo } }", $js );
		$this->assertSame( 'Correo do socio/a cambiado', ANPA_Socios_Auditoria::etiqueta( 'email_cambiado', 'socio' ) );
	}
}
