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

	public function test_current_backup_format_is_v5_after_menu_name_roundtrip(): void {
		$this->assertSame( 5, ANPA_Socios_Backup::VERSION );
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
