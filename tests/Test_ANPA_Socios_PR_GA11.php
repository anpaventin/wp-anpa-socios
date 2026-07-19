<?php
/**
 * PR-GA11 contracts: active-course activity UX and public capacity labels.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ): string {
		unset( $domain );
		return esc_html( $text );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string {
		unset( $domain );
		return (string) $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return esc_html( $text );
	}
}

final class Test_ANPA_Socios_PR_GA11 extends TestCase {

	private string $js;
	private string $page;
	private string $management_page;
	private string $handler;

	protected function setUp(): void {
		$root                  = dirname( __DIR__ );
		require_once $root . '/includes/class-anpa-socios-extraescolares-page.php';
		$this->js              = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
		$this->page            = (string) file_get_contents( $root . '/includes/class-anpa-socios-extraescolares-page.php' );
		$this->management_page = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-management-page.php' );
		$this->handler         = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-actividades-handler.php' );
	}

	private function validGroupPayload(): array {
		return array(
			'nome'           => 'Grupo 1-2-3',
			'cursos'         => array( '2026/2027' ),
			'niveis_por_ano' => array( '2026/2027' => array( 1, 2, 3 ) ),
			'horario'        => 'manha',
			'franxa'         => '14:10-15:10',
			'dias'           => array( 'luns', 'venres' ),
			'estado'         => 'aberto',
		);
	}

	public function test_group_defaults_are_ten_and_fifteen_when_omitted(): void {
		$result = ANPA_Socios_Grupo_Serie::normalize( $this->validGroupPayload() );

		$this->assertSame( 10, $result['min_pupilos'] );
		$this->assertSame( 15, $result['max_pupilos'] );
	}

	public function test_activity_list_uses_requested_columns_and_active_course_filter(): void {
		$this->assertStringContainsString(
			"var ACTIV_COLS = ['_empresa_nome', 'nome', 'custo', 'estado'];",
			$this->js
		);
		$this->assertStringContainsString( "'cursoactivo'", $this->management_page );
		$this->assertStringContainsString( 'cfg.cursoactivo', $this->js );
		$this->assertStringContainsString( 'r.cursos_ofertados.indexOf(activeCourse) !== -1', $this->js );
	}

	public function test_activity_form_preserves_history_but_only_edits_active_course(): void {
		$this->assertStringContainsString( 'Cursos nos que se ofertou', $this->js );
		$this->assertStringContainsString( "document.createElement('details')", $this->js );
		$this->assertStringContainsString( 'historicalCourses.concat(activeSelection)', $this->js );
		$this->assertStringContainsString( 'activeCourseCheckbox.disabled = !isEdit;', $this->js );
		$this->assertStringContainsString( 'Desmarca para retirar a oferta deste curso', $this->js );
		$this->assertStringContainsString( 'validated_cursos_for_activity', $this->handler );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $this->handler );
		$this->assertStringContainsString( 'Só se pode engadir o curso activo á oferta.', $this->handler );
	}

	public function test_every_empty_search_result_rewires_input_before_return(): void {
		$lines = preg_split( '/\R/', $this->js );
		$this->assertIsArray( $lines );
		$emptyReturns = 0;
		foreach ( $lines as $index => $line ) {
			if ( false === strpos( $line, 'if (!sorted.length)' ) ) {
				continue;
			}
			++$emptyReturns;
			$window = implode( "\n", array_slice( $lines, max( 0, $index - 8 ), 9 ) );
			$this->assertStringContainsString( 'wireSearchInput(', $window, 'Empty-result branch must be wired before returning.' );
		}
		$this->assertSame( 6, $emptyReturns );
	}

	public function test_offered_card_compacts_schedule_and_shows_group_minimum(): void {
		$this->assertStringContainsString( 'esc_html( $part[\'franxa\'] )', $this->page );
		$this->assertStringNotContainsString( 'esc_html( $part[\'horario\'] )', $this->page );
		$this->assertStringContainsString( 'Mínimo de %d para crear grupo.', $this->page );
	}

	public function test_offered_card_shows_places_and_minimum_inside_each_group(): void {
		$method = new ReflectionMethod( ANPA_Socios_Extraescolares_Page::class, 'schedule_detail_html' );
		$method->setAccessible( true );

		$html = $method->invoke( null, array(
			'horarios_grupos' => '41|Grupo 4-5-6|manha|14:10-15:10|martes,xoves;;42|Grupo 1-2-3|maña|15:10-16:10|martes,xoves',
			'grupos_detail'   => json_encode( array(
				array( 'id' => 41, 'nome' => 'Grupo 4-5-6', 'min_pupilos' => 6, 'max_pupilos' => 15, 'activos' => 0, 'espera' => 0 ),
				array( 'id' => 42, 'nome' => 'Grupo 1-2-3', 'min_pupilos' => 10, 'max_pupilos' => 15, 'activos' => 0, 'espera' => 0 ),
			) ),
		) );

		$first_group  = strpos( $html, 'Grupo 4-5-6' );
		$first_places = strpos( $html, 'Mínimo de 6 para crear grupo.' );
		$second_group = strpos( $html, 'Grupo 1-2-3' );
		$second_places = strpos( $html, 'Mínimo de 10 para crear grupo.' );

		$this->assertNotFalse( $first_group );
		$this->assertNotFalse( $first_places );
		$this->assertNotFalse( $second_group );
		$this->assertNotFalse( $second_places );
		$this->assertGreaterThan( $first_group, $first_places );
		$this->assertGreaterThan( $first_places, $second_group );
		$this->assertGreaterThan( $second_group, $second_places );
		$this->assertSame( 2, substr_count( $html, 'Prazas:' ) );
		$this->assertSame( 2, substr_count( $html, '/15' ) );
		$this->assertStringNotContainsString( '/30', $html );
		$this->assertStringNotContainsString( 'segundo o grupo', $html );
	}

	public function test_group_name_with_pipe_does_not_corrupt_association(): void {
		$method = new ReflectionMethod( ANPA_Socios_Extraescolares_Page::class, 'schedule_detail_html' );
		$method->setAccessible( true );

		$html = $method->invoke( null, array(
			'horarios_grupos' => '7|Grupo A|B|manha|14:10-15:10|martes,xoves',
			'grupos_detail'   => json_encode( array(
				array( 'id' => 7, 'nome' => 'Grupo A|B', 'min_pupilos' => 9, 'max_pupilos' => 15, 'activos' => 0, 'espera' => 0 ),
			) ),
		) );

		$label  = strpos( $html, 'Grupo A|B' );
		$places = strpos( $html, 'Mínimo de 9 para crear grupo.' );
		$this->assertNotFalse( $label );
		$this->assertNotFalse( $places );
		$this->assertGreaterThan( $label, $places );
		$this->assertStringContainsString( 'Martes, Xoves', $html );
		$this->assertStringNotContainsString( 'Grupo B|manha', $html );
	}

	public function test_schedule_query_and_builder_carry_active_capacity_without_pii(): void {
		$this->assertStringContainsString( 'COUNT(DISTINCT CASE WHEN m.estado', $this->page );
		$this->assertStringContainsString( 'g.max_pupilos', $this->page );

		$grid = ANPA_Socios_Horario_Builder::build( array(
			array(
				'nome'        => 'Francés',
				'grupo_nome'  => 'Grupo 1-2-3',
				'horario'     => 'manha',
				'franxa'      => '14:10-15:10',
				'dias'        => 'luns',
				'activos'     => 8,
				'max_pupilos' => 15,
			),
		) );

		$this->assertSame( array( 'Grupo 1-2-3 — Comedor 8/15' ), $grid[0]['dias']['luns'][0]['grupos'] );
		$this->assertArrayNotHasKey( 'email', $grid[0]['dias']['luns'][0] );
	}
}
