<?php
/**
 * Source-inspection contract tests for PR-ES9 third batch (tasks 86-87):
 * accessible icon selector in the activity form, and icon preservation
 * across create/update/toggle/duplicate/CSV/backup flows.
 *
 * No live wpdb harness is available in this bootstrap (see
 * Test_ANPA_Socios_List_Actividades_Contract.php for the established
 * precedent), so these tests assert the SOURCE-LEVEL contract instead of
 * exercising a real request/response cycle.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-anpa-socios-admin-export-handler.php';

final class Test_ANPA_Socios_PR_ES9_Icono extends TestCase {

	private string $js_file;
	private string $actividades_handler_file;
	private string $import_handler_file;

	public function setUp(): void {
		parent::setUp();
		$this->js_file                   = dirname( __DIR__ ) . '/assets/js/admin-management.js';
		$this->actividades_handler_file  = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-actividades-handler.php';
		$this->import_handler_file       = dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-import-handler.php';
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 86: icon selector in the JS form
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @testdox renderActividadForm no longer contains the buggy always-blank-on-create icono expression
	 */
	public function test_form_no_longer_forces_blank_icono_on_create(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'renderActividadForm' );
		$this->assertStringNotContainsString(
			"icono: isEdit ? (act.icono || '') : ''",
			$body,
			'The old payload must no longer force icono to blank on create / never allow changing it on edit'
		);
	}

	/**
	 * @testdox renderActividadForm builds an accessible radiogroup of icon presets with aria-labels
	 */
	public function test_form_has_accessible_icono_radiogroup(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'renderActividadForm' );
		$this->assertStringContainsString( "role', 'radiogroup'", $body );
		$this->assertStringContainsString( "radio.setAttribute('aria-label'", $body );
		$this->assertStringContainsString( "radio.type = 'radio'", $body );
	}

	/**
	 * @testdox renderActividadForm has a custom emoji input to preserve legacy icons outside the preset list
	 */
	public function test_form_has_custom_icono_input(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'renderActividadForm' );
		$this->assertStringContainsString( 'iconoCustomInput', $body );
		$this->assertStringContainsString( "iconoCustomInput.type = 'text'", $body );
	}

	/**
	 * @testdox renderActividadForm has a live preview element updated on preset/custom selection
	 */
	public function test_form_has_live_icono_preview(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'renderActividadForm' );
		$this->assertStringContainsString( 'iconoPreview', $body );
		$this->assertStringContainsString( "aria-live', 'polite'", $body );
		$this->assertStringContainsString( 'function updateIconoPreview', $body );
	}

	/**
	 * @testdox getSelectedIcono() falls back to the shared default only when nothing is chosen, and the save payload always uses it
	 */
	public function test_save_payload_uses_getSelectedIcono(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'renderActividadForm' );
		$this->assertStringContainsString( 'function getSelectedIcono', $body );
		$this->assertStringContainsString( 'icono: getSelectedIcono()', $body );
	}

	/**
	 * @testdox Editing an activity whose icono does not match any preset preloads the custom input with that value
	 */
	public function test_edit_with_nonpreset_icono_preloads_custom_input(): void {
		$source = file_get_contents( $this->js_file );
		$body   = $this->extract_method_body( $source, 'renderActividadForm' );
		$this->assertStringContainsString( 'matchedIconoPreset', $body );
		$this->assertStringContainsString(
			"iconoCustomInput.value = matchedIconoPreset ? '' : initialIcono;",
			$body
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Task 87: preservation across create/update/toggle/duplicate/CSV
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @testdox Toggle Activar/Desactivar payload preserves row.icono (regression guard)
	 */
	public function test_toggle_payload_preserves_icono(): void {
		$source = file_get_contents( $this->js_file );
		$idx    = strpos( $source, "anpaAdminFetch('actividad/' + row.id, { method: 'PUT'" );
		$this->assertNotFalse( $idx, 'Toggle estado fetch call not found' );
		$window = substr( $source, max( 0, $idx - 900 ), 900 );
		$this->assertStringContainsString(
			"icono: row.icono || ''",
			$window,
			'Toggle payload must resend row.icono, otherwise the icon would be wiped on activate/deactivate'
		);
	}

	/**
	 * @testdox duplicate_actividad() copies the source activity icono to the duplicate
	 */
	public function test_duplicate_actividad_copies_icono(): void {
		$source = file_get_contents( $this->actividades_handler_file );
		$body   = $this->extract_method_body( $source, 'duplicate_actividad' );
		$this->assertStringContainsString( "'icono'         => (string) ( \$src['icono'] ?? '' )", $body );
	}

	/**
	 * @testdox base_payload() forwards the validated icono into the actividades row for create/update
	 */
	public function test_base_payload_forwards_icono(): void {
		$source = file_get_contents( $this->actividades_handler_file );
		$body   = $this->extract_method_body( $source, 'base_payload' );
		$this->assertStringContainsString( "'icono'         => (string) \$payload['icono']", $body );
	}

	/**
	 * @testdox CSV export/import headers for actividades include icono, and stay in sync between both sides
	 */
	public function test_csv_headers_include_icono_and_stay_in_sync(): void {
		$reflection = new ReflectionClass( ANPA_Socios_Admin_Export_Handler::class );
		/** @var array<string,string[]> $export */
		$export = $reflection->getConstant( 'ENTITY_COLUMNS' );

		$this->assertContains( 'icono', $export['actividades'], 'Export ENTITY_COLUMNS must include icono' );
		$this->assertContains( 'icono', ANPA_Socios_Csv_Import::ENTITY_HEADERS['actividades'], 'Import ENTITY_HEADERS must include icono' );
		$this->assertSame(
			ANPA_Socios_Csv_Import::ENTITY_HEADERS['actividades'],
			$export['actividades'],
			'actividades CSV contract drift between export and import headers'
		);
	}

	/**
	 * @testdox The actividades export JOIN selects a.icono
	 */
	public function test_export_join_selects_icono(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-admin-export-handler.php' );
		$body   = $this->extract_method_body( $source, 'fetch_joined_entity' );
		$this->assertStringContainsString( 'a.icono', $body );
	}

	/**
	 * @testdox commit_actividades() only sets icono when INSERTing a brand-new activity, never on re-import of an existing one
	 */
	public function test_commit_actividades_sets_icono_only_on_insert(): void {
		$source = file_get_contents( $this->import_handler_file );
		$body   = $this->extract_method_body( $source, 'commit_actividades' );

		// The icono resolution + 'icono' => key must live INSIDE the `if ( ! $activity_id )`
		// (create-only) branch, never touched when the activity already exists.
		$if_pos = strpos( $body, 'if ( ! $activity_id )' );
		$this->assertNotFalse( $if_pos, 'commit_actividades must branch on activity existence' );
		$insert_branch = substr( $body, $if_pos, (int) strpos( $body, "\$activity_id = (int) \$wpdb->insert_id;", $if_pos ) - $if_pos );

		$this->assertStringContainsString( "row['icono']", $insert_branch, 'icono must be read from the CSV row inside the create branch' );
		$this->assertStringContainsString( "'icono'         => \$icono,", $insert_branch, 'icono must be part of the INSERT array' );

		// And it must NOT appear in an UPDATE call anywhere in this method (no
		// re-import overwrite path).
		$this->assertStringNotContainsString( '$wpdb->update(', $body, 'commit_actividades must never UPDATE the base actividades row (icono or otherwise) on re-import' );
	}

	// ────────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────────

	private function extract_method_body( string $source, string $method_name ): string {
		$pattern = '/function\s+' . preg_quote( $method_name, '/' ) . '\s*\(/';
		if ( ! preg_match( $pattern, $source, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$start     = (int) $m[0][1];
		$brace_pos = strpos( $source, '{', $start );
		if ( false === $brace_pos ) {
			return '';
		}
		$depth = 0;
		$len   = strlen( $source );
		$body  = '';
		for ( $i = $brace_pos; $i < $len; $i++ ) {
			$ch = $source[ $i ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					$body = substr( $source, $brace_pos, $i - $brace_pos + 1 );
					break;
				}
			}
		}
		return $body;
	}
}
