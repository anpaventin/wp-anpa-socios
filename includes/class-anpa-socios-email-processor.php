<?php
/**
 * Batch processor for the email queue (fase35, PR-35s4).
 *
 * One run: recover interrupted rows → claim a bounded batch → for each claimed
 * recipient thaw the frozen payload, hand it to wp_mail() and store the outcome
 * immediately → record the attempt → reconcile counters → close the campaign
 * when nothing is left.
 *
 * Guarantees and explicit non-guarantees:
 *   - NEVER sends during an install or a pending migration (ANPA_Socios_Email_Queue::can_run).
 *   - NEVER re-sends an accepted recipient: only claimable states are claimed and
 *     every result write requires the lease this run owns.
 *   - wp_mail() === true means ACCEPTED BY THE LOCAL MAIL SYSTEM, never delivered.
 *   - The model is AT-LEAST-ONCE. A crash after wp_mail() returned but before the
 *     result was stored is indistinguishable from a crash before sending, so the
 *     row is released with an `uncertain` attempt recorded and retried. Duplicates
 *     are minimised (short lease, immediate result write) but never impossible.
 *   - Bounded by both the batch size (planner clamp) and a wall-clock budget, so a
 *     run cannot overlap the next scheduled tick.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Processor {

	/** Default wall-clock budget for one run, in seconds. */
	const DEFAULT_MAX_SECONDS = 20;

	/** Hard ceiling for the wall-clock budget. */
	const MAX_SECONDS_CEILING = 120;

	/** Option used as a coarse overlap lock between concurrent runs. */
	const LOCK_OPTION = 'anpa_socios_email_run_lock';

	/** Option storing the last successful run (UTC), for the stalled-cron notice. */
	const LAST_RUN_OPTION = 'anpa_socios_email_last_run_utc';

	/** A stale lock is ignored after this many seconds (crashed run). */
	const LOCK_TTL = 600;

	/** Last wp_mail() error captured through the wp_mail_failed hook. */
	private static string $last_mail_error = '';

	/**
	 * Wall-clock budget for one run. Filterable within safe bounds.
	 *
	 * @since  1.39.0
	 * @return int
	 */
	public static function max_seconds(): int {
		$seconds = (int) apply_filters( 'anpa_socios_email_run_max_seconds', self::DEFAULT_MAX_SECONDS );
		return max( 1, min( self::MAX_SECONDS_CEILING, $seconds ) );
	}

	/**
	 * Processes one bounded batch.
	 *
	 * @since  1.39.0
	 * @param  array<string,mixed> $opts {
	 *     @type int $campaign_id Restrict to one campaign, or 0 for any runnable one.
	 *     @type int $max_seconds Wall-clock budget override.
	 *     @type int $limit       Batch size override (clamped by the planner).
	 * }
	 * @return array{ok:bool,code:string,claimed:int,accepted:int,failed:int,recovered:int,released:int,finished:int}
	 */
	public static function run( array $opts = array() ): array {
		$out = array(
			'ok'        => false,
			'code'      => '',
			'claimed'   => 0,
			'accepted'  => 0,
			'failed'    => 0,
			'recovered' => 0,
			'released'  => 0,
			'finished'  => 0,
		);

		if ( ! ANPA_Socios_Email_Queue::can_run() ) {
			$out['code'] = 'blocked';
			return $out;
		}

		if ( ! self::acquire_lock() ) {
			// Another run owns the lock. Row-level leases already make overlapping
			// runs safe; this only avoids redundant work.
			$out['code'] = 'locked';
			return $out;
		}

		try {
			$campaign_id = (int) ( $opts['campaign_id'] ?? 0 );
			$deadline    = microtime( true ) + (float) ( isset( $opts['max_seconds'] ) ? max( 1, (int) $opts['max_seconds'] ) : self::max_seconds() );

			$out['recovered'] = self::recover_interrupted();

			$campaign = $campaign_id > 0 ? ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id ) : null;
			if ( $campaign_id > 0 && null === $campaign ) {
				$out['code'] = 'campaign_not_found';
				return $out;
			}
			// A pending campaign starts running before its first claim; paused and
			// terminal campaigns are simply not claimable (enforced in SQL).
			if ( null !== $campaign && ANPA_Socios_Email_Campaign_State::PENDING === (string) $campaign['state'] ) {
				ANPA_Socios_Email_Queue_Repo::set_campaign_state( $campaign_id, ANPA_Socios_Email_Campaign_State::RUNNING );
			}

			$limit = isset( $opts['limit'] )
				? (int) $opts['limit']
				: ( null !== $campaign ? (int) $campaign['batch_size'] : ANPA_Socios_Email_Batch_Planner::BATCH_DEFAULT );

			$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $campaign_id, $limit );
			if ( empty( $claim['ok'] ) ) {
				$out['code'] = 'claim_failed';
				return $out;
			}

			$lease    = (string) $claim['lease'];
			$touched  = array();
			$out['claimed'] = count( $claim['rows'] );

			foreach ( $claim['rows'] as $row ) {
				$result = self::deliver_one( $row, $lease );
				if ( 'accepted' === $result ) {
					++$out['accepted'];
				} else {
					++$out['failed'];
				}
				$touched[ (int) $row['campaign_id'] ] = true;

				if ( microtime( true ) >= $deadline ) {
					$out['code'] = 'time_budget';
					break;
				}
			}

			// Out of budget (or a partial batch): give back every row still held by
			// this lease so the next run claims it immediately instead of waiting
			// for the lease to expire. Rows already written are untouched.
			$out['released'] = ANPA_Socios_Email_Queue_Repo::release_unprocessed( $lease );

			if ( $campaign_id > 0 ) {
				$touched[ $campaign_id ] = true;
			}
			foreach ( array_keys( $touched ) as $id ) {
				ANPA_Socios_Email_Queue_Repo::recalc_counts( $id );
				if ( self::close_if_complete( $id ) ) {
					++$out['finished'];
				}
			}

			update_option( self::LAST_RUN_OPTION, ANPA_Socios_Email_Queue_Repo::now_utc(), false );

			$out['ok'] = true;
			if ( '' === $out['code'] ) {
				$out['code'] = 0 === $out['claimed'] ? 'idle' : 'ok';
			}
			return $out;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Sends one claimed recipient and stores the outcome immediately.
	 *
	 * The attempt row is written after the result so an interrupted run leaves the
	 * recipient recoverable rather than double-counted.
	 *
	 * @since  1.39.0
	 * @param  array<string,mixed> $row   Claimed recipient row.
	 * @param  string              $lease Lease token owned by this run.
	 * @return string 'accepted' or 'failed'.
	 */
	private static function deliver_one( array $row, string $lease ): string {
		$recipient_id = (int) $row['id'];
		$campaign_id  = (int) $row['campaign_id'];
		$attempt_no   = (int) $row['attempts'] + 1;
		$started      = ANPA_Socios_Email_Queue_Repo::now_utc();
		$began        = microtime( true );

		$payload  = ANPA_Socios_Email_Render::thaw( (string) $row['payload_snapshot'] );
		$subject  = (string) ( $payload['subject'] ?? $row['subject_render'] );
		$body     = (string) ( $payload['body_html'] ?? ( $payload['body_text'] ?? '' ) );
		$is_html  = '' !== (string) ( $payload['body_html'] ?? '' );

		$category = '';
		$error    = '';
		$sent     = false;

		if ( '' === $subject || '' === $body ) {
			// A payload that cannot be rendered is a permanent-ish failure; backoff
			// still bounds it so it never loops forever.
			$category = 'render';
			$error     = 'empty subject or body in the frozen payload';
		} else {
			self::$last_mail_error = '';
			add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
			$headers = $is_html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();
			$sent    = (bool) wp_mail( (string) $row['email'], $subject, $body, $headers );
			remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );

			if ( ! $sent ) {
				$category = 'transport';
				$error    = '' !== self::$last_mail_error ? self::$last_mail_error : 'wp_mail returned false';
			}
		}

		if ( $sent ) {
			// ACCEPTED by the local mail system. NOT delivered.
			$applied = ANPA_Socios_Email_Queue_Repo::mark_accepted( $recipient_id, $lease );
			$result  = 'accepted';
		} else {
			$max     = self::max_attempts_for( $campaign_id );
			$outcome = ANPA_Socios_Email_Queue_Repo::mark_failed( $recipient_id, $lease, $error, $max );
			$applied = ! empty( $outcome['ok'] );
			$result  = 'failed';
		}

		if ( ! $applied ) {
			// The lease was lost (expired and stolen) between the send and the write.
			// Record the ambiguity instead of silently overwriting another owner.
			$category = 'uncertain';
			$error    = 'lease no longer owned when storing the result';
		}

		ANPA_Socios_Email_Queue_Repo::record_attempt(
			array(
				'campaign_id'    => $campaign_id,
				'recipient_id'   => $recipient_id,
				'attempt_no'     => $attempt_no,
				'started_at_utc' => $started,
				'result'         => $applied ? $result : 'uncertain',
				'error_category' => $category,
				'error_message'  => $error,
				'duration_ms'    => (int) round( ( microtime( true ) - $began ) * 1000 ),
				'correlation_id' => (string) $row['correlation_id'],
			)
		);

		return $result;
	}

	/**
	 * Captures the WP_Error raised by wp_mail() so the reason is auditable without
	 * storing headers, bodies, tokens or one-time codes.
	 *
	 * @since  1.39.0
	 * @param  mixed $error WP_Error instance.
	 * @return void
	 */
	public static function capture_mail_error( $error ): void {
		if ( is_wp_error( $error ) ) {
			self::$last_mail_error = (string) $error->get_error_message();
		}
	}

	/**
	 * Releases rows whose lease expired while `processing`, recording an
	 * `uncertain` attempt for each so a possible silent send stays auditable.
	 *
	 * @since  1.39.0
	 * @return int Rows recovered.
	 */
	public static function recover_interrupted(): int {
		$orphans = ANPA_Socios_Email_Queue_Repo::find_orphans( 100 );
		foreach ( $orphans as $row ) {
			ANPA_Socios_Email_Queue_Repo::record_attempt(
				array(
					'campaign_id'    => (int) $row['campaign_id'],
					'recipient_id'   => (int) $row['id'],
					'attempt_no'     => (int) $row['attempts'] + 1,
					'started_at_utc' => (string) ( $row['locked_at_utc'] ?? ANPA_Socios_Email_Queue_Repo::now_utc() ),
					'result'         => 'uncertain',
					'error_category' => 'uncertain',
					'error_message'  => 'interrupted after claiming; the message may or may not have been sent',
					'correlation_id' => (string) $row['correlation_id'],
				)
			);
		}

		return ANPA_Socios_Email_Queue_Repo::recover_orphans();
	}

	/**
	 * Finishes a campaign when no non-terminal recipient is left. Completion is
	 * decided by the real recipient state, never by a possibly stale counter.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return bool Whether the campaign was closed by this call.
	 */
	private static function close_if_complete( int $campaign_id ): bool {
		if ( ANPA_Socios_Email_Queue_Repo::count_unfinished( $campaign_id ) > 0 ) {
			return false;
		}
		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id );
		if ( null === $campaign || ANPA_Socios_Email_Campaign_State::RUNNING !== (string) $campaign['state'] ) {
			return false;
		}
		$done = ANPA_Socios_Email_Queue_Repo::set_campaign_state( $campaign_id, ANPA_Socios_Email_Campaign_State::FINISHED );

		return ! empty( $done['changed'] );
	}

	/**
	 * Retry ceiling for a campaign, falling back to the policy default.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return int
	 */
	private static function max_attempts_for( int $campaign_id ): int {
		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id );
		$max      = null !== $campaign ? (int) $campaign['max_attempts'] : 0;

		return $max > 0 ? $max : ANPA_Socios_Email_Backoff::MAX_DEFAULT;
	}

	/**
	 * Acquires the coarse overlap lock. add_option() only succeeds when the option
	 * does not exist, which makes ACQUISITION atomic. A stale lock (crashed run) is
	 * taken over after LOCK_TTL.
	 *
	 * The stale takeover is deliberately NOT atomic: two runs racing on an expired
	 * lock can both take it. That is acceptable because this lock is only an
	 * optimisation to avoid redundant work — the correctness guarantee is the
	 * row-level lease in claim_batch(), which no two runs can hold for the same
	 * recipient. Do not rely on this lock for mutual exclusion.
	 *
	 * @since  1.39.0
	 * @return bool
	 */
	private static function acquire_lock(): bool {
		$now = time();
		if ( add_option( self::LOCK_OPTION, (string) $now, '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && ( $now - $held ) < self::LOCK_TTL ) {
			return false;
		}
		// Stale lock: take it over.
		update_option( self::LOCK_OPTION, (string) $now, false );

		return true;
	}

	/**
	 * Releases the overlap lock.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	private static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
