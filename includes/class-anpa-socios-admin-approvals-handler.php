<?php
/**
 * Admin REST handler for the new-socio approval workflow.
 *
 * When the "require approval" option is enabled, new socios are parked in
 * estado='pendente_aprobacion' by the alta flow. This handler lets the master
 * list those pending socios and approve or reject them — one at a time or
 * several together. Approving activates the socio and emails a welcome notice;
 * rejecting parks the socio in 'baixa' (so they cannot log in, without losing
 * the data captured during the alta) and emails a decision notice.
 *
 * @since  1.23.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes and callbacks for the `/admin/approvals*` endpoints.
 *
 * @since 1.23.0
 */
final class ANPA_Socios_Admin_Approvals_Handler {

	const PENDING_ESTADO = 'pendente_aprobacion';

	/**
	 * Registers the approval admin routes.
	 *
	 * @since  1.23.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/approvals', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_pending' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/approvals/approve', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'approve' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
		register_rest_route( ANPA_Socios_Admin_REST::REST_NAMESPACE, '/approvals/reject', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reject' ),
			'permission_callback' => array( 'ANPA_Socios_Admin_Shared', 'permission_master' ),
		) );
	}

	/**
	 * GET /admin/approvals — list socios awaiting approval.
	 *
	 * @since  1.23.0
	 * @return WP_REST_Response
	 */
	public static function list_pending(): WP_REST_Response {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT email, nome, apelidos, telefono, nif, creado_en
				 FROM {$wpdb->prefix}anpa_socios
				 WHERE estado = %s
				 ORDER BY creado_en ASC",
				self::PENDING_ESTADO
			),
			ARRAY_A
		);

		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * POST /admin/approvals/approve — activate pending socios + welcome email.
	 *
	 * @since  1.23.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function approve( WP_REST_Request $request ) {
		return self::process( $request, 'approve' );
	}

	/**
	 * POST /admin/approvals/reject — reject pending socios + decision email.
	 *
	 * @since  1.23.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reject( WP_REST_Request $request ) {
		return self::process( $request, 'reject' );
	}

	/**
	 * Shared batch processor for approve/reject.
	 *
	 * Only rows currently in 'pendente_aprobacion' are touched (the WHERE
	 * clause guards against approving/rejecting an already-active socio via a
	 * stale request). Emails are best-effort and never fail the batch.
	 *
	 * @since  1.23.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @param  string          $mode    'approve' or 'reject'.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function process( WP_REST_Request $request, string $mode ) {
		global $wpdb;

		$body   = ANPA_Socios_Admin_Shared::json_body( $request );
		$emails = isset( $body['emails'] ) && is_array( $body['emails'] ) ? $body['emails'] : array();
		if ( empty( $emails ) ) {
			return new WP_Error( 'anpa_admin_invalid', 'Non se indicou ningún socio/a.', array( 'status' => 400 ) );
		}

		$table     = $wpdb->prefix . 'anpa_socios';
		$login_url = class_exists( 'ANPA_Socios_Admin_Settings' ) ? ANPA_Socios_Admin_Settings::landing_page_url() : '';
		$processed = 0;
		$skipped   = array();

		foreach ( $emails as $raw ) {
			$email = ANPA_Socios_Admin_Payload::sanitise_email( rawurldecode( (string) $raw ) );
			if ( null === $email ) {
				$skipped[] = (string) $raw;
				continue;
			}
			// Never touch the protected root/master account.
			if ( ANPA_Socios_Roles::is_protected_admin( $email, ANPA_Socios_Config::master_email() ) ) {
				$skipped[] = $email;
				continue;
			}

			$new_estado = ( 'approve' === $mode ) ? 'activo' : 'baixa';
			$updated    = $wpdb->update(
				$table,
				array( 'estado' => $new_estado, 'actualizado_en' => current_time( 'mysql' ) ),
				array( 'email' => $email, 'estado' => self::PENDING_ESTADO ),
				array( '%s', '%s' ),
				array( '%s', '%s' )
			);

			// 0 rows means the socio was not pending (already handled) — skip.
			if ( ! is_int( $updated ) || $updated <= 0 ) {
				$skipped[] = $email;
				continue;
			}

			++$processed;
			ANPA_Socios_Admin_Shared::write_audit( $request, 'socio', $email, 'approve' === $mode ? 'approval_approve' : 'approval_reject' );

			if ( 'approve' === $mode ) {
				ANPA_Socios_Email::enviar_aprobacion( $email, $login_url );
			} else {
				ANPA_Socios_Email::enviar_rexeitamento( $email );
			}
		}

		return new WP_REST_Response( array(
			'success'   => true,
			'processed' => $processed,
			'skipped'   => $skipped,
		), 200 );
	}
}
