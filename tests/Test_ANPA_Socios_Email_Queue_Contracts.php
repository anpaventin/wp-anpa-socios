<?php
/**
 * Inspection tests (support only) for the fase35 PR-35s3 queue wiring:
 * UTC discipline, atomic lease claiming, lease-ownership on result writes,
 * counter reconciliation, immutable payload freezing and the render contract.
 *
 * These verify CONTRACTS, not runtime behaviour — that is covered by the real
 * integration tests in Test_ANPA_Socios_Email_Queue_Integration.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Queue_Contracts extends TestCase {

	private string $repo;
	private string $queue;
	private string $render;
	private string $bootstrap;

	protected function setUp(): void {
		$root            = dirname( __DIR__ );
		$this->repo      = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-queue-repo.php' );
		$this->queue     = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-queue.php' );
		$this->render    = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-render-provider.php' );
		$this->bootstrap = (string) file_get_contents( $root . '/anpa-socios.php' );
	}

	public function test_classes_are_wired_in_bootstrap(): void {
		$this->assertStringContainsString( 'class-anpa-socios-email-render-provider.php', $this->bootstrap );
		$this->assertStringContainsString( 'class-anpa-socios-email-queue-repo.php', $this->bootstrap );
		$this->assertStringContainsString( 'class-anpa-socios-email-queue.php', $this->bootstrap );
	}

	// ── UTC discipline ──────────────────────────────────────────────────

	/**
	 * Strips comments so a doc line that merely NAMES a forbidden function is not
	 * mistaken for a real call.
	 *
	 * @param string $src Source.
	 * @return string
	 */
	private function code_only( string $src ): string {
		$src = preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src;   // block comments
		$src = preg_replace( '!^\s*//.*$!m', '', $src ) ?? $src;   // line comments
		return $src;
	}

	public function test_repo_uses_only_utc_time_sources(): void {
		// PHP-side writes use gmdate; SQL-side uses UTC_TIMESTAMP().
		$this->assertStringContainsString( "gmdate( 'Y-m-d H:i:s' )", $this->repo );
		$this->assertStringContainsString( 'UTC_TIMESTAMP()', $this->repo );

		// Never the session-local NOW() nor WordPress local time (code only).
		$code = $this->code_only( $this->repo );
		$code_without_utc = str_replace( 'UTC_TIMESTAMP()', '', $code );
		$this->assertStringNotContainsString( 'NOW()', $code_without_utc, 'session-local NOW() must never be used' );
		$this->assertStringNotContainsString( 'current_time(', $code );
		// Local date() is forbidden; gmdate() is not (lookbehind avoids matching it).
		$this->assertDoesNotMatchRegularExpression( "/(?<![A-Za-z0-9_])date\(/", $code, 'local date() must never be used' );
	}

	public function test_all_datetime_columns_referenced_are_utc_suffixed(): void {
		foreach ( array( 'created_at_utc', 'updated_at_utc', 'next_attempt_at_utc', 'last_attempt_at_utc', 'accepted_at_utc', 'locked_at_utc', 'locked_until_utc', 'started_at_utc' ) as $col ) {
			$this->assertStringContainsString( $col, $this->repo, "missing UTC column $col" );
		}
	}

	/**
	 * Every campaign column the repository writes must be declared by the 1.39.0
	 * schema. A missing column is silently swallowed by $wpdb->update() (it only
	 * logs a DB error), so state changes and counters would stop persisting.
	 */
	public function test_campaign_columns_written_by_the_repo_exist_in_the_schema(): void {
		$db  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php' );
		$pos = strpos( $db, 'CREATE TABLE {$campaigns} (' );
		$this->assertIsInt( $pos, 'campaigns CREATE TABLE not found' );
		$create = substr( $db, $pos, (int) strpos( $db, 'PRIMARY KEY', $pos ) - $pos );

		foreach ( array(
			'state',
			'total',
			'pending_count',
			'processed_count',
			'accepted_count',
			'failed_count',
			'cancelled_count',
			'created_at_utc',
			'updated_at_utc',
			'started_at_utc',
			'paused_at_utc',
			'finished_at_utc',
			'cancelled_at_utc',
			'scheduled_at_utc',
		) as $col ) {
			$this->assertMatchesRegularExpression( "/\b{$col}\s/", $create, "campaigns schema is missing $col" );
		}
	}

	// ── Atomic lease claiming ───────────────────────────────────────────

	public function test_claim_batch_is_a_conditional_update_with_a_lease_token(): void {
		$start = strpos( $this->repo, 'public static function claim_batch' );
		$end   = strpos( $this->repo, 'public static function mark_accepted', $start );
		$body  = substr( $this->repo, $start, $end - $start );

		// Claim by conditional UPDATE (not SELECT-then-UPDATE).
		$this->assertStringContainsString( 'UPDATE {$table} r', $body );
		// Single-table UPDATE: MySQL rejects LIMIT on a multi-table UPDATE, so the
		// campaign gate must be a subquery or the batch limit would be ignored.
		$this->assertStringNotContainsString( 'JOIN', $body, 'a multi-table UPDATE cannot honour LIMIT in MySQL' );
		$this->assertStringContainsString( 'r.campaign_id IN (', $body );
		$this->assertStringContainsString( 'r.lease_token = %s', $body );
		$this->assertStringContainsString( 'r.locked_until_utc = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)', $body );
		// Only claimable rows: retryable state, due, and free or expired lease.
		$this->assertStringContainsString( 'r.state IN (%s, %s)', $body );
		$this->assertStringContainsString( 'r.next_attempt_at_utc IS NULL OR r.next_attempt_at_utc <= UTC_TIMESTAMP()', $body );
		$this->assertStringContainsString( 'r.locked_until_utc IS NULL OR r.locked_until_utc < UTC_TIMESTAMP()', $body );
		// Only campaigns that are runnable and due.
		$this->assertStringContainsString( 'c.state IN (%s, %s)', $body );
		$this->assertStringContainsString( 'c.scheduled_at_utc IS NULL OR c.scheduled_at_utc <= UTC_TIMESTAMP()', $body );
		// The follow-up SELECT only reads rows this worker owns.
		$this->assertStringContainsString( 'WHERE lease_token = %s', $body );
		// The batch size is bounded by the pure planner.
		$this->assertStringContainsString( 'ANPA_Socios_Email_Batch_Planner::batch_size', $body );
	}

	public function test_result_writes_require_lease_ownership(): void {
		foreach ( array( 'mark_accepted', 'mark_failed' ) as $method ) {
			$start = strpos( $this->repo, 'public static function ' . $method );
			$this->assertIsInt( $start, "missing $method" );
			$body = substr( $this->repo, $start, 2200 );
			$this->assertStringContainsString( 'lease_token = %s', $body, "$method must check lease ownership" );
			$this->assertStringContainsString( 'AND state = %s', $body, "$method must require the processing state" );
		}
	}

	public function test_orphan_recovery_releases_expired_leases_only(): void {
		$start = strpos( $this->repo, 'public static function recover_orphans' );
		$end   = strpos( $this->repo, 'public static function get_recipient', $start );
		$body  = substr( $this->repo, $start, $end - $start );
		$this->assertStringContainsString( 'locked_until_utc < UTC_TIMESTAMP()', $body );
		$this->assertStringContainsString( 'state = %s', $body );
		// Documents the at-least-once reality of wp_mail().
		$this->assertStringContainsString( 'at-least-once', $this->repo );
	}

	// ── Deduplication / idempotency ─────────────────────────────────────

	public function test_enqueue_is_idempotent_at_both_levels(): void {
		// Campaign level: an existing idempotency key is adopted, not duplicated.
		$this->assertStringContainsString( 'find_campaign_by_key', $this->repo );
		$this->assertMatchesRegularExpression( "/'code'\s*=>\s*'exists'/", $this->repo );
		// Recipient level: INSERT IGNORE against UNIQUE(idempotency_key).
		$this->assertStringContainsString( 'INSERT IGNORE INTO', $this->repo );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Recipients::idempotency_key', $this->repo );
		$this->assertMatchesRegularExpression( "/'code'\s*=>\s*'duplicate'/", $this->repo );
		// Attempts: one row per (recipient, attempt_no).
		$start = strpos( $this->repo, 'public static function record_attempt' );
		$body  = substr( $this->repo, $start, 1600 );
		$this->assertStringContainsString( 'INSERT IGNORE INTO', $body );
	}

	public function test_queue_api_dedups_before_writing(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Email_Recipients::prepare', $this->queue );
		$this->assertStringContainsString( 'public static function enqueue_campaign', $this->queue );
		// Nothing is sent while enqueuing.
		$this->assertStringNotContainsString( 'wp_mail', $this->queue );
	}

	public function test_queue_refuses_to_run_during_install_or_pending_migration(): void {
		$start = strpos( $this->queue, 'public static function can_run' );
		$body  = substr( $this->queue, $start, 700 );
		$this->assertStringContainsString( 'wp_installing()', $body );
		$this->assertStringContainsString( 'version_compare( $installed, ANPA_Socios_DB::DB_VERSION', $body );
	}

	// ── Counters are a cache, not the truth ─────────────────────────────

	public function test_counters_are_recomputed_from_recipients(): void {
		$start = strpos( $this->repo, 'public static function recalc_counts' );
		$end   = strpos( $this->repo, 'public static function count_unfinished', $start );
		$body  = substr( $this->repo, $start, $end - $start );
		$this->assertStringContainsString( 'GROUP BY state', $body );
		// Recomputed (never incremented), so they cannot drift negative.
		$this->assertStringNotContainsString( 'count - 1', $body );
		$this->assertStringContainsString( '$wpdb->update(', $body );
	}

	public function test_completion_is_decided_by_real_state_not_counters(): void {
		$start = strpos( $this->repo, 'public static function count_unfinished' );
		$body  = substr( $this->repo, $start, 900 );
		$this->assertStringContainsString( 'SELECT COUNT(*)', $body );
		$this->assertStringContainsString( 'state NOT IN (%s, %s, %s)', $body );
	}

	public function test_cancel_and_retry_never_touch_accepted_rows(): void {
		foreach ( array( 'cancel_pending_recipients', 'retry_failed' ) as $method ) {
			$start = strpos( $this->repo, 'public static function ' . $method );
			$body  = substr( $this->repo, $start, 1400 );
			$this->assertStringContainsString( 'state IN (%s, %s)', $body );
			$this->assertStringNotContainsString( 'ACCEPTED', $body, "$method must not target accepted rows" );
		}
	}

	// ── Immutable payload + render contract ─────────────────────────────

	public function test_render_contract_is_stable_and_queue_agnostic(): void {
		$this->assertStringContainsString( 'interface ANPA_Socios_Email_Render_Provider_Interface', $this->render );
		$this->assertStringContainsString( "apply_filters( 'anpa_socios_email_render_provider'", $this->render );
		// The queue must not know the template syntax (no mustache handling here).
		$this->assertStringNotContainsString( '{{', $this->render );
	}

	public function test_payload_is_frozen_with_a_hash_at_enqueue_time(): void {
		$this->assertStringContainsString( 'public static function freeze', $this->render );
		$this->assertStringContainsString( "hash( 'sha256', \$json )", $this->render );
		$this->assertStringContainsString( 'const PAYLOAD_VERSION', $this->render );
		// The queue freezes per recipient before inserting.
		$this->assertStringContainsString( 'ANPA_Socios_Email_Render::freeze', $this->queue );
		$this->assertStringContainsString( "'payload_hash'", $this->queue );
	}

	public function test_errors_are_redacted_and_bounded(): void {
		$start = strpos( $this->repo, 'private static function redact_error' );
		$body  = substr( $this->repo, $start, 500 );
		$this->assertStringContainsString( 'mb_substr( $error, 0, 255 )', $body );
		$this->assertStringContainsString( "preg_replace( '/\\s+/', ' '", $body );
	}
}
