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

	public function test_age_above_the_highest_level_is_capped_to_the_last_level(): void {
		$levels = array(
			array( 'id' => 51, 'codigo' => '5º', 'orde' => 11 ),
			array( 'id' => 52, 'codigo' => '6º', 'orde' => 12 ),
		);

		// Older than 6º by age: the child stays in 6º (never removed automatically).
		$this->assertSame(
			array( 'status' => 'capped', 'level' => $levels[1], 'max_age' => 12 ),
			ANPA_Socios_Nivel_Promotion::target_for_age( 13, $levels )
		);
		// Younger than the first level is still a hard error (fail closed).
		$this->assertSame(
			array( 'status' => 'error', 'code' => 'missing_age' ),
			ANPA_Socios_Nivel_Promotion::target_for_age( 10, $levels )
		);
	}

	public function test_promotion_uses_birth_year_and_keeps_sixth_graders_in_sixth(): void {
		// Spanish rule for 2026/2027: 1º = born 2020 (turn 7 in 2027) ... 6º = born 2015.
		$levels = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$levels[] = array( 'id' => 100 + $i, 'codigo' => $i . 'º', 'orde' => 6 + $i );
		}
		$children = array();
		foreach ( array( 2020 => '1º', 2019 => '2º', 2015 => '6º', 2014 => '6º' ) as $year => $expected ) {
			$children[] = array( 'fillo_id' => $year, 'data_nacemento' => $year . '-12-01', 'aula' => 'A', 'principal_email' => 'f' . $year . '@example.test', 'principal_count' => 1, 'nivel_id' => 0, 'curso' => '' );
		}
		$plan = ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $levels, $children );

		$this->assertSame( 'ready', $plan['status'] );
		$this->assertSame( array( '1º', '2º', '6º', '6º' ), array_column( $plan['items'], 'curso' ) );
		$this->assertSame( array( 'update', 'update', 'update', 'capped' ), array_column( $plan['items'], 'action' ) );
		$this->assertSame( array( 'f2014@example.test' ), $plan['emails_cco'] );

		$summary = ANPA_Socios_Nivel_Promotion::summarize_plan( $plan );
		$this->assertSame( 4, $summary['actualizados'] );
		$this->assertSame( 0, $summary['sen_cambios'] );
		$this->assertSame( 1, $summary['no_ultimo_nivel'] );
		$this->assertSame( array( '1º' => 1, '2º' => 1, '6º' => 2 ), $summary['por_curso'] );
		$this->assertSame( '', $summary['cambios'][0]['curso_anterior'] );
		$this->assertSame( 'capped', $summary['cambios'][3]['accion'] );
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
		// 1.54.0: a level change clears the classroom letter; the family re-enters it.
		$this->assertSame( '', $plan['items'][0]['aula'] );
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

	public function test_capped_children_stay_in_last_level_preserve_classroom_and_deduplicate_sorted_bcc_emails(): void {
		$levels = array( array( 'id' => 82, 'codigo' => '6º', 'orde' => 12 ) );
		$children = array(
			array( 'fillo_id' => 22, 'data_nacemento' => '2014-01-02', 'aula' => 'B', 'principal_email' => 'Zeta@Example.test', 'principal_count' => 1, 'nivel_id' => 82, 'curso' => '6º' ),
			array( 'fillo_id' => 23, 'data_nacemento' => '2014-11-30', 'aula' => 'A', 'principal_email' => 'zeta@example.test', 'principal_count' => 1, 'nivel_id' => 0, 'curso' => '' ),
			array( 'fillo_id' => 24, 'data_nacemento' => '2013-04-10', 'aula' => 'D', 'principal_email' => 'alfa@example.test', 'principal_count' => 1, 'nivel_id' => 82, 'curso' => '6º' ),
		);

		$plan = ANPA_Socios_Nivel_Promotion::build_plan( '2026/2027', $levels, $children );

		$this->assertSame( array( 'alfa@example.test', 'zeta@example.test' ), $plan['emails_cco'] );
		$this->assertSame( array( 'unchanged_capped', 'capped', 'unchanged_capped' ), array_column( $plan['items'], 'action' ) );
		// Only the child whose level actually changes (capped) loses the letter.
		$this->assertSame( array( 'B', '', 'D' ), array_column( $plan['items'], 'aula' ) );
		$this->assertSame( array( '6º', '6º', '6º' ), array_column( $plan['items'], 'curso' ) );
		$this->assertSame( array( 82, 82, 82 ), array_column( $plan['items'], 'nivel_id' ) );
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
		// 1.49.6: single button. Step 1 always simulates; step 2 applies only the reviewed plan (fingerprint).
		$this->assertStringContainsString( 'admin_post_anpa_socios_apply_child_levels', $source );
		$this->assertStringContainsString( 'plan_fingerprint', $source );
		$this->assertStringNotContainsString( 'anpa_socios_preview_child_levels', $source );
		$service = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-nivel-promotion-service.php' );
		$this->assertStringContainsString( 'string $expected_fingerprint', $service );
		$this->assertStringContainsString( 'anpa_nivel_promotion_plan_changed', $service );
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
