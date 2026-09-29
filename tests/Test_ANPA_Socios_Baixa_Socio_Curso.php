<?php
/**
 * 1.79.0: a member baixa requested while the course is running is only effective
 * at the end of the course (as the alta said); the request email says so and how
 * to cancel it, and confirming it earlier in Xestión is an explicit exception.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Baixa_Socio_Curso extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_course_is_running_between_start_and_end(): void {
		$f = static function ( string $estado, string $ini, string $fin, string $hoxe ): bool { return ANPA_Socios_Baixa_Socio::curso_en_marcha( $estado, $ini, $fin, $hoxe ); };
		$this->assertTrue( $f( 'activo', '2026-09-01', '2027-06-20', '2026-09-29' ) );
		$this->assertTrue( $f( 'activo', '2026-09-01', '2027-06-20', '2026-09-01' ), 'first day counts' );
		$this->assertTrue( $f( 'activo', '2026-09-01', '2027-06-20', '2027-06-20' ), 'last day counts' );
		$this->assertFalse( $f( 'activo', '2026-09-01', '2027-06-20', '2026-08-31' ), 'before the course starts: immediate' );
		$this->assertFalse( $f( 'activo', '2026-09-01', '2027-06-20', '2027-06-21' ), 'after the end: immediate' );
		$this->assertTrue( $f( 'activo', '2026-09-01', '', '2027-09-01' ), 'no end date: running once started' );
		$this->assertFalse( $f( 'pendente', '2026-09-01', '2027-06-20', '2026-09-29' ), 'course not active' );
		$this->assertFalse( $f( 'activo', '', '2027-06-20', '2026-09-29' ), 'no start date: no rule' );
	}

	public function test_end_date_reads_in_galician(): void {
		$this->assertSame( '20 de xuño de 2027', ANPA_Socios_Baixa_Socio::remate_texto( '2027-06-20' ) );
		$this->assertSame( 'o remate do curso', ANPA_Socios_Baixa_Socio::remate_texto( '' ) );
	}

	public function test_request_email_template_explains_the_rule_and_how_to_cancel(): void {
		$d = ANPA_Socios_Email_Template_Store::get_all_defaults();
		$this->assertArrayHasKey( 'baixa_socio_solicitada_curso', $d );
		$html = $d['baixa_socio_solicitada_curso']['html'];
		$this->assertStringContainsString( 'unha vez iniciado o curso', $html );
		$this->assertStringContainsString( 'deixar de recibir correos', $html );
		$this->assertStringContainsString( '«Anular solicitude de baixa»', $html );
		$vars = ANPA_Socios_Email_Template_Store::get_variables( 'baixa_socio_solicitada_curso' );
		$this->assertSame( array( 'nome', 'association_name', 'data_remate', 'login_url', 'login_url', 'contact_email' ), $vars['html'] );
		foreach ( array( 'subject', 'html', 'text' ) as $k ) {
			$this->assertSame( count( $vars[ $k ] ), substr_count( $d['baixa_socio_solicitada_curso'][ $k ], '%s' ), $k );
		}
		$email = $this->src( 'includes/class-anpa-socios-email.php' );
		$this->assertStringContainsString( "'baixa_socio_solicitada_curso'", $email );
		$this->assertStringContainsString( 'ANPA_Socios_Admin_Baixas_Handler::curso_para_baixas()', $email );
	}

	public function test_confirming_while_running_needs_an_explicit_exception(): void {
		$h = $this->src( 'includes/class-anpa-socios-admin-socios-handler.php' );
		$this->assertStringContainsString( "'anpa_baixa_curso_en_marcha'", $h );
		$this->assertStringContainsString( "if ( \$curso['en_marcha'] && ! \$excepcion && ! \$pedida_antes ) {", $h );
		// A request from before this course started (never confirmed) goes through without an exception.
		$this->assertStringContainsString( 'baixa_solicitada_en FROM {$soc_t} WHERE email = %s', $h );
		$this->assertStringContainsString( "'inicio'       => \$inicio,", $this->src( 'includes/class-anpa-socios-admin-baixas-handler.php' ) );
		$this->assertStringContainsString( "\$excepcion ? 'baixa_confirm_excepcion' : 'baixa_confirm'", $h );
		$this->assertSame( 'Confirmada como excepción (curso en marcha)', ANPA_Socios_Auditoria::aprobacion( 'baixa_confirm_excepcion', 'socio' )['decision'] );
		$js = $this->src( 'assets/js/admin-management.js' );
		$this->assertStringContainsString( "e.code === 'anpa_baixa_curso_en_marcha'", $js );
		$this->assertStringContainsString( "act(path, 'Baixa de socio/a confirmada como excepción.', { excepcion: true });", $js );
		$b = $this->src( 'includes/class-anpa-socios-admin-baixas-handler.php' );
		$this->assertStringContainsString( "'curso_en_marcha' => \$curso_baixas['en_marcha'],", $b );
	}
}
