<?php
/**
 * 1.90.0: sends the Telegram copy of the emails to the junta's inbox.
 * 1.92.0: per notice (ANPA_Socios_Avisos_Xunta), at the end of the request.
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
	 * 1.92.0: messages waiting for the end of the request.
	 *
	 * @var array<int,array{texto:string,aviso:string}>
	 */
	private static $cola = array();

	/**
	 * Copies one junta notice to Telegram when Axustes → Xeral → Avisos á
	 * xunta has its Telegram box ticked.
	 *
	 * 1.92.0: the message waits for the end of the request (shutdown) and is
	 * sent there waiting for Telegram's answer, so the family's request is
	 * not held and the outcome is recorded (OPTION_TELEGRAM_ESTADO). Before,
	 * a fire-and-forget request could be dropped without a trace.
	 *
	 * @param  string $aviso_id ANPA_Socios_Avisos_Xunta notice id.
	 * @param  string $titulo   Fixed title (never family data).
	 * @param  string $aviso    Short line (never family data).
	 * @param  string $url      Xestión link ('' = the Aprobacións section).
	 * @return bool             True when the message was queued.
	 */
	public static function avisar( string $aviso_id, string $titulo, string $aviso = '', string $url = '' ): bool {
		if ( ! self::configurado() || ! ANPA_Socios_Config::aviso_xunta( $aviso_id, ANPA_Socios_Avisos_Xunta::TELEGRAM ) ) {
			return false;
		}
		$texto = ANPA_Socios_Telegram::texto( ANPA_Socios_Config::association_name(), $titulo, $aviso, '' !== $url ? $url : self::xestion_url() );
		self::$cola[] = array( 'texto' => $texto, 'aviso' => $titulo );
		if ( function_exists( 'did_action' ) && function_exists( 'add_action' ) && ! did_action( 'shutdown' ) ) {
			if ( 1 === count( self::$cola ) ) {
				add_action( 'shutdown', array( __CLASS__, 'baleirar_cola' ), 100 );
			}
			return true;
		}
		self::baleirar_cola();
		return true;
	}

	/**
	 * shutdown: sends the queued messages. With PHP-FPM the response is
	 * handed to the browser first, so nobody waits for Telegram.
	 *
	 * @return void
	 */
	public static function baleirar_cola(): void {
		$cola       = self::$cola;
		self::$cola = array();
		if ( array() === $cola ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) && function_exists( 'doing_action' ) && doing_action( 'shutdown' ) ) {
			try {
				fastcgi_finish_request();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		foreach ( $cola as $msg ) {
			$resultado = self::enviar( $msg['texto'], 5 );
			self::rexistrar( $msg['aviso'], $resultado );
		}
	}

	/**
	 * Records the outcome of a notice for Axustes (never the token).
	 *
	 * @param  string      $aviso     Notice title.
	 * @param  true|string $resultado enviar() result.
	 * @return void
	 */
	private static function rexistrar( string $aviso, $resultado ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$estado = get_option( ANPA_Socios_Config::OPTION_TELEGRAM_ESTADO, array() );
		$estado = is_array( $estado ) ? $estado : array();
		$agora  = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		if ( true === $resultado ) {
			$estado['ok'] = $agora;
		} else {
			$estado['erro']    = $agora;
			$estado['aviso']   = $aviso;
			$estado['mensaxe'] = (string) $resultado;
		}
		update_option( ANPA_Socios_Config::OPTION_TELEGRAM_ESTADO, $estado, false );
	}

	/**
	 * Outcome of the last notices for Axustes.
	 *
	 * @return array{ok:string,erro:string,aviso:string,mensaxe:string}
	 */
	public static function estado(): array {
		$estado = function_exists( 'get_option' ) ? get_option( ANPA_Socios_Config::OPTION_TELEGRAM_ESTADO, array() ) : array();
		$estado = is_array( $estado ) ? $estado : array();
		return array(
			'ok'      => (string) ( $estado['ok'] ?? '' ),
			'erro'    => (string) ( $estado['erro'] ?? '' ),
			'aviso'   => (string) ( $estado['aviso'] ?? '' ),
			'mensaxe' => (string) ( $estado['mensaxe'] ?? '' ),
		);
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
		$resultado = self::enviar( $texto, 10 );
		self::rexistrar( __( 'Mensaxe de proba', 'anpa-socios' ), $resultado );
		return $resultado;
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
	 * sendMessage to the configured chat, waiting for Telegram's answer.
	 * Never throws.
	 *
	 * @param  string $texto   Plain text.
	 * @param  int    $timeout Seconds.
	 * @return true|string     True, or an error message.
	 */
	private static function enviar( string $texto, int $timeout ) {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return 'wp_remote_post';
		}
		$bot = ANPA_Socios_Config::telegram_bot();
		try {
			$resposta = wp_remote_post(
				ANPA_Socios_Telegram::api_url( $bot['token'], 'sendMessage' ),
				array(
					'timeout'  => $timeout,
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
