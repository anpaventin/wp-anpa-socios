<?php
/**
 * Pure-domain tests for the fase35 communications queue: state machines,
 * backoff policy, recipient normalization/dedup, and batch planner.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Cola_Dominio extends TestCase {

	// ── Campaign state machine ──────────────────────────────────────────

	public function test_campana_transitions(): void {
		$this->assertTrue( ANPA_Socios_Campana_Estado::pode_transicionar( 'pendente', 'procesando' ) );
		$this->assertTrue( ANPA_Socios_Campana_Estado::pode_transicionar( 'procesando', 'pausada' ) );
		$this->assertTrue( ANPA_Socios_Campana_Estado::pode_transicionar( 'pausada', 'procesando' ) );
		$this->assertTrue( ANPA_Socios_Campana_Estado::pode_transicionar( 'procesando', 'rematada' ) );
		$this->assertTrue( ANPA_Socios_Campana_Estado::pode_transicionar( 'pendente', 'cancelada' ) );
		$this->assertFalse( ANPA_Socios_Campana_Estado::pode_transicionar( 'rematada', 'procesando' ) );
		$this->assertFalse( ANPA_Socios_Campana_Estado::pode_transicionar( 'cancelada', 'procesando' ) );
		$this->assertFalse( ANPA_Socios_Campana_Estado::pode_transicionar( 'x', 'procesando' ) );
		$this->assertTrue( ANPA_Socios_Campana_Estado::terminal( 'rematada' ) );
		$this->assertTrue( ANPA_Socios_Campana_Estado::terminal( 'cancelada' ) );
		$this->assertFalse( ANPA_Socios_Campana_Estado::terminal( 'procesando' ) );
	}

	// ── Recipient state machine ─────────────────────────────────────────

	public function test_comunicacion_transitions_and_helpers(): void {
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::pode_transicionar( 'pendente', 'procesando' ) );
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::pode_transicionar( 'procesando', 'aceptado' ) );
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::pode_transicionar( 'procesando', 'fallido' ) );
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::pode_transicionar( 'procesando', 'pendente' ) ); // orphan recovery
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::pode_transicionar( 'fallido', 'pendente' ) );
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::pode_transicionar( 'fallido', 'fallido_definitivo' ) );
		$this->assertFalse( ANPA_Socios_Comunicacion_Estado::pode_transicionar( 'aceptado', 'pendente' ) );
		$this->assertFalse( ANPA_Socios_Comunicacion_Estado::pode_transicionar( 'cancelado', 'pendente' ) );

		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::reintentable( 'pendente' ) );
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::reintentable( 'fallido' ) );
		$this->assertFalse( ANPA_Socios_Comunicacion_Estado::reintentable( 'aceptado' ) );
		$this->assertFalse( ANPA_Socios_Comunicacion_Estado::reintentable( 'procesando' ) );

		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::terminal( 'aceptado' ) );
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::terminal( 'fallido_definitivo' ) );
		$this->assertTrue( ANPA_Socios_Comunicacion_Estado::terminal( 'cancelado' ) );
		$this->assertFalse( ANPA_Socios_Comunicacion_Estado::terminal( 'fallido' ) );
	}

	// ── Backoff policy ──────────────────────────────────────────────────

	public function test_backoff_is_exponential_with_cap(): void {
		$this->assertSame( 0, ANPA_Socios_Backoff::espera_para( 0, 300, 21600 ) );
		$this->assertSame( 300, ANPA_Socios_Backoff::espera_para( 1, 300, 21600 ) );
		$this->assertSame( 600, ANPA_Socios_Backoff::espera_para( 2, 300, 21600 ) );
		$this->assertSame( 1200, ANPA_Socios_Backoff::espera_para( 3, 300, 21600 ) );
		// Grows until the cap, then stays capped.
		$this->assertSame( 21600, ANPA_Socios_Backoff::espera_para( 20, 300, 21600 ) );
	}

	public function test_backoff_terminal_after_max(): void {
		$this->assertSame( 'fallido', ANPA_Socios_Backoff::estado_tras_fallo( 1, 5 ) );
		$this->assertSame( 'fallido', ANPA_Socios_Backoff::estado_tras_fallo( 4, 5 ) );
		$this->assertSame( 'fallido_definitivo', ANPA_Socios_Backoff::estado_tras_fallo( 5, 5 ) );
		$this->assertSame( 'fallido_definitivo', ANPA_Socios_Backoff::estado_tras_fallo( 9, 5 ) );
	}

	// ── Recipient normalization + deduplication ─────────────────────────

	public function test_normalize_and_validate(): void {
		$this->assertSame( 'a@b.com', ANPA_Socios_Destinatarios::normalizar( '  A@B.CoM ' ) );
		$this->assertTrue( ANPA_Socios_Destinatarios::valido( 'a@b.com' ) );
		$this->assertFalse( ANPA_Socios_Destinatarios::valido( 'nope' ) );
		$this->assertFalse( ANPA_Socios_Destinatarios::valido( 'a@b' ) );
		$this->assertFalse( ANPA_Socios_Destinatarios::valido( '' ) );
	}

	public function test_dedup_principal_equals_secundario(): void {
		$raw = array(
			array( 'email' => 'Pai@Casa.com', 'tipo' => 'principal', 'entidade_tipo' => 'socio', 'entidade_id' => 10 ),
			array( 'email' => 'pai@casa.com', 'tipo' => 'secundario', 'entidade_tipo' => 'socio', 'entidade_id' => 11 ),
			array( 'email' => 'nai@casa.com', 'tipo' => 'principal', 'entidade_tipo' => 'socio', 'entidade_id' => 12 ),
			array( 'email' => 'malo', 'tipo' => 'principal' ),
			array( 'email' => '', 'tipo' => 'principal' ),
		);
		$out = ANPA_Socios_Destinatarios::preparar( $raw );

		$emails = array_column( $out['validos'], 'email' );
		$this->assertContains( 'pai@casa.com', $emails );
		$this->assertContains( 'nai@casa.com', $emails );
		$this->assertCount( 2, $out['validos'], 'principal==secundario collapses to one send' );
		// First occurrence wins (principal / entity 10).
		$this->assertSame( 'principal', $out['validos'][0]['tipo'] );
		$this->assertSame( 10, $out['validos'][0]['entidade_id'] );

		$motivos = array_column( $out['omitidos'], 'motivo' );
		$this->assertContains( 'duplicado', $motivos );
		$this->assertContains( 'invalido', $motivos );
	}

	// ── Batch planner ───────────────────────────────────────────────────

	public function test_planner_selects_only_eligible_capped_oldest_first(): void {
		$now = '2026-07-24 12:00:00';
		$records = array(
			array( 'id' => 5, 'estado' => 'pendente', 'seguinte_intento_en' => '' ),
			array( 'id' => 2, 'estado' => 'fallido', 'seguinte_intento_en' => '2026-07-24 11:00:00' ), // due
			array( 'id' => 3, 'estado' => 'fallido', 'seguinte_intento_en' => '2026-07-24 13:00:00' ), // not due yet
			array( 'id' => 4, 'estado' => 'aceptado', 'seguinte_intento_en' => '' ), // terminal
			array( 'id' => 1, 'estado' => 'cancelado', 'seguinte_intento_en' => '' ), // terminal
			array( 'id' => 6, 'estado' => 'procesando', 'seguinte_intento_en' => '' ), // not retryable
		);

		$sel = ANPA_Socios_Cola_Planner::seleccionar( $records, $now, 25 );
		$ids = array_column( $sel, 'id' );
		$this->assertSame( array( 2, 5 ), $ids, 'only due pendente/fallido, oldest id first' );
	}

	public function test_planner_batch_size_clamped(): void {
		$this->assertSame( 25, ANPA_Socios_Cola_Planner::tamano_lote( 0 ) );
		$this->assertSame( 25, ANPA_Socios_Cola_Planner::tamano_lote( -3 ) );
		$this->assertSame( 10, ANPA_Socios_Cola_Planner::tamano_lote( 10 ) );
		$this->assertSame( 100, ANPA_Socios_Cola_Planner::tamano_lote( 500 ) );

		$records = array();
		for ( $i = 1; $i <= 40; $i++ ) {
			$records[] = array( 'id' => $i, 'estado' => 'pendente', 'seguinte_intento_en' => '' );
		}
		$sel = ANPA_Socios_Cola_Planner::seleccionar( $records, '2026-07-24 12:00:00', 25 );
		$this->assertCount( 25, $sel );
		$this->assertSame( 1, $sel[0]['id'] );
		$this->assertSame( 25, $sel[24]['id'] );
	}
}
