<?php
/**
 * Pure tests for the retention policy + inspection tests for the purge runner,
 * the daily maintenance event and the documentation (fase35, PR-35s6).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Retention extends TestCase {

	private string $purge;
	private string $cron;
	private string $settings;
	private string $docs;
	private string $bootstrap;

	protected function setUp(): void {
		$root            = dirname( __DIR__ );
		$this->purge     = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-purge.php' );
		$this->cron      = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-cron.php' );
		$this->settings  = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-settings.php' );
		$this->docs      = (string) file_get_contents( $root . '/docs/email-queue.md' );
		$this->bootstrap = (string) file_get_contents( $root . '/anpa-socios.php' );
	}

	// ── Pure policy ─────────────────────────────────────────────────────

	public function test_default_windows_are_the_documented_ones(): void {
		$this->assertSame( 30, ANPA_Socios_Email_Retention::PAYLOAD_DAYS_DEFAULT );
		$this->assertSame( 365, ANPA_Socios_Email_Retention::METADATA_DAYS_DEFAULT );
		$this->assertSame( 30, ANPA_Socios_Email_Retention::payload_days( 30 ) );
		$this->assertSame( 365, ANPA_Socios_Email_Retention::metadata_days( 365 ) );
	}

	public function test_windows_are_clamped_so_live_data_is_never_purged(): void {
		// Zero or negative would purge data that is still in use.
		$this->assertSame( ANPA_Socios_Email_Retention::PAYLOAD_DAYS_MIN, ANPA_Socios_Email_Retention::payload_days( 0 ) );
		$this->assertSame( ANPA_Socios_Email_Retention::PAYLOAD_DAYS_MIN, ANPA_Socios_Email_Retention::payload_days( -5 ) );
		$this->assertSame( ANPA_Socios_Email_Retention::METADATA_DAYS_MIN, ANPA_Socios_Email_Retention::metadata_days( 1 ) );
		// And an absurdly long window is capped: no indefinite retention.
		$this->assertSame( ANPA_Socios_Email_Retention::PAYLOAD_DAYS_MAX, ANPA_Socios_Email_Retention::payload_days( 99999 ) );
		$this->assertSame( ANPA_Socios_Email_Retention::METADATA_DAYS_MAX, ANPA_Socios_Email_Retention::metadata_days( 99999 ) );
	}

	public function test_garbage_input_falls_back_to_the_default(): void {
		foreach ( array( '', 'abc', null, true, false, array( 1 ) ) as $bad ) {
			$this->assertSame( ANPA_Socios_Email_Retention::PAYLOAD_DAYS_DEFAULT, ANPA_Socios_Email_Retention::payload_days( $bad ) );
		}
		$this->assertSame( 45, ANPA_Socios_Email_Retention::payload_days( '45' ), 'numeric strings from a form are accepted' );
	}

	public function test_metadata_window_can_never_be_shorter_than_the_payload_window(): void {
		// Otherwise the rows would disappear before their sensitive layer was cleared.
		$this->assertSame( 300, ANPA_Socios_Email_Retention::metadata_days( 40, 300 ) );
		$this->assertGreaterThanOrEqual(
			ANPA_Socios_Email_Retention::payload_days( 365 ),
			ANPA_Socios_Email_Retention::metadata_days( 30, 365 )
		);
	}

	public function test_cutoff_is_utc_and_moves_backwards_in_time(): void {
		$now = 1750000000;
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now - 30 * 86400 ), ANPA_Socios_Email_Retention::cutoff_utc( 30, $now ) );
		$this->assertLessThan(
			ANPA_Socios_Email_Retention::cutoff_utc( 30, $now ),
			ANPA_Socios_Email_Retention::cutoff_utc( 365, $now ),
			'a longer window yields an older cutoff'
		);
	}

	public function test_eligibility_needs_a_terminal_date_older_than_the_cutoff(): void {
		$now    = 1750000000;
		$cutoff = ANPA_Socios_Email_Retention::cutoff_utc( 30, $now );

		$this->assertTrue( ANPA_Socios_Email_Retention::due( gmdate( 'Y-m-d H:i:s', $now - 40 * 86400 ), $cutoff ) );
		$this->assertFalse( ANPA_Socios_Email_Retention::due( gmdate( 'Y-m-d H:i:s', $now - 10 * 86400 ), $cutoff ) );
		$this->assertFalse( ANPA_Socios_Email_Retention::due( '', $cutoff ), 'no terminal date is never eligible' );
	}

	// ── Purge runner contracts ──────────────────────────────────────────

	public function test_the_runner_is_wired_and_guarded(): void {
		$this->assertStringContainsString( 'class-anpa-socios-email-purge.php', $this->bootstrap );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Cron::PURGE_HOOK', $this->bootstrap );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Queue::can_run()', $this->purge );
		$this->assertStringContainsString( "'blocked'", $this->purge );
		// It never sends.
		$this->assertStringNotContainsString( 'wp_mail', $this->purge );
	}

	public function test_payload_is_purged_before_metadata(): void {
		$start = strpos( $this->purge, 'public static function run' );
		$body  = substr( $this->purge, (int) $start, 2200 );
		$this->assertLessThan(
			strpos( $body, 'purge_metadata(' ),
			strpos( $body, 'purge_payloads(' ),
			'the sensitive layer must go first'
		);
	}

	public function test_only_terminal_campaigns_are_purged(): void {
		foreach ( array( 'purge_payloads', 'deletable_campaign_ids' ) as $method ) {
			$start = strpos( $this->purge, 'function ' . $method );
			$body  = substr( $this->purge, (int) $start, 2200 );
			$this->assertStringContainsString( 'ANPA_Socios_Email_Campaign_State::FINISHED', $body, "$method must restrict to terminal campaigns" );
			$this->assertStringContainsString( 'ANPA_Socios_Email_Campaign_State::CANCELLED', $body );
		}
	}

	public function test_the_hash_survives_the_payload_purge(): void {
		$start = strpos( $this->purge, 'function purge_payloads' );
		$body  = substr( $this->purge, (int) $start, 2200 );
		$this->assertStringContainsString( 'payload_snapshot = NULL', $body );
		$this->assertStringContainsString( "subject_render = ''", $body );
		$this->assertStringContainsString( "last_error = ''", $body );
		// The proof that something was sent must NOT be destroyed here.
		$this->assertStringNotContainsString( 'payload_hash =', $body );
		$this->assertStringNotContainsString( 'accepted_at_utc =', $body );
		$this->assertStringNotContainsString( 'state =', $body );
	}

	public function test_a_campaign_with_unfinished_recipients_is_never_deleted(): void {
		$start = strpos( $this->purge, 'function deletable_campaign_ids' );
		$body  = substr( $this->purge, (int) $start, 2200 );
		$this->assertStringContainsString( 'NOT EXISTS', $body );
		$this->assertStringContainsString( 'r.state NOT IN (%s, %s, %s)', $body );
	}

	public function test_children_are_deleted_before_their_campaign(): void {
		$start = strpos( $this->purge, 'function purge_metadata' );
		$body  = substr( $this->purge, (int) $start, 2200 );
		$attempts   = strpos( $body, 'tabela_email_attempts' );
		$recipients = strpos( $body, 'tabela_email_recipients' );
		$campaigns  = strpos( $body, 'DELETE FROM {$campaigns}' );
		$this->assertLessThan( $campaigns, $attempts, 'attempts must go before the campaign' );
		$this->assertLessThan( $campaigns, $recipients, 'recipients must go before the campaign' );
	}

	public function test_each_run_is_bounded(): void {
		$this->assertStringContainsString( 'const BATCH = 200', $this->purge );
		$this->assertStringContainsString( 'LIMIT {$limit}', $this->purge );
	}

	// ── Daily maintenance event ─────────────────────────────────────────

	public function test_the_daily_event_is_scheduled_and_unscheduled_with_the_queue_tick(): void {
		$this->assertStringContainsString( "const PURGE_HOOK = 'anpa_socios_email_purge_daily'", $this->cron );
		$this->assertStringContainsString( "wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK )", $this->cron );
		// Deactivation removes BOTH events and deletes nothing.
		$start = strpos( $this->cron, 'public static function unschedule' );
		$body  = substr( $this->cron, (int) $start, 700 );
		$this->assertStringContainsString( 'self::HOOK, self::PURGE_HOOK', $body );
		$this->assertStringNotContainsString( 'DROP', $body );
		$this->assertStringNotContainsString( 'delete_option', $body );
	}

	public function test_the_purge_callback_is_guarded_like_the_queue_tick(): void {
		$start = strpos( $this->cron, 'public static function purge_tick' );
		$body  = substr( $this->cron, (int) $start, 700 );
		$this->assertStringContainsString( 'wp_installing()', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Purge::run()', $body );
	}

	// ── Settings surface ────────────────────────────────────────────────

	public function test_the_periods_form_is_isolated_and_normalised(): void {
		$start = strpos( $this->settings, 'public static function handle_save_comms_retention_periods' );
		$this->assertIsInt( $start, 'missing handler' );
		$body = substr( $this->settings, (int) $start, 1200 );

		$this->assertStringContainsString( "self::guard( 'anpa_socios_save_comms_retention_periods' )", $body );
		// Exactly the two window options, nothing else.
		$this->assertSame( 2, substr_count( $body, 'update_option(' ) );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Retention::payload_days(', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Retention::metadata_days(', $body );
		$this->assertStringContainsString( "add_action( 'admin_post_anpa_socios_save_comms_retention_periods'", $this->settings );
	}

	public function test_the_form_shows_the_bounds_it_enforces(): void {
		$start = strpos( $this->settings, 'private static function render_subsection_comunicacions_retencion' );
		$body  = substr( $this->settings, (int) $start, 3500 );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Retention::PAYLOAD_DAYS_MIN', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Retention::METADATA_DAYS_MAX', $body );
		$this->assertStringContainsString( 'Non pode ser menor que a ventá do contido enviado.', $body );
	}

	// ── Documentation ───────────────────────────────────────────────────

	public function test_the_documentation_covers_what_an_operator_needs(): void {
		foreach ( array(
			'"Aceptado" non é "entregado"',
			'DISABLE_WP_CRON',
			'wp-cron.php',
			'Procesar agora',
			'incerto',
			'30 días por defecto',
			'365 días por defecto',
			'Desactivar o plugin',
			'anpa_socios_email_cron_interval',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $this->docs, "docs must explain: $needle" );
		}
		// It must not promise delivery.
		$this->assertStringNotContainsString( 'garantimos a entrega', $this->docs );
	}
}
