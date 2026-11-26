<?php
/**
 * Keeps page caches away from the plugin's live pages (1.96.1).
 *
 * The sign-in / alta forms carry a render timestamp (`_ts`) that the
 * anti-bot check rejects after an hour, and the area and the offer show live
 * data (session, places). A page cache (Cache Enabler, WP Super Cache, W3TC,
 * a proxy…) serving an old copy would make code requests fail silently. So
 * any page that renders one of these shortcodes defines DONOTCACHEPAGE (all
 * common cache plugins honour it) and, when it is known before output
 * starts, sends no-cache headers.
 *
 * @since  1.96.1
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No-cache marker for live plugin pages.
 *
 * @since 1.96.1
 */
final class ANPA_Socios_Non_Cachear {

	/**
	 * Shortcodes whose output must never come from a page cache.
	 */
	const SHORTCODES = array(
		'anpa_socios_area',
		'anpa_socios_area_persoal',
		'anpa_socios_asociarse',
		'anpa_extraescolares_horario',
		'anpa_extraescolares_ofertadas',
	);

	/**
	 * Security headers sent with the no-cache headers on live pages.
	 *
	 * @since 1.103.0
	 */
	const SECURITY_HEADERS = array(
		'X-Frame-Options'        => 'SAMEORIGIN',
		'X-Content-Type-Options' => 'nosniff',
		'Referrer-Policy'        => 'strict-origin-when-cross-origin',
	);

	/**
	 * Registers the early check.
	 *
	 * @since  1.96.1
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 0 );
	}

	/**
	 * Whether a post content renders a live plugin shortcode.
	 *
	 * @since  1.96.1
	 * @param  string $content Post content.
	 * @return bool
	 */
	public static function contido_vivo( string $content ): bool {
		foreach ( self::SHORTCODES as $tag ) {
			if ( false !== strpos( $content, '[' . $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Before output: marks the page and sends no-cache headers.
	 *
	 * @since  1.96.1
	 * @return void
	 */
	public static function template_redirect(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( $post instanceof WP_Post && self::contido_vivo( (string) $post->post_content ) ) {
			self::marcar();
			if ( ! headers_sent() ) {
				nocache_headers();
				// 1.103.0: no framing, no MIME sniffing, no full URL in Referer.
				foreach ( self::SECURITY_HEADERS as $name => $value ) {
					header( $name . ': ' . $value );
				}
			}
		}
	}

	/**
	 * Marks the current response as not cacheable. Also called from the
	 * shortcode callbacks, so a shortcode in a widget or template is covered.
	 *
	 * @since  1.96.1
	 * @return void
	 */
	public static function marcar(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}
