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

	public function test_backup_includes_niveis_aulas_grupos_niveis(): void {
		$keys = $this->get_table_keys();

		$this->assertContains( 'niveis', $keys );
		$this->assertContains( 'aulas', $keys );
		$this->assertContains( 'grupos_niveis', $keys );
	}

	public function test_current_backup_format_is_v3_after_phase24_redefinition(): void {
		$this->assertSame( 3, ANPA_Socios_Backup::VERSION );
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
	}
}
