<?php
/**
 * Unit tests for ANPA_Socios_Payload validation methods.
 *
 * Pure PHP tests; no WordPress bootstrap.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Payload extends TestCase {

	/**
	 * One-character nome passes (min boundary).
	 */
	public function test_validar_nome_valid_1_char(): void {
		$this->assertSame(
			'M',
			ANPA_Socios_Payload::validar_nome( 'M' )
		);
	}

	/**
	 * Fifty-character nome passes (max boundary).
	 */
	public function test_validar_nome_valid_50_chars(): void {
		$input = str_repeat( 'a', 50 );
		$this->assertSame(
			$input,
			ANPA_Socios_Payload::validar_nome( $input )
		);
	}

	/**
	 * Empty string returns null.
	 */
	public function test_validar_nome_empty(): void {
		$this->assertNull( ANPA_Socios_Payload::validar_nome( '' ) );
	}

	/**
	 * Whitespace-only string returns null after trim.
	 */
	public function test_validar_nome_whitespace_only(): void {
		$this->assertNull(
			ANPA_Socios_Payload::validar_nome( "   \t  \n  " )
		);
	}

	/**
	 * Fifty-one-character string returns null (over max).
	 */
	public function test_validar_nome_overlong_51_chars(): void {
		$input = str_repeat( 'a', 51 );
		$this->assertNull( ANPA_Socios_Payload::validar_nome( $input ) );
	}

	/**
	 * String with control char returns null.
	 */
	public function test_validar_nome_control_char(): void {
		$this->assertNull(
			ANPA_Socios_Payload::validar_nome( "M\x01aría" )
		);
	}

	/**
	 * One-hundred-character apelidos passes (max boundary).
	 */
	public function test_validar_apelidos_valid_100_chars(): void {
		$input = str_repeat( 'a', 100 );
		$this->assertSame(
			$input,
			ANPA_Socios_Payload::validar_apelidos( $input )
		);
	}

	/**
	 * Empty string returns null.
	 */
	public function test_validar_apelidos_empty(): void {
		$this->assertNull( ANPA_Socios_Payload::validar_apelidos( '' ) );
	}

	/**
	 * One-hundred-one-character string returns null (over max).
	 */
	public function test_validar_apelidos_overlong_101_chars(): void {
		$input = str_repeat( 'a', 101 );
		$this->assertNull(
			ANPA_Socios_Payload::validar_apelidos( $input )
		);
	}

	/**
	 * String with control char returns null.
	 */
	public function test_validar_apelidos_control_char(): void {
		$this->assertNull(
			ANPA_Socios_Payload::validar_apelidos( "García\nLópez" )
		);
	}
}
