<?php
/**
 * REAL integration tests for the fase35 communications screen (PR-35s5).
 *
 * Renders the actual admin markup against a real database and asserts what an
 * operator would read: campaign rows with reconciled counters, per-recipient
 * states in words, the attempt history, the duplicate warning when a send is
 * uncertain, and the stalled-cron notice. Also proves the screen itself never
 * mutates anything.
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Comunicacions_Page_Integration extends TestCase {

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

		foreach ( array(
			ANPA_Socios_DB::tabela_email_attempts(),
			ANPA_Socios_DB::tabela_email_recipients(),
			ANPA_Socios_DB::tabela_email_campaigns(),
		) as $t ) {
			$wpdb->query( "DELETE FROM `{$t}`" );
		}

		// An administrator is required: the screen refuses to render otherwise.
		wp_set_current_user( self::admin_id() );
		$_GET = array();
	}

	protected function tearDown(): void {
		if ( null !== $this->transport ) {
			remove_filter( 'pre_wp_mail', $this->transport, 10 );
			$this->transport = null;
		}
		$_GET = array();
		wp_set_current_user( 0 );
	}

	/**
	 * @return int Administrator user id.
	 */
	private static function admin_id(): int {
		$existing = get_user_by( 'login', 'anpa_it_admin' );
		if ( $existing instanceof WP_User ) {
			return (int) $existing->ID;
		}
		$id = wp_insert_user(
			array(
				'user_login' => 'anpa_it_admin',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'admin@example.org',
				'role'       => 'administrator',
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * @param bool $accept Whether the transport accepts the message.
	 */
	private function transport( bool $accept ): void {
		$this->transport = static function () use ( $accept ) {
			if ( $accept ) {
				return true;
			}
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP unavailable' ) );
			return false;
		};
		add_filter( 'pre_wp_mail', $this->transport, 10, 2 );
	}

	/**
	 * @return array<string,mixed> Enqueue result.
	 */
	private function enqueue( int $n = 2, string $key = 'ui-1' ): array {
		$rs = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$rs[] = array( 'email' => "u{$i}@example.com", 'recipient_type' => 'member', 'message_key' => "m{$i}" );
		}

		return ANPA_Socios_Email_Queue::enqueue_campaign(
			array(
				'event_type'      => 'test_event',
				'idempotency_key' => $key,
				'recipients'      => $rs,
				'context'         => array( 'subject' => 'Asunto', 'body_html' => '<p>Ola</p>' ),
				'created_by'      => 'sistema',
			)
		);
	}

	/**
	 * @return string Rendered markup.
	 */
	private function render(): string {
		ob_start();
		ANPA_Socios_Comunicacions_Page::render_page();

		return (string) ob_get_clean();
	}

	// ── List view ───────────────────────────────────────────────────────

	public function test_the_empty_state_is_explicit(): void {
		$html = $this->render();
		$this->assertStringContainsString( 'Aínda non hai campañas de correo.', $html );
	}

	public function test_the_list_shows_reconciled_counters_and_a_state_in_words(): void {
		$this->transport( true );
		$res = $this->enqueue( 3, 'ui-list' );
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'], 'limit' => 1 ) );

		$html = $this->render();

		$this->assertStringContainsString( 'test_event', $html );
		$this->assertStringContainsString( 'En curso', $html, 'the campaign state is rendered as text' );
		$this->assertStringContainsString( 'anpa-comms-state--running', $html );
		// One accepted, two still pending: the counters come from the real rows.
		$campaign = ANPA_Socios_Email_Queue_Repo::get_campaign( $res['campaign_id'] );
		$this->assertSame( 1, (int) $campaign['accepted_count'] );
		$this->assertSame( 2, (int) $campaign['pending_count'] );
		$this->assertStringContainsString( 'Aceptados', $html );
		$this->assertStringNotContainsString( 'Entregados', $html );
	}

	public function test_the_list_offers_only_the_actions_the_state_allows(): void {
		$res = $this->enqueue( 1, 'ui-actions' );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$running = $this->render();
		$this->assertStringContainsString( ANPA_Socios_Email_Admin_Actions::ACTION_PAUSE, $running );
		$this->assertStringNotContainsString( ANPA_Socios_Email_Admin_Actions::ACTION_RESUME, $running );

		ANPA_Socios_Email_Queue::pause( $res['campaign_id'] );
		$paused = $this->render();
		$this->assertStringContainsString( ANPA_Socios_Email_Admin_Actions::ACTION_RESUME, $paused );
		$this->assertStringNotContainsString( ANPA_Socios_Email_Admin_Actions::ACTION_PAUSE, $paused );

		ANPA_Socios_Email_Queue::cancel( $res['campaign_id'] );
		$cancelled = $this->render();
		$this->assertStringContainsString( 'Campaña pechada.', $cancelled );
		$this->assertStringNotContainsString( ANPA_Socios_Email_Admin_Actions::ACTION_CANCEL, $cancelled );
	}

	public function test_every_action_form_carries_a_nonce(): void {
		$res = $this->enqueue( 1, 'ui-nonce' );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		$html = $this->render();

		$forms  = substr_count( $html, '<form method="post"' );
		$nonces = substr_count( $html, 'name="_wpnonce"' );
		$this->assertGreaterThan( 0, $forms );
		$this->assertSame( $forms, $nonces, 'every write form must carry a nonce' );
		$this->assertSame( $forms, substr_count( $html, 'admin-post.php' ) );
	}

	// ── Detail view ─────────────────────────────────────────────────────

	public function test_the_detail_lists_recipients_states_and_attempts(): void {
		$this->transport( false );
		$res = $this->enqueue( 1, 'ui-detail' );
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$_GET['campaign_id'] = (string) $res['campaign_id'];
		$html                = $this->render();

		$this->assertStringContainsString( 'u1@example.com', $html );
		$this->assertStringContainsString( 'Fallo (reintentarase)', $html, 'recipient state in words' );
		$this->assertStringContainsString( 'SMTP unavailable', $html, 'the stored reason is shown' );
		// Attempt history with its result label.
		$this->assertStringContainsString( '#1 fallo', $html );
		$this->assertStringContainsString( 'Volver ás campañas', $html );
	}

	public function test_the_detail_warns_about_a_possible_duplicate(): void {
		global $wpdb;
		$this->transport( true );
		$res = $this->enqueue( 1, 'ui-uncertain' );
		ANPA_Socios_Email_Queue_Repo::set_campaign_state( $res['campaign_id'], ANPA_Socios_Email_Campaign_State::RUNNING );

		// Interrupted worker: claimed, lease expired, recovered as uncertain.
		$claim = ANPA_Socios_Email_Queue_Repo::claim_batch( $res['campaign_id'], 1 );
		$t     = ANPA_Socios_DB::tabela_email_recipients();
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET locked_until_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = %d", (int) $claim['rows'][0]['id'] ) );
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$_GET['campaign_id'] = (string) $res['campaign_id'];
		$html                = $this->render();

		$this->assertStringContainsString( 'pode ter recibido a mensaxe dúas veces', $html );
		$this->assertStringContainsString( 'incerto', $html );
	}

	public function test_an_unknown_campaign_falls_back_to_the_list(): void {
		$this->enqueue( 1, 'ui-fallback' );
		$_GET['campaign_id'] = '999999';

		$html = $this->render();

		$this->assertStringContainsString( 'test_event', $html );
		$this->assertStringNotContainsString( 'Volver ás campañas', $html );
	}

	// ── Cron notice ─────────────────────────────────────────────────────

	public function test_a_stalled_queue_is_reported_with_a_manual_run(): void {
		update_option( ANPA_Socios_Email_Processor::LAST_RUN_OPTION, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$html = $this->render();

		$this->assertStringContainsString( 'A cola de correo pode estar detida', $html );
		$this->assertStringContainsString( 'wp-cron.php', $html );
		$this->assertStringContainsString( ANPA_Socios_Email_Admin_Actions::ACTION_PROCESS, $html );

		$health = ANPA_Socios_Email_Cron::health();
		$this->assertFalse( $health['ok'] );
		$this->assertContains( 'stalled', $health['problems'] );
	}

	// ── The screen is read-only ─────────────────────────────────────────

	public function test_rendering_changes_no_data(): void {
		$this->transport( true );
		$res = $this->enqueue( 2, 'ui-readonly' );
		ANPA_Socios_Email_Processor::run( array( 'campaign_id' => $res['campaign_id'] ) );

		$before = ANPA_Socios_Email_Queue_Repo::get_campaign( $res['campaign_id'] );
		$rows   = ANPA_Socios_Email_Queue_Repo::list_recipients( $res['campaign_id'] );

		$this->render();
		$_GET['campaign_id'] = (string) $res['campaign_id'];
		$this->render();

		$this->assertSame( $before, ANPA_Socios_Email_Queue_Repo::get_campaign( $res['campaign_id'] ) );
		$this->assertSame( $rows, ANPA_Socios_Email_Queue_Repo::list_recipients( $res['campaign_id'] ) );
	}
}
