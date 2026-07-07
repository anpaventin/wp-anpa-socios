<?php
/**
 * Shared helpers for the admin REST surface.
 *
 * Centralises the master permission check, audit logging, and
 * session/role lookups so each domain handler can stay small and
 * focused on its endpoints.
 *
 * @since  1.3.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers used by every admin handler.
 *
 * @since 1.3.0
 */
final class ANPA_Socios_Admin_Shared {

	/**
	 * Master-only request key.
	 *
	 * Set on $request when the caller passes the master permission
	 * gate. Handlers should treat missing values as 401.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const REQ_PARAM_EMAIL = '_anpa_admin_email';

	/**
	 * Master-only request key carrying the caller's rol.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const REQ_PARAM_ROL = '_anpa_admin_rol';

	/**
	 * Shared master gate: session + master role + CSRF.
	 *
	 * @since  1.21.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @param  bool            $require_admin_password Whether to enforce the admin-password transient gate.
	 * @return true|WP_Error
	 */
	private static function permission_master_core( WP_REST_Request $request, bool $require_admin_password ) {
		$auth = ANPA_Socios_Area_REST::authenticate_area_session( $request, 'anpa_admin_rl_' );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$email = strtolower( trim( (string) $auth['profile']['email'] ) );
		$rol   = self::resolve_socio_rol( $email );
		if ( ! ANPA_Socios_Roles::es_master( $email, $rol, ANPA_Socios_Roles::MASTER_EMAIL ) ) {
			return new WP_Error( 'anpa_admin_forbidden', 'Acción non permitida', array( 'status' => 403 ) );
		}

		// Double-submit CSRF: for state-changing methods, verify the
		// _csrf query param matches the header token.
		$method = strtoupper( $request->get_method() );
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			$header_token = (string) $request->get_header( 'X-Anpa-Area-Token' );
			$csrf_param   = (string) $request->get_param( '_csrf' );
			if ( '' === $header_token || '' === $csrf_param || $header_token !== $csrf_param ) {
				return new WP_Error( 'anpa_csrf_invalid', 'Solicitude rexeitada (CSRF)', array( 'status' => 403 ) );
			}
		}

		$request->set_param( self::REQ_PARAM_EMAIL, $email );
		$request->set_param( self::REQ_PARAM_ROL, $rol );

		if ( $require_admin_password && ANPA_Socios_Master_Auth::admin_password_exists() ) {
			// Resolve the token with the SAME resolver used by handle_admin_auth
			// when marking the session authorized (sanitized + underscore-header
			// fallback), otherwise the mark and the check can diverge under proxy
			// header transforms and loop "password required".
			$header_token = ANPA_Socios_Area_REST::get_session_token( $request, 'X-Anpa-Area-Token' );
			if ( '' === $header_token || ! ANPA_Socios_Master_Auth::is_admin_session_authorized( $header_token ) ) {
				return new WP_Error( 'anpa_admin_auth_required', 'Contrasinal de administración necesario. Usa /area/me/admin-auth.', array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Checks that the caller's area session belongs to a master.
	 *
	 * Delegates session validation to the canonical authenticator
	 * (`ANPA_Socios_Area_REST::authenticate_area_session`), so the admin
	 * surface inherits the SAME guarantees as the member area: rate limit,
	 * HMAC digest, User-Agent binding, active socio state, TTL/usage cap,
	 * and atomic usage increment. On top of that it requires the caller to
	 * be the master (rol='master' AND the master email).
	 *
	 * On success, stashes the email and rol on the request object so
	 * downstream handlers can reuse them without re-querying the DB.
	 *
	 * @since  1.3.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public static function permission_master( WP_REST_Request $request ) {
		return self::permission_master_core( $request, true );
	}

	/**
	 * Master-only permission without the admin-password gate.
	 *
	 * Used exclusively for the one-time /area/master/init endpoint, where
	 * the admin password does not exist yet. It enforces the same session,
	 * CSRF and master checks as permission_master() but skips the transient
	 * password verification.
	 *
	 * @since  1.21.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public static function permission_master_init( WP_REST_Request $request ) {
		return self::permission_master_core( $request, false );
	}

	/**
	 * Writes a canonical row to the audit log.
	 *
	 * Resolves actor email/rol from the request object and delegates
	 * to write_audit_actor.
	 *
	 * @since  1.3.0
	 * @param  WP_REST_Request $request    The incoming request.
	 * @param  string          $target_tipo Target type (socio/fillo/empresa/...).
	 * @param  string          $target_id   Target id.
	 * @param  string          $accion      Verb (create/update/delete/list).
	 * @return void
	 */
	public static function write_audit( WP_REST_Request $request, string $target_tipo, string $target_id, string $accion ): void {
		self::write_audit_actor(
			(string) $request->get_param( self::REQ_PARAM_EMAIL ),
			(string) $request->get_param( self::REQ_PARAM_ROL ),
			$target_tipo,
			$target_id,
			$accion
		);
	}

	/**
	 * Writes a canonical audit row with explicit actor identity.
	 *
	 * Use this when the caller is not an admin request (e.g. empresa
	 * export) and the actor email/tipo must be supplied directly.
	 *
	 * @since  1.5.0
	 * @param  string $actor_email Actor's email address.
	 * @param  string $actor_tipo  Actor type (master/empresa/system).
	 * @param  string $target_tipo Target type (export/socio/fillo/...).
	 * @param  string $target_id   Target identifier.
	 * @param  string $accion      Action verb (export_alumnos_empresa/...).
	 * @return void
	 */
	public static function write_audit_actor( string $actor_email, string $actor_tipo, string $target_tipo, string $target_id, string $accion ): void {
		global $wpdb;

		$row = ANPA_Socios_Admin_Payload::audit_row(
			$actor_email,
			$actor_tipo,
			$target_tipo,
			$target_id,
			$accion
		);

		$wpdb->insert(
			$wpdb->prefix . 'anpa_audit_log',
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Decodes a JSON body safely.
	 *
	 * @since  1.3.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return array<string,mixed>
	 */
	public static function json_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Returns the stored rol for the given email, or '' if not found.
	 *
	 * @since  1.3.0
	 * @param  string $email Socio email.
	 * @return string
	 */
	private static function resolve_socio_rol( string $email ): string {
		global $wpdb;

		$rol = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rol FROM {$wpdb->prefix}anpa_socios WHERE email = %s AND estado = 'activo'",
				$email
			)
		);

		return is_string( $rol ) ? $rol : '';
	}
}
