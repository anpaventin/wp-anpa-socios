<?php
/**
 * Regression test for a fillos export duplication bug found by live
 * auditing (2026-07-14) during PR-ES6.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Fillos_Export_No_Duplication extends TestCase {

	/**
	 * `wp_anpa_fillos_cursos` holds ONE ROW PER SCHOOL YEAR per fillo
	 * (UNIQUE(fillo_id, curso_escolar) — design.md fase23 §2.4). A bare
	 * `LEFT JOIN ... ON fc.fillo_id = f.id` (no curso_escolar scoping)
	 * duplicates the fillo row once per historical year the moment a fillo
	 * accumulates more than one year of history — the NORMAL state from the
	 * second school year of real usage onward, not an edge case.
	 *
	 * Verified live against staging: injecting a second historical year for
	 * an existing fillo turned a 1-row export into a 2-row export under the
	 * unscoped query; the fixed query (curso_escolar-scoped join) stayed at
	 * 1 row.
	 *
	 * No live-DB harness exists in this bootstrap, so this is a source
	 * assertion pinned to the exact fix shape, not a behavioral round-trip.
	 *
	 * @testdox fillos export JOIN against fillos_cursos is scoped by curso_escolar (no year duplication)
	 */
	public function test_fillos_export_join_is_scoped_by_curso_escolar(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-export-handler.php' );

		$this->assertMatchesRegularExpression(
			'/LEFT JOIN \{\$prefix\}anpa_fillos_cursos fc ON fc\.fillo_id = f\.id AND fc\.curso_escolar = %s/',
			$src,
			'The fillos export join to fillos_cursos must be scoped to a single curso_escolar (current course), otherwise fillos with multi-year history are duplicated once per historical row.'
		);
	}
}
