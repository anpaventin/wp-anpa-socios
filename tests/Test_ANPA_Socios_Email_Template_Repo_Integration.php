<?php
/**
 * REAL storage tests for the template repository (fase36, PR-36s2).
 *
 * Everything here needs a database, because everything here is about what actually happens to rows:
 * clean install seeds all 35, a second run is a no-op, a customised row survives re-seeding, a newer
 * shipped default does not overwrite a customisation, restoring is itself versioned, and the history
 * prunes to ten.
 *
 * SKIPPED unless run under the WordPress test suite with a real $wpdb (ANPA_SOCIOS_IT_DB).
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Repo_Integration extends TestCase {

	/** A key that exists in the registry and has a shipped default. */
	private const KEY = 'auth_access_code';

	protected function setUp(): void {
		if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
			$this->markTestSkipped( 'Integration DB not available: run under the WordPress test suite with a real $wpdb (define ANPA_SOCIOS_IT_DB).' );
		}

		$this->reset_catalogue();
	}

	private function reset_catalogue(): void {
		global $wpdb;

		$templates = ANPA_Socios_DB::tabela_email_templates();
		$versions  = ANPA_Socios_DB::tabela_email_template_versions();

		ANPA_Socios_DB::crear_tabelas();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$versions}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$templates}`" );
	}

	/** @return array<string,string> A valid edit of the given template. */
	private function edited( string $key, string $marker = 'Editado' ): array {
		$row = ANPA_Socios_Email_Template_Repo::get( $key );
		$this->assertIsArray( $row, "{$key} is not stored; seeding must run before an edit" );

		return array(
			'subject'   => $marker . ' ' . (string) $row['subject'],
			'body_html' => (string) $row['body_html'],
			'body_text' => (string) $row['body_text'],
		);
	}

	// ── Seeding ─────────────────────────────────────────────────────────

	public function test_a_clean_install_seeds_one_row_per_registered_event(): void {
		$result = ANPA_Socios_Email_Template_Repo::seed_missing( 'system' );

		$this->assertSame( array(), $result['missing_defaults'], 'every declared event must have a shipped default' );
		// The engine's own message, not just "it did not work": a column one character too narrow
		// looks exactly like "there was nothing to seed".
		$this->assertSame( array(), $result['failed'], 'refused inserts: ' . wp_json_encode( $result['failed'] ) );
		$this->assertTrue( $result['ok'] );

		$stored   = ANPA_Socios_Email_Template_Repo::all();
		$declared = ANPA_Socios_Email_Template_Events::set()->keys();

		// A bijection, not a count: every declared event is stored and nothing else is.
		foreach ( $declared as $key ) {
			$this->assertArrayHasKey( (string) $key, $stored, "{$key} was not seeded" );
		}
		foreach ( array_keys( $stored ) as $key ) {
			$this->assertContains( $key, $declared, "{$key} is stored but not declared" );
		}
	}

	public function test_a_seeded_row_is_byte_identical_to_its_shipped_default(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();

		foreach ( ANPA_Socios_Email_Template_Events::set()->keys() as $key ) {
			$default = ANPA_Socios_Email_Template_Defaults::load( $key );
			$row     = ANPA_Socios_Email_Template_Repo::get( $key );
			$this->assertIsArray( $row, "{$key} was not seeded" );

			$this->assertSame( $default['subject'], (string) $row['subject'], "{$key}: subject drifted through storage" );
			$this->assertSame( $default['body_html'], (string) $row['body_html'], "{$key}: HTML drifted through storage" );
			$this->assertSame( $default['body_text'], (string) $row['body_text'], "{$key}: text drifted through storage" );
			$this->assertSame( '0', (string) $row['is_customised'], "{$key}: a freshly seeded row is not customised" );
		}
	}

	public function test_a_second_seeding_is_a_no_op(): void {
		$first  = ANPA_Socios_Email_Template_Repo::seed_missing();
		$second = ANPA_Socios_Email_Template_Repo::seed_missing();

		$this->assertGreaterThan( 0, $first['inserted'] );
		$this->assertSame( 0, $second['inserted'] );
		$this->assertSame( $first['inserted'], $second['skipped'] );
	}

	public function test_a_customised_row_survives_reseeding(): void {
		// The invariant that protects a board's wording from an update.
		ANPA_Socios_Email_Template_Repo::seed_missing();
		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY ), 'nai@example.com' );

		ANPA_Socios_Email_Template_Repo::seed_missing();

		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertStringStartsWith( 'Editado', (string) $row['subject'] );
		$this->assertSame( '1', (string) $row['is_customised'] );
	}

	// ── Saving ──────────────────────────────────────────────────────────

	public function test_a_save_marks_the_row_customised_and_records_the_actor(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();

		$result = ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY ), 'nai@example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertTrue( $result['customised'] );

		$row = ANPA_Socios_Email_Template_Repo::get( self::KEY );
		$this->assertSame( 'nai@example.com', (string) $row['updated_by'] );
		$this->assertNotSame( '', (string) $row['updated_at_utc'] );
	}

	public function test_saving_the_shipped_text_back_clears_the_customised_flag(): void {
		// is_customised answers "does this differ from the shipped default?", so restoring the text by
		// hand must clear it. A flag set by whoever called save() would stay wrong forever.
		ANPA_Socios_Email_Template_Repo::seed_missing();
		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY ), 'nai@example.com' );

		$default = ANPA_Socios_Email_Template_Defaults::load( self::KEY );
		$result  = ANPA_Socios_Email_Template_Repo::save( self::KEY, $default, 'nai@example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['customised'] );
		$this->assertSame( '0', (string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['is_customised'] );
	}

	public function test_an_identical_save_changes_nothing_and_archives_nothing(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();
		$default = ANPA_Socios_Email_Template_Defaults::load( self::KEY );

		$result = ANPA_Socios_Email_Template_Repo::save( self::KEY, $default, 'nai@example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'unchanged', $result['code'] );
		$this->assertSame( array(), ANPA_Socios_Email_Template_Repo::versions( self::KEY ) );
	}

	public function test_a_save_refuses_an_undeclared_token(): void {
		// It would reach an inbox as literal braces, and the renderer is frozen so it will not rescue
		// the mistake.
		ANPA_Socios_Email_Template_Repo::seed_missing();

		$content              = $this->edited( self::KEY );
		$content['body_html'] = '<p>{{inventado}}</p>';

		$result = ANPA_Socios_Email_Template_Repo::save( self::KEY, $content, 'nai@example.com' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'undeclared_tokens', $result['code'] );
		$this->assertContains( 'inventado', $result['undeclared'] );

		// And nothing was written.
		$this->assertSame( '0', (string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['is_customised'] );
	}

	public function test_a_save_refuses_an_over_long_subject_instead_of_truncating_it(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();

		$content            = $this->edited( self::KEY );
		$content['subject'] = str_repeat( 'a', ANPA_Socios_Email_Template_Repo::SUBJECT_MAX + 1 );

		$result = ANPA_Socios_Email_Template_Repo::save( self::KEY, $content, 'nai@example.com' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'subject_too_long', $result['code'] );
	}

	public function test_a_save_strips_a_dangerous_construct_rather_than_storing_it(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();

		$content              = $this->edited( self::KEY );
		$content['body_html'] = '<p>Ola</p><script>alert(1)</script>';

		$result = ANPA_Socios_Email_Template_Repo::save( self::KEY, $content, 'nai@example.com' );
		$this->assertTrue( $result['ok'] );

		$stored = (string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['body_html'];
		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertStringContainsString( 'Ola', $stored );
	}

	public function test_a_save_cannot_store_a_newline_in_the_subject(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();

		$content            = $this->edited( self::KEY );
		$content['subject'] = "Verificación\r\nBcc: outro@example.org";

		$this->assertTrue( ANPA_Socios_Email_Template_Repo::save( self::KEY, $content, 'nai@example.com' )['ok'] );

		$stored = (string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['subject'];
		$this->assertStringNotContainsString( "\n", $stored );
		$this->assertStringNotContainsString( "\r", $stored );
	}

	// ── History ─────────────────────────────────────────────────────────

	public function test_a_save_archives_the_state_it_replaced(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();
		$before = (string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['subject'];

		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY ), 'nai@example.com' );

		$versions = ANPA_Socios_Email_Template_Repo::versions( self::KEY );
		$this->assertNotSame( array(), $versions );
		$this->assertSame( $before, (string) $versions[0]['subject'], 'the history must hold the replaced state, not the new one' );
		$this->assertSame( 'save', (string) $versions[0]['archived_reason'] );
		$this->assertSame( 'nai@example.com', (string) $versions[0]['archived_by'] );
	}

	public function test_the_history_prunes_to_the_declared_limit(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();

		for ( $i = 1; $i <= ANPA_Socios_Email_Template_Repo::HISTORY_LIMIT + 4; $i++ ) {
			ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY, 'Versión ' . $i ), 'nai@example.com' );
		}

		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_template_versions();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE template_key = %s", self::KEY ) );

		$this->assertSame( ANPA_Socios_Email_Template_Repo::HISTORY_LIMIT, $kept );
	}

	public function test_pruning_keeps_the_newest_versions(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();

		for ( $i = 1; $i <= ANPA_Socios_Email_Template_Repo::HISTORY_LIMIT + 2; $i++ ) {
			ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY, 'Versión ' . $i ), 'nai@example.com' );
		}

		$versions = ANPA_Socios_Email_Template_Repo::versions( self::KEY );
		$this->assertStringContainsString(
			'Versión ' . ( ANPA_Socios_Email_Template_Repo::HISTORY_LIMIT + 1 ),
			(string) $versions[0]['subject'],
			'the newest archived version must survive pruning'
		);
	}

	public function test_restoring_a_version_is_itself_versioned(): void {
		// Otherwise the history is a trap: one wrong click overwrites the content you wanted without
		// archiving it.
		ANPA_Socios_Email_Template_Repo::seed_missing();
		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY, 'Primeira' ), 'nai@example.com' );
		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY, 'Segunda' ), 'nai@example.com' );

		$versions = ANPA_Socios_Email_Template_Repo::versions( self::KEY );
		$target   = $versions[ count( $versions ) - 1 ];

		$result = ANPA_Socios_Email_Template_Repo::restore_version( (int) $target['id'], 'pai@example.com' );
		$this->assertTrue( $result['ok'] );

		$this->assertSame( (string) $target['subject'], (string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['subject'] );
		$this->assertSame( 'restore_version', (string) ANPA_Socios_Email_Template_Repo::versions( self::KEY )[0]['archived_reason'] );
	}

	public function test_restoring_the_default_is_archived_with_its_own_reason(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();
		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY ), 'nai@example.com' );

		$result = ANPA_Socios_Email_Template_Repo::restore_default( self::KEY, 'pai@example.com' );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['customised'] );

		$default = ANPA_Socios_Email_Template_Defaults::load( self::KEY );
		$this->assertSame( $default['subject'], (string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['subject'] );
		$this->assertSame( 'restore_default', (string) ANPA_Socios_Email_Template_Repo::versions( self::KEY )[0]['archived_reason'] );
	}

	public function test_history_records_whether_the_replaced_state_was_customised(): void {
		ANPA_Socios_Email_Template_Repo::seed_missing();
		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY, 'Un' ), 'nai@example.com' );
		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY, 'Dous' ), 'nai@example.com' );

		$versions = ANPA_Socios_Email_Template_Repo::versions( self::KEY );

		// Newest archived row replaced a customised state; the oldest replaced the shipped default.
		$this->assertSame( '1', (string) $versions[0]['was_customised'] );
		$this->assertSame( '0', (string) $versions[ count( $versions ) - 1 ]['was_customised'] );
	}

	// ── Newer shipped defaults inform, they do not decide ───────────────

	public function test_a_newer_default_is_reported_for_a_customised_row_and_not_applied(): void {
		global $wpdb;

		ANPA_Socios_Email_Template_Repo::seed_missing();
		ANPA_Socios_Email_Template_Repo::save( self::KEY, $this->edited( self::KEY ), 'nai@example.com' );

		// Simulate a row seeded from an older shipped version.
		$templates = ANPA_Socios_DB::tabela_email_templates();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$templates}` SET default_version = 0 WHERE template_key = %s", self::KEY ) );

		$outdated = ANPA_Socios_Email_Template_Repo::outdated();
		$this->assertArrayHasKey( self::KEY, $outdated );
		$this->assertTrue( $outdated[ self::KEY ]['customised'] );

		$result = ANPA_Socios_Email_Template_Repo::adopt_newer_defaults( 'system' );
		$this->assertContains( self::KEY, $result['reported'] );
		$this->assertNotContains( self::KEY, $result['adopted'] );
		$this->assertStringStartsWith( 'Editado', (string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['subject'] );
	}

	public function test_a_newer_default_is_adopted_for_a_row_nobody_customised(): void {
		global $wpdb;

		ANPA_Socios_Email_Template_Repo::seed_missing();

		$templates = ANPA_Socios_DB::tabela_email_templates();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare(
			"UPDATE `{$templates}` SET default_version = 0, subject = %s WHERE template_key = %s",
			'Asunto vello enviado por unha versión anterior',
			self::KEY
		) );

		$result = ANPA_Socios_Email_Template_Repo::adopt_newer_defaults( 'system' );

		$this->assertContains( self::KEY, $result['adopted'] );
		$this->assertSame(
			ANPA_Socios_Email_Template_Defaults::load( self::KEY )['subject'],
			(string) ANPA_Socios_Email_Template_Repo::get( self::KEY )['subject']
		);
	}

	// ── Stored content is renderable ────────────────────────────────────

	public function test_every_seeded_template_renders_from_its_own_sample_data(): void {
		// Storage that round-trips but produces an unrenderable template would be a silent trap.
		ANPA_Socios_Email_Template_Repo::seed_missing();

		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$row = ANPA_Socios_Email_Template_Repo::get( (string) $key );
			$this->assertIsArray( $row, "{$key} was not seeded" );

			$rendered = ANPA_Socios_Email_Template_Renderer::render(
				array(
					'subject'   => (string) $row['subject'],
					'body_html' => (string) $row['body_html'],
					'body_text' => (string) $row['body_text'],
				),
				$definition->sample_data(),
				$definition->declared_tokens()
			);

			$this->assertTrue( $rendered['ok'], "{$key}: a stored template did not render" );
			$this->assertStringNotContainsString( '{{', $rendered['body_html'], "{$key}: an unresolved token reached the body" );
		}
	}
}
