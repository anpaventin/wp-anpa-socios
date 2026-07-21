<?php
/**
 * Unit tests for ANPA_Socios_Estrutura_Escolar.
 *
 * @since  23.0.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Estrutura_Escolar extends TestCase {
	public function test_bulk_collections_accept_one_hundred_levels_without_removing_schedule_safety_cap(): void {
		$niveis = array_fill( 0, 100, array() );

		$this->assertTrue( ANPA_Socios_Estrutura_Escolar::has_supported_collection_sizes( $niveis, array() ) );
		$this->assertFalse( ANPA_Socios_Estrutura_Escolar::has_supported_collection_sizes( $niveis, array_fill( 0, 31, array() ) ) );
		$this->assertFalse( ANPA_Socios_Estrutura_Escolar::has_supported_collection_sizes( 'invalid', array() ) );
	}

	public function test_normalize_snapshot_orders_levels_and_classrooms(): void {
		$normalized = ANPA_Socios_Estrutura_Escolar::normalize_snapshot( array(
			'curso_escolar' => '2026-2027',
			'niveis'        => array(
				array(
					'codigo' => ' 4 ',
					'etiqueta' => ' 4º ',
					'orde' => '40',
					'aulas' => array(
						array( 'codigo' => ' B ', 'etiqueta' => ' B ', 'orde' => '20' ),
						array( 'codigo' => 'A', 'etiqueta' => 'A', 'orde' => '10' ),
						array( 'codigo' => 'B', 'etiqueta' => 'duplicada', 'orde' => '30' ),
					),
				),
				array(
					'codigo' => '1',
					'etiqueta' => ' 1º ',
					'orde' => '10',
					'aulas' => array(
						array( 'codigo' => 'D', 'etiqueta' => 'D', 'orde' => '10' ),
						array( 'codigo' => 'C', 'etiqueta' => 'C', 'orde' => '20' ),
					),
				),
			),
		) );

		$this->assertSame( '2026/2027', $normalized['curso_escolar'] );
		$this->assertSame( array( '1', '4' ), array_column( $normalized['niveis'], 'codigo' ) );
		$this->assertSame( array( '1º', '4º' ), array_column( $normalized['niveis'], 'etiqueta' ) );
		$this->assertSame( array( 10, 40 ), array_column( $normalized['niveis'], 'orde' ) );
		$this->assertSame( array( 'D', 'C' ), array_column( $normalized['niveis'][0]['aulas'], 'codigo' ) );
		$this->assertSame( array( 'A', 'B' ), array_column( $normalized['niveis'][1]['aulas'], 'codigo' ) );
	}

	public function test_normalize_snapshot_does_not_apply_legacy_entity_caps(): void {
		$niveis = array();
		for ( $nivel = 1; $nivel <= 100; $nivel++ ) {
			$aulas = array();
			for ( $i = 0; $i < 8; $i++ ) {
				$aulas[] = array(
					'codigo' => chr( 65 + $i ),
					'etiqueta' => chr( 65 + $i ),
					'orde' => ( $i + 1 ) * 10,
				);
			}

			$niveis[] = array(
				'codigo' => (string) $nivel,
				'etiqueta' => $nivel . 'º',
				'orde' => $nivel * 10,
				'aulas' => $aulas,
			);
		}

		$normalized = ANPA_Socios_Estrutura_Escolar::normalize_snapshot( array(
			'curso_escolar' => '2026/2027',
			'niveis' => $niveis,
		) );

		$this->assertCount( 100, $normalized['niveis'] );
		$this->assertCount( 8, $normalized['niveis'][0]['aulas'] );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' ), array_column( $normalized['niveis'][0]['aulas'], 'codigo' ) );
	}

	public function test_normalize_snapshot_does_not_cap_classrooms_or_reintroduce_a_to_h_as_a_ceiling(): void {
		$aulas = array();
		for ( $i = 1; $i <= 100; $i++ ) {
			$aulas[] = array( 'codigo' => 'A' . $i, 'etiqueta' => 'Aula ' . $i, 'orde' => $i );
		}
		$aulas[] = array( 'codigo' => 'I', 'etiqueta' => 'I', 'orde' => 101 );

		$normalized = ANPA_Socios_Estrutura_Escolar::normalize_snapshot( array(
			'curso_escolar' => '2026/2027',
			'niveis' => array(
				array( 'codigo' => '1', 'etiqueta' => '1º', 'orde' => 10, 'aulas' => $aulas ),
			),
		) );

		$this->assertCount( 101, $normalized['niveis'][0]['aulas'] );
		$this->assertContains( 'I', array_column( $normalized['niveis'][0]['aulas'], 'codigo' ) );
	}

	public function test_is_valid_assignment_requires_course_level_and_aula(): void {
		$snapshot = ANPA_Socios_Estrutura_Escolar::normalize_snapshot( array(
			'curso_escolar' => '2026/2027',
			'niveis'        => array(
				array(
					'codigo' => '4',
					'etiqueta' => '4º',
					'orde' => 40,
					'aulas' => array(
						array( 'codigo' => 'E', 'etiqueta' => 'E', 'orde' => 10 ),
						array( 'codigo' => 'F', 'etiqueta' => 'F', 'orde' => 20 ),
					),
				),
			),
		) );

		$this->assertTrue( ANPA_Socios_Estrutura_Escolar::is_valid_nivel( $snapshot, '4' ) );
		$this->assertSame( array( 'E', 'F' ), array_column( ANPA_Socios_Estrutura_Escolar::aulas( $snapshot, '4' ), 'codigo' ) );
		$this->assertTrue( ANPA_Socios_Estrutura_Escolar::is_valid_assignment( $snapshot, '2026/2027', '4', 'E' ) );
		$this->assertFalse( ANPA_Socios_Estrutura_Escolar::is_valid_assignment( $snapshot, '2025/2026', '4', 'E' ) );
		$this->assertFalse( ANPA_Socios_Estrutura_Escolar::is_valid_assignment( $snapshot, '2026/2027', '5', 'E' ) );
		$this->assertFalse( ANPA_Socios_Estrutura_Escolar::is_valid_assignment( $snapshot, '2026/2027', '4', '1-2-3' ) );
	}

	public function test_normalize_snapshot_preserves_valid_meal_window_and_clears_empty_pair(): void {
		$normalized = ANPA_Socios_Estrutura_Escolar::normalize_snapshot( array(
			'curso_escolar' => '2026/2027',
			'niveis' => array(
				array( 'codigo' => '1', 'etiqueta' => '1º', 'orde' => 10, 'comedor_inicio' => '13:00', 'comedor_fin' => '14:00' ),
				array( 'codigo' => '2', 'etiqueta' => '2º', 'orde' => 20, 'comedor_inicio' => null, 'comedor_fin' => null ),
			),
		) );

		$this->assertSame( '13:00', $normalized['niveis'][0]['comedor_inicio'] );
		$this->assertSame( '14:00', $normalized['niveis'][0]['comedor_fin'] );
		$this->assertNull( $normalized['niveis'][1]['comedor_inicio'] );
		$this->assertNull( $normalized['niveis'][1]['comedor_fin'] );
	}

	public function test_normalize_snapshot_rejects_partial_or_reversed_meal_window(): void {
		$snapshot = array(
			'curso_escolar' => '2026/2027',
			'niveis' => array(
				array( 'codigo' => '1', 'etiqueta' => '1º', 'orde' => 10, 'comedor_inicio' => '13:00', 'comedor_fin' => '' ),
				array( 'codigo' => '2', 'etiqueta' => '2º', 'orde' => 20, 'comedor_inicio' => '15:00', 'comedor_fin' => '14:00' ),
			),
		);

		$this->assertSame( array(), ANPA_Socios_Estrutura_Escolar::normalize_snapshot( $snapshot ) );
	}

	public function test_normalize_snapshot_resolves_reusable_meal_schedule_by_key(): void {
		$normalized = ANPA_Socios_Estrutura_Escolar::normalize_snapshot( array(
			'curso_escolar' => '2026/2027',
			'horarios_comedor' => array(
				array( 'key' => 'h-2', 'id' => 2, 'nome' => 'Quenda tarde', 'inicio' => '14:00', 'fin' => '15:00', 'orde' => 20 ),
				array( 'key' => 'novo-1', 'id' => 0, 'nome' => 'Quenda cedo', 'inicio' => '13:00', 'fin' => '14:00', 'orde' => 10 ),
			),
			'niveis' => array(
				array( 'codigo' => '1', 'etiqueta' => '1º', 'orde' => 10, 'horario_comedor_key' => 'novo-1' ),
				array( 'codigo' => '4', 'etiqueta' => '4º', 'orde' => 40, 'horario_comedor_key' => 'h-2' ),
			),
		) );

		$this->assertSame( array( 'novo-1', 'h-2' ), array_column( $normalized['horarios_comedor'], 'key' ) );
		$this->assertSame( array( 0, 2 ), array_column( $normalized['horarios_comedor'], 'id' ) );
		$this->assertSame( array( 'novo-1', 'h-2' ), array_column( $normalized['niveis'], 'horario_comedor_key' ) );
		$this->assertSame( array( '13:00', '14:00' ), array_column( $normalized['niveis'], 'comedor_inicio' ) );
		$this->assertSame( array( '14:00', '15:00' ), array_column( $normalized['niveis'], 'comedor_fin' ) );
	}

	public function test_normalize_snapshot_rejects_duplicate_windows_or_unknown_schedule_key(): void {
		$base = array(
			'curso_escolar' => '2026/2027',
			'horarios_comedor' => array(
				array( 'key' => 'h-1', 'id' => 1, 'nome' => 'Un', 'inicio' => '13:00', 'fin' => '14:00', 'orde' => 10 ),
				array( 'key' => 'h-2', 'id' => 2, 'nome' => 'Dous', 'inicio' => '13:00', 'fin' => '14:00', 'orde' => 20 ),
			),
			'niveis' => array( array( 'codigo' => '1', 'etiqueta' => '1º', 'orde' => 10, 'horario_comedor_key' => 'h-1' ) ),
		);
		$this->assertSame( array(), ANPA_Socios_Estrutura_Escolar::normalize_snapshot( $base ) );

		$base['horarios_comedor'] = array( $base['horarios_comedor'][0] );
		$base['niveis'][0]['horario_comedor_key'] = 'inexistente';
		$this->assertSame( array(), ANPA_Socios_Estrutura_Escolar::normalize_snapshot( $base ) );
	}
}
