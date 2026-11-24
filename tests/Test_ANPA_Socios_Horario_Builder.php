<?php
/**
 * Unit tests for ANPA_Socios_Horario_Builder.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_ANPA_Socios_Horario_Builder extends TestCase {

	public function test_empty_input_yields_empty_grid(): void {
		$this->assertSame( array(), ANPA_Socios_Horario_Builder::build( array() ) );
	}

	public function test_single_activity_is_placed_by_franxa_and_day(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Robótica', 'grupo_nome' => 'Grupo iniciación', 'horario' => 'tarde', 'franxa' => '16:45-17:45', 'dias' => 'luns' ),
		) );

		$this->assertCount( 1, $grid );
		$this->assertSame( '16:45-17:45', $grid[0]['franxa'] );
		$this->assertSame( 'Robótica', $grid[0]['dias']['luns'][0]['nome'] );
		$this->assertSame( array( 'Grupo iniciación' ), $grid[0]['dias']['luns'][0]['grupos'] );
		$this->assertSame( array(), $grid[0]['dias']['martes'] );
	}

	public function test_franxas_are_sorted_by_start_time(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Tarde', 'grupo_nome' => 'B', 'horario' => 'tarde', 'franxa' => '16:45-17:45', 'dias' => 'venres' ),
			array( 'nome' => 'Comedor-range', 'grupo_nome' => 'A', 'horario' => 'manha', 'franxa' => '14:20-15:10', 'dias' => 'luns' ),
		) );

		$this->assertSame( '14:20-15:10', $grid[0]['franxa'] );
		$this->assertSame( '16:45-17:45', $grid[1]['franxa'] );
	}

	public function test_entries_sorted_by_name_inside_cell(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Zumba', 'grupo_nome' => 'A', 'horario' => 'tarde', 'franxa' => '16:45-17:45', 'dias' => 'luns' ),
			array( 'nome' => 'Axedrez', 'grupo_nome' => 'B', 'horario' => 'tarde', 'franxa' => '16:45-17:45', 'dias' => 'luns' ),
		) );

		$entries = $grid[0]['dias']['luns'];
		$this->assertSame( 'Axedrez', $entries[0]['nome'] );
		$this->assertSame( 'Zumba', $entries[1]['nome'] );
	}

	public function test_activity_spanning_multiple_days_appears_in_each_day_column(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Inglés', 'grupo_nome' => 'Grupo mixto', 'horario' => 'manha', 'franxa' => '16:45-17:45', 'dias' => 'luns,mercores' ),
		) );

		$this->assertSame( 'Inglés', $grid[0]['dias']['luns'][0]['nome'] );
		$this->assertSame( 'Inglés', $grid[0]['dias']['mercores'][0]['nome'] );
		$this->assertSame( array( 'Grupo mixto' ), $grid[0]['dias']['luns'][0]['grupos'] );
	}

	public function test_invalid_or_missing_franxa_is_ignored(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Sen franxa', 'grupo_nome' => 'A', 'horario' => 'tarde', 'franxa' => '', 'dias' => 'luns' ),
			array( 'nome' => 'Mal', 'grupo_nome' => 'B', 'horario' => 'tarde', 'franxa' => 'tarde', 'dias' => 'luns' ),
		) );

		$this->assertSame( array(), $grid );
	}

	public function test_group_label_drops_the_repeated_activity_name_and_period(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Patios coeducativos', 'grupo_nome' => 'Patios coeducativos (1º, 2º, 3º)', 'horario' => 'maña', 'franxa' => '11:30-11:55', 'dias' => 'luns', 'max_pupilos' => 20, 'activos' => 9 ),
			array( 'nome' => 'Xadrez', 'grupo_nome' => 'Xadrez', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'martes' ),
		) );
		// 1.99.0: the places travel apart from the levels («Nome (cursos) 9/20 Estado»).
		$this->assertSame( array( '1º, 2º, 3º' ), $grid[0]['dias']['luns'][0]['grupos'] );
		$this->assertSame( 9, $grid[0]['dias']['luns'][0]['activos'] );
		$this->assertSame( 20, $grid[0]['dias']['luns'][0]['max'] );
		$this->assertSame( array(), $grid[1]['dias']['martes'][0]['grupos'] );
	}

	public function test_entry_carries_state_and_occupancy(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Legoteca', 'grupo_nome' => 'Legoteca (4º, 5º, 6º)', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'luns', 'max_pupilos' => 15, 'min_pupilos' => 9, 'activos' => 10, 'estado' => 'pechado', 'estado_publico' => 'creado' ),
			array( 'nome' => 'Xadrez', 'grupo_nome' => 'Xadrez', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'martes', 'max_pupilos' => 12, 'min_pupilos' => 8, 'activos' => 3, 'estado' => 'sen_minimo' ),
			array( 'nome' => 'Teatro', 'grupo_nome' => 'Teatro', 'horario' => 'tarde', 'franxa' => '16:00-17:00', 'dias' => 'mercores', 'max_pupilos' => 10, 'min_pupilos' => 5, 'activos' => 10, 'estado_publico' => 'aberto' ),
			array( 'nome' => 'Patios', 'grupo_nome' => 'Patios (1º)', 'horario' => 'maña', 'franxa' => '11:30-11:55', 'dias' => 'xoves', 'max_pupilos' => 0, 'min_pupilos' => 0, 'activos' => 0, 'estado_publico' => 'aberto' ),
		) );
		$tarde = $grid[1]['dias'];
		$this->assertSame( array( 'creado', 'ok' ), array( $tarde['luns'][0]['estado'], $tarde['luns'][0]['ocupacion'] ) );
		$this->assertSame( array( 'sen_minimo', 'baixo', true ), array( $tarde['martes'][0]['estado'], $tarde['martes'][0]['ocupacion'], $tarde['martes'][0]['sen_minimo'] ) );
		$this->assertSame( array( 'aberto', 'completo' ), array( $tarde['mercores'][0]['estado'], $tarde['mercores'][0]['ocupacion'] ) );
		// No places nor minimum: always runs, no state.
		$this->assertSame( '', $grid[0]['dias']['xoves'][0]['estado'] );
		$this->assertFalse( $grid[0]['dias']['xoves'][0]['sen_minimo'] );
	}

	public function test_etiqueta_grupo(): void {
		$this->assertSame( '4º, 5º, 6º', ANPA_Socios_Horario_Builder::etiqueta_grupo( 'Patios coeducativos', 'patios Coeducativos (4º, 5º, 6º)' ) );
		$this->assertSame( 'Iniciación', ANPA_Socios_Horario_Builder::etiqueta_grupo( 'Robótica', 'Robótica - Iniciación' ) );
		$this->assertSame( 'Grupo A', ANPA_Socios_Horario_Builder::etiqueta_grupo( 'Robótica', 'Grupo A' ) );
		$this->assertSame( '', ANPA_Socios_Horario_Builder::etiqueta_grupo( 'Robótica', 'Robótica' ) );
	}
}
