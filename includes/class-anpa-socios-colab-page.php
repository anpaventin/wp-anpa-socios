<?php
/**
 * Collaborators network (1.104.0): public pages.
 *
 * - [anpa_colaboradores]: shops and their published offers (cacheable).
 * - [anpa_colaboradores_acceso]: the shop's own panel (sign in, guided offer form).
 * - [anpa_colaboradores_verificar]: checks a member card (valid / not valid).
 *
 * @since  1.104.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcodes and assets of the collaborators module.
 *
 * @since 1.104.0
 */
final class ANPA_Socios_Colab_Page {

	const SC_LISTA     = 'anpa_colaboradores';
	const SC_ACCESO    = 'anpa_colaboradores_acceso';
	const SC_VERIFICAR = 'anpa_colaboradores_verificar';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::SC_LISTA, array( __CLASS__, 'render_lista' ) );
		add_shortcode( self::SC_ACCESO, array( __CLASS__, 'render_acceso' ) );
		add_shortcode( self::SC_VERIFICAR, array( __CLASS__, 'render_verificar' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * URL of the page that checks cards ('' when there is none yet).
	 *
	 * @return string
	 */
	public static function url_verificar(): string {
		return ANPA_Socios_Hub_Page::find_page_url( self::SC_VERIFICAR );
	}

	/**
	 * URL of the shops' panel page ('' when there is none yet).
	 *
	 * @return string
	 */
	public static function url_acceso(): string {
		return ANPA_Socios_Hub_Page::find_page_url( self::SC_ACCESO );
	}

	/**
	 * [anpa_colaboradores] — the public list. Prints nothing while the module
	 * is off or there is nothing published.
	 *
	 * @param  mixed $atts Unused.
	 * @return string
	 */
	public static function render_lista( $atts = array() ): string {
		unset( $atts );
		if ( ! ANPA_Socios_Colab_DB::activo() ) {
			return '';
		}
		$grupos = ANPA_Socios_Colab_Service::publicas();
		if ( array() === $grupos ) {
			return '<p class="anpa-colab-baleiro">' . esc_html__( 'Aínda non hai ofertas publicadas.', 'anpa-socios' ) . '</p>';
		}
		$html = '<div class="anpa-colab-lista">';
		foreach ( $grupos as $g ) {
			$c     = $g['comercio'];
			$html .= '<article class="anpa-colab-comercio"><header><h3>' . esc_html( $c['nome'] ) . '</h3>';
			$datos = array();
			if ( '' !== $c['enderezo'] ) {
				$datos[] = esc_html( $c['enderezo'] );
			}
			if ( '' !== $c['telefono'] ) {
				$datos[] = esc_html( $c['telefono'] );
			}
			if ( '' !== $c['url_web'] ) {
				$datos[] = '<a href="' . esc_url( $c['url_web'], array( 'http', 'https' ) ) . '" rel="noopener nofollow" target="_blank">' . esc_html__( 'Web', 'anpa-socios' ) . '</a>';
			}
			if ( array() !== $datos ) {
				$html .= '<p class="anpa-colab-datos">' . implode( ' · ', $datos ) . '</p>';
			}
			if ( '' !== $c['descricion'] ) {
				$html .= '<p class="anpa-colab-descricion">' . nl2br( esc_html( $c['descricion'] ) ) . '</p>';
			}
			$html .= '</header><ul class="anpa-colab-ofertas">';
			foreach ( $g['ofertas'] as $o ) {
				$html .= '<li class="anpa-colab-oferta"><span class="anpa-colab-desconto">' . esc_html( (string) $o['desconto'] ) . '</span>'
					. '<strong class="anpa-colab-titulo">' . esc_html( (string) $o['titulo'] ) . '</strong>'
					. '<p>' . nl2br( esc_html( (string) $o['descricion'] ) ) . '</p>';
				if ( '' !== (string) $o['condicions'] ) {
					$html .= '<p class="anpa-colab-condicions">' . nl2br( esc_html( (string) $o['condicions'] ) ) . '</p>';
				}
				$ata = (string) $o['valida_ata'];
				if ( '' !== $ata && '0000-00-00' !== $ata ) {
					/* translators: %s: date */
					$html .= '<p class="anpa-colab-vixencia">' . esc_html( sprintf( __( 'Válida ata o %s', 'anpa-socios' ), date_i18n( 'j \d\e F \d\e Y', (int) strtotime( $ata ) ) ) ) . '</p>';
				}
				$html .= '</li>';
			}
			$html .= '</ul></article>';
		}

		return $html . '</div><p class="anpa-colab-nota">' . esc_html__( 'Para gozar dunha oferta, ensina no comercio o teu carné de socio/a (na área de socios, «O meu carné»).', 'anpa-socios' ) . '</p>';
	}

	/**
	 * [anpa_colaboradores_acceso] — the shop's panel shell (built by colab-portal.js).
	 *
	 * @param  mixed $atts Unused.
	 * @return string
	 */
	public static function render_acceso( $atts = array() ): string {
		unset( $atts );
		if ( ! ANPA_Socios_Colab_DB::activo() ) {
			return '';
		}
		ANPA_Socios_Non_Cachear::marcar();
		$base = 'anpa-socios/v1/colab/';

		return '<div id="anpa-colab-portal" class="anpa-colab-app"'
			. ' data-codigo-url="' . esc_attr( rest_url( $base . 'solicitar-codigo' ) ) . '"'
			. ' data-entrar-url="' . esc_attr( rest_url( $base . 'entrar' ) ) . '"'
			. ' data-me-url="' . esc_attr( rest_url( $base . 'me' ) ) . '"'
			. ' data-sair-url="' . esc_attr( rest_url( $base . 'me/session' ) ) . '"'
			. ' data-ofertas-url="' . esc_attr( rest_url( $base . 'ofertas' ) ) . '"'
			. ' data-ts="' . esc_attr( (string) time() ) . '"'
			. ' data-contacto="' . esc_attr( ANPA_Socios_Config::contact_email() ) . '">'
			. '<noscript>' . esc_html__( 'Esta páxina precisa JavaScript.', 'anpa-socios' ) . '</noscript></div>';
	}

	/**
	 * [anpa_colaboradores_verificar] — card check shell (built by colab-verificar.js).
	 *
	 * @param  mixed $atts Unused.
	 * @return string
	 */
	public static function render_verificar( $atts = array() ): string {
		unset( $atts );
		if ( ! ANPA_Socios_Colab_DB::activo() ) {
			return '';
		}
		ANPA_Socios_Non_Cachear::marcar();

		return '<div id="anpa-colab-verificar" class="anpa-colab-app" data-verificar-url="' . esc_attr( rest_url( 'anpa-socios/v1/colab/verificar' ) ) . '">'
			. '<noscript>' . esc_html__( 'Esta páxina precisa JavaScript.', 'anpa-socios' ) . '</noscript></div>';
	}

	/**
	 * Styles and scripts, only on pages that carry one of the shortcodes.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		global $post;
		if ( ! is_singular() || ! ( $post instanceof WP_Post ) || ! ANPA_Socios_Colab_DB::activo() ) {
			return;
		}
		$contido   = (string) $post->post_content;
		$lista     = has_shortcode( $contido, self::SC_LISTA );
		$acceso    = has_shortcode( $contido, self::SC_ACCESO );
		$verificar = has_shortcode( $contido, self::SC_VERIFICAR );
		if ( ! $lista && ! $acceso && ! $verificar ) {
			return;
		}
		self::enqueue_style();
		if ( $acceso ) {
			self::enqueue_script( 'anpa-colab-portal', 'assets/js/colab-portal.js' );
		}
		if ( $verificar ) {
			self::enqueue_script( 'anpa-colab-verificar', 'assets/js/colab-verificar.js' );
		}
	}

	/**
	 * The module stylesheet (also used by the card in the member area).
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		$path = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/colab.css';
		wp_enqueue_style( 'anpa-colab', plugins_url( 'assets/css/colab.css', ANPA_SOCIOS_PLUGIN_FILE ), array(), file_exists( $path ) ? (int) filemtime( $path ) : ANPA_SOCIOS_VERSION );
	}

	/**
	 * Registers the vendored QR generator (MIT) for the member card.
	 *
	 * @return string Script handle.
	 */
	public static function registrar_qr(): string {
		$path = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/vendor/qrcode-generator.js';
		wp_register_script( 'anpa-qrcode', plugins_url( 'assets/js/vendor/qrcode-generator.js', ANPA_SOCIOS_PLUGIN_FILE ), array(), file_exists( $path ) ? (int) filemtime( $path ) : ANPA_SOCIOS_VERSION, true );

		return 'anpa-qrcode';
	}

	/**
	 * @param  string $handle Script handle.
	 * @param  string $rel    Path inside the plugin.
	 * @return void
	 */
	private static function enqueue_script( string $handle, string $rel ): void {
		$path = ANPA_SOCIOS_PLUGIN_DIR . $rel;
		wp_enqueue_script( $handle, plugins_url( $rel, ANPA_SOCIOS_PLUGIN_FILE ), array( 'wp-i18n' ), file_exists( $path ) ? (int) filemtime( $path ) : ANPA_SOCIOS_VERSION, true );
		wp_set_script_translations( $handle, 'anpa-socios', ANPA_SOCIOS_PLUGIN_DIR . 'languages' );
	}
}
