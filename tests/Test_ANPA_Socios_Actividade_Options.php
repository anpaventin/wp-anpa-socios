<?php
/**
 * Unit tests for ANPA_Socios_Actividade_Options.
 *
 * Covers normalise/parse/serialize/validate of the activity option sets
 * (horario / grupo curricular / días) introduced in fase7 and the fase10
 * real time-slot (`franxa`) validation.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Actividade_Options extends TestCase {

	public function test_constants_are_canonical(): void {
		$this->assertSame( array( 'manha', 'tarde' ), ANPA_Socios_Actividade_Options::HORARIOS );
		$this->assertSame( array( '1-2-3', '4-5-6' ), ANPA_Socios_Actividade_Options::GRUPOS );
		$this->assertSame( array( 'luns', 'martes', 'mercores', 'xoves', 'venres' ), ANPA_Socios_Actividade_Options::DIAS );
	}

	public function test_normalize_drops_invalid_and_dedupes(): void {
		$out = ANPA_Socios_Actividade_Options::normalize(
			array( 'tarde', 'invalida', 'tarde', 'manha' ),
			ANPA_Socios_Actividade_Options::HORARIOS
		);
		// Canonical order (allowed order), deduped, invalid removed.
		$this->assertSame( array( 'manha', 'tarde' ), $out );
	}

	public function test_normalize_canonical_order_independent_of_input(): void {
		$a = ANPA_Socios_Actividade_Options::normalize( array( 'venres', 'luns' ), ANPA_Socios_Actividade_Options::DIAS );
		$b = ANPA_Socios_Actividade_Options::normalize( array( 'luns', 'venres' ), ANPA_Socios_Actividade_Options::DIAS );
		$this->assertSame( $a, $b );
		$this->assertSame( array( 'luns', 'venres' ), $a );
	}

	public function test_normalize_accepts_csv_string_and_trims_case(): void {
		$out = ANPA_Socios_Actividade_Options::normalize( ' Manha , TARDE ', ANPA_Socios_Actividade_Options::HORARIOS );
		$this->assertSame( array( 'manha', 'tarde' ), $out );
	}

	public function test_normalize_empty_inputs(): void {
		$this->assertSame( array(), ANPA_Socios_Actividade_Options::normalize( '', ANPA_Socios_Actividade_Options::DIAS ) );
		$this->assertSame( array(), ANPA_Socios_Actividade_Options::normalize( '   ', ANPA_Socios_Actividade_Options::DIAS ) );
		$this->assertSame( array(), ANPA_Socios_Actividade_Options::normalize( array(), ANPA_Socios_Actividade_Options::DIAS ) );
		$this->assertSame( array(), ANPA_Socios_Actividade_Options::normalize( null, ANPA_Socios_Actividade_Options::DIAS ) );
	}

	public function test_serialize_round_trips_through_parse(): void {
		$csv = ANPA_Socios_Actividade_Options::serialize(
			array( 'mercores', 'luns', 'mercores' ),
			ANPA_Socios_Actividade_Options::DIAS
		);
		$this->assertSame( 'luns,mercores', $csv );
		$this->assertSame(
			array( 'luns', 'mercores' ),
			ANPA_Socios_Actividade_Options::parse( $csv, ANPA_Socios_Actividade_Options::DIAS )
		);
	}

	public function test_serialize_empty_is_empty_string(): void {
		$this->assertSame( '', ANPA_Socios_Actividade_Options::serialize( array(), ANPA_Socios_Actividade_Options::GRUPOS ) );
	}

	public function test_validate_requires_at_least_one_of_each(): void {
		$this->assertTrue( ANPA_Socios_Actividade_Options::validate(
			array( 'manha' ),
			array( '1-2-3' ),
			array( 'luns' )
		) );
		$this->assertTrue( ANPA_Socios_Actividade_Options::validate(
			'manha,tarde',
			'1-2-3,4-5-6',
			'luns,venres'
		) );
	}

	public function test_validate_fails_when_any_set_empty_or_invalid(): void {
		$this->assertFalse( ANPA_Socios_Actividade_Options::validate( array(), array( '1-2-3' ), array( 'luns' ) ) );
		$this->assertFalse( ANPA_Socios_Actividade_Options::validate( array( 'manha' ), array(), array( 'luns' ) ) );
		$this->assertFalse( ANPA_Socios_Actividade_Options::validate( array( 'manha' ), array( '1-2-3' ), array() ) );
		$this->assertFalse( ANPA_Socios_Actividade_Options::validate( array( 'noite' ), array( '7-8-9' ), array( 'domingo' ) ) );
	}

	public function test_validates_real_franxa_format(): void {
		$this->assertSame( '16:45-17:45', ANPA_Socios_Actividade_Options::normalize_franxa( '16:45 - 17:45' ) );
		$this->assertSame( '14:20-15:10', ANPA_Socios_Actividade_Options::normalize_franxa( '14:20-15:10' ) );
		$this->assertNull( ANPA_Socios_Actividade_Options::normalize_franxa( 'tarde' ) );
		$this->assertNull( ANPA_Socios_Actividade_Options::normalize_franxa( '17:45-16:45' ) );
		$this->assertNull( ANPA_Socios_Actividade_Options::normalize_franxa( '25:00-26:00' ) );
	}
}
