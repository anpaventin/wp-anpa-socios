<?php
/**
 * Regression test: ANPA_Socios_DB::upsert_fillo_curso_assignment() must
 * resolve and persist nivel_id/aula_id on every write, not only during the
 * 1.27.0 migration backfill.
 *
 * Bug found during PR-ES10 migration E2E (2026-07-15): the helper only ever
 * wrote the text columns (curso/aula), leaving nivel_id/aula_id NULL for any
 * fillo assignment made after the one-off backfill ran. That silently
 * undercounted the reference-check in
 * ANPA_Socios_Admin_Estrutura_Handler::delete_nivel() (`COUNT(*) FROM
 * fillos_cursos WHERE nivel_id = %d`), letting a nivel/aula still in use be
 * hard-deleted instead of deactivated.
 *
 * This is a source-inspection contract test (no live DB — see tests/bootstrap.php),
 * consistent with the other DB-helper tests in this suite. It asserts the
 * write path now resolves the FK columns via resolve_nivel_aula_ids() and
 * includes them in both the INSERT and the ON DUPLICATE KEY UPDATE clause.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Fillo_Curso_Ids_Backfill extends TestCase {

	/**
	 * @var string
	 */
	private string $src;

	public function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php' );
	}

	/**
	 * @testdox upsert_fillo_curso_assignment resolves nivel_id/aula_id before writing.
	 */
	public function test_upsert_resolves_fk_ids(): void {
		$this->assertStringContainsString( 'resolve_nivel_aula_ids( $curso_escolar, $curso, $aula )', $this->src );
	}

	/**
	 * @testdox The fillos_cursos INSERT includes nivel_id and aula_id columns.
	 */
	public function test_insert_includes_fk_columns(): void {
		$this->assertMatchesRegularExpression(
			'/INSERT INTO \{\$fc_table\}\s*\(fillo_id, curso_escolar, curso, aula, nivel_id, aula_id\)/',
			$this->src
		);
	}

	/**
	 * @testdox ON DUPLICATE KEY UPDATE also refreshes nivel_id/aula_id (so edits re-resolve them too).
	 */
	public function test_on_duplicate_key_update_refreshes_fk_columns(): void {
		$this->assertStringContainsString(
			'nivel_id = VALUES(nivel_id), aula_id = VALUES(aula_id)',
			$this->src
		);
	}

	/**
	 * @testdox An unresolved nivel/aula code writes literal NULL, never 0 (0 is a real, wrong id).
	 */
	public function test_unresolved_ids_use_literal_null_not_zero_placeholder(): void {
		$this->assertStringContainsString( "null === \$nivel_id ? 'NULL' : '%d'", $this->src );
		$this->assertStringContainsString( "null === \$aula_id ? 'NULL' : '%d'", $this->src );
	}

	/**
	 * @testdox resolve_nivel_aula_ids() scopes the nivel lookup by curso_escolar + codigo.
	 */
	public function test_resolve_helper_scopes_nivel_lookup_by_curso_escolar(): void {
		$this->assertMatchesRegularExpression(
			'/SELECT id FROM \{\$niveis_t\} WHERE curso_escolar = %s AND codigo = %s/',
			$this->src
		);
	}

	/**
	 * @testdox resolve_nivel_aula_ids() scopes the aula lookup by the resolved nivel_id + codigo.
	 */
	public function test_resolve_helper_scopes_aula_lookup_by_nivel_id(): void {
		$this->assertMatchesRegularExpression(
			'/SELECT id FROM \{\$aulas_t\} WHERE nivel_id = %d AND codigo = %s/',
			$this->src
		);
	}

	/**
	 * @testdox resolve_nivel_aula_ids() returns [null, null] when the nivel code does not resolve, never throws.
	 */
	public function test_resolve_helper_short_circuits_on_missing_nivel(): void {
		$this->assertStringContainsString( 'return array( null, null );', $this->src );
	}
}
