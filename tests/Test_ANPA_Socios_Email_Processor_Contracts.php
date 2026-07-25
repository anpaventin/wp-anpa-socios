<?php
/**
 * Inspection tests (support only) for the fase35 PR-35s4 processor and admin
 * actions: transport discipline, lease ownership, at-least-once wording, bounded
 * runs, migration guard, and capability + nonce on every write action.
 *
 * These verify CONTRACTS, not runtime behaviour — that is covered by the real
 * integration tests in Test_ANPA_Socios_Email_Processor_Integration.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Processor_Contracts extends TestCase {

	private string $processor;
	private string $actions;
	private string $queue;
	private string $cron;
	private string $bootstrap;

	protected function setUp(): void {
		$root            = dirname( __DIR__ );
		$this->processor = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-processor.php' );
		$this->actions   = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-admin-actions.php' );
		$this->queue     = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-queue.php' );
		$this->cron      = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-cron.php' );
		$this->bootstrap = (string) file_get_contents( $root . '/anpa-socios.php' );
	}

	public function test_processor_and_actions_are_wired_in_bootstrap(): void {
		$this->assertStringContainsString( 'class-anpa-socios-email-processor.php', $this->bootstrap );
		$this->assertStringContainsString( 'class-anpa-socios-email-admin-actions.php', $this->bootstrap );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Admin_Actions::register()', $this->bootstrap );
	}

	public function test_cron_tick_reaches_the_processor_through_the_queue(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Email_Queue::process_due_batch', $this->cron );
		$this->assertStringContainsString( 'public static function process_due_batch', $this->queue );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Processor::run', $this->queue );
	}

	// ── Transport discipline ────────────────────────────────────────────

	/**
	 * Strips comments so a docblock that merely NAMES a function is not counted as
	 * a call site.
	 *
	 * @param  string $src Source.
	 * @return string
	 */
	private function code_only( string $src ): string {
		$src = preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src;
		$src = preg_replace( '!^\s*//.*$!m', '', $src ) ?? $src;
		return $src;
	}

	public function test_only_the_processor_talks_to_the_transport(): void {
		// The single wp_mail() call site in the queue stack (comments excluded).
		$this->assertSame( 1, substr_count( $this->code_only( $this->processor ), 'wp_mail(' ) );
		$this->assertStringNotContainsString( 'wp_mail', $this->queue );
		$this->assertStringNotContainsString( 'wp_mail', $this->actions );
	}

	public function test_the_transport_result_is_treated_as_accepted_not_delivered(): void {
		$this->assertStringContainsString( 'ACCEPTED BY THE LOCAL MAIL SYSTEM, never delivered', $this->processor );
		$this->assertStringContainsString( 'AT-LEAST-ONCE', $this->processor );
		// A failure reason is captured from core instead of being invented.
		$this->assertStringContainsString( "add_action( 'wp_mail_failed'", $this->processor );
		$this->assertStringContainsString( "remove_action( 'wp_mail_failed'", $this->processor );
	}

	public function test_the_payload_is_read_from_the_frozen_snapshot(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Email_Render::thaw', $this->processor );
		// The processor must not re-render at send time (that would break immutability).
		$this->assertStringNotContainsString( 'ANPA_Socios_Email_Render::freeze', $this->processor );
		$this->assertStringNotContainsString( 'ANPA_Socios_Email_Render::provider', $this->processor );
	}

	// ── Guards and bounds ───────────────────────────────────────────────

	public function test_a_run_is_blocked_during_install_or_pending_migration(): void {
		$start = strpos( $this->processor, 'public static function run' );
		$body  = substr( $this->processor, $start, 1200 );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Queue::can_run()', $body );
		$this->assertStringContainsString( "'blocked'", $body );
	}

	public function test_a_run_is_bounded_by_both_batch_size_and_wall_clock(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Email_Batch_Planner::BATCH_DEFAULT', $this->processor );
		$this->assertStringContainsString( '$deadline', $this->processor );
		$this->assertStringContainsString( "'time_budget'", $this->processor );
		// The budget itself is clamped, so a filter cannot make a run unbounded.
		$start = strpos( $this->processor, 'public static function max_seconds' );
		$body  = substr( $this->processor, $start, 400 );
		$this->assertStringContainsString( 'self::MAX_SECONDS_CEILING', $body );
	}

	public function test_unprocessed_rows_are_given_back_instead_of_staying_leased(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Email_Queue_Repo::release_unprocessed', $this->processor );
		$repo  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-email-queue-repo.php' );
		$start = strpos( $repo, 'public static function release_unprocessed' );
		$body  = substr( $repo, (int) $start, 1200 );
		// Only rows this lease still holds, and never a row already written.
		$this->assertStringContainsString( 'WHERE lease_token = %s AND state = %s', $body );
		$this->assertStringNotContainsString( 'attempts = attempts + 1', $body );
	}

	public function test_the_overlap_lock_is_acquired_atomically_and_released(): void {
		$start = strpos( $this->processor, 'private static function acquire_lock' );
		$body  = substr( $this->processor, $start, 800 );
		// add_option() only succeeds when the option is absent.
		$this->assertStringContainsString( 'add_option( self::LOCK_OPTION', $body );
		$this->assertStringContainsString( 'self::LOCK_TTL', $body, 'a crashed run must not lock the queue forever' );
		$this->assertStringContainsString( 'delete_option( self::LOCK_OPTION )', $this->processor );
		$this->assertStringContainsString( '} finally {', $this->processor, 'the lock is released even on failure' );
	}

	// ── Interrupted sends ───────────────────────────────────────────────

	public function test_interrupted_rows_are_recorded_as_uncertain_before_retrying(): void {
		$start = strpos( $this->processor, 'public static function recover_interrupted' );
		$body  = substr( $this->processor, $start, 1400 );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Queue_Repo::find_orphans', $body );
		$this->assertStringContainsString( "'error_category' => 'uncertain'", $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Queue_Repo::recover_orphans', $body );
		// The attempt is recorded BEFORE the row is released.
		$this->assertLessThan(
			strpos( $body, 'recover_orphans' ),
			strpos( $body, 'record_attempt' ),
			'the uncertain attempt must be logged before the row is reclaimable'
		);
	}

	public function test_a_lost_lease_never_overwrites_another_owners_result(): void {
		$start = strpos( $this->processor, 'private static function deliver_one' );
		$body  = substr( $this->processor, $start, 3000 );
		$this->assertStringContainsString( 'if ( ! $applied )', $body );
		$this->assertStringContainsString( "'uncertain'", $body );
	}

	public function test_completion_is_decided_by_real_recipient_state(): void {
		$start = strpos( $this->processor, 'private static function close_if_complete' );
		$body  = substr( $this->processor, $start, 900 );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Queue_Repo::count_unfinished', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Campaign_State::FINISHED', $body );
	}

	// ── Control operations ──────────────────────────────────────────────

	public function test_control_operations_go_through_the_state_machine(): void {
		foreach ( array( 'pause', 'resume', 'cancel', 'retry_failed' ) as $method ) {
			$this->assertStringContainsString( 'public static function ' . $method, $this->queue );
		}
		$this->assertStringContainsString( 'set_campaign_state', $this->queue );
		// Cancelling never touches accepted rows (the repo enforces it) and a
		// terminal campaign refuses a retry instead of stranding pending rows.
		$this->assertStringContainsString( 'cancel_pending_recipients', $this->queue );
		$this->assertStringContainsString( "'campaign_terminal'", $this->queue );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Campaign_State::terminal(', $this->queue );
	}

	// ── Admin actions: capability + nonce + audit ───────────────────────

	public function test_every_admin_action_is_registered_only_for_logged_in_admins(): void {
		foreach ( array( 'ACTION_PROCESS', 'ACTION_PAUSE', 'ACTION_RESUME', 'ACTION_CANCEL', 'ACTION_RETRY' ) as $const ) {
			$this->assertStringContainsString( "add_action( 'admin_post_' . self::" . $const, $this->actions );
		}
		$this->assertStringNotContainsString( 'admin_post_nopriv', $this->actions );
	}

	public function test_authorization_checks_capability_and_nonce_and_dies_on_failure(): void {
		$start = strpos( $this->actions, 'private static function authorize' );
		$body  = substr( $this->actions, $start, 1200 );
		$this->assertStringContainsString( 'current_user_can( self::CAP )', $body );
		$this->assertStringContainsString( 'check_admin_referer( $action )', $body );
		$this->assertStringContainsString( 'wp_die(', $body );
		$this->assertStringContainsString( "'manage_options'", $this->actions );
	}

	public function test_every_handler_authorizes_before_touching_the_queue(): void {
		foreach ( array( 'handle_process_now', 'handle_pause', 'handle_resume', 'handle_cancel', 'handle_retry_failed' ) as $handler ) {
			$start = strpos( $this->actions, 'public static function ' . $handler );
			$this->assertIsInt( $start, "missing $handler" );
			$body = substr( $this->actions, $start, 900 );
			$this->assertLessThan(
				strpos( $body, 'ANPA_Socios_Email_Queue::' ),
				strpos( $body, 'self::authorize(' ),
				"$handler must authorize before calling the queue"
			);
		}
	}

	public function test_actions_are_audited_and_redirect_safely(): void {
		$this->assertMatchesRegularExpression( "/do_action\(\s*'anpa_socios_email_admin_action'/", $this->actions );
		$this->assertStringContainsString( 'wp_safe_redirect(', $this->actions );
		// The audit trail records the actor and outcome, never addresses or payloads.
		$start = strpos( $this->actions, 'private static function audit' );
		$body  = substr( $this->actions, $start, 900 );
		$this->assertStringContainsString( "'actor'", $body );
		$this->assertStringContainsString( "'outcome'", $body );
		$this->assertStringNotContainsString( 'payload', $body );
		$this->assertStringNotContainsString( 'email' . '_body', $body );
	}
}
