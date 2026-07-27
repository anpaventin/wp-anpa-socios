<?php
/**
 * Admin actions for the email template editor (fase36, PR-36s4).
 *
 * Every action is a POST to admin-post.php guarded by BOTH a capability check
 * (manage_options) and a per-action nonce, and always redirects back with a
 * result code instead of rendering. The admin screen itself lands in item 15;
 * these handlers are the write side.
 *
 * Nothing here decides repository semantics: each handler validates the request and
 * delegates to ANPA_Socios_Email_Template_Repo. The nesting guard runs BEFORE the
 * repository to catch nested optional blocks that the renderer cannot resolve.
 *
 * SECURITY: The test-send recipient is resolved server-side from wp_get_current_user().
 * There is NO recipient field in the request — not an ignored one, not a validated one.
 * A free-text recipient would turn this screen into an open relay for the board.
 *
 * @since  1.48.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Template_Admin_Actions {

	/** Capability required by every action. */
	const CAP = 'manage_options';

	/** Action names (also the nonce action names). */
	const ACTION_SAVE            = 'anpa_socios_template_save';
	const ACTION_PREVIEW         = 'anpa_socios_template_preview';
	const ACTION_TEST_SEND       = 'anpa_socios_template_test_send';
	const ACTION_RESTORE_DEFAULT = 'anpa_socios_template_restore_default';
	const ACTION_RESTORE_VERSION = 'anpa_socios_template_restore_version';
	const ACTION_ADOPT_DEFAULTS  = 'anpa_socios_template_adopt_defaults';

	/**
	 * Registers the admin-post handlers. Only for logged-in admins: no `nopriv`
	 * variant is registered on purpose.
	 *
	 * @since  1.48.0
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_TEST_SEND, array( __CLASS__, 'handle_test_send' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE_DEFAULT, array( __CLASS__, 'handle_restore_default' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE_VERSION, array( __CLASS__, 'handle_restore_version' ) );
		add_action( 'admin_post_' . self::ACTION_ADOPT_DEFAULTS, array( __CLASS__, 'handle_adopt_defaults' ) );
	}

	/**
	 * Saves edited template content.
	 *
	 * Runs the nesting guard BEFORE delegating to the repository, so a nested block is
	 * refused even though the repository's own validate() does not check for it.
	 *
	 * The expected_digest from the editor is REQUIRED so that the repository's optimistic
	 * concurrency check fires and a two-editor overwrite is refused with `conflict`.
	 *
	 * Possible result codes: saved, unchanged, conflict, not_found, undeclared_tokens,
	 * empty_subject, subject_too_long, empty_html, empty_text, db_error, nested_block.
	 *
	 * @since  1.48.0
	 * @return void
	 */
	public static function handle_save(): void {
		$template_key = self::authorize( self::ACTION_SAVE );

		$content = array(
			'subject' => isset( $_POST['template_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['template_subject'] ) ) : '',
			'html'    => isset( $_POST['template_html'] ) ? wp_unslash( $_POST['template_html'] ) : '',
			'text'    => isset( $_POST['template_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template_text'] ) ) : '',
		);

		$expected_digest = isset( $_POST['expected_digest'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_digest'] ) ) : '';

		// Nesting guard: reject BEFORE the repository sees the content.
		$nesting = ANPA_Socios_Email_Template_Nesting_Guard::check_content( $content );
		if ( $nesting['nested'] ) {
			self::audit( self::ACTION_SAVE, $template_key, 'nested_block' );
			self::redirect_back( $template_key, array( 'anpa_msg' => 'nested_block', 'anpa_channel' => $nesting['channel'] ) );
			return;
		}

		$actor  = self::current_actor_email();
		$result = ANPA_Socios_Email_Template_Repo::save( $template_key, $content, $actor, $expected_digest );

		self::audit( self::ACTION_SAVE, $template_key, $result['code'] );
		self::redirect_back( $template_key, array( 'anpa_msg' => $result['code'] ) );
	}

	/**
	 * Preview: renders the template with sample data and redirects with a transient key.
	 *
	 * SENDS NOTHING. There is no wp_mail call in this path. The rendered output is stored
	 * in a short-lived transient so the screen can display it without a second POST.
	 *
	 * @since  1.48.0
	 * @return void
	 */
	public static function handle_preview(): void {
		$template_key = self::authorize( self::ACTION_PREVIEW );

		$registry   = ANPA_Socios_Email_Template_Registry::build( ANPA_Socios_Email_Template_Events::definitions() );
		$set        = $registry->set();

		if ( ! $set->has( $template_key ) ) {
			self::redirect_back( $template_key, array( 'anpa_msg' => 'not_found' ) );
			return;
		}

		$definition = $set->get( $template_key );
		$variants   = ANPA_Socios_Email_Template_Preview_Context::all_variants( $definition );

		// Render each variant for each channel.
		$rendered = array();
		foreach ( $variants as $variant_id => $context ) {
			$template = ANPA_Socios_Email_Template_Repo::get( $template_key );
			if ( null === $template ) {
				self::redirect_back( $template_key, array( 'anpa_msg' => 'not_found' ) );
				return;
			}

			$html_result = ANPA_Socios_Email_Template_Renderer::render(
				$template['content_html'],
				$context,
				'html'
			);
			$text_result = ANPA_Socios_Email_Template_Renderer::render(
				$template['content_text'],
				$context,
				'text'
			);
			$subject_result = ANPA_Socios_Email_Template_Renderer::render(
				$template['content_subject'],
				$context,
				'text'
			);

			$rendered[ $variant_id ] = array(
				'subject' => $subject_result['ok'] ? $subject_result['output'] : $subject_result['error'],
				'html'    => $html_result['ok'] ? $html_result['output'] : $html_result['error'],
				'text'    => $text_result['ok'] ? $text_result['output'] : $text_result['error'],
				'ok'      => $html_result['ok'] && $text_result['ok'] && $subject_result['ok'],
			);
		}

		$transient_key = 'anpa_tpl_preview_' . md5( $template_key . wp_get_current_user()->ID );
		set_transient( $transient_key, $rendered, 300 );

		self::audit( self::ACTION_PREVIEW, $template_key, 'rendered' );
		self::redirect_back( $template_key, array( 'anpa_msg' => 'preview_ready', 'anpa_preview' => $transient_key ) );
	}

	/**
	 * Test send: renders and sends the template to the logged-in administrator's own address.
	 *
	 * The recipient is resolved SERVER-SIDE from wp_get_current_user(). There is NO recipient
	 * field in the request at all. The message is clearly marked as a test so the administrator
	 * can distinguish it from a real notification.
	 *
	 * @since  1.48.0
	 * @return void
	 */
	public static function handle_test_send(): void {
		$template_key = self::authorize( self::ACTION_TEST_SEND );

		$registry   = ANPA_Socios_Email_Template_Registry::build( ANPA_Socios_Email_Template_Events::definitions() );
		$set        = $registry->set();

		if ( ! $set->has( $template_key ) ) {
			self::redirect_back( $template_key, array( 'anpa_msg' => 'not_found' ) );
			return;
		}

		$definition = $set->get( $template_key );
		$context    = ANPA_Socios_Email_Template_Preview_Context::build_default( $definition );

		// For branching events, use the with-area-link variant as the test send default.
		if ( ANPA_Socios_Email_Template_Preview_Context::requires_branching_preview( $definition ) ) {
			$variants = ANPA_Socios_Email_Template_Preview_Context::build_variants( $definition );
			$context  = $variants['with_area_link'];
		}

		$template = ANPA_Socios_Email_Template_Repo::get( $template_key );
		if ( null === $template ) {
			self::redirect_back( $template_key, array( 'anpa_msg' => 'not_found' ) );
			return;
		}

		$subject_result = ANPA_Socios_Email_Template_Renderer::render(
			$template['content_subject'],
			$context,
			'text'
		);
		$html_result = ANPA_Socios_Email_Template_Renderer::render(
			$template['content_html'],
			$context,
			'html'
		);

		if ( ! $subject_result['ok'] || ! $html_result['ok'] ) {
			self::audit( self::ACTION_TEST_SEND, $template_key, 'render_error' );
			self::redirect_back( $template_key, array( 'anpa_msg' => 'render_error' ) );
			return;
		}

		// Recipient is the logged-in admin, resolved server-side.
		$current_user = wp_get_current_user();
		$recipient    = $current_user->user_email;

		// Mark as test in the subject.
		$subject = '[PROBA] ' . $subject_result['output'];

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $recipient, $subject, $html_result['output'], $headers );

		$outcome = $sent ? 'sent' : 'send_failed';
		self::audit( self::ACTION_TEST_SEND, $template_key, $outcome );
		self::redirect_back( $template_key, array( 'anpa_msg' => 'test_' . $outcome ) );
	}

	/**
	 * Restores the shipped default for a template. Requires explicit confirmation.
	 *
	 * The repository archives the current version before restoring, so history is preserved.
	 * The confirmation is about the human: "I really mean to lose my edits", not the data.
	 *
	 * @since  1.48.0
	 * @return void
	 */
	public static function handle_restore_default(): void {
		$template_key = self::authorize( self::ACTION_RESTORE_DEFAULT );

		// Explicit confirmation required.
		$confirmed = isset( $_POST['confirm_restore'] ) && '1' === $_POST['confirm_restore'];
		if ( ! $confirmed ) {
			self::redirect_back( $template_key, array( 'anpa_msg' => 'restore_not_confirmed' ) );
			return;
		}

		$actor  = self::current_actor_email();
		$result = ANPA_Socios_Email_Template_Repo::restore_default( $template_key, $actor );

		self::audit( self::ACTION_RESTORE_DEFAULT, $template_key, $result['code'] );
		self::redirect_back( $template_key, array( 'anpa_msg' => $result['code'] ) );
	}

	/**
	 * Restores a specific archived version.
	 *
	 * @since  1.48.0
	 * @return void
	 */
	public static function handle_restore_version(): void {
		self::authorize_simple( self::ACTION_RESTORE_VERSION );

		$version_id = isset( $_POST['version_id'] ) ? absint( wp_unslash( $_POST['version_id'] ) ) : 0;
		if ( $version_id <= 0 ) {
			wp_die( esc_html__( 'Falta o identificador da versión.', 'anpa-socios' ), '', array( 'response' => 400 ) );
		}

		$actor  = self::current_actor_email();
		$result = ANPA_Socios_Email_Template_Repo::restore_version( $version_id, $actor );

		$template_key = isset( $_POST['template_key'] ) ? sanitize_text_field( wp_unslash( $_POST['template_key'] ) ) : '';

		self::audit( self::ACTION_RESTORE_VERSION, $template_key, $result['code'] );
		self::redirect_back( $template_key, array( 'anpa_msg' => $result['code'] ) );
	}

	/**
	 * Adopts newer shipped defaults for all non-customised templates.
	 *
	 * @since  1.48.0
	 * @return void
	 */
	public static function handle_adopt_defaults(): void {
		self::authorize_simple( self::ACTION_ADOPT_DEFAULTS );

		$actor  = self::current_actor_email();
		$result = ANPA_Socios_Email_Template_Repo::adopt_newer_defaults( $actor );

		self::audit( self::ACTION_ADOPT_DEFAULTS, '', $result['code'] );
		self::redirect_back( '', array( 'anpa_msg' => $result['code'], 'anpa_adopted' => (int) $result['adopted'] ) );
	}

	/**
	 * Capability + nonce + template key check. Dies on failure.
	 *
	 * @since  1.48.0
	 * @param  string $action Action/nonce name.
	 * @return string Template key.
	 */
	private static function authorize( string $action ): string {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non tes permisos para esta acción.', 'anpa-socios' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );

		$template_key = isset( $_POST['template_key'] ) ? sanitize_text_field( wp_unslash( $_POST['template_key'] ) ) : '';
		if ( '' === $template_key ) {
			wp_die( esc_html__( 'Falta a plantela.', 'anpa-socios' ), '', array( 'response' => 400 ) );
		}

		return $template_key;
	}

	/**
	 * Capability + nonce only (no template key required). Dies on failure.
	 *
	 * @since  1.48.0
	 * @param  string $action Action/nonce name.
	 * @return void
	 */
	private static function authorize_simple( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non tes permisos para esta acción.', 'anpa-socios' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Renders the hidden fields (action + nonce + template key) for a form.
	 *
	 * @since  1.48.0
	 * @param  string $action       Action name.
	 * @param  string $template_key Template key.
	 * @return void
	 */
	public static function form_fields( string $action, string $template_key = '' ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		if ( '' !== $template_key ) {
			echo '<input type="hidden" name="template_key" value="' . esc_attr( $template_key ) . '" />';
		}
		wp_nonce_field( $action );
	}

	/**
	 * Writes an audit line. Records WHO did WHAT to WHICH template and the outcome
	 * code — never recipient addresses, payloads or template content.
	 *
	 * @since  1.48.0
	 * @param  string $action       Action name.
	 * @param  string $template_key Template key.
	 * @param  string $outcome      Result code.
	 * @return void
	 */
	private static function audit( string $action, string $template_key, string $outcome ): void {
		$actor = wp_get_current_user();
		do_action(
			'anpa_socios_email_admin_action',
			array(
				'action'       => $action,
				'template_key' => $template_key,
				'outcome'      => $outcome,
				'actor'        => $actor instanceof WP_User ? $actor->user_email : '',
				'at_utc'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Redirects back to the referring admin screen with result parameters.
	 *
	 * @since  1.48.0
	 * @param  string              $template_key Template key.
	 * @param  array<string,mixed> $args         Query args to add.
	 * @return void
	 */
	private static function redirect_back( string $template_key, array $args ): void {
		$base = wp_get_referer();
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=anpa-socios-plantelas' );
		}
		if ( '' !== $template_key ) {
			$args['template_key'] = $template_key;
		}
		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	/**
	 * Returns the email of the current admin actor.
	 *
	 * @since  1.48.0
	 * @return string
	 */
	private static function current_actor_email(): string {
		$user = wp_get_current_user();
		return $user instanceof WP_User ? $user->user_email : '';
	}
}
