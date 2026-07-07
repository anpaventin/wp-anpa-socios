<?php
/**
 * Header "Socios" dropdown for logged-in members.
 *
 * Renders an empty mount point after the Blocksy header and a small
 * client-side script that injects the dropdown only when a valid area
 * session token is found in localStorage. This avoids showing the menu
 * to anonymous visitors and keeps the existing Blocksy "Socios" menu
 * untouched for them.
 *
 * @since  1.20.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Header socios dropdown renderer.
 */
class ANPA_Socios_Header_Nav {

	/**
	 * Attach the dropdown mount point to the Blocksy header.
	 *
	 * @since 1.20.0
	 * @return void
	 */
	public static function register(): void {
		// PR-12l part 2: the standalone Blocksy header dropdown was retired.
		// The logged-in member/admin menu now lives inside the green session
		// header of the socios area (see area-page.php + area.js), so we no
		// longer inject a dropdown into the site header. The hooks are left
		// disabled here to keep the change easily reversible.
		return;
	}

	/**
	 * Render the empty mount point and configuration for the JS.
	 *
	 * @since 1.20.0
	 * @return void
	 */
	public static function render_mount(): void {
		// Resolve URLs dynamically (never hardcode page IDs — they differ per site).
		$area_url = class_exists( 'ANPA_Socios_Admin_Settings' )
			? ANPA_Socios_Admin_Settings::landing_page_url()
			: (string) ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area_unified' );

		$config = array(
			'areaUrl'   => $area_url,
			'baixaUrl'  => $area_url,
			'logoutUrl' => rest_url( 'anpa-socios/v1/area/me/session' ),
			'i18n'      => array(
				'toggle'       => __( 'Socios', 'anpa-socios' ),
				'area'         => __( 'Área persoal', 'anpa-socios' ),
				'baixa'        => __( 'Darse de baixa', 'anpa-socios' ),
				'logout'       => __( 'Pechar sesión', 'anpa-socios' ),
				'ariaLabel'    => __( 'Menú de socios', 'anpa-socios' ),
			),
		);
		?>
		<div id="anpa-header-nav-mount" class="anpa-header-nav-mount"></div>
		<script type="application/json" id="anpa-header-nav-config">
			<?php echo wp_json_encode( $config ); ?>
		</script>
		<?php
	}

	/**
	 * Enqueue the minimal dropdown stylesheet and renderer script.
	 *
	 * @since 1.20.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$css_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/header-nav.css';
		$css_version = file_exists( $css_path ) ? (int) filemtime( $css_path ) : ANPA_SOCIOS_VERSION;

		wp_enqueue_style(
			'anpa-socios-header-nav',
			plugins_url( 'assets/css/header-nav.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$css_version
		);

		$js_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/header-nav.js';
		$js_version = file_exists( $js_path ) ? (int) filemtime( $js_path ) : ANPA_SOCIOS_VERSION;

		wp_enqueue_script(
			'anpa-socios-header-nav',
			plugins_url( 'assets/js/header-nav.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$js_version,
			true
		);
	}
}
