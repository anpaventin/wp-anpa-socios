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
}
