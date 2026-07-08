<?php
/**
 * Pre-flight REST endpoint for the unified socio area flow.
 *
 * @since  1.2.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-flight lookup that decides which UI step to show next.
 *
 * The endpoint is intentionally privacy-preserving: it always returns
 * 200 with the same generic message and an opaque `next` value. The
 * caller (JS) uses `next` to choose the next step in the area flow.
 *
 * The lookup is delegated to the pure helper `ANPA_Socios_Flow` so
 * the decision can be unit-tested without a database.
 *
 * @since 1.2.0
 */
class ANPA_Socios_Preflight_REST {

	/**
	 * REST namespace for anpa-socios endpoints.
	 *
	 * Re-uses the same namespace as the existing area REST to keep all
	 * area flow endpoints under `anpa-socios/v1`.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const REST_NAMESPACE = 'anpa-socios/v1';

	/**
	 * Registers the preflight route.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/area/preflight',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_preflight' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'website' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'_ts'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handles POST /wp-json/anpa-socios/v1/area/preflight.
	 *
	 * Privacy contract:
	 *  - Invalid email format: 400 with a generic message.
	 *  - Valid email: 200 with the same generic message and a `next` value.
	 *  - Rate limit (3/h by email+IP): 200 with the same message and
	 *    `next=alta` to avoid leaking rate-limit state.
	 *
	 * @since  1.2.0
	 * @param  WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_preflight( WP_REST_Request $request ) {
		$email = (string) $request->get_param( 'email' );

		// Anti-bot: honeypot + time-trap. On detection, return the same
		// generic success the rate-limited branch returns (no oracle).
		$honeypot  = (string) $request->get_param( 'website' );
		$ts_raw    = $request->get_param( '_ts' );
		$render_ts = is_numeric( $ts_raw ) ? (int) $ts_raw : null;
		if ( ! ANPA_Socios_Antibot::passes( $honeypot, $render_ts, time() ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Continúa', 'anpa-socios' ),
					'next'    => ANPA_Socios_Flow::next( array() ),
				),
				200
			);
		}

		// Privacy contract: ALWAYS return 200 with the same message.
		// For an invalid email format we still respond 200 and let
		// the JS route to the generic "alta" step; the upstream UI
		// shows the same message regardless. This avoids a 4xx side
		// channel that could otherwise distinguish valid vs invalid
		// email.
		$ip   = self::get_request_ip();
		$flags = is_email( $email ) ? self::collect_flags( $email ) : array();

		if ( '' !== $email && self::is_rate_limited( $email, $ip ) ) {
			// No info leak: keep the response identical to the
			// non-limited path, only swap to a non-revealing next.
			$flags = array();
		}

		$next = ANPA_Socios_Flow::next( $flags );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Continúa', 'anpa-socios' ),
				'next'    => $next,
			),
			200
		);
	}

	/**
	 * Returns socio/empresa flags for the email without leaking them.
	 *
	 * The integration layer returns ONLY present keys with restricted
	 * values. The pure helper decides what to do with them.
	 *
	 * @since  1.2.0
	 * @param  string $email Email to look up.
	 * @return array<string,string>
	 */
	private static function collect_flags( string $email ): array {
		global $wpdb;

		$flags = array();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, estado, baixa_estado, familia_id, rol FROM {$wpdb->prefix}anpa_socios WHERE email = %s LIMIT 1",
				$email
			),
			ARRAY_A
		);
		if ( is_array( $row ) ) {
			$estado = (string) $row['estado'];
			if ( in_array( $estado, ANPA_Socios_Flow::FLAG_VALUES, true ) ) {
				$flags['socio'] = $estado;
			}
			// Surface a pending baixa request so the flow can offer to cancel it.
			if ( 'activo' === $estado && 'solicitada' === (string) ( $row['baixa_estado'] ?? '' ) ) {
				$flags['socio_baixa'] = 'solicitada';
			}
			// Detect proxenitor2: has familia_id and is NOT the primary (lowest id) in that family.
			$fam_id = (int) ( $row['familia_id'] ?? 0 );
			if ( $fam_id > 0 ) {
				$socio_id = (int) $row['id'];
				$primary  = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT MIN(id) FROM {$wpdb->prefix}anpa_socios WHERE familia_id = %d AND email <> %s AND estado = 'activo'",
						$fam_id,
						$email
					)
				);
				if ( $primary > 0 && $primary !== $socio_id ) {
					$flags['socio_proxenitor2'] = '1';
				}
			}
		}

		// Empresa lookup: query the empresas table for this email.
		// Flow::next() already prioritises socio over empresa, so an
		// active socio will never fall through to the empresa branch.
		$emp_estado = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT estado FROM ' . ANPA_Socios_DB::tabela_empresas() . ' WHERE email = %s LIMIT 1',
				$email
			)
		);
		if ( is_string( $emp_estado ) && in_array( $emp_estado, ANPA_Socios_Flow::FLAG_VALUES, true ) ) {
			$flags['empresa'] = $emp_estado;
		}

		return $flags;
	}

	/**
	 * Returns whether the email+IP pair has hit the rate limit.
	 *
	 * @since  1.2.0
	 * @param  string $email Socio email.
	 * @param  string $ip    Request IP.
	 * @return bool
	 */
	private static function is_rate_limited( string $email, string $ip ): bool {
		if ( '' === $email || '' === $ip ) {
			return false;
		}

		$key = 'anpa_preflight_rl_' . md5( $email . '|' . $ip );
		$now = time();
		$history = get_transient( $key );
		$history = is_array( $history ) ? array_map( 'intval', $history ) : array();

		if ( ! ANPA_Socios_Rate_Limiter::permitir( $history, 3, HOUR_IN_SECONDS, $now ) ) {
			return true;
		}

		$history[] = $now;
		set_transient( $key, $history, HOUR_IN_SECONDS );

		return false;
	}

	/**
	 * Returns the current request IP using REMOTE_ADDR only.
	 *
	 * @since  1.2.0
	 * @return string
	 */
	private static function get_request_ip(): string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
}
