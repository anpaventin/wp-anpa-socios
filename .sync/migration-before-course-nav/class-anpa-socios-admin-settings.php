<?php
/**
 * Plugin onboarding surface (fase12): Plugins-page action links + an admin
 * settings screen that (a) runs a first-run setup wizard on a clean install
 * (banking passphrase → creates the sealed-box banking key, the socios page,
 * and configures the season), (b) lets admins edit the config afterwards,
 * and (c) an offline docs mini-wiki.
 *
 * The setup is handled INLINE on the settings page (self-POST) so its result —
 * including the one-time escrow secret — renders inside the WordPress admin UI.
 * All screens and write actions require `manage_options` + nonce.
 *
 * @since  1.22.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Admin_Settings {

	const CAP            = 'manage_options';
	const SETTINGS_SLUG  = 'anpa-socios-settings';
	const DOCS_SLUG      = 'anpa-socios-docs';
	const MIN_PASSPHRASE = 12;
	const LANDING_OPTION = 'anpa_socios_landing_page_id';
	const AREA_SHORTCODE = '[anpa_socios_area]';

	/**
	 * Wires menu, action links and admin-post handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ANPA_SOCIOS_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_anpa_socios_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_anpa_socios_save_location', array( __CLASS__, 'handle_save_location' ) );
		add_action( 'admin_post_anpa_socios_save_cursos', array( __CLASS__, 'handle_save_cursos' ) );
		add_action( 'admin_post_anpa_socios_run_season', array( __CLASS__, 'handle_run_season' ) );
		add_action( 'admin_post_anpa_socios_check_updates', array( __CLASS__, 'handle_check_updates' ) );
		add_action( 'admin_post_anpa_socios_backup', array( __CLASS__, 'handle_backup' ) );
		add_action( 'admin_post_anpa_socios_wipe', array( __CLASS__, 'handle_wipe' ) );
		add_action( 'admin_post_anpa_socios_restore', array( __CLASS__, 'handle_restore' ) );
	}

	/**
	 * Adds "Axustes" and "Documentación" to the plugin row.
	 *
	 * @param  string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$axustes = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ), esc_html__( 'Axustes', 'anpa-socios' ) );
		$docs    = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG ) ), esc_html__( 'Documentación', 'anpa-socios' ) );
		array_unshift( $links, $axustes, $docs );

		return $links;
	}

	/**
	 * Registers the ANPA admin menu and ordered submenus.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page( 'ANPA Socios', 'ANPA Socios', self::CAP, self::SETTINGS_SLUG, array( __CLASS__, 'render_settings_page' ), 'dashicons-groups', 58 );
		$submenus = ANPA_Socios_Admin_Nav::plugin_submenus();
		$settings = $submenus['settings'];
		$docs     = $submenus['documentation'];
		add_submenu_page( self::SETTINGS_SLUG, $settings['page_title'], $settings['menu_label'], self::CAP, $settings['slug'], array( __CLASS__, 'render_settings_page' ) );
		ANPA_Socios_Admin_Management_Page::register_menu( self::SETTINGS_SLUG, self::CAP );
		add_submenu_page( self::SETTINGS_SLUG, $docs['page_title'], $docs['menu_label'], self::CAP, $docs['slug'], array( __CLASS__, 'render_docs_page' ) );
	}

	/**
	 * Whether the plugin has completed first-run setup.
	 *
	 * @return bool
	 */
	private static function is_setup_done(): bool {
		return ANPA_Socios_Banking_Key::is_configured();
	}

	/**
	 * Settings screen dispatcher. Handles the setup self-POST inline so its
	 * result renders inside the WordPress admin UI.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		echo '<div class="wrap anpa-cfg">';
		echo self::admin_styles();
		echo '<h1>ANPA Socios — Axustes</h1>';

		$is_setup_post = ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['anpa_action'] ) && 'setup' === $_POST['anpa_action'] );
		if ( $is_setup_post ) {
			self::process_setup_inline();
			self::render_eye_script();
			echo '</div>';
			return;
		}

		self::render_flash();
		if ( self::is_setup_done() ) {
			self::render_tabs();
		} else {
			self::render_setup_wizard();
		}
		self::render_eye_script();

		echo '<p style="margin-top:16px"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG ) ) . '">' . esc_html__( 'Ver documentación', 'anpa-socios' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * First-run setup wizard (self-POST to the settings page).
	 *
	 * @return void
	 */
	private static function render_setup_wizard(): void {
		$self_url    = esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
		$suggested   = ANPA_Socios_Crypto::generate_passphrase();
		$curso       = ANPA_Socios_Curso_Escolar::current();
		$data_inicio = ANPA_Socios_Season::default_data_inicio( $curso );
		$data_peche  = ANPA_Socios_Season::default_data_peche( $curso );
		$detected    = get_page_by_path( 'socios' );
		$sel_page    = $detected instanceof WP_Post ? $detected->ID : 0;

		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Instalación limpa.', 'anpa-socios' ) . '</strong> ' . esc_html__( 'Configura a clave bancaria e a páxina de socios para poñer en marcha o sistema. Este paso só se fai unha vez.', 'anpa-socios' ) . '</p></div>';

		echo '<form method="post" action="' . $self_url . '">';
		echo '<input type="hidden" name="anpa_action" value="setup">';
		wp_nonce_field( 'anpa_socios_setup' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="anpa-passphrase">' . esc_html__( 'Frase da clave bancaria (mín. 5 palabras)', 'anpa-socios' ) . '</label></th><td>';
		printf(
			'<input name="passphrase" id="anpa-passphrase" type="text" class="regular-text code" value="%s" size="48" required minlength="%d">',
			esc_attr( $suggested ),
			(int) self::MIN_PASSPHRASE
		);
		printf( ' <a class="button" href="%s">%s</a>', $self_url, esc_html__( 'Xerar outra', 'anpa-socios' ) );
		echo '<p class="description"><strong>' . esc_html__( 'Garda esta frase nun lugar seguro.', 'anpa-socios' ) . '</strong> ' . esc_html__( 'Protexe os datos bancarios cifrados. Se a perdes, os datos serán irrecuperables e só se poderá cambiar reinstalando a base de datos.', 'anpa-socios' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="anpa-socios-page">' . esc_html__( 'Páxina de socios', 'anpa-socios' ) . '</label></th><td>';
		wp_dropdown_pages( array(
			'name'              => 'socios_page',
			'id'                => 'anpa-socios-page',
			'selected'          => $sel_page,
			'show_option_none'  => "— Crear nova páxina 'Socios' —",
			'option_none_value' => 'new',
		) );
		echo '<p class="description">A instalación <strong>sobrescribirá</strong> o contido desta páxina coa área de socios (' . esc_html( self::AREA_SHORTCODE ) . '). Se escolles "crear nova", crearase en <code>/socios/</code>.</p></td></tr>';

		printf(
			'<tr><th scope="row"><label for="anpa-curso">%s</label></th><td><input name="curso_escolar" id="anpa-curso" type="text" value="%s" pattern="\d{4}/\d{4}" class="regular-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Curso escolar actual', 'anpa-socios' ),
			esc_attr( $curso ),
			esc_html__( 'Formato AAAA/AAAA+1. Proposto para hoxe.', 'anpa-socios' )
		);
		printf( '<tr><th scope="row"><label for="anpa-inicio">%s</label></th><td><input name="data_inicio" id="anpa-inicio" type="date" value="%s"></td></tr>', esc_html__( 'Comeza (data_inicio)', 'anpa-socios' ), esc_attr( $data_inicio ) );
		printf( '<tr><th scope="row"><label for="anpa-peche">%s</label></th><td><input name="data_peche" id="anpa-peche" type="date" value="%s"></td></tr>', esc_html__( 'Pecha (data_peche)', 'anpa-socios' ), esc_attr( $data_peche ) );

		echo '</tbody></table>';
		submit_button( __( 'Lanzar instalación', 'anpa-socios' ) );
		echo '</form>';
	}

	/**
	 * Processes the setup self-POST and renders the result inline (styled).
	 *
	 * @return void
	 */
	private static function process_setup_inline(): void {
		check_admin_referer( 'anpa_socios_setup' );

		$passphrase  = (string) wp_unslash( $_POST['passphrase'] ?? '' );
		$socios_page = sanitize_text_field( (string) wp_unslash( $_POST['socios_page'] ?? 'new' ) );
		$curso       = sanitize_text_field( (string) wp_unslash( $_POST['curso_escolar'] ?? '' ) );
		$inicio      = sanitize_text_field( (string) wp_unslash( $_POST['data_inicio'] ?? '' ) );
		$peche       = sanitize_text_field( (string) wp_unslash( $_POST['data_peche'] ?? '' ) );

		// Validation — on error, show notice and re-render the wizard.
		$error = '';
		if ( strlen( $passphrase ) < self::MIN_PASSPHRASE ) {
			/* translators: %d: minimum character count for the passphrase */
			$error = sprintf( __( 'A frase da clave debe ter polo menos %d caracteres.', 'anpa-socios' ), self::MIN_PASSPHRASE );
		}
		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
			self::render_setup_wizard();
			return;
		}

		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			$curso = ANPA_Socios_Curso_Escolar::current();
		}
		$inicio = self::valid_date( $inicio, ANPA_Socios_Season::default_data_inicio( $curso ) );
		$peche  = self::valid_date( $peche, ANPA_Socios_Season::default_data_peche( $curso ) );

		// 1) Schema.
		ANPA_Socios_DB::crear_tabelas();

		// 2) Season config.
		self::upsert_course( $curso, $inicio, $peche );

		// 3) Socios page (create or overwrite) with the area shortcode.
		$page_id = self::ensure_socios_page( $socios_page );

		// 3b) Ensure the signup (asociarse) and extraescolares pages exist so the
		// alta flow and the public activities/timetable work out of the box.
		self::ensure_page_by_shortcode( 'anpa_socios_asociarse', 'asociarse', 'Asociarse', '[anpa_socios_asociarse]' );
		// Extraescolares: create only if absent; NEVER overwrite an existing page.
		self::ensure_page_by_shortcode( 'anpa_extraescolares_ofertadas', 'extraescolares', 'Extraescolares', self::extraescolares_page_content(), false );

		// 4) Sealed-box banking key (once).
		$secret_key = null;
		if ( ! ANPA_Socios_Banking_Key::is_configured() && null === ANPA_Socios_Banking_Key::wrapped_secret() ) {
			$keypair = ANPA_Socios_Crypto::generate_keypair();
			$wrapped = ! empty( $keypair['secret'] ) ? ANPA_Socios_Crypto::wrap_secret( $keypair['secret'], $passphrase ) : null;
			if ( empty( $keypair['public'] ) || null === $wrapped ) {
				echo '<div class="notice notice-error"><p>Erro ao xerar a clave bancaria. Téntao de novo.</p></div>';
				self::render_setup_wizard();
				return;
			}
			ANPA_Socios_Banking_Key::store( $keypair['public'], $wrapped );
			$secret_key = (string) $keypair['secret'];
		}

		self::render_setup_result( $passphrase, $secret_key, $page_id );
	}

	/**
	 * Renders the styled, in-admin setup result (escrow shown once).
	 *
	 * @param  string      $email      Master email.
	 * @param  string      $passphrase Banking passphrase.
	 * @param  string|null $secret_key Base64 secret key (null if key pre-existed).
	 * @param  int         $page_id    Socios page id.
	 * @return void
	 */
	private static function render_setup_result( string $passphrase, ?string $secret_key, int $page_id ): void {
		nocache_headers();
		$area_url     = $page_id > 0 ? (string) get_permalink( $page_id ) : self::landing_page_url();
		$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );

		echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Instalación completada.', 'anpa-socios' ) . '</strong> ' . esc_html__( 'A clave bancaria e a páxina de socios quedaron configurados.', 'anpa-socios' ) . '</p></div>';

		echo '<div class="notice notice-warning" style="padding:8px 12px"><p><strong>' . esc_html__( 'Garda esta información AGORA. Non se volverá amosar.', 'anpa-socios' ) . '</strong></p>';
		echo '<table class="widefat" style="max-width:760px"><tbody>';
		printf( '<tr><td style="width:220px"><strong>%s</strong></td><td><code>%s</code></td></tr>', esc_html__( 'Frase da clave bancaria', 'anpa-socios' ), esc_html( $passphrase ) );
		if ( null !== $secret_key && '' !== $secret_key ) {
			printf( '<tr><td><strong>%s</strong></td><td><code style="word-break:break-all">%s</code></td></tr>', esc_html__( 'Clave privada (escrow)', 'anpa-socios' ), esc_html( $secret_key ) );
		} else {
			echo '<tr><td><strong>' . esc_html__( 'Clave privada', 'anpa-socios' ) . '</strong></td><td><em>' . esc_html__( 'xa existía; non se rexenerou.', 'anpa-socios' ) . '</em></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Sen a frase e a clave privada, os datos bancarios cifrados serán irrecuperables.', 'anpa-socios' ) . '</p></div>';

		echo '<h2>' . esc_html__( 'Seguintes pasos', 'anpa-socios' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'Podes editar a configuración en calquera momento desde esta páxina de Axustes.', 'anpa-socios' ) . '</li>';
		printf(
			'<li>' . esc_html__( 'Para amosar as actividades extraescolares e o horario, consulta a %1$sdocumentación%2$s co código a pegar na páxina de extraescolares (e suxestións de FAQ e cabeceira).', 'anpa-socios' ) . '</li>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG ) ) . '">',
			'</a>'
		);
		echo '</ol>';
		printf( '<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>', esc_url( $settings_url ), esc_html__( 'Ir a Axustes', 'anpa-socios' ), esc_url( $area_url ), esc_html__( 'Abrir a páxina de socios', 'anpa-socios' ) );
	}

	/**
	 * Post-setup configuration editor.
	 *
	 * @return void
	 */
	private static function render_tabs(): void {
		$requested_tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$requested_section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		// Backwards-compatible deep links from the old flat tabs.
		if ( 'verificacion' === $requested_tab || 'actualizacions' === $requested_tab ) {
			$requested_section = $requested_tab;
			$requested_tab     = 'xeral';
		}

		$active  = ANPA_Socios_Admin_Nav::active_settings_tab( $requested_tab );
		$section = ANPA_Socios_Admin_Nav::active_settings_section( $active, $requested_section );
		$base    = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Pestañas de axustes', 'anpa-socios' ) . '">';
		foreach ( ANPA_Socios_Admin_Nav::settings_tabs() as $slug => $label ) {
			$is_active = ( $active === $slug );
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( add_query_arg( 'tab', $slug, $base ) ),
				$is_active ? ' nav-tab-active' : '',
				$is_active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		self::render_section_nav( $active, $section, $base );

		echo '<div class="anpa-tab-panel">';
		switch ( $active ) {
			case 'cursos':
				self::render_tab_cursos();
				break;
			case 'localizacion':
				self::render_tab_localizacion();
				break;
			case 'xeral':
			default:
				self::render_tab_xeral( $section );
				break;
		}
		echo '</div>';
	}

	/**
	 * Renders the second-level navigation for settings tabs with subsections.
	 *
	 * @param  string $tab     Active tab.
	 * @param  string $section Active section.
	 * @param  string $base    Base admin URL.
	 * @return void
	 */
	private static function render_section_nav( string $tab, string $section, string $base ): void {
		$sections = ANPA_Socios_Admin_Nav::settings_sections( $tab );
		if ( array() === $sections ) {
			return;
		}

		echo '<nav class="anpa-section-nav" aria-label="' . esc_attr__( 'Subseccións', 'anpa-socios' ) . '">';
		$links = array();
		foreach ( $sections as $slug => $label ) {
			$is_active = ( $section === $slug );
			$links[] = sprintf(
				'<a href="%s" class="anpa-section-link%s"%s>%s</a>',
				esc_url( add_query_arg( array( 'tab' => $tab, 'section' => $slug ), $base ) ),
				$is_active ? ' current' : '',
				$is_active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo implode( '', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links are escaped above.
		echo '</nav>';
	}

	/**
	 * Tab "Xeral": status summary + editable configuration (Settings-style form
	 * handled via admin-post + PRG).
	 *
	 * @return void
	 */
	private static function render_tab_xeral( string $section = 'estado' ): void {
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$master   = ANPA_Socios_Config::master_email();

		if ( 'estado' === $section ) {
			echo '<h2>' . esc_html__( 'Estado', 'anpa-socios' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:680px"><tbody>';
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Versión do plugin', 'anpa-socios' ), esc_html( defined( 'ANPA_SOCIOS_VERSION' ) ? ANPA_SOCIOS_VERSION : '?' ) );
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Versión da base de datos', 'anpa-socios' ), esc_html( (string) get_option( 'anpa_socios_db_version', __( '(non instalada)', 'anpa-socios' ) ) ) );
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Clave bancaria', 'anpa-socios' ), ANPA_Socios_Banking_Key::is_configured() ? '✅ ' . esc_html__( 'configurada', 'anpa-socios' ) : '❌ ' . esc_html__( 'sen configurar', 'anpa-socios' ) );
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Email do equipo administrador', 'anpa-socios' ), esc_html( ANPA_Socios_Config::master_email() ) );

			// WP Mail SMTP status check.
			$wp_mail_smtp = is_plugin_active( 'wp-mail-smtp/wp_mail_smtp.php' );
			if ( $wp_mail_smtp ) {
				// Check if a mailer is configured.
				$mailer_option = get_option( 'wp_mail_smtp', array() );
				$mailer_configured = ! empty( $mailer_option['mail']['from_email'] ) || ! empty( $mailer_option['mail']['from_name'] );
				printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'WP Mail SMTP', 'anpa-socios' ), $mailer_configured ? '✅ ' . esc_html__( 'configurado', 'anpa-socios' ) : '⚠️ ' . esc_html__( 'plugin activo sen configurar', 'anpa-socios' ) );
			} else {
				printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'WP Mail SMTP', 'anpa-socios' ), '❌ ' . esc_html__( 'non detectado', 'anpa-socios' ) );
			}

			// MailPoet status check.
			$mailpoet_active = is_plugin_active( 'mailpoet/mailpoet.php' );
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'MailPoet', 'anpa-socios' ), $mailpoet_active ? '✅ ' . esc_html__( 'activo', 'anpa-socios' ) : '❌ ' . esc_html__( 'inactivo', 'anpa-socios' ) );

			// Verification module status.
			$legacy = defined( 'ANPA_VERIFICACION_VERSION' );
			if ( $legacy ) {
				printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Verificación por email', 'anpa-socios' ), '⚠️ ' . esc_html__( 'plugin legado activo', 'anpa-socios' ) . ' v' . esc_html( (string) constant( 'ANPA_VERIFICACION_VERSION' ) ) );
			} else {
				echo '<tr><td><strong>' . esc_html__( 'Verificación por email', 'anpa-socios' ) . '</strong></td><td>✅ ' . esc_html__( 'integrada en ANPA Socios', 'anpa-socios' ) . '</td></tr>';
			}

			// Sistema de rexistro (logs).
			$audit_table = ANPA_Socios_DB::tabela_audit_log();
			global $wpdb;
			$audit_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $audit_table ) ) === $audit_table;
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Sistema de rexistro (logs)', 'anpa-socios' ), $audit_exists ? '✅ ' . esc_html__( 'dispoñible', 'anpa-socios' ) : '❌ ' . esc_html__( 'táboa non atopada', 'anpa-socios' ) );

			echo '</tbody></table>';

			// ── Actualizacións (shown inside Estado) ──
			self::render_subsection_actualizacions( $post_url );

			return;
		}

		// Mantemento section — copias, contrasinais, ferramentas.
		if ( 'mantemento' === $section ) {
			self::render_subsection_contrasinais( $post_url );
			self::render_subsection_copias( $post_url );
			self::render_subsection_ferramentas( $post_url );
			return;
		}

		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_save_settings">';
		echo '<input type="hidden" name="tab" value="xeral">';
		echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '">';
		wp_nonce_field( 'anpa_socios_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		if ( 'paxinas' === $section ) {
			echo '<h2>' . esc_html__( 'Páxinas e shortcodes', 'anpa-socios' ) . '</h2>';
			echo '<tr><th scope="row"><label for="cfg-landing">' . esc_html__( 'Páxina de socios', 'anpa-socios' ) . '</label></th><td>';
			wp_dropdown_pages( array(
				'name'              => 'landing_page_id',
				'id'                => 'cfg-landing',
				'selected'          => (int) get_option( self::LANDING_OPTION, 0 ),
				'show_option_none'  => '— Detección automática —',
				'option_none_value' => 0,
			) );
			echo '<p class="description">' . esc_html__( 'Páxina que contén a área de socios.', 'anpa-socios' ) . '</p></td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Shortcodes principais', 'anpa-socios' ) . '</th><td>';
			echo '<code>[anpa_socios_area]</code> — ' . esc_html__( 'Área principal de socios (login, perfil, fillos, extraescolares).', 'anpa-socios' ) . '<br>';
			echo '<code>[anpa_socios_area_persoal]</code> — ' . esc_html__( 'Área persoal do socio (perfil e fillos).', 'anpa-socios' ) . '<br>';
			echo '<code>[anpa_socios_asociarse]</code> — ' . esc_html__( 'Formulario de alta de socios.', 'anpa-socios' ) . '<br>';
			echo '<code>[anpa_extraescolares_ofertadas]</code> — ' . esc_html__( 'Tarxetas de actividades ofertadas no curso actual.', 'anpa-socios' ) . '<br>';
			echo '<code>[anpa_extraescolares_horario]</code> — ' . esc_html__( 'Grella semanal con horarios e grupos activos.', 'anpa-socios' ) . '<br>';
			echo '</td></tr>';
			echo '</tbody></table>';
			submit_button( __( 'Gardar páxinas', 'anpa-socios' ) );
			echo '</form>';
			return;
		}

		echo '<h2>' . esc_html__( 'Configuración', 'anpa-socios' ) . '</h2>';
		printf( '<tr><th scope="row"><label for="cfg-master">%s</label></th><td><input name="master_email" id="cfg-master" type="email" class="regular-text" value="%s"></td></tr>', esc_html__( 'Email do equipo administrador', 'anpa-socios' ), esc_attr( $master ) );
		printf(
			'<tr><th scope="row"><label for="cfg-assoc">%s</label></th><td><input name="association_name" id="cfg-assoc" type="text" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Nome da asociación', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::association_name() ),
			esc_html__( 'Úsase en toda a app (correos, avisos) no canto dun valor fixo.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="cfg-sign">%s</label></th><td><textarea name="email_signature" id="cfg-sign" class="large-text" rows="3">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Firma dos correos', 'anpa-socios' ),
			esc_textarea( ANPA_Socios_Config::email_signature() ),
			esc_html__( 'Engádese ao final dos correos enviados dende a conta do equipo administrador.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="require_approval" value="1" %s> %s</label></td></tr>',
			esc_html__( 'Aprobación de socios novos', 'anpa-socios' ),
			checked( ANPA_Socios_Config::require_approval(), true, false ),
			esc_html__( 'Os socios novos precisan aprobación do equipo administrador antes de acceder.', 'anpa-socios' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Gardar configuración', 'anpa-socios' ) );
		echo '</form>';
	}

	/**
	 * Tab "Cursos": unified single page with course selector, editor, and create-new form.
	 *
	 * @return void
	 */
	private static function render_tab_cursos(): void {
		global $wpdb;
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$self_url = esc_url( admin_url( 'admin.php' ) );
		$aula_max = ANPA_Socios_Config::aula_max();
		$cursos_t = ANPA_Socios_DB::tabela_cursos();

		// All stored courses (current + past + any future already created).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only course list for the selector.
		$rows = $wpdb->get_results( "SELECT curso_escolar, matriculas_abertas, estado, data_inicio, data_peche FROM {$cursos_t}", ARRAY_A );
		$known = array();
		foreach ( (array) $rows as $r ) {
			if ( ANPA_Socios_Curso_Escolar::is_valid( (string) $r['curso_escolar'] ) ) {
				$known[ (string) $r['curso_escolar'] ] = $r;
			}
		}
		// Always offer the date-based current course and the following one, so a
		// new course can be created/activated with no code changes.
		$current = ANPA_Socios_Curso_Escolar::current();
		$next    = ANPA_Socios_Curso_Escolar::next( $current );
		foreach ( array( $current, $next ) as $c ) {
			if ( ! array_key_exists( $c, $known ) ) { $known[ $c ] = null; }
		}
		krsort( $known ); // newest first.

		// Selected course: ?curso= if valid & offered, else the date-based current.
		$sel = isset( $_GET['curso'] ) ? sanitize_text_field( wp_unslash( $_GET['curso'] ) ) : '';
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $sel ) || ! array_key_exists( $sel, $known ) ) {
			$sel = array_key_exists( $current, $known ) ? $current : (string) array_key_first( $known );
		}

		// Selected course fields (stored row or computed defaults for a new one).
		$srow = is_array( $known[ $sel ] ) ? $known[ $sel ] : array(
			'matriculas_abertas' => 0,
			'estado'      => ANPA_Socios_Season::ESTADO_PENDENTE,
			'data_inicio' => ANPA_Socios_Season::default_data_inicio( $sel ),
			'data_peche'  => ANPA_Socios_Season::default_data_peche( $sel ),
		);
		$estados = array(
			ANPA_Socios_Season::ESTADO_PENDENTE => __( 'Pendente (pre-temporada)', 'anpa-socios' ),
			ANPA_Socios_Season::ESTADO_ACTIVO   => __( 'Activo', 'anpa-socios' ),
			ANPA_Socios_Season::ESTADO_PECHADO  => __( 'Pechado', 'anpa-socios' ),
		);

		// ── Section heading ───────────────────────────────────────
		echo '<h2>' . esc_html__( 'Curso escolar', 'anpa-socios' ) . '</h2>';

		// ── Course selector (GET, auto-submits so the editor reloads) ──
		echo '<form method="get" action="' . $self_url . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SETTINGS_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="cursos">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="cfg-curso-sel">' . esc_html__( 'Curso a xestionar', 'anpa-socios' ) . '</label></th><td>';
		echo '<select name="curso" id="cfg-curso-sel" onchange="this.form.submit()">';
		foreach ( array_keys( $known ) as $c ) {
			$extra = is_array( $known[ $c ] ) ? '' : ' — ' . __( 'novo', 'anpa-socios' );
			printf( '<option value="%1$s"%2$s>%1$s%3$s</option>', esc_attr( $c ), selected( $c, $sel, false ), esc_html( $extra ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Escolle un curso existente (o actual e os pasados con datos) para editar o seu estado e datas. Os marcados como «novo» crearanse ao gardar.', 'anpa-socios' ) . '</p>';
		echo '</td></tr></tbody></table></form>';

		// ── Editor for the selected course (POST) ──
		echo '<h3>' . esc_html__( 'Datos do curso', 'anpa-socios' ) . '</h3>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_save_cursos">';
		echo '<input type="hidden" name="tab" value="cursos">';
		echo '<input type="hidden" name="section" value="curso-escolar">';
		wp_nonce_field( 'anpa_socios_save_cursos' );
		echo '<input type="hidden" name="curso_escolar" value="' . esc_attr( $sel ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row">%s</th><td><strong>%s</strong></td></tr>', esc_html__( 'Curso seleccionado', 'anpa-socios' ), esc_html( $sel ) );

		printf(
			'<tr><th scope="row">%s</th><td><strong>%s</strong><p class="description">%s</p></td></tr>',
			esc_html__( 'Estado do curso', 'anpa-socios' ),
			esc_html( $estados[ (string) $srow['estado'] ] ?? (string) $srow['estado'] ),
			esc_html__( 'O estado e a apertura de matrículas cámbianse desde Xestión ANPA → Cursos e matrículas.', 'anpa-socios' )
		);
		printf( '<tr><th scope="row"><label for="cfg-inicio">%s</label></th><td><input name="data_inicio" id="cfg-inicio" type="date" value="%s"></td></tr>', esc_html__( 'Comeza (data_inicio)', 'anpa-socios' ), esc_attr( (string) $srow['data_inicio'] ) );
		printf( '<tr><th scope="row"><label for="cfg-peche">%s</label></th><td><input name="data_peche" id="cfg-peche" type="date" value="%s"></td></tr>', esc_html__( 'Pecha (data_peche)', 'anpa-socios' ), esc_attr( (string) $srow['data_peche'] ) );

		// Máximo de liñas por curso (aula máxima). Global option; storage accepts A-H.
		echo '<tr><th scope="row"><label for="cfg-aula-max">';
		esc_html_e( 'Liñas por curso (aula máxima)', 'anpa-socios' );
		echo '</label></th><td><select name="aula_max" id="cfg-aula-max">';
		foreach ( range( 'A', 'H' ) as $letter ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $letter ), selected( $letter, $aula_max, false ) );
		}
		echo '</select><p class="description">';
		esc_html_e( 'Letra máxima de aula ofrecida ao asignar un fillo/a a un curso (por exemplo A–D ou A–E).', 'anpa-socios' );
		echo '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Gardar curso', 'anpa-socios' ) );
		echo '</form>';

		// ── Create a brand-new course ──
		echo '<h3>' . esc_html__( 'Crear novo curso', 'anpa-socios' ) . '</h3>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_save_cursos">';
		echo '<input type="hidden" name="tab" value="cursos">';
		echo '<input type="hidden" name="section" value="curso-escolar">';
		wp_nonce_field( 'anpa_socios_save_cursos' );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="cfg-curso-novo">%s</label></th><td><input name="curso_nuevo" id="cfg-curso-novo" type="text" pattern="\d{4}/\d{4}" placeholder="%s" class="regular-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Novo curso (AAAA/AAAA+1)', 'anpa-socios' ),
			esc_attr( $next ),
			esc_html__( 'Créase como «pendente» e queda seleccionado arriba para editar o seu estado e datas.', 'anpa-socios' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Crear curso', 'anpa-socios' ), 'secondary' );
		echo '</form>';
	 }

	/**
	 * Tab "Localización e idioma": country/province/town defaults shown to
	 * socios in the Provincia/Poboación fields, plus the plugin UI language.
	 * Every value is a deployer-editable option — nothing is hardcoded.
	 *
	 * @return void
	 */
	private static function render_tab_localizacion(): void {
		$post_url = esc_url( admin_url( 'admin-post.php' ) );

		echo '<h2>Localización</h2>';
		echo '<p class="description" style="max-width:720px">O país, provincia/estado e poboación que escollas aquí mostraranse <strong>por defecto</strong> aos socios nos campos <strong>Provincia</strong> e <strong>Poboación</strong> do formulario de alta (poderán escribir outros valores se o desexan). Pensado para colexios onde a maioría das familias son da mesma zona. WordPress non inclúe unha base de datos de países/provincias/concellos, así que estes campos son de texto libre.</p>';

		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_save_location">';
		wp_nonce_field( 'anpa_socios_save_location' );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="loc-country">País</label></th><td><input name="country" id="loc-country" type="text" class="regular-text" value="%s"></td></tr>',
			esc_attr( ANPA_Socios_Config::country() )
		);
		printf(
			'<tr><th scope="row"><label for="loc-province">Provincia / Estado (por defecto)</label></th><td><input name="default_province" id="loc-province" type="text" class="regular-text" value="%s"></td></tr>',
			esc_attr( ANPA_Socios_Config::default_province() )
		);
		printf(
			'<tr><th scope="row"><label for="loc-town">Poboación (por defecto)</label></th><td><input name="default_town" id="loc-town" type="text" class="regular-text" value="%s"></td></tr>',
			esc_attr( ANPA_Socios_Config::default_town() )
		);

		echo '</tbody></table>';
		submit_button( __( 'Gardar localización', 'anpa-socios' ) );
		echo '</form>';

		// Idioma: the plugin follows the WordPress site language. No custom
		// selector — WordPress is the single source of truth.
		echo '<h2>Idioma</h2>';
		$locale = get_locale();
		$names  = array(
			'gl_ES' => 'Galego',
			'es_ES' => 'Español',
			'en_US' => 'English (United States)',
			'en_GB' => 'English (UK)',
			'pt_PT' => 'Português',
			'ca'    => 'Català',
			'eu'    => 'Euskara',
		);
		$native = $names[ $locale ] ?? $locale;
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row">Idioma actual do sitio</th><td><strong>%s</strong> <code>%s</code></td></tr>',
			esc_html( (string) $native ),
			esc_html( $locale )
		);
		echo '</tbody></table>';
		printf(
			'<p class="description" style="max-width:720px">O plugin usa o idioma do sitio WordPress. Cámbiao en <a href="%s">Axustes → Xerais → Idioma do sitio</a>. As traducións do plugin engádense como ficheiros <code>.mo</code> en <code>/languages</code> (idioma orixe: galego); mentres non existan, os textos amósanse en galego.</p>',
			esc_url( admin_url( 'options-general.php' ) )
		);
	}

	/**
	 * admin-post: save the localization + language options. Isolated from the
	 * general settings save so a partial form never clears other options.
	 *
	 * @return void
	 */
	public static function handle_save_location(): void {
		self::guard( 'anpa_socios_save_location' );

		update_option( ANPA_Socios_Config::OPTION_COUNTRY, sanitize_text_field( (string) wp_unslash( $_POST['country'] ?? '' ) ) );
		update_option( ANPA_Socios_Config::OPTION_PROVINCE, sanitize_text_field( (string) wp_unslash( $_POST['default_province'] ?? '' ) ) );
		update_option( ANPA_Socios_Config::OPTION_TOWN, sanitize_text_field( (string) wp_unslash( $_POST['default_town'] ?? '' ) ) );

		self::redirect_msg( 'settings_saved' );
	}

	/**
	 * Tab "Verificación": read-only status of the email-verification flow.
	 * The full module absorption + controls arrive in fase13b.
	 *
	 * @return void
	 */
	private static function render_tab_verificacion(): void {
		$legacy = defined( 'ANPA_VERIFICACION_VERSION' );
		echo '<h2>Verificación por email</h2>';
		echo '<table class="widefat striped" style="max-width:680px"><tbody>';
		printf(
			'<tr><td style="width:260px"><strong>Módulo de verificación</strong></td><td>%s</td></tr>',
			$legacy
				? '⚠️ servido polo plugin legado «ANPA Verificación» v' . esc_html( (string) constant( 'ANPA_VERIFICACION_VERSION' ) )
				: '✅ integrado en ANPA Socios'
		);
		printf(
			'<tr><td><strong>Rutas REST <code>anpa/v1</code></strong></td><td>%s</td></tr>',
			$legacy ? 'rexístraas o plugin legado' : 'solicitar-codigo · verificar-codigo (por ANPA Socios)'
		);
		printf(
			'<tr><td><strong>Validez do código</strong></td><td>%s</td></tr>',
			'15 minutos · máx. 5 intentos por código · 3 envíos/hora'
		);
		echo '</tbody></table>';
		if ( $legacy ) {
			echo '<div class="notice notice-warning inline"><p>O plugin legado <strong>ANPA Verificación</strong> aínda está activo e é quen serve as rutas. '
				. 'ANPA Socios xa inclúe o módulo equivalente: <strong>desactiva o plugin legado</strong> (Plugins → Desactivar) e ANPA Socios tomará o relevo automaticamente. '
				. 'Despois xa podes eliminar o plugin legado.</p></div>';
		} else {
			echo '<p class="description">O módulo de verificación está integrado en ANPA Socios. Xa non se precisa o plugin «ANPA Verificación».</p>';
		}
	}

	/**
	 * Subsection: contrasinais (autenticación de administración).
	 *
	 * @param  string $post_url Admin-post URL.
	 * @return void
	 */
	private static function render_subsection_contrasinais( string $post_url ): void {
		echo '<h2>' . esc_html__( 'Autenticación de administración', 'anpa-socios' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'O acceso de administración usa as credenciais de WordPress (usuario + contrasinal). Para cambiar o teu contrasinal, accede ao teu perfil de WordPress.', 'anpa-socios' ) . '</p>';
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( admin_url( 'profile.php' ) ), esc_html__( 'Ir ao meu perfil', 'anpa-socios' ) );
	}

	/**
	 * Subsection: copias de seguridade / restauración / borrado.
	 *
	 * @param  string $post_url Admin-post URL.
	 * @return void
	 */
	private static function render_subsection_copias( string $post_url ): void {
		echo '<h2>Copia de seguridade</h2>';
		echo '<p class="description">A copia inclúe socios, fillos, actividades, cursos, matrículas, empresas e datos bancarios. O ficheiro cífrase coa frase da clave bancaria.</p>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_backup">';
		echo '<input type="hidden" name="tab" value="xeral">';
		echo '<input type="hidden" name="section" value="mantemento">';
		wp_nonce_field( 'anpa_socios_backup' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="bk-pass">Frase da clave bancaria</label></th><td><input name="banking_passphrase" id="bk-pass" type="text" class="regular-text code" autocomplete="off" required>' . self::eye_button( 'bk-pass' ) . '<p class="description">Necesaria para descifrar os datos bancarios e incluílos na copia.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Descargar copia de seguridade', 'anpa-socios' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>Recuperar copia</h2>';
		echo '<p class="description">Sube un ficheiro <code>.anpabak</code> e a frase da clave bancaria co que se cifrou. Os datos bancarios recifraranse coa clave actual.</p>';
		echo '<form method="post" action="' . $post_url . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="anpa_socios_restore">';
		echo '<input type="hidden" name="tab" value="xeral">';
		echo '<input type="hidden" name="section" value="mantemento">';
		wp_nonce_field( 'anpa_socios_restore' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="rs-file">Ficheiro de copia</label></th><td><input name="backup_file" id="rs-file" type="file" accept=".anpabak,application/json" required></td></tr>';
		echo '<tr><th scope="row"><label for="rs-pass">Frase da clave bancaria</label></th><td><input name="banking_passphrase" id="rs-pass" type="text" class="regular-text code" autocomplete="off" required>' . self::eye_button( 'rs-pass' ) . '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Recuperar copia', 'anpa-socios' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2 style="color:#b32d2e">Borrar base de datos</h2>';
		echo '<p class="description" style="color:#b32d2e"><strong>Irreversible.</strong> Borra TODOS os datos do plugin e volve ao asistente de instalación. Descarga primeiro unha copia de seguridade.</p>';
		$confirm_msg = __( 'Seguro? Esta acción borra TODOS os datos e non se pode desfacer.', 'anpa-socios' );
		echo '<form method="post" action="' . $post_url . '" onsubmit="return confirm(\'' . esc_js( $confirm_msg ) . '\');">';
		echo '<input type="hidden" name="action" value="anpa_socios_wipe">';
		echo '<input type="hidden" name="tab" value="xeral">';
		echo '<input type="hidden" name="section" value="mantemento">';
		wp_nonce_field( 'anpa_socios_wipe' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">Confirmación</th><td><label><input type="checkbox" name="confirm_wipe" value="1" required> Descarguei unha copia e entendo que esta acción é irreversible.</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Borrar base de datos', 'anpa-socios' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Subsection: ferramentas de mantemento (comprobación de temporada).
	 *
	 * @param  string $post_url Admin-post URL.
	 * @return void
	 */
	private static function render_subsection_ferramentas( string $post_url ): void {
		echo '<h2>' . esc_html__( 'Ferramentas de mantemento', 'anpa-socios' ) . '</h2>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_run_season">';
		echo '<input type="hidden" name="tab" value="xeral">';
		echo '<input type="hidden" name="section" value="mantemento">';
		wp_nonce_field( 'anpa_socios_run_season' );
		submit_button( __( 'Executar comprobación de temporada agora', 'anpa-socios' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Subsection: actualizacións.
	 *
	 * @param  string $post_url Admin-post URL.
	 * @return void
	 */
	private static function render_subsection_actualizacions( string $post_url ): void {
		$version   = defined( 'ANPA_SOCIOS_VERSION' ) ? ANPA_SOCIOS_VERSION : '?';
		$repo      = ANPA_Socios_Updater::REPO_URL;

		echo '<h2>Actualizacións</h2>';
		echo '<table class="widefat striped" style="max-width:680px"><tbody>';
		printf( '<tr><td style="width:260px"><strong>Versión instalada</strong></td><td>%s</td></tr>', esc_html( (string) $version ) );
		printf( '<tr><td><strong>Orixe das actualizacións</strong></td><td><a href="%s" target="_blank" rel="noreferrer">%s</a></td></tr>', esc_url( $repo . '/releases' ), esc_html( 'anpaventin/wp-anpa-socios' ) );

		$pending = get_site_transient( 'update_plugins' );
		$slug    = 'anpa-socios/anpa-socios.php';
		$new_ver = '';
		if ( is_object( $pending ) && ! empty( $pending->response[ $slug ]->new_version ) ) {
			$new_ver = (string) $pending->response[ $slug ]->new_version;
		}
		printf(
			'<tr><td><strong>Estado</strong></td><td>%s</td></tr>',
			'' !== $new_ver
				? '⬆️ hai unha actualización dispoñible: <strong>' . esc_html( $new_ver ) . '</strong> (ver <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '\">Plugins</a>)'
				: '✅ ao día'
		);
		echo '</tbody></table>';

		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_check_updates">';
		wp_nonce_field( 'anpa_socios_check_updates' );
		submit_button( __( 'Comprobar actualizacións agora', 'anpa-socios' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">Comproba a última <em>Release</em> publicada no repositorio e, se hai unha versión máis nova, aparecerá en <strong>Plugins</strong> para actualizar cun clic.</p>';
	}

	/**
	 * A small show/hide eye toggle button bound to a password input id.
	 *
	 * @param  string $target Input element id.
	 * @return string
	 */
	private static function eye_button( string $target ): string {
		return sprintf(
			'<button type="button" class="anpa-eye" data-target="%s" aria-label="Mostrar/ocultar" title="Mostrar/ocultar">👁</button>',
			esc_attr( $target )
		);
	}

	/**
	 * Scoped admin CSS to give the settings sections clear visual separation.
	 *
	 * @return string
	 */
	private static function admin_styles(): string {
		return '<style>
			.anpa-cfg h1 { margin-bottom: .3em; }
			.anpa-cfg .nav-tab-wrapper { margin: 1em 0 0; border-bottom: 1px solid #c3c4c7; }
			.anpa-cfg .nav-tab { font-size: .95em; padding: .6em 1.1em; }
			.anpa-cfg .nav-tab:focus-visible { outline: 2px solid #2271b1; outline-offset: -2px; }
			.anpa-cfg .anpa-tab-panel { margin-top: .4em; }
			.anpa-cfg h2:not(.nav-tab-wrapper) { margin: 1.6em 0 .6em; padding: .5em .9em; background: #fbfbfc;
				border-left: 4px solid #e67e22; border-radius: 3px; font-size: 1.1em;
				box-shadow: 0 1px 2px rgba(0,0,0,.04); color: #1d2327; }
			.anpa-cfg h3 { margin: 1.2em 0 .3em; color: #2c3338; font-size: 1em; }
			/* Section sub-nav (pill/underline style, visually secondary to top tabs) */
			.anpa-cfg .anpa-section-nav { display: flex; flex-wrap: wrap; gap: .3em; margin: .9em 0 1.2em;
				padding: .4em 0; border-bottom: 1px solid #e2e4e7; }
			.anpa-cfg .anpa-section-link { display: inline-block; padding: .35em .85em; border-radius: 3px;
				text-decoration: none; font-size: .875em; color: #2c3338; transition: background .15s; }
			.anpa-cfg .anpa-section-link:hover { background: #f0f0f1; color: #1d2327; }
			.anpa-cfg .anpa-section-link.current { background: #2271b1; color: #fff; font-weight: 500; }
			.anpa-cfg .anpa-section-link:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
			/* Cards and form tables */
			.anpa-cfg .form-table, .anpa-cfg .widefat { background: #fff; border: 1px solid #e2e4e7;
				border-radius: 6px; padding: .6em 1.4em; margin: .4em 0 1.2em; max-width: 820px; }
			.anpa-cfg .form-table th { width: 260px; padding: 1em 1.2em 1em .4em; vertical-align: top; }
			.anpa-cfg .form-table td { padding: .9em 1em; }
			.anpa-cfg .widefat td, .anpa-cfg .widefat th { padding: .7em 1em; }
			.anpa-cfg .form-table input.regular-text,
			.anpa-cfg .form-table input[type="email"],
			.anpa-cfg .form-table input[type="text"],
			.anpa-cfg .form-table input[type="date"],
			.anpa-cfg .form-table select,
			.anpa-cfg .form-table textarea { padding: .5em .7em; }
			.anpa-cfg .form-table select { padding-right: 2.2em; min-width: 5em; min-height: 2.4em; }
			.anpa-cfg .description { color: #646970; margin-top: .5em; }
			.anpa-cfg hr { margin: 2.6em 0 0; border: 0; border-top: 1px dashed #c3c4c7; }
			.anpa-cfg form { margin: 0 0 .6em; }
			/* All interactive elements: visible keyboard focus */
			.anpa-cfg a:focus-visible,
			.anpa-cfg button:focus-visible,
			.anpa-cfg input:focus-visible,
			.anpa-cfg select:focus-visible,
			.anpa-cfg textarea:focus-visible { outline: 2px solid #2271b1; outline-offset: 1px; }
			/* Reveal "eye": transparent icon overlaid inside the field */
			.anpa-cfg .anpa-eye { background: transparent; border: 0; box-shadow: none; outline: 0;
				cursor: pointer; padding: 0; margin: 0 0 0 -2.2em; position: relative; font-size: 1.15em;
				line-height: 1; opacity: .6; vertical-align: middle; }
			.anpa-cfg .anpa-eye:hover, .anpa-cfg .anpa-eye:focus-visible { opacity: 1; }
			.anpa-cfg .anpa-eye + .description { margin-left: 0; }
			.anpa-cfg input.regular-text { padding-right: 2.6em; }
			.anpa-cfg h2[style*="b32d2e"] { border-left-color: #b32d2e; background: #fcf0f1; }
			/* Responsive: sub-nav wraps naturally via flex-wrap */
			@media (max-width: 782px) {
				.anpa-cfg .form-table th { width: auto; display: block; padding-bottom: .2em; }
				.anpa-cfg .form-table td { display: block; padding-left: .4em; }
				.anpa-cfg .anpa-section-nav { gap: .2em; }
				.anpa-cfg .anpa-section-link { font-size: .8125em; padding: .3em .6em; }
			}
		</style>';
	}

	/**
	 * Inline JS for the eye toggle (admin-only page).
	 *
	 * @return void
	 */
	private static function render_eye_script(): void {
		echo "<script>(function(){document.querySelectorAll('.anpa-eye').forEach(function(b){b.addEventListener('click',function(){var i=document.getElementById(b.getAttribute('data-target'));if(i){i.type=(i.type==='password')?'text':'password';}});});})();</script>";
	}

	/**
	 * Resolves the configured or auto-detected socios landing page URL.
	 *
	 * @return string
	 */
	public static function landing_page_url(): string {
		$id = (int) get_option( self::LANDING_OPTION, 0 );
		if ( $id > 0 ) {
			$url = get_permalink( $id );
			if ( $url ) {
				return (string) $url;
			}
		}

		return (string) ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area' );
	}

	// ─────────────────────────────────────────────────────────────
	// Handlers (config editor uses admin-post + PRG redirect)
	// ─────────────────────────────────────────────────────────────

	/**
	 * admin-post: save editable settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings(): void {
		self::guard( 'anpa_socios_save_settings' );

		if ( array_key_exists( 'master_email', $_POST ) ) {
			$email = sanitize_email( (string) wp_unslash( $_POST['master_email'] ) );
			if ( ! is_email( $email ) ) {
				self::redirect_msg( 'settings_invalid_email' );
			}
			update_option( 'anpa_socios_master_email', strtolower( $email ) );
		}

		if ( array_key_exists( 'landing_page_id', $_POST ) ) {
			$landing = (int) $_POST['landing_page_id'];
			update_option( self::LANDING_OPTION, $landing > 0 ? $landing : 0 );
		}

		// fase12 identity/config (PR-12h). Each subsection form saves only
		// submitted fields so partial settings screens never clear siblings.
		if ( array_key_exists( 'association_name', $_POST ) ) {
			$assoc = sanitize_text_field( (string) wp_unslash( $_POST['association_name'] ) );
			if ( '' !== $assoc ) {
				update_option( ANPA_Socios_Config::OPTION_ASSOCIATION, $assoc );
			}
		}
		if ( array_key_exists( 'email_signature', $_POST ) ) {
			update_option( ANPA_Socios_Config::OPTION_SIGNATURE, sanitize_textarea_field( (string) wp_unslash( $_POST['email_signature'] ) ) );
		}
		if ( array_key_exists( 'require_approval', $_POST ) || array_key_exists( 'association_name', $_POST ) ) {
			update_option( ANPA_Socios_Config::OPTION_APPROVAL, ! empty( $_POST['require_approval'] ) ? '1' : '0' );
		}

		self::redirect_msg( 'settings_saved' );
	}

	/**
	 * admin-post: save the "Cursos" tab — course season (curso escolar, estado,
	 * data_inicio, data_peche) and the max classroom letter. Isolated so a
	 * partial form never clears other options.
	 *
	 * @return void
	 */
	public static function handle_save_cursos(): void {
		self::guard( 'anpa_socios_save_cursos' );

		$nuevo  = sanitize_text_field( (string) wp_unslash( $_POST['curso_nuevo'] ?? '' ) );
		$curso  = sanitize_text_field( (string) wp_unslash( $_POST['curso_escolar'] ?? '' ) );
		$inicio = sanitize_text_field( (string) wp_unslash( $_POST['data_inicio'] ?? '' ) );
		$peche  = sanitize_text_field( (string) wp_unslash( $_POST['data_peche'] ?? '' ) );

		// Create-new path (from the "Crear novo curso" form) takes precedence:
		// create it as pendente with default season dates and select it.
		if ( ANPA_Socios_Curso_Escolar::is_valid( $nuevo ) ) {
			self::upsert_course(
				$nuevo,
				ANPA_Socios_Season::default_data_inicio( $nuevo ),
				ANPA_Socios_Season::default_data_peche( $nuevo ),
				ANPA_Socios_Season::ESTADO_PENDENTE
			);
			self::redirect_cursos( $nuevo );
		}

		if ( ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			global $wpdb;
			$cursos_t = ANPA_Socios_DB::tabela_cursos();
			$stored_estado = $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$cursos_t} WHERE curso_escolar = %s", $curso ) );
			$valid = array( ANPA_Socios_Season::ESTADO_PENDENTE, ANPA_Socios_Season::ESTADO_ACTIVO, ANPA_Socios_Season::ESTADO_PECHADO );
			$estado = in_array( $stored_estado, $valid, true ) ? (string) $stored_estado : ANPA_Socios_Season::ESTADO_PENDENTE;
			$inicio = self::valid_date( $inicio, ANPA_Socios_Season::default_data_inicio( $curso ) );
			$peche  = self::valid_date( $peche, ANPA_Socios_Season::default_data_peche( $curso ) );
			self::upsert_course( $curso, $inicio, $peche, $estado );
		}

		// Maximum classroom letter ("aula"): only persist a single letter A..H;
		// anything else leaves the option unchanged so the getter falls back to
		// the neutral default.
		$aula_max = strtoupper( substr( sanitize_text_field( (string) wp_unslash( $_POST['aula_max'] ?? '' ) ), 0, 1 ) );
		if ( 1 === strlen( $aula_max ) && $aula_max >= 'A' && $aula_max <= 'H' ) {
			update_option( ANPA_Socios_Config::OPTION_AULA_MAX, $aula_max );
		}

		self::redirect_cursos( $curso );
	}

	/**
	 * PRG redirect back to the Cursos tab keeping the selected course.
	 *
	 * @param  string $curso Selected course to keep in the URL.
	 * @return void
	 */
	private static function redirect_cursos( string $curso ): void {
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : 'curso-escolar';
		if ( ! ANPA_Socios_Admin_Nav::is_settings_section( 'cursos', $section ) ) {
			$section = 'curso-escolar';
		}
		$args = array( 'page' => self::SETTINGS_SLUG, 'tab' => 'cursos', 'section' => $section, 'anpa_msg' => 'settings_saved' );
		if ( ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			$args['curso'] = $curso;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * admin-post: run the season check now.
	 *
	 * @return void
	 */
	public static function handle_run_season(): void {
		self::guard( 'anpa_socios_run_season' );
		ANPA_Socios_Season_Service::run_check();
		self::redirect_msg( 'season_ok' );
	}

	/**
	 * admin-post: force a plugin update check now (clears the cache).
	 *
	 * @return void
	 */
	public static function handle_check_updates(): void {
		self::guard( 'anpa_socios_check_updates' );
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		wp_safe_redirect( add_query_arg(
			array( 'anpa_msg' => 'updates_checked', 'tab' => 'xeral', 'section' => 'estado' ),
			admin_url( 'admin.php?page=' . self::SETTINGS_SLUG )
		) );
		exit;
	}

	/**
	 * admin-post: download an encrypted backup (.anpabak).
	 *
	 * @return void
	 */
	public static function handle_backup(): void {
		self::guard( 'anpa_socios_backup' );
		$pass     = (string) wp_unslash( $_POST['banking_passphrase'] ?? '' );

		if ( '' === $pass ) {
			self::redirect_msg( 'bak_err' );
		}
		$blob = ANPA_Socios_Backup::build( $pass );
		if ( is_wp_error( $blob ) ) {
			self::redirect_msg( 'bak_err' );
		}

		nocache_headers();
		$fname = 'anpa-copia-' . gmdate( 'Y-m-d-His' ) . '.anpabak';
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		header( 'Content-Length: ' . strlen( (string) $blob ) );
		echo $blob; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- encrypted binary download.
		exit;
	}

	/**
	 * admin-post: restore from an uploaded .anpabak.
	 *
	 * @return void
	 */
	public static function handle_restore(): void {
		self::guard( 'anpa_socios_restore' );
		$pass = (string) wp_unslash( $_POST['banking_passphrase'] ?? '' );

		if ( empty( $_FILES['backup_file']['tmp_name'] ) || ! is_uploaded_file( (string) $_FILES['backup_file']['tmp_name'] ) ) {
			self::redirect_msg( 'restore_nofile' );
		}
		$blob = (string) file_get_contents( (string) $_FILES['backup_file']['tmp_name'] );
		$res  = ANPA_Socios_Backup::restore( $blob, $pass );
		if ( is_wp_error( $res ) ) {
			self::redirect_msg( 'restore_err' );
		}
		self::redirect_msg( 'restored' );
	}

	/**
	 * admin-post: wipe the database (irreversible) → returns to the wizard.
	 *
	 * @return void
	 */
	public static function handle_wipe(): void {
		self::guard( 'anpa_socios_wipe' );

		if ( empty( $_POST['confirm_wipe'] ) ) {
			self::redirect_msg( 'wipe_noconfirm' );
		}
		ANPA_Socios_Backup::wipe();
		self::redirect_msg( 'wiped' );
	}

	// ─────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────

	/**
	 * Capability + nonce guard for admin-post handlers.
	 *
	 * @param  string $nonce_action Nonce action name.
	 * @return void
	 */
	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Validates a Y-m-d date, falling back to a default.
	 *
	 * @param  string $value    Candidate.
	 * @param  string $fallback Default when invalid.
	 * @return string
	 */
	private static function valid_date( string $value, string $fallback ): string {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return ( false !== $dt && $dt->format( 'Y-m-d' ) === $value ) ? $value : $fallback;
	}

	/**
	 * Creates or overwrites the socios page with the area shortcode, returns id.
	 *
	 * @param  string $choice Page id, or 'new'.
	 * @return int
	 */
	private static function ensure_socios_page( string $choice ): int {
		$page_id = 0;
		if ( 'new' === $choice || '' === $choice || '0' === $choice ) {
			$existing = get_page_by_path( 'socios' );
			if ( $existing instanceof WP_Post ) {
				$page_id = (int) $existing->ID;
			}
		} else {
			$page_id = (int) $choice;
		}

		if ( $page_id > 0 && get_post( $page_id ) instanceof WP_Post ) {
			wp_update_post( array(
				'ID'           => $page_id,
				'post_content' => self::AREA_SHORTCODE,
				'post_status'  => 'publish',
			) );
		} else {
			$new_id = wp_insert_post( array(
				'post_title'   => 'Socios',
				'post_name'    => 'socios',
				'post_content' => self::AREA_SHORTCODE,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			) );
			$page_id = is_wp_error( $new_id ) ? 0 : (int) $new_id;
		}

		if ( $page_id > 0 ) {
			update_option( self::LANDING_OPTION, $page_id );
		}

		return $page_id;
	}

	/**
	 * The Galician content for the auto-created extraescolares page.
	 *
	 * @return string
	 */
	private static function extraescolares_page_content(): string {
		return "<!-- wp:heading --><h2>Actividades ofertadas</h2><!-- /wp:heading -->\n"
			. "<!-- wp:paragraph --><p>As actividades amósanse automaticamente a partir das actividades dadas de alta e activas para o curso actual. O icono escóllese no formulario de alta ou edición da actividade.</p><!-- /wp:paragraph -->\n"
			. "[anpa_extraescolares_ofertadas]\n\n"
			. "<!-- wp:heading --><h2>Horario semanal</h2><!-- /wp:heading -->\n"
			. "<!-- wp:paragraph --><p>Comedor: as franxas de comedor mantéñense como información estática separada. As actividades extraescolares activas amósanse automaticamente na grella seguinte.</p><!-- /wp:paragraph -->\n"
			. "[anpa_extraescolares_horario]";
	}

	/**
	 * Ensures a published page hosting the given shortcode exists. If any page
	 * already hosts it, does nothing. Otherwise creates/updates a page at $slug.
	 *
	 * @param  string $shortcode Shortcode tag (without brackets).
	 * @param  string $slug      Desired slug when creating.
	 * @param  string $title     Page title when creating.
	 * @param  string $content   Full page content (must contain the shortcode).
	 * @return int     Page id (0 when an existing page already hosts it).
	 */
	private static function ensure_page_by_shortcode( string $shortcode, string $slug, string $title, string $content, bool $overwrite_slug = true ): int {
		if ( '' !== ANPA_Socios_Hub_Page::find_page_url( $shortcode ) ) {
			return 0; // already hosted somewhere; leave it.
		}
		$existing = get_page_by_path( $slug );
		if ( $existing instanceof WP_Post ) {
			// A page already exists at this slug. Never overwrite when the caller
			// opted out (e.g. extraescolares) — just leave it and let the Docs
			// guide the user to paste the shortcodes.
			if ( ! $overwrite_slug ) {
				return 0;
			}
			wp_update_post( array(
				'ID'           => $existing->ID,
				'post_content' => $content,
				'post_status'  => 'publish',
			) );

			return (int) $existing->ID;
		}
		$id = wp_insert_post( array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Upserts a course row with season fields.
	 *
	 * @param  string      $curso  Curso escolar.
	 * @param  string      $inicio data_inicio (Y-m-d).
	 * @param  string      $peche  data_peche (Y-m-d).
	 * @param  string|null $estado Optional explicit estado (else date-derived).
	 * @return void
	 */
	private static function upsert_course( string $curso, string $inicio, string $peche, ?string $estado = null ): void {
		global $wpdb;
		$cursos = ANPA_Socios_DB::tabela_cursos();
		if ( null === $estado ) {
			$estado = ANPA_Socios_Season::estado_for( date( 'Y-m-d' ), $inicio, $peche );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent course upsert.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$cursos} (curso_escolar, matriculas_abertas, estado, data_inicio, data_peche, creado_en, actualizado_en)
			 VALUES (%s, 0, %s, %s, %s, NOW(), NOW())
			 ON DUPLICATE KEY UPDATE estado = VALUES(estado), data_inicio = VALUES(data_inicio), data_peche = VALUES(data_peche), actualizado_en = NOW()",
			$curso,
			$estado,
			$inicio,
			$peche
		) );
	}


	/**
	 * Redirects back to the settings page with a message key.
	 *
	 * @param  string $key Message key.
	 * @return void
	 */
	private static function redirect_msg( string $key ): void {
		$args = array( 'anpa_msg' => $key );
		$tab  = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		if ( ANPA_Socios_Admin_Nav::is_settings_tab( $tab ) ) {
			$args['tab'] = $tab;
			$section     = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
			if ( ANPA_Socios_Admin_Nav::is_settings_section( $tab, $section ) ) {
				$args['section'] = $section;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) );
		exit;
	}

	/**
	 * Renders admin notices from the anpa_msg query arg.
	 *
	 * @return void
	 */
	private static function render_flash(): void {
		$key = isset( $_GET['anpa_msg'] ) ? sanitize_key( wp_unslash( $_GET['anpa_msg'] ) ) : '';
		if ( '' === $key ) {
			return;
		}
		$map = array(
			'settings_saved' => array( 'success', __( 'Configuración gardada.', 'anpa-socios' ) ),
			'settings_invalid_email' => array( 'error', __( 'O email do equipo administrador non é válido. Non se gardou ningún cambio.', 'anpa-socios' ) ),
			'pw_ok'          => array( 'success', __( 'Contrasinal de admin actualizado.', 'anpa-socios' ) ),
			'pw_bad'         => array( 'error', __( 'O contrasinal non cumpre os requisitos (mín. 8 caracteres, unha maiúscula e un símbolo).', 'anpa-socios' ) ),
			'season_ok'      => array( 'success', __( 'Comprobación de temporada executada.', 'anpa-socios' ) ),
			'updates_checked' => array( 'success', __( 'Comprobación de actualizacións executada. Se hai unha versión nova, aparecerá en Plugins.', 'anpa-socios' ) ),
			'bak_bad_pw'     => array( 'error', __( 'Contrasinal de admin incorrecto.', 'anpa-socios' ) ),
			'bak_err'        => array( 'error', __( 'Non se puido xerar a copia (revisa a frase da clave bancaria).', 'anpa-socios' ) ),
			'restored'       => array( 'success', __( 'Copia recuperada correctamente.', 'anpa-socios' ) ),
			'restore_nofile' => array( 'error', __( 'Non se recibiu ningún ficheiro de copia.', 'anpa-socios' ) ),
			'restore_err'    => array( 'error', __( 'Non se puido recuperar a copia (ficheiro ou contrasinal incorrectos).', 'anpa-socios' ) ),
			'wiped'          => array( 'success', __( 'Base de datos borrada. Configura de novo o plugin.', 'anpa-socios' ) ),
			'wipe_noconfirm' => array( 'error', __( 'Debes confirmar a casa de verificación para borrar a base de datos.', 'anpa-socios' ) ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $key ][0] ), esc_html( $map[ $key ][1] ) );
	}

	/**
	 * Renders the offline docs mini-wiki with an index driven by Admin_Nav.
	 *
	 * @return void
	 */
	public static function render_docs_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		$sections = ANPA_Socios_Admin_Nav::docs_sections();

		echo '<div class="wrap anpa-docs">';
		echo self::docs_styles();
		echo '<h1>' . esc_html__( 'ANPA Socios — Documentación', 'anpa-socios' ) . '</h1>';
		echo '<p>' . esc_html__( 'Guía rápida para a xunta: configuración, operación diaria, páxinas públicas, exportacións e seguridade.', 'anpa-socios' ) . '</p>';

		// Section index navigation.
		echo '<nav class="card anpa-docs-index" aria-label="' . esc_attr__( 'Índice de documentación', 'anpa-socios' ) . '">';
		echo '<h2>' . esc_html__( 'Índice', 'anpa-socios' ) . '</h2><ol>';
		foreach ( $sections as $slug => $label ) {
			printf( '<li><a href="#%s">%s</a></li>', esc_attr( $slug ), esc_html( $label ) );
		}
		echo '</ol></nav>';

		echo '<div class="anpa-docs-content">';

		echo '<section id="posta-en-marcha" class="card"><h2>' . esc_html( $sections['posta-en-marcha'] ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'En Axustes → Xeral revisa o email do equipo administrador, a páxina da área de socios e as páxinas públicas creadas automaticamente.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'Garda a frase da clave bancaria nun lugar seguro e accesible só para a xunta.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'Lanza a instalación só cando a configuración sexa correcta: créanse a clave bancaria, a páxina de socios e a configuración inicial do curso.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'Accede á área de socios para comprobar que chega o código por correo electrónico.', 'anpa-socios' ) . '</li>';
		echo '</ol></section>';

		echo '<section id="ciclo-curso" class="card"><h2>' . esc_html( $sections['ciclo-curso'] ) . '</h2><ul>';
		echo '<li>' . wp_kses_post( __( 'Cada curso vai do 1 de xullo ao 30 de xuño e usa formato <code>AAAA/AAAA+1</code>.', 'anpa-socios' ) ) . '</li>';
		echo '<li>' . esc_html__( 'O 20 de xuño péchase o curso activo e créase o seguinte en estado pendente.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'O curso pendente actívase automaticamente o 1 de setembro.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'En pretempada só o equipo administrador pode iniciar sesión; as familias quedan protexidas ata a apertura.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'En Axustes → Cursos → Matrículas podes abrir ou pechar novas matrículas sen tocar datas nin estado do curso.', 'anpa-socios' ) . '</li>';
		echo '</ul></section>';

		echo '<section id="paxinas-shortcodes" class="card"><h2>' . esc_html( $sections['paxinas-shortcodes'] ) . '</h2>';
		echo '<p>' . esc_html__( 'A área pública principal amósase coa páxina configurada en Axustes e o shortcode:', 'anpa-socios' ) . ' <code>[anpa_socios_area]</code></p>';
		echo '<h3>' . esc_html__( 'Shortcodes dispoñibles', 'anpa-socios' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><td><code>[anpa_socios_area]</code></td><td>' . esc_html__( 'Área principal de socios (login, perfil, fillos, extraescolares, banking).', 'anpa-socios' ) . '</td></tr>';
		echo '<tr><td><code>[anpa_socios_area_persoal]</code></td><td>' . esc_html__( 'Área persoal do socio (perfil e fillos, sen menú completo).', 'anpa-socios' ) . '</td></tr>';
		echo '<tr><td><code>[anpa_socios_asociarse]</code></td><td>' . esc_html__( 'Formulario de alta de socios.', 'anpa-socios' ) . '</td></tr>';
		echo '<tr><td><code>[anpa_socios_area_link]</code></td><td>' . esc_html__( 'Botón/enlace cara á área persoal.', 'anpa-socios' ) . '</td></tr>';
		echo '<tr><td><code>[anpa_extraescolares_ofertadas]</code></td><td>' . esc_html__( 'Tarxetas coas actividades activas do curso actual.', 'anpa-socios' ) . '</td></tr>';
		echo '<tr><td><code>[anpa_extraescolares_horario]</code></td><td>' . esc_html__( 'Grella semanal de horarios e grupos activos.', 'anpa-socios' ) . '</td></tr>';
		echo '</tbody></table></section>';

		echo '<section id="extraescolares" class="card"><h2>' . esc_html( $sections['extraescolares'] ) . '</h2>';
		echo '<p>' . esc_html__( 'A instalación crea automaticamente unha páxina de extraescolares. As actividades visibles dependen do curso actual, do estado activo e dos grupos configurados.', 'anpa-socios' ) . '</p>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'O icono da tarxeta pública escóllese no formulario de alta ou edición da actividade.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'O comedor ou avisos fixos deben manterse como contido editorial separado, non como actividade automática.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'Recoméndase engadir FAQ con prazos, prezos, autorizacións, baixas e contacto.', 'anpa-socios' ) . '</li>';
		echo '</ul></section>';

		echo '<section id="exportacions-copias" class="card"><h2>' . esc_html( $sections['exportacions-copias'] ) . '</h2><ul>';
		echo '<li>' . esc_html__( 'Cada táboa de Xestión ANPA ten a súa propia exportación CSV filtrada pola busca visible.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'A exportación sensible “Descargar Socios IBAN” vive en Socios/as e require o contrasinal de descifrado.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'As copias de seguridade deben facerse con UpdraftPlus antes de cambios importantes, importacións ou actualizacións.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'A sección Importar listados é só unha guía nesta fase: non escribe CSV nin modifica a base de datos.', 'anpa-socios' ) . '</li>';
		echo '</ul></section>';

		echo '<section id="privacidade-seguridade" class="card"><h2>' . esc_html( $sections['privacidade-seguridade'] ) . '</h2><ul>';
		echo '<li>' . esc_html__( 'Os datos bancarios están cifrados e só deben exportarse cando sexa imprescindible para a domiciliación.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'Non compartas o contrasinal de descifrado por correo nin o gardes en documentos públicos.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'A Xestión ANPA require sesión de administración válida; se caduca, hai que volver autenticarse.', 'anpa-socios' ) . '</li>';
		echo '<li>' . esc_html__( 'Antes de importar ou arquivar datos persoais, debe existir unha subfase con validación, vista previa, confirmación e auditoría.', 'anpa-socios' ) . '</li>';
		echo '</ul></section>';

		echo '</div></div>';
	}

	/**
	 * Scoped styles for the docs page.
	 *
	 * @return string
	 */
	private static function docs_styles(): string {
		return '<style>
			.anpa-docs { max-width: 960px; }
			.anpa-docs h1 { margin-bottom: .4em; }
			.anpa-docs .anpa-docs-index { padding: 1em 1.6em; }
			.anpa-docs .anpa-docs-index ol { margin: .6em 0 0 1.4em; }
			.anpa-docs .anpa-docs-index a { text-decoration: none; }
			.anpa-docs .anpa-docs-index a:hover { text-decoration: underline; }
			.anpa-docs .anpa-docs-index a:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
			.anpa-docs .anpa-docs-content .card { padding: 1em 1.6em; margin-bottom: 1.2em; }
			.anpa-docs .anpa-docs-content h2 { margin: 0 0 .5em; font-size: 1.15em; color: #1d2327;
				border-bottom: 2px solid #e67e22; padding-bottom: .3em; }
			.anpa-docs .anpa-docs-content h3 { margin: 1em 0 .4em; font-size: 1em; color: #2c3338; }
			.anpa-docs .anpa-docs-content ul, .anpa-docs .anpa-docs-content ol { margin-left: 1.4em; }
			.anpa-docs .anpa-docs-content ul { list-style: disc; }
			.anpa-docs .anpa-docs-content ol { list-style: decimal; }
			.anpa-docs .anpa-docs-content li { margin-bottom: .35em; line-height: 1.5; }
			.anpa-docs .anpa-docs-content code { background: #f6f7f7; padding: .15em .4em; border-radius: 3px; font-size: .9em; }
			.anpa-docs .widefat { margin: .6em 0; }
			.anpa-docs .widefat td { padding: .5em .8em; vertical-align: top; }
			.anpa-docs .widefat td:first-child { white-space: nowrap; width: 280px; }
			.anpa-docs a:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
		</style>';
	}
}
