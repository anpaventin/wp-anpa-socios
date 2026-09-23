<?php
/**
 * 1.68.0: the eleven templates behind the course-cycle notices (Xestión →
 * Matrículas, Grupos e horarios, pending enrolments) and the mass-mail path of
 * ANPA_Socios_Email (junta in To, families in Bcc batches).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Extraescolares_Control_Emails extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private const NOVAS = array(
		'prazo_matriculas', 'fin_curso', 'matriculas_abertas', 'matriculas_pechadas',
		'matricula_pendente', 'matricula_aprobada_praza', 'matricula_aprobada_espera', 'matricula_rexeitada',
		'grupo_comezo_trimestre', 'grupo_comezo_espera', 'grupo_pechado_minimo',
	);

	public function test_eleven_new_templates_exist_in_galician_with_the_association_and_a_contact_email(): void {
		$defaults = ANPA_Socios_Email_Template_Store::get_all_defaults();
		$this->assertCount( 28, $defaults );
		foreach ( self::NOVAS as $id ) {
			$this->assertArrayHasKey( $id, $defaults, $id );
			$this->assertContains( 'association_name', ANPA_Socios_Email_Template_Store::get_variables( $id )['subject'], "$id subject names the association" );
			$this->assertContains( 'contact_email', ANPA_Socios_Email_Template_Store::get_variables( $id )['html'], "$id html gives a contact" );
			$this->assertContains( 'contact_email', ANPA_Socios_Email_Template_Store::get_variables( $id )['text'], "$id text gives a contact" );
			// Placeholders match the variable list (sprintf contract of the renderer).
			foreach ( array( 'subject', 'html', 'text' ) as $field ) {
				$this->assertSame( count( ANPA_Socios_Email_Template_Store::get_variables( $id )[ $field ] ), substr_count( $defaults[ $id ][ $field ], '%s' ), "$id $field placeholders" );
			}
		}
	}

	public function test_variable_order_of_the_mass_templates(): void {
		$v = static function ( string $id ): array { return ANPA_Socios_Email_Template_Store::get_variables( $id )['html']; };
		$this->assertSame( array( 'data_peche', 'data_inicio_actividades', 'extraescolares_url', 'extraescolares_url', 'login_url', 'login_url', 'contact_email' ), $v( 'prazo_matriculas' ) );
		$this->assertSame( array( 'curso_escolar', 'association_name', 'contact_email' ), $v( 'fin_curso' ) );
		$this->assertSame( array( 'trimestre', 'extraescolares_url', 'extraescolares_url', 'login_url', 'login_url', 'contact_email' ), $v( 'matriculas_abertas' ) );
		$this->assertSame( array( 'trimestre', 'contact_email' ), $v( 'matriculas_pechadas' ) );
		$this->assertSame( array( 'alumno', 'actividade', 'grupo', 'contact_email' ), $v( 'matricula_pendente' ) );
		$this->assertSame( array( 'alumno', 'actividade', 'grupo', 'login_url', 'login_url', 'contact_email' ), $v( 'matricula_aprobada_praza' ) );
		$this->assertSame( array( 'alumno', 'actividade', 'grupo', 'posicion', 'contact_email' ), $v( 'matricula_aprobada_espera' ) );
		$this->assertSame( array( 'alumno', 'actividade', 'contact_email' ), $v( 'matricula_rexeitada' ) );
		$this->assertSame( array( 'grupo', 'actividade', 'trimestre', 'horario', 'contact_email' ), $v( 'grupo_comezo_trimestre' ) );
		$this->assertSame( array( 'grupo', 'actividade', 'trimestre', 'contact_email' ), $v( 'grupo_comezo_espera' ) );
		$this->assertSame( array( 'grupo', 'actividade', 'contact_email' ), $v( 'grupo_pechado_minimo' ) );
	}

	public function test_rendered_pending_and_rejected_emails_say_what_happens_next(): void {
		$ctx = array( 'association_name' => 'ANPA Proba', 'contact_email' => 'directiva@example.org', 'alumno' => 'Uxía Pérez', 'actividade' => 'Xadrez', 'grupo' => 'Grupo A (luns 16:00-17:00)' );
		$out = ANPA_Socios_Email_Template_Renderer::render( 'matricula_pendente', $ctx );
		$this->assertSame( 'Solicitude de matrícula recibida: Uxía Pérez en Xadrez — ANPA Proba', $out['subject'] );
		$this->assertStringContainsString( 'pendente de aprobación', $out['html'] );
		$this->assertStringContainsString( 'retirala dende a área', $out['html'] );
		$this->assertStringContainsString( 'directiva@example.org', $out['html'] );

		$out = ANPA_Socios_Email_Template_Renderer::render( 'matricula_rexeitada', $ctx );
		$this->assertStringContainsString( 'non puido aceptar', $out['html'] );
		$this->assertStringContainsString( 'ponte en contacto coa directiva en directiva@example.org', $out['html'] );

		$out = ANPA_Socios_Email_Template_Renderer::render( 'matricula_aprobada_espera', $ctx + array( 'posicion' => '4' ) );
		$this->assertStringContainsString( 'lista de espera</strong> (posición 4)', $out['html'] );

		$out = ANPA_Socios_Email_Template_Renderer::render( 'prazo_matriculas', $ctx + array( 'data_peche' => '30/09/2026', 'data_inicio_actividades' => '01/10/2026', 'login_url' => 'https://example.org/area/', 'extraescolares_url' => 'https://example.org/extra/' ) );
		$this->assertSame( 'Últimos días para revisar as inscricións nas extraescolares: o prazo remata o 30/09/2026 — ANPA Proba', $out['subject'] );
		$this->assertStringContainsString( 'remata o <strong>30/09/2026</strong>', $out['html'] );
		$this->assertStringContainsString( 'comezan o <strong>01/10/2026</strong>', $out['html'] );
		// 1.68.1: the three points of the junta's real reminder (viability, waiting lists, review).
		foreach ( array( 'número mínimo de participantes', 'Inscricións en grupos activos:', 'Listas de espera:', 'Revisión dos datos:', 'lista definitiva ás empresas' ) as $needle ) {
			$this->assertStringContainsString( $needle, $out['html'] );
			$this->assertStringContainsString( $needle, $out['text'] );
		}
		$this->assertStringContainsString( '<a href="https://example.org/extra/">https://example.org/extra/</a>', $out['html'] );

		$out = ANPA_Socios_Email_Template_Renderer::render( 'grupo_pechado_minimo', $ctx );
		$this->assertStringContainsString( 'non acadou o mínimo', $out['html'] );
		$this->assertStringContainsString( 'sen ningún cobro', $out['html'] );
	}

	// ── Mass mail path ──────────────────────────────────────────────────

	public function test_mass_mail_puts_the_junta_in_to_and_the_families_in_bcc_batches(): void {
		$email = $this->src( 'includes/class-anpa-socios-email.php' );
		$start = strpos( $email, 'public static function enviar_masivo' );
		$this->assertNotFalse( $start );
		$body = substr( $email, $start, strpos( $email, 'public static function enviar_matricula_pendente', $start ) - $start );
		$this->assertStringContainsString( 'ANPA_Socios_Envio_Masivo::lotes( $emails )', $body );
		$this->assertStringContainsString( '$to         = self::junta_email();', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Envio_Masivo::cabeceira_bcc( $lote )', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Envio_Masivo::resumo(', $body );
		// Rendered once, outside the loop.
		$this->assertSame( 1, substr_count( $body, 'ANPA_Socios_Email_Template_Renderer::render(' ) );
		// send_from_master accepts the extra headers and merges them after From/Reply-To.
		$this->assertStringContainsString( 'private static function send_from_master( string $to, string $subject, string $body, array $extra_headers = array() ): bool', $email );
		$this->assertStringContainsString( 'array_merge( self::notice_headers(), $extra_headers )', $email );
	}

	public function test_family_facing_methods_exist_for_the_pending_flow(): void {
		$email = $this->src( 'includes/class-anpa-socios-email.php' );
		foreach ( array( 'enviar_matricula_pendente', 'enviar_matricula_aprobada_praza', 'enviar_matricula_aprobada_espera', 'enviar_matricula_rexeitada', 'links_context' ) as $fn ) {
			$this->assertStringContainsString( "public static function $fn(", $email, $fn );
		}
		// inicio_curso keeps working through the shared links context.
		$this->assertStringContainsString( "return self::send_template( \$to, 'inicio_curso', self::links_context() );", $email );
	}

	public function test_new_lib_files_are_wired_in_plugin_and_bootstrap(): void {
		foreach ( array( 'anpa-socios.php', 'tests/bootstrap.php' ) as $rel ) {
			$src = $this->src( $rel );
			foreach ( array( 'class-anpa-socios-trimestre-combo.php', 'class-anpa-socios-matricula-estado.php', 'class-anpa-socios-envio-masivo.php' ) as $file ) {
				$this->assertStringContainsString( $file, $src, "$rel requires $file" );
			}
		}
	}
}
