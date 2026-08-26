<?php
/**
 * Public queue API (fase35, PR-35s3).
 *
 * The single entry point other modules (fase36 templates, fase39 admin flows)
 * use to enqueue a campaign. Callers never touch the tables directly.
 *
 * Guarantees:
 *   - Enqueuing is idempotent at BOTH levels: the campaign (its own idempotency
 *     key) and each recipient (logical message identity).
 *   - Recipients are deduplicated by logical identity, so principal/secondary
 *     parents sharing an address collapse for the SAME message, while distinct
 *     messages to the same address are preserved.
 *   - Every message is FROZEN at enqueue time (immutable snapshot + hash), so a
 *     later template edit cannot change pending recipients.
 *   - Enqueuing never sends: it only writes rows. Sending is delegated to
 *     ANPA_Socios_Email_Processor (this class never calls the transport) and
 *     never happens during an install or a pending migration.
 *   - Control operations (pause/resume/cancel/retry) go through the campaign
 *     state machine, so an illegal transition is refused instead of forced.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Queue {

	/**
	 * Enqueues a campaign and its recipients.
	 *
	 * @since  1.39.0
	 * @param  array<string,mixed> $args {
	 *     @type string $event_type      Logical event (required).
	 *     @type string $idempotency_key Stable key for this operation (required).
	 *     @type array  $recipients      List of raw recipients: each with 'email' and
	 *                                   optionally recipient_type, message_key,
	 *                                   entity_type, entity_id, context.
	 *     @type array  $context         Shared context merged under each recipient's.
	 *     @type string $template_ref    Template id (fase36) or ''.
	 *     @type string $course_year     Correlation with fase34 (optional).
	 *     @type int    $trimester       Correlation with fase34 (optional).
	 *     @type int    $batch_size      Per-run batch size (clamped).
	 *     @type int    $max_attempts    Retry ceiling.
	 *     @type string $scheduled_at_utc Do not start before this UTC datetime.
	 *     @type string $created_by      Actor (email or 'sistema').
	 *     @type string $correlation_id  Correlation id for the whole operation.
	 * }
	 * @return array{ok:bool,code:string,campaign_id:int,uuid:string,queued:int,skipped:int,created:bool}
	 */
	public static function enqueue_campaign( array $args ): array {
		$fail = array(
			'ok' => false, 'code' => '', 'campaign_id' => 0,
			'uuid' => '', 'queued' => 0, 'skipped' => 0, 'created' => false,
		);

		$event = (string) ( $args['event_type'] ?? '' );
		$key   = (string) ( $args['idempotency_key'] ?? '' );
		if ( '' === $event || '' === $key ) {
			$fail['code'] = 'missing_event_or_key';
			return $fail;
		}

		$raw = isset( $args['recipients'] ) && is_array( $args['recipients'] ) ? $args['recipients'] : array();
		// Deduplicate by logical identity BEFORE writing anything.
		$prepared = ANPA_Socios_Email_Recipients::prepare( $raw );
		$valid    = $prepared['valid'];
		$skipped  = count( $prepared['skipped'] );

		$campaign = ANPA_Socios_Email_Queue_Repo::create_campaign(
			array(
				'event_type'       => $event,
				'idempotency_key'  => $key,
				'course_year'      => $args['course_year'] ?? '',
				'trimester'        => $args['trimester'] ?? '',
				'entity_type'      => $args['entity_type'] ?? 'general',
				'entity_id'        => $args['entity_id'] ?? 0,
				'template_ref'     => $args['template_ref'] ?? '',
				'payload_version'  => ANPA_Socios_Email_Render::PAYLOAD_VERSION,
				'batch_size'       => $args['batch_size'] ?? ANPA_Socios_Email_Batch_Planner::BATCH_DEFAULT,
				'max_attempts'     => $args['max_attempts'] ?? ANPA_Socios_Email_Backoff::MAX_DEFAULT,
				'scheduled_at_utc' => $args['scheduled_at_utc'] ?? '',
				'purge_after_utc'  => $args['purge_after_utc'] ?? '',
				'created_by'       => $args['created_by'] ?? '',
				'meta'             => $args['meta'] ?? null,
			)
		);

		if ( empty( $campaign['ok'] ) ) {
			$fail['code'] = (string) $campaign['code'];
			return $fail;
		}

		$campaign_id  = (int) $campaign['campaign_id'];
		$campaign_uuid = (string) $campaign['uuid'];
		$template_ref = (string) ( $args['template_ref'] ?? '' );
		$shared_ctx   = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array();
		$correlation  = (string) ( $args['correlation_id'] ?? '' );

		$queued = 0;
		foreach ( $valid as $recipient ) {
			// Per-recipient context wins over the shared one.
			$ctx = $shared_ctx;
			if ( isset( $recipient['context'] ) && is_array( $recipient['context'] ) ) {
				$ctx = array_merge( $ctx, $recipient['context'] );
			}
			$ctx['email']          = $recipient['email'];
			$ctx['recipient_type'] = $recipient['recipient_type'];
			$ctx['message_key']    = $recipient['message_key'];

			// FREEZE the message now: later template edits cannot change it.
			$frozen = ANPA_Socios_Email_Render::freeze( $event, $template_ref, $ctx );

			$res = ANPA_Socios_Email_Queue_Repo::enqueue_recipient(
				$campaign_id,
				$campaign_uuid,
				$recipient,
				array(
					'subject'        => $frozen['subject'],
					'snapshot'       => $frozen['snapshot'],
					'payload_hash'   => $frozen['payload_hash'],
					'correlation_id' => $correlation,
				)
			);

			if ( ! empty( $res['inserted'] ) ) {
				++$queued;
			} elseif ( 'duplicate' === (string) $res['code'] ) {
				++$skipped;
			}
		}

		// Counters are recomputed from the rows, never incremented blindly.
		ANPA_Socios_Email_Queue_Repo::recalc_counts( $campaign_id );

		return array(
			'ok'          => true,
			'code'        => 'ok',
			'campaign_id' => $campaign_id,
			'uuid'        => $campaign_uuid,
			'queued'      => $queued,
			'skipped'     => $skipped,
			'created'     => ! empty( $campaign['created'] ),
		);
	}

	/**
	 * Processes one bounded batch. This is the cron entry point
	 * (ANPA_Socios_Email_Cron::tick) and the "process now" admin action target.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Restrict to one campaign, or 0 for any runnable one.
	 * @return array<string,mixed> Processor result.
	 */
	public static function process_due_batch( int $campaign_id = 0 ): array {
		return ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $campaign_id ) );
	}

	/**
	 * Pauses a running campaign. Already claimed recipients finish their current
	 * attempt (their lease is honoured); nothing new is claimed afterwards.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	public static function pause( int $campaign_id ): array {
		return ANPA_Socios_Email_Queue_Repo::set_campaign_state( $campaign_id, ANPA_Socios_Email_Campaign_State::PAUSED );
	}

	/**
	 * Resumes a paused campaign.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	public static function resume( int $campaign_id ): array {
		return ANPA_Socios_Email_Queue_Repo::set_campaign_state( $campaign_id, ANPA_Socios_Email_Campaign_State::RUNNING );
	}

	/**
	 * Cancels a campaign: every still-pending recipient is cancelled, accepted ones
	 * are left untouched (an accepted message cannot be un-sent).
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return array{ok:bool,code:string,cancelled:int}
	 */
	public static function cancel( int $campaign_id ): array {
		$cancelled = ANPA_Socios_Email_Queue_Repo::cancel_pending_recipients( $campaign_id );
		$state     = ANPA_Socios_Email_Queue_Repo::set_campaign_state( $campaign_id, ANPA_Socios_Email_Campaign_State::CANCELLED );
		ANPA_Socios_Email_Queue_Repo::recalc_counts( $campaign_id );

		return array(
			'ok'        => ! empty( $state['ok'] ),
			'code'      => (string) $state['code'],
			'cancelled' => $cancelled,
		);
	}

	/**
	 * Requeues only the failed recipients of a campaign and reopens it so the
	 * processor can pick them up. Accepted recipients are never touched.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return array{ok:bool,code:string,requeued:int}
	 */
	public static function retry_failed( int $campaign_id ): array {
		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $campaign_id );
		if ( null === $campaign ) {
			return array( 'ok' => false, 'code' => 'not_found', 'requeued' => 0 );
		}

		// A finished or cancelled campaign is TERMINAL by design, and the processor
		// only claims from pending/running campaigns. Requeuing here would leave
		// rows pending in a campaign that can never run again, so the retry is
		// refused: a deliberate resend is a NEW campaign (its own idempotency key).
		if ( ANPA_Socios_Email_Campaign_State::terminal( (string) $campaign['state'] ) ) {
			return array( 'ok' => false, 'code' => 'campaign_terminal', 'requeued' => 0 );
		}

		$code = 'ok';
		if ( ANPA_Socios_Email_Campaign_State::PAUSED === (string) $campaign['state'] ) {
			$code = (string) ANPA_Socios_Email_Queue_Repo::set_campaign_state( $campaign_id, ANPA_Socios_Email_Campaign_State::RUNNING )['code'];
		}

		$requeued = ANPA_Socios_Email_Queue_Repo::retry_failed( $campaign_id );
		ANPA_Socios_Email_Queue_Repo::recalc_counts( $campaign_id );

		return array( 'ok' => true, 'code' => $code, 'requeued' => $requeued );
	}

	/**
	 * Whether the queue may run right now. Never process during an install or a
	 * schema upgrade: a migration must never trigger sends.
	 *
	 * @since  1.39.0
	 * @return bool
	 */
	public static function can_run(): bool {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return false;
		}
		$installed = (string) get_option( ANPA_Socios_DB::VERSION_OPTION, '0.0.0' );
		if ( version_compare( $installed, ANPA_Socios_DB::DB_VERSION, '<' ) ) {
			return false; // Pending migration.
		}
		return true;
	}
}
