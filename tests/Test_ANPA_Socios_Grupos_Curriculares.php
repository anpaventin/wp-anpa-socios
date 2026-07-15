<?php
/**
 * Unit tests for the pure ANPA_Socios_Grupos_Curriculares domain (fase24 PR-GC1).
 *
 * Covers snapshot normalization, exclusive-horario validation and
 * effective-franxa resolution — all WordPress-independent.
 *
 * @since  1.41.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupos_Curriculares extends TestCase {

	// ── normalize_snapshot ────────────────────────────────────

	public function test_normalize_accepts_valid_group_with_both_franxas(): void {
		$out = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Grupo 1',
			'orde'         => 10,
			'niveis'       => array( '1', '2', '3' ),
			'franxa_manha' => '14:10-15:10',
			'franxa_tarde' => '16:45-17:45',
		) );

		$this->assertSame( 'Grupo 1', $out['etiqueta'] );
		$this->assertSame( 10, $out['orde'] );
		$this->assertSame( array( '1', '2', '3' ), $out['niveis'] );
		$this->assertSame( '14:10-15:10', $out['franxa_manha'] );
		$this->assertSame( '16:45-17:45', $out['franxa_tarde'] );
	}

	public function test_normalize_accepts_group_with_only_morning(): void {
		$out = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Só mañá',
			'niveis'       => array( '4', '5', '6' ),
			'franxa_manha' => '15:10-16:10',
		) );

		$this->assertSame( '15:10-16:10', $out['franxa_manha'] );
		$this->assertSame( '', $out['franxa_tarde'] );
		// orde defaults to 10 when absent.
		$this->assertSame( 10, $out['orde'] );
	}

	public function test_normalize_accepts_group_with_only_afternoon(): void {
		$out = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Só tarde',
			'niveis'       => array( '1' ),
			'franxa_tarde' => '16:45-17:45',
		) );

		$this->assertSame( '', $out['franxa_manha'] );
		$this->assertSame( '16:45-17:45', $out['franxa_tarde'] );
	}

	public function test_normalize_rejects_group_without_label(): void {
		$this->assertSame( array(), ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => '   ',
			'niveis'       => array( '1' ),
			'franxa_manha' => '14:10-15:10',
		) ) );
	}

	public function test_normalize_rejects_group_without_niveis(): void {
		$this->assertSame( array(), ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Sen niveis',
			'niveis'       => array(),
			'franxa_manha' => '14:10-15:10',
		) ) );
	}

	public function test_normalize_rejects_group_without_any_franxa(): void {
		$this->assertSame( array(), ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta' => 'Sen franxa',
			'niveis'   => array( '1', '2' ),
		) ) );
	}

	public function test_normalize_rejects_group_with_only_invalid_franxa(): void {
		// An invalid franxa string normalises to '' — same as absent.
		$this->assertSame( array(), ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Franxa mala',
			'niveis'       => array( '1' ),
			'franxa_manha' => '25:99-99:99',
			'franxa_tarde' => 'non é unha franxa',
		) ) );
	}

	public function test_normalize_dedupes_and_sorts_niveis(): void {
		$out = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Grupo',
			'niveis'       => array( '3', '1', '1', '2' ),
			'franxa_manha' => '14:10-15:10',
		) );

		$this->assertSame( array( '1', '2', '3' ), $out['niveis'] );
	}

	public function test_normalize_rejects_overlong_label(): void {
		$this->assertSame( array(), ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => str_repeat( 'x', 61 ),
			'niveis'       => array( '1' ),
			'franxa_manha' => '14:10-15:10',
		) ) );
	}

	// ── is_valid_horario (exclusive) ──────────────────────────

	public function test_is_valid_horario_accepts_single_token(): void {
		$this->assertTrue( ANPA_Socios_Grupos_Curriculares::is_valid_horario( 'manha' ) );
		$this->assertTrue( ANPA_Socios_Grupos_Curriculares::is_valid_horario( 'tarde' ) );
	}

	public function test_is_valid_horario_rejects_both_as_array(): void {
		// The whole point of fase24: horario is exclusive, never a set.
		$this->assertFalse( ANPA_Socios_Grupos_Curriculares::is_valid_horario( array( 'manha', 'tarde' ) ) );
	}

	public function test_is_valid_horario_rejects_empty_and_unknown(): void {
		$this->assertFalse( ANPA_Socios_Grupos_Curriculares::is_valid_horario( '' ) );
		$this->assertFalse( ANPA_Socios_Grupos_Curriculares::is_valid_horario( 'noite' ) );
		$this->assertFalse( ANPA_Socios_Grupos_Curriculares::is_valid_horario( 'manha,tarde' ) );
		$this->assertFalse( ANPA_Socios_Grupos_Curriculares::is_valid_horario( null ) );
	}

	// ── franxa_efectiva / offerable_under ─────────────────────

	public function test_franxa_efectiva_resolves_by_horario(): void {
		$grupo = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Grupo 1',
			'niveis'       => array( '1', '2', '3' ),
			'franxa_manha' => '14:10-15:10',
			'franxa_tarde' => '16:45-17:45',
		) );

		$this->assertSame( '14:10-15:10', ANPA_Socios_Grupos_Curriculares::franxa_efectiva( $grupo, 'manha' ) );
		$this->assertSame( '16:45-17:45', ANPA_Socios_Grupos_Curriculares::franxa_efectiva( $grupo, 'tarde' ) );
	}

	public function test_franxa_efectiva_null_when_slot_absent(): void {
		$grupo = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Só mañá',
			'niveis'       => array( '1' ),
			'franxa_manha' => '14:10-15:10',
		) );

		$this->assertSame( '14:10-15:10', ANPA_Socios_Grupos_Curriculares::franxa_efectiva( $grupo, 'manha' ) );
		$this->assertNull( ANPA_Socios_Grupos_Curriculares::franxa_efectiva( $grupo, 'tarde' ) );
	}

	public function test_franxa_efectiva_null_for_invalid_horario(): void {
		$grupo = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Grupo',
			'niveis'       => array( '1' ),
			'franxa_manha' => '14:10-15:10',
		) );

		$this->assertNull( ANPA_Socios_Grupos_Curriculares::franxa_efectiva( $grupo, 'noite' ) );
	}

	public function test_offerable_under_matches_available_slot(): void {
		$grupo = ANPA_Socios_Grupos_Curriculares::normalize_snapshot( array(
			'etiqueta'     => 'Só tarde',
			'niveis'       => array( '4', '5', '6' ),
			'franxa_tarde' => '16:45-17:45',
		) );

		$this->assertFalse( ANPA_Socios_Grupos_Curriculares::offerable_under( $grupo, 'manha' ) );
		$this->assertTrue( ANPA_Socios_Grupos_Curriculares::offerable_under( $grupo, 'tarde' ) );
	}
}
