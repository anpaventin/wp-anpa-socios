<?php
/**
 * Inspection tests (support only) for the fase35 email-queue lifecycle wiring:
 * cron registration/recovery/unschedule, uninstall preservation, and bootstrap
 * hooks. These verify WIRING, not runtime behaviour (that is the integration
 * matrix T-INT-*).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Lifecycle extends TestCase {

	private string $cron;
	private string $bootstrap;
	private string $uninstall;

	protected function setUp(): void {
		$root            = dirname( __DIR__ );
		$this->cron      = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-cron.php' );
		$this->bootstrap = (string) file_get_contents( $root . '/anpa-socios.php' );
		$this->uninstall = (string) file_get_contents( $root . '/uninstall.php' );
	}

	public function test_cron_interval_is_bounded_and_filterable(): void {
		$this->assertStringContainsString( "const DEFAULT_INTERVAL = 300", $this->cron );
		$this->assertStringContainsString( "const MIN_INTERVAL = 60", $this->cron );
		$this->assertStringContainsString( "const MAX_INTERVAL = 3600", $this->cron );
		$this->assertStringContainsString( "apply_filters( 'anpa_socios_email_cron_interval'", $this->cron );
		// Clamp both bounds.
		$this->assertStringContainsString( 'self::MIN_INTERVAL', $this->cron );
		$this->assertStringContainsString( 'self::MAX_INTERVAL', $this->cron );
	}

	public function test_cron_schedules_idempotently_and_recovers(): void {
		$this->assertStringContainsString( 'public static function schedule', $this->cron );
		$this->assertStringContainsString( 'wp_next_scheduled( self::HOOK )', $this->cron );
		$this->assertStringContainsString( 'wp_schedule_event(', $this->cron );
		$this->assertStringContainsString( 'public static function ensure_scheduled', $this->cron );
	}

	public function test_cron_unschedule_removes_event_without_deleting_data(): void {
		$start = strpos( $this->cron, 'public static function unschedule' );
		$end   = strpos( $this->cron, 'public static function ensure_scheduled', $start );
		$body  = substr( $this->cron, $start, $end - $start );
		$this->assertStringContainsString( 'wp_unschedule_event(', $body );
		$this->assertStringContainsString( 'wp_clear_scheduled_hook( self::HOOK )', $body );
		// Must not touch tables/options/data.
		$this->assertStringNotContainsString( 'DROP TABLE', $body );
		$this->assertStringNotContainsString( 'delete_option', $body );
		$this->assertStringNotContainsString( '$wpdb', $body );
	}

	public function test_cron_tick_is_guarded_noop_until_processor_exists(): void {
		$start = strpos( $this->cron, 'public static function tick' );
		$body  = substr( $this->cron, $start, 700 );
		$this->assertStringContainsString( 'wp_installing()', $body );
		$this->assertStringContainsString( "class_exists( 'ANPA_Socios_Email_Queue' )", $body );
		// Never sends email directly here.
		$this->assertStringNotContainsString( 'wp_mail', $body );
	}

	public function test_bootstrap_wires_cron_lifecycle(): void {
		$this->assertStringContainsString( "add_filter( 'cron_schedules', array( 'ANPA_Socios_Email_Cron', 'add_schedule' ) )", $this->bootstrap );
		$this->assertStringContainsString( "register_activation_hook( __FILE__, array( 'ANPA_Socios_Email_Cron', 'schedule' ) )", $this->bootstrap );
		$this->assertStringContainsString( "register_deactivation_hook( __FILE__, array( 'ANPA_Socios_Email_Cron', 'unschedule' ) )", $this->bootstrap );
		$this->assertStringContainsString( "add_action( ANPA_Socios_Email_Cron::HOOK, array( 'ANPA_Socios_Email_Cron', 'tick' ) )", $this->bootstrap );
		$this->assertStringContainsString( "add_action( 'admin_init', array( 'ANPA_Socios_Email_Cron', 'ensure_scheduled' ) )", $this->bootstrap );
	}

	public function test_uninstall_preserves_communications_by_default(): void {
		// Communications-only scope, defensively named (NOT "delete all data").
		$this->assertStringContainsString( "get_option( 'anpa_socios_delete_comms_on_uninstall'", $this->uninstall );
		$this->assertStringNotContainsString( 'anpa_socios_delete_all_on_uninstall', $this->uninstall );
		$this->assertStringContainsString( 'anpa_email_campaigns', $this->uninstall );
		$this->assertStringContainsString( 'anpa_email_recipients', $this->uninstall );
		$this->assertStringContainsString( 'anpa_email_attempts', $this->uninstall );
		$this->assertStringContainsString( 'in_array( $table, $preserve, true )', $this->uninstall );
		// Only the exact authorized value deletes; otherwise preserve.
		$this->assertStringContainsString( '$delete_comms ? array() :', $this->uninstall );
		// Documented multisite policy (per-site).
		$this->assertStringContainsString( 'MULTISITE', $this->uninstall );
	}
}
