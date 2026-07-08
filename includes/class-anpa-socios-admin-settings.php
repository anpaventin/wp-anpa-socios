<?php
/**
 * Plugin onboarding surface (fase12): Plugins-page action links + an admin
 * settings screen that (a) runs a first-run setup wizard on a clean install
 * (master email + banking passphrase + optional admin password → creates the
 * master socio, the sealed-box banking key, the socios page, and configures the
 * season), (b) lets admins edit the config afterwards, (c) exposes a short
 * admin-password change, and (d) an offline docs mini-wiki.
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
	const AREA_SHORTCODE = '[anpa_socios_area_unified]';

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
		add_action( 'admin_post_anpa_socios_set_admin_password', array( __CLASS__, 'handle_set_admin_password' ) );
		add_action( 'admin_post_anpa_socios_run_season', array( __CLASS__, 'handle_run_season' ) );
		add_action( 'admin_post_anpa_socios_check_updates', array( __CLASS__, 'handle_check_updates' ) );
		add_action( 'admin_post_anpa_socios_backup', array( __CLASS__, 'handle_backup' ) );
		add_action( 'admin_post_anpa_socios_wipe', array( __CLASS__, 'handle_wipe' ) );
		add_action( 'admin_post_anpa_socios_restore', array( __CLASS__, 'handle_restore' ) );
	}

	/**
	 * Adds "Axustes" and "Docs" to the plugin row.
	 *
	 * @param  string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$axustes = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ), esc_html__( 'Axustes', 'anpa-socios' ) );
		$docs    = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG ) ), esc_html__( 'Docs', 'anpa-socios' ) );
		array_unshift( $links, $axustes, $docs );

		return $links;
	}

	/**
	 * Registers the ANPA admin menu + Axustes/Docs submenus.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page( 'ANPA Socios', 'ANPA Socios', self::CAP, self::SETTINGS_SLUG, array( __CLASS__, 'render_settings_page' ), 'dashicons-groups', 58 );
		add_submenu_page( self::SETTINGS_SLUG, 'Axustes ANPA Socios', 'Axustes', self::CAP, self::SETTINGS_SLUG, array( __CLASS__, 'render_settings_page' ) );
		add_submenu_page( self::SETTINGS_SLUG, 'Documentación ANPA Socios', 'Docs', self::CAP, self::DOCS_SLUG, array( __CLASS__, 'render_docs_page' ) );
	}

	/**
	 * Whether the plugin has completed first-run setup.
	 *
	 * @return bool
	 */
	private static function is_setup_done(): bool {
		return ANPA_Socios_Master_Auth::is_initialized() && ANPA_Socios_Banking_Key::is_configured();
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

		echo '<p style="margin-top:16px"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG ) ) . '">Ver documentación</a></p>';
		echo '</div>';
	}

	/**
	 * First-run setup wizard (self-POST to the settings page).
	 *
	 * @return void
	 */
	private static function render_setup_wizard(): void {
		$self_url    = esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
		$master      = ANPA_Socios_Config::master_email();
		$suggested   = ANPA_Socios_Master_Auth::generate_banking_passphrase();
		$curso       = ANPA_Socios_Curso_Escolar::current();
		$data_inicio = ANPA_Socios_Season::default_data_inicio( $curso );
		$data_peche  = ANPA_Socios_Season::default_data_peche( $curso );
		$detected    = get_page_by_path( 'socios' );
		$sel_page    = $detected instanceof WP_Post ? $detected->ID : 0;

		echo '<div class="notice notice-info"><p><strong>Instalación limpa.</strong> Configura o equipo administrador, a clave bancaria e a páxina de socios para poñer en marcha o sistema. Este paso só se fai unha vez.</p></div>';

		echo '<form method="post" action="' . $self_url . '">';
		echo '<input type="hidden" name="anpa_action" value="setup">';
		wp_nonce_field( 'anpa_socios_setup' );
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="anpa-master-email">Email do equipo administrador (master)</label></th><td><input name="master_email" id="anpa-master-email" type="email" class="regular-text" value="%s" required></td></tr>',
			esc_attr( $master )
		);

		echo '<tr><th scope="row"><label for="anpa-passphrase">Frase da clave bancaria (mín. 5 palabras)</label></th><td>';
		printf(
			'<input name="passphrase" id="anpa-passphrase" type="text" class="regular-text code" value="%s" size="48" required minlength="%d">',
			esc_attr( $suggested ),
			(int) self::MIN_PASSPHRASE
		);
		printf( ' <a class="button" href="%s">Xerar outra</a>', $self_url );
		echo '<p class="description"><strong>Garda esta frase nun lugar seguro.</strong> Protexe os datos bancarios cifrados. Se a perdes, os datos serán irrecuperables e só se poderá cambiar reinstalando a base de datos.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="anpa-admin-pw">Contrasinal de administración</label></th><td>';
		echo '<input name="admin_password" id="anpa-admin-pw" type="password" class="regular-text" autocomplete="new-password" required>';
		echo self::eye_button( 'anpa-admin-pw' );
		echo '<p class="description">Obrigatorio. Os administradores deberán introducilo ao entrar en "Xestión ANPA". Mín. 8 caracteres, unha maiúscula e un símbolo.</p></td></tr>';

		echo '<tr><th scope="row"><label for="anpa-socios-page">Páxina de socios</label></th><td>';
		wp_dropdown_pages( array(
			'name'              => 'socios_page',
			'id'                => 'anpa-socios-page',
			'selected'          => $sel_page,
			'show_option_none'  => "— Crear nova páxina 'Socios' —",
			'option_none_value' => 'new',
		) );
		echo '<p class="description">A instalación <strong>sobrescribirá</strong> o contido desta páxina coa área de socios (' . esc_html( self::AREA_SHORTCODE ) . '). Se escolles "crear nova", crearase en <code>/socios/</code>.</p></td></tr>';

		printf(
			'<tr><th scope="row"><label for="anpa-curso">Curso escolar actual</label></th><td><input name="curso_escolar" id="anpa-curso" type="text" value="%s" pattern="\d{4}/\d{4}" class="regular-text"><p class="description">Formato AAAA/AAAA+1. Proposto para hoxe.</p></td></tr>',
			esc_attr( $curso )
		);
		printf( '<tr><th scope="row"><label for="anpa-inicio">Comeza (data_inicio)</label></th><td><input name="data_inicio" id="anpa-inicio" type="date" value="%s"></td></tr>', esc_attr( $data_inicio ) );
		printf( '<tr><th scope="row"><label for="anpa-peche">Pecha (data_peche)</label></th><td><input name="data_peche" id="anpa-peche" type="date" value="%s"></td></tr>', esc_attr( $data_peche ) );

		echo '</tbody></table>';
		submit_button( 'Lanzar instalación e crear o equipo administrador' );
		echo '</form>';
	}

	/**
	 * Processes the setup self-POST and renders the result inline (styled).
	 *
	 * @return void
	 */
	private static function process_setup_inline(): void {
		check_admin_referer( 'anpa_socios_setup' );

		$email       = sanitize_email( (string) wp_unslash( $_POST['master_email'] ?? '' ) );
		$passphrase  = (string) wp_unslash( $_POST['passphrase'] ?? '' );
		$admin_pw    = (string) wp_unslash( $_POST['admin_password'] ?? '' );
		$socios_page = sanitize_text_field( (string) wp_unslash( $_POST['socios_page'] ?? 'new' ) );
		$curso       = sanitize_text_field( (string) wp_unslash( $_POST['curso_escolar'] ?? '' ) );
		$inicio      = sanitize_text_field( (string) wp_unslash( $_POST['data_inicio'] ?? '' ) );
		$peche       = sanitize_text_field( (string) wp_unslash( $_POST['data_peche'] ?? '' ) );

		// Validation — on error, show notice and re-render the wizard.
		$error = '';
		if ( ! is_email( $email ) ) {
			$error = 'Email do master non válido.';
		} elseif ( strlen( $passphrase ) < self::MIN_PASSPHRASE ) {
			$error = 'A frase da clave debe ter polo menos ' . self::MIN_PASSPHRASE . ' caracteres.';
		} elseif ( '' === $admin_pw ) {
			$error = 'O contrasinal de administración é obrigatorio.';
		} elseif ( true !== ANPA_Socios_Master_Auth::validate_admin_password( $admin_pw ) ) {
			$error = (string) ANPA_Socios_Master_Auth::validate_admin_password( $admin_pw );
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

		// 1) Master email + schema.
		update_option( 'anpa_socios_master_email', strtolower( $email ) );
		ANPA_Socios_DB::crear_tabelas();

		// 2) Master socio (active + master) so it can log in.
		self::ensure_master_socio( strtolower( $email ) );

		// 3) Optional short admin password.
		if ( '' !== $admin_pw ) {
			ANPA_Socios_Master_Auth::set_admin_password( $admin_pw );
		}

		// 4) Season config.
		self::upsert_course( $curso, $inicio, $peche );

		// 5) Socios page (create or overwrite) with the area shortcode.
		$page_id = self::ensure_socios_page( $socios_page );

		// 5b) Ensure the signup (asociarse) and extraescolares pages exist so the
		// alta flow and the public activities/timetable work out of the box.
		self::ensure_page_by_shortcode( 'anpa_socios_asociarse', 'asociarse', 'Asociarse', '[anpa_socios_asociarse]' );
		// Extraescolares: create only if absent; NEVER overwrite an existing page.
		self::ensure_page_by_shortcode( 'anpa_extraescolares_ofertadas', 'extraescolares', 'Extraescolares', self::extraescolares_page_content(), false );

		// 6) Sealed-box banking key (once).
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

		// 7) Mark initialized.
		ANPA_Socios_Master_Auth::mark_initialized();

		self::render_setup_result( strtolower( $email ), $passphrase, $secret_key, $page_id );
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
	private static function render_setup_result( string $email, string $passphrase, ?string $secret_key, int $page_id ): void {
		nocache_headers();
		$area_url     = $page_id > 0 ? (string) get_permalink( $page_id ) : self::landing_page_url();
		$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );

		echo '<div class="notice notice-success"><p><strong>Instalación completada.</strong> O equipo administrador, a clave bancaria e a páxina de socios quedaron configurados.</p></div>';

		echo '<div class="notice notice-warning" style="padding:8px 12px"><p><strong>Garda esta información AGORA. Non se volverá amosar.</strong></p>';
		echo '<table class="widefat" style="max-width:760px"><tbody>';
		printf( '<tr><td style="width:220px"><strong>Email do master</strong></td><td><code>%s</code></td></tr>', esc_html( $email ) );
		printf( '<tr><td><strong>Frase da clave bancaria</strong></td><td><code>%s</code></td></tr>', esc_html( $passphrase ) );
		if ( null !== $secret_key && '' !== $secret_key ) {
			printf( '<tr><td><strong>Clave privada (escrow)</strong></td><td><code style="word-break:break-all">%s</code></td></tr>', esc_html( $secret_key ) );
		} else {
			echo '<tr><td><strong>Clave privada</strong></td><td><em>xa existía; non se rexenerou.</em></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>Sen a frase e a clave privada, os datos bancarios cifrados serán irrecuperables.</p></div>';

		echo '<h2>Seguintes pasos</h2><ol>';
		printf( '<li>Vai á <a href="%s">páxina de socios</a> e inicia sesión co email do master (<code>%s</code>). Recibirás un código de acceso por correo.</li>', esc_url( $area_url ), esc_html( $email ) );
		echo '<li>Podes editar a configuración e o contrasinal de admin en calquera momento desde esta páxina de Axustes.</li>';
		printf(
			'<li>Para amosar as actividades extraescolares e o horario, consulta a <a href="%s">documentación</a> co código a pegar na páxina de extraescolares (e suxestións de FAQ e cabeceira).</li>',
			esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG ) )
		);
		echo '</ol>';
		printf( '<p><a class="button button-primary" href="%s">Ir a Axustes</a> <a class="button" href="%s">Abrir a páxina de socios</a></p>', esc_url( $settings_url ), esc_url( $area_url ) );
	}

	/**
	 * Post-setup configuration editor.
	 *
	 * @return void
	 */
	private static function render_tabs(): void {
		$active = ANPA_Socios_Settings_Tabs::active(
			isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''
		);
		$base = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( ANPA_Socios_Settings_Tabs::all() as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( 'tab', $slug, $base ) ),
				$active === $slug ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		echo '<div class="anpa-tab-panel">';
		switch ( $active ) {
			case 'localizacion':
				self::render_tab_localizacion();
				break;
			case 'verificacion':
				self::render_tab_verificacion();
				break;
			case 'actualizacions':
				self::render_tab_actualizacions();
				break;
			case 'mantemento':
				self::render_tab_mantemento();
				break;
			case 'xeral':
			default:
				self::render_tab_xeral();
				break;
		}
		echo '</div>';
	}

	/**
	 * Tab "Xeral": status summary + editable configuration (Settings-style form
	 * handled via admin-post + PRG).
	 *
	 * @return void
	 */
	private static function render_tab_xeral(): void {
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$season   = ANPA_Socios_Season_Service::current_course_row();
		$master   = ANPA_Socios_Config::master_email();
		$has_pw    = ANPA_Socios_Master_Auth::admin_password_exists();

		echo '<h2>Estado</h2>';
		echo '<table class="widefat striped" style="max-width:680px"><tbody>';
		printf( '<tr><td><strong>Versión da base de datos</strong></td><td>%s</td></tr>', esc_html( (string) get_option( 'anpa_socios_db_version', '(non instalada)' ) ) );
		printf( '<tr><td><strong>Clave bancaria</strong></td><td>%s</td></tr>', ANPA_Socios_Banking_Key::is_configured() ? '✅ configurada' : '❌ sen configurar' );
		printf( '<tr><td><strong>Contrasinal curto de admin</strong></td><td>%s</td></tr>', $has_pw ? '✅ definido' : '❌ sen definir' );
		printf( '<tr><td><strong>Email do equipo administrador</strong></td><td>%s</td></tr>', esc_html( $master ) );
		echo '</tbody></table>';

		$estados = array(
			ANPA_Socios_Season::ESTADO_PENDENTE => 'Pendente (pre-temporada)',
			ANPA_Socios_Season::ESTADO_ACTIVO   => 'Activo',
			ANPA_Socios_Season::ESTADO_PECHADO  => 'Pechado',
		);
		echo '<h2>Configuración</h2>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_save_settings">';
		wp_nonce_field( 'anpa_socios_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row"><label for="cfg-master">Email do equipo administrador</label></th><td><input name="master_email" id="cfg-master" type="email" class="regular-text" value="%s"></td></tr>', esc_attr( $master ) );

		echo '<tr><th scope="row"><label for="cfg-landing">Páxina de socios</label></th><td>';
		wp_dropdown_pages( array(
			'name'              => 'landing_page_id',
			'id'                => 'cfg-landing',
			'selected'          => (int) get_option( self::LANDING_OPTION, 0 ),
			'show_option_none'  => '— Detección automática —',
			'option_none_value' => 0,
		) );
		echo '<p class="description">Páxina que contén a área de socios.</p></td></tr>';

		printf( '<tr><th scope="row"><label for="cfg-curso">Curso escolar actual</label></th><td><input name="curso_escolar" id="cfg-curso" type="text" value="%s" pattern="\d{4}/\d{4}" class="regular-text"></td></tr>', esc_attr( (string) $season['curso_escolar'] ) );

		echo '<tr><th scope="row"><label for="cfg-estado">Estado do curso</label></th><td><select name="estado" id="cfg-estado">';
		foreach ( $estados as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( (string) $season['estado'], $val, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		printf( '<tr><th scope="row"><label for="cfg-inicio">Comeza (data_inicio)</label></th><td><input name="data_inicio" id="cfg-inicio" type="date" value="%s"></td></tr>', esc_attr( (string) $season['data_inicio'] ) );
		printf( '<tr><th scope="row"><label for="cfg-peche">Pecha (data_peche)</label></th><td><input name="data_peche" id="cfg-peche" type="date" value="%s"></td></tr>', esc_attr( (string) $season['data_peche'] ) );

		printf(
			'<tr><th scope="row"><label for="cfg-assoc">Nome da asociación</label></th><td><input name="association_name" id="cfg-assoc" type="text" class="regular-text" value="%s"><p class="description">Úsase en toda a app (correos, avisos) no canto dun valor fixo.</p></td></tr>',
			esc_attr( ANPA_Socios_Config::association_name() )
		);
		printf(
			'<tr><th scope="row"><label for="cfg-sign">Firma dos correos</label></th><td><textarea name="email_signature" id="cfg-sign" class="large-text" rows="3">%s</textarea><p class="description">Engádese ao final dos correos enviados dende a conta do equipo administrador.</p></td></tr>',
			esc_textarea( ANPA_Socios_Config::email_signature() )
		);
		printf(
			'<tr><th scope="row">Aprobación de socios novos</th><td><label><input type="checkbox" name="require_approval" value="1" %s> Os socios novos precisan aprobación do equipo administrador antes de acceder.</label></td></tr>',
			checked( ANPA_Socios_Config::require_approval(), true, false )
		);

		echo '</tbody></table>';
		submit_button( 'Gardar configuración' );
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
		submit_button( 'Gardar localización' );
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
	 * Tab "Actualizacións": current version, update source, token status and a
	 * manual "check now" action.
	 *
	 * @return void
	 */
	private static function render_tab_actualizacions(): void {
		$post_url  = esc_url( admin_url( 'admin-post.php' ) );
		$version   = defined( 'ANPA_SOCIOS_VERSION' ) ? ANPA_SOCIOS_VERSION : '?';
		$repo      = ANPA_Socios_Updater::REPO_URL;

		echo '<h2>Actualizacións</h2>';
		echo '<table class="widefat striped" style="max-width:680px"><tbody>';
		printf( '<tr><td style="width:260px"><strong>Versión instalada</strong></td><td>%s</td></tr>', esc_html( (string) $version ) );
		printf( '<tr><td><strong>Orixe das actualizacións</strong></td><td><a href="%s/releases" target="_blank" rel="noreferrer">%s</a></td></tr>', esc_url( $repo . '/releases' ), esc_html( 'anpaventin/wp-anpa-socios' ) );

		$pending = get_site_transient( 'update_plugins' );
		$slug    = 'anpa-socios/anpa-socios.php';
		$new_ver = '';
		if ( is_object( $pending ) && ! empty( $pending->response[ $slug ]->new_version ) ) {
			$new_ver = (string) $pending->response[ $slug ]->new_version;
		}
		printf(
			'<tr><td><strong>Estado</strong></td><td>%s</td></tr>',
			'' !== $new_ver
				? '⬆️ hai unha actualización dispoñible: <strong>' . esc_html( $new_ver ) . '</strong> (ver <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins</a>)'
				: '✅ ao día'
		);
		echo '</tbody></table>';

		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_check_updates">';
		wp_nonce_field( 'anpa_socios_check_updates' );
		submit_button( 'Comprobar actualizacións agora', 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">Comproba a última <em>Release</em> publicada no repositorio e, se hai unha versión máis nova, aparecerá en <strong>Plugins</strong> para actualizar cun clic.</p>';
	}

	/**
	 * Tab "Copias & Mantemento": admin password, season check, backup, restore
	 * and wipe. All actions use admin-post handlers with nonce + capability.
	 *
	 * @return void
	 */
	private static function render_tab_mantemento(): void {
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$has_pw   = ANPA_Socios_Master_Auth::admin_password_exists();

		echo '<h2>Contrasinal curto de admin</h2>';
		echo '<p class="description">Panel de admin (mín. 8 caracteres, unha maiúscula e un símbolo). A frase da clave bancaria non se cambia aquí (só reinstalando a BD).</p>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_set_admin_password">';
		wp_nonce_field( 'anpa_socios_set_admin_password' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="cfg-pw">' . ( $has_pw ? 'Novo' : 'Definir' ) . ' contrasinal</label></th><td>';
		echo '<input name="admin_password" id="cfg-pw" type="password" class="regular-text" autocomplete="new-password" required>';
		echo self::eye_button( 'cfg-pw' );
		echo '</td></tr></tbody></table>';
		submit_button( $has_pw ? 'Cambiar contrasinal' : 'Definir contrasinal', 'secondary' );
		echo '</form>';

		echo '<h2>Mantemento</h2>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_run_season">';
		wp_nonce_field( 'anpa_socios_run_season' );
		submit_button( 'Executar comprobación de temporada agora', 'secondary', 'submit', false );
		echo '</form>';

		// ── Copia de seguridade / restauración / borrado ──
		echo '<hr><h2>Copia de seguridade</h2>';
		echo '<p class="description">A copia inclúe socios, fillos, actividades, cursos, matrículas, empresas e datos bancarios. NON inclúe o usuario master, as claves de cifrado nin o contrasinal de admin. O ficheiro cífrase co contrasinal de admin.</p>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_backup">';
		wp_nonce_field( 'anpa_socios_backup' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="bk-admin">Contrasinal de admin</label></th><td><input name="admin_password" id="bk-admin" type="password" class="regular-text" autocomplete="off" required>' . self::eye_button( 'bk-admin' ) . '</td></tr>';
		echo '<tr><th scope="row"><label for="bk-pass">Frase da clave bancaria</label></th><td><input name="banking_passphrase" id="bk-pass" type="text" class="regular-text code" autocomplete="off" required>' . self::eye_button( 'bk-pass' ) . '<p class="description">Necesaria para descifrar os datos bancarios e incluílos na copia.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Descargar copia de seguridade', 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>Recuperar copia</h2>';
		echo '<p class="description">Sube un ficheiro <code>.anpabak</code> e o contrasinal de admin co que se cifrou. Os datos bancarios recifraranse coa clave actual.</p>';
		echo '<form method="post" action="' . $post_url . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="anpa_socios_restore">';
		wp_nonce_field( 'anpa_socios_restore' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="rs-file">Ficheiro de copia</label></th><td><input name="backup_file" id="rs-file" type="file" accept=".anpabak,application/json" required></td></tr>';
		echo '<tr><th scope="row"><label for="rs-admin">Contrasinal do ficheiro</label></th><td><input name="admin_password" id="rs-admin" type="password" class="regular-text" autocomplete="off" required>' . self::eye_button( 'rs-admin' ) . '</td></tr>';
		echo '</tbody></table>';
		submit_button( 'Recuperar copia', 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2 style="color:#b32d2e">Borrar base de datos</h2>';
		echo '<p class="description" style="color:#b32d2e"><strong>Irreversible.</strong> Borra TODOS os datos do plugin e volve ao asistente de instalación. Descarga primeiro unha copia de seguridade.</p>';
		echo '<form method="post" action="' . $post_url . '" onsubmit="return confirm(\'Seguro? Esta acción borra TODOS os datos e non se pode desfacer.\');">';
		echo '<input type="hidden" name="action" value="anpa_socios_wipe">';
		wp_nonce_field( 'anpa_socios_wipe' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wp-admin-pw">Contrasinal de admin</label></th><td><input name="admin_password" id="wp-admin-pw" type="password" class="regular-text" autocomplete="off" required>' . self::eye_button( 'wp-admin-pw' ) . '</td></tr>';
		echo '<tr><th scope="row">Confirmación</th><td><label><input type="checkbox" name="confirm_wipe" value="1" required> Descarguei unha copia e entendo que esta acción é irreversible.</label></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Borrar base de datos', 'delete', 'submit', false );
		echo '</form>';
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
			.anpa-cfg .nav-tab-wrapper { margin: 1em 0 1.4em; }
			.anpa-cfg h2:not(.nav-tab-wrapper) { margin: 2em 0 .6em; padding: .6em 1em; background: #fbfbfc;
				border-left: 5px solid #e67e22; border-radius: 4px; font-size: 1.15em;
				box-shadow: 0 1px 2px rgba(0,0,0,.05); }
			.anpa-cfg h3 { margin: 1.2em 0 .3em; color: #2c3338; font-size: 1em; }
			.anpa-cfg .form-table, .anpa-cfg .widefat { background: #fff; border: 1px solid #e2e4e7;
				border-radius: 6px; padding: .6em 1.4em; margin: .4em 0 1.2em; max-width: 820px; }
			/* Comfortable breathing room so text is never glued to the box edge. */
			.anpa-cfg .form-table th { width: 260px; padding: 1em 1.2em 1em .4em; vertical-align: top; }
			.anpa-cfg .form-table td { padding: .9em 1em; }
			.anpa-cfg .widefat td, .anpa-cfg .widefat th { padding: .7em 1em; }
			.anpa-cfg .form-table input.regular-text,
			.anpa-cfg .form-table input[type="email"],
			.anpa-cfg .form-table input[type="text"],
			.anpa-cfg .form-table input[type="date"],
			.anpa-cfg .form-table select,
			.anpa-cfg .form-table textarea { padding: .5em .7em; }
			.anpa-cfg .description { color: #646970; margin-top: .5em; }
			.anpa-cfg hr { margin: 2.6em 0 0; border: 0; border-top: 1px dashed #c3c4c7; }
			.anpa-cfg form { margin: 0 0 .6em; }
			/* Reveal "eye": transparent icon overlaid inside the field, not a grey button. */
			.anpa-cfg .anpa-eye { background: transparent; border: 0; box-shadow: none; outline: 0;
				cursor: pointer; padding: 0; margin: 0 0 0 -2.2em; position: relative; font-size: 1.15em;
				line-height: 1; opacity: .6; vertical-align: middle; }
			.anpa-cfg .anpa-eye:hover, .anpa-cfg .anpa-eye:focus { opacity: 1; }
			.anpa-cfg .anpa-eye + .description { margin-left: 0; }
			/* leave room on the right so the text never runs under the eye */
			.anpa-cfg input.regular-text { padding-right: 2.6em; }
			.anpa-cfg h2[style*="b32d2e"] { border-left-color: #b32d2e; background: #fcf0f1; }
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

		return (string) ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area_unified' );
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

		$email   = sanitize_email( (string) wp_unslash( $_POST['master_email'] ?? '' ) );
		$landing = (int) ( $_POST['landing_page_id'] ?? 0 );
		$curso   = sanitize_text_field( (string) wp_unslash( $_POST['curso_escolar'] ?? '' ) );
		$estado  = sanitize_text_field( (string) wp_unslash( $_POST['estado'] ?? '' ) );
		$inicio  = sanitize_text_field( (string) wp_unslash( $_POST['data_inicio'] ?? '' ) );
		$peche   = sanitize_text_field( (string) wp_unslash( $_POST['data_peche'] ?? '' ) );

		if ( is_email( $email ) ) {
			update_option( 'anpa_socios_master_email', strtolower( $email ) );
		}
		update_option( self::LANDING_OPTION, $landing > 0 ? $landing : 0 );

		// fase12 identity/config (PR-12h).
		$assoc = sanitize_text_field( (string) wp_unslash( $_POST['association_name'] ?? '' ) );
		if ( '' !== $assoc ) {
			update_option( ANPA_Socios_Config::OPTION_ASSOCIATION, $assoc );
		}
		update_option( ANPA_Socios_Config::OPTION_SIGNATURE, sanitize_textarea_field( (string) wp_unslash( $_POST['email_signature'] ?? '' ) ) );
		update_option( ANPA_Socios_Config::OPTION_APPROVAL, ! empty( $_POST['require_approval'] ) ? '1' : '0' );

		if ( ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			$valid = array( ANPA_Socios_Season::ESTADO_PENDENTE, ANPA_Socios_Season::ESTADO_ACTIVO, ANPA_Socios_Season::ESTADO_PECHADO );
			if ( ! in_array( $estado, $valid, true ) ) {
				$estado = ANPA_Socios_Season::ESTADO_ACTIVO;
			}
			$inicio = self::valid_date( $inicio, ANPA_Socios_Season::default_data_inicio( $curso ) );
			$peche  = self::valid_date( $peche, ANPA_Socios_Season::default_data_peche( $curso ) );
			self::upsert_course( $curso, $inicio, $peche, $estado );
		}

		self::redirect_msg( 'settings_saved' );
	}

	/**
	 * admin-post: set/change the short admin password.
	 *
	 * @return void
	 */
	public static function handle_set_admin_password(): void {
		self::guard( 'anpa_socios_set_admin_password' );
		$pw     = (string) wp_unslash( $_POST['admin_password'] ?? '' );
		$result = ANPA_Socios_Master_Auth::set_admin_password( $pw );
		self::redirect_msg( true === $result ? 'pw_ok' : 'pw_bad' );
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
			array( 'anpa_msg' => 'updates_checked', 'tab' => 'actualizacions' ),
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
		$admin_pw = (string) wp_unslash( $_POST['admin_password'] ?? '' );
		$pass     = (string) wp_unslash( $_POST['banking_passphrase'] ?? '' );

		if ( ! ANPA_Socios_Master_Auth::verify_admin_password( $admin_pw ) ) {
			self::redirect_msg( 'bak_bad_pw' );
		}
		$blob = ANPA_Socios_Backup::build( $admin_pw, $pass );
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
		$admin_pw = (string) wp_unslash( $_POST['admin_password'] ?? '' );

		if ( empty( $_FILES['backup_file']['tmp_name'] ) || ! is_uploaded_file( (string) $_FILES['backup_file']['tmp_name'] ) ) {
			self::redirect_msg( 'restore_nofile' );
		}
		$blob = (string) file_get_contents( (string) $_FILES['backup_file']['tmp_name'] );
		$res  = ANPA_Socios_Backup::restore( $blob, $admin_pw );
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
		$admin_pw = (string) wp_unslash( $_POST['admin_password'] ?? '' );

		if ( empty( $_POST['confirm_wipe'] ) ) {
			self::redirect_msg( 'wipe_noconfirm' );
		}
		if ( ! ANPA_Socios_Master_Auth::verify_admin_password( $admin_pw ) ) {
			self::redirect_msg( 'bak_bad_pw' );
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
	 * Creates or promotes the master socio (active + master role).
	 *
	 * @param  string $email Lowercased master email.
	 * @return void
	 */
	private static function ensure_master_socio( string $email ): void {
		global $wpdb;
		$socios = ANPA_Socios_DB::tabela_socios();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- idempotent master bootstrap on setup.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$socios} (email, nome, apelidos, rol, estado, creado_en, actualizado_en)
			 VALUES (%s, 'Equipo', 'Directiva', 'master', 'activo', NOW(), NOW())
			 ON DUPLICATE KEY UPDATE rol = 'master', estado = 'activo', actualizado_en = NOW()",
			$email
		) );
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
			 VALUES (%s, 1, %s, %s, %s, NOW(), NOW())
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
		wp_safe_redirect( add_query_arg( 'anpa_msg', $key, admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) );
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
			'settings_saved' => array( 'success', 'Configuración gardada.' ),
			'pw_ok'          => array( 'success', 'Contrasinal de admin actualizado.' ),
			'pw_bad'         => array( 'error', 'O contrasinal non cumpre os requisitos (mín. 8 caracteres, unha maiúscula e un símbolo).' ),
			'season_ok'      => array( 'success', 'Comprobación de temporada executada.' ),
			'updates_checked' => array( 'success', 'Comprobación de actualizacións executada. Se hai unha versión nova, aparecerá en Plugins.' ),
			'bak_bad_pw'     => array( 'error', 'Contrasinal de admin incorrecto.' ),
			'bak_err'        => array( 'error', 'Non se puido xerar a copia (revisa a frase da clave bancaria).' ),
			'restored'       => array( 'success', 'Copia recuperada correctamente.' ),
			'restore_nofile' => array( 'error', 'Non se recibiu ningún ficheiro de copia.' ),
			'restore_err'    => array( 'error', 'Non se puido recuperar a copia (ficheiro ou contrasinal incorrectos).' ),
			'wiped'          => array( 'success', 'Base de datos borrada. Configura de novo o plugin.' ),
			'wipe_noconfirm' => array( 'error', 'Debes confirmar a casa de verificación para borrar a base de datos.' ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $key ][0] ), esc_html( $map[ $key ][1] ) );
	}

	/**
	 * Renders the offline docs mini-wiki.
	 *
	 * @return void
	 */
	public static function render_docs_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		echo '<div class="wrap"><h1>ANPA Socios — Documentación</h1><div style="max-width:820px">';
		echo '<h2>Que fai este plugin</h2>';
		echo '<p>Xestiona a área de socios: altas, fillos/as, actividades extraescolares, empresas e datos bancarios cifrados. A área pública amósase coa páxina que contén <code>' . esc_html( self::AREA_SHORTCODE ) . '</code>.</p>';
		echo '<h2>Posta en marcha (instalación limpa)</h2><ol>';
		echo '<li>En Axustes, introduce o email do equipo administrador, garda a frase da clave bancaria, define (opcionalmente) o contrasinal curto e escolle a páxina de socios.</li>';
		echo '<li>Lanza a instalación: créase o socio administrador, a clave bancaria, a páxina de socios e a configuración do curso.</li>';
		echo '<li>Vai á páxina de socios e inicia sesión co email do master (chega un código por correo).</li>';
		echo '</ol>';
		echo '<h2>Ciclo do curso escolar</h2><ul style="list-style:disc;margin-left:20px">';
		echo '<li>Curso do 1 de xullo ao 30 de xuño (<code>AAAA/AAAA+1</code>).</li>';
		echo '<li>O 20 de xuño péchase o curso e créase o seguinte en estado <em>pendente</em>.</li>';
		echo '<li>O curso pendente actívase automaticamente o 1 de setembro.</li>';
		echo '<li>En pre-temporada só o equipo administrador pode iniciar sesión.</li>';
		echo '</ul>';

		echo '<h2>Amosar as actividades extraescolares e o horario</h2>';
		echo '<p>A instalación crea automaticamente a páxina de extraescolares. Se precisas amosalas noutra páxina, copia estes bloques:</p>';
		echo '<h3>Actividades ofertadas</h3>';
		echo '<p>As actividades amósanse automaticamente a partir das actividades dadas de alta e activas para o curso actual. O icono escóllese no formulario de alta ou edición da actividade.</p>';
		echo '<p><code>[anpa_extraescolares_ofertadas]</code></p>';
		echo '<h3>Horario semanal</h3>';
		echo '<p>Comedor: as franxas de comedor mantéñense como información estática separada. As actividades extraescolares activas amósanse automaticamente na grella seguinte.</p>';
		echo '<p><code>[anpa_extraescolares_horario]</code></p>';

		echo '<h3>Suxestións para a páxina de extraescolares</h3>';
		echo '<ul style="list-style:disc;margin-left:20px">';
		echo '<li><strong>Cabeceira de exemplo</strong> cunha ligazón á área de socios: <code>[anpa_socios_area_link]</code> (mostra un botón/enlace á área persoal, coma na cabeceira actual).</li>';
		echo '<li><strong>Preguntas frecuentes (FAQ)</strong>: engade unha sección ao final da páxina coas dúbidas habituais (prazos, prezos, autorizacións de comedor/tarde, baixas por trimestre).</li>';
		echo '</ul>';

		echo '</div></div>';
	}
}
