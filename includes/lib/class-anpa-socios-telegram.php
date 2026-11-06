<?php
/**
 * 1.90.0: Telegram copy of the emails the web sends to the junta's inbox.
 *
 * Pure helpers (no WordPress): the validation of the bot token and the chat
 * id, and the text of the message. 1.92.0: which notices go to Telegram is
 * chosen per notice in ANPA_Socios_Avisos_Xunta.
 * Telegram is not a provider contracted by the ANPA, so the message never
 * carries family data: a fixed title, a short line and the Xestión link.
 * The sending lives in ANPA_Socios_Telegram_Xunta.
 *
 * @since   1.90.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telegram notice rules and formatting.
 *
 * @since 1.90.0
 */
final class ANPA_Socios_Telegram {

	/** Longest text Telegram accepts in one sendMessage. */
	const MAX_TEXTO = 4096;

	/**
	 * Whether the string looks like a BotFather token («123456:ABC…»).
	 *
	 * @param  string $token Candidate.
	 * @return bool
	 */
	public static function token_valido( string $token ): bool {
		return 1 === preg_match( '/^\d{5,}:[A-Za-z0-9_-]{30,}$/', trim( $token ) );
	}

	/**
	 * Normalised chat id: a number (negative for groups and channels) or a
	 * public «@canle»; '' when it is neither.
	 *
	 * @param  string $chat Candidate.
	 * @return string
	 */
	public static function chat_id( string $chat ): string {
		$chat = trim( $chat );
		if ( 1 === preg_match( '/^-?\d{1,20}$/', $chat ) || 1 === preg_match( '/^@[A-Za-z][A-Za-z0-9_]{4,31}$/', $chat ) ) {
			return $chat;
		}
		return '';
	}

	/**
	 * Token shown in Axustes: only the bot number and the last four characters.
	 *
	 * @param  string $token Token.
	 * @return string
	 */
	public static function token_mascarado( string $token ): string {
		$token = trim( $token );
		if ( '' === $token ) {
			return '';
		}
		$pos = strpos( $token, ':' );
		$bot = false === $pos ? '' : substr( $token, 0, $pos );
		return $bot . ':…' . substr( $token, -4 );
	}

	/**
	 * Bot API endpoint for a method.
	 *
	 * @param  string $token  Bot token.
	 * @param  string $metodo API method (sendMessage, getMe…).
	 * @return string
	 */
	public static function api_url( string $token, string $metodo ): string {
		return 'https://api.telegram.org/bot' . trim( $token ) . '/' . $metodo;
	}

	/**
	 * Plain-text message: association, title, short line and the link.
	 *
	 * @param  string $asociacion Association name.
	 * @param  string $titulo     Fixed title of the notice (subject without data).
	 * @param  string $aviso      Short line (no family data).
	 * @param  string $url        Xestión link ('' to omit).
	 * @return string
	 */
	public static function texto( string $asociacion, string $titulo, string $aviso = '', string $url = '' ): string {
		$linas = array();
		$cab   = trim( $titulo );
		if ( '' !== trim( $asociacion ) ) {
			$cab = trim( $asociacion ) . ' · ' . $cab;
		}
		$linas[] = '📬 ' . $cab;
		if ( '' !== trim( $aviso ) ) {
			$linas[] = trim( $aviso );
		}
		if ( '' !== trim( $url ) ) {
			$linas[] = trim( $url );
		}
		$texto = implode( "\n", $linas );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $texto ) > self::MAX_TEXTO ) {
			$texto = mb_substr( $texto, 0, self::MAX_TEXTO - 1 ) . '…';
		}
		return $texto;
	}

	/**
	 * Name of a mass email for the summary line, from its template. The
	 * rendered subject is not used: the notices to the company carry the
	 * pupil's name in it.
	 *
	 * @param  string $template_id Template id.
	 * @return string
	 */
	public static function titulo_masivo( string $template_id ): string {
		$t = array(
			'inicio_curso'                     => __( 'Comezo do curso', 'anpa-socios' ),
			'prazo_matriculas'                 => __( 'Prazo de matrículas', 'anpa-socios' ),
			'matriculas_abertas'               => __( 'Matrículas abertas', 'anpa-socios' ),
			'matriculas_pechadas'              => __( 'Matrículas pechadas', 'anpa-socios' ),
			'fin_curso'                        => __( 'Fin de curso', 'anpa-socios' ),
			'grupo_comezo_trimestre'           => __( 'Grupo creado: inscritos/as', 'anpa-socios' ),
			'grupo_comezo_espera'              => __( 'Grupo creado: lista de espera', 'anpa-socios' ),
			'grupo_pechado_minimo'             => __( 'Grupo pechado por non acadar o mínimo', 'anpa-socios' ),
			'grupo_sen_minimo'                 => __( 'Grupo sen mínimo', 'anpa-socios' ),
			'matricula_alta_aviso'             => __( 'Aviso á empresa/comedor: alta nun grupo', 'anpa-socios' ),
			'matricula_baixa_aviso'            => __( 'Aviso á empresa/comedor: baixa nun grupo', 'anpa-socios' ),
			'matricula_cambio_grupo_aviso'     => __( 'Aviso á empresa/comedor: cambio de grupo', 'anpa-socios' ),
			'matricula_baixa_programada_aviso' => __( 'Aviso á empresa: baixa rexistrada', 'anpa-socios' ),
			'matricula_baixa_anulada_aviso'    => __( 'Aviso á empresa: baixa anulada', 'anpa-socios' ),
		);
		return $t[ $template_id ] ?? $template_id;
	}

	/**
	 * One summary line for a mass email that went to the junta with the
	 * recipients in Bcc (never the addresses or the subject).
	 *
	 * @param  string                                        $template_id Template id.
	 * @param  array{lotes?:int,enviados?:int,fallidos?:int} $resumo      ANPA_Socios_Envio_Masivo::resumo().
	 * @return string
	 */
	public static function resumo_masivo( string $template_id, array $resumo ): string {
		$enviados = (int) ( $resumo['enviados'] ?? 0 );
		$fallidos = (int) ( $resumo['fallidos'] ?? 0 );
		/* translators: 1: name of the mass email, 2: number of addresses */
		$linea = sprintf( __( '«%1$s» enviado en copia oculta a %2$d enderezos.', 'anpa-socios' ), self::titulo_masivo( $template_id ), $enviados );
		if ( $fallidos > 0 ) {
			/* translators: %d: addresses that failed */
			$linea .= ' ' . sprintf( __( 'Non se puido enviar a %d.', 'anpa-socios' ), $fallidos );
		}
		return $linea;
	}
}
