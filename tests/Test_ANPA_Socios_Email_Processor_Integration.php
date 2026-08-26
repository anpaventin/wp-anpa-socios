<?php
/**
 * REAL integration tests for the fase35 batch processor (PR-35s4).
 *
 * The transport is short-circuited with the core `pre_wp_mail` filter, so no
 * message ever leaves the machine while the processor still sees exactly what
 * wp_mail() would return (its boolean is the only signal it trusts).
 *
 * Covers T-INT-4 (overlapping runs), T-INT-7 (interrupted send → `uncertain`),
 * T-INT-9/10 (backoff, retry only touches failures), T-INT-11 (pause/resume/
 * cancel take effect immediately) and T-INT-12 (never send during a migration).
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Processor_Integration extends TestCase {

	/** @var array<int,string> Addresses the transport was asked to send to. */
	private array $sent = array();

	/** @var callable|null Active pre_wp_mail short circuit. */
	private $transport = null;

	protected function setUp(): void {
		if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
			$this->markTestSkipped( 'Integration DB not available (define ANPA_SOCIOS_IT_DB).' );
		}
		global $wpdb;
		ANPA_Socios_DB::crear_tabelas();
		update_option( ANPA_Socios_DB::VERSION_OPTION, ANPA_Socios_DB::DB_VERSION );
		delete_option( ANPA_Socios_Email_Processor::LOCK_OPTION );
		// These integration classes extend plain TestCase, so WordPress does NOT
		// reset filters between them: a render provider left behind by another
		// class would silently change the payload asserted here.
		remove_all_filters( 'anpa_socios_email_render_provider' );

		foreach ( array(
			ANPA_Socios_DB::tabela_email_attempts(),
			ANPA_Socios_DB::tabela_email_recipients(),
			ANPA_Socios_DB::tabela_email_campaigns(),
		) as $t ) {
			$wpdb->query( "DELETE FROM `{$t}`" );
		}
		$this->sent = array();
	}

	protected function tearDown(): void {
		if ( null !== $this->transport ) {
			remove_filter( 'pre_wp_mail', $this->transport, 10 );
			$this->transport = null;
		}
		delete_option( ANPA_Socios_Email_Processor::LOCK_OPTION );
	}

	/**
	 * Short-circuits wp_mail(). $accept decides the boolean the processor sees.
	 *
	 * @param bool   $accept  Whether the transport accepts the message.
	 * @param string $failure Error message reported through wp_mail_failed.
	 */
	private function transport( bool $accept, string $failure = 'SMTP unavailable', int $delay_us = 0 ): void {
		$this->transport = function ( $short_circuit, $atts ) use ( $accept, $failure, $delay_us ) {
			$to           = is_array( $atts['to'] ) ? (string) reset( $atts['to'] ) : (string) $atts['to'];
			$this->sent[] = $to;
			if ( $delay_us > 0 ) {
				usleep( $delay_us ); // Makes the wall-clock budget deterministic.
			}
			if ( $accept ) {
				return true;
			}
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $failure ) );
			return false;
		};
		add_filter( 'pre_wp_mail', $this->transport, 10, 2 );
	}

	/**
	 * @param int $n Number of recipients.
	 * @return array<string,mixed> Enqueue result.
	 */
	private function enqueue( int $n, string $key = 'run-1', array $extra = array() ): array {
		$rs = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$rs[] = array( 'email' => "u{$i}@example.com", 'recipient_type' => 'member', 'message_key' => "m{$i}" );
		}

		return ANPA_Socios_Email_Queue::enqueue_campaign(
			array_merge(
				array(
					'event_type'      => 'test_event',
					'idempotency_key' => $key,
					'recipients'      => $rs,
					'context'         => array( 'subject' => 'Asunto', 'body_html' => '<p>Ola</p>' ),
					'created_by'      => 'sistema',
				),
				$extra
			)
		);
	}

	/**
	 * @param int $recipient_id Recipient id.
	 * @return array<int,array<string,mixed>>
	 */
	private function attempts_of( int $recipient_id ): array {
		global $wpdb;
		$t = ANPA_Socios_DB::tabela_email_attempts();

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$t}` WHERE recipient_id = %d ORDER BY attempt_no", $recipient_id ),
			ARRAY_A
		);
	}

	/**
	 * @param int $campaign_id Campaign id.
	 * @return array<int,array<string,mixed>>
	 */
	private function recipients_of( int $campaign_id ): array {
		global $wpdb;
		$t = ANPA_Socios_DB::tabela_email_recipients();

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$t}` WHERE campaign_id = %d ORDER BY id", $campaign_id ),
			ARRAY_A
		);
	}

	// ── Happy path ──────────────────────────────────────────────────────

	public function test_a_run_sends_each_recipient_once_and_finishes_the_campaign(): void {
		$this->transport( true );
		$res = $this->enqueue( 3 );

		$out = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 3, $out['claimed'] );
		$this->assertSame( 3, $out['accepted'] );
		$this->assertSame( 0, $out['failed'] );
		$this->assertCount( 3, $this->sent, 'exactly one transport call per recipient' );

		foreach ( $this->recipients_of( $res['campaign_id'] ) as $row ) {
			$this->assertSame( ANPA_Socios_Email_Recipient_State::ACCEPTED, $row['state'] );
			$this->assertSame( 1, (int) $row['attempts'] );
			$this->assertSame( '', (string) $row['lease_token'], 'the lease is released on success' );
			$attempts = $this->attempts_of( (int) $row['id'] );
			$this->assertCount( 1, $attempts );
			$this->assertSame( 'accepted', $attempts[0]['result'] );
		}

		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $res['campaign_id'] );
		$this->assertSame( ANPA_Socios_Email_Campaign_State::FINISHED, $campaign['state'] );
		$this->assertSame( 3, (int) $campaign['accepted_count'] );
		$this->assertNotEmpty( $campaign['finished_at_utc'] );
	}

	public function test_a_second_run_never_resends_an_accepted_recipient(): void {
		$this->transport( true );
		$res = $this->enqueue( 2 );
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		$this->sent = array();

		$again = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$this->assertSame( 0, $again['claimed'] );
		$this->assertSame( 'idle', $again['code'] );
		$this->assertSame( array(), $this->sent, 'an accepted message must never be sent twice' );
	}

	public function test_two_sequential_runs_split_the_work_without_double_sending(): void {
		$this->transport( true );
		$res = $this->enqueue( 4 );

		$first  = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'], 'limit' => 2 ) );
		$second = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'], 'limit' => 2 ) );

		$this->assertSame( 2, $first['accepted'] );
		$this->assertSame( 2, $second['accepted'] );
		$this->assertCount( 4, $this->sent );
		$this->assertSame( 4, count( array_unique( $this->sent ) ), 'no address is sent to twice' );

		foreach ( $this->recipients_of( $res['campaign_id'] ) as $row ) {
			$this->assertCount( 1, $this->attempts_of( (int) $row['id'] ) );
		}
	}

	public function test_the_overlap_lock_makes_a_concurrent_run_a_no_op(): void {
		$this->transport( true );
		$res = $this->enqueue( 1 );
		add_option( ANPA_Socios_Email_Processor::LOCK_OPTION, (string) time(), '', false );

		$out = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'locked', $out['code'] );
		$this->assertSame( array(), $this->sent );
	}

	// ── Failures + backoff ──────────────────────────────────────────────

	public function test_a_transport_failure_is_recorded_and_retried_then_becomes_permanent(): void {
		$this->transport( false, 'SMTP  refused\nthe message' );
		$res = $this->enqueue( 1, 'run-fail', array( 'max_attempts' => 2 ) );
		$rid = (int) $this->recipients_of( $res['campaign_id'] )[0]['id'];

		$first = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		$this->assertSame( 1, $first['failed'] );

		$row = ANPA_Socios_Email_Queue_Repo::get_recipient( $rid );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::FAILED, $row['state'] );
		$this->assertNotEmpty( $row['next_attempt_at_utc'], 'a retryable failure is rescheduled' );
		$this->assertStringContainsString( 'refused', (string) $row['last_error'] );

		$attempt = $this->attempts_of( $rid )[0];
		$this->assertSame( 'failed', $attempt['result'] );
		$this->assertSame( 'transport', $attempt['error_category'] );

		// Not due yet: a run right now does nothing.
		$this->sent = array();
		$idle       = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		$this->assertSame( 0, $idle['claimed'] );
		$this->assertSame( array(), $this->sent );

		// Make it due; the second failure hits the ceiling (max_attempts = 2).
		global $wpdb;
		$t = ANPA_Socios_DB::tabela_email_recipients();
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET next_attempt_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = %d", $rid ) );

		$second = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		$this->assertSame( 1, $second['failed'] );

		$row = ANPA_Socios_Email_Queue_Repo::get_recipient( $rid );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT, $row['state'] );
		$this->assertCount( 2, $this->attempts_of( $rid ), 'one attempt row per attempt number' );

		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $res['campaign_id'] );
		$this->assertSame( ANPA_Socios_Email_Campaign_State::FINISHED, $campaign['state'] );
		$this->assertSame( 1, (int) $campaign['failed_count'] );
		$this->assertSame( 0, (int) $campaign['accepted_count'] );
	}

	public function test_retrying_a_terminal_campaign_is_refused_instead_of_stranding_rows(): void {
		$this->transport( false );
		$res = $this->enqueue( 2, 'run-retry-terminal', array( 'max_attempts' => 1 ) );
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $res['campaign_id'] );
		$this->assertSame( ANPA_Socios_Email_Campaign_State::FINISHED, $campaign['state'] );

		$retry = ANPA_Socios_Email_Queue::retry_failed( $res['campaign_id'] );
		$this->assertFalse( $retry['ok'] );
		$this->assertSame( 'campaign_terminal', $retry['code'] );
		$this->assertSame( 0, $retry['requeued'] );

		// The rows stay terminal: requeuing them into a finished campaign would
		// leave work that the processor can never claim.
		foreach ( $this->recipients_of( $res['campaign_id'] ) as $row ) {
			$this->assertSame( ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT, $row['state'] );
		}
	}

	public function test_retrying_a_live_campaign_requeues_only_the_failures(): void {
		$this->transport( false );
		$res = $this->enqueue( 3, 'run-retry-live', array( 'max_attempts' => 5 ) );

		// One recipient is accepted first, the other two fail retryably.
		remove_filter( 'pre_wp_mail', $this->transport, 10 );
		$this->transport( true );
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'], 'limit' => 1 ) );
		remove_filter( 'pre_wp_mail', $this->transport, 10 );
		$this->transport( false );
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'], 'limit' => 2 ) );

		$states = array_count_values( array_column( $this->recipients_of( $res['campaign_id'] ), 'state' ) );
		$this->assertSame( 1, $states[ ANPA_Socios_Email_Recipient_State::ACCEPTED ] ?? 0 );
		$this->assertSame( 2, $states[ ANPA_Socios_Email_Recipient_State::FAILED ] ?? 0 );

		$retry = ANPA_Socios_Email_Queue::retry_failed( $res['campaign_id'] );
		$this->assertTrue( $retry['ok'] );
		$this->assertSame( 2, $retry['requeued'], 'only the failures are requeued' );

		// Requeued rows are due immediately and are delivered on the next run;
		// the accepted one is never touched again.
		remove_filter( 'pre_wp_mail', $this->transport, 10 );
		$this->transport( true );
		$this->sent = array();
		$out        = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$this->assertSame( 2, $out['accepted'] );
		$this->assertCount( 2, $this->sent );
		$this->assertSame( 0, ANPA_Socios_Email_Queue_Repo::count_unfinished( $res['campaign_id'] ) );
	}

	// ── Interrupted sends (at-least-once) ───────────────────────────────

	public function test_an_interrupted_row_is_released_with_an_uncertain_attempt(): void {
		global $wpdb;
		$this->transport( true );
		$res = $this->enqueue( 1, 'run-orphan' );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		// Simulate a worker that claimed the row and then died.
		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 );
		$rid   = (int) $claim['rows'][0]['id'];
		$t     = ANPA_Socios_DB::tabela_email_recipients();
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET locked_until_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = %d", $rid ) );

		$out = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$this->assertSame( 1, $out['recovered'] );
		$uncertain = array_values(
			array_filter(
				$this->attempts_of( $rid ),
				static function ( $a ) {
					return 'uncertain' === $a['error_category'];
				}
			)
		);
		$this->assertCount( 1, $uncertain, 'a possible silent send must leave a trace' );
		$this->assertSame( 'uncertain', $uncertain[0]['result'] );

		// The interrupted attempt is consumed, and the row was retried in the same
		// run, so the recipient ends accepted with a second attempt recorded.
		$row = ANPA_Socios_Email_Queue_Repo::get_recipient( $rid );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::ACCEPTED, $row['state'] );
		$this->assertSame( 2, (int) $row['attempts'], 'the interrupted attempt counts against the ceiling' );
		$this->assertCount( 2, $this->attempts_of( $rid ) );
	}

	// ── Control operations take effect immediately ──────────────────────

	public function test_a_paused_campaign_is_not_processed_until_resumed(): void {
		$this->transport( true );
		$res = $this->enqueue( 2, 'run-pause' );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$this->assertTrue( ANPA_Socios_Email_Queue::pause( $res['campaign_id'] )['ok'] );
		$paused = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		$this->assertSame( 0, $paused['claimed'] );
		$this->assertSame( array(), $this->sent );

		$this->assertTrue( ANPA_Socios_Email_Queue::resume( $res['campaign_id'] )['ok'] );
		$resumed = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		$this->assertSame( 2, $resumed['accepted'] );
		$this->assertCount( 2, $this->sent );
	}

	public function test_cancelling_stops_pending_work_but_keeps_accepted(): void {
		$this->transport( true );
		$res = $this->enqueue( 3, 'run-cancel' );

		// Send only one, then cancel the rest.
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'], 'limit' => 1 ) );
		$cancel = ANPA_Socios_Email_Queue::cancel( $res['campaign_id'] );
		$this->assertTrue( $cancel['ok'] );
		$this->assertSame( 2, $cancel['cancelled'] );

		$this->sent = array();
		$after      = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		$this->assertSame( 0, $after['claimed'] );
		$this->assertSame( array(), $this->sent );

		$states = array_column( $this->recipients_of( $res['campaign_id'] ), 'state' );
		$this->assertContains( ANPA_Socios_Email_Recipient_State::ACCEPTED, $states, 'an accepted message cannot be un-sent' );
		$this->assertSame( 2, count( array_filter( $states, static function ( $s ) {
			return ANPA_Socios_Email_Recipient_State::CANCELLED === $s;
		} ) ) );

		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $res['campaign_id'] );
		$this->assertSame( ANPA_Socios_Email_Campaign_State::CANCELLED, $campaign['state'] );
		$this->assertSame( 1, (int) $campaign['accepted_count'] );
		$this->assertSame( 2, (int) $campaign['cancelled_count'] );
	}

	// ── Migration guard + budget ────────────────────────────────────────

	public function test_nothing_is_sent_while_a_migration_is_pending(): void {
		$this->transport( true );
		$res = $this->enqueue( 2, 'run-migration' );

		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.0.0' );
		$out = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		update_option( ANPA_Socios_DB::VERSION_OPTION, ANPA_Socios_DB::DB_VERSION );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'blocked', $out['code'] );
		$this->assertSame( array(), $this->sent, 'a migration must never trigger sends' );

		foreach ( $this->recipients_of( $res['campaign_id'] ) as $row ) {
			$this->assertSame( ANPA_Socios_Email_Recipient_State::PENDING, $row['state'] );
			$this->assertSame( 0, (int) $row['attempts'] );
		}
	}

	public function test_the_time_budget_gives_unprocessed_rows_straight_back(): void {
		// A deliberate 200 ms per send against a 1 s budget: the run provably stops
		// partway through 10 recipients instead of depending on machine speed.
		$this->transport( true, 'SMTP unavailable', 200000 );
		$res = $this->enqueue( 10, 'run-budget' );

		$out = ANPA_Socios_Email_Processor::run(
			array(
				'campaign_id' => $res['campaign_id'],
				'max_seconds' => 1,
			)
		);

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'time_budget', $out['code'], 'the run must stop on its budget, not on an empty queue' );
		$this->assertGreaterThanOrEqual( 1, $out['accepted'] );
		$this->assertLessThan( 10, $out['accepted'], 'it must NOT have processed the whole batch' );
		$this->assertGreaterThan( 0, $out['released'], 'the untouched rows are handed back' );

		foreach ( $this->recipients_of( $res['campaign_id'] ) as $row ) {
			if ( ANPA_Socios_Email_Recipient_State::ACCEPTED === $row['state'] ) {
				continue;
			}
			$this->assertSame( ANPA_Socios_Email_Recipient_State::PENDING, $row['state'], 'unsent rows must not stay leased' );
			$this->assertSame( '', (string) $row['lease_token'] );
			$this->assertSame( 0, (int) $row['attempts'], 'a released row must not consume an attempt' );
		}

		// Whatever is left is immediately claimable by the next run.
		$next = ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );
		$this->assertTrue( $next['ok'] );
		$this->assertSame( 0, ANPA_Socios_Email_Queue_Repo::count_unfinished( $res['campaign_id'] ) );
	}

	public function test_a_run_records_its_timestamp_for_the_stalled_cron_notice(): void {
		$this->transport( true );
		delete_option( ANPA_Socios_Email_Processor::LAST_RUN_OPTION );
		$this->enqueue( 1, 'run-stamp' );

		ANPA_Socios_Email_Processor::run();

		$stamp = (string) get_option( ANPA_Socios_Email_Processor::LAST_RUN_OPTION, '' );
		$this->assertNotEmpty( $stamp );
		// Tight on purpose: a correct UTC stamp is seconds away from wall clock, so
		// even a small timezone mistake fails instead of slipping through.
		$this->assertLessThan( 10, abs( time() - (int) strtotime( $stamp . ' UTC' ) ), 'the stamp is UTC' );
	}
}
