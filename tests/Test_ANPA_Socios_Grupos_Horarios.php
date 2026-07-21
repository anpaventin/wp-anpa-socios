<?php
/**
 * Tests for the read-only Grupos e horarios aggregate.
 *
 * @since 1.44.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupos_Horarios extends TestCase {

	public function test_admin_endpoint_is_read_only_master_gated_and_has_no_pii_joins(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-grupos-handler.php' );

		$this->assertStringContainsString( "'/grupos-horarios'", $source );
		$this->assertStringContainsString( "'callback'            => array( __CLASS__, 'list_grupos_horarios' )", $source );
		$this->assertStringContainsString( "'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' )", $source );

		$start = strpos( $source, 'public static function list_grupos_horarios' );
		$end   = strpos( $source, "\n	/**", $start + 1 );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$body = substr( $source, (int) $start, (int) $end - (int) $start );

		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $body );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_niveis()', $body );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_horarios_comedor()', $body );
		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_grupos_niveis()', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Grupos_Horarios::build', $body );
		$this->assertStringNotContainsString( 'tabela_fillos', $body );
		$this->assertStringNotContainsString( 'tabela_socios', $body );
		$this->assertStringNotContainsString( 'tabela_matriculas', $body );
	}

	public function test_builds_one_slot_per_group_level_and_day_without_pii(): void {
		$result = ANPA_Socios_Grupos_Horarios::build(
			'2026/2027',
			array(
				array(
					'id'              => 1,
					'codigo'          => '1',
					'etiqueta'        => '1º Primaria',
					'orde'            => 1,
					'horario_id'      => 7,
					'horario_nome'    => 'Quenda 1',
					'comedor_inicio'  => '14:00',
					'comedor_fin'     => '15:30',
				),
				array(
					'id'              => 2,
					'codigo'          => '2',
					'etiqueta'        => '2º Primaria',
					'orde'            => 2,
					'horario_id'      => null,
					'horario_nome'    => null,
					'comedor_inicio'  => null,
					'comedor_fin'     => null,
				),
			),
			array(
				array(
					'grupo_id'        => 10,
					'actividade_id'   => 5,
					'serie_uid'       => 'serie-5',
					'actividade_nome' => 'Robótica',
					'grupo_nome'      => 'Grupo A',
					'horario'         => 'tarde',
					'franxa'          => '16:00-17:00',
					'dias'            => 'luns,mercores',
					'estado'          => 'aberto',
					'nivel_id'        => 1,
				),
				array(
					'grupo_id'        => 11,
					'actividade_id'   => 6,
					'serie_uid'       => 'serie-6',
					'actividade_nome' => 'Teatro',
					'grupo_nome'      => 'Grupo B',
					'horario'         => 'manha',
					'franxa'          => '14:30-15:30',
					'dias'            => 'martes',
					'estado'          => 'pechado',
					'nivel_id'        => 1,
				),
			)
		);

		$this->assertSame( '2026/2027', $result['curso_escolar'] );
		$this->assertSame( array( '14:30-15:30', '16:00-17:00' ), $result['franxas'] );
		$this->assertSame( array( 'luns', 'martes', 'mercores', 'xoves', 'venres' ), array_keys( $result['dias'] ) );
		$this->assertCount( 2, $result['niveis'] );
		$this->assertSame(
			array( 'id' => 7, 'nome' => 'Quenda 1', 'inicio' => '14:00', 'fin' => '15:30' ),
			$result['niveis'][0]['comedor']
		);
		$this->assertNull( $result['niveis'][1]['comedor'] );
		$this->assertCount( 3, $result['slots'] );
		$this->assertSame( '10:1:luns', $result['slots'][0]['slot_key'] );
		$this->assertSame( 5, $result['slots'][0]['actividade_id'] );
		$this->assertSame( 10, $result['slots'][0]['grupo_id'] );
		$this->assertSame( 'serie-5', $result['slots'][0]['serie_uid'] );
		$this->assertFalse( $result['slots'][0]['conflito_comedor'] );
		$this->assertSame( '11:1:martes', $result['slots'][2]['slot_key'] );
		$this->assertTrue( $result['slots'][2]['conflito_comedor'] );
		$this->assertStringNotContainsString( 'fillo', strtolower( (string) json_encode( $result ) ) );
		$this->assertStringNotContainsString( 'familia', strtolower( (string) json_encode( $result ) ) );
	}

	public function test_invalid_course_fails_closed(): void {
		$this->assertSame( array(), ANPA_Socios_Grupos_Horarios::build( 'actual', array(), array() ) );
	}

	public function test_admin_renderer_uses_read_only_aggregate_and_accessible_dual_view(): void {
		$js  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-management.js' );
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/admin-management.css' );

		$start = strpos( $js, 'function loadGruposHorarios()' );
		$end   = strpos( $js, "\n	function readCsvFile", $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$body = substr( $js, (int) $start, (int) $end - (int) $start );

		$this->assertStringContainsString( "anpaAdminFetch('grupos-horarios?curso_escolar='", $body );
		$this->assertStringContainsString( 'cfg.cursosescolares', $body );
		$this->assertStringContainsString( 'anpa-grupos-horarios-grid', $body );
		$this->assertStringContainsString( 'anpa-grupos-horarios-list', $body );
		$this->assertStringContainsString( 'Non dispoñible por comedor', $body );
		$this->assertStringContainsString( 'Editar', $body );
		$this->assertStringContainsString( 'openGroupEditor', $body );
		$this->assertStringNotContainsString( 'Vista agrupada en preparación', $body );
		$this->assertStringNotContainsString( "method: 'POST'", $body );
		$this->assertStringNotContainsString( "method: 'PUT'", $body );
		$this->assertStringNotContainsString( "method: 'DELETE'", $body );
		$this->assertStringContainsString( '.anpa-grupos-horarios-grid', $css );
		$this->assertStringContainsString( '.anpa-grupos-horarios-list', $css );
		$this->assertStringNotContainsString( '!important', $css );
	}

	public function test_renderer_groups_levels_and_opens_the_exact_group_form(): void {
		$js       = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-management.js' );
		$css      = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/admin-management.css' );
		$settings = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-settings.php' );

		$this->assertStringContainsString( 'function groupSlotsByGroup', $js );
		$this->assertStringContainsString( 'nivel_ids', $js );
		$this->assertStringContainsString( "makeMetaLabel('Niveis'", $js );
		$this->assertStringContainsString( 'function openGroupEditor', $js );
		$this->assertStringContainsString( 'renderGrupoForm(targetGroup, targetActivity)', $js );
		$this->assertStringNotContainsString( 'pendingActivityGroupsId', $js );
		$this->assertStringNotContainsString( 'function openActivityGroups', $js );
		$this->assertStringContainsString( '.anpa-grupos-horarios-panel', $css );
		$this->assertStringContainsString( 'max-width: none;', $css );
		$this->assertStringContainsString( '.anpa-grupos-horarios-comedor', $css );
		$this->assertStringContainsString( '.anpa-grupos-horarios-free', $css );
		$this->assertStringContainsString( 'max-width: none;', $settings );
	}

	public function test_grid_can_start_a_canonical_group_for_the_selected_course(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-management.js' );

		$grid_start = strpos( $js, 'function loadGruposHorarios()' );
		$grid_end   = strpos( $js, "\n	function readCsvFile", $grid_start );
		$this->assertNotFalse( $grid_start );
		$this->assertNotFalse( $grid_end );
		$grid = substr( $js, (int) $grid_start, (int) $grid_end - (int) $grid_start );

		$this->assertStringContainsString( "newGroupBtn.textContent = 'Novo grupo'", $grid );
		$this->assertStringContainsString( 'openNewGroupEditor(state.curso)', $grid );
		$this->assertLessThan( strpos( $grid, "courseLabel.textContent = 'Curso escolar'" ), strpos( $grid, "newGroupBtn.textContent = 'Novo grupo'" ) );
		$this->assertStringContainsString( 'function openNewGroupEditor', $js );
		$this->assertStringContainsString( "anpaAdminFetch('actividades')", $js );
		$this->assertStringContainsString( 'actividad.cursos_ofertados.indexOf(course)', $js );
		$this->assertStringContainsString( 'renderGrupoForm(null, selectedActivity, course, true)', $js );
		$this->assertStringContainsString( 'preferredCourse', $js );
		$this->assertStringContainsString( 'returnToGrid ? loadGruposHorarios() : renderGruposPanel(actividad)', $js );
	}
}
