<?php
/**
 * Inspection tests for the fase35 PR-35s5 admin surface: the communications
 * screen, the stalled-cron notice and the communications-retention option.
 *
 * The screen is an audit surface, so these tests police the things a reviewer
 * would otherwise have to re-check by hand: it never writes, it never claims
 * "delivered", every state carries text, and the retention option is isolated
 * and scoped to communications only.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Communications_Ui extends TestCase {

	use ANPA_Socios_Inspection_Helpers;

	private string $page;
	private string $settings;
	private string $cron;
	private string $uninstall;
	private string $bootstrap;

	protected function setUp(): void {
		$root            = dirname( __DIR__ );
		$this->page      = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-communications-page.php' );
		$this->settings  = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-settings.php' );
		$this->cron      = (string) file_get_contents( $root . '/includes/class-anpa-socios-email-cron.php' );
		$this->uninstall = (string) file_get_contents( $root . '/uninstall.php' );
		$this->bootstrap = (string) file_get_contents( $root . '/anpa-socios.php' );
	}

	public function test_the_screen_is_registered_under_the_plugin_menu(): void {
		$this->assertStringContainsString( 'class-anpa-socios-email-communications-page.php', $this->bootstrap );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Communications_Page::register_menu( self::OVERVIEW_SLUG, self::CAP )', $this->settings );
		$this->assertStringContainsString( 'add_submenu_page(', $this->page );
	}

	// ── The screen reads; it never writes ───────────────────────────────

	public function test_the_screen_never_writes_or_sends(): void {
		// Comments excluded: the docblock legitimately NAMES wp_mail to explain why
		// "accepted" is not "delivered".
		$code = preg_replace( '!/\*.*?\*/!s', '', $this->page ) ?? $this->page;
		$code = preg_replace( '!^\s*//.*$!m', '', $code ) ?? $code;

		foreach ( array( '$wpdb->', 'update_option(', 'delete_option(', 'wp_mail', 'set_campaign_state', 'claim_batch' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $code, "the screen must not call $forbidden" );
		}
		// Writes go through the nonce-checked admin-post handlers instead.
		$this->assertStringContainsString( 'admin-post.php', $this->page );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Admin_Actions::form_fields', $this->page );
	}

	public function test_the_screen_checks_the_capability_before_rendering(): void {
		$start = $this->marker( $this->page, 'public static function render_page' );
		$body  = substr( $this->page, $start, 600 );
		$this->assertStringContainsString( "current_user_can( self::CAP )", $body );
		$this->assertStringContainsString( 'wp_die(', $body );
		$this->assertStringContainsString( "const CAP = 'manage_options'", $this->page );
	}

	public function test_request_input_is_sanitized_and_output_escaped(): void {
		$this->assertStringContainsString( "absint( wp_unslash( \$_GET['campaign_id'] ) )", $this->page );
		$this->assertStringContainsString( "sanitize_key( wp_unslash( \$_GET['anpa_msg'] ) )", $this->page );
		// No raw echo of request data: every dynamic value goes through an escaper.
		$this->assertSame(
			0,
			preg_match( '/echo\s+\$_(GET|POST|REQUEST)/', $this->page ),
			'request data must never be echoed directly'
		);
		foreach ( array( 'esc_html(', 'esc_attr(', 'esc_url(' ) as $escaper ) {
			$this->assertStringContainsString( $escaper, $this->page );
		}
	}

	// ── Terminology and accessibility ───────────────────────────────────

	public function test_accepted_is_never_presented_as_delivered(): void {
		$this->assertStringContainsString( 'Aceptado', $this->page );
		// "entregado/entrega" only appears where it is explicitly DENIED.
		$this->assertStringNotContainsString( 'Entregado', $this->page );
		$this->assertStringContainsString( 'non que chegase', $this->page );
	}

	public function test_the_possible_duplicate_is_surfaced_not_hidden(): void {
		$this->assertStringContainsString( 'has_uncertain_attempts', $this->page );
		$this->assertStringContainsString( 'pode ter recibido a mensaxe dúas veces', $this->page );
		$this->assertStringContainsString( 'incerto', $this->page );
	}

	public function test_states_are_conveyed_with_text_not_colour_alone(): void {
		$start = $this->marker( $this->page, 'private static function state_cell' );
		$body  = substr( $this->page, $start, 500 );
		// The label is always rendered inside the element; the class is only a hook.
		$this->assertStringContainsString( 'esc_html( $label )', $body );

		foreach ( array( 'campaign_state_label', 'recipient_state_label' ) as $method ) {
			$this->assertStringContainsString( 'public static function ' . $method, $this->page );
		}
		// Every state of both machines has a human label. The label maps are read
		// from the source because the class itself needs WordPress loaded.
		$campaign_map  = substr( $this->page, (int) strpos( $this->page, 'public static function campaign_state_label' ), 900 );
		$recipient_map = substr( $this->page, (int) strpos( $this->page, 'public static function recipient_state_label' ), 1200 );

		foreach ( ANPA_Socios_Email_Campaign_State::all() as $state ) {
			$this->assertStringContainsString(
				'ANPA_Socios_Email_Campaign_State::' . strtoupper( $state ),
				$campaign_map,
				"campaign state $state has no label"
			);
		}
		foreach ( ANPA_Socios_Email_Recipient_State::all() as $state ) {
			$this->assertStringContainsString(
				'ANPA_Socios_Email_Recipient_State::' . strtoupper( $state ),
				$recipient_map,
				"recipient state $state has no label"
			);
		}
	}

	public function test_only_legal_actions_are_offered_for_the_current_state(): void {
		$start = $this->marker( $this->page, 'private static function render_campaign_actions' );
		$body  = substr( $this->page, $start, 2000 );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Campaign_State::terminal(', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Campaign_State::PAUSED === $state', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Campaign_State::RUNNING === $state', $body );
	}

	// ── Stalled cron notice ─────────────────────────────────────────────

	public function test_cron_health_distinguishes_the_three_real_problems(): void {
		$start = $this->marker( $this->cron, 'public static function health' );
		$body  = substr( $this->cron, $start, 1600 );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $body );
		$this->assertStringContainsString( 'wp_next_scheduled( self::HOOK )', $body );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Processor::LAST_RUN_OPTION', $body );
		foreach ( array( 'disabled', 'unscheduled', 'stalled' ) as $problem ) {
			$this->assertStringContainsString( "'" . $problem . "'", $body );
		}
	}

	public function test_the_notice_explains_the_real_server_cron_and_offers_a_manual_run(): void {
		$start = $this->marker( $this->page, 'private static function render_cron_notice' );
		$body  = substr( $this->page, $start, 2600 );
		$this->assertStringContainsString( 'ANPA_Socios_Email_Cron::health()', $body );
		$this->assertStringContainsString( 'wp-cron.php', $body, 'the operator must be told what to configure' );
		$this->assertStringContainsString( 'ACTION_PROCESS', $body );
	}

	// ── Communications retention option ─────────────────────────────────

	public function test_the_option_is_scoped_to_communications_and_named_accordingly(): void {
		$this->assertSame(
			'anpa_socios_delete_comms_on_uninstall',
			ANPA_Socios_DB::OPTION_DELETE_COMMS_ON_UNINSTALL
		);
		// uninstall.php repeats the literal (plugin classes are not loaded there),
		// so the two must stay in sync.
		$this->assertStringContainsString(
			"get_option( '" . ANPA_Socios_DB::OPTION_DELETE_COMMS_ON_UNINSTALL . "'",
			$this->uninstall
		);
		// It must NOT be presented as deleting all data.
		$this->assertStringContainsString( 'Eliminar o rexistro de comunicacións ao desinstalar', $this->settings );
		$this->assertStringNotContainsString( 'Eliminar todos os datos', $this->settings );
	}

	public function test_saving_the_option_is_isolated_and_nonce_checked(): void {
		$start = $this->marker( $this->settings, 'public static function handle_save_comms_retention' );
		$body = substr( $this->settings, $start, 900 );

		$this->assertStringContainsString( "self::guard( 'anpa_socios_save_comms_retention' )", $body );
		// Exactly ONE option written, so a partial form cannot clear other settings.
		$this->assertSame( 1, substr_count( $body, 'update_option(' ) );
		$this->assertStringContainsString( 'ANPA_Socios_DB::OPTION_DELETE_COMMS_ON_UNINSTALL', $body );
		// Normalised to "1"/"0" because uninstall.php requires exactly "1".
		$this->assertStringContainsString( "? '1' : '0'", $body );

		$this->assertStringContainsString( "add_action( 'admin_post_anpa_socios_save_comms_retention'", $this->settings );
		$this->assertStringContainsString( "wp_nonce_field( 'anpa_socios_save_comms_retention' )", $this->settings );
	}

	public function test_the_option_form_states_the_current_effect_in_words(): void {
		$start = $this->marker( $this->settings, 'private static function render_subsection_comunicacions' );
		$body  = substr( $this->settings, $start, 2600 );
		$this->assertStringContainsString( 'Estado actual: o rexistro BORRARASE ao desinstalar.', $body );
		$this->assertStringContainsString( 'Estado actual: o rexistro CONSÉRVASE ao desinstalar.', $body );
		// And makes the narrow scope explicit.
		$this->assertStringContainsString( 'NON afecta a socios/as', $body );
	}
}
