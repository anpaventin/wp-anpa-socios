<?php
/**
 * REAL migration integration tests for the fase35 email queue (1.39.0).
 *
 * These exercise the actual migration against a real WordPress + MySQL/MariaDB.
 * They are SKIPPED unless run under the WordPress test suite with a real $wpdb,
 * signalled by defining ANPA_SOCIOS_IT_DB (set by the CI/staging bootstrap).
 * Locally (pure-logic bootstrap with a $wpdb stub) they self-skip so the unit
 * suite stays green while these remain runnable in CI.
 *
 * Covers (user-requested):
 *   clean install, upgrade from 1.38.1, idempotent re-run, partially-created
 *   table, pre-existing index, existing data preserved, delete-option off,
 *   delete-option on, deactivation without data loss, and no campaigns/
 *   recipients created during migration.
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Migration_Integration extends TestCase {

	protected function setUp(): void {
		if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
			$this->markTestSkipped( 'Integration DB not available: run under the WordPress test suite with a real $wpdb (define ANPA_SOCIOS_IT_DB).' );
		}
	}

	private function tables(): array {
		return array(
			ANPA_Socios_DB::tabela_email_campaigns(),
			ANPA_Socios_DB::tabela_email_recipients(),
			ANPA_Socios_DB::tabela_email_attempts(),
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function test_clean_install_creates_the_three_tables(): void {
		global $wpdb;
		foreach ( $this->tables() as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		}
		delete_option( ANPA_Socios_DB::VERSION_OPTION );

		ANPA_Socios_DB::crear_tabelas();

		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ), "Missing table $t after clean install" );
		}
		$this->assertSame( '1.39.0', get_option( ANPA_Socios_DB::VERSION_OPTION ) );
	}

	public function test_upgrade_from_1_38_1_reaches_same_schema(): void {
		global $wpdb;
		foreach ( $this->tables() as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		}
		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.38.1' );

		ANPA_Socios_DB::crear_tabelas();

		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ) );
		}
		$this->assertSame( '1.39.0', get_option( ANPA_Socios_DB::VERSION_OPTION ) );
	}

	public function test_second_run_is_idempotent(): void {
		ANPA_Socios_DB::crear_tabelas();
		$wpdb_error_before = $GLOBALS['wpdb']->last_error;
		ANPA_Socios_DB::crear_tabelas(); // second run
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error, 'Idempotent re-run must not error' );
		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ) );
		}
	}

	public function test_partially_created_table_is_repaired(): void {
		global $wpdb;
		ANPA_Socios_DB::crear_tabelas();
		// Drop just one table and one version step back.
		$recipients = ANPA_Socios_DB::tabela_email_recipients();
		$wpdb->query( "DROP TABLE IF EXISTS `{$recipients}`" );
		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.38.1' );

		ANPA_Socios_DB::crear_tabelas();
		$this->assertTrue( $this->table_exists( $recipients ) );
	}

	public function test_existing_index_causes_no_error(): void {
		global $wpdb;
		ANPA_Socios_DB::crear_tabelas();
		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.38.1' );
		$wpdb->last_error = '';
		ANPA_Socios_DB::crear_tabelas(); // dbDelta over existing indexes
		$this->assertSame( '', (string) $wpdb->last_error );
	}

	public function test_existing_data_is_preserved_on_rerun(): void {
		global $wpdb;
		ANPA_Socios_DB::crear_tabelas();
		$campaigns = ANPA_Socios_DB::tabela_email_campaigns();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$campaigns}` (uuid, event_type, state, idempotency_key, created_at_utc) VALUES (%s,%s,%s,%s,%s)",
				'11111111-1111-1111-1111-111111111111',
				'test',
				'pending',
				str_repeat( 'a', 64 ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.38.1' );
		ANPA_Socios_DB::crear_tabelas();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$campaigns}`" );
		$this->assertSame( 1, $count, 'Existing campaign row must survive a re-migration' );
	}

	public function test_uninstall_preserves_communications_when_option_off(): void {
		global $wpdb;
		ANPA_Socios_DB::crear_tabelas();
		update_option( 'anpa_socios_delete_comms_on_uninstall', '0' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__ ) . '/uninstall.php';
		if ( function_exists( 'anpa_socios_uninstall_cleanup' ) ) {
			anpa_socios_uninstall_cleanup();
		}
		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ), "Comms table $t must be preserved when option off" );
		}
	}

	public function test_uninstall_deletes_communications_when_option_on(): void {
		global $wpdb;
		ANPA_Socios_DB::crear_tabelas();
		update_option( 'anpa_socios_delete_comms_on_uninstall', '1' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__ ) . '/uninstall.php';
		if ( function_exists( 'anpa_socios_uninstall_cleanup' ) ) {
			anpa_socios_uninstall_cleanup();
		}
		foreach ( $this->tables() as $t ) {
			$this->assertFalse( $this->table_exists( $t ), "Comms table $t must be dropped when option on" );
		}
	}

	public function test_deactivation_unschedules_without_data_loss(): void {
		ANPA_Socios_DB::crear_tabelas();
		ANPA_Socios_Email_Cron::schedule();
		$this->assertNotFalse( wp_next_scheduled( ANPA_Socios_Email_Cron::HOOK ) );

		ANPA_Socios_Email_Cron::unschedule();
		$this->assertFalse( wp_next_scheduled( ANPA_Socios_Email_Cron::HOOK ) );
		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ), 'Deactivation must not drop tables' );
		}
	}

	public function test_migration_creates_no_campaigns_or_recipients(): void {
		global $wpdb;
		foreach ( $this->tables() as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		}
		delete_option( ANPA_Socios_DB::VERSION_OPTION );
		ANPA_Socios_DB::crear_tabelas();

		$campaigns  = ANPA_Socios_DB::tabela_email_campaigns();
		$recipients = ANPA_Socios_DB::tabela_email_recipients();
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$campaigns}`" ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$recipients}`" ) );
	}
}
