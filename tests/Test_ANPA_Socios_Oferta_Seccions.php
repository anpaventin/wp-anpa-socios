<?php
/**
 * 1.68.2: the public offer is shown in titled sections (comedor / tarde) with
 * at most three cards per row.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Oferta_Seccions extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_activity_goes_to_the_section_of_its_first_group(): void {
		$this->assertSame( 'manha', ANPA_Socios_Oferta_Seccions::horario_de( array( 'horarios_grupos' => '41|Grupo 4-5-6|manha|14:10-15:10|martes,xoves;;42|Grupo B|tarde|16:00-17:00|luns' ) ) );
		$this->assertSame( 'tarde', ANPA_Socios_Oferta_Seccions::horario_de( array( 'horarios_grupos' => '10|Iniciación|tarde|16:00-17:00|luns' ) ) );
		// A group name with a literal '|' still parses (the last three segments are fixed).
		$this->assertSame( 'manha', ANPA_Socios_Oferta_Seccions::horario_de( array( 'horarios_grupos' => '7|Grupo A|B|manha|14:10-15:10|martes,xoves' ) ) );
		$this->assertSame( 'maña', ANPA_Socios_Oferta_Seccions::horario_de( array( 'horarios_grupos' => '3|Madrugadores|maña|08:00-09:00|luns,martes' ) ) );
		$this->assertSame( 'outros', ANPA_Socios_Oferta_Seccions::horario_de( array( 'horarios_grupos' => '' ) ) );
		$this->assertSame( 'outros', ANPA_Socios_Oferta_Seccions::horario_de( array( 'horarios_grupos' => '9|X|raro|10:00-11:00|luns' ) ) );
	}

	public function test_sections_are_ordered_and_only_non_empty_ones_are_returned(): void {
		$rows = array(
			array( 'nome' => 'Xadrez', 'horarios_grupos' => '1|A|tarde|16:00-17:00|luns' ),
			array( 'nome' => 'Fútbol', 'horarios_grupos' => '2|A|manha|14:10-15:10|martes' ),
			array( 'nome' => 'Teatro', 'horarios_grupos' => '3|A|tarde|17:00-18:00|xoves' ),
		);
		$sec = ANPA_Socios_Oferta_Seccions::agrupar( $rows );
		$this->assertCount( 2, $sec );
		$this->assertSame( 'manha', $sec[0]['horario'] );
		$this->assertSame( 'Actividades no horario de comedor (mediodía)', $sec[0]['titulo'] );
		$this->assertSame( array( 'Fútbol' ), array_column( $sec[0]['rows'], 'nome' ) );
		$this->assertSame( 'tarde', $sec[1]['horario'] );
		$this->assertSame( 'Actividades de tarde', $sec[1]['titulo'] );
		$this->assertSame( array( 'Xadrez', 'Teatro' ), array_column( $sec[1]['rows'], 'nome' ), 'input order is kept inside a section' );
		$this->assertSame( array(), ANPA_Socios_Oferta_Seccions::agrupar( array() ) );
		$this->assertSame( array( 'mana', 'comedor', 'tarde', 'outros' ), array_map( array( 'ANPA_Socios_Oferta_Seccions', 'clase' ), array( 'maña', 'manha', 'tarde', 'x' ) ) );
	}

	public function test_public_renderer_emits_one_titled_section_per_block_and_caps_the_grid_at_three(): void {
		$page = $this->src( 'includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringContainsString( 'ANPA_Socios_Oferta_Seccions::agrupar( $rows )', $page );
		$this->assertStringContainsString( '<section class="anpa-extra-seccion anpa-extra-seccion--', $page );
		$this->assertStringContainsString( '<h2 class="anpa-extra-seccion-titulo">', $page );
		// One grid per section, cards unchanged.
		$this->assertStringContainsString( '<div class="anpa-card-grid">', $page );

		$css = $this->src( 'assets/css/extraescolares.css' );
		$this->assertStringContainsString( 'minmax(260px, 1fr)', $css, 'narrow screens keep the auto-fit grid' );
		$this->assertStringContainsString( 'repeat(3, minmax(0, 1fr))', $css, 'never more than three cards per row' );
		$this->assertStringNotContainsString( 'repeat(4, 1fr)', $css );
		$this->assertStringContainsString( '.anpa-extra-seccion + .anpa-extra-seccion', $css, 'divider between sections' );
		$this->assertStringContainsString( '.anpa-extra-seccion-titulo', $css );
	}

	public function test_lib_is_wired(): void {
		foreach ( array( 'anpa-socios.php', 'tests/bootstrap.php' ) as $rel ) {
			$this->assertStringContainsString( 'class-anpa-socios-oferta-seccions.php', $this->src( $rel ), $rel );
		}
	}
}
