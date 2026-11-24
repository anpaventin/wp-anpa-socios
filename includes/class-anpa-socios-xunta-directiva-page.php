<?php
/**
 * Board roles (1.100.0): [anpa_xunta_directiva] shortcode and the admin form
 * under Axustes → Contido → Xunta directiva.
 *
 * @since  1.100.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress glue for ANPA_Socios_Xunta_Directiva.
 *
 * @since 1.100.0
 */
final class ANPA_Socios_Xunta_Directiva_Page {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( 'anpa_xunta_directiva', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_anpa_save_xunta_directiva', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Stored roles, already clean.
	 *
	 * @return array<int,array{cargo:string,nome:string}>
	 */
	public static function cargos(): array {
		$v = get_option( ANPA_Socios_Xunta_Directiva::OPTION, array() );
		return ANPA_Socios_Xunta_Directiva::sanitizar( is_array( $v ) ? $v : array() );
	}

	/**
	 * Shortcode [anpa_xunta_directiva].
	 *
	 * @param  mixed $atts Unused.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		return ANPA_Socios_Xunta_Directiva::html( self::cargos() );
	}

	/**
	 * Saves the roles (admin-post + PRG).
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non tes permiso para facer isto.', 'anpa-socios' ) );
		}
		check_admin_referer( 'anpa_save_xunta_directiva' );
		$rows = isset( $_POST['cargos'] ) && is_array( $_POST['cargos'] ) ? wp_unslash( $_POST['cargos'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by sanitizar().
		update_option( ANPA_Socios_Xunta_Directiva::OPTION, ANPA_Socios_Xunta_Directiva::sanitizar( (array) $rows ), false );
		if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
			Cache_Enabler::clear_complete_cache(); // The page with the cards is cached.
		}
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => ANPA_Socios_Admin_Settings::SETTINGS_SLUG, 'tab' => 'contenido', 'section' => 'xunta-directiva', 'anpa_msg' => 'contenido_saved' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Admin form: one row per role plus four empty rows to add more.
	 *
	 * @return void
	 */
	public static function render_admin(): void {
		$rows = self::cargos();
		echo '<p class="description">' . esc_html__( 'Os cargos amósanse como tarxetas na páxina «Xunta Directiva» co shortcode [anpa_xunta_directiva], na orde indicada. Para quitar un cargo, baleira o seu nome de cargo e garda. Para engadir máis de catro, garda e volverán aparecer filas baleiras.', 'anpa-socios' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="anpa-form">';
		echo '<input type="hidden" name="action" value="anpa_save_xunta_directiva">';
		wp_nonce_field( 'anpa_save_xunta_directiva' );
		echo '<datalist id="anpa-xunta-cargos">';
		foreach ( ANPA_Socios_Xunta_Directiva::SUXESTIONS as $s ) {
			echo '<option value="' . esc_attr( $s ) . '">';
		}
		echo '</datalist>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr>'
			. '<th scope="col" style="width:5em">' . esc_html__( 'Orde', 'anpa-socios' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Cargo', 'anpa-socios' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Nome e apelidos', 'anpa-socios' ) . '</th>'
			. '</tr></thead><tbody>';
		$total = count( $rows ) + 4;
		for ( $i = 0; $i < $total; $i++ ) {
			$r = $rows[ $i ] ?? array( 'cargo' => '', 'nome' => '' );
			echo '<tr>'
				. '<td><input type="number" name="cargos[' . $i . '][orde]" value="' . ( isset( $rows[ $i ] ) ? (int) ( ( $i + 1 ) * 10 ) : '' ) . '" class="small-text" min="0" step="1" aria-label="' . esc_attr__( 'Orde', 'anpa-socios' ) . '"></td>'
				. '<td><input type="text" name="cargos[' . $i . '][cargo]" value="' . esc_attr( $r['cargo'] ) . '" class="regular-text" list="anpa-xunta-cargos" maxlength="120" aria-label="' . esc_attr__( 'Cargo', 'anpa-socios' ) . '"></td>'
				. '<td><input type="text" name="cargos[' . $i . '][nome]" value="' . esc_attr( $r['nome'] ) . '" class="regular-text" maxlength="120" aria-label="' . esc_attr__( 'Nome e apelidos', 'anpa-socios' ) . '"></td>'
				. '</tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Gardar cargos', 'anpa-socios' ) );
		echo '</form>';
	}
}
