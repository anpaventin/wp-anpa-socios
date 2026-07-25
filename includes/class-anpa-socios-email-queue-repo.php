<?php
/**
 * Persistence layer for the email queue (fase35, PR-35s3).
 *
 * Thin wpdb glue over wp_anpa_email_campaigns / _recipients / _attempts. All
 * transition RULES live in the pure value objects; all scheduling decisions live
 * in the pure policies (ANPA_Socios_Email_Backoff / _Batch_Planner). This class
 * only reads and writes rows.
 *
 * Invariants:
 *   - EVERY datetime is UTC. Writes use gmdate() or SQL UTC_TIMESTAMP(); reads
 *     compare against UTC_TIMESTAMP(). NOW() and current_time() are never used.
 *   - Deduplication is by LOGICAL MESSAGE IDENTITY, enforced by
 *     UNIQUE(idempotency_key) where the key is a sha256 over canonical JSON of
 *     [version, campaign_uuid, normalized_email, recipient_type, message_key].
 *   - Batch claiming is atomic: a conditional UPDATE stamps a random lease token
 *     on eligible rows; the follow-up SELECT only sees rows this process owns.
 *     Result writes re-check lease ownership, so a stolen/expired lease cannot
 *     overwrite another worker's outcome.
 *   - Campaign counters are a CACHE, never the source of truth. recalc_counts()
 *     recomputes them from the recipient rows and is the reconciliation path.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Queue_Repo {

	/** Default lease window in seconds for a claimed batch. */
	const LEASE_SECONDS = 120;

	/**
	 * Current UTC timestamp in MySQL format. Single source of "now" for PHP-side
	 * writes (SQL-side comparisons use UTC_TIMESTAMP()).
	 *
	 * @since  1.39.0
	 * @return string 'Y-m-d H:i:s' in UTC.
	 */
	public static function now_utc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Creates a campaign, or returns the existing one for the same
	 * idempotency key (so a retried operation never creates a duplicate).
	 *
	 * @since  1.39.0
	 * @param  array<string,mixed> $args Keys: event_type, idempotency_key (required),
	 *                                   uuid, course_year, trimester, entity_type,
	 *                                   entity_id, template_ref, payload_version,
	 *                                   batch_size, max_attempts, scheduled_at_utc,
	 *                                   purge_after_utc, created_by, meta.
	 * @return array{ok:bool,code:string,campaign_id:int,uuid:string,created:bool}
	 */
	public static function create_campaign( array $args ): array {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_email_campaigns();
		$key   = (string) ( $args['idempotency_key'] ?? '' );
		if ( '' === $key ) {
			return array( 'ok' => false, 'code' => 'missing_idempotency_key', 'campaign_id' => 0, 'uuid' => '', 'created' => false );
		}

		// Existing campaign for this logical operation?
		$existing = self::find_campaign_by_key( $key );
		if ( null !== $existing ) {
			return array(
				'ok'          => true,
				'code'        => 'exists',
				'campaign_id' => (int) $existing['id'],
				'uuid'        => (string) $existing['uuid'],
				'created'     => false,
			);
		}

		$now  = self::now_utc();
		$uuid = (string) ( $args['uuid'] ?? '' );
		if ( '' === $uuid ) {
			$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallback_uuid();
		}

		$data = array(
			'uuid'             => $uuid,
			'event_type'       => (string) ( $args['event_type'] ?? '' ),
			'state'            => ANPA_Socios_Email_Campaign_State::PENDING,
			'course_year'      => isset( $args['course_year'] ) && '' !== $args['course_year'] ? (string) $args['course_year'] : null,
			'trimester'        => isset( $args['trimester'] ) && '' !== $args['trimester'] ? (int) $args['trimester'] : null,
			'entity_type'      => (string) ( $args['entity_type'] ?? 'general' ),
			'entity_id'        => isset( $args['entity_id'] ) ? (int) $args['entity_id'] : null,
			'template_ref'     => isset( $args['template_ref'] ) && '' !== $args['template_ref'] ? (string) $args['template_ref'] : null,
			'payload_version'  => (int) ( $args['payload_version'] ?? 1 ),
			'batch_size'       => ANPA_Socios_Email_Batch_Planner::batch_size( (int) ( $args['batch_size'] ?? ANPA_Socios_Email_Batch_Planner::BATCH_DEFAULT ) ),
			'max_attempts'     => max( 1, (int) ( $args['max_attempts'] ?? ANPA_Socios_Email_Backoff::MAX_DEFAULT ) ),
			'scheduled_at_utc' => isset( $args['scheduled_at_utc'] ) && '' !== $args['scheduled_at_utc'] ? (string) $args['scheduled_at_utc'] : null,
			'purge_after_utc'  => isset( $args['purge_after_utc'] ) && '' !== $args['purge_after_utc'] ? (string) $args['purge_after_utc'] : null,
			'created_at_utc'   => $now,
			'created_by'       => (string) ( $args['created_by'] ?? '' ),
			'idempotency_key'  => $key,
			'meta_json'        => isset( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : null,
		);

		$inserted = $wpdb->insert( $table, $data );
		if ( false === $inserted ) {
			// A concurrent create may have won the UNIQUE race — adopt that row.
			$existing = self::find_campaign_by_key( $key );
			if ( null !== $existing ) {
				return array(
					'ok'          => true,
					'code'        => 'exists',
					'campaign_id' => (int) $existing['id'],
					'uuid'        => (string) $existing['uuid'],
					'created'     => false,
				);
			}
			return array( 'ok' => false, 'code' => 'db_error', 'campaign_id' => 0, 'uuid' => '', 'created' => false );
		}

		return array(
			'ok'          => true,
			'code'        => 'created',
			'campaign_id' => (int) $wpdb->insert_id,
			'uuid'        => $uuid,
			'created'     => true,
		);
	}

	/**
	 * Finds a campaign row by idempotency key.
	 *
	 * @since  1.39.0
	 * @param  string $key Idempotency key.
	 * @return array<string,mixed>|null
	 */
	public static function find_campaign_by_key( string $key ): ?array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_campaigns();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Reads a campaign by id.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return array<string,mixed>|null
	 */
	public static function get_campaign( int $campaign_id ): ?array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_campaigns();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $campaign_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Inserts one recipient row, ignoring duplicates by logical identity.
	 *
	 * Idempotent: a repeated enqueue of the same logical message is a no-op
	 * (guaranteed by UNIQUE(idempotency_key)); the existing row is preserved so a
	 * retry never resets its state or attempt history.
	 *
	 * @since  1.39.0
	 * @param  int                 $campaign_id Campaign id.
	 * @param  string              $campaign_uuid Campaign uuid (for the key).
	 * @param  array<string,mixed> $recipient Prepared recipient (email, recipient_type,
	 *                                        message_key, entity_type, entity_id).
	 * @param  array<string,mixed> $payload   Keys: subject, body_hash, snapshot,
	 *                                        correlation_id.
	 * @return array{ok:bool,code:string,recipient_id:int,inserted:bool}
	 */
	public static function enqueue_recipient( int $campaign_id, string $campaign_uuid, array $recipient, array $payload = array() ): array {
		global $wpdb;

		$table = ANPA_Socios_DB::tabela_email_recipients();
		$email = ANPA_Socios_Email_Recipients::normalize( (string) ( $recipient['email'] ?? '' ) );
		if ( ! ANPA_Socios_Email_Recipients::valid( $email ) ) {
			return array( 'ok' => false, 'code' => 'invalid_email', 'recipient_id' => 0, 'inserted' => false );
		}

		$type = (string) ( $recipient['recipient_type'] ?? 'other' );
		$mkey = (string) ( $recipient['message_key'] ?? '' );
		$key  = ANPA_Socios_Email_Recipients::idempotency_key( $campaign_uuid, $email, $type, $mkey );
		$now  = self::now_utc();

		$columns = array(
			'campaign_id'     => $campaign_id,
			'email'           => $email,
			'recipient_type'  => $type,
			'message_key'     => $mkey,
			'entity_type'     => (string) ( $recipient['entity_type'] ?? 'general' ),
			'entity_id'       => isset( $recipient['entity_id'] ) ? (int) $recipient['entity_id'] : null,
			'state'           => ANPA_Socios_Email_Recipient_State::PENDING,
			'subject_render'  => mb_substr( (string) ( $payload['subject'] ?? '' ), 0, 255 ),
			'payload_snapshot' => isset( $payload['snapshot'] ) ? (string) $payload['snapshot'] : null,
			'payload_hash'    => (string) ( $payload['payload_hash'] ?? '' ),
			'idempotency_key' => $key,
			'correlation_id'  => (string) ( $payload['correlation_id'] ?? '' ),
			'created_at_utc'  => $now,
			'updated_at_utc'  => $now,
		);

		// INSERT IGNORE so a duplicate logical identity is a silent no-op.
		$cols   = array();
		$holders = array();
		$values = array();
		foreach ( $columns as $col => $val ) {
			$cols[]    = '`' . $col . '`';
			$holders[] = null === $val ? 'NULL' : ( is_int( $val ) ? '%d' : '%s' );
			if ( null !== $val ) {
				$values[] = $val;
			}
		}
		$sql = 'INSERT IGNORE INTO ' . $table . ' (' . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $holders ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- placeholders built per column; values bound.
		$affected = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		if ( false === $affected ) {
			return array( 'ok' => false, 'code' => 'db_error', 'recipient_id' => 0, 'inserted' => false );
		}
		if ( 0 === (int) $affected ) {
			$existing = self::find_recipient_by_key( $key );
			return array(
				'ok'           => true,
				'code'         => 'duplicate',
				'recipient_id' => null !== $existing ? (int) $existing['id'] : 0,
				'inserted'     => false,
			);
		}

		return array( 'ok' => true, 'code' => 'inserted', 'recipient_id' => (int) $wpdb->insert_id, 'inserted' => true );
	}

	/**
	 * Finds a recipient row by idempotency key.
	 *
	 * @since  1.39.0
	 * @param  string $key Idempotency key.
	 * @return array<string,mixed>|null
	 */
	public static function find_recipient_by_key( string $key ): ?array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomically claims up to $limit due recipients for a campaign (or for any
	 * campaign when $campaign_id is 0), stamping a random lease token.
	 *
	 * The conditional UPDATE is the concurrency primitive: two simultaneous
	 * workers can never claim the same row, because the first one moves it out of
	 * the claimable state set and stamps its own token. Rows whose lease expired
	 * (interrupted worker) become claimable again.
	 *
	 * The campaign gate is expressed as a subquery, NOT as a JOIN: MySQL rejects
	 * LIMIT on a multi-table UPDATE (error 1221), and an unbounded claim would
	 * ignore the batch size. A single-table UPDATE keeps the claim atomic while
	 * still honouring the planner's batch limit.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id, or 0 for any runnable campaign.
	 * @param  int $limit       Batch size (clamped by the pure planner).
	 * @param  int $lease_secs  Lease window in seconds.
	 * @return array{ok:bool,lease:string,rows:array<int,array<string,mixed>>}
	 */
	public static function claim_batch( int $campaign_id, int $limit, int $lease_secs = self::LEASE_SECONDS ): array {
		global $wpdb;

		$table  = ANPA_Socios_DB::tabela_email_recipients();
		$camps  = ANPA_Socios_DB::tabela_email_campaigns();
		$limit  = ANPA_Socios_Email_Batch_Planner::batch_size( $limit );
		$lease  = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallback_uuid();
		$secs   = max( 10, $lease_secs );

		$pending    = ANPA_Socios_Email_Recipient_State::PENDING;
		$failed     = ANPA_Socios_Email_Recipient_State::FAILED;
		$processing = ANPA_Socios_Email_Recipient_State::PROCESSING;
		$running    = ANPA_Socios_Email_Campaign_State::RUNNING;
		$campaign_pending = ANPA_Socios_Email_Campaign_State::PENDING;

		// Only claim from campaigns that are runnable (pending/running) and due.
		$scope = '';
		$args  = array( $processing, $lease, $secs, $pending, $failed );
		if ( $campaign_id > 0 ) {
			$scope  = ' AND r.campaign_id = %d';
			$args[] = $campaign_id;
		}
		$args[] = $campaign_pending;
		$args[] = $running;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table names internal; values bound.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} r
				    SET r.state = %s,
				        r.lease_token = %s,
				        r.locked_at_utc = UTC_TIMESTAMP(),
				        r.locked_until_utc = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
				        r.updated_at_utc = UTC_TIMESTAMP()
				  WHERE r.state IN (%s, %s)
				    {$scope}
				    AND ( r.next_attempt_at_utc IS NULL OR r.next_attempt_at_utc <= UTC_TIMESTAMP() )
				    AND ( r.locked_until_utc IS NULL OR r.locked_until_utc < UTC_TIMESTAMP() )
				    AND r.campaign_id IN (
				        SELECT c.id FROM {$camps} c
				         WHERE c.state IN (%s, %s)
				           AND ( c.scheduled_at_utc IS NULL OR c.scheduled_at_utc <= UTC_TIMESTAMP() )
				    )
				  ORDER BY r.id
				  LIMIT {$limit}",
				$args
			)
		);

		if ( false === $claimed ) {
			return array( 'ok' => false, 'lease' => '', 'rows' => array() );
		}
		if ( 0 === (int) $claimed ) {
			return array( 'ok' => true, 'lease' => $lease, 'rows' => array() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only; lease bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE lease_token = %s ORDER BY id", $lease ),
			ARRAY_A
		);

		return array( 'ok' => true, 'lease' => $lease, 'rows' => is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Marks a claimed recipient as accepted by the transport. Requires the caller
	 * to still own the lease; otherwise the write is refused (0 rows) so a worker
	 * whose lease expired cannot overwrite the new owner's outcome.
	 *
	 * @since  1.39.0
	 * @param  int    $recipient_id Recipient id.
	 * @param  string $lease        Lease token held by the caller.
	 * @return bool True when this caller's write applied.
	 */
	public static function mark_accepted( int $recipient_id, string $lease ): bool {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- values bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET state = %s,
				        attempts = attempts + 1,
				        last_attempt_at_utc = UTC_TIMESTAMP(),
				        accepted_at_utc = UTC_TIMESTAMP(),
				        next_attempt_at_utc = NULL,
				        last_error = '',
				        lease_token = '',
				        locked_at_utc = NULL,
				        locked_until_utc = NULL,
				        updated_at_utc = UTC_TIMESTAMP()
				  WHERE id = %d AND lease_token = %s AND state = %s",
				ANPA_Socios_Email_Recipient_State::ACCEPTED,
				$recipient_id,
				$lease,
				ANPA_Socios_Email_Recipient_State::PROCESSING
			)
		);

		return is_numeric( $done ) && (int) $done > 0;
	}

	/**
	 * Marks a claimed recipient as failed, applying the pure backoff policy to
	 * decide between a retryable failure and a permanent one. Lease ownership is
	 * required, same as mark_accepted().
	 *
	 * @since  1.39.0
	 * @param  int    $recipient_id Recipient id.
	 * @param  string $lease        Lease token held by the caller.
	 * @param  string $error        Error message (redacted, truncated).
	 * @param  int    $max_attempts Max attempts for this campaign.
	 * @return array{ok:bool,state:string,delay:int}
	 */
	public static function mark_failed( int $recipient_id, string $lease, string $error, int $max_attempts = ANPA_Socios_Email_Backoff::MAX_DEFAULT ): array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();

		$row = self::get_recipient( $recipient_id );
		if ( null === $row ) {
			return array( 'ok' => false, 'state' => '', 'delay' => 0 );
		}

		$attempts = (int) $row['attempts'] + 1;
		$state    = ANPA_Socios_Email_Backoff::state_after_failure( $attempts, $max_attempts );
		$delay    = ANPA_Socios_Email_Recipient_State::FAILED === $state
			? ANPA_Socios_Email_Backoff::delay_for( $attempts )
			: 0;

		$next_sql = ANPA_Socios_Email_Recipient_State::FAILED === $state
			? 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . (int) $delay . ' SECOND)'
			: 'NULL';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- interval is an int literal; values bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET state = %s,
				        attempts = %d,
				        last_attempt_at_utc = UTC_TIMESTAMP(),
				        next_attempt_at_utc = {$next_sql},
				        last_error = %s,
				        lease_token = '',
				        locked_at_utc = NULL,
				        locked_until_utc = NULL,
				        updated_at_utc = UTC_TIMESTAMP()
				  WHERE id = %d AND lease_token = %s AND state = %s",
				$state,
				$attempts,
				self::redact_error( $error ),
				$recipient_id,
				$lease,
				ANPA_Socios_Email_Recipient_State::PROCESSING
			)
		);

		return array(
			'ok'    => is_numeric( $done ) && (int) $done > 0,
			'state' => $state,
			'delay' => $delay,
		);
	}

	/**
	 * Lists recipients whose lease expired while `processing` (an interrupted
	 * worker), so the caller can audit them before they are released.
	 *
	 * Because wp_mail() gives no idempotent send id, a crash AFTER the transport
	 * accepted the message but BEFORE the result was stored is indistinguishable
	 * from a crash before sending. Such rows are logged with the `uncertain`
	 * category by the processor: the model is at-least-once, never exactly-once.
	 *
	 * @since  1.39.0
	 * @param  int $limit Maximum rows to inspect (clamped to 200).
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_orphans( int $limit = 100 ): array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();
		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- read-only; values bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				  WHERE state = %s
				    AND locked_until_utc IS NOT NULL
				    AND locked_until_utc < UTC_TIMESTAMP()
				  ORDER BY id
				  LIMIT {$limit}",
				ANPA_Socios_Email_Recipient_State::PROCESSING
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Releases recipients whose lease expired (interrupted worker) back to pending.
	 *
	 * The interrupted attempt is CONSUMED (attempts + 1): the transport may already
	 * have accepted the message, so an unbounded free retry would risk an unbounded
	 * number of duplicates. The caller records an `uncertain` attempt first (see
	 * ANPA_Socios_Email_Processor) so the ambiguity is auditable.
	 *
	 * @since  1.39.0
	 * @return int Rows recovered.
	 */
	public static function recover_orphans(): int {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- values bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET state = %s,
				        attempts = attempts + 1,
				        last_attempt_at_utc = UTC_TIMESTAMP(),
				        lease_token = '',
				        locked_at_utc = NULL,
				        locked_until_utc = NULL,
				        updated_at_utc = UTC_TIMESTAMP()
				  WHERE state = %s
				    AND locked_until_utc IS NOT NULL
				    AND locked_until_utc < UTC_TIMESTAMP()",
				ANPA_Socios_Email_Recipient_State::PENDING,
				ANPA_Socios_Email_Recipient_State::PROCESSING
			)
		);

		return is_numeric( $done ) ? (int) $done : 0;
	}

	/**
	 * Releases rows still held by this lease back to `pending` WITHOUT consuming an
	 * attempt: they were claimed but never sent (the run ran out of time budget), so
	 * the next run must be able to pick them up immediately.
	 *
	 * Only rows still `processing` under this exact lease are touched, so a result
	 * already written by this run is never undone.
	 *
	 * @since  1.39.0
	 * @param  string $lease Lease token owned by the caller.
	 * @return int Rows released.
	 */
	public static function release_unprocessed( string $lease ): int {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();

		if ( '' === $lease ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- values bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET state = %s,
				        lease_token = '',
				        locked_at_utc = NULL,
				        locked_until_utc = NULL,
				        updated_at_utc = UTC_TIMESTAMP()
				  WHERE lease_token = %s AND state = %s",
				ANPA_Socios_Email_Recipient_State::PENDING,
				$lease,
				ANPA_Socios_Email_Recipient_State::PROCESSING
			)
		);

		return is_numeric( $done ) ? (int) $done : 0;
	}

	/**
	 * Reads a recipient row by id.
	 *
	 * @since  1.39.0
	 * @param  int $recipient_id Recipient id.
	 * @return array<string,mixed>|null
	 */
	public static function get_recipient( int $recipient_id ): ?array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $recipient_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Records one delivery attempt. Idempotent per (recipient, attempt number)
	 * thanks to UNIQUE(recipient_id, attempt_no): a duplicated record attempt is
	 * ignored instead of duplicating history.
	 *
	 * Never stores SMTP headers, one-time codes, tokens, credentials or bodies.
	 *
	 * @since  1.39.0
	 * @param  array<string,mixed> $args Keys: campaign_id, recipient_id, attempt_no,
	 *                                   started_at_utc, result, error_category,
	 *                                   error_message, duration_ms, correlation_id.
	 * @return bool
	 */
	public static function record_attempt( array $args ): bool {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_attempts();

		$started = (string) ( $args['started_at_utc'] ?? self::now_utc() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- values bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				 (campaign_id, recipient_id, attempt_no, started_at_utc, finished_at_utc, result, error_category, error_message, duration_ms, correlation_id)
				 VALUES (%d, %d, %d, %s, UTC_TIMESTAMP(), %s, %s, %s, %d, %s)",
				(int) ( $args['campaign_id'] ?? 0 ),
				(int) ( $args['recipient_id'] ?? 0 ),
				(int) ( $args['attempt_no'] ?? 1 ),
				$started,
				(string) ( $args['result'] ?? '' ),
				(string) ( $args['error_category'] ?? '' ),
				self::redact_error( (string) ( $args['error_message'] ?? '' ) ),
				(int) ( $args['duration_ms'] ?? 0 ),
				(string) ( $args['correlation_id'] ?? '' )
			)
		);

		return false !== $done;
	}

	/**
	 * Recomputes a campaign's counters FROM the recipient rows (the source of
	 * truth) and stores them. This is the reconciliation path for counters that
	 * drifted through interruptions, concurrency, retries or manual repairs.
	 * Counters can never go negative because they are recomputed, not adjusted.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return array<string,int> The recomputed counters.
	 */
	public static function recalc_counts( int $campaign_id ): array {
		global $wpdb;
		$recips = ANPA_Socios_DB::tabela_email_recipients();
		$camps  = ANPA_Socios_DB::tabela_email_campaigns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT state, COUNT(*) AS n FROM {$recips} WHERE campaign_id = %d GROUP BY state", $campaign_id ),
			ARRAY_A
		);

		$by_state = array();
		foreach ( (array) $rows as $row ) {
			$by_state[ (string) $row['state'] ] = (int) $row['n'];
		}

		$pending   = (int) ( $by_state[ ANPA_Socios_Email_Recipient_State::PENDING ] ?? 0 );
		$procing   = (int) ( $by_state[ ANPA_Socios_Email_Recipient_State::PROCESSING ] ?? 0 );
		$accepted  = (int) ( $by_state[ ANPA_Socios_Email_Recipient_State::ACCEPTED ] ?? 0 );
		$failed    = (int) ( $by_state[ ANPA_Socios_Email_Recipient_State::FAILED ] ?? 0 );
		$permanent = (int) ( $by_state[ ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT ] ?? 0 );
		$cancelled = (int) ( $by_state[ ANPA_Socios_Email_Recipient_State::CANCELLED ] ?? 0 );

		$total     = $pending + $procing + $accepted + $failed + $permanent + $cancelled;
		$processed = $accepted + $permanent + $cancelled;

		$counts = array(
			'total'           => $total,
			'pending_count'   => $pending + $failed + $procing,
			'processed_count' => $processed,
			'accepted_count'  => $accepted,
			'failed_count'    => $permanent,
			'cancelled_count' => $cancelled,
		);

		$wpdb->update(
			$camps,
			$counts + array( 'updated_at_utc' => self::now_utc() ),
			array( 'id' => $campaign_id )
		);

		return $counts;
	}

	/**
	 * Whether a campaign still has non-terminal recipients. The authoritative
	 * completion check — a campaign is never finished off a possibly stale
	 * counter.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return int Count of non-terminal recipients.
	 */
	public static function count_unfinished( int $campaign_id ): int {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate; values bound.
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND state NOT IN (%s, %s, %s)",
				$campaign_id,
				ANPA_Socios_Email_Recipient_State::ACCEPTED,
				ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT,
				ANPA_Socios_Email_Recipient_State::CANCELLED
			)
		);

		return (int) $n;
	}

	/**
	 * Lists campaigns, newest first, for the admin screen.
	 *
	 * @since  1.39.0
	 * @param  int $limit  Page size (clamped to 100).
	 * @param  int $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_campaigns( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$table  = ANPA_Socios_DB::tabela_email_campaigns();
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- read-only; integers clamped.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total number of campaigns (for pagination).
	 *
	 * @since  1.39.0
	 * @return int
	 */
	public static function count_campaigns(): int {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_campaigns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Lists the recipients of a campaign for the detail view.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @param  int $limit       Page size (clamped to 200).
	 * @param  int $offset      Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_recipients( int $campaign_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table  = ANPA_Socios_DB::tabela_email_recipients();
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- read-only; id bound, integers clamped.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, campaign_id, email, recipient_type, message_key, state, attempts,
				        next_attempt_at_utc, last_attempt_at_utc, accepted_at_utc, last_error,
				        subject_render, payload_hash, created_at_utc, updated_at_utc
				   FROM {$table}
				  WHERE campaign_id = %d
				  ORDER BY id
				  LIMIT {$limit} OFFSET {$offset}",
				$campaign_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Lists the attempt history of one recipient.
	 *
	 * @since  1.39.0
	 * @param  int $recipient_id Recipient id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_attempts( int $recipient_id ): array {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_attempts();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only; id bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE recipient_id = %d ORDER BY attempt_no", $recipient_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether a campaign has at least one attempt recorded as `uncertain`, i.e. a
	 * send that may have gone out twice. Drives the duplicate warning in the UI.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return bool
	 */
	public static function has_uncertain_attempts( int $campaign_id ): bool {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_attempts();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate; values bound.
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND ( result = %s OR error_category = %s )",
				$campaign_id,
				'uncertain',
				'uncertain'
			)
		);

		return (int) $n > 0;
	}

	/**
	 * Applies a validated campaign state transition (the value object decides
	 * whether it is allowed) and stamps the matching timestamp column.
	 *
	 * @since  1.39.0
	 * @param  int    $campaign_id Campaign id.
	 * @param  string $to          Target state.
	 * @return array{ok:bool,code:string,changed:bool}
	 */
	public static function set_campaign_state( int $campaign_id, string $to ): array {
		global $wpdb;
		$camps = ANPA_Socios_DB::tabela_email_campaigns();

		$campaign = self::get_campaign( $campaign_id );
		if ( null === $campaign ) {
			return array( 'ok' => false, 'code' => 'not_found', 'changed' => false );
		}
		$from = (string) $campaign['state'];
		if ( $from === $to ) {
			return array( 'ok' => true, 'code' => 'noop', 'changed' => false );
		}
		if ( ! ANPA_Socios_Email_Campaign_State::can_transition( $from, $to ) ) {
			return array( 'ok' => false, 'code' => 'transition_not_allowed', 'changed' => false );
		}

		$now  = self::now_utc();
		$data = array( 'state' => $to, 'updated_at_utc' => $now );
		switch ( $to ) {
			case ANPA_Socios_Email_Campaign_State::RUNNING:
				if ( empty( $campaign['started_at_utc'] ) ) {
					$data['started_at_utc'] = $now;
				}
				break;
			case ANPA_Socios_Email_Campaign_State::PAUSED:
				$data['paused_at_utc'] = $now;
				break;
			case ANPA_Socios_Email_Campaign_State::FINISHED:
				$data['finished_at_utc'] = $now;
				break;
			case ANPA_Socios_Email_Campaign_State::CANCELLED:
				$data['cancelled_at_utc'] = $now;
				break;
		}

		$done = $wpdb->update( $camps, $data, array( 'id' => $campaign_id ) );
		if ( false === $done ) {
			return array( 'ok' => false, 'code' => 'db_error', 'changed' => false );
		}

		return array( 'ok' => true, 'code' => 'ok', 'changed' => true );
	}

	/**
	 * Cancels every still-pending recipient of a campaign. Already accepted rows
	 * are untouched: an accepted message cannot be un-sent.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return int Number of cancelled recipients.
	 */
	public static function cancel_pending_recipients( int $campaign_id ): int {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- values bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET state = %s,
				        lease_token = '',
				        locked_at_utc = NULL,
				        locked_until_utc = NULL,
				        next_attempt_at_utc = NULL,
				        updated_at_utc = UTC_TIMESTAMP()
				  WHERE campaign_id = %d AND state IN (%s, %s)",
				ANPA_Socios_Email_Recipient_State::CANCELLED,
				$campaign_id,
				ANPA_Socios_Email_Recipient_State::PENDING,
				ANPA_Socios_Email_Recipient_State::FAILED
			)
		);

		return is_numeric( $done ) ? (int) $done : 0;
	}

	/**
	 * Requeues only the failed recipients of a campaign (both retryable and
	 * permanently failed), leaving accepted ones untouched.
	 *
	 * @since  1.39.0
	 * @param  int $campaign_id Campaign id.
	 * @return int Number of requeued recipients.
	 */
	public static function retry_failed( int $campaign_id ): int {
		global $wpdb;
		$table = ANPA_Socios_DB::tabela_email_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- values bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET state = %s,
				        next_attempt_at_utc = NULL,
				        lease_token = '',
				        locked_at_utc = NULL,
				        locked_until_utc = NULL,
				        updated_at_utc = UTC_TIMESTAMP()
				  WHERE campaign_id = %d AND state IN (%s, %s)",
				ANPA_Socios_Email_Recipient_State::PENDING,
				$campaign_id,
				ANPA_Socios_Email_Recipient_State::FAILED,
				ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT
			)
		);

		return is_numeric( $done ) ? (int) $done : 0;
	}

	/**
	 * Truncates and strips newlines from an error message so the log never grows
	 * unbounded and never carries multi-line transport dumps.
	 *
	 * @since  1.39.0
	 * @param  string $error Raw error.
	 * @return string
	 */
	private static function redact_error( string $error ): string {
		$error = trim( preg_replace( '/\s+/', ' ', $error ) ?? '' );
		return mb_substr( $error, 0, 255 );
	}

	/**
	 * RFC-4122-ish v4 uuid for environments without wp_generate_uuid4().
	 *
	 * @since  1.39.0
	 * @return string
	 */
	private static function fallback_uuid(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
