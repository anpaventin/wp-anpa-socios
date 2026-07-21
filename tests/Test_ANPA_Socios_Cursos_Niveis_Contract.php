<?php
/**
 * Revised fase24 level ownership contracts.
 *
 * Activity-level minimum/maximum bounds are superseded. Each annual group
 * explicitly owns its allowed nivel ids.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Cursos_Niveis_Contract extends TestCase {

	private string $activities;
	private string $groups;
	private string $js;

	protected function setUp(): void {
		$root             = dirname( __DIR__ );
		$this->activities = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-actividades-handler.php' );
		$this->groups     = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-grupos-handler.php' );
		$this->js         = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
	}

	public function test_activity_create_update_do_not_validate_level_bounds(): void {
		$this->assertStringNotContainsString( 'self::validated_cursos_niveis( $body, $cursos )', $this->activities );
		$this->assertStringNotContainsString( 'validated_cursos', $this->activities );
	}

	public function test_group_payload_owns_levels_for_the_active_year(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Grupo_Serie::normalize', $this->groups );
		$this->assertStringContainsString( "\$body['nivel_ids']", $this->groups );
		$this->assertStringContainsString( "\$body['niveis_por_ano'] = array( \$curso_activo => \$nivel_ids )", $this->groups );
		$this->assertStringContainsString( 'ANPA_Socios_DB::niveis_belong_to_curso', $this->groups );
		$this->assertStringContainsString( 'ANPA_Socios_DB::insert_grupo_niveis', $this->groups );
	}

	public function test_group_form_loads_structure_only_for_the_active_year(): void {
		$this->assertStringContainsString( "estrutura?curso_escolar=", $this->js );
		$this->assertStringContainsString( "encodeURIComponent(activeCourse)", $this->js );
		$this->assertStringNotContainsString( 'niveis_por_ano: levels', $this->js );
		$this->assertStringNotContainsString( 'Niveis mínimo/máximo por curso escolar', $this->js );
	}

	public function test_legacy_activity_range_no_longer_restricts_group(): void {
		$this->assertStringNotContainsString( 'assert_niveis_within_range', $this->groups );
		$this->assertStringNotContainsString( 'anpa_admin_grupo_nivel_fora_rango', $this->groups );
		$this->assertStringNotContainsString( 'anpa_admin_grupo_dias', $this->groups );
	}

	public function test_get_niveis_ordes_remains_available_for_other_callers(): void {
		$this->assertTrue( method_exists( 'ANPA_Socios_DB', 'get_niveis_ordes' ) );
	}
}
