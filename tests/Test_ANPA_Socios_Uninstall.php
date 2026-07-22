<?php
/**
 * Contract tests for uninstall.php (Error 1: full data removal on delete).
 *
 * Source-inspection style: uninstall.php runs in WordPress isolation, so we
 * assert its guarded, complete cleanup contract by reading the file.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Uninstall extends TestCase {

	private string $file;

	public function setUp(): void {
		parent::setUp();
		$this->file = dirname( __DIR__ ) . '/uninstall.php';
	}

	public function test_uninstall_file_exists_and_is_guarded(): void {
		$this->assertFileExists( $this->file );
		$src = file_get_contents( $this->file );
		$this->assertStringContainsString( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )", $src );
		$this->assertStringContainsString( 'exit;', $src );
	}

	public function test_uninstall_drops_all_anpa_tables(): void {
		$src = file_get_contents( $this->file );
		$this->assertStringContainsString( "\$wpdb->esc_like( \$wpdb->prefix . 'anpa_' ) . '%'", $src );
		$this->assertStringContainsString( 'SHOW TABLES LIKE %s', $src );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $src );
	}

	public function test_uninstall_deletes_all_anpa_options_and_transients(): void {
		$src = file_get_contents( $this->file );
		$this->assertStringContainsString( "DELETE FROM {\$wpdb->options} WHERE option_name LIKE %s", $src );
		$this->assertStringContainsString( "\$wpdb->esc_like( 'anpa_' ) . '%'", $src );
		$this->assertStringContainsString( '_transient_anpa_', $src );
		$this->assertStringContainsString( '_transient_timeout_anpa_', $src );
	}

	public function test_uninstall_clears_anpa_cron_and_handles_multisite(): void {
		$src = file_get_contents( $this->file );
		$this->assertStringContainsString( '_get_cron_array', $src );
		$this->assertStringContainsString( 'wp_clear_scheduled_hook', $src );
		$this->assertStringContainsString( "0 === strpos( \$hook, 'anpa' )", $src );
		$this->assertStringContainsString( 'is_multisite()', $src );
		$this->assertStringContainsString( 'switch_to_blog', $src );
		$this->assertStringContainsString( 'restore_current_blog', $src );
	}
}
