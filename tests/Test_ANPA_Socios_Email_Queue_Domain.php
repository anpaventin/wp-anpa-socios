<?php
/**
 * Pure-domain tests for the fase35 email queue: state machines, backoff policy,
 * recipient normalization/dedup, and batch planner.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Queue_Domain extends TestCase {

	// ── Campaign state machine ──────────────────────────────────────────

	public function test_campaign_transitions(): void {
		$this->assertTrue( ANPA_Socios_Email_Campaign_State::can_transition( 'pending', 'running' ) );
		$this->assertTrue( ANPA_Socios_Email_Campaign_State::can_transition( 'running', 'paused' ) );
		$this->assertTrue( ANPA_Socios_Email_Campaign_State::can_transition( 'paused', 'running' ) );
		$this->assertTrue( ANPA_Socios_Email_Campaign_State::can_transition( 'running', 'finished' ) );
		$this->assertTrue( ANPA_Socios_Email_Campaign_State::can_transition( 'pending', 'cancelled' ) );
		$this->assertFalse( ANPA_Socios_Email_Campaign_State::can_transition( 'finished', 'running' ) );
		$this->assertFalse( ANPA_Socios_Email_Campaign_State::can_transition( 'cancelled', 'running' ) );
		$this->assertFalse( ANPA_Socios_Email_Campaign_State::can_transition( 'x', 'running' ) );
		$this->assertTrue( ANPA_Socios_Email_Campaign_State::terminal( 'finished' ) );
		$this->assertTrue( ANPA_Socios_Email_Campaign_State::terminal( 'cancelled' ) );
		$this->assertFalse( ANPA_Socios_Email_Campaign_State::terminal( 'running' ) );
	}

	// ── Recipient state machine ─────────────────────────────────────────

	public function test_recipient_transitions_and_helpers(): void {
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::can_transition( 'pending', 'processing' ) );
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::can_transition( 'processing', 'accepted' ) );
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::can_transition( 'processing', 'failed' ) );
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::can_transition( 'processing', 'pending' ) ); // orphan recovery
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::can_transition( 'failed', 'pending' ) );
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::can_transition( 'failed', 'failed_permanent' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipient_State::can_transition( 'accepted', 'pending' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipient_State::can_transition( 'cancelled', 'pending' ) );

		$this->assertTrue( ANPA_Socios_Email_Recipient_State::retryable( 'pending' ) );
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::retryable( 'failed' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipient_State::retryable( 'accepted' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipient_State::retryable( 'processing' ) );

		$this->assertTrue( ANPA_Socios_Email_Recipient_State::terminal( 'accepted' ) );
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::terminal( 'failed_permanent' ) );
		$this->assertTrue( ANPA_Socios_Email_Recipient_State::terminal( 'cancelled' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipient_State::terminal( 'failed' ) );
	}

	// ── Backoff policy ──────────────────────────────────────────────────

	public function test_backoff_is_exponential_with_cap(): void {
		$this->assertSame( 0, ANPA_Socios_Email_Backoff::delay_for( 0, 300, 21600 ) );
		$this->assertSame( 300, ANPA_Socios_Email_Backoff::delay_for( 1, 300, 21600 ) );
		$this->assertSame( 600, ANPA_Socios_Email_Backoff::delay_for( 2, 300, 21600 ) );
		$this->assertSame( 1200, ANPA_Socios_Email_Backoff::delay_for( 3, 300, 21600 ) );
		$this->assertSame( 21600, ANPA_Socios_Email_Backoff::delay_for( 20, 300, 21600 ) );
	}

	public function test_backoff_terminal_after_max(): void {
		$this->assertSame( 'failed', ANPA_Socios_Email_Backoff::state_after_failure( 1, 5 ) );
		$this->assertSame( 'failed', ANPA_Socios_Email_Backoff::state_after_failure( 4, 5 ) );
		$this->assertSame( 'failed_permanent', ANPA_Socios_Email_Backoff::state_after_failure( 5, 5 ) );
		$this->assertSame( 'failed_permanent', ANPA_Socios_Email_Backoff::state_after_failure( 9, 5 ) );
	}

	// ── Recipient normalization + deduplication ─────────────────────────

	public function test_normalize_and_validate(): void {
		$this->assertSame( 'a@b.com', ANPA_Socios_Email_Recipients::normalize( '  A@B.CoM ' ) );
		// Dots and +tags are preserved (no provider-specific canonicalization).
		$this->assertSame( 'a.b+x@gmail.com', ANPA_Socios_Email_Recipients::normalize( ' A.B+X@Gmail.com ' ) );
		$this->assertTrue( ANPA_Socios_Email_Recipients::valid( 'a@b.com' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipients::valid( 'nope' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipients::valid( 'a@b' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipients::valid( '' ) );
		// Near-max length (<=254) accepted; over rejected.
		$local = str_repeat( 'a', 240 );
		$this->assertTrue( ANPA_Socios_Email_Recipients::valid( $local . '@b.com' ) );
		$this->assertFalse( ANPA_Socios_Email_Recipients::valid( str_repeat( 'a', 250 ) . '@b.com' ) );
	}

	public function test_dedup_by_logical_identity_not_email_alone(): void {
		$raw = array(
			// principal + secondary of the SAME message (same type+message_key) → collapse.
			array( 'email' => 'Pai@Casa.com', 'recipient_type' => 'member', 'message_key' => 'enrolment:10', 'entity_id' => 10 ),
			array( 'email' => 'pai@casa.com', 'recipient_type' => 'member', 'message_key' => 'enrolment:10', 'entity_id' => 11 ),
			// same email, DIFFERENT message (another child) → kept.
			array( 'email' => 'pai@casa.com', 'recipient_type' => 'member', 'message_key' => 'enrolment:20', 'entity_id' => 20 ),
			// same email as a COMPANY responsible → kept.
			array( 'email' => 'pai@casa.com', 'recipient_type' => 'company', 'message_key' => 'company:7', 'entity_id' => 7 ),
			array( 'email' => 'bad', 'recipient_type' => 'member' ),
			array( 'email' => '', 'recipient_type' => 'member' ),
		);
		$out = ANPA_Socios_Email_Recipients::prepare( $raw );

		$this->assertCount( 3, $out['valid'], 'principal==secondary same message collapses; distinct messages kept' );
		// First occurrence wins for the collapsed pair.
		$this->assertSame( 10, $out['valid'][0]['entity_id'] );

		$reasons = array_column( $out['skipped'], 'reason' );
		$this->assertContains( 'duplicate', $reasons ); // the secondary parent
		$this->assertContains( 'invalid', $reasons );   // bad + empty
	}

	public function test_idempotency_key_is_deterministic_and_discriminates(): void {
		$k1 = ANPA_Socios_Email_Recipients::idempotency_key( 'uuid-1', 'A@b.com', 'member', 'enrolment:10' );
		$k1b = ANPA_Socios_Email_Recipients::idempotency_key( 'uuid-1', 'a@b.com ', 'member', 'enrolment:10' );
		$this->assertSame( $k1, $k1b, 'normalization makes the key stable' );
		$this->assertSame( 64, strlen( $k1 ) );

		// Different message_key, recipient_type, campaign or email → different key.
		$this->assertNotSame( $k1, ANPA_Socios_Email_Recipients::idempotency_key( 'uuid-1', 'a@b.com', 'member', 'enrolment:20' ) );
		$this->assertNotSame( $k1, ANPA_Socios_Email_Recipients::idempotency_key( 'uuid-1', 'a@b.com', 'company', 'enrolment:10' ) );
		$this->assertNotSame( $k1, ANPA_Socios_Email_Recipients::idempotency_key( 'uuid-2', 'a@b.com', 'member', 'enrolment:10' ) );
		$this->assertNotSame( $k1, ANPA_Socios_Email_Recipients::idempotency_key( 'uuid-1', 'c@b.com', 'member', 'enrolment:10' ) );
	}

	public function test_canonical_identity_uses_structured_serialization(): void {
		// Components are serialized as canonical JSON (never blindly concatenated),
		// so ambiguous boundaries cannot collide.
		$a = ANPA_Socios_Email_Recipients::idempotency_key( 'u', 'x@y.com', 'a', 'bc' );
		$b = ANPA_Socios_Email_Recipients::idempotency_key( 'u', 'x@y.com', 'ab', 'c' );
		$this->assertNotSame( $a, $b, 'boundary between recipient_type and message_key must be unambiguous' );
	}

	// ── Batch planner ───────────────────────────────────────────────────

	public function test_planner_selects_only_eligible_capped_oldest_first(): void {
		$now = '2026-07-24 12:00:00';
		$records = array(
			array( 'id' => 5, 'state' => 'pending', 'next_attempt_at' => '' ),
			array( 'id' => 2, 'state' => 'failed', 'next_attempt_at' => '2026-07-24 11:00:00' ), // due
			array( 'id' => 3, 'state' => 'failed', 'next_attempt_at' => '2026-07-24 13:00:00' ), // not due
			array( 'id' => 4, 'state' => 'accepted', 'next_attempt_at' => '' ), // terminal
			array( 'id' => 1, 'state' => 'cancelled', 'next_attempt_at' => '' ), // terminal
			array( 'id' => 6, 'state' => 'processing', 'next_attempt_at' => '' ), // not retryable
		);

		$sel = ANPA_Socios_Email_Batch_Planner::select( $records, $now, 25 );
		$ids = array_column( $sel, 'id' );
		$this->assertSame( array( 2, 5 ), $ids, 'only due pending/failed, oldest id first' );
	}

	public function test_planner_batch_size_clamped(): void {
		$this->assertSame( 25, ANPA_Socios_Email_Batch_Planner::batch_size( 0 ) );
		$this->assertSame( 25, ANPA_Socios_Email_Batch_Planner::batch_size( -3 ) );
		$this->assertSame( 10, ANPA_Socios_Email_Batch_Planner::batch_size( 10 ) );
		$this->assertSame( 100, ANPA_Socios_Email_Batch_Planner::batch_size( 500 ) );

		$records = array();
		for ( $i = 1; $i <= 40; $i++ ) {
			$records[] = array( 'id' => $i, 'state' => 'pending', 'next_attempt_at' => '' );
		}
		$sel = ANPA_Socios_Email_Batch_Planner::select( $records, '2026-07-24 12:00:00', 25 );
		$this->assertCount( 25, $sel );
		$this->assertSame( 1, $sel[0]['id'] );
		$this->assertSame( 25, $sel[24]['id'] );
	}
}
