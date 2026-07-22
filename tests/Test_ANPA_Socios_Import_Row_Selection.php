<?php
/**
 * Contract tests for Mejora 3: per-row selection on CSV import.
 *
 * Dry-run returns the full position-indexed to-insert list (insert_rows) so the
 * UI can render a checkbox per row; commit accepts exclude_rows (positions) and
 * skips them. Source-inspection style (import is DB/glue heavy).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Import_Row_Selection extends TestCase {

	private string $handler;
	private string $js;

	public function setUp(): void {
		parent::setUp();
		$root          = dirname( __DIR__ );
		$this->handler = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-import-handler.php' );
		$this->js      = (string) file_get_contents( $root . '/assets/js/admin-management.js' );
	}

	public function test_dry_run_returns_full_indexed_insert_rows(): void {
		$this->assertStringContainsString( "\$to_insert = array_values( \$report['to_insert'] )", $this->handler );
		$this->assertStringContainsString( "'insert_rows'      => \$to_insert", $this->handler );
	}

	public function test_commit_excludes_unchecked_row_positions(): void {
		$this->assertStringContainsString( "\$body['exclude_rows']", $this->handler );
		$this->assertStringContainsString( 'isset( $exclude[ $pos ] )', $this->handler );
		$this->assertStringContainsString( 'self::commit_rows( $entity, $to_insert, $request )', $this->handler );
	}

	public function test_js_renders_row_checkboxes_and_sends_exclusions(): void {
		$this->assertStringContainsString( "data.insert_rows", $this->js );
		$this->assertStringContainsString( "setAttribute('data-row-index'", $this->js );
		$this->assertStringContainsString( 'exclude_rows: excluded', $this->js );
	}
}
