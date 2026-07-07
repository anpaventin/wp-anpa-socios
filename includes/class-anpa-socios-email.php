<?php
/**
 * Email sending for ANPA Socios signup verification codes.
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
	 * Junta directiva inbox for operational notifications (baixa requests, …).
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const JUNTA_EMAIL = 'xunta.directiva@anpaventin.es';

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
		$from  = ANPA_Socios_Config::master_email();

		if ( 'verificacion' === $context ) {
			$asunto = wp_specialchars_decode( 'O teu código de verificación — ' . $assoc, ENT_QUOTES );
			$corpo  = self::crear_corpo_html_verificacion( $codigo );
		} else {
			$asunto = wp_specialchars_decode( 'O teu código de alta — ' . $assoc, ENT_QUOTES );
			$corpo  = self::crear_corpo_html( $codigo );
		}

		// Append the configurable signature (if any) before </body>.
		$corpo = str_replace( '</body>', self::signature_html() . '</body>', $corpo );

		$headers = array(
			'From: ' . $assoc . ' <' . $from . '>',
			'Reply-To: ' . $assoc . ' <' . $from . '>',
		);

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
			. '<h2>Verificación de socio/a</h2>'
			. '<p>O teu código de verificación é:</p>'
			. '<p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center;'
			. ' background: #f0f0f0; padding: 15px; border-radius: 8px;">'
			. $codigo_seguro
			. '</p>'
			. '<p>Este código caduca en <strong>15 minutos</strong>.</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">'
			. 'Se non solicitaches este código, ignora este correo.'
			. '</p>'
			. '</body>'
			. '</html>';
	}

	/**
	 * Builds the HTML body for the verification email.
	 *
	 * @param  string $codigo Plain-text 6-digit code.
	 * @return string         HTML body.
	 */
	private static function crear_corpo_html( string $codigo ): string {
		$codigo_seguro = esc_html( $codigo );

		return '<!DOCTYPE html>'
			. '<html>'
			. '<head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>Alta na ANPA As Brañas</h2>'
			. '<p>O teu código para continuar coa alta é:</p>'
			. '<p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center;'
			. ' background: #f0f0f0; padding: 15px; border-radius: 8px;">'
			. $codigo_seguro
			. '</p>'
			. '<p>Este código caduca en <strong>15 minutos</strong>.</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">'
			. 'Se non solicitaches esta alta, ignora este correo.'
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
		$asunto        = wp_specialchars_decode( 'Solicitude de baixa de socio/a — ANPA As Brañas', ENT_QUOTES );
		$nome_completo = trim( $nome . ' ' . $apelidos );

		$corpo = '<!DOCTYPE html>'
			. '<html>'
			. '<head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>Solicitude de baixa de socio/a</h2>'
			. '<p>Un/unha socio/a solicitou a baixa na ANPA As Brañas:</p>'
			. '<ul>'
			. '<li><strong>Nome:</strong> ' . esc_html( $nome_completo ) . '</li>'
			. '<li><strong>Email:</strong> ' . esc_html( $email_socio ) . '</li>'
			. '</ul>'
			. '<p>A baixa será efectiva a fin de curso, tras a confirmación dun/dunha administrador/a no panel de Xestión ANPA.</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">Aviso automático do sistema de socios.</p>'
			. '</body>'
			. '</html>';

		$headers = array(
			'From: ANPA As Brañas <xunta.directiva@anpaventin.es>',
			'Reply-To: ANPA As Brañas <xunta.directiva@anpaventin.es>',
		);

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );

		try {
			return wp_mail( self::JUNTA_EMAIL, $asunto, $corpo, $headers );
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
		$asunto = wp_specialchars_decode( 'Solicitude de reactivación de socio/a — ANPA As Brañas', ENT_QUOTES );

		$corpo = '<!DOCTYPE html>'
			. '<html>'
			. '<head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>Solicitude de reactivación</h2>'
			. '<p>Un/unha antig@ socio/a solicitou reactivar a súa conta:</p>'
			. '<ul><li><strong>Email:</strong> ' . esc_html( $email_socio ) . '</li></ul>'
			. '<p>A conta quedou en estado <strong>pendente de alta</strong>. Un/unha administrador/a debe activala '
			. 'explicitamente no panel de Xestión ANPA para restaurar o acceso.</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">Aviso automático do sistema de socios.</p>'
			. '</body>'
			. '</html>';

		$headers = array(
			'From: ANPA As Brañas <xunta.directiva@anpaventin.es>',
			'Reply-To: ANPA As Brañas <xunta.directiva@anpaventin.es>',
		);

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );

		try {
			return wp_mail( self::JUNTA_EMAIL, $asunto, $corpo, $headers );
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
		$asunto = wp_specialchars_decode( 'Solicitude de baixa nunha extraescolar — ANPA As Brañas', ENT_QUOTES );

		$corpo = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>Solicitude de baixa nunha actividade extraescolar</h2>'
			. '<ul>'
			. '<li><strong>Alumno/a:</strong> ' . esc_html( $alumno ) . '</li>'
			. '<li><strong>Actividade:</strong> ' . esc_html( $actividade ) . '</li>'
			. '<li><strong>Solicitado por:</strong> ' . esc_html( $email_socio ) . '</li>'
			. '</ul>'
			. '<p>A baixa será efectiva a fin de trimestre, tras a confirmación dun/dunha administrador/a no panel de Xestión ANPA.</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">Aviso automático do sistema de socios.</p>'
			. '</body></html>';

		$headers = array(
			'From: ANPA As Brañas <xunta.directiva@anpaventin.es>',
			'Reply-To: ANPA As Brañas <xunta.directiva@anpaventin.es>',
		);
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		try {
			return wp_mail( self::JUNTA_EMAIL, $asunto, $corpo, $headers );
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
		$asunto = wp_specialchars_decode( 'Hai unha praza dispoñible nunha extraescolar — ANPA As Brañas', ENT_QUOTES );

		$corpo = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>Quedou unha praza libre</h2>'
			. '<p>Liberouse unha praza na actividade <strong>' . esc_html( $actividade ) . '</strong> e '
			. 'o teu fillo/a é o/a seguinte na lista de espera.</p>'
			. '<p>Entra na túa <strong>Área persoal</strong> da web da ANPA e acepta a praza no apartado '
			. '«Actividades extraescolares» antes de <strong>' . (int) $dias_prazo . ' días</strong>. '
			. 'Se non respondes a tempo, a praza ofrecerase á seguinte familia.</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">Aviso automático do sistema de socios.</p>'
			. '</body></html>';

		$headers = array(
			'From: ANPA As Brañas <xunta.directiva@anpaventin.es>',
			'Reply-To: ANPA As Brañas <xunta.directiva@anpaventin.es>',
		);
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
		$assoc = ANPA_Socios_Config::association_name();
		$from  = ANPA_Socios_Config::master_email();

		$body    = str_replace( '</body>', self::signature_html() . '</body>', $body );
		$headers = array(
			'From: ' . $assoc . ' <' . $from . '>',
			'Reply-To: ' . $assoc . ' <' . $from . '>',
		);

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

		$asunto = 'Novo socio/a pendente de aprobación — ' . $assoc;
		$corpo  = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>Novo socio/a pendente de aprobación</h2>'
			. '<p>Unha nova persoa solicitou darse de alta en ' . esc_html( $assoc ) . ' e precisa da túa aprobación:</p>'
			. '<ul>'
			. '<li><strong>Nome:</strong> ' . esc_html( '' !== trim( $nome ) ? $nome : '(sen nome)' ) . '</li>'
			. '<li><strong>Email:</strong> ' . esc_html( $email_socio ) . '</li>'
			. '</ul>'
			. '<p>Revisa e aproba (ou rexeita) as solicitudes pendentes na sección de <strong>Xestión ANPA</strong> '
			. 'da área de socios, ou na páxina de <strong>Axustes</strong> do plugin:</p>'
			. '<p><a href="' . esc_url( $settings_url ) . '">' . esc_html( $settings_url ) . '</a></p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">Aviso automático do sistema de socios.</p>'
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

		$asunto = 'A túa alta foi aprobada — ' . $assoc;
		$corpo  = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>Benvido/a a ' . esc_html( $assoc ) . '</h2>'
			. '<p>A directiva de ' . esc_html( $assoc ) . ' aprobou a túa alta como socio/a. '
			. 'Xa podes acceder á túa área persoal.</p>'
			. ( '' !== $login_url
				? '<p>Entra na área de socios e inicia sesión co teu correo (recibirás un código de acceso):</p>'
					. '<p><a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a></p>'
				: '<p>Entra na área de socios da web e inicia sesión co teu correo; recibirás un código de acceso.</p>' )
			. '<p>Desde a túa área persoal poderás xestionar os teus datos, os teus fillos/as e as actividades extraescolares.</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">Aviso automático do sistema de socios.</p>'
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
		$contact = ANPA_Socios_Config::master_email();

		$asunto = 'Sobre a túa solicitude de alta — ' . $assoc;
		$corpo  = '<!DOCTYPE html>'
			. '<html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. '<h2>Solicitude de alta</h2>'
			. '<p>Sentímolo, pero por decisión da directiva de ' . esc_html( $assoc ) . ' non se aprobou '
			. 'a túa solicitude de alta como socio/a neste momento.</p>'
			. '<p>Se pensas que se trata dun erro ou queres máis información, ponte en contacto con nós respondendo '
			. 'a este correo ou escribindo a <a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>.</p>'
			. '<p style="color: #666; font-size: 12px; margin-top: 30px;">Aviso automático do sistema de socios.</p>'
			. '</body></html>';

		return self::send_from_master( $email_socio, $asunto, $corpo );
	}
}
