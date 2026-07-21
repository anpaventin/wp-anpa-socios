<?php
/**
 * Characterisation tests for the current legacy hardcodes.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Legacy_Hardcodes extends TestCase {
	public function test_remaining_legacy_hardcodes_are_frozen_but_global_aula_limit_is_retired(): void {
		$config = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-config.php' );
		$this->assertStringNotContainsString( 'DEFAULT_AULA_MAX', $config );
		$this->assertStringNotContainsString( 'OPTION_AULA_MAX', $config );
		$this->assertSame( array( '1', '2', '3', '4', '5', '6' ), ANPA_Socios_Admin_Payload::CURSO_VALIDOS );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' ), ANPA_Socios_Admin_Payload::GRUPO_VALIDOS );
		$this->assertSame(
			array(
				'1-2-3' => array( '1', '2', '3' ),
				'4-5-6' => array( '4', '5', '6' ),
			),
			ANPA_Socios_Curso_Fit::RANGES
		);
		$this->assertSame( array( '1-2-3', '4-5-6' ), ANPA_Socios_Actividade_Options::GRUPOS );
	}

	public function test_fillo_edit_repopulates_classrooms_before_restoring_saved_classroom(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/area.js' );
		$start  = strpos( $source, 'function fillFilloForm(fillo)' );
		$end    = strpos( $source, 'function renderFillos', $start );
		$body   = substr( $source, $start, $end - $start );
		$repopulate = strpos( $body, 'populateFilloAulas(' );
		$restore    = strpos( $body, "querySelector('#anpa-fillo-aula').value = fillo.aula" );

		$this->assertNotFalse( $start );
		$this->assertNotFalse( $repopulate );
		$this->assertNotFalse( $restore );
		$this->assertLessThan( $restore, $repopulate );
	}
}
