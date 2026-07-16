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
		$this->assertSame( array( 'Grupo iniciación — Tarde' ), $grid[0]['dias']['luns'][0]['grupos'] );
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
		$this->assertSame( array( 'Grupo mixto — Comedor' ), $grid[0]['dias']['luns'][0]['grupos'] );
	}

	public function test_invalid_or_missing_franxa_is_ignored(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array( 'nome' => 'Sen franxa', 'grupo_nome' => 'A', 'horario' => 'tarde', 'franxa' => '', 'dias' => 'luns' ),
			array( 'nome' => 'Mal', 'grupo_nome' => 'B', 'horario' => 'tarde', 'franxa' => 'tarde', 'dias' => 'luns' ),
		) );

		$this->assertSame( array(), $grid );
	}
}
