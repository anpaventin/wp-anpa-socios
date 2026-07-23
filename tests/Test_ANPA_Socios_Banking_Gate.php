<?php
/**
 * Banking-completeness gate: a family without COMPLETE SEPA details cannot
 * enrol children in activities, and the IBAN form warns (in red) that the data
 * is mandatory. Covers the pure row_is_complete logic plus source-level wiring.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-anpa-socios-domiciliacion.php';

final class Test_ANPA_Socios_Banking_Gate extends TestCase {

	private function complete_row(): array {
		return array(
			'iban_last4'        => '1234',
			'titular_nif_mask'  => '****5678Z',
			'enderezo'          => 'Rúa Falsa 1',
			'poboacion'         => 'Ames',
			'codigo_postal'     => '15220',
			'entidade_bancaria' => 'Abanca',
			'autorizacion'      => 1,
		);
	}

	public function test_row_is_complete_true_when_all_mandatory_fields_present(): void {
		$this->assertTrue( ANPA_Socios_Domiciliacion::row_is_complete( $this->complete_row() ) );
	}

	public function test_row_is_incomplete_when_address_missing(): void {
		foreach ( array( 'enderezo', 'codigo_postal', 'poboacion' ) as $field ) {
			$row = $this->complete_row();
			$row[ $field ] = '';
			$this->assertFalse( ANPA_Socios_Domiciliacion::row_is_complete( $row ), "Empty {$field} must be incomplete" );
		}
	}

	public function test_row_is_incomplete_without_iban_nif_or_authorization(): void {
		$noIban = $this->complete_row(); $noIban['iban_last4'] = '';
		$this->assertFalse( ANPA_Socios_Domiciliacion::row_is_complete( $noIban ) );

		$noNif = $this->complete_row(); $noNif['titular_nif_mask'] = '';
		$this->assertFalse( ANPA_Socios_Domiciliacion::row_is_complete( $noNif ) );

		$noAuth = $this->complete_row(); $noAuth['autorizacion'] = 0;
		$this->assertFalse( ANPA_Socios_Domiciliacion::row_is_complete( $noAuth ) );
	}

	public function test_enrol_endpoint_blocks_families_without_complete_banking(): void {
		$src = (string) file_get_contents( __DIR__ . '/../includes/class-anpa-socios-extraescolares-rest.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Domiciliacion::is_complete( $familia_id )', $src );
		$this->assertStringContainsString( 'anpa_extra_sen_banca', $src );
	}

	public function test_get_banking_exposes_banking_complete_flag(): void {
		$src = (string) file_get_contents( __DIR__ . '/../includes/class-anpa-socios-area-rest.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Domiciliacion::row_is_complete( $row )', $src );
		$this->assertStringContainsString( "'has_banking' => false, 'banking_complete' => false", $src );
	}

	public function test_frontend_warns_and_blocks_enrolment_when_banking_incomplete(): void {
		$js = (string) file_get_contents( __DIR__ . '/../assets/js/area.js' );
		// Red mandatory-data warning in the IBAN form.
		$this->assertStringContainsString( 'var bankingComplete', $js );
		$this->assertStringContainsString( '[data-banking-missing]', $js );
		// Enrolment form is gated on banking_complete.
		$this->assertStringContainsString( '!banking.banking_complete', $js );
		$page = (string) file_get_contents( __DIR__ . '/../includes/class-anpa-socios-area-page.php' );
		$this->assertStringContainsString( 'data-banking-missing', $page );
	}
}
