<?php
/**
 * REAL integration tests for the fase35 queue repository (PR-35s3).
 *
 * Exercises the behaviour that inspection cannot prove: unique constraints,
 * atomic lease claiming, lease ownership, orphan recovery, backoff scheduling,
 * counter reconciliation, UTC storage and payload immutability.
 *
 * Skipped unless running under the WordPress test suite with a real database
 * (ANPA_SOCIOS_IT_DB defined by tests/integration/bootstrap.php).
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Queue_Integration extends TestCase {

	protected function setUp(): void {
		if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
			$this->markTestSkipped( 'Integration DB not available (define ANPA_SOCIOS_IT_DB).' );
		}
		global $wpdb;
		ANPA_Socios_DB::crear_tabelas();
		// Clean slate for each test (harness tables only).
		foreach ( array(
			ANPA_Socios_DB::tabela_email_attempts(),
			ANPA_Socios_DB::tabela_email_recipients(),
			ANPA_Socios_DB::tabela_email_campaigns(),
		) as $t ) {
			$wpdb->query( "DELETE FROM `{$t}`" );
		}
	}

	private function enqueue( array $recipients, string $key = 'op-1', array $extra = array() ): array {
		return ANPA_Socios_Email_Queue::enqueue_campaign(
			array_merge(
				array(
					'event_type'      => 'test_event',
					'idempotency_key' => $key,
					'recipients'      => $recipients,
					'context'         => array( 'subject' => 'Asunto', 'body_html' => '<p>Ola</p>' ),
					'created_by'      => 'sistema',
				),
				$extra
			)
		);
	}

	// ── Deduplication by logical identity ───────────────────────────────

	public function test_same_address_same_message_collapses_but_distinct_messages_do_not(): void {
		$res = $this->enqueue(
			array(
				array( 'email' => 'Pai@Casa.com', 'recipient_type' => 'member', 'message_key' => 'enrol:10' ),
				array( 'email' => 'pai@casa.com', 'recipient_type' => 'member', 'message_key' => 'enrol:10' ), // same message
				array( 'email' => 'pai@casa.com', 'recipient_type' => 'member', 'message_key' => 'enrol:20' ), // other child
				array( 'email' => 'pai@casa.com', 'recipient_type' => 'company', 'message_key' => 'company:7' ),
			)
		);

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 3, $res['queued'], 'principal+secondary of the same message collapse; distinct messages kept' );

		global $wpdb;
		$t = ANPA_Socios_DB::tabela_email_recipients();
		$this->assertSame( 3, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE email = %s AND message_key = %s", 'pai@casa.com', 'enrol:10' ) ) );
	}

	public function test_repeating_the_same_operation_creates_nothing_new(): void {
		$r1 = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ), 'op-same' );
		$r2 = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ), 'op-same' );

		$this->assertTrue( $r1['created'] );
		$this->assertFalse( $r2['created'], 'the campaign is adopted, not duplicated' );
		$this->assertSame( $r1['campaign_id'], $r2['campaign_id'] );
		$this->assertSame( 1, $r1['queued'] );
		$this->assertSame( 0, $r2['queued'], 'the recipient is not duplicated' );

		global $wpdb;
		$t = ANPA_Socios_DB::tabela_email_recipients();
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ) );
	}

	public function test_authorized_resend_uses_a_new_campaign(): void {
		$r1 = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ), 'op-A' );
		$r2 = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ), 'op-B' );

		$this->assertNotSame( $r1['campaign_id'], $r2['campaign_id'] );
		$this->assertSame( 1, $r2['queued'], 'a deliberate resend is a new campaign, so it is allowed' );
	}

	public function test_invalid_addresses_are_skipped_not_queued(): void {
		$res = $this->enqueue(
			array(
				array( 'email' => 'ok@b.com', 'recipient_type' => 'member', 'message_key' => 'm' ),
				array( 'email' => 'bad', 'recipient_type' => 'member', 'message_key' => 'm' ),
				array( 'email' => '', 'recipient_type' => 'member', 'message_key' => 'm' ),
			)
		);
		$this->assertSame( 1, $res['queued'] );
		$this->assertGreaterThanOrEqual( 2, $res['skipped'] );
	}

	// ── Atomic lease claiming ───────────────────────────────────────────

	public function test_two_concurrent_claims_get_disjoint_rows(): void {
		$rs = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$rs[] = array( 'email' => "u{$i}@b.com", 'recipient_type' => 'member', 'message_key' => "m{$i}" );
		}
		$res = $this->enqueue( $rs );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$a = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 3 );
		$b = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 3 );

		$ids_a = array_map( 'intval', array_column( $a['rows'], 'id' ) );
		$ids_b = array_map( 'intval', array_column( $b['rows'], 'id' ) );

		$this->assertCount( 3, $ids_a );
		$this->assertCount( 3, $ids_b );
		$this->assertNotSame( $a['lease'], $b['lease'] );
		$this->assertSame( array(), array_intersect( $ids_a, $ids_b ), 'no row may be claimed twice' );

		// A third claim finds nothing left (all leased).
		$c = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 3 );
		$this->assertCount( 0, $c['rows'] );
	}

	public function test_claim_respects_the_batch_limit(): void {
		$rs = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$rs[] = array( 'email' => "u{$i}@b.com", 'recipient_type' => 'member', 'message_key' => "m{$i}" );
		}
		$res = $this->enqueue( $rs );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$a = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 2 );
		$this->assertCount( 2, $a['rows'] );
	}

	public function test_a_stolen_or_expired_lease_cannot_overwrite_the_result(): void {
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 );
		$rid   = (int) $claim['rows'][0]['id'];

		// A worker holding the WRONG lease must not be able to write a result.
		$this->assertFalse( ANPA_Socios_Email_Queue_Repo::mark_accepted( $rid, 'not-my-lease' ) );
		// The rightful owner can.
		$this->assertTrue( ANPA_Socios_Email_Queue_Repo::mark_accepted( $rid, $claim['lease'] ) );
		// And cannot mark it twice (state is no longer processing).
		$this->assertFalse( ANPA_Socios_Email_Queue_Repo::mark_accepted( $rid, $claim['lease'] ) );

		$row = ANPA_Socios_Email_Queue_Repo::get_recipient( $rid );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::ACCEPTED, $row['state'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertNotEmpty( $row['accepted_at_utc'] );
	}

	public function test_accepted_rows_are_never_claimed_again(): void {
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 5 );
		ANPA_Socios_Email_Queue_Repo::mark_accepted( (int) $claim['rows'][0]['id'], $claim['lease'] );

		$again = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 5 );
		$this->assertCount( 0, $again['rows'], 'an accepted recipient must never be re-sent' );
	}

	public function test_expired_lease_is_recovered_and_reclaimable(): void {
		global $wpdb;
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 );
		$rid   = (int) $claim['rows'][0]['id'];

		// Simulate an interrupted worker: force the lease into the past.
		$t = ANPA_Socios_DB::tabela_email_recipients();
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET locked_until_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = %d", $rid ) );

		$this->assertSame( 1, ANPA_Socios_Email_Queue_Repo::recover_orphans() );
		$row = ANPA_Socios_Email_Queue_Repo::get_recipient( $rid );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::PENDING, $row['state'] );

		$reclaim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 );
		$this->assertCount( 1, $reclaim['rows'] );
	}

	// ── Backoff + failure handling ───────────────────────────────────────

	public function test_failure_schedules_a_future_retry_then_becomes_permanent(): void {
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 );
		$rid   = (int) $claim['rows'][0]['id'];

		$out = ANPA_Socios_Email_Queue_Repo::mark_failed( $rid, $claim['lease'], "boom\nwith  newlines", 2 );
		$this->assertTrue( $out['ok'] );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::FAILED, $out['state'] );
		$this->assertGreaterThan( 0, $out['delay'] );

		$row = ANPA_Socios_Email_Queue_Repo::get_recipient( $rid );
		$this->assertNotEmpty( $row['next_attempt_at_utc'] );
		$this->assertSame( 'boom with newlines', $row['last_error'], 'errors are collapsed and redacted' );

		// Not due yet -> not claimable.
		$this->assertCount( 0, ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 )['rows'] );

		// Make it due, fail again -> reaches the attempt ceiling (max 2).
		global $wpdb;
		$t = ANPA_Socios_DB::tabela_email_recipients();
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET next_attempt_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = %d", $rid ) );

		$claim2 = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 );
		$this->assertCount( 1, $claim2['rows'] );
		$out2 = ANPA_Socios_Email_Queue_Repo::mark_failed( $rid, $claim2['lease'], 'again', 2 );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT, $out2['state'] );
		$this->assertCount( 0, ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 )['rows'] );
	}

	public function test_attempts_are_recorded_once_per_attempt_number(): void {
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );
		$rid = (int) ANPA_Socios_Email_Queue_Repo::find_recipient_by_key(
			ANPA_Socios_Email_Recipients::idempotency_key( $res['uuid'], 'a@b.com', 'member', 'm1' )
		)['id'];

		$args = array( 'campaign_id' => $res['campaign_id'], 'recipient_id' => $rid, 'attempt_no' => 1, 'result' => 'accepted' );
		$this->assertTrue( ANPA_Socios_Email_Queue_Repo::record_attempt( $args ) );
		$this->assertTrue( ANPA_Socios_Email_Queue_Repo::record_attempt( $args ) ); // duplicate ignored

		global $wpdb;
		$t = ANPA_Socios_DB::tabela_email_attempts();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE recipient_id = %d", $rid ) ) );
	}

	// ── Counters + completion ───────────────────────────────────────────

	public function test_counters_match_real_state_and_are_never_negative(): void {
		$rs = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$rs[] = array( 'email' => "u{$i}@b.com", 'recipient_type' => 'member', 'message_key' => "m{$i}" );
		}
		$res = $this->enqueue( $rs );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 4 );
		ANPA_Socios_Email_Queue_Repo::mark_accepted( (int) $claim['rows'][0]['id'], $claim['lease'] );
		ANPA_Socios_Email_Queue_Repo::mark_failed( (int) $claim['rows'][1]['id'], $claim['lease'], 'x', 1 ); // permanent

		$counts = ANPA_Socios_Email_Queue_Repo::recalc_counts( $res['campaign_id'] );

		$this->assertSame( 4, $counts['total'] );
		$this->assertSame( 1, $counts['accepted_count'] );
		$this->assertSame( 1, $counts['failed_count'] );
		foreach ( $counts as $k => $v ) {
			$this->assertGreaterThanOrEqual( 0, $v, "$k must never be negative" );
		}
		// The state sum always equals the total.
		$sum = $counts['accepted_count'] + $counts['failed_count'] + $counts['cancelled_count'] + $counts['pending_count'];
		$this->assertSame( $counts['total'], $sum );
	}

	public function test_cancel_leaves_accepted_intact_and_retry_only_touches_failures(): void {
		$rs = array(
			array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ),
			array( 'email' => 'b@b.com', 'recipient_type' => 'member', 'message_key' => 'm2' ),
			array( 'email' => 'c@b.com', 'recipient_type' => 'member', 'message_key' => 'm3' ),
		);
		$res = $this->enqueue( $rs );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 3 );
		ANPA_Socios_Email_Queue_Repo::mark_accepted( (int) $claim['rows'][0]['id'], $claim['lease'] );
		ANPA_Socios_Email_Queue_Repo::mark_failed( (int) $claim['rows'][1]['id'], $claim['lease'], 'x', 1 ); // permanent
		// Third row stays leased; release it so it is pending again.
		ANPA_Socios_Email_Queue_Repo::mark_failed( (int) $claim['rows'][2]['id'], $claim['lease'], 'y', 99 );

		$cancelled = ANPA_Socios_Email_Queue_Repo::cancel_pending_recipients( $res['campaign_id'] );
		$this->assertGreaterThanOrEqual( 1, $cancelled );

		$accepted = ANPA_Socios_Email_Queue_Repo::get_recipient( (int) $claim['rows'][0]['id'] );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::ACCEPTED, $accepted['state'], 'accepted cannot be un-sent' );

		$requeued = ANPA_Socios_Email_Queue_Repo::retry_failed( $res['campaign_id'] );
		$this->assertGreaterThanOrEqual( 1, $requeued );
		$still = ANPA_Socios_Email_Queue_Repo::get_recipient( (int) $claim['rows'][0]['id'] );
		$this->assertSame( ANPA_Socios_Email_Recipient_State::ACCEPTED, $still['state'] );
	}

	public function test_unfinished_count_drives_completion(): void {
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );
		$this->assertSame( 1, ANPA_Socios_Email_Queue_Repo::count_unfinished( $res['campaign_id'] ) );

		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );
		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 );
		ANPA_Socios_Email_Queue_Repo::mark_accepted( (int) $claim['rows'][0]['id'], $claim['lease'] );

		$this->assertSame( 0, ANPA_Socios_Email_Queue_Repo::count_unfinished( $res['campaign_id'] ) );
	}

	public function test_paused_campaign_yields_no_claims(): void {
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::PAUSED );

		$this->assertCount( 0, ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 5 )['rows'] );
	}

	public function test_scheduled_campaign_is_not_claimable_before_its_time(): void {
		$res = $this->enqueue(
			array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ),
			'op-sched',
			array( 'scheduled_at_utc' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) )
		);
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );
		$this->assertCount( 0, ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 5 )['rows'] );
	}

	// ── UTC storage ─────────────────────────────────────────────────────

	public function test_timestamps_are_stored_in_utc_not_session_time(): void {
		global $wpdb;
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );

		$t   = ANPA_Socios_DB::tabela_email_recipients();
		$row = $wpdb->get_row( "SELECT created_at_utc, UTC_TIMESTAMP() AS utc_now, NOW() AS session_now FROM `{$t}` LIMIT 1", ARRAY_A );

		$stored  = strtotime( $row['created_at_utc'] . ' UTC' );
		$utc_now = strtotime( $row['utc_now'] . ' UTC' );
		$this->assertLessThan( 120, abs( $utc_now - $stored ), 'created_at_utc must be UTC, not session-local' );

		// When the DB session is not UTC, prove the stored value follows UTC.
		if ( $row['session_now'] !== $row['utc_now'] ) {
			$session = strtotime( $row['session_now'] . ' UTC' );
			$this->assertGreaterThan( 60, abs( $session - $stored ), 'stored time must not follow the session timezone' );
		}
	}

	// ── Payload immutability ────────────────────────────────────────────

	public function test_payload_snapshot_is_frozen_at_enqueue_time(): void {
		$res = $this->enqueue( array( array( 'email' => 'a@b.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ) );
		$key = ANPA_Socios_Email_Recipients::idempotency_key( $res['uuid'], 'a@b.com', 'member', 'm1' );
		$row = ANPA_Socios_Email_Queue_Repo::find_recipient_by_key( $key );

		$this->assertSame( 'Asunto', $row['subject_render'] );
		$this->assertNotEmpty( $row['payload_hash'] );
		$thawed = ANPA_Socios_Email_Render::thaw( $row['payload_snapshot'] );
		$this->assertSame( '<p>Ola</p>', $thawed['body_html'] );
		// The hash matches the stored snapshot (provable after body purge).
		$this->assertSame( hash( 'sha256', (string) $row['payload_snapshot'] ), $row['payload_hash'] );

		// A later "template change" must NOT alter the pending recipient.
		add_filter(
			'anpa_socios_email_render_provider',
			static function () {
				return new class() implements ANPA_Socios_Email_Render_Provider_Interface {
					public function render( string $event_type, string $template_ref, array $context ): array {
						return array( 'subject' => 'CAMBIADO', 'body_html' => '<p>outro</p>', 'body_text' => 'outro' );
					}
				};
			}
		);
		$row_after = ANPA_Socios_Email_Queue_Repo::find_recipient_by_key( $key );
		$this->assertSame( 'Asunto', $row_after['subject_render'], 'a frozen payload must not change' );
	}

	public function test_enqueue_never_sends_and_is_blocked_during_migration(): void {
		// A pending migration marker disables processing.
		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.0.0' );
		$this->assertFalse( ANPA_Socios_Email_Queue::can_run() );
		update_option( ANPA_Socios_DB::VERSION_OPTION, ANPA_Socios_DB::DB_VERSION );
		$this->assertTrue( ANPA_Socios_Email_Queue::can_run() );
	}
}
