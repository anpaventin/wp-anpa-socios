<?php
/**
 * REST endpoint for the anpa-socios plugin.
 *
 * Registers a single POST route that validates the Fase 1
 * verification token, runs pure-logic validation on the
 * provided data, and upserts a row into the production
 * wp_anpa_socios table.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the REST routes and handles the crear-socio call.
 *
 * @since 1.0.0
 */
class ANPA_Socios_REST {

	/**
	 * The REST namespace for this plugin.
	 *
	 * The Fase 1 plugin uses `anpa/v1`; this plugin uses a
	 * distinct namespace `anpa-socios/v1` to keep the two
	 * plugins decoupled in the REST API surface.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const REST_NAMESPACE = 'anpa-socios/v1';

	/**
	 * WordPress hook to register REST routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/solicitar-codigo-alta',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_solicitar_codigo_alta' ),
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

		register_rest_route(
			self::REST_NAMESPACE,
			'/crear-socio',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_crear_socio' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && strlen( $value ) <= 64;
						},
					),
					'nome'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'apelidos' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/alta',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_alta' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && strlen( $value ) <= 64;
						},
					),
				),
			)
		);
	}

	/**
	 * Generic success response for accepted signup-code requests.
	 *
	 * Used for both sent and rate-limited valid emails so the API
	 * does not reveal request state.
	 *
	 * @since  1.0.0
	 * @return WP_REST_Response
	 */
	private static function resposta_xenerica(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Se o email é válido, recibirás un código en breve',
			),
			200
		);
	}

	/**
	 * Returns the request IP address sanitized for storage.
	 *
	 * Uses REMOTE_ADDR only, matching Fase 1 and avoiding spoofable
	 * client-provided proxy headers.
	 *
	 * @since  1.0.0
	 * @return string Sanitized IP address, or an empty string if unavailable.
	 */
	private static function get_request_ip(): string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * Returns whether wpdb currently reports an error.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private static function has_db_error(): bool {
		global $wpdb;

		return '' !== (string) $wpdb->last_error;
	}

	/**
	 * Clears wpdb's last error before a query whose result is checked.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function clear_db_error(): void {
		global $wpdb;

		$wpdb->last_error = '';
	}

	/**
	 * Handler for POST /wp-json/anpa-socios/v1/solicitar-codigo-alta.
	 *
	 * Flow:
	 *   1. Sanitise and validate email. Malformed email returns 400
	 *      without touching the DB or email subsystem.
	 *   2. Read rate-limit history by email+IP from the shared code table.
	 *   3. If rate-limited, return the generic 200 response without
	 *      invalidating old codes, inserting a row, or sending email.
	 *   4. Generate code/hash/expiry.
	 *   5. In a DB transaction, invalidate previous unused codes for
	 *      the email and insert the new pending code row.
	 *   6. If any DB write fails, rollback and return 500.
	 *   7. After successful commit, send email and return generic 200.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_solicitar_codigo_alta( WP_REST_Request $request ) {
		global $wpdb;

		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				'Email non válido',
				array( 'status' => 400 )
			);
		}

		// Anti-bot: honeypot + time-trap. On detection, return the same
		// generic success response (no oracle — indistinguishable from real).
		$honeypot  = (string) $request->get_param( 'website' );
		$ts_raw    = $request->get_param( '_ts' );
		$render_ts = is_numeric( $ts_raw ) ? (int) $ts_raw : null;
		if ( ! ANPA_Socios_Antibot::passes( $honeypot, $render_ts, time() ) ) {
			return self::resposta_xenerica();
		}

		$tabela_codigos = $wpdb->prefix . 'anpa_codigos_verificacion';
		$ip             = self::get_request_ip();

		self::clear_db_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- shared verification-code table lookup is the documented rate-limit contract.
		$timestamps = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT UNIX_TIMESTAMP(creado_en) FROM {$tabela_codigos} "
				. 'WHERE email = %s AND ip = %s '
				. 'AND creado_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
				$email,
				$ip
			)
		);
		if ( self::has_db_error() ) {
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		$timestamps = array_map( 'intval', is_array( $timestamps ) ? $timestamps : array() );

		if ( ! ANPA_Socios_Rate_Limiter::permitir( $timestamps, 3, 3600 ) ) {
			return self::resposta_xenerica();
		}

		$codigo = ANPA_Socios_Codigo_Generator::generate();
		$hash   = ANPA_Socios_Codigo_Generator::hash_code( $codigo );
		$expira = date( 'Y-m-d H:i:s', ANPA_Socios_Codigo_Generator::expiry( time() ) );

		self::clear_db_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction keeps invalidation + insert atomic.
		$wpdb->query( 'START TRANSACTION' );
		if ( self::has_db_error() ) {
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		// Global one-active-code rule: invalidate every previous unused code for this email,
		// regardless of whether Fase 1 or anpa-socios created it.
		self::clear_db_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicit shared-table invalidation is the documented contract.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tabela_codigos} SET usado = 1 WHERE email = %s AND usado = 0",
				$email
			)
		);
		if ( self::has_db_error() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction rollback for failed invalidation.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		self::clear_db_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct insert into shared verification-code table is intentional.
		$inserted = $wpdb->insert(
			$tabela_codigos,
			array(
				'email'       => $email,
				'codigo_hash' => $hash,
				'expira_en'   => $expira,
				'intentos'    => 0,
				'usado'       => 0,
				'ip'          => $ip,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( false === $inserted || self::has_db_error() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction rollback for failed insert.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		self::clear_db_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction commit for shared-table update+insert.
		$wpdb->query( 'COMMIT' );
		if ( self::has_db_error() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback is safe even if commit outcome is uncertain.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		$enviado = ANPA_Socios_Email::enviar_codigo( $email, $codigo );
		if ( ! $enviado ) {
			error_log( 'wp_mail failed for solicitar-codigo-alta from ip=' . $ip ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational SMTP failure signal; no email/code is logged.
			return new WP_Error( 'anpa_socios_mail_error', 'Erro ao enviar, téntao de novo', array( 'status' => 500 ) );
		}

		return self::resposta_xenerica();
	}

	/**
	 * Handler for POST /wp-json/anpa-socios/v1/crear-socio.
	 *
	 * 12-step call sequence:
	 *   1.  Sanitise token
	 *   2.  Sanitise nome
	 *   3.  Sanitise apelidos
	 *   4.  Validate nome (pure logic)
	 *   5.  Validate apelidos (pure logic)
	 *   6.  Look up get_transient('anpa_token_' . $token)
	 *   7.  If transient is false -> 400
	 *   8.  Build SQL (INSERT ... ON DUPLICATE KEY UPDATE)
	 *   9.  Execute via $wpdb->query
	 *   10. Check $wpdb->last_error
	 *         - duplicate-key (1062) -> continue (silent no-op)
	 *         - other error          -> 500 (DO NOT delete_transient)
	 *   11. delete_transient (single-use)
	 *   12. Return 200
	 *
	 * The order of steps 9-12 is the critical user-confirmed
	 * contract: delete_transient MUST NOT run if the DB write
	 * failed.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_crear_socio( WP_REST_Request $request ) {
		// Steps 1-3: sanitise.
		$token    = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$nome     = sanitize_text_field( (string) $request->get_param( 'nome' ) );
		$apelidos = sanitize_text_field( (string) $request->get_param( 'apelidos' ) );

		// Steps 4-5: pure-logic validation.
		$nome_validado     = ANPA_Socios_Payload::validar_nome( $nome );
		$apelidos_validado = ANPA_Socios_Payload::validar_apelidos( $apelidos );

		if ( null === $nome_validado || null === $apelidos_validado ) {
			return new WP_Error(
				'anpa_socios_invalid',
				'Datos inválidos',
				array( 'status' => 400 )
			);
		}

		// Step 6: transient lookup. The Fase 1 plugin stored this
		// transient with the email as the value.
		$email = get_transient( 'anpa_token_' . $token );

		// Step 7: token expired, missing, or already consumed.
		// Note: we do NOT call delete_transient here (a no-op
		// wouldn't help, and the user must re-issue
		// solicitar-codigo for a fresh token).
		if ( false === $email || ! is_string( $email ) || '' === $email ) {
			return new WP_Error(
				'anpa_socios_invalid_token',
				'Token inválido ou caducado',
				array( 'status' => 400 )
			);
		}

		// Step 8: build SQL.
		global $wpdb;
		$tabela        = $wpdb->prefix . 'anpa_socios';
		$sql           = "INSERT INTO {$tabela} "
			. '(email, nome, apelidos, estado, creado_en, actualizado_en) '
			. 'VALUES (%s, %s, %s, %s, NOW(), NOW()) '
			. "ON DUPLICATE KEY UPDATE "
			. "actualizado_en = NOW(), "
			. "estado         = 'activo', "
			. 'nome           = VALUES(nome), '
			. 'apelidos       = VALUES(apelidos)';
		$sql_prepared  = $wpdb->prepare(
			$sql,
			$email,
			$nome_validado,
			$apelidos_validado,
			'activo'
		);

		// Step 9: execute.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct query is intentional (the
		// upsert is the documented contract; wpdb::insert does not
		// support ON DUPLICATE KEY UPDATE).
		$wpdb->query( $sql_prepared ); // phpcs:ignore

		// Step 10: check the result. If the query failed with
		// anything other than the duplicate-key silent no-op, return
		// 500 WITHOUT deleting the transient. The user can retry.
		$last_error = $wpdb->last_error;
		if ( '' !== $last_error ) {
			// MySQL error code 1062 is the duplicate-key silent
			// no-op (the ON DUPLICATE KEY UPDATE clause handled it).
			// Anything else is a real DB error.
			if ( false === strpos( $last_error, '1062' ) ) {
				return new WP_Error(
					'anpa_socios_db_error',
					'Erro interno',
					array( 'status' => 500 )
				);
			}
			// Duplicate-key path: the upsert succeeded; continue
			// to step 11.
		}

		// Step 11: delete the transient (single-use enforcement).
		// Called ONLY after a successful DB write (step 9 returned
		// without a non-1062 error). The user MUST NOT be able to
		// reuse this token.
		delete_transient( 'anpa_token_' . $token );

		// Step 11b: ensure the configured master email gets the master role.
		if ( strtolower( $email ) === ANPA_Socios_Config::master_email() ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'anpa_socios',
				array( 'rol' => 'master' ),
				array( 'email' => $email ),
				array( '%s' ),
				array( '%s' )
			);
		}

		// Step 12: success response. Identical for fresh insert,
		// duplicate-update, and reactivación (R14) so that no
		// email enumeration is possible.
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Alta completada',
			),
			200
		);
	}

	/**
	 * Handler for POST /wp-json/anpa-socios/v1/alta.
	 *
	 * Transactional full membership signup: validates the whole payload
	 * (parent1 + optional parent2 + N fillos + RGPD) with pure logic, then
	 * writes everything inside a single DB transaction. Any failure rolls
	 * the whole alta back, so no partial socio/fillo rows persist. The
	 * single-use Fase 1 token is consumed only after a successful commit.
	 *
	 * Banking (SEPA) data is NOT handled here; it is collected and stored
	 * encrypted by a later unit into a dedicated table.
	 *
	 * @since  1.7.0
	 * @param  WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_alta( WP_REST_Request $request ) {
		global $wpdb;

		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );

		// Defense-in-depth rate limit (the token is single-use and strong,
		// but cap per-IP attempts anyway). 20 attempts/hour/IP.
		if ( ! self::consume_alta_rate_limit() ) {
			return new WP_Error( 'anpa_socios_rate_limited', 'Demasiadas solicitudes', array( 'status' => 429 ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// Pure validation of the entire payload (parents + fillos + RGPD).
		$clean = ANPA_Socios_Alta_Payload::validar( $body );
		if ( null === $clean ) {
			$field_errors = ANPA_Socios_Alta_Payload::$errors;
			if ( ! empty( $field_errors ) ) {
				return new WP_Error(
					'anpa_socios_invalid',
					'Corrixe os campos marcados.',
					array( 'status' => 400, 'fields' => $field_errors )
				);
			}
			return new WP_Error( 'anpa_socios_invalid', 'Datos inválidos', array( 'status' => 400 ) );
		}

		// Single-use token -> parent1 email. Mirrors crear-socio.
		$email = get_transient( 'anpa_token_' . $token );
		if ( false === $email || ! is_string( $email ) || '' === $email ) {
			return new WP_Error( 'anpa_socios_invalid_token', 'Token inválido ou caducado', array( 'status' => 400 ) );
		}

		$socios       = $wpdb->prefix . 'anpa_socios';
		$fillos_table = $wpdb->prefix . 'anpa_fillos';

		self::clear_db_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction keeps the multi-row alta atomic.
		$wpdb->query( 'START TRANSACTION' );
		if ( self::has_db_error() ) {
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		// New-socio approval workflow: when enabled, a new socio is parked in
		// 'pendente_aprobacion' until the master approves them (they cannot
		// request a login code while pending). The configured master account is
		// never gated so the admin can always get in.
		$is_master_email = ( strtolower( $email ) === ANPA_Socios_Config::master_email() );
		$needs_approval  = ANPA_Socios_Config::require_approval() && ! $is_master_email;
		$owner_estado    = $needs_approval ? 'pendente_aprobacion' : 'activo';

		// Parent 1: full upsert (account owner from the verified token).
		if ( ! self::upsert_socio( $email, $clean['parent1'], null, false, $owner_estado ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback on failed parent1 write.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		// Ensure the configured master email gets the master role (and stays
		// active — the master is never subject to the approval gate).
		if ( $is_master_email ) {
			$wpdb->update(
				$socios,
				array( 'rol' => 'master', 'estado' => 'activo' ),
				array( 'email' => $email ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}

		// Resolve parent1 id + family id (familia_id = head socio id).
		$parent1_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$socios} WHERE email = %s", $email ) );
		if ( $parent1_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback when parent1 id cannot be resolved.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		$familia_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT familia_id FROM {$socios} WHERE email = %s", $email ) );
		if ( $familia_id <= 0 ) {
			$familia_id = $parent1_id;
			self::clear_db_error();
			$wpdb->update( $socios, array( 'familia_id' => $familia_id ), array( 'email' => $email ), array( '%d' ), array( '%s' ) );
			if ( self::has_db_error() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback on failed familia_id link.
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
			}
		}

		// Parent 2 (optional): insert-only. If the email already belongs to
		// an existing socio it is left completely untouched (no hijack, no
		// reactivation, no familia_id reassignment) — see upsert_socio.
		if ( null !== $clean['parent2'] ) {
			if ( ! self::upsert_socio( $clean['parent2']['email'], $clean['parent2'], $familia_id, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback on failed parent2 write.
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
			}
		}

		// Fillos.
		foreach ( $clean['fillos'] as $fillo ) {
			// Skip an identical active fillo for this socio so re-running
			// the alta (with a fresh token) does not create duplicate rows.
			$dup = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$fillos_table} WHERE socio_email = %s AND nome = %s AND apelidos = %s AND data_nacemento = %s AND estado = 'activo'",
					$email,
					$fillo['nome'],
					$fillo['apelidos'],
					$fillo['data_nacemento']
				)
			);
			if ( $dup > 0 ) {
				continue;
			}

			self::clear_db_error();
			$inserted = $wpdb->insert(
				$fillos_table,
				array(
					'socio_email'   => $email,
					'nome'          => $fillo['nome'],
					'apelidos'      => $fillo['apelidos'],
					'data_nacemento' => $fillo['data_nacemento'],
					'curso'         => $fillo['curso'],
					'aula'          => $fillo['aula'],
					'estado'        => 'activo',
					'image_consent' => (int) $fillo['image_consent'],
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
			if ( false === $inserted || self::has_db_error() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback on failed fillo insert.
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
			}
		}

		// SEPA banking (optional): seal + upsert via the shared helper (one row
		// per family). On any failure the whole alta is rolled back.
		if ( is_array( $clean['sepa'] ) ) {
			$saved = ANPA_Socios_Domiciliacion::save_sealed( $familia_id, $clean['sepa'] );
			if ( is_wp_error( $saved ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback on banking failure.
				$wpdb->query( 'ROLLBACK' );
				return $saved;
			}
		}

		self::clear_db_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- commit the atomic alta.
		$wpdb->query( 'COMMIT' );
		if ( self::has_db_error() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback if commit outcome is uncertain.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'anpa_socios_db_error', 'Erro interno', array( 'status' => 500 ) );
		}

		// Single-use enforcement: consume the token only after commit.
		delete_transient( 'anpa_token_' . $token );

		if ( $needs_approval ) {
			// Notify the master (best-effort) with a link to the pending
			// approvals in the plugin settings, and tell the applicant they
			// must wait for the junta directiva's approval.
			$nome_owner = trim( (string) ( $clean['parent1']['nome'] ?? '' ) . ' ' . (string) ( $clean['parent1']['apelidos'] ?? '' ) );
			ANPA_Socios_Email::enviar_aviso_pendente_aprobacion( $email, $nome_owner );

			$assoc = ANPA_Socios_Config::association_name();

			return new WP_REST_Response(
				array(
					'success'          => true,
					'pending_approval' => true,
					'message'          => sprintf(
						'A túa solicitude foi rexistrada. A directiva de %s ten que aprobala antes de que poidas acceder á área de socios. Recibirás un correo cando estea aprobada.',
						$assoc
					),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Alta completada',
			),
			200
		);
	}

	/**
	 * Upserts a socio row from a validated parent block.
	 *
	 * For the account owner ($link_only_on_dup = false) all profile fields
	 * are written/refreshed. For a second parent ($link_only_on_dup = true)
	 * the row is INSERT-only: if the email already exists the statement is a
	 * strict no-op (email = email), so an existing socio is never modified,
	 * reactivated, or reassigned to another family by someone else's alta.
	 *
	 * The duplicate-key (1062) path is treated as a successful no-op, in
	 * line with handle_crear_socio.
	 *
	 * @since  1.7.0
	 * @param  string                    $email            Socio email.
	 * @param  array<string,string|null> $parent           Validated parent block.
	 * @param  int|null                  $familia_id       Family id to link, or null.
	 * @param  bool                      $link_only_on_dup Preserve existing identity on duplicate.
	 * @param  string                    $estado           Target estado for the owner branches
	 *                                                     ('activo' or 'pendente_aprobacion').
	 *                                                     Ignored when $link_only_on_dup is true.
	 * @return bool True on success (including 1062 no-op), false on real DB error.
	 */
	private static function upsert_socio( string $email, array $parent, ?int $familia_id, bool $link_only_on_dup, string $estado = 'activo' ): bool {
		global $wpdb;

		$socios   = $wpdb->prefix . 'anpa_socios';
		$nome     = (string) $parent['nome'];
		$apelidos = (string) $parent['apelidos'];
		$nif      = (string) ( $parent['nif'] ?? '' );
		$telefono = (string) ( $parent['telefono'] ?? '' );
		// Whitelist the estado so it can only ever be one of the two expected
		// values; never interpolate a caller string straight into SQL.
		$estado   = in_array( $estado, array( 'activo', 'pendente_aprobacion' ), true ) ? $estado : 'activo';

		if ( $link_only_on_dup ) {
			$fam = null === $familia_id ? 0 : $familia_id;
			// Insert a brand-new second parent linked to the family. If the
			// email already belongs to an existing socio, this is a strict
			// no-op (email = email) so another person's alta can NEVER
			// reassign, reactivate, or clobber that existing socio. Linking
			// a pre-existing socio to a family requires its own verified flow.
			$sql = "INSERT INTO {$socios} (email, nome, apelidos, nif, telefono, familia_id, estado, creado_en, actualizado_en)
				VALUES (%s, %s, %s, %s, %s, %d, 'activo', NOW(), NOW())
				ON DUPLICATE KEY UPDATE email = email";
			$prepared = $wpdb->prepare( $sql, $email, $nome, $apelidos, $nif, $telefono, $fam );
		} elseif ( null === $familia_id ) {
			// $estado is whitelisted above to a fixed literal, safe to interpolate.
			$sql = "INSERT INTO {$socios} (email, nome, apelidos, nif, telefono, estado, creado_en, actualizado_en)
				VALUES (%s, %s, %s, %s, %s, '{$estado}', NOW(), NOW())
				ON DUPLICATE KEY UPDATE actualizado_en = NOW(), estado = '{$estado}',
				nome = VALUES(nome), apelidos = VALUES(apelidos), nif = VALUES(nif), telefono = VALUES(telefono)";
			$prepared = $wpdb->prepare( $sql, $email, $nome, $apelidos, $nif, $telefono );
		} else {
			// $estado is whitelisted above to a fixed literal, safe to interpolate.
			$sql = "INSERT INTO {$socios} (email, nome, apelidos, nif, telefono, familia_id, estado, creado_en, actualizado_en)
				VALUES (%s, %s, %s, %s, %s, %d, '{$estado}', NOW(), NOW())
				ON DUPLICATE KEY UPDATE actualizado_en = NOW(), estado = '{$estado}',
				nome = VALUES(nome), apelidos = VALUES(apelidos), nif = VALUES(nif), telefono = VALUES(telefono), familia_id = VALUES(familia_id)";
			$prepared = $wpdb->prepare( $sql, $email, $nome, $apelidos, $nif, $telefono, $familia_id );
		}

		self::clear_db_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- upsert is the documented contract; wpdb::insert has no ON DUPLICATE KEY UPDATE.
		$wpdb->query( $prepared );

		$last_error = $wpdb->last_error;
		if ( '' !== $last_error && false === strpos( $last_error, '1062' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Per-IP rate limit for the /alta endpoint (transient-backed).
	 *
	 * Allows up to 20 attempts per hour per IP. Defense-in-depth on top of
	 * the single-use token; prevents token brute-force and write floods.
	 *
	 * @since  1.7.0
	 * @return bool True if the request is permitted, false if rate-limited.
	 */
	private static function consume_alta_rate_limit(): bool {
		$ip      = self::get_request_ip();
		$key     = 'anpa_alta_rl_' . md5( $ip );
		$now     = time();
		$stored  = get_transient( $key );
		$history = is_array( $stored ) ? array_map( 'intval', $stored ) : array();

		if ( ! ANPA_Socios_Rate_Limiter::permitir( $history, 20, 3600, $now ) ) {
			return false;
		}

		$history[] = $now;
		set_transient( $key, $history, 3600 );

		return true;
	}
}
