<?php
/**
 * 1.68.0: the enrolment states in one place, including the new
 * `pendente_aprobacion` (requested while the window was closed).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Matricula_Estado extends TestCase {

	public function test_the_six_states_and_their_groupings(): void {
		$this->assertSame( array( 'activo', 'lista_espera', 'oferta', 'baixa_solicitada', 'pendente_aprobacion', 'baixa' ), ANPA_Socios_Matricula_Estado::TODOS );
		$this->assertSame( array( 'activo', 'lista_espera', 'oferta', 'baixa_solicitada', 'pendente_aprobacion' ), ANPA_Socios_Matricula_Estado::VIXENTES );
		$this->assertSame( array( 'activo', 'lista_espera', 'oferta', 'baixa_solicitada' ), ANPA_Socios_Matricula_Estado::VIXENTES_LEGADO, 'the pre-1.68.0 list, for callers that must not see pending ones' );
		$this->assertTrue( ANPA_Socios_Matricula_Estado::valido( 'pendente_aprobacion' ) );
		$this->assertFalse( ANPA_Socios_Matricula_Estado::valido( 'pendente' ) );
	}

	public function test_sql_in_lists_are_ascii_and_quoted(): void {
		$this->assertSame( "'activo','lista_espera','oferta','baixa_solicitada','pendente_aprobacion'", ANPA_Socios_Matricula_Estado::sql_in( ANPA_Socios_Matricula_Estado::VIXENTES ) );
		$this->assertSame( 1, preg_match( '/^[\x20-\x7e]+$/', ANPA_Socios_Matricula_Estado::sql_in( ANPA_Socios_Matricula_Estado::TODOS ) ), 'SQL must stay ASCII (1.56.3 trap)' );
	}

	public function test_approval_destination_depends_on_group_state_and_capacity(): void {
		$this->assertSame( 'activo', ANPA_Socios_Matricula_Estado::destino_aprobacion( 'aberto', 9, 10 ) );
		$this->assertSame( 'lista_espera', ANPA_Socios_Matricula_Estado::destino_aprobacion( 'aberto', 10, 10 ) );
		$this->assertSame( 'lista_espera', ANPA_Socios_Matricula_Estado::destino_aprobacion( 'pechado', 0, 10 ) );
		$this->assertSame( 'lista_espera', ANPA_Socios_Matricula_Estado::destino_aprobacion( 'aberto', 0, 0 ), 'a zero maximum never grants a place' );
	}

	public function test_only_pending_requests_can_be_withdrawn_by_the_family(): void {
		$this->assertTrue( ANPA_Socios_Matricula_Estado::pode_retirar( 'pendente_aprobacion' ) );
		foreach ( array( 'activo', 'lista_espera', 'oferta', 'baixa_solicitada', 'baixa' ) as $e ) {
			$this->assertFalse( ANPA_Socios_Matricula_Estado::pode_retirar( $e ), $e );
		}
	}

	public function test_labels_in_galician(): void {
		$this->assertSame( 'Pendente de aprobación', ANPA_Socios_Matricula_Estado::etiqueta( 'pendente_aprobacion' ) );
		$this->assertSame( 'Lista de espera', ANPA_Socios_Matricula_Estado::etiqueta( 'lista_espera' ) );
		$this->assertSame( 'descoñecido', ANPA_Socios_Matricula_Estado::etiqueta( 'descoñecido' ) );
	}
}
