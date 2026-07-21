<?php
/**
 * Revised fase24 dynamic-group membership contracts.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupo_Dinamico_Assert extends TestCase {

	public function test_series_validation_checks_levels_for_the_active_school_year(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-grupos-handler.php' );

		$this->assertStringContainsString( 'private static function validate_series_payload', $src );
		$this->assertStringContainsString( "\$body['nivel_ids']", $src );
		$this->assertStringContainsString( "\$body['niveis_por_ano'][ \$curso_activo ]", $src );
		$this->assertStringContainsString( 'ANPA_Socios_DB::niveis_belong_to_curso', $src );
		$this->assertStringNotContainsString( 'assert_within_activity', $src );
		$this->assertStringNotContainsString( 'anpa_admin_grupo_curso_range', $src );
	}

	public function test_niveis_belong_to_curso_exists(): void {
		$this->assertTrue( method_exists( 'ANPA_Socios_DB', 'niveis_belong_to_curso' ) );
		$ref    = new ReflectionMethod( 'ANPA_Socios_DB', 'niveis_belong_to_curso' );
		$params = $ref->getParameters();
		$this->assertCount( 2, $params );
		$this->assertSame( 'nivel_ids', $params[0]->getName() );
		$this->assertSame( 'curso_escolar', $params[1]->getName() );
	}
}
