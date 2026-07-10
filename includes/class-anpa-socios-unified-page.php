<?php
/**
 * Page renderer and asset loader for the unified "Área de socios" page.
 *
 * Replaces both [anpa_socios_asociarse] and [anpa_socios_area] with a single
 * entry point that detects the user's status and routes accordingly.
 *
 * Visual styling is intentionally delegated to the active WordPress theme.
 * Only structural CSS (step visibility, spinner, actions row) is provided
 * by the plugin.
 *
 * @since  1.21.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [anpa_socios_area] shortcode and loads its assets.
 *
 * @since 1.21.0
 */
class ANPA_Socios_Unified_Page {

	/**
	 * Shortcode callback for the unified socios page.
	 *
	 * Renders a light container with all REST URLs as data attributes.
	 * The JS (unified.js) decides which section to show based on session status.
	 *
	 * @since  1.21.0
	 * @param  array $atts Shortcode attributes (none used).
	 * @return string
	 */
	public static function render( $atts ): string {
		$preflight_url        = rest_url( 'anpa-socios/v1/area/preflight' );
		$solicitar_codigo_url = rest_url( 'anpa/v1/solicitar-codigo' );
		// Alta (new member) code endpoint: issues a code to ANY valid email.
		// The login endpoint above (anpa/v1/solicitar-codigo) only sends codes
		// to already-registered active socios, so the alta branch MUST use this
		// one — otherwise a new applicant never receives a code.
		$solicitar_codigo_alta_url = rest_url( 'anpa-socios/v1/solicitar-codigo-alta' );
		$verificar_codigo_url = rest_url( 'anpa/v1/verificar-codigo' );
		$session_url          = rest_url( 'anpa-socios/v1/area/session' );
		$session_status_url   = rest_url( 'anpa-socios/v1/area/session-status' );
		$profile_url          = rest_url( 'anpa-socios/v1/area/me' );
		$logout_url           = rest_url( 'anpa-socios/v1/area/me/session' );
		$baixa_url            = rest_url( 'anpa-socios/v1/area/me/baixa' );
		$baixa_cancel_url     = rest_url( 'anpa-socios/v1/area/me/baixa/cancel' );
		$reactivar_url        = rest_url( 'anpa-socios/v1/area/reactivar' );
		$referencias_url      = rest_url( 'anpa-socios/v1/area/referencias' );
		$alta_url             = rest_url( 'anpa-socios/v1/alta' );
		$fillos_url           = rest_url( 'anpa-socios/v1/fillos' );
		$fillo_url            = rest_url( 'anpa-socios/v1/fillo/' );
		$proxenitor2_url      = rest_url( 'anpa-socios/v1/area/me/proxenitor2' );
		$admin_base_url       = rest_url( 'anpa-socios/v1/admin/' );

		// Pre-season lifecycle: when the current course has not started yet,
		// non-admin socios cannot log in. We render a prominent notice; the
		// login form stays available so admins/master can still access.
		$season          = ANPA_Socios_Season_Service::current_course_row();
		$is_preseason    = ANPA_Socios_Season::ESTADO_PENDENTE === (string) $season['estado'];
		$preseason_curso = (string) $season['curso_escolar'];
		$preseason_date  = ANPA_Socios_Preseason_Guard::format_date_gl( (string) $season['data_inicio'] );

		// Prefer the new child area page under /socios/ so existing members
		// land on a real area page after verification, not back on this entry.
		$area_page_url = ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area_persoal' );
		$new_area      = get_page_by_path( 'socios/area-persoal' );
		if ( $new_area ) {
			$area_page_url = (string) get_permalink( $new_area->ID );
		}

		// Resolve the public alta page dynamically so we don't hardcode
		// `/socios/asociarse/` (that path now redirects to /socios-old/).
		// find_page_url() locates the published page that actually contains
		// the [anpa_socios_asociarse] shortcode, wherever it currently lives.
		$alta_page_url = ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_asociarse' );

		ob_start();
		?>
		<div id="anpa-unified"
			data-preflight-url="<?php echo esc_attr( $preflight_url ); ?>"
			data-request-code-url="<?php echo esc_attr( $solicitar_codigo_url ); ?>"
			data-request-code-alta-url="<?php echo esc_attr( $solicitar_codigo_alta_url ); ?>"
			data-verify-code-url="<?php echo esc_attr( $verificar_codigo_url ); ?>"
			data-session-url="<?php echo esc_attr( $session_url ); ?>"
			data-session-status-url="<?php echo esc_attr( $session_status_url ); ?>"
			data-profile-url="<?php echo esc_attr( $profile_url ); ?>"
			data-logout-url="<?php echo esc_attr( $logout_url ); ?>"
			data-baixa-url="<?php echo esc_attr( $baixa_url ); ?>"
			data-baixa-cancel-url="<?php echo esc_attr( $baixa_cancel_url ); ?>"
			data-reactivar-url="<?php echo esc_attr( $reactivar_url ); ?>"
			data-referencias-url="<?php echo esc_attr( $referencias_url ); ?>"
			data-alta-url="<?php echo esc_attr( $alta_url ); ?>"
			data-fillos-url="<?php echo esc_attr( $fillos_url ); ?>"
			data-fillo-url="<?php echo esc_attr( $fillo_url ); ?>"
			data-proxenitor2-url="<?php echo esc_attr( $proxenitor2_url ); ?>"
			data-admin-base-url="<?php echo esc_attr( $admin_base_url ); ?>"
			data-preseason="<?php echo $is_preseason ? '1' : '0'; ?>"
			data-preseason-date="<?php echo esc_attr( $preseason_date ); ?>"
			data-area-page-url="<?php echo esc_attr( $area_page_url ); ?>"
			data-alta-page-url="<?php echo esc_attr( (string) $alta_page_url ); ?>"
			data-landing-url="<?php echo esc_attr( ANPA_Socios_Admin_Settings::landing_page_url() ); ?>">

			<?php if ( $is_preseason ) : ?>
			<!-- PRE-SEASON: course not started yet; only admins can log in. -->
			<div class="anpa-preseason-notice anpa-aviso" role="status">
				<h2><?php echo sprintf( esc_html__( 'O curso escolar %s aínda non comezou', 'anpa-socios' ), esc_html( $preseason_curso ) ); ?></h2>
				<p><?php echo sprintf( __( 'A alta de socios e a matriculación nas actividades extraescolares abriranse automaticamente o <strong>%s</strong>. O proceso farase de xeito automático, non tes que facer nada agora.', 'anpa-socios' ), esc_html( $preseason_date ) ); ?></p>
				<p><?php esc_html_e( 'Mentres tanto, só o equipo administrador pode iniciar sesión.', 'anpa-socios' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- PERSISTENT NOTICES -->
			<div class="anpa-unified-notice" data-notice hidden></div>
			<div class="anpa-unified-busy" data-busy hidden>
				<span class="anpa-area-spinner" role="status" aria-label="<?php esc_attr_e( 'Traballando', 'anpa-socios' ); ?>"></span>
			</div>

			<!-- STEP: alta -- email input for new members -->
			<div data-step="alta" hidden>
				<h2><?php echo sprintf( esc_html__( 'Acceder ó Área de socios/as da %s', 'anpa-socios' ), esc_html( ANPA_Socios_Config::association_name() ) ); ?></h2>
				<p><?php esc_html_e( 'O formulario de alta require cubrir todos os campos obrigatorios e aceptar a política de privacidade. Recibirás un código de verificación no teu correo para confirmar a identidade.', 'anpa-socios' ); ?></p>
				<label for="anpa-unified-email"><?php esc_html_e( 'Email', 'anpa-socios' ); ?></label>
				<input id="anpa-unified-email" type="email" autocomplete="email" required>
				<div style="position:absolute;left:-9999px" aria-hidden="true">
					<input type="text" id="anpa-unified-website" name="website" tabindex="-1" autocomplete="off" value="">
				</div>
				<input type="hidden" id="anpa-unified-ts" name="_ts" value="<?php echo (int) time(); ?>">
				<div class="anpa-unified-actions">
					<button type="button" class="wp-element-button" data-action="request-code-alta"><?php esc_html_e( 'Enviar código', 'anpa-socios' ); ?></button>
				</div>
			</div>

			<!-- STEP: code -- verification code entry -->
			<div data-step="code" hidden>
				<h2><?php esc_html_e( 'Revisa o teu correo', 'anpa-socios' ); ?></h2>
				<p><?php esc_html_e( 'Escribe o código recibido para continuar.', 'anpa-socios' ); ?></p>
				<label for="anpa-unified-code"><?php esc_html_e( 'Código de 6 díxitos', 'anpa-socios' ); ?></label>
				<input id="anpa-unified-code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
				<div class="anpa-unified-actions">
					<button type="button" class="wp-element-button" data-action="verify-code"><?php esc_html_e( 'Verificar', 'anpa-socios' ); ?></button>
					<button type="button" class="wp-element-button is-style-outline" data-action="back-email"><?php esc_html_e( 'Cambiar email', 'anpa-socios' ); ?></button>
				</div>
			</div>

			<!-- STEP: baixa_solicitada -- pending cancellation -->
			<div data-step="baixa-solicitada" hidden>
				<h2><?php esc_html_e( 'Solicitude de baixa pendente', 'anpa-socios' ); ?></h2>
				<p><?php esc_html_e( 'Recibimos a túa solicitude de baixa. Se foi un erro, podes anulala e seguir sendo socio/a.', 'anpa-socios' ); ?></p>
				<div class="anpa-unified-actions">
					<button type="button" class="wp-element-button" data-action="cancel-baixa"><?php esc_html_e( 'Anular solicitude de baixa', 'anpa-socios' ); ?></button>
					<button type="button" class="wp-element-button is-style-outline" data-action="area-confirm"><?php esc_html_e( 'Ir á miña área', 'anpa-socios' ); ?></button>
				</div>
			</div>

			<!-- STEP: inactivo -- member has been deactivated -->
			<div data-step="inactivo" hidden>
				<h2><?php esc_html_e( 'A túa conta está dada de baixa', 'anpa-socios' ); ?></h2>
				<p><?php esc_html_e( 'Se queres volver ser socio/a, solicita a reactivación e a directiva revisará a túa petición.', 'anpa-socios' ); ?></p>
				<div class="anpa-unified-actions">
					<button type="button" class="wp-element-button" data-action="request-reactivar"><?php esc_html_e( 'Solicitar reactivación', 'anpa-socios' ); ?></button>
				</div>
			</div>

			<!-- STEP: area -- logged-in dashboard (redirect by default) -->
			<div data-step="area" hidden>
				<h2><?php esc_html_e( 'Benvido/a á túa área de socio/a', 'anpa-socios' ); ?></h2>
				<p><?php esc_html_e( 'Redirixíndoche á túa área persoal...', 'anpa-socios' ); ?></p>
			</div>

			<!-- STEP: error -- generic error display -->
			<div data-step="error" hidden>
				<h2><?php esc_html_e( 'Houbo un problema', 'anpa-socios' ); ?></h2>
				<p data-error-text></p>
				<div class="anpa-unified-actions">
					<button type="button" class="wp-element-button is-style-outline" data-action="back-email"><?php esc_html_e( 'Tentar de novo', 'anpa-socios' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Embedded alta form. Hidden until the alta flow verifies the code;
		     unified.js then reveals it and calls AnpaAlta.initAltaForm() with
		     the verified token, so the whole alta happens on this one page. -->
		<div id="anpa-alta-form-host" hidden>
			<?php
			// render_alta_form() returns pre-built, escaped markup.
			echo ANPA_Socios_Socios_Page::render_alta_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueues assets for the unified page.
	 *
	 * @since  1.21.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'anpa_socios_area' ) ) {
			return;
		}

		$js_path     = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/unified.js';
		$css_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/unified.css';
		$js_version  = file_exists( $js_path ) ? (int) filemtime( $js_path ) : ANPA_SOCIOS_VERSION;
		$css_version = file_exists( $css_path ) ? (int) filemtime( $css_path ) : ANPA_SOCIOS_VERSION;

		wp_enqueue_script(
			'anpa-socios-unified',
			plugins_url( 'assets/js/unified.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array( 'wp-i18n' ),
			$js_version,
			true
		);
		wp_set_script_translations( 'anpa-socios-unified', 'anpa-socios', ANPA_SOCIOS_PLUGIN_DIR . 'languages' );

		wp_enqueue_style(
			'anpa-socios-unified',
			plugins_url( 'assets/css/unified.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$css_version
		);

		// The embedded alta form is driven by asociarse.js (window.AnpaAlta)
		// and styled by asociarse.css, so both must load on the unified page.
		$alta_js_path  = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/asociarse.js';
		$alta_css_path = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/asociarse.css';
		$alta_js_ver   = file_exists( $alta_js_path ) ? (int) filemtime( $alta_js_path ) : ANPA_SOCIOS_VERSION;
		$alta_css_ver  = file_exists( $alta_css_path ) ? (int) filemtime( $alta_css_path ) : ANPA_SOCIOS_VERSION;

		wp_enqueue_script(
			'anpa-socios-asociarse',
			plugins_url( 'assets/js/asociarse.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array( 'wp-i18n' ),
			$alta_js_ver,
			true
		);
		wp_set_script_translations( 'anpa-socios-asociarse', 'anpa-socios', ANPA_SOCIOS_PLUGIN_DIR . 'languages' );
		wp_enqueue_style(
			'anpa-socios-asociarse',
			plugins_url( 'assets/css/asociarse.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$alta_css_ver
		);
	}
}
