<?php
/**
 * Regression test for the legacy-mirror gate in
 * ANPA_Socios_DB::upsert_fillo_curso_assignment().
 *
 * The gate must compare against the OPERATIONAL active course
 * (ANPA_Socios_Curso_Activo::get(), fase22 DB-backed resolver) rather than
 * the pure date-based ANPA_Socios_Curso_Escolar::current(). The two usually
 * agree but fase22 explicitly allows them to diverge (the junta can delay or
 * advance opening the next year), and using the wrong one would silently
 * stop refreshing the anpa_fillos.curso/aula legacy mirror whenever they
 * disagree — the same "old check doesn't know about the newer concept"
 * pattern found repeatedly in this audit session.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Fillo_Curso_Assignment_Mirror extends TestCase {

	/**
	 * @testdox upsert_fillo_curso_assignment's legacy-mirror gate uses the operational Curso_Activo resolver, not the date-based one
	 */
	public function test_mirror_gate_uses_operational_resolver(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php' );

		$this->assertStringContainsString( 'resolve_operational_curso_activo', $src );
		$this->assertStringContainsString( 'ANPA_Socios_Curso_Activo::get()', $src );

		// The mirror-write condition itself must compare against the
		// operational resolver, not a bare Curso_Escolar::current() call.
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*\$curso_escolar\s*===\s*self::resolve_operational_curso_activo\(\)\s*\)/',
			$src,
			'The legacy-mirror gate must use the operational Curso_Activo resolver (with Curso_Escolar::current() as its own fallback only), not compare directly against Curso_Escolar::current().'
		);
	}
}
