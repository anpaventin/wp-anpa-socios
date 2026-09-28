<?php
/**
 * 1.71.0: public offer — activity dates (Axustes → Xeral), group state shown
 * to families («Creado», «non acadou o mínimo») and the mid-course notices
 * to the company and the canteen. Pure helpers.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Oferta_Publica extends TestCase {

	public function test_dates_are_validated_as_calendar_days(): void {
		$this->assertSame( '2026-10-01', ANPA_Socios_Oferta_Publica::data_valida( ' 2026-10-01 ' ) );
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::data_valida( '2026-02-30' ) );
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::data_valida( '01/10/2026' ) );
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::data_valida( '' ) );
	}

	public function test_long_date_in_galician(): void {
		$this->assertSame( '1 de outubro de 2026', ANPA_Socios_Oferta_Publica::data_longa( '2026-10-01' ) );
		$this->assertSame( '15 de xuño', ANPA_Socios_Oferta_Publica::data_longa( '2027-06-15', false ) );
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::data_longa( 'x' ) );
	}

	public function test_banner_and_card_texts_hide_missing_dates(): void {
		$this->assertSame( 'As actividades extraescolares comezan o 1 de outubro de 2026 e rematan o 15 de xuño de 2027.', ANPA_Socios_Oferta_Publica::aviso_datas( '2026-10-01', '2027-06-15' ) );
		$this->assertSame( 'As actividades extraescolares comezan o 1 de outubro de 2026.', ANPA_Socios_Oferta_Publica::aviso_datas( '2026-10-01', '' ) );
		$this->assertSame( 'As actividades extraescolares rematan o 15 de xuño de 2027.', ANPA_Socios_Oferta_Publica::aviso_datas( '', '2027-06-15' ) );
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::aviso_datas( '', '' ) );

		$this->assertSame( 'Do 1 de outubro ao 15 de xuño', ANPA_Socios_Oferta_Publica::texto_curto( '2026-10-01', '2027-06-15' ) );
		$this->assertSame( 'Comeza o 1 de outubro', ANPA_Socios_Oferta_Publica::texto_curto( '2026-10-01', '' ) );
		$this->assertSame( 'Remata o 15 de xuño', ANPA_Socios_Oferta_Publica::texto_curto( '', '2027-06-15' ) );
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::texto_curto( '', '' ) );
	}

	public function test_public_state_of_a_group(): void {
		$this->assertSame( 'aberto', ANPA_Socios_Oferta_Publica::estado_grupo( 'aberto', null, 0, 8 ) );
		// Closed, notified «grupo creado» and with pupils → Creado (even if a pupil left mid-course).
		$this->assertSame( 'creado', ANPA_Socios_Oferta_Publica::estado_grupo( 'pechado', '2026-09-25 10:00:00', 9, 8 ) );
		$this->assertSame( 'creado', ANPA_Socios_Oferta_Publica::estado_grupo( 'pechado', '2026-09-25 10:00:00', 7, 8 ) );
		// Notified but nobody left → it does not run.
		$this->assertSame( 'non_acadado', ANPA_Socios_Oferta_Publica::estado_grupo( 'pechado', '2026-09-25 10:00:00', 0, 8 ) );
		// Closed, not notified, below the minimum → informative list.
		$this->assertSame( 'non_acadado', ANPA_Socios_Oferta_Publica::estado_grupo( 'pechado', null, 3, 8 ) );
		$this->assertSame( 'non_acadado', ANPA_Socios_Oferta_Publica::estado_grupo( 'pechado', '', 0, 8 ) );
		// Closed, reached the minimum but not notified yet → not shown (the junta still has to confirm it).
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::estado_grupo( 'pechado', null, 8, 8 ) );
		// «Pechar por non acadar o mínimo» disables the group and gives every enrolment baixa.
		$this->assertSame( 'non_acadado', ANPA_Socios_Oferta_Publica::estado_grupo( 'deshabilitado', null, 0, 8, 5 ) );
		// A disabled group nobody ever signed up for (not offered) is never shown.
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::estado_grupo( 'deshabilitado', null, 0, 8 ) );
		$this->assertSame( '', ANPA_Socios_Oferta_Publica::estado_grupo( 'deshabilitado', null, 0, 8, 0 ) );
	}

	public function test_groups_below_minimum_are_listed_by_activity_names_only(): void {
		$lista = ANPA_Socios_Oferta_Publica::non_acadados( array(
			array( 'actividade' => 'Xadrez', 'grupo' => 'Martes' ),
			array( 'actividade' => 'Robótica', 'grupo' => 'Luns' ),
			array( 'actividade' => 'Xadrez', 'grupo' => 'Xoves' ),
			array( 'actividade' => 'Xadrez', 'grupo' => 'Martes' ),
		) );
		$this->assertSame(
			array(
				array( 'actividade' => 'Robótica', 'grupos' => array( 'Luns' ) ),
				array( 'actividade' => 'Xadrez', 'grupos' => array( 'Martes', 'Xoves' ) ),
			),
			$lista
		);
	}

	public function test_mid_course_notice_only_with_active_course_and_closed_window(): void {
		$this->assertTrue( ANPA_Socios_Aviso_Matricula::debe_avisar( array( 'estado_curso' => 'activo', 'abertas' => false ) ) );
		$this->assertFalse( ANPA_Socios_Aviso_Matricula::debe_avisar( array( 'estado_curso' => 'activo', 'abertas' => true ) ), 'window open = mass moments, no per-pupil notice' );
		$this->assertFalse( ANPA_Socios_Aviso_Matricula::debe_avisar( array( 'estado_curso' => 'pechado', 'abertas' => false ) ) );
		$this->assertFalse( ANPA_Socios_Aviso_Matricula::debe_avisar( array() ) );
	}

	public function test_recipients_are_company_and_canteen_deduplicated(): void {
		$this->assertSame( array( 'empresa@example.com', 'comedor@example.com' ), ANPA_Socios_Aviso_Matricula::destinatarios( ' Empresa@Example.com ', 'comedor@example.com' ) );
		$this->assertSame( array( 'comedor@example.com' ), ANPA_Socios_Aviso_Matricula::destinatarios( '', 'comedor@example.com' ) );
		$this->assertSame( array( 'x@example.com' ), ANPA_Socios_Aviso_Matricula::destinatarios( 'x@example.com', 'X@example.com' ) );
		$this->assertSame( array(), ANPA_Socios_Aviso_Matricula::destinatarios( 'non-e-correo', '' ) );
	}

	public function test_template_context_from_a_listing_row(): void {
		$ctx = ANPA_Socios_Aviso_Matricula::contexto( array(
			'nome' => 'Uxía', 'apelidos' => 'Pérez Souto', 'curso' => '3º', 'aula' => 'A',
			'actividade_nome' => 'Xadrez', 'grupo_nome' => 'Luns', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'luns',
			'empresa_nome' => 'Empresa Exemplo', 'trimestre' => '2',
			'autorizacion_comedor' => 'si', 'tarde_transicion' => 'comedor', 'tardes_divertidas_continua' => '1', 'recollida_autorizada' => '0',
			'proxenitor1_nome' => 'Ana Souto', 'proxenitor1_telefono' => '600000001', 'proxenitor1_email' => 'ana@example.com',
			'proxenitor2_nome' => '', 'proxenitor2_telefono' => '', 'proxenitor2_email' => '',
		) );
		$this->assertSame( 'Uxía Pérez Souto', $ctx['alumno'] );
		$this->assertSame( '3º A', $ctx['curso'] );
		$this->assertSame( 'Xadrez', $ctx['actividade'] );
		$this->assertStringContainsString( 'Luns', $ctx['grupo'] );
		$this->assertStringContainsString( '16:00-17:00', $ctx['grupo'] );
		$this->assertSame( 'Empresa Exemplo', $ctx['empresa'] );
		$this->assertSame( '2', $ctx['trimestre'] );
		$this->assertSame( 'Ana Souto · 600000001 · ana@example.com', $ctx['proxenitor1'] );
		$this->assertSame( '—', $ctx['proxenitor2'] );
		$this->assertSame( 'Comedor: autoriza ao persoal · Tras o comedor pasa á actividade · Continúa en Tardes divertidas', $ctx['opcions'] );
	}

	public function test_empty_options_read_as_none(): void {
		$ctx = ANPA_Socios_Aviso_Matricula::contexto( array( 'autorizacion_comedor' => 'na', 'tarde_transicion' => 'na' ) );
		$this->assertSame( '—', $ctx['opcions'] );
	}
}
