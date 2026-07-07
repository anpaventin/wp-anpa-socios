<?php
/**
 * Unit tests for fase5 form identity validators: NIF/NIE and phone.
 *
 * @since  1.6.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers ANPA_Socios_Sepa::validar_nif_nie and ANPA_Socios_Payload::validar_telefono.
 */
final class Test_ANPA_Socios_Forms_Identity extends TestCase {

	/**
	 * @dataProvider validNifNieProvider
	 */
	public function test_valid_nif_nie_returns_canonical( string $input, string $expected ): void {
		$this->assertSame( $expected, ANPA_Socios_Sepa::validar_nif_nie( $input ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function validNifNieProvider(): array {
		return array(
			'NIF Z'             => array( '12345678Z', '12345678Z' ),
			'NIF T (zeros)'     => array( '00000000T', '00000000T' ),
			'NIF lowercase'     => array( '12345678z', '12345678Z' ),
			'NIF with spaces'   => array( '  12345678Z  ', '12345678Z' ),
			'NIE X -> L'        => array( 'X1234567L', 'X1234567L' ),
			'NIE Y -> Z'        => array( 'Y0000000Z', 'Y0000000Z' ),
			'NIE lowercase x'   => array( 'x1234567l', 'X1234567L' ),
		);
	}

	/**
	 * @dataProvider invalidNifNieProvider
	 */
	public function test_invalid_nif_nie_returns_null( string $input ): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_nif_nie( $input ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function invalidNifNieProvider(): array {
		return array(
			'wrong NIF letter'  => array( '12345678A' ),
			'too short'         => array( '1234567Z' ),
			'too long'          => array( '123456789Z' ),
			'no letter'         => array( '12345678' ),
			'wrong NIE letter'  => array( 'X1234567Z' ),
			'bad NIE prefix'    => array( 'W1234567L' ),
			'letters only'      => array( 'ABCDEFGHI' ),
			'empty'             => array( '' ),
			'spaces only'       => array( '   ' ),
		);
	}

	/**
	 * @dataProvider validPhoneProvider
	 */
	public function test_valid_phone_returns_canonical( string $input, string $expected ): void {
		$this->assertSame( $expected, ANPA_Socios_Payload::validar_telefono( $input ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function validPhoneProvider(): array {
		return array(
			'mobile 6'          => array( '666555444', '666555444' ),
			'mobile 7'          => array( '711223344', '711223344' ),
			'landline 8'        => array( '881234567', '881234567' ),
			'landline 9'        => array( '981123456', '981123456' ),
			'with +34 + spaces' => array( '+34 666 555 444', '666555444' ),
			'with 0034'         => array( '0034666555444', '666555444' ),
			'with hyphens'      => array( '666-55-54-44', '666555444' ),
		);
	}

	/**
	 * @dataProvider invalidPhoneProvider
	 */
	public function test_invalid_phone_returns_null( string $input ): void {
		$this->assertNull( ANPA_Socios_Payload::validar_telefono( $input ) );
	}

	/**
	 * @dataProvider validIbanProvider
	 */
	public function test_valid_iban_returns_canonical( string $input, string $expected ): void {
		$this->assertSame( $expected, ANPA_Socios_Sepa::validar_iban( $input ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function validIbanProvider(): array {
		return array(
			'ES compact'   => array( 'ES9121000418450200051332', 'ES9121000418450200051332' ),
			'ES spaced'    => array( 'ES91 2100 0418 4502 0005 1332', 'ES9121000418450200051332' ),
			'ES lowercase' => array( 'es9121000418450200051332', 'ES9121000418450200051332' ),
			'GB valid'     => array( 'GB82 WEST 1234 5698 7654 32', 'GB82WEST12345698765432' ),
		);
	}

	/**
	 * @dataProvider invalidIbanProvider
	 */
	public function test_invalid_iban_returns_null( string $input ): void {
		$this->assertNull( ANPA_Socios_Sepa::validar_iban( $input ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function invalidIbanProvider(): array {
		return array(
			'bad checksum'  => array( 'ES9121000418450200051333' ),
			'too short'     => array( 'ES12' ),
			'no country'    => array( '1234567890123456' ),
			'letters body'  => array( 'ES91ZZZZ0418450200051332' ),
			'empty'         => array( '' ),
		);
	}
}
