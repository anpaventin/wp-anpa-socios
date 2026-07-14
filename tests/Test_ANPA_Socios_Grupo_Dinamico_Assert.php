<?php
/**
 * Regression test for a real bug found by live auditing (2026-07-14):
 * ANPA_Socios_Admin_Grupos_Handler::assert_within_activity() unconditionally
 * required the legacy `curso_range` to be one of the activity's option set,
 * even when the payload used the dynamic `nivel_ids` path (PR-ES5). A pure
 * nivel_ids payload legitimately has an empty curso_range, so every
 * dynamic-only grupo was rejected with 400 — it was impossible to create a
 * grupo through the real API using only niveis, despite the pure validator
 * (`ANPA_Socios_Admin_Payload::validar_grupo()`) accepting it correctly.
 *
 * This is a source-level test (no live DB harness available in this
 * bootstrap) asserting the fixed method branches on which identity the
 * payload actually carries instead of always requiring the legacy string.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Grupo_Dinamico_Assert extends TestCase {

	/**
	 * @testdox assert_within_activity branches on nivel_ids vs curso_range instead of always requiring the legacy string
	 */
	public function test_assert_within_activity_branches_on_payload_identity(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-grupos-handler.php' );

		// Must check for a non-empty nivel_ids array BEFORE falling back to
		// the legacy curso_range branch.
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*array\(\)\s*!==\s*\$nivel_ids\s*\)/',
			$src,
			'assert_within_activity must branch on nivel_ids presence first, not always require curso_range.'
		);

		// The dynamic branch must validate against the real structure via
		// ANPA_Socios_DB::niveis_belong_to_curso(), not re-derive SQL inline.
		$this->assertStringContainsString( 'ANPA_Socios_DB::niveis_belong_to_curso', $src );
	}

	/**
	 * @testdox ANPA_Socios_DB::niveis_belong_to_curso exists with the expected signature
	 */
	public function test_niveis_belong_to_curso_exists(): void {
		$this->assertTrue( method_exists( 'ANPA_Socios_DB', 'niveis_belong_to_curso' ) );

		$ref    = new ReflectionMethod( 'ANPA_Socios_DB', 'niveis_belong_to_curso' );
		$params = $ref->getParameters();
		$this->assertCount( 2, $params );
		$this->assertSame( 'nivel_ids', $params[0]->getName() );
		$this->assertSame( 'curso_escolar', $params[1]->getName() );
	}
}
