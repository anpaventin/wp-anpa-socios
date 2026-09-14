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
	 * Admin gate — delegates to the WordPress-native authorization (fase17 R6a).
	 *
	 * Historically this validated a front-end area session + master role +
	 * admin password. As of fase17 the sole gate is
	 * `current_user_can('manage_options')` + WP REST nonce, implemented in
	 * `ANPA_Socios_Admin_Auth`. This method is kept as a compatibility bridge
	 * so the 14 admin handlers keep referencing `permission_master` while the
	 * migration completes; it will be inlined/removed in a later step.
	 *
	 * On success the WP user email is stashed on the request (audit identity)
	 * by the delegated gate; here we tag the audit rol as `admin_wp`.
	 *
	 * @since  1.3.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public static function permission_master( WP_REST_Request $request ) {
		return self::bridge_admin( $request );
	}

	/**
	 * Admin gate for the (soon-removed) one-time init endpoint.
	 *
	 * Behaves identically to `permission_master()` now that the admin-password
	 * distinction no longer exists.
	 *
	 * @since  1.21.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public static function permission_master_init( WP_REST_Request $request ) {
		return self::bridge_admin( $request );
	}

	/**
	 * Shared bridge to the WordPress-native admin gate.
	 *
	 * @since  1.31.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	private static function bridge_admin( WP_REST_Request $request ) {
		$ok = ANPA_Socios_Admin_Auth::permission_admin( $request );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		// Admin_Auth::REQ_PARAM_EMAIL === self::REQ_PARAM_EMAIL ('_anpa_admin_email'),
		// so write_audit() reads the WP user email set by the gate. Tag the rol.
		$request->set_param( self::REQ_PARAM_ROL, 'admin_wp' );

		return true;
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
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Builds the «Curso/Aula» label shown in Xestión: «3ºD», or «3º» when the pupil has no letter yet.
	 *
	 * Deliberately computed in PHP (1.56.3). The 1.56.2 SQL version carried the literal 'º' plus a
	 * `TRIM(TRAILING 'º' FROM …)`: a non-ASCII query makes wpdb run its invalid-text check, whose
	 * table parser (`get_table_from_query()`) took that inner FROM as the main one, resolved the
	 * table as «COALESCE», failed `SHOW FULL COLUMNS` and rejected the whole query with «datos non
	 * válidos» — both admin lists came back empty with HTTP 200. Keeping the SQL pure ASCII skips
	 * that machinery entirely.
	 *
	 * @since  1.56.3
	 * @param  string|null $curso Canonical level («3º») or a legacy bare number («3»).
	 * @param  string|null $aula  Class letter; may be empty or NULL (1.54.0).
	 * @return string Empty string when there is no level.
	 */
	public static function curso_completo( ?string $curso, ?string $aula ): string {
		$curso = (string) preg_replace( '/[º°]+$/u', '', trim( (string) $curso ) );
		if ( '' === $curso ) {
			return '';
		}

		return $curso . 'º' . trim( (string) $aula );
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
