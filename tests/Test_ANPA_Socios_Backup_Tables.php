<?php
/**
 * Structural assertion tests for ANPA_Socios_Backup::tables().
 *
 * Verifies that niveis, aulas, and grupos_niveis are included in the backup
 * at correct relative positions respecting FK order (task 52, PR-ES6).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-anpa-socios-backup.php';

final class Test_ANPA_Socios_Backup_Tables extends TestCase {

	private function get_table_keys(): array {
		$reflection = new ReflectionClass( ANPA_Socios_Backup::class );
		$method     = $reflection->getMethod( 'tables' );
		$method->setAccessible( true );
		return array_keys( $method->invoke( null ) );
	}

	public function test_backup_includes_horarios_niveis_aulas_grupos_niveis(): void {
		$keys = $this->get_table_keys();

		$this->assertContains( 'horarios_comedor', $keys );
		$this->assertContains( 'niveis', $keys );
		$this->assertContains( 'aulas', $keys );
		$this->assertContains( 'grupos_niveis', $keys );
	}

	public function test_current_backup_format_is_v8_with_per_course_comedor_pivot(): void {
		$this->assertSame( 8, ANPA_Socios_Backup::VERSION );
		$keys = $this->get_table_keys();
		$this->assertNotContains( 'actividades_cursos', $keys );
		$this->assertContains( 'niveis_curso', $keys );
	}

	public function test_old_backup_restore_strips_retired_niveis_comedor_columns(): void {
		$reflection = new ReflectionClass( ANPA_Socios_Backup::class );
		$method     = $reflection->getMethod( 'normalize_restore_row' );
		$method->setAccessible( true );
		$row = array( 'id' => 3, 'codigo' => '1', 'horario_comedor_id' => 5, 'comedor_inicio' => '13:00', 'comedor_fin' => '14:00' );
		$normalized = $method->invoke( null, 'niveis', $row, 7 );
		$this->assertSame( 3, $normalized['id'] );
		$this->assertSame( '1', $normalized['codigo'] );
		foreach ( array( 'horario_comedor_id', 'comedor_inicio', 'comedor_fin' ) as $col ) {
			$this->assertArrayNotHasKey( $col, $normalized );
		}
	}

	public function test_niveis_curso_pivot_after_niveis_and_horarios(): void {
		$keys              = $this->get_table_keys();
		$pos_niveis        = array_search( 'niveis', $keys, true );
		$pos_horarios      = array_search( 'horarios_comedor', $keys, true );
		$pos_niveis_curso  = array_search( 'niveis_curso', $keys, true );

		$this->assertIsInt( $pos_niveis_curso );
		$this->assertLessThan( $pos_niveis_curso, $pos_niveis, 'niveis must come before niveis_curso (FK: nivel_id)' );
		$this->assertLessThan( $pos_niveis_curso, $pos_horarios, 'horarios_comedor must come before niveis_curso (FK: horario_comedor_id)' );
	}

	public function test_v5_restore_preserves_menu_name_and_v4_uses_default(): void {
		$reflection = new ReflectionClass( ANPA_Socios_Backup::class );
		$this->assertTrue( $reflection->hasMethod( 'normalize_restore_options' ) );

		$method = $reflection->getMethod( 'normalize_restore_options' );
		$method->setAccessible( true );

		$this->assertSame(
			array( 'menu_name' => 'Secretaría ANPA' ),
			$method->invoke( null, array( 'menu_name' => '  Secretaría ANPA  ' ), 5 )
		);
		$this->assertSame(
			array( 'menu_name' => '' ),
			$method->invoke( null, array(), 4 )
		);
	}

	public function test_backup_restore_and_wipe_cover_menu_name_and_meal_schedules(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-backup.php' );
		$settings = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-settings.php' );

		$this->assertStringContainsString( "'horarios_comedor'", $source );
		$this->assertStringContainsString( "'options' => self::backup_options()", $source );
		$this->assertStringContainsString( 'normalize_restore_options', $source );
		$this->assertStringContainsString( 'restore_options', $source );
		$this->assertStringContainsString( 'ANPA_Socios_Config::OPTION_MENU_NAME', $source );
		$this->assertStringContainsString( "'anpa_bak_wipe_failed'", $source );
		$this->assertStringContainsString( '$res = ANPA_Socios_Backup::wipe()', $settings );
		$this->assertStringContainsString( "self::redirect_msg( 'wipe_err' )", $settings );
	}

	public function test_backup_reads_fail_closed_and_wipe_removes_all_config_options(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-backup.php' );

		$this->assertStringContainsString( 'if ( ! is_array( $rows ) )', $source );
		$this->assertStringContainsString( "'anpa_bak_read_failed'", $source );
		$this->assertStringContainsString( '$backup_version > self::VERSION', $source );
		foreach ( array(
			'OPTION_CONTACT_EMAIL',
			'OPTION_ADDRESS',
			'OPTION_FEE',
			'OPTION_COUNTRY',
			'OPTION_PROVINCE',
			'OPTION_TOWN',
		) as $constant ) {
			$this->assertStringContainsString( 'ANPA_Socios_Config::' . $constant, $source, $constant );
		}
		$this->assertStringContainsString( "'anpa_socios_aula_max'", $source );
		foreach ( array(
			'actividades_cursos_grupos_curriculares',
			'grupos_curriculares',
			'grupos_curriculares_niveis',
		) as $legacy_table ) {
			$this->assertStringContainsString( "'{$legacy_table}'", $source, $legacy_table );
		}
	}

	public function test_banking_and_container_failures_never_succeed_with_empty_data(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-backup.php' );

		$this->assertStringContainsString( "'anpa_bak_decrypt_failed'", $source );
		$this->assertStringContainsString( 'null === $iban_sealed', $source );
		$this->assertStringContainsString( 'null === $nif_sealed', $source );
		$this->assertStringContainsString( "'anpa_bak_container_encode'", $source );
		$this->assertStringContainsString( "wp_cache_delete( 'alloptions', 'options' )", $source );
		$this->assertStringContainsString( "wp_cache_delete( 'notoptions', 'options' )", $source );
	}

	public function test_documentation_declares_anpabak_as_only_meal_schedule_transport(): void {
		$settings = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-admin-settings.php' );
		$readme   = file_get_contents( __DIR__ . '/../README.md' );

		$this->assertStringContainsString( '.anpabak', $settings );
		$this->assertStringContainsString( 'único transporte completo', $settings );
		$this->assertStringContainsString( '.anpabak', $readme );
		$this->assertStringContainsString( 'meal schedules', $readme );
	}

	public function test_horarios_comedor_before_niveis(): void {
		$keys = $this->get_table_keys();
		$this->assertLessThan(
			array_search( 'niveis', $keys, true ),
			array_search( 'horarios_comedor', $keys, true )
		);
	}

	public function test_niveis_before_aulas(): void {
		$keys       = $this->get_table_keys();
		$pos_niveis = array_search( 'niveis', $keys, true );
		$pos_aulas  = array_search( 'aulas', $keys, true );

		$this->assertIsInt( $pos_niveis );
		$this->assertIsInt( $pos_aulas );
		$this->assertLessThan( $pos_aulas, $pos_niveis, 'niveis must come before aulas (FK: aulas.nivel_id)' );
	}

	public function test_grupos_niveis_after_grupos(): void {
		$keys              = $this->get_table_keys();
		$pos_grupos        = array_search( 'grupos', $keys, true );
		$pos_grupos_niveis = array_search( 'grupos_niveis', $keys, true );

		$this->assertIsInt( $pos_grupos );
		$this->assertIsInt( $pos_grupos_niveis );
		$this->assertLessThan( $pos_grupos_niveis, $pos_grupos, 'grupos must come before grupos_niveis (FK: grupo_id)' );
	}

	public function test_niveis_before_grupos_niveis(): void {
		$keys              = $this->get_table_keys();
		$pos_niveis        = array_search( 'niveis', $keys, true );
		$pos_grupos_niveis = array_search( 'grupos_niveis', $keys, true );

		$this->assertIsInt( $pos_niveis );
		$this->assertIsInt( $pos_grupos_niveis );
		$this->assertLessThan( $pos_grupos_niveis, $pos_niveis, 'niveis must come before grupos_niveis (FK: nivel_id)' );
	}

	public function test_v2_restore_strips_only_retired_phase24_columns(): void {
		$reflection = new ReflectionClass( ANPA_Socios_Backup::class );
		$this->assertTrue(
			$reflection->hasMethod( 'normalize_restore_row' ),
			'Backup v2 restore needs a row normalizer for the migrated schema.'
		);

		$method = $reflection->getMethod( 'normalize_restore_row' );
		$method->setAccessible( true );

		$fixtures = array(
			'actividades' => array(
				'row'     => array( 'id' => 7, 'nome' => 'Teatro', 'curso_escolar' => '2025-2026', 'min_pupilos' => 4, 'max_pupilos' => 12, 'curso_min' => 1, 'curso_max' => 6 ),
				'retired' => array( 'min_pupilos', 'max_pupilos', 'curso_min', 'curso_max' ),
			),
			'actividades_cursos' => array(
				'row'     => array( 'id' => 8, 'actividad_id' => 7, 'curso_escolar' => '2025-2026', 'horario' => 'legacy' ),
				'retired' => array( 'horario' ),
			),
			'grupos' => array(
				'row'     => array( 'id' => 9, 'actividad_id' => 7, 'nome' => 'Grupo A', 'grupo_curricular_id' => 3 ),
				'retired' => array( 'grupo_curricular_id' ),
			),
		);

		foreach ( $fixtures as $table_key => $fixture ) {
			$normalized = $method->invoke( null, $table_key, $fixture['row'], 2 );
			$this->assertSame( $fixture['row']['id'], $normalized['id'] );
			foreach ( $fixture['retired'] as $column ) {
				$this->assertArrayNotHasKey( $column, $normalized, $table_key . '.' . $column );
			}
		}

		$this->assertSame( '2025-2026', $method->invoke( null, 'actividades', $fixtures['actividades']['row'], 2 )['curso_escolar'] );
	}

	public function test_restore_rejects_malformed_known_table_before_destructive_work(): void {
		$reflection = new ReflectionClass( ANPA_Socios_Backup::class );
		$this->assertTrue( $reflection->hasMethod( 'validate_restore_dump_shape' ) );
		$method = $reflection->getMethod( 'validate_restore_dump_shape' );
		$method->setAccessible( true );

		$this->assertNull( $method->invoke( null, array( 'socios' => array(), 'actividades_cursos' => array() ) ) );
		$this->assertSame( 'invalid_table_shape', $method->invoke( null, array( 'socios' => 'corrupt' ) ) );
		$this->assertSame( 'invalid_table_shape', $method->invoke( null, array( 'actividades_cursos' => false ) ) );
	}

	public function test_legacy_activity_course_restore_classifies_before_destructive_work(): void {
		$reflection = new ReflectionClass( ANPA_Socios_Backup::class );
		$this->assertTrue( $reflection->hasMethod( 'validate_legacy_activity_course_dump' ) );
		$method = $reflection->getMethod( 'validate_legacy_activity_course_dump' );
		$method->setAccessible( true );

		$activity = array( 'id' => 7, 'custo' => '20.00', 'estado' => 'activo' );
		$empty_offer = array(
			'id' => 8, 'actividad_id' => 7, 'curso_escolar' => '2026/2027',
			'custo' => '20.00', 'estado' => 'activo', 'franxa' => '', 'horarios' => '',
			'grupos' => '', 'dias' => '', 'min_pupilos' => 0, 'max_pupilos' => 0,
			'nivel_min_id' => null, 'nivel_max_id' => null,
		);
		$base = array(
			'actividades' => array( $activity ),
			'actividades_cursos' => array( $empty_offer ),
			'grupos' => array(),
			'grupos_niveis' => array(),
		);
		$this->assertNull( $method->invoke( null, $base ), 'A genuinely empty legacy row is safely discarded.' );

		$material = $empty_offer;
		$material['dias'] = 'luns';
		$represented = $base;
		$represented['actividades_cursos'] = array( $material );
		$represented['grupos'] = array( array( 'id' => 30, 'actividad_id' => 7, 'curso_escolar' => '2026/2027' ) );
		$represented['grupos_niveis'] = array( array( 'grupo_id' => 30, 'nivel_id' => 2 ) );
		$this->assertNull( $method->invoke( null, $represented ), 'A material row represented by a levelled group is adapted through groups.' );

		$divergent = $base;
		$divergent['actividades_cursos'][0]['custo'] = '21.00';
		$this->assertSame( 'divergent_cost_or_state', $method->invoke( null, $divergent ) );

		$missing = $base;
		$missing['actividades_cursos'] = array( $material );
		$this->assertSame( 'material_offer_without_group', $method->invoke( null, $missing ) );

		$orphan = $base;
		$orphan['actividades_cursos'][0]['actividad_id'] = 999;
		$this->assertSame( 'orphan_activity', $method->invoke( null, $orphan ) );
	}

	public function test_legacy_offer_rejects_array_values_in_scalar_fields(): void {
		$reflection = new ReflectionClass( ANPA_Socios_Backup::class );
		$method = $reflection->getMethod( 'validate_legacy_activity_course_dump' );
		$method->setAccessible( true );

		$activity = array( 'id' => 7, 'custo' => '20.00', 'estado' => 'activo' );
		$base = array(
			'actividades'       => array( $activity ),
			'actividades_cursos' => array(
				array(
					'id' => 8, 'actividad_id' => 7, 'curso_escolar' => '2026/2027',
					'custo' => '20.00', 'estado' => 'activo', 'franxa' => '', 'horarios' => '',
					'grupos' => '', 'dias' => '', 'min_pupilos' => 0, 'max_pupilos' => 0,
					'nivel_min_id' => null, 'nivel_max_id' => null,
				),
			),
			'grupos'            => array(),
			'grupos_niveis'     => array(),
		);

		// Array in 'dias' field — should reject cleanly, not warn.
		$array_offer = $base;
		$array_offer['actividades_cursos'][0]['dias'] = array( 'luns', 'martes' );
		$this->assertSame( 'invalid_legacy_offer_shape', $method->invoke( null, $array_offer ) );

		// Array in 'curso_escolar' field.
		$array_offer2 = $base;
		$array_offer2['actividades_cursos'][0]['curso_escolar'] = array( '2026/2027' );
		$this->assertSame( 'invalid_legacy_offer_shape', $method->invoke( null, $array_offer2 ) );
	}

	public function test_restore_validates_legacy_offers_before_starting_transaction(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-backup.php' );
		$start  = strpos( $source, 'public static function restore' );
		$end    = strpos( $source, 'public static function wipe', $start );
		$body   = substr( $source, $start, $end - $start );
		$validation = strpos( $body, 'validate_legacy_activity_course_dump' );
		$transaction = strpos( $body, "query( 'START TRANSACTION' )" );

		$this->assertIsInt( $validation );
		$this->assertIsInt( $transaction );
		$this->assertLessThan( $transaction, $validation );
		$this->assertStringContainsString( "'anpa_bak_legacy_offer_conflict'", $body );
	}

	public function test_restore_rolls_back_when_any_domain_write_fails(): void {
		$source = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-backup.php' );
		$start  = strpos( $source, 'public static function restore' );
		$end    = strpos( $source, 'public static function wipe', $start );
		$body   = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( "query( 'START TRANSACTION' )", $body );
		$this->assertStringContainsString( 'false === $wpdb->insert( $table, $row )', $body );
		$this->assertStringContainsString( "query( 'ROLLBACK' )", $body );
		$this->assertStringContainsString( "'anpa_bak_restore_failed'", $body );
		$this->assertStringContainsString( "query( 'SET FOREIGN_KEY_CHECKS=1' )", $body );
		$this->assertStringContainsString( 'backfill_legacy_horarios_comedor', $body );
	}
}
