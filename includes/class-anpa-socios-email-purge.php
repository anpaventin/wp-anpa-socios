<?php
/**
 * Retention enforcement for the communications log (fase35, PR-35s6).
 *
 * Runs from a daily maintenance event. Two ordered steps, decided by the pure
 * policy in ANPA_Socios_Email_Retention:
 *   1. Clear the rendered payload and the personal context of TERMINAL campaigns
 *      past the diagnosis window, keeping state, counters and `payload_hash`.
 *   2. Delete the minimal rows of TERMINAL campaigns past the long window.
 *
 * Hard rules:
 *   - A campaign that is not terminal is NEVER touched: a pending or running
 *     campaign still needs its payload to send.
 *   - A campaign with any non-terminal recipient is never deleted, even if its
 *     own state says otherwise: the recipients are the source of truth.
 *   - Payload always goes before metadata, so the sensitive layer cannot outlive
 *     the rows that reference it.
 *   - Idempotent: a second run in the same day changes nothing further.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Purge {

	/** Option holding the payload retention window in days. */
	const OPTION_PAYLOAD_DAYS = 'anpa_socios_email_payload_retention_days';

	/** Option holding the metadata retention window in days. */
	const OPTION_METADATA_DAYS = 'anpa_socios_email_metadata_retention_days';

	/** Option storing the last successful purge run (UTC). */
	const LAST_RUN_OPTION = 'anpa_socios_email_purge_last_run_utc';

	/** Rows processed per step and run, so a run can never lock the database. */
	const BATCH = 200;

	/**
	 * Effective payload window in days (option → clamped by the policy, filterable).
	 *
	 * @since  1.39.0
	 * @return int
	 */
	public static function payload_days(): int {
		$days = ANPA_Socios_Email_Retention::payload_days( get_option( self::OPTION_PAYLOAD_DAYS, ANPA_Socios_Email_Retention::PAYLOAD_DAYS_DEFAULT ) );

		return ANPA_Socios_Email_Retention::payload_days( apply_filters( 'anpa_socios_email_payload_retention_days', $days ) );
	}

	/**
	 * Effective metadata window in days. Never shorter than the payload window.
	 *
	 * @since  1.39.0
	 * @return int
	 */
	public static function metadata_days(): int {
		$days = ANPA_Socios_Email_Retention::metadata_days(
			get_option( self::OPTION_METADATA_DAYS, ANPA_Socios_Email_Retention::METADATA_DAYS_DEFAULT ),
			self::payload_days()
		);

		return ANPA_Socios_Email_Retention::metadata_days( apply_filters( 'anpa_socios_email_metadata_retention_days', $days ), self::payload_days() );
	}

	/**
	 * Runs the whole retention pass. Payload first, metadata second.
	 *
	 * @since  1.39.0
	 * @return array{ok:bool,code:string,payload_cleared:int,campaigns_deleted:int,recipients_deleted:int,attempts_deleted:int}
	 */
	public static function run(): array {
		$out = array(
			'ok'                 => false,
			'code'               => '',
			'payload_cleared'    => 0,
			'campaigns_deleted'  => 0,
			'recipients_deleted' => 0,
			'attempts_deleted'   => 0,
		);

		// Never purge while the schema is mid-migration or the site is installing.
		if ( ! ANPA_Socios_Email_Queue::can_run() ) {
			$out['code'] = 'blocked';
			return $out;
		}

		$now = time();

		$out['payload_cleared'] = self::purge_payloads(
			ANPA_Socios_Email_Retention::cutoff_utc( self::payload_days(), $now )
		);

		$deleted                     = self::purge_metadata(
			ANPA_Socios_Email_Retention::cutoff_utc( self::metadata_days(), $now )
		);
		$out['campaigns_deleted']    = $deleted['campaigns'];
		$out['recipients_deleted']   = $deleted['recipients'];
		$out['attempts_deleted']     = $deleted['attempts'];

		update_option( self::LAST_RUN_OPTION, ANPA_Socios_Email_Queue_Repo::now_utc(), false );

		$out['ok']   = true;
		$out['code'] = 'ok';

		return $out;
	}

	/**
	 * Step 1: clears the rendered payload and personal context of terminal
	 * campaigns older than the cutoff, keeping `payload_hash` so the content of a
	 * past send stays verifiable.
	 *
	 * `purge_after_utc` on the campaign, when set, overrides the global window:
	 * a caller can ask for a shorter life for a specific campaign.
	 *
	 * @since  1.39.0
	 * @param  string $cutoff_utc Cutoff datetime (UTC).
	 * @return int Rows cleared.
	 */
	public static function purge_payloads( string $cutoff_utc ): int {
		global $wpdb;

		$recipients = ANPA_Socios_DB::tabela_email_recipients();
		$campaigns  = ANPA_Socios_DB::tabela_email_campaigns();
		$limit      = self::BATCH;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table names internal; values bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$recipients} r
				    SET r.payload_snapshot = NULL,
				        r.subject_render = '',
				        r.last_error = '',
				        r.updated_at_utc = UTC_TIMESTAMP()
				  WHERE ( r.payload_snapshot IS NOT NULL OR r.subject_render <> '' )
				    AND r.campaign_id IN (
				        SELECT c.id FROM {$campaigns} c
				         WHERE c.state IN (%s, %s)
				           AND (
				                 ( c.purge_after_utc IS NOT NULL AND c.purge_after_utc <= UTC_TIMESTAMP() )
				              OR ( COALESCE(c.finished_at_utc, c.cancelled_at_utc) IS NOT NULL
				                   AND COALESCE(c.finished_at_utc, c.cancelled_at_utc) < %s )
				               )
				    )
				  LIMIT {$limit}",
				ANPA_Socios_Email_Campaign_State::FINISHED,
				ANPA_Socios_Email_Campaign_State::CANCELLED,
				$cutoff_utc
			)
		);

		return is_numeric( $done ) ? (int) $done : 0;
	}

	/**
	 * Step 2: deletes the minimal rows of terminal campaigns older than the long
	 * cutoff. Children go before parents, and a campaign with any non-terminal
	 * recipient is skipped even if its own state claims otherwise.
	 *
	 * @since  1.39.0
	 * @param  string $cutoff_utc Cutoff datetime (UTC).
	 * @return array{campaigns:int,recipients:int,attempts:int}
	 */
	public static function purge_metadata( string $cutoff_utc ): array {
		global $wpdb;

		$out = array( 'campaigns' => 0, 'recipients' => 0, 'attempts' => 0 );
		$ids = self::deletable_campaign_ids( $cutoff_utc );
		if ( array() === $ids ) {
			return $out;
		}

		$attempts   = ANPA_Socios_DB::tabela_email_attempts();
		$recipients = ANPA_Socios_DB::tabela_email_recipients();
		$campaigns  = ANPA_Socios_DB::tabela_email_campaigns();
		$in         = implode( ',', array_map( 'intval', $ids ) );

		// Children first: no orphan attempt or recipient may survive its campaign.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are cast to int above.
		$out['attempts']   = (int) $wpdb->query( "DELETE FROM {$attempts} WHERE campaign_id IN ({$in})" );
		$out['recipients'] = (int) $wpdb->query( "DELETE FROM {$recipients} WHERE campaign_id IN ({$in})" );
		$out['campaigns']  = (int) $wpdb->query( "DELETE FROM {$campaigns} WHERE id IN ({$in})" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $out;
	}

	/**
	 * Campaigns eligible for deletion: terminal, older than the cutoff, and with
	 * no recipient left in a non-terminal state.
	 *
	 * @since  1.39.0
	 * @param  string $cutoff_utc Cutoff datetime (UTC).
	 * @return int[]
	 */
	private static function deletable_campaign_ids( string $cutoff_utc ): array {
		global $wpdb;

		$campaigns  = ANPA_Socios_DB::tabela_email_campaigns();
		$recipients = ANPA_Socios_DB::tabela_email_recipients();
		$limit      = self::BATCH;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- read-only; values bound.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.id FROM {$campaigns} c
				  WHERE c.state IN (%s, %s)
				    AND COALESCE(c.finished_at_utc, c.cancelled_at_utc) IS NOT NULL
				    AND COALESCE(c.finished_at_utc, c.cancelled_at_utc) < %s
				    AND NOT EXISTS (
				        SELECT 1 FROM {$recipients} r
				         WHERE r.campaign_id = c.id
				           AND r.state NOT IN (%s, %s, %s)
				    )
				  ORDER BY c.id
				  LIMIT {$limit}",
				ANPA_Socios_Email_Campaign_State::FINISHED,
				ANPA_Socios_Email_Campaign_State::CANCELLED,
				$cutoff_utc,
				ANPA_Socios_Email_Recipient_State::ACCEPTED,
				ANPA_Socios_Email_Recipient_State::FAILED_PERMANENT,
				ANPA_Socios_Email_Recipient_State::CANCELLED
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
