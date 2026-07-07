<?php
/**
 * Unified "Socios" hub page + area-link shortcode (fase8 PR-8b).
 *
 * [anpa_socios_hub]        — three clear cards: Facerme socio/a, Xa es socio/a?,
 *                            Darme de baixa, linking to the asociarse + area pages.
 * [anpa_socios_area_link]  — a simple link to the personal-area page, for menus.
 *
 * Page URLs are auto-detected (transient-cached) from the published page that
 * hosts each shortcode, so no slug is hardcoded.
 *
 * @since  1.9.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Socios hub and the area-link shortcode.
 *
 * @since 1.9.0
 */
final class ANPA_Socios_Hub_Page {

	/**
	 * Finds the permalink of the published page hosting a given shortcode.
	 *
	 * Cached per-shortcode for an hour. Returns '' when none is found.
	 *
	 * @since  1.9.0
	 * @param  string $shortcode Shortcode tag (without brackets).
	 * @return string
	 */
	public static function find_page_url( string $shortcode ): string {
		$key    = 'anpa_socios_pageurl_' . md5( $shortcode );
		$cached = get_transient( $key );
		// Only trust a non-empty cached value; never cache "not found" so a page
		// published later is picked up immediately (avoids a 1h dead-loop).
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$url   = '';
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		foreach ( $pages as $pid ) {
			if ( has_shortcode( (string) get_post_field( 'post_content', $pid ), $shortcode ) ) {
				$url = (string) get_permalink( $pid );
				break;
			}
		}

		if ( '' !== $url ) {
			set_transient( $key, $url, HOUR_IN_SECONDS );
		}

		return $url;
	}

	/**
	 * Shortcode [anpa_socios_hub] — three colour-coded entry cards.
	 *
	 * @since  1.9.0
	 * @param  array $atts Shortcode attributes (none used).
	 * @return string
	 */
	public static function render( $atts ): string {
		$area_url = self::find_page_url( 'anpa_socios_area' );
		$alta_url = self::find_page_url( 'anpa_socios_asociarse' );

		ob_start();
		?>
		<div class="anpa-hub">
			<div class="anpa-hub-card anpa-hub-alta">
				<h3>Facerme socio/a</h3>
				<p>Dáte de alta na ANPA As Brañas. A cota é de 15 € por familia e curso.</p>
				<?php if ( '' !== $alta_url ) : ?>
					<a class="anpa-hub-btn" href="<?php echo esc_url( $alta_url ); ?>">Asociarme</a>
				<?php endif; ?>
			</div>
			<div class="anpa-hub-card anpa-hub-login">
				<h3>Xa es socio/a?</h3>
				<p>Accede á túa área persoal para xestionar os teus datos, fillos/as e a domiciliación.</p>
				<?php if ( '' !== $area_url ) : ?>
					<a class="anpa-hub-btn" href="<?php echo esc_url( $area_url ); ?>">Entrar na área persoal</a>
				<?php endif; ?>
			</div>
			<div class="anpa-hub-card anpa-hub-baixa">
				<h3>Darme de baixa</h3>
				<p>A baixa solicítase desde a túa área persoal e require confirmación da directiva (efectiva a fin de curso).</p>
				<?php if ( '' !== $area_url ) : ?>
					<a class="anpa-hub-btn" href="<?php echo esc_url( $area_url ); ?>">Ir á área persoal</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [anpa_socios_area_link] — a plain link to the area page.
	 *
	 * @since  1.9.0
	 * @param  array $atts Shortcode attributes: text (link label).
	 * @return string
	 */
	public static function render_area_link( $atts ): string {
		$area_url = self::find_page_url( 'anpa_socios_area' );
		if ( '' === $area_url ) {
			return '';
		}
		$atts = shortcode_atts( array( 'text' => 'Área persoal' ), is_array( $atts ) ? $atts : array() );

		return '<a class="anpa-area-link" href="' . esc_url( $area_url ) . '">' . esc_html( $atts['text'] ) . '</a>';
	}

	/**
	 * Enqueues the hub styles only on pages using [anpa_socios_hub].
	 *
	 * Reuses the asociarse stylesheet (which carries the hub rules).
	 *
	 * @since  1.9.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		global $post;
		if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'anpa_socios_hub' ) ) {
			return;
		}
		$css_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/asociarse.css';
		$css_version = file_exists( $css_path ) ? (int) filemtime( $css_path ) : ANPA_SOCIOS_VERSION;
		wp_enqueue_style(
			'anpa-socios-asociarse',
			plugins_url( 'assets/css/asociarse.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$css_version
		);
	}
}
