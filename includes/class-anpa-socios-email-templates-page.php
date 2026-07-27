<?php
/**
 * Admin screen for email templates: list + edit view (fase36, PR-36s4).
 *
 * Server rendered on purpose: every field on this screen is an editorial surface,
 * so it must be readable without JavaScript and inspectable in review. Writes
 * NEVER happen here — every button POSTs to ANPA_Socios_Email_Template_Admin_Actions,
 * which checks the capability and the nonce.
 *
 * PREVIEW DISPLAY STRATEGY: The preview is rendered inside a sandboxed &lt;iframe&gt;
 * using the srcdoc attribute. This prevents any stored HTML from breaking out of
 * the page layout, executing inline scripts, or accessing the parent document's
 * cookies or DOM. The tradeoff: srcdoc iframes cannot load external resources (images,
 * fonts) that the production email would reference — but that is acceptable because
 * preview fidelity for layout is secondary to admin-page safety. An operator who needs
 * pixel-perfect rendering uses the test-send action and reads the email in their client.
 *
 * Terminology: a test send is "aceptada" (accepted by the mail system), NEVER
 * "entregada" (delivered). wp_mail() === true is not delivery.
 *
 * @since  TBD
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Templates_Page {

	/** Submenu slug. */
	const SLUG = 'anpa-socios-plantelas';

	/** Capability required to see or act on templates. */
	const CAP = 'manage_options';

	/**
	 * Registers the submenu entry.
	 *
	 * @since  TBD
	 * @param  string $parent_slug Parent menu slug.
	 * @param  string $capability  Capability required by wp-admin.
	 * @return void
	 */
	public static function register_menu( string $parent_slug, string $capability ): void {
		add_submenu_page(
			$parent_slug,
			esc_html__( 'Plantelas de correo electrónico', 'anpa-socios' ),
			esc_html__( 'Plantelas', 'anpa-socios' ),
			$capability,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renders the list or, when a template is selected, the edit view.
	 *
	 * CAPABILITY CHECK FIRST: no output is emitted before verifying permissions.
	 *
	 * @since  TBD
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$template_key = isset( $_GET['template_key'] ) ? sanitize_key( wp_unslash( $_GET['template_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap anpa-templates-wrap">';
		echo '<h1>' . esc_html__( 'Plantelas de correo electrónico', 'anpa-socios' ) . '</h1>';

		self::render_result_notice();

		if ( '' !== $template_key ) {
			self::render_edit( $template_key );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * Shows the outcome of the last admin action, if any.
	 *
	 * Covers every result code the actions can emit. A code with no message would leave
	 * the operator staring at a screen that silently did nothing.
	 *
	 * @since  TBD
	 * @return void
	 */
	private static function render_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$msg     = isset( $_GET['anpa_msg'] ) ? sanitize_key( wp_unslash( $_GET['anpa_msg'] ) ) : '';
		$channel = isset( $_GET['anpa_channel'] ) ? sanitize_key( wp_unslash( $_GET['anpa_channel'] ) ) : '';
		$adopted = isset( $_GET['anpa_adopted'] ) ? absint( wp_unslash( $_GET['anpa_adopted'] ) ) : 0;
		$reported = isset( $_GET['anpa_reported'] ) ? absint( wp_unslash( $_GET['anpa_reported'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $msg ) {
			return;
		}

		$messages = array(
			'saved'                 => __( 'Plantela gardada.', 'anpa-socios' ),
			'unchanged'            => __( 'A plantela non cambiou; nada que gardar.', 'anpa-socios' ),
			'conflict'             => __( 'Outra persoa editou esta plantela mentres a tiñas aberta. Recarga e volve aplicar o cambio.', 'anpa-socios' ),
			'not_found'            => __( 'Non se atopou a plantela.', 'anpa-socios' ),
			'undeclared_tokens'    => __( 'A plantela usa variables que non están declaradas para este evento.', 'anpa-socios' ),
			'empty_subject'        => __( 'O asunto non pode estar baleiro.', 'anpa-socios' ),
			'subject_too_long'     => __( 'O asunto supera o límite de caracteres.', 'anpa-socios' ),
			'empty_html'           => __( 'O corpo HTML non pode estar baleiro.', 'anpa-socios' ),
			'empty_text'           => __( 'O corpo de texto plano non pode estar baleiro.', 'anpa-socios' ),
			'db_error'             => __( 'Erro ao gardar na base de datos.', 'anpa-socios' ),
			'nested_block'         => sprintf(
				/* translators: %s: channel name (body_html or body_text). */
				__( 'A plantela contén un bloque opcional dentro doutro na canle %s. Os bloques opcionais non se poden aniñar.', 'anpa-socios' ),
				'<code>' . esc_html( $channel ) . '</code>'
			),
			'restore_not_confirmed' => __( 'Para restaurar o texto orixinal é preciso confirmar a acción.', 'anpa-socios' ),
			'restored_default'     => __( 'Restaurouse o texto orixinal de fábrica.', 'anpa-socios' ),
			'restored_version'     => __( 'Restaurouse a versión seleccionada.', 'anpa-socios' ),
			'preview_ready'        => __( 'Vista previa xerada.', 'anpa-socios' ),
			'test_sent'            => __( 'Envío de proba aceptado polo sistema de correo. Revisa a túa caixa de entrada.', 'anpa-socios' ),
			'test_send_failed'     => __( 'O sistema de correo rexeitou o envío de proba.', 'anpa-socios' ),
			'render_error'         => __( 'Erro ao renderizar a plantela para a vista previa ou o envío de proba.', 'anpa-socios' ),
			'adopted_defaults'     => sprintf(
				/* translators: 1: number adopted, 2: number reported. */
				__( 'Adoptáronse %1$d versións actualizadas de fábrica. %2$d plantelas personalizadas teñen versión nova dispoñible pero non se tocaron.', 'anpa-socios' ),
				$adopted,
				$reported
			),
			'newer_available'      => __( 'Non hai plantelas non personalizadas para actualizar, pero algunhas personalizadas teñen unha versión de fábrica máis recente.', 'anpa-socios' ),
			'nothing_to_adopt'     => __( 'Todas as plantelas xa teñen a última versión de fábrica.', 'anpa-socios' ),
			'unknown_event'        => __( 'O evento da plantela non está rexistrado.', 'anpa-socios' ),
		);

		$text = isset( $messages[ $msg ] ) ? $messages[ $msg ] : $msg;

		$error_codes = array(
			'conflict', 'not_found', 'undeclared_tokens', 'empty_subject',
			'subject_too_long', 'empty_html', 'empty_text', 'db_error',
			'nested_block', 'restore_not_confirmed', 'test_send_failed',
			'render_error', 'unknown_event',
		);
		$class = in_array( $msg, $error_codes, true ) ? 'notice notice-warning' : 'notice notice-success';

		echo '<div class="' . esc_attr( $class ) . '"><p>' . wp_kses_post( $text ) . '</p></div>';
	}

	/**
	 * Renders the list view: every registered event grouped by category.
	 *
	 * @since  TBD
	 * @return void
	 */
	private static function render_list(): void {
		$set      = ANPA_Socios_Email_Template_Events::set();
		$stored   = ANPA_Socios_Email_Template_Repo::all();
		$outdated = ANPA_Socios_Email_Template_Repo::outdated();

		$category_labels = self::category_labels();
		$grouped         = array();

		foreach ( $set->all() as $key => $definition ) {
			$cat = $definition->category();
			if ( ! isset( $grouped[ $cat ] ) ) {
				$grouped[ $cat ] = array();
			}
			$grouped[ $cat ][ $key ] = $definition;
		}

		// Adopt newer defaults action (global).
		if ( array() !== $outdated ) {
			echo '<div class="anpa-templates-adopt">';
			echo '<p>' . esc_html__( 'Hai versións de fábrica máis recentes dispoñibles.', 'anpa-socios' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			ANPA_Socios_Email_Template_Admin_Actions::form_fields( ANPA_Socios_Email_Template_Admin_Actions::ACTION_ADOPT_DEFAULTS );
			echo '<button type="submit" class="button">' . esc_html__( 'Adoptar versións de fábrica actualizadas', 'anpa-socios' ) . '</button>';
			echo '</form>';
			echo '</div>';
		}

		foreach ( $grouped as $category => $definitions ) {
			$label = isset( $category_labels[ $category ] ) ? $category_labels[ $category ] : ucfirst( $category );
			echo '<h2>' . esc_html( $label ) . '</h2>';
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Plantela', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Destinatario', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Fase', 'anpa-socios' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Estado', 'anpa-socios' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $definitions as $key => $definition ) {
				$url = add_query_arg(
					array( 'page' => self::SLUG, 'template_key' => $key ),
					admin_url( 'admin.php' )
				);
				$is_customised    = isset( $stored[ $key ] ) && 1 === (int) $stored[ $key ]['is_customised'];
				$is_outdated      = isset( $outdated[ $key ] );

				echo '<tr>';
				echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( $definition->display_name() ) . '</a></td>';
				echo '<td>' . esc_html( self::audience_label( $definition->audience() ) ) . '</td>';
				echo '<td>' . esc_html( $definition->phase()->id() ) . '</td>';
				echo '<td>';
				if ( $is_customised ) {
					echo '<span class="anpa-tpl-badge anpa-tpl-badge--customised">' . esc_html__( 'Personalizada', 'anpa-socios' ) . '</span> ';
				}
				if ( $is_outdated ) {
					echo '<span class="anpa-tpl-badge anpa-tpl-badge--outdated">' . esc_html__( 'Nova versión dispoñible', 'anpa-socios' ) . '</span>';
				}
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Renders the edit view for a single template.
	 *
	 * @since  TBD
	 * @param  string $template_key Template key.
	 * @return void
	 */
	private static function render_edit( string $template_key ): void {
		$set = ANPA_Socios_Email_Template_Events::set();

		if ( ! $set->has( $template_key ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Evento non rexistrado.', 'anpa-socios' ) . '</p></div>';
			return;
		}

		$definition = $set->get( $template_key );
		$row        = ANPA_Socios_Email_Template_Repo::get( $template_key );

		if ( null === $row ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'A plantela aínda non foi inicializada na base de datos.', 'anpa-socios' ) . '</p></div>';
			return;
		}

		$back_url = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Volver á lista', 'anpa-socios' ) . '</a></p>';

		echo '<h2>' . esc_html( $definition->display_name() ) . '</h2>';
		echo '<p class="description">' . esc_html( $definition->description() ) . '</p>';

		$is_customised = 1 === (int) $row['is_customised'];
		$outdated      = ANPA_Socios_Email_Template_Repo::outdated();
		$is_outdated   = isset( $outdated[ $template_key ] );

		if ( $is_customised ) {
			echo '<span class="anpa-tpl-badge anpa-tpl-badge--customised">' . esc_html__( 'Personalizada', 'anpa-socios' ) . '</span> ';
		}
		if ( $is_outdated ) {
			echo '<span class="anpa-tpl-badge anpa-tpl-badge--outdated">' . esc_html__( 'Nova versión de fábrica dispoñible', 'anpa-socios' ) . '</span>';
		}

		// Preview display (from transient, if available).
		self::render_preview( $template_key, $definition );

		// Edit form.
		self::render_edit_form( $template_key, $row, $definition );

		// Variable panel.
		self::render_variable_panel( $definition );

		// Actions: preview, test send, restore.
		self::render_action_forms( $template_key, $definition );

		// Version history.
		self::render_version_history( $template_key );
	}

	/**
	 * Renders the preview if the transient is present.
	 *
	 * Uses a sandboxed iframe (srcdoc) to prevent stored HTML from breaking out of the
	 * page or executing script. See the class docblock for the tradeoff.
	 *
	 * @since  TBD
	 * @param  string                                  $template_key Template key.
	 * @param  ANPA_Socios_Email_Template_Definition   $definition   Event declaration.
	 * @return void
	 */
	private static function render_preview( string $template_key, ANPA_Socios_Email_Template_Definition $definition ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display.
		$preview_key = isset( $_GET['anpa_preview'] ) ? sanitize_key( wp_unslash( $_GET['anpa_preview'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $preview_key ) {
			return;
		}

		$rendered = get_transient( $preview_key );
		if ( ! is_array( $rendered ) || array() === $rendered ) {
			return;
		}

		// Clean up the transient after reading (one-time display).
		delete_transient( $preview_key );

		$variant_labels = ANPA_Socios_Email_Template_Preview_Context::variant_labels();

		echo '<div class="anpa-tpl-preview">';
		echo '<h3>' . esc_html__( 'Vista previa', 'anpa-socios' ) . '</h3>';

		foreach ( $rendered as $variant_id => $variant ) {
			$label = isset( $variant_labels[ $variant_id ] ) ? $variant_labels[ $variant_id ] : $variant_id;

			echo '<div class="anpa-tpl-preview-variant">';
			if ( count( $rendered ) > 1 ) {
				echo '<h4>' . esc_html( $label ) . '</h4>';
			}

			if ( empty( $variant['ok'] ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Erro ao renderizar esta variante.', 'anpa-socios' ) . '</p></div>';
			}

			echo '<p><strong>' . esc_html__( 'Asunto:', 'anpa-socios' ) . '</strong> ' . esc_html( (string) $variant['subject'] ) . '</p>';

			// HTML preview in sandboxed iframe.
			$html_content = isset( $variant['html'] ) ? (string) $variant['html'] : '';
			echo '<iframe sandbox="" srcdoc="' . esc_attr( $html_content ) . '" style="width:100%;min-height:300px;border:1px solid #ccc;background:#fff;" title="' . esc_attr__( 'Vista previa HTML', 'anpa-socios' ) . '"></iframe>';

			// Plain text preview.
			if ( ! empty( $variant['text'] ) ) {
				echo '<details><summary>' . esc_html__( 'Texto plano', 'anpa-socios' ) . '</summary>';
				echo '<pre style="white-space:pre-wrap;background:#f9f9f9;padding:10px;border:1px solid #ddd;">' . esc_html( (string) $variant['text'] ) . '</pre>';
				echo '</details>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Renders the edit form with subject, HTML body, and plain-text body.
	 *
	 * The form carries the expected_digest (the row's content_hash) so the repository's
	 * optimistic concurrency check fires if another editor saved in between.
	 *
	 * @since  TBD
	 * @param  string              $template_key Template key.
	 * @param  array<string,mixed> $row          Current stored row.
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return void
	 */
	private static function render_edit_form( string $template_key, array $row, ANPA_Socios_Email_Template_Definition $definition ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="anpa-tpl-edit-form">';
		ANPA_Socios_Email_Template_Admin_Actions::form_fields( ANPA_Socios_Email_Template_Admin_Actions::ACTION_SAVE, $template_key );

		// Optimistic concurrency: carry the digest the form was rendered from.
		echo '<input type="hidden" name="expected_digest" value="' . esc_attr( (string) $row['content_hash'] ) . '" />';

		echo '<table class="form-table" role="presentation">';

		// Subject.
		echo '<tr><th scope="row"><label for="template_subject">' . esc_html__( 'Asunto', 'anpa-socios' ) . '</label></th>';
		echo '<td><input type="text" id="template_subject" name="template_subject" value="' . esc_attr( (string) $row['subject'] ) . '" class="large-text" /></td></tr>';

		// HTML body.
		echo '<tr><th scope="row"><label for="template_html">' . esc_html__( 'Corpo HTML', 'anpa-socios' ) . '</label></th>';
		echo '<td><textarea id="template_html" name="template_html" rows="15" class="large-text code">' . esc_textarea( (string) $row['body_html'] ) . '</textarea></td></tr>';

		// Plain text body.
		echo '<tr><th scope="row"><label for="template_text">' . esc_html__( 'Corpo texto plano', 'anpa-socios' ) . '</label></th>';
		echo '<td><textarea id="template_text" name="template_text" rows="10" class="large-text code">' . esc_textarea( (string) $row['body_text'] ) . '</textarea></td></tr>';

		echo '</table>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Gardar', 'anpa-socios' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Renders the variable panel: every token with label, description, and sample.
	 *
	 * Nobody should need documentation to edit a template.
	 *
	 * @since  TBD
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return void
	 */
	private static function render_variable_panel( ANPA_Socios_Email_Template_Definition $definition ): void {
		$variables = $definition->variables();
		if ( array() === $variables ) {
			return;
		}

		echo '<div class="anpa-tpl-variables">';
		echo '<h3>' . esc_html__( 'Variables dispoñibles', 'anpa-socios' ) . '</h3>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Token', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Etiqueta', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Descrición', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Exemplo', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Obrigatorio', 'anpa-socios' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $variables as $token => $variable ) {
			echo '<tr>';
			echo '<td><code>{{' . esc_html( $token ) . '}}</code></td>';
			echo '<td>' . esc_html( $variable->label() ) . '</td>';
			echo '<td>' . esc_html( $variable->description() ) . '</td>';
			echo '<td><em>' . esc_html( $variable->example() ) . '</em></td>';
			echo '<td>' . ( $variable->is_required() ? esc_html__( 'Si', 'anpa-socios' ) : esc_html__( 'Non', 'anpa-socios' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders the action forms: preview (with variant selection for branching events),
	 * test send (NO recipient field), and restore default (with confirmation).
	 *
	 * @since  TBD
	 * @param  string                                $template_key Template key.
	 * @param  ANPA_Socios_Email_Template_Definition $definition   Event declaration.
	 * @return void
	 */
	private static function render_action_forms( string $template_key, ANPA_Socios_Email_Template_Definition $definition ): void {
		echo '<div class="anpa-tpl-actions">';
		echo '<h3>' . esc_html__( 'Accións', 'anpa-socios' ) . '</h3>';

		// Preview.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
		ANPA_Socios_Email_Template_Admin_Actions::form_fields( ANPA_Socios_Email_Template_Admin_Actions::ACTION_PREVIEW, $template_key );
		$button_label = esc_html__( 'Vista previa', 'anpa-socios' );
		if ( ANPA_Socios_Email_Template_Preview_Context::requires_branching_preview( $definition ) ) {
			$button_label = esc_html__( 'Vista previa (ambas variantes)', 'anpa-socios' );
		}
		echo '<button type="submit" class="button">' . $button_label . '</button>';
		echo '</form>';

		// Test send — NO recipient field. The address is resolved server-side.
		$current_user = wp_get_current_user();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
		ANPA_Socios_Email_Template_Admin_Actions::form_fields( ANPA_Socios_Email_Template_Admin_Actions::ACTION_TEST_SEND, $template_key );
		echo '<button type="submit" class="button">';
		echo esc_html(
			sprintf(
				/* translators: %s: email address. */
				__( 'Envío de proba a %s', 'anpa-socios' ),
				$current_user->user_email
			)
		);
		echo '</button>';
		echo '</form>';

		// Restore default — requires explicit confirmation.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;" onsubmit="return confirm(\'' . esc_js( __( 'Restaurar o texto orixinal? Perderanse os cambios personalizados.', 'anpa-socios' ) ) . '\');">';
		ANPA_Socios_Email_Template_Admin_Actions::form_fields( ANPA_Socios_Email_Template_Admin_Actions::ACTION_RESTORE_DEFAULT, $template_key );
		echo '<input type="hidden" name="confirm_restore" value="1" />';
		echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Restaurar orixinal', 'anpa-socios' ) . '</button>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Renders the version history for a template, with restore buttons.
	 *
	 * @since  TBD
	 * @param  string $template_key Template key.
	 * @return void
	 */
	private static function render_version_history( string $template_key ): void {
		$versions = ANPA_Socios_Email_Template_Repo::versions( $template_key );

		echo '<div class="anpa-tpl-versions">';
		echo '<h3>' . esc_html__( 'Historial de versións', 'anpa-socios' ) . '</h3>';

		if ( array() === $versions ) {
			echo '<p class="description">' . esc_html__( 'Aínda non hai versións arquivadas.', 'anpa-socios' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Data (UTC)', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Motivo', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Autor/a', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Restaurar', 'anpa-socios' ) . '</th>';
		echo '</tr></thead><tbody>';

		$reason_labels = array(
			'save'            => __( 'Gardado', 'anpa-socios' ),
			'restore_default' => __( 'Restaurar orixinal', 'anpa-socios' ),
			'restore_version' => __( 'Restaurar versión', 'anpa-socios' ),
		);

		foreach ( $versions as $version ) {
			$vid    = (int) $version['id'];
			$reason = isset( $reason_labels[ (string) $version['reason'] ] )
				? $reason_labels[ (string) $version['reason'] ]
				: (string) $version['reason'];

			echo '<tr>';
			echo '<td>' . esc_html( (string) $version['archived_at_utc'] ) . '</td>';
			echo '<td>' . esc_html( $reason ) . '</td>';
			echo '<td>' . esc_html( (string) $version['actor'] ) . '</td>';
			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			ANPA_Socios_Email_Template_Admin_Actions::form_fields( ANPA_Socios_Email_Template_Admin_Actions::ACTION_RESTORE_VERSION, $template_key );
			echo '<input type="hidden" name="version_id" value="' . esc_attr( (string) $vid ) . '" />';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Restaurar', 'anpa-socios' ) . '</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Galician labels for event categories.
	 *
	 * @since  TBD
	 * @return array<string,string>
	 */
	private static function category_labels(): array {
		return array(
			ANPA_Socios_Email_Template_Definition::CATEGORY_MEMBERSHIP     => __( 'Socios e membresía', 'anpa-socios' ),
			ANPA_Socios_Email_Template_Definition::CATEGORY_ACTIVITIES     => __( 'Actividades', 'anpa-socios' ),
			ANPA_Socios_Email_Template_Definition::CATEGORY_COMPANIES      => __( 'Empresas', 'anpa-socios' ),
			ANPA_Socios_Email_Template_Definition::CATEGORY_COMMUNICATIONS => __( 'Comunicacións', 'anpa-socios' ),
			ANPA_Socios_Email_Template_Definition::CATEGORY_SYSTEM         => __( 'Sistema', 'anpa-socios' ),
		);
	}

	/**
	 * Galician label for an audience value.
	 *
	 * @since  TBD
	 * @param  string $audience Audience constant.
	 * @return string
	 */
	private static function audience_label( string $audience ): string {
		$labels = array(
			ANPA_Socios_Email_Template_Definition::AUDIENCE_FAMILY  => __( 'Familia', 'anpa-socios' ),
			ANPA_Socios_Email_Template_Definition::AUDIENCE_BOARD   => __( 'Xunta', 'anpa-socios' ),
			ANPA_Socios_Email_Template_Definition::AUDIENCE_COMPANY => __( 'Empresa', 'anpa-socios' ),
		);

		return isset( $labels[ $audience ] ) ? $labels[ $audience ] : $audience;
	}
}
