<?php
/**
 * Tests for the pure annual level-promotion domain.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

$promotion_class = __DIR__ . '/../includes/lib/class-anpa-socios-nivel-promotion.php';
if ( file_exists( $promotion_class ) ) {
	require_once $promotion_class;
}

final class Test_ANPA_Socios_Nivel_Promotion extends TestCase {

	public function test_age_uses_the_final_calendar_year_regardless_of_birthday(): void {
		$this->assertTrue( class_exists( 'ANPA_Socios_Nivel_Promotion' ), 'Promotion helper must exist.' );
		$this->assertSame( 9, ANPA_Socios_Nivel_Promotion::age_for_course( '2018-12-31', '2026/2027' ) );
		$this->assertSame( 9, ANPA_Socios_Nivel_Promotion::age_for_course( '2018-01-01', '2026/2027' ) );
	}

	public function test_target_for_age_returns_the_level_with_matching_order(): void {
		$levels = array(
			array( 'id' => 41, 'codigo' => '3º', 'orde' => 9 ),
			array( 'id' => 42, 'codigo' => '4º', 'orde' => 10 ),
		);

		$this->assertSame(
			array( 'status' => 'assigned', 'level' => $levels[0] ),
			ANPA_Socios_Nivel_Promotion::target_for_age( 9, $levels )
		);
	}

	public function test_age_above_the_highest_level_is_completed(): void {
		$levels = array(
			array( 'id' => 51, 'codigo' => '5º', 'orde' => 11 ),
			array( 'id' => 52, 'codigo' => '6º', 'orde' => 12 ),
		);

		$this->assertSame(
			array( 'status' => 'completed', 'max_age' => 12 ),
			ANPA_Socios_Nivel_Promotion::target_for_age( 13, $levels )
		);
	}

	public function test_duplicate_level_age_is_rejected(): void {
		$levels = array(
			array( 'id' => 61, 'codigo' => '3º', 'orde' => 9 ),
			array( 'id' => 62, 'codigo' => 'Outro', 'orde' => 9 ),
		);

		$this->assertSame(
			array( 'status' => 'error', 'code' => 'duplicate_age', 'age' => 9 ),
			ANPA_Socios_Nivel_Promotion::target_for_age( 9, $levels )
		);
	}

	public function test_annual_assignment_writer_supports_explicit_no_level_state(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-db.php' );
		$start  = strpos( $source, 'public static function upsert_fillo_curso_assignment' );
		$end    = strpos( $source, '/**', $start + 10 );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( "if ( '' === \$curso )", $method );
		$this->assertStringContainsString( "\$nivel_id = null", $method );
		$this->assertStringContainsString( "\$aula_id  = null", $method );
		$this->assertStringNotContainsString( "|| '' === \$curso ||", $method );
	}

	public function test_build_plan_assigns_level_by_age_and_preserves_classroom(): void {
		$levels = array(
			array( 'id' => 71, 'codigo' => '3º', 'orde' => 9 ),
			array( 'id' => 72, 'codigo' => '4º', 'orde' => 10 ),
		);
		$children = array(
			array(
				'fillo_id'        => 5,
				'data_nacemento' => '2018-05-20',
				'aula'            => 'B',
				'principal_email' => 'familia@example.test',
				'nivel_id'        => 0,
				'curso'           => '',
			),
		);

		$plan = ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $levels, $children );

		$this->assertSame( 'ready', $plan['status'] );
		$this->assertSame( 71, $plan['items'][0]['nivel_id'] );
		$this->assertSame( '3º', $plan['items'][0]['curso'] );
		$this->assertSame( 'B', $plan['items'][0]['aula'] );
		$this->assertSame( 'update', $plan['items'][0]['action'] );
	}

	public function test_build_plan_rejects_an_active_course_without_levels_even_without_children(): void {
		$this->assertSame(
			array( 'status' => 'error', 'code' => 'no_levels' ),
			ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', array(), array() )
		);
	}

	public function test_build_plan_rejects_duplicate_ages_even_without_children(): void {
		$levels = array(
			array( 'id' => 81, 'codigo' => '3º', 'orde' => 9 ),
			array( 'id' => 82, 'codigo' => 'Outro', 'orde' => 9 ),
		);

		$this->assertSame(
			array( 'status' => 'error', 'code' => 'duplicate_age', 'age' => 9 ),
			ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $levels, array() )
		);
	}

	public function test_build_plan_is_idempotent_when_the_annual_assignment_is_already_correct(): void {
		$levels = array( array( 'id' => 81, 'codigo' => '3º', 'orde' => 9 ) );
		$children = array(
			array(
				'fillo_id'        => 21,
				'data_nacemento' => '2018-12-31',
				'aula'            => 'C',
				'principal_email' => 'familia@example.test',
				'principal_count' => 1,
				'nivel_id'        => 81,
				'curso'           => '3º',
			),
		);

		$plan = ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $levels, $children );

		$this->assertSame( 'ready', $plan['status'] );
		$this->assertSame( 'unchanged', $plan['items'][0]['action'] );
		$this->assertSame( 'C', $plan['items'][0]['aula'] );
		$this->assertSame( array(), $plan['emails_cco'] );
	}

	public function test_completed_children_preserve_classroom_and_deduplicate_sorted_bcc_emails(): void {
		$levels = array( array( 'id' => 82, 'codigo' => '6º', 'orde' => 12 ) );
		$children = array(
			array( 'fillo_id' => 22, 'data_nacemento' => '2014-01-02', 'aula' => 'B', 'principal_email' => 'Zeta@Example.test', 'principal_count' => 1, 'nivel_id' => 82, 'curso' => '6º' ),
			array( 'fillo_id' => 23, 'data_nacemento' => '2014-11-30', 'aula' => 'A', 'principal_email' => 'zeta@example.test', 'principal_count' => 1, 'nivel_id' => 0, 'curso' => '' ),
			array( 'fillo_id' => 24, 'data_nacemento' => '2013-04-10', 'aula' => 'D', 'principal_email' => 'alfa@example.test', 'principal_count' => 1, 'nivel_id' => 82, 'curso' => '6º' ),
		);

		$plan = ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $levels, $children );

		$this->assertSame( array( 'alfa@example.test', 'zeta@example.test' ), $plan['emails_cco'] );
		$this->assertSame( array( 'completed', 'unchanged_completed', 'completed' ), array_column( $plan['items'], 'action' ) );
		$this->assertSame( array( 'B', 'A', 'D' ), array_column( $plan['items'], 'aula' ) );
		$this->assertSame( array( '', '', '' ), array_column( $plan['items'], 'curso' ) );
	}

	public function test_build_plan_requires_exactly_one_active_principal(): void {
		$levels = array( array( 'id' => 91, 'codigo' => '3º', 'orde' => 9 ) );
		$children = array(
			array(
				'fillo_id'        => 15,
				'data_nacemento' => '2018-03-04',
				'aula'            => 'A',
				'principal_email' => 'familia@example.test',
				'principal_count' => 2,
			),
		);

		$this->assertSame(
			array( 'status' => 'error', 'code' => 'invalid_principal_count', 'fillo_id' => 15 ),
			ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $levels, $children )
		);
	}

	public function test_loader_keeps_active_children_without_an_active_principal_so_preflight_can_fail_closed(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-nivel-promotion-service.php' );
		$start  = strpos( $source, 'private static function load_children' );
		$end    = strpos( $source, '/**', $start + 20 );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( 'LEFT JOIN (', $method );
		$this->assertStringContainsString( 'COALESCE(principals.principal_count, 0) AS principal_count', $method );
		$this->assertStringNotContainsString( 'INNER JOIN (', $method );
	}

	public function test_service_contract_is_transactional_and_uses_the_canonical_writer(): void {
		$file = __DIR__ . '/../includes/class-anpa-socios-nivel-promotion-service.php';
		$this->assertFileExists( $file );
		$source = file_get_contents( $file );

		$this->assertStringContainsString( 'START TRANSACTION', $source );
		$this->assertStringContainsString( 'FOR UPDATE', $source );
		$this->assertStringContainsString( 'ANPA_Socios_DB::upsert_fillo_curso_assignment', $source );
		$this->assertStringContainsString( "\$wpdb->query( 'ROLLBACK' )", $source );
		$this->assertStringContainsString( "\$wpdb->query( 'COMMIT' )", $source );
	}

	public function test_service_rechecks_the_locked_active_course_before_writing(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-nivel-promotion-service.php' );

		$this->assertStringContainsString( 'ANPA_Socios_DB::tabela_cursos()', $source );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get() !== $school_year', $source );
	}

	public function test_settings_ui_explains_season_and_wires_private_level_update_result(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-settings.php' );

		$this->assertStringContainsString( 'admin_post_anpa_socios_update_child_levels', $source );
		$this->assertStringContainsString( 'Actualizar niveis dos fillos', $source );
		$this->assertStringContainsString( 'non activa automaticamente o curso seguinte', $source );
		$this->assertStringContainsString( "set_transient( self::promotion_result_key()", $source );
		$this->assertStringContainsString( "delete_transient( self::promotion_result_key()", $source );
		$this->assertStringContainsString( 'anpa_socios_update_child_levels', $source );
	}

	public function test_only_the_level_order_header_is_renamed_to_student_age(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-estrutura-escolar-page.php' );

		$this->assertSame( 1, substr_count( $source, "esc_html__( 'Idade alumnado', 'anpa-socios' )" ) );
		$this->assertStringContainsString( "esc_html__( 'Orde', 'anpa-socios' )", $source );
	}

	public function test_level_age_must_be_unique_in_server_and_client_bulk_validation(): void {
		$handler = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-estrutura-handler.php' );
		$page    = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-estrutura-escolar-page.php' );

		$this->assertStringContainsString( '$seen_ages', $handler );
		$this->assertStringContainsString( 'Non pode haber dous niveis coa mesma idade do alumnado.', $handler );
		$this->assertStringContainsString( 'Non pode haber dous niveis coa mesma idade do alumnado.', $page );
	}

	public function test_level_age_input_is_a_strict_positive_integer_without_coercion_or_fallback(): void {
		$handler = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-estrutura-handler.php' );

		$this->assertStringContainsString( 'private static function positive_integer_input', $handler );
		$this->assertGreaterThanOrEqual( 2, substr_count( $handler, 'self::positive_integer_input' ) );
		$this->assertStringNotContainsString( '$age = absint(', $handler );
		$this->assertStringNotContainsString( 'Default orde to the end of the list', $handler );
	}

	public function test_lock_rows_does_not_reference_the_retired_niveis_curso_escolar_column(): void {
		// Since 1.35.0 the niveis table is global (no curso_escolar column).
		// lock_rows() must lock active levels without that column, or the whole
		// promotion transaction would error out and always roll back.
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-nivel-promotion-service.php' );
		$start  = strpos( $source, 'private static function lock_rows' );
		$end    = strpos( $source, "\n\t}", $start );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( "SELECT id FROM {\$levels} WHERE estado = 'activo' ORDER BY id FOR UPDATE", $method );
		$this->assertStringNotContainsString( "{\$levels} WHERE curso_escolar", $method );
	}
}
