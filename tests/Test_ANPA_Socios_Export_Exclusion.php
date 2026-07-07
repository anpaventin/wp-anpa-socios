<?php
/**
 * Property 2: sensitive banking data is never present in any export.
 *
 * @since  1.7.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Asserts export column lists carry no IBAN/NIF/ciphertext fields.
 */
final class Test_ANPA_Socios_Export_Exclusion extends TestCase {

	/**
	 * @dataProvider columnSetProvider
	 * @param string[] $columns
	 */
	public function test_no_banking_columns_in_alumnos_export( array $columns ): void {
		foreach ( $columns as $col ) {
			$lc = strtolower( (string) $col );
			$this->assertStringNotContainsString( 'iban', $lc, "Export column leaks IBAN: {$col}" );
			$this->assertStringNotContainsString( 'nif', $lc, "Export column leaks NIF: {$col}" );
			$this->assertStringNotContainsString( 'cifrado', $lc, "Export column leaks ciphertext: {$col}" );
			$this->assertStringNotContainsString( 'domicil', $lc, "Export column leaks banking: {$col}" );
		}
	}

	/**
	 * @return array<string,array{0:string[]}>
	 */
	public function columnSetProvider(): array {
		return array(
			'admin view'   => array( ANPA_Socios_Alumnos_Export::columns( true ) ),
			'empresa view' => array( ANPA_Socios_Alumnos_Export::columns( false ) ),
		);
	}
}
