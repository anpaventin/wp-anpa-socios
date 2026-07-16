<?php
/**
 * Regression contracts for second-parent approval state.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Approval_Second_Parent extends TestCase {

	private string $rest;

	protected function setUp(): void {
		$this->rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-rest.php' );
	}

	public function test_new_second_parent_inherits_approval_state_and_secondary_role(): void {
		$this->assertStringContainsString(
			'familia_id, rol_familia, estado, creado_en, actualizado_en',
			$this->rest
		);
		$this->assertStringContainsString(
			'%d, \'secundario\', \'{$estado}\', NOW(), NOW()',
			$this->rest
		);
		$this->assertStringNotContainsString(
			"%d, 'activo', NOW(), NOW())\n\t\t\t\tON DUPLICATE KEY UPDATE email = email",
			$this->rest
		);
	}
}
