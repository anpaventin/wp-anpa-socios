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
	 * 1.90.0: Telegram copy of a junta notice. 1.92.0: sent when the notice
	 * has its Telegram box ticked in Axustes → Xeral → Avisos á xunta. Only a
	 * fixed title and a short line, never family data. Best-effort: never
	 * throws, never changes what the email call returns.
	 *
	 * @since  1.90.0
	 * @param  string $aviso_id ANPA_Socios_Avisos_Xunta notice id.
	 * @param  string $titulo   Title (the subject without data).
	 * @param  string $aviso    Short line.
	 * @param  string $url      Xestión link ('' = Aprobacións).
	 * @return void
	 */
	private static function telegram( string $aviso_id, string $titulo, string $aviso, string $url = '' ): void {
		if ( ! class_exists( 'ANPA_Socios_Telegram_Xunta' ) ) {
			return;
		}
		try {
			ANPA_Socios_Telegram_Xunta::avisar( $aviso_id, $titulo, $aviso, $url );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * 1.92.0: whether a junta notice goes by email to the junta's inbox
	 * (Axustes → Xeral → Avisos á xunta). Defaults to true on any error.
	 *
	 * @since  1.92.0
	 * @param  string $aviso_id ANPA_Socios_Avisos_Xunta notice id.
	 * @return bool
	 */
	private static function correo_xunta( string $aviso_id ): bool {
		if ( ! class_exists( 'ANPA_Socios_Avisos_Xunta' ) ) {
			return true;
		}
		try {
			return ANPA_Socios_Config::aviso_xunta( $aviso_id, ANPA_Socios_Avisos_Xunta::CORREO );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Xestión link for the junta notices ('' outside WordPress).
	 *
	 * @param  string $section Xestión section slug.
	 * @return string
	 */
	private static function xestion_url( string $section = 'aprobacions' ): string {
		return class_exists( 'ANPA_Socios_Telegram_Xunta' ) ? ANPA_Socios_Telegram_Xunta::xestion_url( $section ) : '';
	}

	/**
	 * FASE36: Renders email content with template override support.
	 *
	 * If a custom template exists for the given ID, uses the template renderer.
	 * Otherwise falls back to the hardcoded content builder (preserving
	 * backward compatibility exactly).
	 *
	 * @since  1.39.0
	 * @param string   $template_id  Template identifier.
	 * @param array    $context      Variables for template rendering.
	 * @param callable $hardcoded_fn Returns [subject, html, text] arrays.
	 * @return array{subject:string,html:string,text:string}
	 */
	private static function render_with_template(
		string $template_id,
		array $context,
		callable $hardcoded_fn
	): array {
		$custom = get_option( 'anpa_socios_email_templates', null );

		if ( is_array( $custom ) && isset( $custom[ $template_id ] ) ) {
			return ANPA_Socios_Email_Template_Renderer::render( $template_id, $context );
		}

		return $hardcoded_fn();
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

		$content = self::render_with_template(
			'verification_code',
			array(
				'association_name' => $assoc,
				'nome'             => $email,
				'codigo'           => $codigo,
			),
			function () use ( $assoc, $codigo, $context ) {
				if ( 'verificacion' === $context ) {
					$subj = wp_specialchars_decode( sprintf( __( 'O teu código de verificación — %s', 'anpa-socios' ), $assoc ), ENT_QUOTES );
					$html = self::crear_corpo_html_verificacion( $codigo );
				} else {
					$subj = wp_specialchars_decode( sprintf( __( 'O teu código de alta — %s', 'anpa-socios' ), $assoc ), ENT_QUOTES );
					$html = self::crear_corpo_html( $codigo );
				}
				return array(
					'subject' => $subj,
					'html'    => $html,
					'text'    => wp_strip_all_tags( $html ),
				);
			}
		);

		// 1.65.1: one exit point for every email. The store template (seeded since
		// fase36) is a fragment without </body>, so the old str_replace never added
		// the signature to the code emails — the most frequent email of all.
		return self::send_from_master( $email, (string) $content['subject'], (string) $content['html'] );
	}

	/**
	 * 1.102.0: tells the OLD address that the account's login email changed, so
	 * a change the owner did not make is noticed. Best-effort: never throws.
	 *
	 * @since  1.102.0
	 * @param  string $vello Address that no longer opens the account.
	 * @param  string $novo  New login address.
	 * @return bool
	 */
	public static function enviar_aviso_cambio_correo( string $vello, string $novo ): bool {
		$vello = trim( $vello );
		if ( '' === $vello || false === filter_var( $vello, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}
		/* translators: %s: association name */
		$asunto = sprintf( __( 'Cambiouse o correo da túa conta — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() );
		$html   = '<h2>' . esc_html__( 'Cambio de correo', 'anpa-socios' ) . '</h2>'
			/* translators: 1: old email, 2: new email */
			. '<p>' . esc_html( sprintf( __( 'O correo de acceso á área de socios cambiouse de %1$s a %2$s. A partir de agora só se pode entrar co novo enderezo.', 'anpa-socios' ), $vello, $novo ) ) . '</p>'
			/* translators: %s: contact email */
			. '<p>' . esc_html( sprintf( __( 'Se é un erro, ponte en contacto canto antes coa administración da ANPA en %s.', 'anpa-socios' ), ANPA_Socios_Config::contact_email() ) ) . '</p>';
		try {
			return self::send_from_master( $vello, $asunto, $html );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * 1.103.0: one parent changed the other parent's data in the area. The
	 * other one gets the list of changes; when the login address changed, the
	 * message goes to the OLD address and says how to report a mistake.
	 * Best-effort: never throws.
	 *
	 * @since  1.103.0
	 * @param  string                                                         $destino     Address of the parent whose data changed (the old one if it changed).
	 * @param  string                                                         $autor       Full name of the parent who made the change.
	 * @param  array<int,array{campo:string,antes:string,despois:string}> $cambios     ANPA_Socios_Cambio_Email::cambios().
	 * @param  bool                                                           $cambia_mail The login address is one of the changes.
	 * @return bool
	 */
	public static function enviar_aviso_datos_modificados( string $destino, string $autor, array $cambios, bool $cambia_mail ): bool {
		$destino = trim( $destino );
		if ( '' === $destino || false === filter_var( $destino, FILTER_VALIDATE_EMAIL ) || array() === $cambios ) {
			return false;
		}
		/* translators: %s: association name */
		$asunto = sprintf( __( 'Cambiáronse os teus datos na área de socios — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() );
		$filas  = '';
		foreach ( $cambios as $c ) {
			$baleiro = __( '(baleiro)', 'anpa-socios' );
			$filas  .= '<li><strong>' . esc_html( (string) $c['campo'] ) . ':</strong> '
				. esc_html( '' !== (string) $c['antes'] ? (string) $c['antes'] : $baleiro )
				. ' → '
				. esc_html( '' !== (string) $c['despois'] ? (string) $c['despois'] : $baleiro ) . '</li>';
		}
		$html = '<h2>' . esc_html__( 'Cambios nos teus datos', 'anpa-socios' ) . '</h2>'
			/* translators: %s: full name of the other parent */
			. '<p>' . esc_html( sprintf( __( '%s, o outro proxenitor/titor da túa familia, modificou os teus datos na área de socios:', 'anpa-socios' ), '' !== trim( $autor ) ? $autor : __( 'Outra persoa', 'anpa-socios' ) ) ) . '</p>'
			. '<ul>' . $filas . '</ul>';
		if ( $cambia_mail ) {
			$html .= '<p><strong>' . esc_html__( 'O correo de acceso cambiou: a partir de agora só se pode entrar na área co novo enderezo, e este xa non recibirá os avisos da ANPA.', 'anpa-socios' ) . '</strong></p>';
		}
		/* translators: %s: contact email */
		$html .= '<p>' . esc_html( sprintf( __( 'Se é un erro, ponte en contacto canto antes coa administración da ANPA en %s.', 'anpa-socios' ), ANPA_Socios_Config::contact_email() ) ) . '</p>';
		try {
			return self::send_from_master( $destino, $asunto, $html );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * 1.104.0: a plain notice from the association's identity, with the
	 * signature. The caller escapes the HTML. Best-effort: never throws.
	 *
	 * @since  1.104.0
	 * @param  string $to     Recipient.
	 * @param  string $asunto Subject.
	 * @param  string $html   Escaped HTML fragment.
	 * @return bool
	 */
	public static function enviar_simple( string $to, string $asunto, string $html ): bool {
		$to = trim( $to );
		if ( '' === $to || false === filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}
		try {
			return self::send_from_master( $to, $asunto, $html );
		} catch ( \Throwable $e ) {
			return false;
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

		// 1.67.0: limited HTML (links, bold, line breaks) instead of plain text, so the
		// social links can be clickable. Line breaks typed in the textarea still render.
		return '<hr style="margin-top:24px"><p style="color:#666;font-size:12px;white-space:pre-line">'
			. wp_kses( $sig, self::signature_allowed_html() )
			. '</p>';
	}

	/**
	 * HTML the signature may contain: links, bold/italic and line breaks. Nothing
	 * that can carry scripts, styles or remote images into every email.
	 *
	 * @since  1.67.0
	 * @return array<string,array<string,bool>>
	 */
	public static function signature_allowed_html(): array {
		return array(
			'a'      => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true ),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
		);
	}

	/**
	 * Sanitizes the signature typed in Axustes (wizard and Xeral) before saving.
	 * Keeps the allowed tags and the plain-text line breaks; drops the rest.
	 *
	 * @since  1.67.0
	 * @param  string $raw Submitted value (already unslashed).
	 * @return string
	 */
	public static function sanitize_signature( string $raw ): string {
		return trim( wp_kses( $raw, self::signature_allowed_html() ) );
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
		$nome_completo = trim( $nome . ' ' . $apelidos );

		$content = self::render_with_template(
			'baixa_socio',
			array(
				'association_name' => $assoc,
				'nome'             => $nome,
				'apelidos'         => $apelidos,
				'email_socio'      => $email_socio,
			),
			function () use ( $assoc, $nome_completo, $email_socio ) {
				$subj = wp_specialchars_decode( sprintf( __( 'Solicitude de baixa de socio/a — %s', 'anpa-socios' ), $assoc ), ENT_QUOTES );
				$html = '<!DOCTYPE html>'
					. '<html>'
					. '<head><meta charset="UTF-8"></head>'
					. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
					. '<h2>' . esc_html__( 'Solicitude de baixa de socio/a', 'anpa-socios' ) . '</h2>'
					. '<p>' . sprintf( esc_html__( 'Un/unha socio/a solicitou a baixa en %s:', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
					. '<ul>'
					. '<li><strong>' . esc_html__( 'Nome:', 'anpa-socios' ) . '</strong> ' . esc_html( $nome_completo ) . '</li>'
					. '<li><strong>' . esc_html__( 'Email:', 'anpa-socios' ) . '</strong> ' . esc_html( $email_socio ) . '</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'A baixa será efectiva a fin de curso, tras a confirmación dun/dunha administrador/a no panel de Xestión ANPA.', 'anpa-socios' ) . '</p>'
					. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
					. '</body>'
					. '</html>';
				return array(
					'subject' => $subj,
					'html'    => $html,
					'text'    => wp_strip_all_tags( $html ),
				);
			}
		);

		// 1.65.1: junta notices go through send_from_master() too (full HTML + signature).
		$ok = self::correo_xunta( ANPA_Socios_Avisos_Xunta::BAIXA_SOCIO ) && self::send_from_master( self::junta_email(), (string) $content['subject'], (string) $content['html'] );
		self::telegram( ANPA_Socios_Avisos_Xunta::BAIXA_SOCIO, __( 'Solicitude de baixa de socio/a', 'anpa-socios' ), __( 'Unha familia pediu a baixa como socia. Revísaa en Aprobacións.', 'anpa-socios' ) );
		return $ok;
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
		$content = self::render_with_template(
			'reactivacion',
			array(
				'association_name' => ANPA_Socios_Config::association_name(),
				'email_socio'      => $email_socio,
			),
			function () use ( $email_socio ) {
				$subj = wp_specialchars_decode( sprintf( __( 'Solicitude de reactivación de socio/a — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() ), ENT_QUOTES );
				$html = '<!DOCTYPE html>'
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
				return array(
					'subject' => $subj,
					'html'    => $html,
					'text'    => wp_strip_all_tags( $html ),
				);
			}
		);

		// 1.65.1: junta notices go through send_from_master() too (full HTML + signature).
		$ok = self::correo_xunta( ANPA_Socios_Avisos_Xunta::REACTIVACION_SOCIO ) && self::send_from_master( self::junta_email(), (string) $content['subject'], (string) $content['html'] );
		self::telegram( ANPA_Socios_Avisos_Xunta::REACTIVACION_SOCIO, __( 'Solicitude de reactivación de socio/a', 'anpa-socios' ), __( 'Unha antiga familia socia pediu reactivar a conta. Actívaa en Aprobacións.', 'anpa-socios' ) );
		return $ok;
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
		$content = self::render_with_template(
			'baixa_extraescolar',
			array(
				'association_name' => ANPA_Socios_Config::association_name(),
				'alumno'           => $alumno,
				'actividade'       => $actividade,
				'email_socio'      => $email_socio,
			),
			function () use ( $email_socio, $alumno, $actividade ) {
				$subj = wp_specialchars_decode( sprintf( __( 'Solicitude de baixa nunha extraescolar — %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() ), ENT_QUOTES );
				$html = '<!DOCTYPE html>'
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
				return array(
					'subject' => $subj,
					'html'    => $html,
					'text'    => wp_strip_all_tags( $html ),
				);
			}
		);

		// 1.65.1: junta notices go through send_from_master() too (full HTML + signature).
		$ok = self::correo_xunta( ANPA_Socios_Avisos_Xunta::BAIXA_EXTRAESCOLAR ) && self::send_from_master( self::junta_email(), (string) $content['subject'], (string) $content['html'] );
		self::telegram( ANPA_Socios_Avisos_Xunta::BAIXA_EXTRAESCOLAR, __( 'Solicitude de baixa nunha extraescolar', 'anpa-socios' ), __( 'Unha familia pediu a baixa dunha actividade extraescolar. Revísaa en Aprobacións.', 'anpa-socios' ) );
		return $ok;
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
	 * @param  bool   $copia_xunta 1.91.0: Bcc the junta (place freed by a baixa the company reported).
	 * @return bool
	 */
	public static function enviar_oferta_extraescolar( array $emails, string $alumno, string $actividade, string $grupo, int $dias_prazo, string $estado_grupo = '', bool $copia_xunta = false, string $comezo = '' ): bool {
		// 1.93.0: to every parent (it used to reach only the pupil's socio_email), with the
		// pupil, the group and the group's real state; through the template store like the rest.
		return self::send_template_familia_xunta(
			$emails,
			'oferta_extraescolar',
			array(
				'actividade'   => $actividade,
				'dias_prazo'   => (string) $dias_prazo,
				'alumno'       => $alumno,
				'grupo'        => $grupo,
				'estado_grupo' => $estado_grupo,
				'comezo'       => '' !== $comezo ? $comezo : ANPA_Socios_Estado_Grupo_Texto::comezo_oferta( 0 ),
			) + self::links_context(),
			$copia_xunta
		);
	}

	/**
	 * 1.93.0: the group's real state for the enrolment emails.
	 *
	 * @since  1.93.0
	 * @param  int $grupo_id Group id.
	 * @return array{clave:string,xunta:string}
	 */
	public static function contexto_grupo( int $grupo_id ): array {
		global $wpdb;
		$out = array( 'clave' => ANPA_Socios_Estado_Grupo_Texto::SEN_CREAR, 'xunta' => '' );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only lookup for the email.
			$g = $wpdb->get_row( $wpdb->prepare( 'SELECT curso_escolar, estado, min_pupilos, max_pupilos, aviso_comezo_en FROM ' . ANPA_Socios_DB::tabela_grupos() . ' WHERE id = %d', $grupo_id ), ARRAY_A );
			if ( ! is_array( $g ) ) {
				return $out;
			}
			$gate   = ANPA_Socios_Matricula_Gate_Repo::para_curso( (string) $g['curso_escolar'] );
			$creado = ANPA_Socios_Aviso_Matricula::grupo_creado( (string) ( $gate['estado_curso'] ?? '' ), null === $g['aviso_comezo_en'] ? null : (string) $g['aviso_comezo_en'] );
			$clave  = ANPA_Socios_Estado_Grupo_Texto::clave( (string) $g['estado'], $creado );
			return array(
				'clave' => $clave,
				'xunta' => ANPA_Socios_Estado_Grupo_Texto::xunta( $clave, ANPA_Socios_Lista_Espera::ocupadas( $grupo_id ), (int) $g['min_pupilos'], (int) $g['max_pupilos'], ANPA_Socios_Lista_Espera::en_espera( $grupo_id ) ),
			);
		} catch ( \Throwable $e ) {
			return $out;
		}
	}

	/**
	 * 1.93.0: the family sentence about the group of an enrolment.
	 *
	 * @since  1.93.0
	 * @param  int    $grupo_id Group id.
	 * @param  string $momento  ANPA_Socios_Estado_Grupo_Texto::PRAZA|ESPERA|PENDENTE|OFERTA.
	 * @return string
	 */
	public static function estado_grupo_familia( int $grupo_id, string $momento ): string {
		return ANPA_Socios_Estado_Grupo_Texto::familia( self::contexto_grupo( $grupo_id )['clave'], $momento );
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
	private static function send_from_master( string $to, string $subject, string $body, array $extra_headers = array() ): bool {
		$body    = self::wrap_html( $body );
		$headers = array_merge( self::notice_headers(), $extra_headers );

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		try {
			return wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), $body, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type_html' ) );
		}
	}

	/**
	 * Full HTML document with the ANPA signature (Axustes → Xeral → Sinatura).
	 *
	 * The hardcoded bodies already carry a <body>; the templates edited in
	 * Axustes → Plantillas are fragments, so until 1.62.0 a customised template
	 * lost the signature (str_replace on a missing </body> did nothing). Both
	 * shapes now end with the same signature block.
	 *
	 * @since  1.62.0
	 * @param  string $body HTML fragment or full document.
	 * @return string
	 */
	public static function wrap_html( string $body ): string {
		if ( false !== stripos( $body, '</body>' ) ) {
			return str_ireplace( '</body>', self::signature_html() . '</body>', $body );
		}

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
			. $body
			. self::signature_html()
			. '</body></html>';
	}

	/**
	 * Context shared by the family-facing templates.
	 *
	 * @since  1.62.0
	 * @return array<string,string>
	 */
	private static function family_context(): array {
		return array(
			'association_name' => ANPA_Socios_Config::association_name(),
			'contact_email'    => ANPA_Socios_Config::contact_email(),
		);
	}

	/**
	 * Renders a store template (custom or default) and sends it from the master
	 * identity with the signature. Best-effort: never throws.
	 *
	 * @since  1.62.0
	 * @param  string               $to          Recipient.
	 * @param  string               $template_id Template id in the store.
	 * @param  array<string,string> $context     Variables.
	 * @return bool
	 */
	private static function send_template( string $to, string $template_id, array $context, array $extra_headers = array() ): bool {
		$to = trim( $to );
		if ( '' === $to ) {
			return false;
		}
		$content = ANPA_Socios_Email_Template_Renderer::render( $template_id, array_merge( self::family_context(), $context ) );
		if ( '' === trim( (string) $content['subject'] ) || '' === trim( (string) $content['html'] ) ) {
			return false;
		}
		// 1.93.0: an optional sentence (e.g. the group's state) left empty leaves no blank paragraph.
		$content['html'] = (string) preg_replace( '#<p>\s*</p>#', '', (string) $content['html'] );

		return self::send_from_master( $to, (string) $content['subject'], (string) $content['html'], $extra_headers );
	}

	/**
	 * 1.91.0: one message to every parent of the pupil, with a copy (Bcc) to the
	 * junta's inbox. Best-effort: never throws.
	 *
	 * @param  array<int,string>    $emails      Family addresses.
	 * @param  string               $template_id Template id.
	 * @param  array<string,string> $context     Variables.
	 * @return bool
	 */
	private static function send_template_familia_xunta( array $emails, string $template_id, array $context, bool $copia_xunta = true ): bool {
		$emails = array_values( array_unique( array_filter( array_map( static function ( $e ): string { return strtolower( trim( (string) $e ) ); }, $emails ), static function ( string $e ): bool { return '' !== $e && false !== filter_var( $e, FILTER_VALIDATE_EMAIL ); } ) ) );
		if ( array() === $emails ) {
			return false;
		}
		try {
			return self::send_template( implode( ', ', $emails ), $template_id, $context, $copia_xunta ? array( 'Bcc: ' . self::junta_email() ) : array() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The junta confirmed the member's baixa request (Baixas solicitadas → Confirmar).
	 *
	 * @since  1.62.0
	 * The whole family unit is given baixa at once; every parent receives this
	 * email with the list of addresses that were deregistered.
	 *
	 * @param  string $email_socio  Member email.
	 * @param  string $nome         Member first name.
	 * @param  string $emails_baixa Comma-separated addresses of the whole unit.
	 * @return bool
	 */
	public static function enviar_baixa_socio_confirmada( string $email_socio, string $nome, string $emails_baixa = '' ): bool {
		// 1.83.0: the date the family's data will be deleted (retention setting).
		$eliminacion = class_exists( 'ANPA_Socios_Retencion_Service' ) ? ANPA_Socios_Retencion_Service::data_eliminacion_texto() : '';
		return self::send_template( $email_socio, 'baixa_socio_confirmada', array( 'nome' => $nome, 'emails_baixa' => '' !== $emails_baixa ? $emails_baixa : $email_socio, 'data_eliminacion' => $eliminacion ) );
	}

	/**
	 * The junta rejected the member's baixa request (Baixas solicitadas → Rexeitar).
	 *
	 * @since  1.62.0
	 * @param  string $email_socio Member email.
	 * @param  string $nome        Member first name.
	 * @return bool
	 */
	public static function enviar_baixa_socio_rexeitada( string $email_socio, string $nome ): bool {
		return self::send_template( $email_socio, 'baixa_socio_rexeitada', array( 'nome' => $nome ) );
	}

	/**
	 * The junta confirmed a pupil's activity baixa. $efectos is the sentence
	 * built by ANPA_Socios_Baixa_Extraescolar_Efectos (end of the running
	 * trimester vs. no charge during pre-enrolment).
	 *
	 * @since  1.62.0
	 * @param  string $email_socio Family email.
	 * @param  string $alumno      Pupil full name.
	 * @param  string $actividade  Activity name.
	 * @param  string $efectos     Effects sentence.
	 * @return bool
	 */
	public static function enviar_baixa_extraescolar_confirmada( string $email_socio, string $alumno, string $actividade, string $efectos ): bool {
		return self::send_template( $email_socio, 'baixa_extraescolar_confirmada', array( 'alumno' => $alumno, 'actividade' => $actividade, 'efectos' => $efectos ) );
	}

	/**
	 * The junta rejected a pupil's activity baixa request.
	 *
	 * @since  1.62.0
	 * @param  string $email_socio Family email.
	 * @param  string $alumno      Pupil full name.
	 * @param  string $actividade  Activity name.
	 * @return bool
	 */
	public static function enviar_baixa_extraescolar_rexeitada( string $email_socio, string $alumno, string $actividade ): bool {
		return self::send_template( $email_socio, 'baixa_extraescolar_rexeitada', array( 'alumno' => $alumno, 'actividade' => $actividade ) );
	}

	/**
	 * Acknowledgement to the member who just requested baixa from the area: the
	 * process is manual, takes a few days, and a confirmation email will follow.
	 *
	 * @since  1.62.0
	 * @param  string $email_socio Member email.
	 * @param  string $nome        Member first name.
	 * @return bool
	 */
	public static function enviar_baixa_socio_solicitada( string $email_socio, string $nome ): bool {
		// 1.79.0: once the course has started the baixa is only effective at its end (as the alta says).
		$curso = class_exists( 'ANPA_Socios_Admin_Baixas_Handler' ) ? ANPA_Socios_Admin_Baixas_Handler::curso_para_baixas() : array( 'en_marcha' => false );
		if ( ! empty( $curso['en_marcha'] ) ) {
			return self::send_template( $email_socio, 'baixa_socio_solicitada_curso', array( 'nome' => $nome, 'data_remate' => (string) $curso['remate_texto'] ) + self::links_context() );
		}
		return self::send_template( $email_socio, 'baixa_socio_solicitada', array( 'nome' => $nome ) );
	}

	/**
	 * Acknowledgement to the family that just requested a pupil's activity baixa.
	 *
	 * @since  1.62.0
	 * @param  string $email_socio Family email.
	 * @param  string $alumno      Pupil full name.
	 * @param  string $actividade  Activity name.
	 * @return bool
	 */
	public static function enviar_baixa_extraescolar_solicitada( string $email_socio, string $alumno, string $actividade ): bool {
		return self::send_template( $email_socio, 'baixa_extraescolar_solicitada', array( 'alumno' => $alumno, 'actividade' => $actividade ) );
	}

	// ──────────────────────────────────────────────
	// 1.91.0: baixa reported by the company (effective in 24 h on its own)
	// ──────────────────────────────────────────────

	/**
	 * To the family (both parents, Bcc the junta): the company reported the
	 * baixa; it becomes effective at $data_efectiva unless they say it is a mistake.
	 *
	 * @since  1.91.0
	 * @param  array<int,string> $emails        Family addresses.
	 * @param  string            $alumno        Pupil full name.
	 * @param  string            $actividade    Activity name.
	 * @param  string            $empresa       Company name.
	 * @param  string            $data_efectiva «29/10/2026 ás 10:30».
	 * @return bool
	 */
	public static function enviar_baixa_extraescolar_programada( array $emails, string $alumno, string $actividade, string $empresa, string $data_efectiva, string $efecto_texto = '' ): bool {
		return self::send_template_familia_xunta( $emails, 'baixa_extraescolar_programada', array( 'alumno' => $alumno, 'actividade' => $actividade, 'empresa' => '' !== trim( $empresa ) ? $empresa : __( 'da actividade', 'anpa-socios' ), 'data_efectiva' => $data_efectiva, 'efecto_texto' => $efecto_texto ) + self::links_context(), self::correo_xunta( ANPA_Socios_Avisos_Xunta::BAIXA_EMPRESA ) );
	}

	/**
	 * To the family (both parents, Bcc the junta): the reported baixa is now
	 * effective (same template as «Confirmar baixa»).
	 *
	 * @since  1.91.0
	 * @param  array<int,string> $emails     Family addresses.
	 * @param  string            $alumno     Pupil full name.
	 * @param  string            $actividade Activity name.
	 * @param  string            $efectos    ANPA_Socios_Baixa_Extraescolar_Efectos sentence.
	 * @return bool
	 */
	public static function enviar_baixa_programada_efectiva( array $emails, string $alumno, string $actividade, string $efectos ): bool {
		return self::send_template_familia_xunta( $emails, 'baixa_extraescolar_confirmada', array( 'alumno' => $alumno, 'actividade' => $actividade, 'efectos' => $efectos ), self::correo_xunta( ANPA_Socios_Avisos_Xunta::BAIXA_EMPRESA ) );
	}

	/**
	 * To the company only (Para: xunta, CCO: empresa): the reported baixa was
	 * registered, or cancelled. Sent whether the group was «created» or not: the
	 * company itself raised it. Best-effort, never throws.
	 *
	 * @since  1.91.0
	 * @param  int                  $matricula_id Enrolment id.
	 * @param  string               $template_id  matricula_baixa_programada_aviso | matricula_baixa_anulada_aviso.
	 * @param  array<string,string> $extra        Extra variables (data_efectiva).
	 * @return array<string,int>|null
	 */
	public static function avisar_empresa( int $matricula_id, string $template_id, array $extra = array() ): ?array {
		try {
			$row = ANPA_Socios_Alumnos_Export::row_panel_matricula( $matricula_id );
			if ( null === $row ) {
				return null;
			}
			$dest = ANPA_Socios_Aviso_Matricula::destinatarios( (string) $row['empresa_email'], '' );
			if ( array() === $dest ) {
				return null;
			}
			return self::enviar_masivo( $dest, $template_id, $extra + ANPA_Socios_Aviso_Matricula::contexto( $row ) );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Telegram notice of each step of a reported baixa (fixed text, no family data).
	 *
	 * @since  1.91.0
	 * @param  string $fase   programada | efectiva | anulada.
	 * @param  bool   $oferta Effective: whether the place was offered to the waiting list.
	 * @return void
	 */
	public static function telegram_baixa_programada( string $fase, bool $oferta = false ): void {
		$url = self::xestion_url( 'matriculas' );
		if ( 'programada' === $fase ) {
			/* translators: %d: hours */
			self::telegram( ANPA_Socios_Avisos_Xunta::BAIXA_EMPRESA, __( 'Baixa comunicada pola empresa', 'anpa-socios' ), sprintf( __( 'Rexistrouse a baixa dunha extraescolar que comunicou a empresa. Será efectiva en %d horas salvo que a familia indique que é un erro.', 'anpa-socios' ), ANPA_Socios_Baixa_Programada::PRAZO_HORAS ), $url );
		} elseif ( 'efectiva' === $fase ) {
			self::telegram( ANPA_Socios_Avisos_Xunta::BAIXA_EMPRESA, __( 'Baixa definitiva dunha extraescolar', 'anpa-socios' ), $oferta ? __( 'A baixa comunicada pola empresa xa é efectiva. A praza ofreceuse ao seguinte da lista de espera.', 'anpa-socios' ) : __( 'A baixa comunicada pola empresa xa é efectiva. Non había ninguén na lista de espera para ofrecerlle a praza.', 'anpa-socios' ), $url );
		} elseif ( 'anulada' === $fase ) {
			self::telegram( ANPA_Socios_Avisos_Xunta::BAIXA_EMPRESA, __( 'Baixa anulada', 'anpa-socios' ), __( 'Anulouse unha baixa comunicada pola empresa: a matrícula segue activa e avisouse á empresa.', 'anpa-socios' ), $url );
		}
	}

	/**
	 * Start-of-year email, addressed to the families but delivered to ONE inbox
	 * (the junta's) for forwarding to the Google Contacts label.
	 *
	 * @since  1.62.0
	 * @param  string $to Recipient (the junta's account).
	 * @return bool
	 */
	public static function enviar_inicio_curso( string $to, array $datas = array() ): bool {
		// 1.93.0: the email also gives the end of the initial enrolment period and the first day
		// of the activities (the separate «prazo_matriculas» reminder is gone).
		$ok = self::send_template( $to, 'inicio_curso', array(
			'data_peche'              => (string) ( $datas['peche'] ?? '' ),
			'data_inicio_actividades' => (string) ( $datas['inicio_actividades'] ?? '' ),
		) + self::links_context() );
		if ( strtolower( trim( $to ) ) === self::junta_email() ) {
			self::telegram( ANPA_Socios_Avisos_Xunta::INICIO_CURSO, __( 'Correo de inicio de curso', 'anpa-socios' ), __( 'Chegou á conta da xunta o correo de inicio de curso para reenvialo ás familias desde Gmail.', 'anpa-socios' ), self::xestion_url( 'lista-gmail' ) );
		}
		return $ok;
	}

	/**
	 * Links shared by the family-facing templates: area, web, instructions post
	 * and public activities page (Axustes → Xeral), each with a sensible fallback
	 * so no link is ever empty.
	 *
	 * @since  1.68.0
	 * @return array<string,string>
	 */
	public static function links_context(): array {
		$login_url = class_exists( 'ANPA_Socios_Admin_Settings' ) ? ANPA_Socios_Admin_Settings::landing_page_url() : '';
		// 1.63.0: the instructions post and the public activities page come from
		// Axustes → Xeral; both fall back to something sensible so no link is empty.
		$instrucions = ANPA_Socios_Config::instrucions_url();
		$extra       = ANPA_Socios_Config::extraescolares_url();
		if ( '' === $extra && class_exists( 'ANPA_Socios_Hub_Page' ) ) {
			$extra = (string) ANPA_Socios_Hub_Page::find_page_url( 'anpa_extraescolares_ofertadas' );
		}
		return array(
			'login_url'          => $login_url,
			'web_url'            => home_url( '/' ),
			'instrucions_url'    => '' !== $instrucions ? $instrucions : $login_url,
			'extraescolares_url' => '' !== $extra ? $extra : home_url( '/' ),
		);
	}

	// ──────────────────────────────────────────────
	// 1.68.0: mass email to the families (Xestión → Matrículas, Grupos e horarios)
	// ──────────────────────────────────────────────

	/**
	 * Sends one templated message to many families: the visible recipient is
	 * the junta's own address and the families go in Bcc, in batches of
	 * ANPA_Socios_Envio_Masivo::TAMANO_LOTE so no message exceeds the recipient
	 * limit of Gmail or of the usual SMTP relays. Renders once, never throws.
	 *
	 * @since  1.68.0
	 * @param  array<int,string>    $emails      Family addresses (deduped and validated here).
	 * @param  string               $template_id Template id in the store.
	 * @param  array<string,string> $context     Variables (links and family context are merged in).
	 * @return array{lotes:int,enviados:int,fallidos:int,lotes_fallidos:int,destinatarios:int}
	 */
	public static function enviar_masivo( array $emails, string $template_id, array $context = array() ): array {
		$lotes         = ANPA_Socios_Envio_Masivo::lotes( $emails );
		$destinatarios = 0;
		foreach ( $lotes as $lote ) {
			$destinatarios += count( $lote );
		}
		if ( array() === $lotes ) {
			return ANPA_Socios_Envio_Masivo::resumo( array() ) + array( 'destinatarios' => 0 );
		}
		$content = ANPA_Socios_Email_Template_Renderer::render( $template_id, array_merge( self::family_context(), self::links_context(), $context ) );
		$subject = (string) $content['subject'];
		$html    = (string) $content['html'];
		if ( '' === trim( $subject ) || '' === trim( $html ) ) {
			return ANPA_Socios_Envio_Masivo::resumo( array_map( static function ( array $l ): array { return array( 'ok' => false, 'n' => count( $l ) ); }, $lotes ) ) + array( 'destinatarios' => $destinatarios );
		}
		$to         = self::junta_email();
		$resultados = array();
		foreach ( $lotes as $lote ) {
			$ok = false;
			try {
				$ok = self::send_from_master( $to, $subject, $html, array( ANPA_Socios_Envio_Masivo::cabeceira_bcc( $lote ) ) );
			} catch ( \Throwable $e ) {
				$ok = false;
			}
			$resultados[] = array( 'ok' => (bool) $ok, 'n' => count( $lote ) );
		}
		$resumo = ANPA_Socios_Envio_Masivo::resumo( $resultados );
		// 1.90.0: one summary line for the whole send, not one per batch.
		self::telegram( ANPA_Socios_Avisos_Xunta::id_masivo( $template_id ), __( 'Correo masivo', 'anpa-socios' ), ANPA_Socios_Telegram::resumo_masivo( $template_id, $resumo ), self::xestion_url( 'correos' ) );
		return $resumo + array( 'destinatarios' => $destinatarios );
	}

	/**
	 * The family requested a place while the trimester window was closed: the
	 * request is pending the junta's approval.
	 *
	 * @since  1.68.0
	 * @param  string $email_socio Family address.
	 * @param  string $alumno      Pupil name.
	 * @param  string $actividade  Activity name.
	 * @param  string $grupo       Group label (name, schedule).
	 * @return bool
	 */
	public static function enviar_matricula_pendente( string $email_socio, string $alumno, string $actividade, string $grupo, string $estado_grupo = '' ): bool {
		return self::send_template( $email_socio, 'matricula_pendente', array( 'alumno' => $alumno, 'actividade' => $actividade, 'grupo' => $grupo, 'estado_grupo' => $estado_grupo ) );
	}

	/**
	 * 1.92.0: tells the junta that an enrolment waits in Operacións →
	 * Aprobacións: a new request (outside the free enrolment window or in a
	 * group already running) or a waiting-list offer the family accepted.
	 * Email and/or Telegram as chosen in Axustes → Xeral → Avisos á xunta.
	 * Before 1.92.0 neither reached the junta until the Monday summary.
	 *
	 * @since  1.92.0
	 * @param  string $email_socio Address of the parent who asked.
	 * @param  string $alumno      Pupil name.
	 * @param  string $actividade  Activity name.
	 * @param  string $grupo       Group label.
	 * @param  bool   $de_oferta   True when the family accepted an offer.
	 * @return bool                True if the email was accepted.
	 */
	public static function enviar_aviso_matricula_pendente_xunta( string $email_socio, string $alumno, string $actividade, string $grupo, bool $de_oferta = false, int $grupo_id = 0 ): bool {
		$aviso_id = $de_oferta ? ANPA_Socios_Avisos_Xunta::OFERTA_ACEPTADA : ANPA_Socios_Avisos_Xunta::MATRICULA_PENDENTE;
		$ok       = false;
		try {
			if ( self::correo_xunta( $aviso_id ) ) {
				// 1.93.0: the group's state with the numbers, and when the automatic approval happens.
				$ctx_grupo = $grupo_id > 0 ? self::contexto_grupo( $grupo_id ) : array( 'xunta' => '' );
				$ok = self::send_template(
					self::junta_email(),
					$de_oferta ? 'oferta_aceptada_xunta' : 'matricula_pendente_xunta',
					array(
						'alumno'                => $alumno,
						'actividade'            => $actividade,
						'grupo'                 => $grupo,
						'email_socio'           => $email_socio,
						'xestion_url'           => self::xestion_url(),
						'estado_grupo'          => '' !== $ctx_grupo['xunta'] ? $ctx_grupo['xunta'] : '—',
						'aprobacion_automatica' => self::texto_aprobacion_automatica(),
					)
				);
			}
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		if ( $de_oferta ) {
			self::telegram( $aviso_id, __( 'Oferta de praza aceptada', 'anpa-socios' ), __( 'Unha familia aceptou a praza ofrecida dende a lista de espera. Confírmaa en Aprobacións.', 'anpa-socios' ) );
		} else {
			self::telegram( $aviso_id, __( 'Nova inscrición nunha extraescolar', 'anpa-socios' ), __( 'Unha familia inscribiu a un alumno/a nunha actividade e a solicitude espera a vosa aprobación en Aprobacións.', 'anpa-socios' ) );
		}
		return $ok;
	}

	/**
	 * 1.93.0: the line about the automatic approval in the junta notice.
	 *
	 * @since  1.93.0
	 * @return string
	 */
	public static function texto_aprobacion_automatica(): string {
		if ( ! ANPA_Socios_Config::matricula_aprobacion_automatica() ) {
			return __( 'Non: só se aproba en Aprobacións.', 'anpa-socios' );
		}
		$horas = ANPA_Socios_Config::matricula_aprobacion_horas();
		$cando = function_exists( 'wp_date' ) ? (string) wp_date( 'd/m/Y H:i', time() + $horas * HOUR_IN_SECONDS ) : '';
		return sprintf(
			/* translators: 1: date and time, 2: hours */
			__( 'Si: aprobarase soa arredor do %1$s (%2$d horas) se non a revisades antes.', 'anpa-socios' ),
			$cando,
			$horas
		);
	}

	/**
	 * The junta approved the pending request and there was a place.
	 *
	 * @since  1.68.0
	 * @return bool
	 */
	public static function enviar_matricula_aprobada_praza( string $email_socio, string $alumno, string $actividade, string $grupo, string $estado_grupo = '' ): bool {
		return self::send_template( $email_socio, 'matricula_aprobada_praza', array( 'alumno' => $alumno, 'actividade' => $actividade, 'grupo' => $grupo, 'estado_grupo' => $estado_grupo ) + self::links_context() );
	}

	/**
	 * 1.72.0: whether the enrolment's group was already created («grupo creado»
	 * notice sent or marked) in an active course. Only then do per-pupil
	 * notices go out; the creation itself is the mass notice.
	 *
	 * @since  1.72.0
	 * @param  array<string,mixed>|null $row ANPA_Socios_Alumnos_Export::row_panel_matricula() row.
	 * @return bool
	 */
	public static function e_grupo_creado( ?array $row ): bool {
		if ( null === $row ) {
			return false;
		}
		$gate = ANPA_Socios_Matricula_Gate_Repo::para_curso( (string) $row['curso_escolar'] );
		return ANPA_Socios_Aviso_Matricula::grupo_creado( (string) ( $gate['estado_curso'] ?? '' ), isset( $row['grupo_aviso_en'] ) ? (string) $row['grupo_aviso_en'] : null );
	}

	/**
	 * @since  1.72.0
	 * @param  int $matricula_id Enrolment id.
	 * @return bool Whether the enrolment's group was already created.
	 */
	public static function matricula_en_grupo_creado( int $matricula_id ): bool {
		return self::e_grupo_creado( ANPA_Socios_Alumnos_Export::row_panel_matricula( $matricula_id ) );
	}

	/**
	 * 1.71.0: tells the company of the activity and the canteen that a pupil
	 * joined (PLANTILLA_ALTA) or left (PLANTILLA_BAIXA) a group, with the
	 * pupil's data and both parents' contact. One message: To the junta, Bcc
	 * company + canteen. Only for a group already created (1.72.0); best-effort,
	 * never throws.
	 *
	 * @since  1.71.0
	 * @param  int                  $matricula_id Enrolment id.
	 * @param  string               $template_id  ANPA_Socios_Aviso_Matricula::PLANTILLA_*.
	 * @param  array<string,string> $extra        Extra variables (e.g. efectos).
	 * @return array<string,int>|null Mass-send summary, or null when nothing was sent.
	 */
	public static function avisar_empresa_comedor( int $matricula_id, string $template_id, array $extra = array() ): ?array {
		try {
			$row = ANPA_Socios_Alumnos_Export::row_panel_matricula( $matricula_id );
			if ( ! self::e_grupo_creado( $row ) ) {
				return null;
			}
			return self::enviar_aviso_empresa_comedor( $row, $template_id, $extra );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * 1.72.0: a pupil moved to another group of the activity (Xestión → Mover).
	 * «Cambio de grupo» when the pupil was in the origin group and either group
	 * was created; «Alta no grupo» when moved from the waiting list into a
	 * created group. Best-effort, never throws.
	 *
	 * @since  1.72.0
	 * @param  int    $matricula_id   Enrolment id (already in the destination).
	 * @param  int    $grupo_orixe_id Origin group id.
	 * @param  string $estado_previo  Enrolment state before the move.
	 * @return array<string,int>|null
	 */
	public static function avisar_cambio_grupo( int $matricula_id, int $grupo_orixe_id, string $estado_previo ): ?array {
		global $wpdb;
		try {
			$row = ANPA_Socios_Alumnos_Export::row_panel_matricula( $matricula_id );
			if ( null === $row ) {
				return null;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only origin group lookup.
			$orixe = $wpdb->get_row( $wpdb->prepare( 'SELECT nome, franxa, dias, aviso_comezo_en FROM ' . ANPA_Socios_DB::tabela_grupos() . ' WHERE id = %d', $grupo_orixe_id ), ARRAY_A );
			$gate  = ANPA_Socios_Matricula_Gate_Repo::para_curso( (string) $row['curso_escolar'] );
			$curso = (string) ( $gate['estado_curso'] ?? '' );
			$orixe_creado = is_array( $orixe ) && ANPA_Socios_Aviso_Matricula::grupo_creado( $curso, null === $orixe['aviso_comezo_en'] ? null : (string) $orixe['aviso_comezo_en'] );
			// No origin group (e.g. an enrolment created by the admin API without a group): it is an alta.
			$tipo = ANPA_Socios_Aviso_Matricula::tipo_movemento( is_array( $orixe ) ? $estado_previo : 'lista_espera', $orixe_creado, self::e_grupo_creado( $row ) );
			if ( 'cambio' === $tipo ) {
				$label = is_array( $orixe ) ? ANPA_Socios_Aviso_Matricula::grupo_label( (string) $orixe['nome'], (string) $orixe['franxa'], (string) $orixe['dias'] ) : '';
				return self::enviar_aviso_empresa_comedor( $row, ANPA_Socios_Aviso_Matricula::PLANTILLA_CAMBIO, array( 'grupo_orixe' => '' !== $label ? $label : '—' ) );
			}
			if ( 'alta' === $tipo ) {
				return self::enviar_aviso_empresa_comedor( $row, ANPA_Socios_Aviso_Matricula::PLANTILLA_ALTA );
			}
			return null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * One message To the junta, Bcc the activity's company and the canteen.
	 *
	 * @param  array<string,mixed>  $row         row_panel_matricula() row.
	 * @param  string               $template_id Template id.
	 * @param  array<string,string> $extra       Extra variables.
	 * @return array<string,int>|null Null when there is no recipient.
	 */
	private static function enviar_aviso_empresa_comedor( array $row, string $template_id, array $extra = array() ): ?array {
		$dest = ANPA_Socios_Aviso_Matricula::destinatarios( (string) $row['empresa_email'], ANPA_Socios_Config::comedor_email() );
		if ( array() === $dest ) {
			return null;
		}
		return self::enviar_masivo( $dest, $template_id, $extra + ANPA_Socios_Aviso_Matricula::contexto( $row ) );
	}

	/**
	 * 1.74.0: Monday reminder to the junta of what waits in Operacións → Aprobacións.
	 *
	 * @since  1.74.0
	 * @param  array<string,int>               $contas    ANPA_Socios_Admin_Approvals_Handler::contas_pendentes().
	 * @param  array<int,array<string,string>> $conflitos 1.77.0: ANPA_Socios_Admin_Approvals_Handler::conflitos_correos().
	 * @return bool
	 */
	public static function enviar_aprobacions_pendentes( array $contas, array $conflitos = array() ): bool {
		$url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . ANPA_Socios_Admin_Management_Page::MANAGEMENT_SLUG . '&section=aprobacions' ) : '';
		$ok = self::correo_xunta( ANPA_Socios_Avisos_Xunta::RESUMO_SEMANAL ) && self::send_template( self::junta_email(), 'aprobacions_pendentes_semanal', array(
			'total'       => (string) (int) ( $contas['total'] ?? 0 ),
			'socios'      => (string) (int) ( $contas['socios'] ?? 0 ),
			'matriculas'  => (string) (int) ( $contas['matriculas'] ?? 0 ),
			'baixas'      => (string) (int) ( $contas['baixas'] ?? 0 ),
			'xestion_url' => $url,
			'revision_correos' => ANPA_Socios_Aviso_Semanal::revision_correos( $conflitos ),
		) );
		self::telegram(
			ANPA_Socios_Avisos_Xunta::RESUMO_SEMANAL,
			__( 'Revisión semanal de Xestión', 'anpa-socios' ),
			/* translators: 1: total, 2: member signups, 3: enrolments, 4: member baixas */
			sprintf( __( 'Hai %1$d cousas pendentes en Aprobacións: %2$d altas de socios/as, %3$d matrículas e %4$d baixas.', 'anpa-socios' ), (int) ( $contas['total'] ?? 0 ), (int) ( $contas['socios'] ?? 0 ), (int) ( $contas['matriculas'] ?? 0 ), (int) ( $contas['baixas'] ?? 0 ) ),
			$url
		);
		return $ok;
	}

	/**
	 * The junta approved the pending request but the group is full: waiting list.
	 *
	 * @since  1.68.0
	 * @return bool
	 */
	/**
	 * An offer not accepted in time (or turned down): back at the end of the waiting list (1.85.0).
	 *
	 * @since  1.85.0
	 * @return bool
	 */
	public static function enviar_oferta_final_lista( string $email_socio, string $alumno, string $actividade, string $grupo, int $posicion, bool $rexeitada ): bool {
		$motivo = $rexeitada ? __( 'rexeitastes a oferta', 'anpa-socios' ) : __( 'non se aceptou a oferta no prazo', 'anpa-socios' );
		return self::send_template( $email_socio, 'oferta_final_lista', array( 'motivo' => $motivo, 'alumno' => $alumno, 'actividade' => $actividade, 'grupo' => $grupo, 'posicion' => (string) $posicion ) );
	}

	public static function enviar_matricula_aprobada_espera( string $email_socio, string $alumno, string $actividade, string $grupo, int $posicion, string $estado_grupo = '' ): bool {
		return self::send_template( $email_socio, 'matricula_aprobada_espera', array( 'alumno' => $alumno, 'actividade' => $actividade, 'grupo' => $grupo, 'posicion' => (string) $posicion, 'estado_grupo' => $estado_grupo ) );
	}

	/**
	 * The junta rejected the pending request.
	 *
	 * @since  1.68.0
	 * @return bool
	 */
	public static function enviar_matricula_rexeitada( string $email_socio, string $alumno, string $actividade ): bool {
		return self::send_template( $email_socio, 'matricula_rexeitada', array( 'alumno' => $alumno, 'actividade' => $actividade ) );
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

		$content = self::render_with_template(
			'pendente_aprobacion',
			array(
				'association_name' => $assoc,
				'nome'             => $nome,
				'email_socio'      => $email_socio,
				'login_url'        => $settings_url,
			),
			function () use ( $assoc, $email_socio, $nome, $settings_url ) {
				$subj = sprintf( __( 'Novo socio/a pendente de aprobación — %s', 'anpa-socios' ), $assoc );
				$html = '<!DOCTYPE html>'
					. '<html><head><meta charset="UTF-8"></head>'
					. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
					. '<h2>' . esc_html__( 'Novo socio/a pendente de aprobación', 'anpa-socios' ) . '</h2>'
					. '<p>' . sprintf( esc_html__( 'Unha nova persoa solicitou darse de alta en %s e precisa da túa aprobación:', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
					. '<ul>'
					. '<li><strong>' . esc_html__( 'Nome:', 'anpa-socios' ) . '</strong> ' . esc_html( '' !== trim( $nome ) ? $nome : __( '(sen nome)', 'anpa-socios' ) ) . '</li>'
					. '<li><strong>' . esc_html__( 'Email:', 'anpa-socios' ) . '</strong> ' . esc_html( $email_socio ) . '</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'Revisa e aproba (ou rexeita) as solicitudes pendentes na sección de Xestión ANPA da área de socios, ou na páxina de Axustes do plugin:', 'anpa-socios' ) . '</p>'
					. '<p><a href="' . esc_url( $settings_url ) . '">' . esc_html( $settings_url ) . '</a></p>'
					. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
					. '</body></html>';
				return array(
					'subject' => $subj,
					'html'    => $html,
					'text'    => wp_strip_all_tags( $html ),
				);
			}
		);

		$ok = self::correo_xunta( ANPA_Socios_Avisos_Xunta::ALTA_SOCIO ) && self::send_from_master( ANPA_Socios_Config::master_email(), $content['subject'], $content['html'] );
		self::telegram( ANPA_Socios_Avisos_Xunta::ALTA_SOCIO, __( 'Novo socio/a pendente de aprobación', 'anpa-socios' ), __( 'Unha persoa nova pediu a alta e espera a vosa aprobación en Aprobacións.', 'anpa-socios' ) );
		return $ok;
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

		$content = self::render_with_template(
			'aprobacion',
			array(
				'association_name' => $assoc,
				'login_url'        => $login_url,
			),
			function () use ( $assoc, $login_url ) {
				$subj = sprintf( __( 'A túa alta foi aprobada — %s', 'anpa-socios' ), $assoc );
				$html = '<!DOCTYPE html>'
					. '<html><head><meta charset="UTF-8"></head>'
					. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
					. '<h2>' . sprintf( esc_html__( 'Benvido/a a %s', 'anpa-socios' ), esc_html( $assoc ) ) . '</h2>'
					. '<p>' . sprintf( esc_html__( 'A directiva de %s aprobou a túa alta como socio/a. Xa podes acceder á túa área persoal.', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
					. ( '' !== $login_url
						? '<p>' . esc_html__( 'Entra na área de socios e inicia sesión co teu correo (recibirás un código de acceso):', 'anpa-socios' ) . '</p>'
							. '<p><a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a></p>'
						: '<p>' . esc_html__( 'Entra na área de socios da web e inicia sesión co teu correo; recibirás un código de acceso.', 'anpa-socios' ) . '</p>' )
					. '<p>' . esc_html__( 'Desde a túa área persoal poderás xestionar os teus datos, os teus fillos/as e as actividades extraescolares.', 'anpa-socios' ) . '</p>'
					. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
					. '</body></html>';
				return array(
					'subject' => $subj,
					'html'    => $html,
					'text'    => wp_strip_all_tags( $html ),
				);
			}
		);

		return self::send_from_master( $email_socio, $content['subject'], $content['html'] );
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

		$content = self::render_with_template(
			'benvida_alta',
			array(
				'association_name' => $assoc,
				'login_url'        => $login_url,
			),
			function () use ( $assoc, $login_url ) {
				$subj = sprintf( __( 'Alta completada — %s', 'anpa-socios' ), $assoc );
				$html = '<!DOCTYPE html>'
					. '<html><head><meta charset="UTF-8"></head>'
					. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
					. '<h2>' . sprintf( esc_html__( 'Benvido/a a %s', 'anpa-socios' ), esc_html( $assoc ) ) . '</h2>'
					. '<p>' . sprintf( esc_html__( 'A túa alta como socio/a en %s completouse correctamente. Xa podes acceder á túa área persoal.', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
					. ( '' !== $login_url
						? '<p>' . esc_html__( 'Entra na área de socios e inicia sesión co teu correo (recibirás un código de acceso):', 'anpa-socios' ) . '</p>'
							. '<p><a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a></p>'
						: '<p>' . esc_html__( 'Entra na área de socios da web e inicia sesión co teu correo; recibirás un código de acceso.', 'anpa-socios' ) . '</p>' )
					. '<p>' . esc_html__( 'Desde a túa área persoal poderás xestionar os teus datos, os teus fillos/as e as actividades extraescolares.', 'anpa-socios' ) . '</p>'
					. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
					. '</body></html>';
				return array(
					'subject' => $subj,
					'html'    => $html,
					'text'    => wp_strip_all_tags( $html ),
				);
			}
		);

		return self::send_from_master( $email_socio, $content['subject'], $content['html'] );
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

		$content = self::render_with_template(
			'rexeitamento',
			array(
				'association_name' => $assoc,
				'contact_email'    => $contact,
			),
			function () use ( $assoc, $contact ) {
				$subj = sprintf( __( 'Sobre a túa solicitude de alta — %s', 'anpa-socios' ), $assoc );
				$html = '<!DOCTYPE html>'
					. '<html><head><meta charset="UTF-8"></head>'
					. '<body style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
					. '<h2>' . esc_html__( 'Solicitude de alta', 'anpa-socios' ) . '</h2>'
					. '<p>' . sprintf( esc_html__( 'Sentímolo, pero por decisión da directiva de %s non se aprobou a túa solicitude de alta como socio/a neste momento.', 'anpa-socios' ), esc_html( $assoc ) ) . '</p>'
					. '<p>' . sprintf(
						__( 'Se pensas que se trata dun erro ou queres máis información, ponte en contacto con nós respondendo a este correo ou escribindo a %s.', 'anpa-socios' ),
						'<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>'
					) . '</p>'
					. '<p style="color: #666; font-size: 12px; margin-top: 30px;">' . esc_html__( 'Aviso automático do sistema de socios.', 'anpa-socios' ) . '</p>'
					. '</body></html>';
				return array(
					'subject' => $subj,
					'html'    => $html,
					'text'    => wp_strip_all_tags( $html ),
				);
			}
		);

		return self::send_from_master( $email_socio, $content['subject'], $content['html'] );
	}
}
