<?php
/**
 * Email sending for ANPA Socios transactional messages.
 *
 * All sender identity (association name, From/Reply-To address, recipient
 * for junta notices) is resolved from ANPA_Socios_Config so the plugin is
 * multi-tenant: any ANPA/AMPA sets its own values via the setup wizard /
 * Axustes. Nothing here is hardcoded to a single association.
 *
 * Since 1.40.0 (fase36, PR-36s3), the wording comes from templates — stored
 * row first, else packaged default — rendered by the frozen Validator/Renderer
 * pipeline. The public signatures, parameter names, defaults, nullability and
 * return types are UNCHANGED: the 13 call sites know nothing about templates.
 *
 * IMPORTANT SECURITY RULES:
 * - Never log the access code anywhere.
 * - Never return the code from REST handlers.
 * - Never expose the code via var_dump / print_r.
 * - The access code (`enviar_codigo`) is NEVER enqueued, never written to a
 *   payload snapshot, and never recorded in the communications log.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends transactional emails through templates.
 *
 * @since 1.0.0
 */
class ANPA_Socios_Email {

	/**
	 * From/Reply-To headers built from the configurable association identity.
	 *
	 * @since  1.27.0
	 * @return string[]
	 */
	private static function notice_headers(): array {
		$assoc = ANPA_Socios_Config::association_name();
		$from  = ANPA_Socios_Config::master_email();

		return array(
			'From: ' . $assoc . ' <' . $from . '>',
			'Reply-To: ' . $assoc . ' <' . $from . '>',
		);
	}

	/**
	 * Recipient inbox for operational junta notices (baixa requests, …).
	 *
	 * @since  1.27.0
	 * @return string
	 */
	private static function junta_email(): string {
		return ANPA_Socios_Config::master_email();
	}

	/**
	 * Sends a verification code email to the given address.
	 *
	 * DIRECT SEND ONLY. This method MUST NOT be routed through any queue, payload
	 * snapshot, or communications log. The access code is ephemeral by design: it
	 * exists in memory for the duration of this call and in the family's inbox, and
	 * nowhere else.
	 *
	 * @param  string $email   Recipient email address.
	 * @param  string $codigo  Plain-text 6-digit code to embed in the email.
	 * @param  string $context Optional context: 'alta' (default) or 'verificacion'.
	 * @return bool            True if wp_mail() accepted the message locally.
	 */
	public static function enviar_codigo( string $email, string $codigo, string $context = 'alta' ): bool {
		// ─── NON-ENQUEUE GUARD ────────────────────────────────────────────────────
		// This assertion proves — in CI, not by convention — that the access code can
		// never be enqueued. If a future queue integration were to call this method
		// inside an enqueue context, the guard fires. The constant is defined ONLY by
		// the queue processor at the moment it processes a queued item; it is never
		// defined during a normal request.
		if ( defined( 'ANPA_SOCIOS_EMAIL_QUEUE_PROCESSING' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] SECURITY: enviar_codigo called inside queue processing context; refused' );
			return false;
		}

		$event_key = 'verificacion' === $context ? 'auth_access_code' : 'auth_access_code_signup';

		$event_context = array_merge(
			self::global_context(),
			array( 'codigo' => $codigo )
		);

		return self::send_templated( $event_key, $email, $event_context );
	}

	/**
	 * Filter callback that returns HTML email content type.
	 *
	 * @return string
	 */
	public static function content_type_html(): string {
		return 'text/html';
	}

	/**
	 * Notifies the junta directiva that a socio requested baixa.
	 *
	 * Operational notice with the socio's name + email only (no sensitive
	 * data). Best-effort: callers must not fail the request if mail fails.
	 *
	 * @since  1.8.0
	 * @param  string $email_socio Socio email.
	 * @param  string $nome        Socio nome.
	 * @param  string $apelidos    Socio apelidos.
	 * @return bool                True if wp_mail() accepted the message locally.
	 */
	public static function enviar_aviso_baixa_socio( string $email_socio, string $nome, string $apelidos ): bool {
		$nome_completo = trim( $nome . ' ' . $apelidos );

		$event_context = array_merge(
			self::global_context(),
			array(
				'nome_socio'   => $nome_completo,
				'correo_socio' => $email_socio,
			)
		);

		return self::send_templated( 'member_cancellation_admin_notice', self::junta_email(), $event_context );
	}

