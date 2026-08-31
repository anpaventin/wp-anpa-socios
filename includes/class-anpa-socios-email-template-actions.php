<?php
/**
 * Admin actions for email template management (fase36, PR-36s4).
 *
 * Every action is a POST to admin-post.php guarded by BOTH a capability check
 * (manage_options) and a per-action nonce, and always redirects back with a
 * result code instead of rendering.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Template_Actions {

	/** Capability required by every action. */
	const CAP = 'manage_options';

	/** Action names (also the nonce action names). */
	const ACTION_SAVE   = 'anpa_save_template';
	const ACTION_RESTORE = 'anpa_restore_template';

	/** Nonce action. */
	const NONCE_ACTION = 'anpa_save_template';

	/** Nonce field name. */
	const NONCE_FIELD = 'anpa_template_nonce';

	/**
	 * Registers the admin-post handlers.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE, array( __CLASS__, 'handle_restore' ) );
	}

	/**
	 * Saves a template customization.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function handle_save(): void {
		$template_id = self::authorize( self::ACTION_SAVE );

		// phpcs:disable WordPress.Security.NonceVerification.MUST -- verified by authorize().
		$subject = isset( $_POST['template_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['template_subject'] ) ) : '';
		$html    = isset( $_POST['template_html'] ) ? wp_kses_post( wp_unslash( $_POST['template_html'] ) ) : '';
		$text    = isset( $_POST['template_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template_text'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.MUST

		$result = ANPA_Socios_Email_Template_Store::save( $template_id, $subject, $html, $text );

		if ( $result ) {
			self::redirect_back( 'saved', '' );
		} else {
			self::redirect_back( 'error', 'error' );
		}
	}

	/**
	 * Restores a template to its default.
	 *
	 * @since  1.39.0
	 * @return void
	 */
	public static function handle_restore(): void {
		$template_id = self::authorize( self::ACTION_RESTORE );

		ANPA_Socios_Email_Template_Store::delete( $template_id );

		self::redirect_back( 'restored', '' );
	}

	/**
	 * Checks capability + nonce and returns the template ID.
	 *
	 * @since  1.39.0
	 * @param  string $action Nonce action.
	 * @return string         Template ID.
	 */
	private static function authorize( string $action ): string {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Erro de seguridade.', 'anpa-socios' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.MUST -- verified above.
		$template_id = isset( $_POST['template_id'] ) ? sanitize_text_field( wp_unslash( $_POST['template_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.MUST

		if ( '' === $template_id ) {
			wp_die( esc_html__( 'Plantilla non válida.', 'anpa-socios' ) );
		}

		return $template_id;
	}

	/**
	 * Redirects back to the templates page with a result code.
	 *
	 * @since  1.39.0
	 * @param  string $msg  Message code.
	 * @param  string $code Optional error code.
	 * @return void
	 */
	private static function redirect_back( string $msg, string $code = '' ): void {
		$redirect = add_query_arg(
			array(
				'page'      => ANPA_Socios_Email_Templates_Page::SLUG,
				'anpa_msg'  => $msg,
				'anpa_code' => $code,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
