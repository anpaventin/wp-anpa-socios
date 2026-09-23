<?php
/**
 * 1.68.0: mass email to the families — To = the junta's address, families in
 * Bcc in batches of 50 (under the 100-recipients-per-message limit of Gmail
 * and of most SMTP relays). Pure batching helper.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-envio-masivo.php';

final class Test_ANPA_Socios_Envio_Masivo extends TestCase {

	public function test_batch_size_is_fifty(): void {
		$this->assertSame( 50, ANPA_Socios_Envio_Masivo::TAMANO_LOTE );
	}

	public function test_lotes_normalises_dedupes_and_drops_invalid_addresses(): void {
		$lotes = ANPA_Socios_Envio_Masivo::lotes( array( ' Ana@Example.com ', 'ana@example.com', 'non-e-un-correo', '', 'bea@example.com', null, 42 ) );
		$this->assertSame( array( array( 'ana@example.com', 'bea@example.com' ) ), $lotes );
	}

	public function test_lotes_chunks_by_the_batch_size_preserving_order(): void {
		$emails = array();
		for ( $i = 1; $i <= 120; $i++ ) {
			$emails[] = sprintf( 'familia%03d@example.com', $i );
		}
		$lotes = ANPA_Socios_Envio_Masivo::lotes( $emails );
		$this->assertCount( 3, $lotes );
		$this->assertCount( 50, $lotes[0] );
		$this->assertCount( 50, $lotes[1] );
		$this->assertCount( 20, $lotes[2] );
		$this->assertSame( 'familia001@example.com', $lotes[0][0] );
		$this->assertSame( 'familia120@example.com', $lotes[2][19] );

		$this->assertCount( 12, ANPA_Socios_Envio_Masivo::lotes( $emails, 10 ) );
		$this->assertCount( 3, ANPA_Socios_Envio_Masivo::lotes( $emails, 0 ), 'a non-positive size falls back to the default' );
	}

	public function test_lotes_of_nothing_is_an_empty_list(): void {
		$this->assertSame( array(), ANPA_Socios_Envio_Masivo::lotes( array() ) );
		$this->assertSame( array(), ANPA_Socios_Envio_Masivo::lotes( array( 'x', '' ) ) );
	}

	public function test_resumo_counts_batches_and_recipients(): void {
		$resumo = ANPA_Socios_Envio_Masivo::resumo( array( array( 'ok' => true, 'n' => 50 ), array( 'ok' => false, 'n' => 50 ), array( 'ok' => true, 'n' => 7 ) ) );
		$this->assertSame( array( 'lotes' => 3, 'enviados' => 57, 'fallidos' => 50, 'lotes_fallidos' => 1 ), $resumo );
		$this->assertSame( array( 'lotes' => 0, 'enviados' => 0, 'fallidos' => 0, 'lotes_fallidos' => 0 ), ANPA_Socios_Envio_Masivo::resumo( array() ) );
	}

	public function test_bcc_header_joins_a_batch(): void {
		$this->assertSame( 'Bcc: a@example.com, b@example.com', ANPA_Socios_Envio_Masivo::cabeceira_bcc( array( 'a@example.com', 'b@example.com' ) ) );
	}

	public function test_audit_tag_is_compact_and_ascii(): void {
		$this->assertSame( 'inicio_curso:8/397/0', ANPA_Socios_Envio_Masivo::etiqueta_auditoria( 'inicio_curso', array( 'lotes' => 8, 'enviados' => 397, 'fallidos' => 0, 'lotes_fallidos' => 0 ) ) );
	}
}
