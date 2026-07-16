<?php
/**
 * Revised fase24 public schedule contracts: only real annual groups provide
 * horario, franxa and dias.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_PR_ES9_Horario extends TestCase {

	private function activeActivity(): array {
		return array( 'estado' => 'activo', 'curso_estado' => 'activo' );
	}

	private function openGroup( array $replace = array() ): array {
		return array_replace(
			array( 'estado' => 'aberto', 'franxa' => '16:00-17:00', 'dias' => 'luns,martes' ),
			$replace
		);
	}

	public function test_diagnose_includes_valid_open_group(): void {
		$this->assertSame(
			'incluida_por_grupo',
			ANPA_Socios_Horario_Builder::diagnose( $this->activeActivity(), array( $this->openGroup() ), true )
		);
	}

	public function test_diagnose_never_synthesizes_group_from_activity_fields(): void {
		$activity = array_merge( $this->activeActivity(), array( 'franxa' => '16:00-17:00', 'dias' => 'luns' ) );
		$this->assertSame( 'sen_grupo_aberto', ANPA_Socios_Horario_Builder::diagnose( $activity, array(), true ) );
	}

	public function test_diagnose_validates_franxa_on_open_group(): void {
		$this->assertSame(
			'sen_franxa',
			ANPA_Socios_Horario_Builder::diagnose( $this->activeActivity(), array( $this->openGroup( array( 'franxa' => 'tarde' ) ) ), true )
		);
	}

	public function test_diagnose_validates_dias_on_open_group(): void {
		$this->assertSame(
			'sen_dias',
			ANPA_Socios_Horario_Builder::diagnose( $this->activeActivity(), array( $this->openGroup( array( 'dias' => '' ) ) ), true )
		);
	}

	public function test_diagnose_rejects_closed_only_groups(): void {
		$this->assertSame(
			'sen_grupo_aberto',
			ANPA_Socios_Horario_Builder::diagnose( $this->activeActivity(), array( $this->openGroup( array( 'estado' => 'pechado' ) ) ), true )
		);
	}

	public function test_diagnose_respects_activity_and_course_state(): void {
		$this->assertSame( 'estado_inactivo', ANPA_Socios_Horario_Builder::diagnose( array( 'estado' => 'inactivo', 'curso_estado' => 'activo' ), array( $this->openGroup() ), true ) );
		$this->assertSame( 'curso_non_activo', ANPA_Socios_Horario_Builder::diagnose( $this->activeActivity(), array( $this->openGroup() ), false ) );
	}

	public function test_builder_labels_real_group_and_horario(): void {
		$grid = ANPA_Socios_Horario_Builder::build( array(
			array(
				'nome'       => 'Xadrez',
				'grupo_nome' => 'Grupo iniciación',
				'horario'    => 'manha',
				'franxa'     => '14:20-15:10',
				'dias'       => 'luns,mercores',
			),
		) );
		$this->assertSame( array( 'Grupo iniciación — Comedor' ), $grid[0]['dias']['luns'][0]['grupos'] );
	}

	public function test_public_query_has_no_provisional_activity_slot(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-extraescolares-page.php' );
		$this->assertStringNotContainsString( 'NOT EXISTS', $src );
		$this->assertStringNotContainsString( "'' AS grupos", $src );
		$this->assertStringContainsString( 'g.nome AS grupo_nome', $src );
		$this->assertStringContainsString( "g.horario IN ('maña','manha','tarde')", $src );
	}

	public function test_horario_diagnostic_route_remains_registered(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-actividades-handler.php' );
		$this->assertStringContainsString( 'horario-diagnostic', $src );
		$this->assertStringContainsString( 'horario_diagnostic', $src );
	}
}
