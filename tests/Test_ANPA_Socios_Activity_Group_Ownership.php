<?php
/**
 * Revised fase24 activity contract: operational scheduling lives in groups.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Activity_Group_Ownership extends TestCase {

	public function test_activity_payload_needs_no_primary_year_or_schedule_fields(): void {
		$result = ANPA_Socios_Admin_Payload::validar_actividad( array(
			'empresa_id'  => 7,
			'nome'        => 'Patinaxe',
			'descripcion' => 'Actividade de patinaxe',
			'icono'       => '⛸️',
			'cursos'      => array( '2025/2026', '2026/2027' ),
			'custo'       => '20.00',
			'estado'      => 'activo',
		) );

		$this->assertNotNull( $result );
		$this->assertSame( 7, $result['empresa_id'] );
		$this->assertSame( 'Patinaxe', $result['nome'] );
		foreach ( array( 'curso_escolar', 'cursos', 'franxa', 'horarios', 'grupos', 'dias', 'curso_min', 'curso_max', 'min_pupilos', 'max_pupilos' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $result );
		}
	}

	public function test_activity_payload_ignores_legacy_schedule_values(): void {
		$result = ANPA_Socios_Admin_Payload::validar_actividad( array(
			'empresa_id'    => 7,
			'nome'          => 'Patinaxe',
			'descripcion'   => 'Actividade',
			'cursos'        => array( '2025/2026', '2026/2027' ),
			'curso_escolar' => '2024/2025',
			'franxa'        => '09:00-10:00',
			'horarios'      => array( 'manha' ),
			'grupos'        => array( '1-2-3' ),
			'dias'          => array( 'luns' ),
			'curso_min'     => 1,
			'curso_max'     => 6,
			'min_pupilos'   => 10,
			'max_pupilos'   => 20,
			'custo'         => '20.00',
			'estado'        => 'activo',
		) );

		$this->assertNotNull( $result );
		foreach ( array( 'curso_escolar', 'cursos', 'franxa', 'horarios', 'grupos', 'dias', 'curso_min', 'curso_max', 'min_pupilos', 'max_pupilos' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $result );
		}
	}

	public function test_admin_activity_ui_only_shows_activity_owned_fields(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-management.js' );

		$this->assertStringContainsString(
			"var ACTIV_COLS = ['_empresa_nome', 'nome', 'custo', 'estado'];",
			$js
		);
		foreach ( array(
			'Curso escolar (primario)',
			'Niveis mínimo/máximo por curso escolar',
			"franxaLabel.textContent = 'Franxa'",
			"horariosLabel.textContent = 'Horarios'",
			"gruposLabel.textContent = 'Grupos curriculares'",
			"diasLabel.textContent = 'Días'",
			'Límites antigos de curso',
		) as $removed ) {
			$this->assertStringNotContainsString( $removed, $js );
		}
	}

	public function test_activity_has_no_manual_course_offer_writer(): void {
		$php = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-actividades-handler.php' );
		$this->assertStringNotContainsString( 'sync_actividad_cursos', $php );
		$this->assertStringNotContainsString( 'validated_cursos_for_activity', $php );
		$this->assertStringNotContainsString( 'tabela_actividades_cursos', $php );
	}
}
