<?php
/**
 * 1.62.0: templated emails to the family when the junta confirms or rejects a
 * baixa request (member or activity), the trimester rule for activity baixas,
 * the start-of-year email to the junta's inbox, and the signature on templated
 * emails.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Emails_Baixas_Inicio_Curso extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	private function datas(): array {
		return array( 'inicio' => '2026-09-01', 't1' => '2026-12-22', 't2' => '2027-03-19', 'peche' => '2027-06-20' );
	}

	private function trimestres( string $e1, string $e2 = 'pendente', string $e3 = 'pendente' ): array {
		return array(
			1 => array( 'estado' => $e1, 'ventana_estado' => 'aberta', 'presente' => true ),
			2 => array( 'estado' => $e2, 'ventana_estado' => 'pechada', 'presente' => true ),
			3 => array( 'estado' => $e3, 'ventana_estado' => 'pechada', 'presente' => true ),
		);
	}

	// ── Templates ────────────────────────────────────────────────────────

	public function test_five_new_templates_exist_with_galician_text_and_the_right_variables(): void {
		$defaults = ANPA_Socios_Email_Template_Store::get_all_defaults();
		foreach ( array( 'baixa_socio_confirmada', 'baixa_socio_rexeitada', 'baixa_extraescolar_confirmada', 'baixa_extraescolar_rexeitada', 'inicio_curso' ) as $id ) {
			$this->assertArrayHasKey( $id, $defaults, $id );
			$this->assertStringContainsString( '%s', $defaults[ $id ]['subject'], "$id subject has the association placeholder" );
		}
		$this->assertStringContainsString( 'confirmou a túa baixa como socio/a', $defaults['baixa_socio_confirmada']['html'] );
		$this->assertStringContainsString( 'non a aceptou: segues sendo socio/a activo/a', $defaults['baixa_socio_rexeitada']['html'] );
		$this->assertStringContainsString( 'ponte en contacto coa directiva en %s para solucionalo', $defaults['baixa_socio_rexeitada']['html'] );
		$this->assertStringContainsString( 'ponte en contacto coa directiva en %s para solucionalo', $defaults['baixa_extraescolar_rexeitada']['html'] );
		$this->assertSame( array( 'association_name', 'alumno', 'actividade', 'efectos', 'contact_email' ), ANPA_Socios_Email_Template_Store::get_variables( 'baixa_extraescolar_confirmada' )['html'] );
		$this->assertSame( array( 'nome', 'association_name', 'contact_email' ), ANPA_Socios_Email_Template_Store::get_variables( 'baixa_socio_confirmada' )['html'] );

		$inicio = $defaults['inicio_curso']['html'];
		foreach ( array( 'Iniciar sesión como socio/a:', 'Darse de alta:', 'Modificar datos:', 'Actividades extraescolares:', 'Área de socios:', 'Web da asociación:' ) as $needle ) {
			$this->assertStringContainsString( $needle, $inicio );
		}
		$this->assertSame( array( 'association_name', 'login_url', 'login_url', 'web_url', 'web_url', 'contact_email' ), ANPA_Socios_Email_Template_Store::get_variables( 'inicio_curso' )['html'] );
	}

	public function test_rendered_activity_baixa_email_carries_the_effects_sentence_and_a_readable_contact_email(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render( 'baixa_extraescolar_confirmada', array(
			'association_name' => 'ANPA Ventín',
			'alumno'           => 'Uxía Pérez',
			'actividade'       => 'Xadrez',
			'efectos'          => 'Frase dos efectos.',
			'contact_email'    => 'directiva@example.org',
		) );
		$this->assertSame( 'Baixa da actividade Xadrez confirmada — ANPA Ventín', $out['subject'] );
		$this->assertStringContainsString( '<strong>Uxía Pérez</strong>', $out['html'] );
		$this->assertStringContainsString( '<p>Frase dos efectos.</p>', $out['html'] );
		$this->assertStringContainsString( 'directiva@example.org', $out['html'] );
		$this->assertStringNotContainsString( 'http://directiva@example.org', $out['html'], 'emails must not be run through esc_url()' );
		$this->assertStringContainsString( 'Frase dos efectos.', $out['text'] );
	}

	public function test_urls_are_still_escaped_as_urls(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render( 'inicio_curso', array(
			'association_name' => 'ANPA',
			'login_url'        => 'https://example.org/socios/area-persoal/',
			'web_url'          => 'https://example.org/',
			'contact_email'    => 'info@example.org',
		) );
		$this->assertStringContainsString( '<a href="https://example.org/socios/area-persoal/">https://example.org/socios/area-persoal/</a>', $out['html'] );
		$this->assertStringContainsString( '<a href="https://example.org/">https://example.org/</a>', $out['html'] );
		$this->assertStringContainsString( 'info@example.org', $out['html'] );
		$this->assertSame( 'Comeza o curso — ANPA', $out['subject'] );
	}

	// ── Trimester rule ───────────────────────────────────────────────────

	public function test_started_trimester_means_effective_at_its_end_with_the_date(): void {
		$gate = ANPA_Socios_Matricula_Gate::avaliar( array( 'curso_escolar' => '2026/2027', 'estado' => 'activo' ) + $this->datas_row(), $this->trimestres( 'activo' ), '2026-10-05' );
		$r    = ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( $gate, $this->trimestres( 'activo' ), $this->datas() );
		$this->assertSame( ANPA_Socios_Baixa_Extraescolar_Efectos::FIN_TRIMESTRE, $r['efecto'] );
		$this->assertSame( 1, $r['trimestre'] );
		$this->assertSame( '2026-12-22', $r['data_fin'] );
		$this->assertStringContainsString( 'Como o 1º trimestre xa comezou', $r['texto'] );
		$this->assertStringContainsString( '(o 22/12/2026)', $r['texto'] );
		$this->assertStringContainsString( 'non se cobrará o trimestre seguinte', $r['texto'] );

		// Second trimester started, evaluated in February.
		$gate = ANPA_Socios_Matricula_Gate::avaliar( array( 'curso_escolar' => '2026/2027', 'estado' => 'activo' ) + $this->datas_row(), $this->trimestres( 'pechado', 'activo' ), '2027-02-01' );
		$r    = ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( $gate, $this->trimestres( 'pechado', 'activo' ), $this->datas() );
		$this->assertSame( 2, $r['trimestre'] );
		$this->assertSame( '2027-03-19', $r['data_fin'] );
	}

	public function test_pre_enrolment_means_immediate_and_free_of_charge(): void {
		// Trimester configured but not started yet (groups still open, enrolments at the start of the course).
		$gate = ANPA_Socios_Matricula_Gate::avaliar( array( 'curso_escolar' => '2026/2027', 'estado' => 'activo' ) + $this->datas_row(), $this->trimestres( 'pendente' ), '2026-09-10' );
		$r    = ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( $gate, $this->trimestres( 'pendente' ), $this->datas() );
		$this->assertSame( ANPA_Socios_Baixa_Extraescolar_Efectos::INMEDIATA, $r['efecto'] );
		$this->assertSame( '', $r['data_fin'] );
		$this->assertStringContainsString( 'período de inscrición', $r['texto'] );
		$this->assertStringContainsString( 'non se pasará ningún cobro', $r['texto'] );

		// Nothing configured at all: fail towards "no charge" rather than inventing a date.
		$r = ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( ANPA_Socios_Matricula_Gate::avaliar( null, array() ), array(), null );
		$this->assertSame( ANPA_Socios_Baixa_Extraescolar_Efectos::INMEDIATA, $r['efecto'] );
	}

	public function test_started_trimester_without_calendar_omits_the_date(): void {
		$gate = array( 'trimestre' => 3, 'abertas' => false, 'motivo' => 'ventana_pechada' );
		$r    = ANPA_Socios_Baixa_Extraescolar_Efectos::avaliar( $gate, $this->trimestres( 'pechado', 'pechado', 'activo' ), null );
		$this->assertSame( ANPA_Socios_Baixa_Extraescolar_Efectos::FIN_TRIMESTRE, $r['efecto'] );
		$this->assertStringContainsString( 'Como o 3º trimestre xa comezou', $r['texto'] );
		$this->assertStringNotContainsString( '(o ', $r['texto'] );
	}

	private function datas_row(): array {
		return array( 'data_inicio' => '2026-09-01', 't1_peche_operativo' => '2026-12-22', 't2_peche_operativo' => '2027-03-19', 'data_peche' => '2027-06-20' );
	}

	// ── Signature ────────────────────────────────────────────────────────

	public function test_wrap_html_gives_fragments_a_document_and_keeps_full_documents(): void {
		$frag = ANPA_Socios_Email::wrap_html( '<p>Ola</p>' );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $frag );
		$this->assertStringContainsString( '<body style="font-family: sans-serif;', $frag );
		$this->assertStringContainsString( '<p>Ola</p>', $frag );
		$this->assertStringEndsWith( '</body></html>', $frag );

		$full = '<!DOCTYPE html><html><body><p>X</p></body></html>';
		$this->assertSame( 1, substr_count( ANPA_Socios_Email::wrap_html( $full ), '<body' ), 'a full document is not wrapped twice' );
	}

	// ── Wiring ───────────────────────────────────────────────────────────

	public function test_handlers_send_the_emails_and_report_it(): void {
		$socios = $this->src( 'includes/class-anpa-socios-admin-socios-handler.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_baixa_socio_confirmada( $email, $nome )', $socios );
		$this->assertStringContainsString( "\$data['correo_enviado'] = \$correo_enviado;", $socios );

		$baixas = $this->src( 'includes/class-anpa-socios-admin-baixas-handler.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_baixa_socio_rexeitada( $email, $nome )', $baixas );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_baixa_extraescolar_rexeitada(', $baixas );
		$this->assertStringContainsString( 'public static function detalle_matricula( int $id ): ?array', $baixas );
		// Pure ASCII SQL (1.56.3 trap).
		preg_match_all( '/"SELECT[^"]+"/s', $baixas, $m );
		foreach ( $m[0] as $sql ) {
			$this->assertSame( 1, preg_match( '/^[\x00-\x7F]*$/', $sql ), 'SQL must stay ASCII' );
		}

		$grupos = $this->src( 'includes/class-anpa-socios-admin-grupos-handler.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Baixas_Handler::detalle_matricula( $id )', $grupos );
		$this->assertStringContainsString( 'ANPA_Socios_Baixa_Extraescolar_Efectos::texto( $gate, $tris, $datas )', $grupos );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_baixa_extraescolar_confirmada(', $grupos );
		$this->assertStringContainsString( "'correo_enviado' => \$correo_enviado, 'efectos' => \$efectos", $grupos );

		$email = $this->src( 'includes/class-anpa-socios-email.php' );
		foreach ( array( 'enviar_baixa_socio_confirmada', 'enviar_baixa_socio_rexeitada', 'enviar_baixa_extraescolar_confirmada', 'enviar_baixa_extraescolar_rexeitada', 'enviar_inicio_curso' ) as $fn ) {
			$this->assertStringContainsString( "public static function {$fn}(", $email );
		}
		$this->assertStringContainsString( '$body    = self::wrap_html( $body );', $email, 'send_from_master signs every email' );

		$this->assertStringContainsString( "includes/lib/class-anpa-socios-baixa-extraescolar-efectos.php';", $this->src( 'anpa-socios.php' ) );
	}

	public function test_inicio_curso_route_and_button(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-contactos-google-handler.php' );
		$this->assertStringContainsString( "'/contactos-google/inicio-curso'", $h );
		$this->assertStringContainsString( 'ANPA_Socios_Email::enviar_inicio_curso( $to )', $h );
		$this->assertStringContainsString( '$to      = ANPA_Socios_Config::master_email();', $h, 'one email to the junta, never to every family' );
		$this->assertStringContainsString( "'conta_xunta'       => ANPA_Socios_Config::master_email(),", $h );

		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "anpaAdminFetch('contactos-google/inicio-curso', { method: 'POST' })", $js );
		$this->assertStringContainsString( "'Enviar o correo de inicio de curso á conta da xunta'", $js );
		$this->assertStringContainsString( "admin.php?page=anpa-socios-templates&edit=inicio_curso", $js );
		$this->assertStringContainsString( "if (r && r.correo_enviado === true) { mail = ' Enviouse o correo á familia.'; }", $js );
		$this->assertStringNotContainsString( 'a familia non recibe correo automático', $js );

		$docs = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertStringContainsString( 'Correos automáticos ás familias nas baixas', $docs );
		$this->assertStringContainsString( 'Correo de inicio de curso:', $docs );
	}
}
