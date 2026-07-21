<?php
/**
 * Tests for the activity projection derived exclusively from annual groups.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

$projection_class = __DIR__ . '/../includes/lib/class-anpa-socios-activity-group-projection.php';
if ( file_exists( $projection_class ) ) {
	require_once $projection_class;
}

final class Test_ANPA_Socios_Activity_Group_Projection extends TestCase {

	public function test_activity_without_groups_remains_administrable_and_has_no_offered_course(): void {
		$this->assertTrue( class_exists( 'ANPA_Socios_Activity_Group_Projection' ) );
		$rows = ANPA_Socios_Activity_Group_Projection::build(
			array( array( 'id' => 7, 'nome' => 'Teatro', 'estado' => 'activo' ) ),
			array(),
			'2026/2027'
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( array(), $rows[0]['cursos_con_grupos'] );
		$this->assertFalse( $rows[0]['ten_grupo_curso_activo'] );
		$this->assertFalse( $rows[0]['ten_grupo_aberto_curso_activo'] );
		$this->assertSame( 'sen_grupos_no_curso_activo', $rows[0]['estado_curso_activo'] );
	}

	public function test_courses_and_current_state_are_derived_from_groups(): void {
		$rows = ANPA_Socios_Activity_Group_Projection::build(
			array( array( 'id' => 7, 'nome' => 'Teatro', 'estado' => 'activo' ) ),
			array(
				array( 'actividad_id' => 7, 'curso_escolar' => '2026/2027', 'estado' => 'pechado' ),
				array( 'actividad_id' => 7, 'curso_escolar' => '2025/2026', 'estado' => 'aberto' ),
				array( 'actividad_id' => 7, 'curso_escolar' => '2026/2027', 'estado' => 'aberto' ),
				array( 'actividad_id' => 7, 'curso_escolar' => '2025/2026', 'estado' => 'pechado' ),
			),
			'2026/2027'
		);

		$this->assertSame( array( '2025/2026', '2026/2027' ), $rows[0]['cursos_con_grupos'] );
		$this->assertTrue( $rows[0]['ten_grupo_curso_activo'] );
		$this->assertTrue( $rows[0]['ten_grupo_aberto_curso_activo'] );
		$this->assertSame( 2, $rows[0]['grupos_curso_activo'] );
		$this->assertSame( 1, $rows[0]['grupos_abertos_curso_activo'] );
		$this->assertSame( 'con_grupos_abertos', $rows[0]['estado_curso_activo'] );
	}

	public function test_global_inactive_state_has_precedence_over_open_groups(): void {
		$rows = ANPA_Socios_Activity_Group_Projection::build(
			array( array( 'id' => 7, 'nome' => 'Teatro', 'estado' => 'inactivo' ) ),
			array( array( 'actividad_id' => 7, 'curso_escolar' => '2026/2027', 'estado' => 'aberto' ) ),
			'2026/2027'
		);

		$this->assertSame( 'inactiva', $rows[0]['estado_curso_activo'] );
		$this->assertTrue( $rows[0]['ten_grupo_aberto_curso_activo'] );
	}

	public function test_only_closed_current_groups_have_a_distinct_projection_state(): void {
		$rows = ANPA_Socios_Activity_Group_Projection::build(
			array( array( 'id' => 8, 'nome' => 'Xadrez', 'estado' => 'activo' ) ),
			array( array( 'actividad_id' => 8, 'curso_escolar' => '2026/2027', 'estado' => 'pechado' ) ),
			'2026/2027'
		);

		$this->assertSame( 'con_grupos_pechados', $rows[0]['estado_curso_activo'] );
		$this->assertTrue( $rows[0]['ten_grupo_curso_activo'] );
		$this->assertFalse( $rows[0]['ten_grupo_aberto_curso_activo'] );
	}

	public function test_admin_activity_list_reads_annual_presence_from_groups_not_activity_courses(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-actividades-handler.php' );
		$start  = strpos( $source, 'public static function list_actividades' );
		$end    = strpos( $source, 'public static function create_actividad', $start );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( 'ANPA_Socios_Activity_Group_Projection::build', $method );
		$this->assertStringContainsString( 'SELECT actividad_id, curso_escolar, estado', $method );
		$this->assertStringNotContainsString( 'tabela_actividades_cursos', $method );
		$this->assertStringNotContainsString( 'ANPA_Socios_Actividades_Collapse', $method );
	}

	public function test_activity_create_update_and_get_row_do_not_write_manual_course_offers(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-actividades-handler.php' );
		$start  = strpos( $source, 'public static function create_actividad' );
		$end    = strpos( $source, 'public static function delete_actividad', $start );
		$writers = substr( $source, $start, $end - $start );
		$get_start = strpos( $source, 'private static function get_row' );
		$get_end   = strpos( $source, '/**', $get_start + 20 );
		$get_row   = substr( $source, $get_start, $get_end - $get_start );

		$this->assertStringNotContainsString( 'validated_cursos', $writers );
		$this->assertStringNotContainsString( 'sync_actividad_cursos', $writers );
		$this->assertStringNotContainsString( 'tabela_actividades_cursos', $writers . $get_row );
		$this->assertStringContainsString( 'START TRANSACTION', $writers );
		$this->assertStringContainsString( 'COMMIT', $writers );
	}
}
