<?php
/**
 * 1.92.0: catalogue of the junta notices and their channels (Axustes →
 * Xeral → Avisos á xunta).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-avisos-xunta.php';

final class Test_ANPA_Socios_Avisos_Xunta extends TestCase {

	public function test_catalogue_lists_every_notice_in_order(): void {
		$this->assertSame(
			array( 'alta_socio', 'reactivacion_socio', 'baixa_socio', 'matricula_pendente', 'oferta_aceptada', 'baixa_extraescolar', 'baixa_empresa', 'resumo_semanal', 'inicio_curso', 'avisos_empresa', 'correos_masivos' ),
			array_keys( ANPA_Socios_Avisos_Xunta::catalogo() )
		);
		foreach ( ANPA_Socios_Avisos_Xunta::catalogo() as $id => $def ) {
			$this->assertArrayHasKey( $def['grupo'], ANPA_Socios_Avisos_Xunta::grupos(), $id );
			$this->assertNotSame( '', $def['titulo'], $id );
		}
	}

	public function test_approvals_go_by_email_and_telegram_by_default(): void {
		$canles = ANPA_Socios_Avisos_Xunta::canles( null );
		foreach ( ANPA_Socios_Avisos_Xunta::catalogo() as $id => $def ) {
			if ( ANPA_Socios_Avisos_Xunta::GRUPO_APROBACIONS === $def['grupo'] ) {
				$this->assertSame( array( 'correo' => true, 'telegram' => true ), $canles[ $id ], $id );
			}
		}
		// The two enrolment approvals that were never notified before 1.92.0.
		$this->assertTrue( ANPA_Socios_Avisos_Xunta::activo( $canles, 'matricula_pendente', 'correo' ) );
		$this->assertTrue( ANPA_Socios_Avisos_Xunta::activo( $canles, 'oferta_aceptada', 'telegram' ) );
	}

	public function test_old_mode_todo_keeps_telegram_for_the_mass_emails(): void {
		$avisos = ANPA_Socios_Avisos_Xunta::canles( array(), 'avisos' );
		$todo   = ANPA_Socios_Avisos_Xunta::canles( array(), 'todo' );
		foreach ( array( 'inicio_curso', 'avisos_empresa', 'correos_masivos' ) as $id ) {
			$this->assertSame( array( 'correo' => true, 'telegram' => false ), $avisos[ $id ], $id );
			$this->assertSame( array( 'correo' => true, 'telegram' => true ), $todo[ $id ], $id );
		}
		$this->assertTrue( $avisos['resumo_semanal']['telegram'] );
	}

	public function test_saved_channels_win_and_fixed_emails_stay_on(): void {
		$canles = ANPA_Socios_Avisos_Xunta::canles(
			array(
				'matricula_pendente' => array( 'correo' => false, 'telegram' => true ),
				'baixa_socio'        => array( 'correo' => true, 'telegram' => false ),
				'correos_masivos'    => array( 'correo' => false, 'telegram' => true ),
				'descoñecido'        => array( 'correo' => true ),
				'alta_socio'         => 'lixo',
			),
			'todo'
		);
		$this->assertSame( array( 'correo' => false, 'telegram' => true ), $canles['matricula_pendente'] );
		$this->assertSame( array( 'correo' => true, 'telegram' => false ), $canles['baixa_socio'] );
		$this->assertSame( array( 'correo' => true, 'telegram' => true ), $canles['correos_masivos'], 'the mass email cannot lose its email' );
		$this->assertSame( array( 'correo' => true, 'telegram' => true ), $canles['alta_socio'] );
		$this->assertArrayNotHasKey( 'descoñecido', $canles );
		$this->assertFalse( ANPA_Socios_Avisos_Xunta::activo( $canles, 'descoñecido', 'correo' ) );
	}

	public function test_form_writes_explicit_booleans_for_every_notice(): void {
		$out = ANPA_Socios_Avisos_Xunta::desde_formulario(
			array(
				'matricula_pendente' => array( 'telegram' => '1' ),
				'baixa_socio'        => array( 'correo' => '1', 'telegram' => '1' ),
				'inicio_curso'       => array(),
				'inxectado'          => array( 'correo' => '1' ),
			)
		);
		$this->assertSame( array_keys( ANPA_Socios_Avisos_Xunta::catalogo() ), array_keys( $out ) );
		$this->assertSame( array( 'correo' => false, 'telegram' => true ), $out['matricula_pendente'] );
		$this->assertSame( array( 'correo' => true, 'telegram' => true ), $out['baixa_socio'] );
		$this->assertSame( array( 'correo' => true, 'telegram' => false ), $out['inicio_curso'] );
		$this->assertSame( array( 'correo' => false, 'telegram' => false ), $out['alta_socio'], 'unticked = absent from the POST' );
		$this->assertSame( $out, ANPA_Socios_Avisos_Xunta::canles( $out, 'todo' ), 'a saved table is read back as is' );
		$this->assertSame( array( 'correo' => true, 'telegram' => false ), ANPA_Socios_Avisos_Xunta::desde_formulario( 'x' )['correos_masivos'] );
	}

	public function test_mass_email_notice_from_the_template(): void {
		foreach ( array( 'matricula_alta_aviso', 'matricula_baixa_aviso', 'matricula_cambio_grupo_aviso', 'matricula_baixa_programada_aviso', 'matricula_baixa_anulada_aviso' ) as $t ) {
			$this->assertSame( 'avisos_empresa', ANPA_Socios_Avisos_Xunta::id_masivo( $t ), $t );
		}
		foreach ( array( 'prazo_matriculas', 'inicio_curso', 'grupo_comezo_trimestre', 'matricula_pendente', 'fin_curso' ) as $t ) {
			$this->assertSame( 'correos_masivos', ANPA_Socios_Avisos_Xunta::id_masivo( $t ), $t );
		}
	}

	public function test_junta_templates_exist_with_their_variables(): void {
		$vars = array( 'alumno', 'actividade', 'grupo', 'email_socio', 'xestion_url' );
		foreach ( array( 'matricula_pendente_xunta', 'oferta_aceptada_xunta' ) as $id ) {
			$v = ANPA_Socios_Email_Template_Store::get_variables( $id );
			$this->assertSame( $vars, $v['html'], $id );
			$this->assertSame( $vars, $v['text'], $id );
			$this->assertSame( array( 'alumno', 'actividade', 'association_name' ), $v['subject'], $id );
		}
	}
}
