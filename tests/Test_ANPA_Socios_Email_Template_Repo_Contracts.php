<?php
/**
 * Source-inspection contracts for the template repository (fase36, PR-36s2).
 *
 * Behaviour against a real engine lives in the integration suite. What belongs here is the set of
 * structural promises that are cheap to break and expensive to notice: that seeding never updates,
 * that `is_customised` is computed rather than claimed, that every write archives first, and that no
 * query is assembled from unbound input.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Repo_Contracts extends TestCase {

	private string $src;
	private string $bootstrap;

	protected function setUp(): void {
		$this->src       = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-email-template-repo.php' );
		$this->bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/anpa-socios.php' );
	}

	/**
	 * @param  string $method Method name.
	 * @return string Source of that method, up to the next declaration.
	 */
	private function method( string $method ): string {
		$start = strpos( $this->src, 'function ' . $method . '(' );
		$this->assertIsInt( $start, "{$method} is not declared" );

		$next = strpos( $this->src, "\tprivate static function ", (int) $start + 10 );
		$pub  = strpos( $this->src, "\tpublic static function ", (int) $start + 10 );
		if ( false !== $pub && ( false === $next || $pub < $next ) ) {
			$next = $pub;
		}

		return false === $next
			? substr( $this->src, (int) $start )
			: substr( $this->src, (int) $start, (int) $next - (int) $start );
	}

	// ── Seeding must never overwrite ────────────────────────────────────

	public function test_seeding_only_inserts(): void {
		// The invariant that protects a board's wording from an update. An ->update() inside the
		// seeding path is the exact bug this test exists to catch.
		$body = $this->method( 'seed_missing' ) . $this->method( 'insert_default' );

		$this->assertStringContainsString( '$wpdb->insert(', $body );
		$this->assertStringNotContainsString( '$wpdb->update(', $body );
		$this->assertStringNotContainsString( 'DELETE', $body );
		$this->assertStringNotContainsString( 'REPLACE INTO', $body );
	}

	public function test_seeding_refuses_to_write_a_template_with_no_shipped_default(): void {
		// Writing an empty row would produce a template that renders a blank email and validates
		// happily. A packaging bug must look like a packaging bug.
		$body = $this->method( 'seed_missing' );

		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Defaults::exists(', $body );
		$this->assertStringContainsString( 'missing_defaults', $body );
	}

	public function test_seeding_reads_the_catalogue_once(): void {
		// It runs on an admin request; 35 existence checks to learn that nothing is missing is 35
		// queries too many.
		$body = $this->method( 'seed_missing' );

		$this->assertStringContainsString( 'self::all()', $body );
		$this->assertStringNotContainsString( 'self::exists(', $body );
	}

	// ── is_customised is a computed fact ────────────────────────────────

	public function test_customisation_is_derived_from_a_digest_comparison(): void {
		$body = $this->method( 'save' );

		$this->assertStringContainsString( "\$hash !== (string) \$row['default_hash']", $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Defaults::content_hash(', $body );
	}

	public function test_restoring_the_default_clears_the_customised_flag(): void {
		$body = $this->method( 'restore_default' );

		$this->assertStringContainsString( "'is_customised'   => 0", $body );
	}

	// ── Every write archives what it replaces ───────────────────────────

	public function test_all_three_write_paths_archive_before_overwriting(): void {
		foreach ( array( 'save', 'restore_default', 'restore_version' ) as $method ) {
			$body    = $this->method( $method );
			$archive = strpos( $body, 'self::archive(' );
			$update  = strpos( $body, '$wpdb->update(' );

			$this->assertIsInt( $archive, "{$method} does not archive" );
			$this->assertIsInt( $update, "{$method} does not write" );
			$this->assertLessThan( $update, $archive, "{$method} overwrites before archiving" );
		}
	}

	public function test_a_restore_is_archived_with_its_own_reason(): void {
		// A history that only recorded manual edits would silently lose the moment somebody reverted
		// the board's wording.
		$this->assertStringContainsString( "const REASON_RESTORE_DEFAULT = 'restore_default'", $this->src );
		$this->assertStringContainsString( "const REASON_RESTORE_VERSION = 'restore_version'", $this->src );
		$this->assertStringContainsString( 'self::REASON_RESTORE_DEFAULT', $this->method( 'restore_default' ) );
		$this->assertStringContainsString( 'self::REASON_RESTORE_VERSION', $this->method( 'restore_version' ) );
	}

	public function test_the_history_stores_the_replaced_state(): void {
		$body = $this->method( 'archive' );

		// Every content column comes from $row, the row about to be overwritten.
		foreach ( array( 'subject', 'body_html', 'body_text', 'content_hash' ) as $column ) {
			$this->assertStringContainsString( "\$row['{$column}']", $body, "the archive does not copy {$column} from the replaced row" );
		}
	}

	public function test_an_unchanged_save_does_not_fill_the_history(): void {
		// Identical rows would push the version somebody wants out of the last ten.
		$body = $this->method( 'save' );

		$this->assertStringContainsString( "'code'       => 'unchanged'", $body );
		$this->assertLessThan(
			strpos( $body, 'self::archive(' ),
			strpos( $body, "'unchanged'" ),
			'the unchanged short-circuit must come before archiving'
		);
	}

	// ── Validation ──────────────────────────────────────────────────────

	public function test_content_is_sanitised_before_it_is_validated(): void {
		// The other order would let a token pass validation and then be removed by the sanitiser.
		$body      = $this->method( 'save' );
		$sanitised = strpos( $body, 'Sanitizer::sanitize_subject(' );
		$validated = strpos( $body, 'self::validate(' );

		$this->assertIsInt( $sanitised );
		$this->assertIsInt( $validated );
		$this->assertLessThan( $validated, $sanitised );
	}

	public function test_validation_uses_the_declared_vocabulary_of_the_event(): void {
		$body = $this->method( 'validate' );

		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Events::set()->get(', $body );
		$this->assertStringContainsString( '->declared_tokens()', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Renderer::undeclared_tokens(', $body );
	}

	public function test_validation_covers_all_three_channels(): void {
		// A token nobody declared is just as broken in the subject as in the body.
		$this->assertStringContainsString( 'array( $subject, $html, $text )', $this->method( 'validate' ) );
	}

	public function test_an_over_long_subject_is_refused_and_never_truncated(): void {
		$body = $this->method( 'validate' );

		$this->assertStringContainsString( 'subject_too_long', $body );
		$this->assertStringNotContainsString( 'mb_substr( $subject', $body );
	}

	public function test_an_empty_plain_text_body_is_refused(): void {
		// The text channel is a channel, not a by-product: an empty one leaves text-only clients
		// with nothing.
		$this->assertStringContainsString( 'empty_text', $this->method( 'validate' ) );
	}

	public function test_restoring_an_old_version_revalidates_it(): void {
		// It was valid when stored; the event's vocabulary may have changed since.
		$body = $this->method( 'restore_version' );

		$this->assertStringContainsString( 'self::validate(', $body );
		$this->assertStringContainsString( 'version_no_longer_valid', $body );
	}

	// ── Updates inform, they do not decide ──────────────────────────────

	public function test_a_newer_default_never_overwrites_a_customised_row(): void {
		$body = $this->method( 'adopt_newer_defaults' );

		$this->assertStringContainsString( "if ( \$state['customised'] ) {", $body );
		$this->assertStringContainsString( '$reported[] = $key;', $body );
	}

	public function test_outdated_reports_without_writing(): void {
		$body = $this->method( 'outdated' );

		$this->assertStringNotContainsString( '$wpdb->update(', $body );
		$this->assertStringNotContainsString( '$wpdb->insert(', $body );
	}

	// ── SQL hygiene and UTC ─────────────────────────────────────────────

	public function test_every_variable_in_a_query_is_bound(): void {
		// Table names are internal; the values never are.
		$this->assertStringNotContainsString( 'WHERE template_key = \'', $this->src );
		$this->assertStringContainsString( 'WHERE template_key = %s', $this->src );
		$this->assertStringContainsString( 'WHERE id = %d', $this->src );
	}

	public function test_the_prune_avoids_the_self_referencing_subquery(): void {
		// MySQL refuses DELETE ... WHERE id NOT IN (SELECT ... FROM the_same_table) with error 1093.
		$body = $this->method( 'prune' );

		$this->assertStringContainsString( 'SELECT id FROM', $body );
		$this->assertStringNotContainsString( 'NOT IN ( SELECT', $body );
		$this->assertStringContainsString( "array_map( 'intval', \$stale )", $body );
	}

	public function test_the_history_guard_is_documented_as_technical(): void {
		$this->assertStringContainsString( 'const HISTORY_LIMIT = 10', $this->src );
		$this->assertStringContainsString( 'A TECHNICAL GUARD, not an archival policy', $this->src );
	}

	public function test_times_are_written_in_utc(): void {
		$this->assertStringContainsString( "gmdate( 'Y-m-d H:i:s' )", $this->src );
		$this->assertStringNotContainsString( 'current_time(', $this->src );

		// `date()` uses the site timezone, so a stored timestamp would depend on where the site
		// thinks it is. The lookbehind is what keeps this from matching gmdate() itself.
		$this->assertSame(
			0,
			preg_match( '/(?<!gm)date\(\s*\'Y-m-d/', $this->src ),
			'a local-timezone date() reached a stored timestamp'
		);
	}

	public function test_the_repository_never_sends_or_renders(): void {
		$this->assertStringNotContainsString( 'wp_mail', $this->src );
		$this->assertStringNotContainsString( 'Renderer::render(', $this->src );
	}

	public function test_the_glue_guards_direct_access(): void {
		$this->assertStringContainsString( "if ( ! defined( 'ABSPATH' ) ) {", $this->src );
	}

	// ── Wiring ──────────────────────────────────────────────────────────

	public function test_seeding_is_wired_separately_from_the_migration(): void {
		$this->assertStringContainsString( 'class-anpa-socios-email-template-repo.php', $this->bootstrap );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Template_Repo::seed_missing(', $this->bootstrap );

		// Not called from inside crear_tabelas(): a schema step that failed halfway must never leave
		// half a catalogue behind.
		$db = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php' );
		$this->assertStringNotContainsString( 'Email_Template_Repo', $db );
	}

	public function test_seeding_is_gated_on_the_plugin_version(): void {
		// Otherwise every admin page load pays for a catalogue read to learn that nothing changed.
		$this->assertStringContainsString( "anpa_socios_email_templates_seeded", $this->bootstrap );
		$this->assertStringContainsString( 'ANPA_SOCIOS_VERSION === (string) get_option(', $this->bootstrap );
	}

	public function test_a_failed_seeding_is_not_recorded_as_done(): void {
		// Marking a packaging bug as complete is how it becomes permanent.
		$start = strpos( $this->bootstrap, "\$option = 'anpa_socios_email_templates_seeded'" );
		$this->assertIsInt( $start );
		$hook = substr( $this->bootstrap, (int) $start, 900 );

		$this->assertStringContainsString( "if ( \$result['ok'] ) {", $hook );
		$this->assertStringContainsString( 'missing_defaults', $hook );
	}
}
