<?php
/**
 * REAL migration integration tests for the fase36 template tables (1.40.0).
 *
 * A source-inspection test proves the migration SAYS the right thing; only a real engine proves
 * `dbDelta` actually accepts it. The 1.39.0 slice learned this the hard way: an index referencing a
 * column being dropped is valid-looking SQL that a real MySQL rejects.
 *
 * SKIPPED unless run under the WordPress test suite with a real $wpdb, signalled by
 * ANPA_SOCIOS_IT_DB (set by the CI/staging bootstrap). Locally the pure-logic bootstrap self-skips
 * so the unit suite stays green.
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Migration_Integration extends TestCase {

	protected function setUp(): void {
		if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
			$this->markTestSkipped( 'Integration DB not available: run under the WordPress test suite with a real $wpdb (define ANPA_SOCIOS_IT_DB).' );
		}
	}

	/** @return string[] */
	private function tables(): array {
		return array(
			ANPA_Socios_DB::tabela_email_templates(),
			ANPA_Socios_DB::tabela_email_template_versions(),
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function drop_all(): void {
		global $wpdb;

		foreach ( $this->tables() as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		}
	}

	public function test_clean_install_creates_both_tables(): void {
		$this->drop_all();
		delete_option( ANPA_Socios_DB::VERSION_OPTION );

		ANPA_Socios_DB::crear_tabelas();

		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ), "Missing table {$t} after clean install" );
		}
		$this->assertSame( ANPA_Socios_DB::DB_VERSION, get_option( ANPA_Socios_DB::VERSION_OPTION ) );
	}

	public function test_upgrade_from_the_previous_schema_reaches_the_same_state(): void {
		$this->drop_all();
		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.39.0' );

		ANPA_Socios_DB::crear_tabelas();

		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ) );
		}
		$this->assertSame( ANPA_Socios_DB::DB_VERSION, get_option( ANPA_Socios_DB::VERSION_OPTION ) );
	}

	public function test_a_second_run_is_a_no_op(): void {
		global $wpdb;

		ANPA_Socios_DB::crear_tabelas();
		$wpdb->last_error = '';
		ANPA_Socios_DB::crear_tabelas();

		$this->assertSame( '', (string) $wpdb->last_error, 'an idempotent re-run must not error' );
		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ) );
		}
	}

	public function test_a_partially_created_schema_is_repaired(): void {
		global $wpdb;

		ANPA_Socios_DB::crear_tabelas();
		$versions = ANPA_Socios_DB::tabela_email_template_versions();
		$wpdb->query( "DROP TABLE IF EXISTS `{$versions}`" );
		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.39.0' );

		ANPA_Socios_DB::crear_tabelas();

		$this->assertTrue( $this->table_exists( $versions ) );
	}

	public function test_the_migration_seeds_nothing(): void {
		// Schema and content are separate steps: a schema step that failed halfway must never leave
		// half a catalogue of templates behind.
		global $wpdb;

		$this->drop_all();
		delete_option( ANPA_Socios_DB::VERSION_OPTION );
		ANPA_Socios_DB::crear_tabelas();

		foreach ( $this->tables() as $t ) {
			$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ), "{$t} must be empty after migration" );
		}
	}

	public function test_an_existing_customised_row_survives_a_remigration(): void {
		// The whole point of is_customised: a schema step must never touch a board's wording.
		global $wpdb;

		ANPA_Socios_DB::crear_tabelas();
		$templates = ANPA_Socios_DB::tabela_email_templates();

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$templates}` (template_key, event_type, subject, body_html, body_text, is_customised, created_at_utc)
			 VALUES (%s, %s, %s, %s, %s, 1, %s)",
			'auth_access_code',
			'auth_access_code',
			'Asunto personalizado',
			'<p>Corpo personalizado</p>',
			'Corpo personalizado',
			gmdate( 'Y-m-d H:i:s' )
		) );

		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.39.0' );
		ANPA_Socios_DB::crear_tabelas();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT subject, is_customised FROM `{$templates}` WHERE template_key = %s",
			'auth_access_code'
		), ARRAY_A );

		$this->assertIsArray( $row );
		$this->assertSame( 'Asunto personalizado', $row['subject'] );
		$this->assertSame( '1', (string) $row['is_customised'] );
	}

	public function test_the_unique_key_refuses_a_duplicate_template_key(): void {
		global $wpdb;

		ANPA_Socios_DB::crear_tabelas();
		$templates = ANPA_Socios_DB::tabela_email_templates();
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$templates}` WHERE template_key = %s", 'it_duplicate_probe' ) );

		$insert = static function () use ( $wpdb, $templates ) {
			return $wpdb->query( $wpdb->prepare(
				"INSERT INTO `{$templates}` (template_key, event_type, created_at_utc) VALUES (%s, %s, %s)",
				'it_duplicate_probe',
				'it_probe',
				gmdate( 'Y-m-d H:i:s' )
			) );
		};

		$this->assertNotFalse( $insert() );
		$wpdb->suppress_errors( true );
		$this->assertFalse( $insert(), 'a second row with the same template_key must be refused by the engine' );
		$wpdb->suppress_errors( false );

		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$templates}` WHERE template_key = %s", 'it_duplicate_probe' ) );
	}

	public function test_history_survives_the_deletion_of_its_live_row(): void {
		// Why the history table carries a denormalised template_key and declares no foreign key: a
		// version must stay readable after the live row is gone.
		global $wpdb;

		ANPA_Socios_DB::crear_tabelas();
		$templates = ANPA_Socios_DB::tabela_email_templates();
		$versions  = ANPA_Socios_DB::tabela_email_template_versions();
		$now       = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$templates}` (template_key, event_type, subject, created_at_utc) VALUES (%s, %s, %s, %s)",
			'it_orphan_probe',
			'it_probe',
			'Asunto vello',
			$now
		) );
		$template_id = (int) $wpdb->insert_id;

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$versions}` (template_id, template_key, subject, archived_at_utc, archived_reason)
			 VALUES (%d, %s, %s, %s, %s)",
			$template_id,
			'it_orphan_probe',
			'Asunto vello',
			$now,
			'save'
		) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$templates}` WHERE id = %d", $template_id ) );

		$archived = $wpdb->get_row( $wpdb->prepare(
			"SELECT template_key, subject FROM `{$versions}` WHERE template_id = %d",
			$template_id
		), ARRAY_A );

		$this->assertIsArray( $archived, 'the archived version must outlive its live row' );
		$this->assertSame( 'it_orphan_probe', $archived['template_key'] );
		$this->assertSame( 'Asunto vello', $archived['subject'] );

		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$versions}` WHERE template_id = %d", $template_id ) );
	}

	public function test_uninstall_removes_the_template_tables(): void {
		// The deliberate asymmetry with the communications tables: templates are configuration and
		// re-seed from the shipped defaults, so they follow destructive-by-default.
		ANPA_Socios_DB::crear_tabelas();
		update_option( 'anpa_socios_delete_comms_on_uninstall', '0' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require_once dirname( __DIR__ ) . '/uninstall.php';
		anpa_socios_uninstall_cleanup();

		foreach ( $this->tables() as $t ) {
			$this->assertFalse( $this->table_exists( $t ), "{$t} must not be preserved on uninstall" );
		}
	}
}