	/**
	 * Notifies the junta that a former member requested reactivation.
	 *
	 * Operational notice (email only). Best-effort; never fail the caller.
	 *
	 * @since  1.9.0
	 * @param  string $email_socio Socio email requesting reactivation.
	 * @return bool                True if wp_mail() accepted the message locally.
	 */
	public static function enviar_aviso_reactivacion( string $email_socio ): bool {
		$event_context = array_merge(
			self::global_context(),
			array( 'correo_socio' => $email_socio )
		);

		return self::send_templated( 'member_reactivation_admin_notice', self::junta_email(), $event_context );
	}

	/**
	 * Notifies the junta that a socio requested baixa of an extraescolar.
	 *
	 * Operational notice (no sensitive data). Best-effort.
	 *
	 * @since  1.9.0
	 * @param  string $email_socio Socio email.
	 * @param  string $alumno      Pupil name.
	 * @param  string $actividade  Activity name.
	 * @return bool
	 */
	public static function enviar_aviso_baixa_extraescolar( string $email_socio, string $alumno, string $actividade ): bool {
		$event_context = array_merge(
			self::global_context(),
			array(
				'nome_alumno'     => $alumno,
				'nome_actividade' => $actividade,
				'correo_socio'    => $email_socio,
			)
		);

		return self::send_templated( 'activity_cancellation_admin_notice', self::junta_email(), $event_context );
	}

	/**
	 * Notifies a socio that a waitlist place opened up for an activity.
	 *
	 * No sensitive data; directs the socio to their personal area to accept
	 * before the deadline. Best-effort.
	 *
	 * @since  1.9.0
	 * @param  string $email_socio Socio email.
	 * @param  string $actividade  Activity name.
	 * @param  int    $dias_prazo  Days to respond.
	 * @return bool
	 */
	public static function enviar_oferta_extraescolar( string $email_socio, string $actividade, int $dias_prazo ): bool {
		$event_context = array_merge(
			self::global_context(),
			array(
				'nome_actividade' => $actividade,
				'dias_prazo'      => (string) $dias_prazo,
			)
		);

		return self::send_templated( 'waitlist_place_offer', $email_socio, $event_context );
	}

	/**
	 * Sends one transactional email from its stored template (fase36, PR-36s3).
	 *
	 * The single internal path every migrated emitter uses. The public methods keep their names,
	 * parameters and return types: what changes is where the wording comes from, never the contract
	 * the 13 call sites depend on.
	 *
	 *   context → effective template → validate → render → wp_mail
	 *
	 * EFFECTIVE TEMPLATE, in this order and for this reason:
	 *   1. the stored row, because a board that edited its wording expects to see its wording;
	 *   2. otherwise the packaged default, because an install that has not seeded yet must still be
	 *      able to send. A missing row is a reason to fall back, never a reason to lose an email.
	 *
	 * A RENDER FAILURE REFUSES THE SEND. Never a partial email: a body with a literal `{{token}}`
	 * or a paragraph missing is worse for the reader than an email that did not arrive, and the
	 * failure is logged so it is diagnosable instead of silent.
	 *
	 * Headers, recipient handling and the HTML content type are byte-identical to the legacy path —
	 * the golden oracle pins `TO`, `SUBJECT`, `HEADERS` and the body, so any drift fails CI.
	 *
	 * @since  1.40.0
	 * @param  string              $event_key Registered event key.
	 * @param  string              $to        Recipient.
	 * @param  array<string,mixed> $context   Token values for this send.
	 * @return bool True when wp_mail() accepted the message locally. NOT a delivery confirmation.
	 */
	private static function send_templated( string $event_key, string $to, array $context ): bool {
		try {
			$definition = ANPA_Socios_Email_Template_Events::set()->get( $event_key );
			$template   = self::effective_template( $event_key );
			$rendered   = ANPA_Socios_Email_Template_Validator::render( $definition, $template, $context );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Refused to send ' . $event_key . ': ' . $e->getMessage() );
			return false;
		}

		if ( ! $rendered['ok'] ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[anpa-socios] Refused to send ' . $event_key . ': render failed (' . $rendered['code'] . ')' );
			return false;
		}

		$headers = self::notice_headers();

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );

		try {
			// Production sends HTML only. The plain-text channel exists as reviewable content for a
			// future multipart message; shipping a file does not authorise changing the historical
			// MIME type, and this slice does not change it.
			return wp_mail( $to, $rendered['subject'], $rendered['body_html'], $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		}
	}

