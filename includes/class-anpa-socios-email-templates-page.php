<?php
/**
 * Admin screen for email template management (fase36, PR-36s4).
 *
 * Server rendered: everything on this screen is an audit/edit surface.
 * Writes POST to ANPA_Socios_Email_Template_Actions via admin_post hooks,
 * which check capability and nonce.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Templates_Page {

	/** Submenu slug. */
	const SLUG = 'anpa-socios-templates';

	/** Capability required to manage templates. */
	const CAP = 'manage_options';

	/** Nonce action for template writes. */
	const NONCE_ACTION = 'anpa_save_template';

	/** Nonce field name. */
	const NONCE_FIELD = 'anpa_template_nonce';

	/**
	 * Registers the submenu entry.
	 *
	 * @since  1.39.0
	 * @param  string $parent_slug Parent menu slug.
	 * @param  string $capability  Capability required by wp-admin.
	 * @return void
	 */
	public static function register_menu( string $parent_slug, string $capability ): void {
		add_submenu_page(
			$parent_slug,
			esc_html__( 'Plantillas de Email', 'anpa-socios' ),
			esc_html__( 'Plantillas de Email', 'anpa-socios' ),
			$capability,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renders the templates list or edit form.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap anpa-templates-wrap">';
		echo '<h1>' . esc_html__( 'Plantillas de Email', 'anpa-socios' ) . '</h1>';

		self::render_flash();

		if ( '' !== $edit_id && ANPA_Socios_Email_Template_Store::get_default( $edit_id ) ) {
			self::render_edit_form( $edit_id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * Shows the outcome of the last admin action, if any.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	private static function render_flash(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$msg  = isset( $_GET['anpa_msg'] ) ? sanitize_key( wp_unslash( $_GET['anpa_msg'] ) ) : '';
		$code = isset( $_GET['anpa_code'] ) ? sanitize_key( wp_unslash( $_GET['anpa_code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $msg ) {
			return;
		}

		$labels = array(
			'saved'   => __( 'Plantilla gardada correctamente.', 'anpa-socios' ),
			'restored' => __( 'Plantilla restaurada ao valor predeterminado.', 'anpa-socios' ),
			'error'   => __( 'Erro: revisa os campos e inténtao de novo.', 'anpa-socios' ),
		);

		if ( ! isset( $labels[ $msg ] ) ) {
			return;
		}

		$class = ( 'error' === $code || 'error' === $msg ) ? 'error' : 'updated';
		echo '<div class="' . esc_attr( $class ) . ' notice is-dismissible"><p>' . esc_html( $labels[ $msg ] ) . '</p></div>';
	}

	/**
	 * Renders the list of templates.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	private static function render_list(): void {
		$templates = ANPA_Socios_Email_Template_Store::get_all();
		$custom    = get_option( 'anpa_socios_email_templates', array() );

		echo '<p>' . esc_html__( 'Aquí podes personalizar as plantillas de email que se envían ás familias. Os cambios só se aplican cando se gardan; o contido predeterminado non se modifica.', 'anpa-socios' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Asunto', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Estado', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Variables', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Accións', 'anpa-socios' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $templates as $id => $template ) {
			$is_custom = isset( $custom[ $id ] );
			$vars      = ANPA_Socios_Email_Template_Store::get_variables( $id );
			$all_vars  = array_unique( array_merge( $vars['subject'], $vars['html'], $vars['text'] ) );

			echo '<tr>';
			echo '<td><code>' . esc_html( $id ) . '</code></td>';
			echo '<td>' . esc_html( $template['subject'] ) . '</td>';
			echo '<td>' . ( $is_custom ? esc_html__( 'Personalizada', 'anpa-socios' ) : esc_html__( 'Predeterminada', 'anpa-socios' ) ) . '</td>';
			echo '<td><code>' . esc_html( implode( ', ', array_map( function( $v ) { return '{{' . $v . '}}'; }, $all_vars ) ) ) . '</code></td>';
			echo '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&edit=' . $id ) ) . '">' . esc_html__( 'Editar', 'anpa-socios' ) . '</a> ';
			if ( $is_custom ) {
				echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_post_url( 'anpa_restore_template_' . $id ), self::NONCE_ACTION ) ) . '">' . esc_html__( 'Restaurar', 'anpa-socios' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the edit form for a single template.
	 *
	 * @since  1.39.0
	 * @param  string $id Template identifier.
	 * @return void
	 */
	private static function render_edit_form( string $id ): void {
		$template = ANPA_Socios_Email_Template_Store::get( $id );
		$vars     = ANPA_Socios_Email_Template_Store::get_variables( $id );
		$all_vars = array_unique( array_merge( $vars['subject'], $vars['html'], $vars['text'] ) );

		echo '<h2>' . sprintf( esc_html__( 'Editando: %s', 'anpa-socios' ), '<code>' . esc_html( $id ) . '</code>' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_post_url( 'anpa_save_template' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="template_id" value="' . esc_attr( $id ) . '" />';

		echo '<table class="form-table">';
		echo '<tr><th scope="row"><label for="template_subject">' . esc_html__( 'Asunto', 'anpa-socios' ) . '</label></th>';
		echo '<td><input type="text" id="template_subject" name="template_subject" value="' . esc_attr( $template['subject'] ) . '" class="large-text" />';
		echo '<p class="description">' . esc_html__( 'Variables: ', 'anpa-socios' ) . '<code>' . esc_html( implode( ', ', array_map( function( $v ) { return '{{' . $v . '}}'; }, $vars['subject'] ) ) ) . '</code></p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="template_html">' . esc_html__( 'Contido HTML', 'anpa-socios' ) . '</label></th>';
		echo '<td><textarea id="template_html" name="template_html" rows="12" class="large-text code">' . esc_textarea( $template['html'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Variables: ', 'anpa-socios' ) . '<code>' . esc_html( implode( ', ', array_map( function( $v ) { return '{{' . $v . '}}'; }, $vars['html'] ) ) ) . '</code></p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="template_text">' . esc_html__( 'Texto plano', 'anpa-socios' ) . '</label></th>';
		echo '<td><textarea id="template_text" name="template_text" rows="8" class="large-text">' . esc_textarea( $template['text'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Variables: ', 'anpa-socios' ) . '<code>' . esc_html( implode( ', ', array_map( function( $v ) { return '{{' . $v . '}}'; }, $vars['text'] ) ) ) . '</code></p>';
		echo '</td></tr>';

		echo '</table>';

		echo '<p class="submit">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Gardar', 'anpa-socios' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Cancelar', 'anpa-socios' ) . '</a>';
		echo '</p>';

		echo '</form>';

		echo '<h3>' . esc_html__( 'Previsualización', 'anpa-socios' ) . '</h3>';
		echo '<div style="border:1px solid #ddd;padding:16px;background:#f9f9f9;">';
		echo '<p><strong>' . esc_html__( 'Asunto:', 'anpa-socios' ) . '</strong> ' . esc_html( $template['subject'] ) . '</p>';
		echo '<div style="border-top:1px solid #ddd;margin-top:8px;padding-top:8px;">' . wp_kses_post( $template['html'] ) . '</div>';
		echo '</div>';
	}
}
