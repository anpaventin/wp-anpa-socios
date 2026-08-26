<?php
/**
 * REAL integration tests for the fase35 retention pass (PR-35s6).
 *
 * Proves what inspection cannot: that the payload is actually cleared while the
 * hash and the state survive, that the minimal rows are deleted only much later,
 * that a live campaign is never touched, and that a second run is a no-op.
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Purge_Integration extends TestCase {

	/** @var callable|null */
	private $transport = null;

	protected function setUp(): void {
		if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
			$this->markTestSkipped( 'Integration DB not available (define ANPA_SOCIOS_IT_DB).' );
		}
		global $wpdb;
		ANPA_Socios_DB::crear_tabelas();
		update_option( ANPA_Socios_DB::VERSION_OPTION, ANPA_Socios_DB::DB_VERSION );
		delete_option( ANPA_Socios_Email_Processor::LOCK_OPTION );
		delete_option( ANPA_Socios_Email_Purge::OPTION_PAYLOAD_DAYS );
		delete_option( ANPA_Socios_Email_Purge::OPTION_METADATA_DAYS );
		// Defensive: filters are global state and a leaked render provider from
		// another test would silently change the payload this suite asserts on.
		remove_all_filters( 'anpa_socios_email_render_provider' );

		foreach ( array(
			ANPA_Socios_DB::tabela_email_attempts(),
			ANPA_Socios_DB::tabela_email_recipients(),
			ANPA_Socios_DB::tabela_email_campaigns(),
		) as $t ) {
			$wpdb->query( "DELETE FROM `{$t}`" );
		}

		$this->transport = static function () {
			return true;
		};
		add_filter( 'pre_wp_mail', $this->transport, 10, 2 );
	}

	protected function tearDown(): void {
		if ( null !== $this->transport ) {
			remove_filter( 'pre_wp_mail', $this->transport, 10 );
			$this->transport = null;
		}
		delete_option( ANPA_Socios_Email_Purge::OPTION_PAYLOAD_DAYS );
		delete_option( ANPA_Socios_Email_Purge::OPTION_METADATA_DAYS );
	}

	/**
	 * Enqueues a campaign and processes it to completion.
	 *
	 * @param  int    $n   Recipients.
	 * @param  string $key Idempotency key.
	 * @return int Campaign id.
	 */
	private function finished_campaign( int $n = 2, string $key = 'purge-1' ): int {
		$rs = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$rs[] = array( 'email' => "u{$i}@example.com", 'recipient_type' => 'member', 'message_key' => "m{$i}" );
		}
		$res = ANPA_Socios_Email_Queue::enqueue_campaign(
			array(
				'event_type'      => 'test_event',
				'idempotency_key' => $key,
				'recipients'      => $rs,
				'context'         => array( 'subject' => 'Asunto', 'body_html' => '<p>Ola</p>' ),
				'created_by'      => 'sistema',
			)
		);
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		return (int) $res['campaign_id'];
	}

	/**
	 * Backdates a campaign's terminal date so it falls outside a window.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $days        Days in the past.
	 */
	private function backdate( int $campaign_id, int $days ): void {
		global $wpdb;
		$t = ANPA_Socios_DB::tabela_email_campaigns();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$t}` SET finished_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) WHERE id = %d",
				$days,
				$campaign_id
			)
		);
	}

	// ── Step 1: payload ─────────────────────────────────────────────────

	public function test_the_payload_is_cleared_but_the_proof_of_sending_survives(): void {
		$campaign_id = $this->finished_campaign( 2, 'purge-payload' );
		$before      = ANPA_Socios_Email_Queue_Repo::list_recipients( $campaign_id );
		$this->assertNotEmpty( $before[0]['payload_hash'] );

		$this->backdate( $campaign_id, 40 ); // outside the 30-day window
		$out = ANPA_Socios_Email_Purge::run();

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 2, $out['payload_cleared'] );

		global $wpdb;
		$t    = ANPA_Socios_DB::tabela_email_recipients();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE campaign_id = %d", $campaign_id ), ARRAY_A );
		$this->assertCount( 2, $rows, 'the rows themselves are NOT deleted in this step' );

		foreach ( $rows as $i => $row ) {
			$this->assertNull( $row['payload_snapshot'], 'the body is gone' );
			$this->assertSame( '', $row['subject_render'], 'the subject is gone' );
			$this->assertSame( '', $row['last_error'] );
			// What proves the communication happened stays.
			$this->assertSame( ANPA_Socios_Email_Recipient_State::ACCEPTED, $row['state'] );
			$this->assertSame( $before[ $i ]['payload_hash'], $row['payload_hash'], 'the hash survives' );
			$this->assertNotEmpty( $row['accepted_at_utc'] );
			$this->assertNotEmpty( $row['email'], 'the address is metadata, purged in step 2' );
		}
	}

	public function test_a_recent_campaign_keeps_its_payload(): void {
		$campaign_id = $this->finished_campaign( 1, 'purge-recent' );
		$this->backdate( $campaign_id, 5 ); // inside the window

		$out = ANPA_Socios_Email_Purge::run();

		$this->assertSame( 0, $out['payload_cleared'] );
		$row = ANPA_Socios_Email_Queue_Repo::list_recipients( $campaign_id )[0];
		$this->assertSame( 'Asunto', $row['subject_render'] );
	}

	public function test_a_live_campaign_is_never_touched(): void {
		global $wpdb;
		$res = ANPA_Socios_Email_Queue::enqueue_campaign(
			array(
				'event_type'      => 'test_event',
				'idempotency_key' => 'purge-live',
				'recipients'      => array( array( 'email' => 'live@example.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ),
				'context'         => array( 'subject' => 'Asunto', 'body_html' => '<p>Ola</p>' ),
			)
		);
		// Backdate its creation AND force a finished date it must not have.
		$t = ANPA_Socios_DB::tabela_email_campaigns();
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET created_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 900 DAY) WHERE id = %d", (int) $res['campaign_id'] ) );

		$out = ANPA_Socios_Email_Purge::run();

		$this->assertSame( 0, $out['payload_cleared'], 'a pending campaign still needs its payload' );
		$this->assertSame( 0, $out['campaigns_deleted'] );
		$row = ANPA_Socios_Email_Queue_Repo::list_recipients( (int) $res['campaign_id'] )[0];
		$this->assertSame( 'Asunto', $row['subject_render'] );
	}

	public function test_the_window_is_configurable_within_its_bounds(): void {
		$campaign_id = $this->finished_campaign( 1, 'purge-window' );
		$this->backdate( $campaign_id, 5 );

		update_option( ANPA_Socios_Email_Purge::OPTION_PAYLOAD_DAYS, 2 );
		$this->assertSame( 2, ANPA_Socios_Email_Purge::payload_days() );

		$out = ANPA_Socios_Email_Purge::run();
		$this->assertSame( 1, $out['payload_cleared'], 'a shorter window makes it eligible' );

		// A value below the floor is clamped instead of purging live data.
		update_option( ANPA_Socios_Email_Purge::OPTION_PAYLOAD_DAYS, 0 );
		$this->assertSame( ANPA_Socios_Email_Retention::PAYLOAD_DAYS_MIN, ANPA_Socios_Email_Purge::payload_days() );
	}

	// ── Step 2: metadata ────────────────────────────────────────────────

	public function test_metadata_is_deleted_only_after_the_long_window(): void {
		global $wpdb;
		$campaign_id = $this->finished_campaign( 2, 'purge-meta' );

		// Old enough for the payload window, not for the metadata one.
		$this->backdate( $campaign_id, 100 );
		$out = ANPA_Socios_Email_Purge::run();
		$this->assertSame( 0, $out['campaigns_deleted'] );
		$this->assertNotNull( ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id ) );

		// Now past the metadata window too.
		$this->backdate( $campaign_id, 400 );
		$out = ANPA_Socios_Email_Purge::run();

		$this->assertSame( 1, $out['campaigns_deleted'] );
		$this->assertSame( 2, $out['recipients_deleted'] );
		$this->assertGreaterThanOrEqual( 2, $out['attempts_deleted'] );
		$this->assertNull( ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id ) );

		// No orphans left behind.
		foreach ( array( ANPA_Socios_DB::tabela_email_recipients(), ANPA_Socios_DB::tabela_email_attempts() ) as $t ) {
			$this->assertSame(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE campaign_id = %d", $campaign_id ) )
			);
		}
	}

	public function test_a_campaign_with_unfinished_recipients_is_not_deleted(): void {
		global $wpdb;
		$campaign_id = $this->finished_campaign( 2, 'purge-inconsistent' );
		$this->backdate( $campaign_id, 400 );

		// Force an inconsistency: the campaign says finished, a recipient is pending.
		$t = ANPA_Socios_DB::tabela_email_recipients();
		$id = (int) ANPA_Socios_Email_Queue_Repo::list_recipients( $campaign_id )[0]['id'];
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET state = %s WHERE id = %d", ANPA_Socios_Email_Recipient_State::PENDING, $id ) );

		$out = ANPA_Socios_Email_Purge::run();

		$this->assertSame( 0, $out['campaigns_deleted'], 'recipients are the source of truth' );
		$this->assertNotNull( ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id ) );
	}

	public function test_a_cancelled_campaign_is_purged_by_its_cancellation_date(): void {
		global $wpdb;
		$res = ANPA_Socios_Email_Queue::enqueue_campaign(
			array(
				'event_type'      => 'test_event',
				'idempotency_key' => 'purge-cancelled',
				'recipients'      => array( array( 'email' => 'c@example.com', 'recipient_type' => 'member', 'message_key' => 'm1' ) ),
				'context'         => array( 'subject' => 'Asunto', 'body_html' => '<p>Ola</p>' ),
			)
		);
		ANPA_Socios_Email_Queue::cancel( (int) $res['campaign_id'] );

		$t = ANPA_Socios_DB::tabela_email_campaigns();
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET cancelled_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 400 DAY) WHERE id = %d", (int) $res['campaign_id'] ) );

		$out = ANPA_Socios_Email_Purge::run();

		$this->assertSame( 1, $out['campaigns_deleted'] );
		$this->assertNull( ANPA_Socios_Email_Queue_Repo::get_campaign( (int) $res['campaign_id'] ) );
	}

	// ── Idempotence and guards ──────────────────────────────────────────

	public function test_a_second_run_changes_nothing_further(): void {
		$campaign_id = $this->finished_campaign( 2, 'purge-idem' );
		$this->backdate( $campaign_id, 40 );

		$first  = ANPA_Socios_Email_Purge::run();
		$second = ANPA_Socios_Email_Purge::run();

		$this->assertSame( 2, $first['payload_cleared'] );
		$this->assertSame( 0, $second['payload_cleared'], 'nothing left to clear' );
		$this->assertSame( 0, $second['campaigns_deleted'] );
	}

	public function test_nothing_is_purged_while_a_migration_is_pending(): void {
		$campaign_id = $this->finished_campaign( 1, 'purge-blocked' );
		$this->backdate( $campaign_id, 900 );

		update_option( ANPA_Socios_DB::VERSION_OPTION, '1.0.0' );
		$out = ANPA_Socios_Email_Purge::run();
		update_option( ANPA_Socios_DB::VERSION_OPTION, ANPA_Socios_DB::DB_VERSION );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'blocked', $out['code'] );
		$this->assertNotNull( ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id ) );
	}

	public function test_the_run_records_its_timestamp_in_utc(): void {
		delete_option( ANPA_Socios_Email_Purge::LAST_RUN_OPTION );
		ANPA_Socios_Email_Purge::run();

		$stamp = (string) get_option( ANPA_Socios_Email_Purge::LAST_RUN_OPTION, '' );
		$this->assertNotEmpty( $stamp );
		$this->assertLessThan( 10, abs( time() - (int) strtotime( $stamp . ' UTC' ) ) );
	}

	// ── Deactivation keeps the data ─────────────────────────────────────

	public function test_deactivation_removes_both_events_without_deleting_data(): void {
		$campaign_id = $this->finished_campaign( 1, 'purge-deactivate' );

		ANPA_Socios_Email_Cron::schedule();
		$this->assertNotFalse( wp_next_scheduled( ANPA_Socios_Email_Cron::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( ANPA_Socios_Email_Cron::PURGE_HOOK ) );

		ANPA_Socios_Email_Cron::unschedule();

		$this->assertFalse( wp_next_scheduled( ANPA_Socios_Email_Cron::HOOK ) );
		$this->assertFalse( wp_next_scheduled( ANPA_Socios_Email_Cron::PURGE_HOOK ) );
		$this->assertNotNull( ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id ), 'deactivation must not delete data' );

		ANPA_Socios_Email_Cron::schedule();
	}
}
