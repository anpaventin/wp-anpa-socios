<?php
/**
 * Email sending for ANPA Socios verification codes and notices.
 *
 * All sender identity (association name, From/Reply-To address, recipient
 * for junta notices) is resolved from ANPA_Socios_Config so the plugin is
 * multi-tenant: any ANPA/AMPA sets its own values via the setup wizard /
 * Axustes. Nothing here is hardcoded to a single association.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends signup verification code emails.
 *
 * IMPORTANT SECURITY RULES:
 * - Never log the code anywhere.
 * - Never return the code from REST handlers.
 * - Never expose the code via var_dump / print_r.
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
	 * @param  string $email Recipient email address.
	 * @param  string $codigo Plain-text 6-digit code to embed in the email.
	 * @param  string $context Optional context: 'alta' (default) or 'verificacion'.
	 * @return bool          True if wp_mail() accepted the message.
	 */
	public static function enviar_codigo( string $email, string $codigo, string $context = 'alta' ): bool {
		$assoc = ANPA_Socios_Config::association_name();

		if ( 'verificacion' === $context ) {
			/* translators: %s: association name */
			$asunto = wp_specialchars_decode( sprintf( __( 'O teu código de verificación — %s', 'anpa-socios' ), $assoc ), ENT_QUOTES );
			$corpo  = self::crear_corpo_html_verificacion( $codigo );
		} else {
			/* translators: %s: association name */
			$asunto = wp_specialchars_decode( sprintf( __( 'O teu código de alta — %s', 'anpa-socios' ), $assoc ), ENT_QUOTES );
			$corpo  = self::crear_corpo_html( $codigo );
		}

		// Append the configurable signature (if any) before </body>.
		$corpo = str_replace( '</body>', self::signature_html() . '</body>', $corpo );

		$headers = self::notice_headers();

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );

		try {
			return wp_mail( $email, $asunto, $corpo, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		}
	}

	/**
	 * The configurable email signature as an HTML block (empty if unset).
	 *
	 * @since  1.22.0
	 * @return string
	 */
	private static function signature_html(): string {
		$sig = trim( ANPA_Socios_Config::email_signature() );
		if ( '' === $sig ) {
			return '';
		}

		return '<hr style="margin-top:24px"><p style="color:#666;font-size:12px;white-space:pre-line">'
			. esc_html( $sig )
			. '</p>';
	}

	/**
	 * Builds the HTML body for the area verification email.
	 *
	 * @param  string $codigo Plain-text 6-digit code.
	 * @return string         HTML body.
	 */
	private static function crear_corpo_html_verificacion( string $codigo ): string {
		$codigo_seguro = esc_html( $codigo );

		return '<!DOCTYPE html>'
			. '<html>'
			. '<head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>' . esc_html__( 'Verificación de socio/a', 'anpa-socios' ) . '</h2>'
			. '<p>' . esc_html__( 'O teu código de verificación é:', 'anpa-socios' ) . '</p>'
			. '<p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center;'
			. ' background: #f0f0f0; padding: 15px; border-radius: 8px;">'
			. $codigo_seguro
			. '</p>'
			. '<p>' . sprintf( __( 'Este código caduca en %s.', 'anpa-socios' ), '<strong>' . esc_html__( '15 minutos', 'anpa-socios' ) . '</strong>' ) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">'
			. esc_html__( 'Se non solicitaches este código, ignora este correo.', 'anpa-socios' )
			. '</p>'
			. '</body>'
			. '</html>';
	}

	/**
	 * Builds the HTML body for the alta verification email.
	 *
	 * @param  string $codigo Plain-text 6-digit code.
	 * @return string         HTML body.
	 */
	private static function crear_corpo_html( string $codigo ): string {
		$codigo_seguro = esc_html( $codigo );
		$assoc         = esc_html( ANPA_Socios_Config::association_name() );

		return '<!DOCTYPE html>'
			. '<html>'
			. '<head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			/* translators: %s: association name (already escaped) */
			. '<h2>' . sprintf( __( 'Alta na %s', 'anpa-socios' ), $assoc ) . '</h2>'
			. '<p>' . esc_html__( 'O teu código para continuar coa alta é:', 'anpa-socios' ) . '</p>'
			. '<p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center;'
			. ' background: #f0f0f0; padding: 15px; border-radius: 8px;">'
			. $codigo_seguro
			. '</p>'
			. '<p>' . sprintf( __( 'Este código caduca en %s.', 'anpa-socios' ), '<strong>' . esc_html__( '15 minutos', 'anpa-socios' ) . '</strong>' ) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">'
			. esc_html__( 'Se non solicitaches esta alta, ignora este correo.', 'anpa-socios' )
			. '</p>'
			. '</body>'
			. '</html>';
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
	 * @return bool                True if wp_mail() accepted the message.
	 */
	public static function enviar_aviso_baixa_socio( string $email_socio, string $nome, string $apelidos ): bool {
		$assoc         = ANPA_Socios_Config::association_name();
		/* translators: %s: association name */
		$asunto        = wp_specialchars_decode( sprintf( __( 'Solicitude de baixa de socio/a — %s', 'anpa-socios' ), $assoc ), ENT_QUOTES );
		$nome_completo = trim( $nome . ' ' . $apelidos );

		$corpo = '<!DOCTYPE html>'
			. '<html>'
			. '<head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>' . esc_html__( 'Solicitude de baixa de socio/a', 'anpa-socios' ) . '</h2>'
			/* translators: %s: association name */
			. '<p>' . sprintf( esc_html__( 'Un/unha socio/a solicitou a baixa en %s:', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Nome:', 'anpa-socios' ) . '</strong> ' . esc_html( $nome_completo ) . '</li>'
			. '<li><strong>' . esc_html__( 'Email:', 'anpa-socios' ) . '</strong> ' . esc_html( $email_socio ) . '</li>'
			. '</ul>'
			. '<p>' . esc_html__( 'A baixa será efectiva a fin de curso, tras a confirmación dun/dunha administrador/a no panel de Xestión ANPA.', 'anpa-socios' ) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
			. '</body>'
			. '</html>';

		$headers = self::notice_headers();

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );

		try {
			return wp_mail( self::junta_email(), $asunto, $corpo, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		}
	}

	/**
	 * Notifies the junta that a former member requested reactivation.
	 *
	 * Operational notice (email only). Best-effort; never fail the caller.
	 *
	 * @since  1.9.0
	 * @param  string $email_socio Socio email requesting reactivation.
	 * @return bool                True if wp_mail() accepted the message.
	 */
	public static function enviar_aviso_reactivacion( string $email_socio ): bool {
		/* translators: %s: association name */
		$asunto = wp_specialchars_decode( sprintf( __( 'Solicitude de reactivación de socio/a — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() ), ENT_QUOTES );

		$corpo = '<!DOCTYPE html>'
			. '<html>'
			. '<head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>' . esc_html__( 'Solicitude de reactivación', 'anpa-socios' ) . '</h2>'
			. '<p>' . esc_html__( 'Un/unha antig@ socio/a solicitou reactivar a súa conta:', 'anpa-socios' ) . '</p>'
			. '<ul><li><strong>' . esc_html__( 'Email:', 'anpa-socios' ) . '</strong> ' . esc_html( $email_socio ) . '</li></ul>'
			. '<p>' . esc_html__( 'A conta quedou en estado pendente de alta. Un/unha administrador/a debe activala explicitamente no panel de Xestión ANPA para restaurar o acceso.', 'anpa-socios' ) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
			. '</body>'
			. '</html>';

		$headers = self::notice_headers();

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );

		try {
			return wp_mail( self::junta_email(), $asunto, $corpo, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		}
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
		/* translators: %s: association name */
		$asunto = wp_specialchars_decode( sprintf( __( 'Solicitude de baixa nunha extraescolar — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() ), ENT_QUOTES );

		$corpo = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>' . esc_html__( 'Solicitude de baixa nunha actividade extraescolar', 'anpa-socios' ) . '</h2>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Alumno/a:', 'anpa-socios' ) . '</strong> ' . esc_html( $alumno ) . '</li>'
			. '<li><strong>' . esc_html__( 'Actividade:', 'anpa-socios' ) . '</strong> ' . esc_html( $actividade ) . '</li>'
			. '<li><strong>' . esc_html__( 'Solicitado por:', 'anpa-socios' ) . '</strong> ' . esc_html( $email_socio ) . '</li>'
			. '</ul>'
			. '<p>' . esc_html__( 'A baixa será efectiva a fin de trimestre, tras a confirmación dun/dunha administrador/a no panel de Xestión ANPA.', 'anpa-socios' ) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
			. '</body></html>';

		$headers = self::notice_headers();
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		try {
			return wp_mail( self::junta_email(), $asunto, $corpo, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		}
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
		/* translators: %s: association name */
		$asunto = wp_specialchars_decode( sprintf( __( 'Hai unha praza dispoñible nunha extraescolar — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() ), ENT_QUOTES );

		$corpo = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>' . esc_html__( 'Quedou unha praza libre', 'anpa-socios' ) . '</h2>'
			/* translators: %s: activity name */
			. '<p>' . sprintf( esc_html__( 'Liberouse unha praza na actividade %s e o teu fillo/a é o/a seguinte na lista de espera.', 'anpa-socios' ), '<strong>' . esc_html( $actividade ) . '</strong>' ) . '</p>'
			/* translators: %d: number of days to respond */
			. '<p>' . sprintf( esc_html__( 'Entra na túa Área persoal da web da ANPA e acepta a praza no apartado «Actividades extraescolares» antes de %d días. Se non respondes a tempo, a praza ofrecerase á seguinte familia.', 'anpa-socios' ), (int) $dias_prazo ) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
			. '</body></html>';

		$headers = self::notice_headers();
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		try {
			return wp_mail( $email_socio, $asunto, $corpo, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		}
	}

	/**
	 * Sends an email through the master mailbox using the configurable
	 * association name and appending the configurable signature.
	 *
	 * @since  1.23.0
	 * @param  string $to      Recipient email.
	 * @param  string $subject Subject (plain).
	 * @param  string $body    HTML body (must contain a </body> tag).
	 * @return bool
	 */
	private static function send_from_master( string $to, string $subject, string $body ): bool {
		$body    = str_replace( '</body>', self::signature_html() . '</body>', $body );
		$headers = self::notice_headers();

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		try {
			return wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), $body, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		}
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
		$assoc        = ANPA_Socios_Config::association_name();
		$settings_url = admin_url( 'admin.php?page=anpa-socios-settings' );

		/* translators: %s: association name */
		$asunto = sprintf( __( 'Novo socio/a pendente de aprobación — %s', 'anpa-socios' ), $assoc );
		$corpo  = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>' . esc_html__( 'Novo socio/a pendente de aprobación', 'anpa-socios' ) . '</h2>'
			/* translators: %s: association name */
			. '<p>' . sprintf( esc_html__( 'Unha nova persoa solicitou darse de alta en %s e precisa da túa aprobación:', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Nome:', 'anpa-socios' ) . '</strong> ' . esc_html( '' !== trim( $nome ) ? $nome : __( '(sen nome)', 'anpa-socios' ) ) . '</li>'
			. '<li><strong>' . esc_html__( 'Email:', 'anpa-socios' ) . '</strong> ' . esc_html( $email_socio ) . '</li>'
			. '</ul>'
			. '<p>' . esc_html__( 'Revisa e aproba (ou rexeita) as solicitudes pendentes na sección de Xestión ANPA da área de socios, ou na páxina de Axustes do plugin:', 'anpa-socios' ) . '</p>'
			. '<p><a href="' . esc_url( $settings_url ) . '">' . esc_html( $settings_url ) . '</a></p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
			. '</body></html>';

		return self::send_from_master( ANPA_Socios_Config::master_email(), $asunto, $corpo );
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
		$assoc = ANPA_Socios_Config::association_name();

		/* translators: %s: association name */
		$asunto = sprintf( __( 'A túa alta foi aprobada — %s', 'anpa-socios' ), $assoc );
		$corpo  = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			/* translators: %s: association name */
			. '<h2>' . sprintf( esc_html__( 'Benvido/a a %s', 'anpa-socios' ), esc_html( $assoc ) ) . '</h2>'
			/* translators: %s: association name */
			. '<p>' . sprintf( esc_html__( 'A directiva de %s aprobou a túa alta como socio/a. Xa podes acceder á túa área persoal.', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
			. ( '' !== $login_url
				? '<p>' . esc_html__( 'Entra na área de socios e inicia sesión co teu correo (recibirás un código de acceso):', 'anpa-socios' ) . '</p>'
					. '<p><a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a></p>'
				: '<p>' . esc_html__( 'Entra na área de socios da web e inicia sesión co teu correo; recibirás un código de acceso.', 'anpa-socios' ) . '</p>' )
			. '<p>' . esc_html__( 'Desde a túa área persoal poderás xestionar os teus datos, os teus fillos/as e as actividades extraescolares.', 'anpa-socios' ) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
			. '</body></html>';

		return self::send_from_master( $email_socio, $asunto, $corpo );
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
		$assoc = ANPA_Socios_Config::association_name();

		/* translators: %s: association name */
		$asunto = sprintf( __( 'Alta completada — %s', 'anpa-socios' ), $assoc );
		$corpo  = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			/* translators: %s: association name */
			. '<h2>' . sprintf( esc_html__( 'Benvido/a a %s', 'anpa-socios' ), esc_html( $assoc ) ) . '</h2>'
			/* translators: %s: association name */
			. '<p>' . sprintf( esc_html__( 'A túa alta como socio/a en %s completouse correctamente. Xa podes acceder á túa área persoal.', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
			. ( '' !== $login_url
				? '<p>' . esc_html__( 'Entra na área de socios e inicia sesión co teu correo (recibirás un código de acceso):', 'anpa-socios' ) . '</p>'
					. '<p><a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a></p>'
				: '<p>' . esc_html__( 'Entra na área de socios da web e inicia sesión co teu correo; recibirás un código de acceso.', 'anpa-socios' ) . '</p>' )
			. '<p>' . esc_html__( 'Desde a túa área persoal poderás xestionar os teus datos, os teus fillos/as e as actividades extraescolares.', 'anpa-socios' ) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
			. '</body></html>';

		return self::send_from_master( $email_socio, $asunto, $corpo );
	}

	/**
	 * Notifies a socio that their alta was rejected by the junta directiva.
	 *
	 * @since  1.23.0
	 * @param  string $email_socio Rejected socio email.
	 * @return bool
	 */
	public static function enviar_rexeitamento( string $email_socio ): bool {
		$assoc   = ANPA_Socios_Config::association_name();
		$contact = ANPA_Socios_Config::contact_email();

		/* translators: %s: association name */
		$asunto = sprintf( __( 'Sobre a túa solicitude de alta — %s', 'anpa-socios' ), $assoc );
		$corpo  = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>' . esc_html__( 'Solicitude de alta', 'anpa-socios' ) . '</h2>'
			/* translators: %s: association name */
			. '<p>' . sprintf( esc_html__( 'Sentímolo, pero por decisión da directiva de %s non se aprobou a túa solicitude de alta como socio/a neste momento.', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: contact email address (already linked) */
				__( 'Se pensas que se trata dun erro ou queres máis información, ponte en contacto con nós respondendo a este correo ou escribindo a %s.', 'anpa-socios' ),
				'<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>'
			) . '</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
			. '</body></html>';

		return self::send_from_master( $email_socio, $asunto, $corpo );
	}
}
