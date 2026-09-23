<?php
/**
 * 1.65.0: the fase35 email queue is gone — code, wiring, tables (via migration
 * 1.42.0) and uninstall exception. Only ANPA_Socios_Email_Ownership (shared
 * prefix, unrelated purpose) survives.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Cola_Retirada extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_queue_classes_and_tests_no_longer_exist(): void {
		$root = dirname( __DIR__ );
		foreach ( array(
			'includes/class-anpa-socios-email-queue.php', 'includes/class-anpa-socios-email-queue-repo.php',
			'includes/class-anpa-socios-email-processor.php', 'includes/class-anpa-socios-email-cron.php',
			'includes/class-anpa-socios-email-purge.php', 'includes/class-anpa-socios-email-admin-actions.php',
			'includes/class-anpa-socios-email-communications-page.php', 'includes/class-anpa-socios-email-render-provider.php',
			'includes/class-anpa-socios-email-template-render-provider.php',
			'includes/lib/class-anpa-socios-email-campaign-state.php', 'includes/lib/class-anpa-socios-email-recipient-state.php',
			'includes/lib/class-anpa-socios-email-backoff.php', 'includes/lib/class-anpa-socios-email-recipients.php',
			'includes/lib/class-anpa-socios-email-batch-planner.php', 'includes/lib/class-anpa-socios-email-retention.php',
		) as $rel ) {
			$this->assertFileDoesNotExist( $root . '/' . $rel, $rel );
		}
		$this->assertFileExists( $root . '/includes/class-anpa-socios-email-ownership.php', 'Ownership is unrelated to the queue and stays' );
	}

	public function test_bootstrap_and_test_config_reference_none_of_it(): void {
		$pattern = '/class-anpa-socios-email-(queue|queue-repo|processor|cron|purge|admin-actions|communications-page|render-provider|template-render-provider|campaign-state|recipient-state|backoff|recipients|batch-planner|retention)\.php/';
		foreach ( array( 'anpa-socios.php', 'tests/bootstrap.php', 'phpunit.xml', 'phpunit-integration.xml' ) as $rel ) {
			$this->assertSame( 0, preg_match( $pattern, $this->src( $rel ) ), "$rel still references a queue file" );
		}
		$main = $this->src( 'anpa-socios.php' );
		foreach ( array( 'ANPA_Socios_Email_Cron', 'ANPA_Socios_Email_Admin_Actions', 'anpa_socios_email_render_provider', 'ANPA_Socios_Email_Template_Render_Provider' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $main );
		}
		$this->assertStringContainsString( "define( 'ANPA_SOCIOS_DB_VERSION', '1.43.0' )", $main );
	}

	public function test_migration_1_42_0_drops_the_tables_and_forgets_only_queue_options(): void {
		$db = $this->src( 'includes/class-anpa-socios-db.php' );
		$this->assertStringContainsString( "const DB_VERSION = '1.43.0';", $db );
		$this->assertStringContainsString( "version_compare( \$installed_version, '1.42.0', '<' ) && ! self::migrate_to_1_42_0()", $db );
		$this->assertStringContainsString( 'Migration halted at step 1.42.0', $db );

		$start = strpos( $db, 'private static function migrate_to_1_42_0' );
		$this->assertNotFalse( $start );
		$body = substr( $db, $start );
		foreach ( array( 'anpa_email_attempts', 'anpa_email_recipients', 'anpa_email_campaigns' ) as $t ) {
			$this->assertStringContainsString( "'{$t}'", $body );
		}
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS {$table}', $body );
		$this->assertStringContainsString( "delete_option( \$option );", $body );
		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'anpa_socios_email_queue_tick' )", $body );
		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'anpa_socios_email_purge_daily' )", $body );
		// The fase36 template store and the signature are NOT queue options.
		$this->assertStringNotContainsString( "'anpa_socios_email_templates'", $body );
		$this->assertStringNotContainsString( "'anpa_socios_email_signature'", $body );

		// 1.39.0 no longer creates anything, and the table helpers are gone.
		$s39  = strpos( $db, 'private static function migrate_to_1_39_0' );
		$b39  = substr( $db, $s39, strpos( $db, "\n\t}\n", $s39 ) - $s39 );
		$this->assertStringNotContainsString( 'CREATE TABLE', $b39 );
		$this->assertStringContainsString( 'return true;', $b39 );
		$this->assertStringNotContainsString( 'tabela_email_', $db );
		$this->assertStringNotContainsString( 'OPTION_DELETE_COMMS_ON_UNINSTALL', $db );
	}

	public function test_uninstall_has_no_communications_exception_any_more(): void {
		$u = $this->src( 'uninstall.php' );
		$this->assertStringNotContainsString( 'anpa_socios_delete_comms_on_uninstall', $u );
		$this->assertStringNotContainsString( '$preserve', $u );
		$this->assertStringContainsString( "DROP TABLE IF EXISTS", $u );
	}

	public function test_settings_have_no_retention_screen_or_handlers(): void {
		$s = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		foreach ( array( 'render_subsection_comunicacions', 'handle_save_comms_retention', 'comms_retention_saved', 'OPTION_DELETE_COMMS_ON_UNINSTALL', 'Email_Communications_Page' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $s, $needle );
		}
		$nav = $this->src( 'includes/lib/class-anpa-socios-admin-nav.php' );
		$this->assertStringNotContainsString( 'anpa-socios-comunicacions', $nav );
	}
}
