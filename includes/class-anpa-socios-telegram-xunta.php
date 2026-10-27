<?php
/**
 * 1.90.0: sends the Telegram copy of the emails to the junta's inbox.
 *
 * Best-effort like the emails themselves: a missing configuration or a
 * failing Telegram never breaks the request that raised the notice. Only a
 * fixed title, a short line and the Xestión link travel to Telegram (see
 * ANPA_Socios_Telegram).
 *
 * @since   1.90.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telegram notifier for the junta.
 *
 * @since 1.90.0
 */
final class ANPA_Socios_Telegram_Xunta {

	/**
	 * Whether a bot token and a chat are configured.
	 *
	 * @return bool
	 */
	public static function configurado(): bool {
		$bot = ANPA_Socios_Config::telegram_bot();
		return ANPA_Socios_Telegram::token_valido( $bot['token'] ) && '' !== ANPA_Socios_Config::telegram_chat_id();
	}

	/**
	 * Copies one junta email to Telegram when the configured mode includes it.
	 *
	 * @param  string $tipo   ANPA_Socios_Telegram::TIPO_AVISO or TIPO_OUTRO.
	 * @param  string $titulo Fixed title (never family data).
	 * @param  string $aviso  Short line (never family data).
	 * @param  string $url    Xestión link ('' = the Aprobacións section).
	 * @return bool           True when the message was handed to Telegram.
	 */
	public static function avisar( string $tipo, string $titulo, string $aviso = '', string $url = '' ): bool {
		if ( ! self::configurado() || ! ANPA_Socios_Telegram::debe_notificar( ANPA_Socios_Config::telegram_modo(), $tipo ) ) {
			return false;
		}
		$texto = ANPA_Socios_Telegram::texto( ANPA_Socios_Config::association_name(), $titulo, $aviso, '' !== $url ? $url : self::xestion_url() );
		// Families wait on some of these requests (a baixa, an alta): do not hold them.
		return true === self::enviar( $texto, false );
	}

	/**
	 * Test message from Axustes; waits for Telegram's answer.
	 *
	 * @return true|string True, or the error to show.
	 */
	public static function proba() {
		if ( ! self::configurado() ) {
			return __( 'Falta o token do bot ou o chat de Telegram.', 'anpa-socios' );
		}
		$texto = ANPA_Socios_Telegram::texto(
			ANPA_Socios_Config::association_name(),
			__( 'Mensaxe de proba', 'anpa-socios' ),
			__( 'Se ves isto, os avisos da web á xunta chegarán tamén aquí.', 'anpa-socios' ),
			self::xestion_url()
		);
		return self::enviar( $texto, true );
	}

	/**
	 * Xestión → Operacións → Aprobacións, where every junta notice is resolved.
	 *
	 * @param  string $section Xestión section slug.
	 * @return string
	 */
	public static function xestion_url( string $section = 'aprobacions' ): string {
		if ( ! function_exists( 'admin_url' ) || ! class_exists( 'ANPA_Socios_Admin_Management_Page' ) ) {
			return '';
		}
		return admin_url( 'admin.php?page=' . ANPA_Socios_Admin_Management_Page::MANAGEMENT_SLUG . '&section=' . $section );
	}

	/**
	 * sendMessage to the configured chat. Never throws.
	 *
	 * @param  string $texto    Plain text.
	 * @param  bool   $esperar  Wait for the answer (test) or fire and forget (notices).
	 * @return true|string      True, or an error message.
	 */
	private static function enviar( string $texto, bool $esperar ) {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return 'wp_remote_post';
		}
		$bot = ANPA_Socios_Config::telegram_bot();
		try {
			$resposta = wp_remote_post(
				ANPA_Socios_Telegram::api_url( $bot['token'], 'sendMessage' ),
				array(
					'timeout'  => $esperar ? 10 : 3,
					'blocking' => $esperar,
					'body'     => array(
						'chat_id'                  => ANPA_Socios_Config::telegram_chat_id(),
						'text'                     => $texto,
						'disable_web_page_preview' => 'true',
					),
				)
			);
		} catch ( \Throwable $e ) {
			return __( 'Non se puido conectar con Telegram.', 'anpa-socios' );
		}
		if ( is_wp_error( $resposta ) ) {
			// The token is in the URL: never echo the transport error, it may quote it.
			return __( 'Non se puido conectar con Telegram.', 'anpa-socios' );
		}
		if ( ! $esperar ) {
			return true;
		}
		$corpo = json_decode( (string) wp_remote_retrieve_body( $resposta ), true );
		if ( is_array( $corpo ) && ! empty( $corpo['ok'] ) ) {
			return true;
		}
		$descricion = is_array( $corpo ) && isset( $corpo['description'] ) ? sanitize_text_field( (string) $corpo['description'] ) : '';
		return '' !== $descricion
			/* translators: %s: error returned by Telegram */
			? sprintf( __( 'Telegram rexeitou a mensaxe: %s', 'anpa-socios' ), $descricion )
			: __( 'Telegram non aceptou a mensaxe.', 'anpa-socios' );
	}
}