	/**
	 * The template a send should actually use: the stored row, or the packaged default.
	 *
	 * @since  1.40.0
	 * @param  string $event_key Registered event key.
	 * @return array<string,string> Keys: subject, body_html, body_text.
	 * @throws ANPA_Socios_Email_Template_Registry_Error When neither a row nor a packaged default exists.
	 */
	private static function effective_template( string $event_key ): array {
		if ( class_exists( 'ANPA_Socios_Email_Template_Repo' ) ) {
			$row = ANPA_Socios_Email_Template_Repo::get( $event_key );

			if ( null !== $row ) {
				return array(
					'subject'   => (string) $row['subject'],
					'body_html' => (string) $row['body_html'],
					'body_text' => (string) $row['body_text'],
				);
			}
		}

		return ANPA_Socios_Email_Template_Packaged_Default::for_event( $event_key )->content();
	}

	/**
	 * Configuration values every template may use, resolved once per send.
	 *
	 * The global tokens exist so no template names the association. Resolving them here rather than
	 * in each emitter is what keeps "the association is called X" out of nine methods.
	 *
	 * @since  1.40.0
	 * @return array<string,string>
	 */
	private static function global_context(): array {
		return array(
			'nome_anpa'   => ANPA_Socios_Config::association_name(),
			'correo_anpa' => ANPA_Socios_Config::contact_email(),
			// The RAW signature, not HTML: the renderer escapes values in the HTML channel
			// and the template wraps this in a `white-space: pre-line` paragraph. Passing markup
			// here would arrive as literal tags, which is the renderer working as designed.
			'sinatura'    => trim( ANPA_Socios_Config::email_signature() ),
		);
	}

	/**
	 * Notifies the master that a new socio is waiting for approval.
	 *
	 * Best-effort; callers must not fail the alta if mail fails. Contains the
	 * applicant name + email and a direct link to the plugin settings, where
	 * the pending-approvals section lives.
	 *
	 * @since  1.23.0
	 * @param  string $email_socio Applicant email.
	 * @param  string $nome        Applicant full name.
	 * @return bool
	 */
	public static function enviar_aviso_pendente_aprobacion( string $email_socio, string $nome ): bool {
		// The legacy substitutes `(sen nome)` when `trim($nome) === ''`. `nome_solicitante` is a
		// REQUIRED token, so the fallback must be applied BEFORE building the context.
		$nome_effective = '' !== trim( $nome ) ? $nome : __( '(sen nome)', 'anpa-socios' );

		$event_context = array_merge(
			self::global_context(),
			array(
				'nome_solicitante'   => $nome_effective,
				'correo_solicitante' => $email_socio,
				'ligazon_axustes'    => admin_url( 'admin.php?page=anpa-socios-settings' ),
			)
		);

		return self::send_templated( 'member_application_admin_pending', self::junta_email(), $event_context );
	}

	/**
	 * Notifies a socio that their alta was approved by the junta directiva.
	 *
	 * @since  1.23.0
	 * @param  string $email_socio Approved socio email.
	 * @param  string $login_url   URL of the socios login/area page.
	 * @return bool
	 */
	public static function enviar_aprobacion( string $email_socio, string $login_url = '' ): bool {
		$event_context = array_merge(
			self::global_context(),
			ANPA_Socios_Email_Template_Context::area_link( $login_url )
		);

		return self::send_templated( 'member_application_approved', $email_socio, $event_context );
	}

	/**
	 * Welcome/confirmation email sent right after a completed alta when NO
	 * admin approval is required. Sent to every parent of the family so both
	 * proxenitores get the initial confirmation and access instructions.
	 *
	 * @since  1.41.0
	 * @param  string $email_socio Socio email.
	 * @param  string $login_url   URL of the socios login/area page.
	 * @return bool
	 */
	public static function enviar_benvida_alta( string $email_socio, string $login_url = '' ): bool {
		$event_context = array_merge(
			self::global_context(),
			ANPA_Socios_Email_Template_Context::area_link( $login_url )
		);

		return self::send_templated( 'member_application_completed', $email_socio, $event_context );
	}

	/**
	 * Notifies a socio that their alta was rejected by the junta directiva.
	 *
	 * @since  1.23.0
	 * @param  string $email_socio Rejected socio email.
	 * @return bool
	 */
	public static function enviar_rexeitamento( string $email_socio ): bool {
		$event_context = self::global_context();

		return self::send_templated( 'member_application_changes_required', $email_socio, $event_context );
	}
}
