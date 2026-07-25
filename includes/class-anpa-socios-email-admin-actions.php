<?php
/**
 * Admin actions for the email queue (fase35, PR-35s4).
 *
 * Every action is a POST to admin-post.php guarded by BOTH a capability check
 * (manage_options) and a per-action nonce, and always redirects back with a
 * result code instead of rendering. The admin screen itself lands in PR-35s5;
 * these handlers are the write side and are usable from any campaign list.
 *
 * Nothing here decides queue semantics: each handler validates the request and
 * delegates to ANPA_Socios_Email_Queue.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Admin_Actions {

	/** Capability required by every action. */
	const CAP = 'manage_options';

	/** Action names (also the nonce action names). */
	const ACTION_PROCESS = 'anpa_socios_email_process_now';
	const ACTION_PAUSE   = 'anpa_socios_email_pause';
	const ACTION_RESUME  = 'anpa_socios_email_resume';
	const ACTION_CANCEL  = 'anpa_socios_email_cancel';
	const ACTION_RETRY   = 'anpa_socios_email_retry_failed';

	/**
	 * Registers the admin-post handlers. Only for logged-in admins: no `nopriv`
	 * variant is registered on purpose.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_PROCESS, array( __CLASS__, 'handle_process_now' ) );
		add_action( 'admin_post_' . self::ACTION_PAUSE, array( __CLASS__, 'handle_pause' ) );
		add_action( 'admin_post_' . self::ACTION_RESUME, array( __CLASS__, 'handle_resume' ) );
		add_action( 'admin_post_' . self::ACTION_CANCEL, array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::ACTION_RETRY, array( __CLASS__, 'handle_retry_failed' ) );
	}

	/**
	 * Processes one bounded batch on demand (useful when the server cron is
	 * stopped or delayed). Bounded by the same batch size and time budget as the
	 * scheduled run.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function handle_process_now(): void {
		$campaign_id = self::authorize( self::ACTION_PROCESS, false );
		$result      = ANPA_Socios_Email_Queue::process_due_batch( $campaign_id );

		self::audit( self::ACTION_PROCESS, $campaign_id, (string) $result['code'] );
		self::redirect_back(
			$campaign_id,
			array(
				'anpa_msg'      => 'processed',
				'anpa_code'     => (string) $result['code'],
				'anpa_accepted' => (int) $result['accepted'],
				'anpa_failed'   => (int) $result['failed'],
			)
		);
	}

	/**
	 * Pauses a campaign.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function handle_pause(): void {
		$campaign_id = self::authorize( self::ACTION_PAUSE );
		$result      = ANPA_Socios_Email_Queue::pause( $campaign_id );

		self::audit( self::ACTION_PAUSE, $campaign_id, (string) $result['code'] );
		self::redirect_back( $campaign_id, array( 'anpa_msg' => 'paused', 'anpa_code' => (string) $result['code'] ) );
	}

	/**
	 * Resumes a paused campaign.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function handle_resume(): void {
		$campaign_id = self::authorize( self::ACTION_RESUME );
		$result      = ANPA_Socios_Email_Queue::resume( $campaign_id );

		self::audit( self::ACTION_RESUME, $campaign_id, (string) $result['code'] );
		self::redirect_back( $campaign_id, array( 'anpa_msg' => 'resumed', 'anpa_code' => (string) $result['code'] ) );
	}

	/**
	 * Cancels a campaign (pending recipients only; accepted ones stay accepted).
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function handle_cancel(): void {
		$campaign_id = self::authorize( self::ACTION_CANCEL );
		$result      = ANPA_Socios_Email_Queue::cancel( $campaign_id );

		self::audit( self::ACTION_CANCEL, $campaign_id, (string) $result['code'] . ':' . (int) $result['cancelled'] );
		self::redirect_back(
			$campaign_id,
			array(
				'anpa_msg'       => 'cancelled',
				'anpa_code'      => (string) $result['code'],
				'anpa_cancelled' => (int) $result['cancelled'],
			)
		);
	}

	/**
	 * Requeues the failed recipients of a campaign.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function handle_retry_failed(): void {
		$campaign_id = self::authorize( self::ACTION_RETRY );
		$result      = ANPA_Socios_Email_Queue::retry_failed( $campaign_id );

		self::audit( self::ACTION_RETRY, $campaign_id, (string) $result['code'] . ':' . (int) $result['requeued'] );
		self::redirect_back(
			$campaign_id,
			array(
				'anpa_msg'      => 'retried',
				'anpa_code'     => (string) $result['code'],
				'anpa_requeued' => (int) $result['requeued'],
			)
		);
	}

	/**
	 * Capability + nonce check, then the campaign id. Dies on failure (never falls
	 * through to the queue).
	 *
	 * @since  1.39.0
	 * @param  string $action   Action/nonce name.
	 * @param  bool   $required Whether a campaign id is mandatory.
	 * @return int Campaign id (0 when optional and absent).
	 */
	private static function authorize( string $action, bool $required = true ): int {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non tes permisos para esta acción.', 'anpa-socios' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0;
		if ( $required && $campaign_id <= 0 ) {
			wp_die( esc_html__( 'Falta a campaña.', 'anpa-socios' ), '', array( 'response' => 400 ) );
		}

		return $campaign_id;
	}

	/**
	 * Renders the hidden fields (action + nonce + campaign) for a form.
	 *
	 * @since  1.39.0
	 * @param  string $action      Action name.
	 * @param  int    $campaign_id Campaign id.
	 * @return void
	 */
	public static function form_fields( string $action, int $campaign_id = 0 ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		if ( $campaign_id > 0 ) {
			echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $campaign_id ) . '" />';
		}
		wp_nonce_field( $action );
	}

	/**
	 * Writes an audit line. Records WHO did WHAT to WHICH campaign and the outcome
	 * code — never recipient addresses, payloads or tokens.
	 *
	 * @since  1.39.0
	 * @param  string $action      Action name.
	 * @param  int    $campaign_id Campaign id.
	 * @param  string $outcome     Result code.
	 * @return void
	 */
	private static function audit( string $action, int $campaign_id, string $outcome ): void {
		$actor = wp_get_current_user();
		do_action(
			'anpa_socios_email_admin_action',
			array(
				'action'      => $action,
				'campaign_id' => $campaign_id,
				'outcome'     => $outcome,
				'actor'       => $actor instanceof WP_User ? $actor->user_email : '',
				'at_utc'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Redirects back to the referring admin screen with result parameters.
	 *
	 * @since  1.39.0
	 * @param  int                 $campaign_id Campaign id.
	 * @param  array<string,mixed> $args        Query args to add.
	 * @return void
	 */
	private static function redirect_back( int $campaign_id, array $args ): void {
		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=anpa-socios-comunicacions' );
		}
		if ( $campaign_id > 0 ) {
			$args['campaign_id'] = $campaign_id;
		}
		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}
}
