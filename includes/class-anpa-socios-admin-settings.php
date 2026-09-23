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
	public const OVERVIEW_SLUG = 'anpa-socios';
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
		add_action( 'admin_post_anpa_socios_copiar_datas_curso', array( __CLASS__, 'handle_copiar_datas_curso' ) );
		add_action( 'admin_post_anpa_socios_run_season', array( __CLASS__, 'handle_run_season' ) );
		add_action( 'admin_post_anpa_socios_update_child_levels', array( __CLASS__, 'handle_update_child_levels' ) );
		add_action( 'admin_post_anpa_socios_apply_child_levels', array( __CLASS__, 'handle_apply_child_levels' ) );
		add_action( 'admin_post_anpa_socios_check_updates', array( __CLASS__, 'handle_check_updates' ) );
		add_action( 'admin_post_anpa_socios_backup', array( __CLASS__, 'handle_backup' ) );
		add_action( 'admin_post_anpa_socios_wipe', array( __CLASS__, 'handle_wipe' ) );
		add_action( 'admin_post_anpa_socios_restore', array( __CLASS__, 'handle_restore' ) );
		// FASE37: administrative content (transporte, libros, servizos).
		add_action( 'admin_post_anpa_save_contenido', array( __CLASS__, 'handle_save_contenido' ) );
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
	 * Registers the configurable top-level overview and its three stable
	 * submenu pages: Xestión, Axustes, Documentación.
	 *
	 * The visible top-level label comes from ANPA_Socios_Config::menu_name()
	 * so admins can rebrand the sidebar without touching slugs or deep links.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			esc_html__( 'ANPA Socios', 'anpa-socios' ),
			ANPA_Socios_Config::menu_name(),
			self::CAP,
			self::OVERVIEW_SLUG,
			array( __CLASS__, 'render_overview_page' ),
			'dashicons-groups',
			58
		);
		ANPA_Socios_Admin_Management_Page::register_menu( self::OVERVIEW_SLUG, self::CAP );
		// 1.64.0/1.65.0: the fase35 «Rexistro de envíos» screen was retired and removed
		// (no campaign was ever created; see openspec 2026-09-15-retirada-cola-comunicacions).
		// fase36: email template management.
		ANPA_Socios_Email_Templates_Page::register_menu( self::OVERVIEW_SLUG, self::CAP );
		add_submenu_page(
			self::OVERVIEW_SLUG,
			esc_html__( 'Axustes', 'anpa-socios' ),
			esc_html__( 'Axustes', 'anpa-socios' ),
			self::CAP,
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
		add_submenu_page(
			self::OVERVIEW_SLUG,
			esc_html__( 'Documentación', 'anpa-socios' ),
			esc_html__( 'Documentación', 'anpa-socios' ),
			self::CAP,
			self::DOCS_SLUG,
			array( __CLASS__, 'render_docs_page' )
		);
		remove_submenu_page( self::OVERVIEW_SLUG, self::OVERVIEW_SLUG );
	}

	/**
	 * Renders a short overview instead of duplicating the operational screen.
	 *
	 * @return void
	 */
	public static function render_overview_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acceso non permitido.', 'anpa-socios' ) );
		}

		$destinations = array(
			array( 'Xestión', 'Xestiona socios/as, fillos/as, empresas, actividades, grupos e matrículas.', ANPA_Socios_Admin_Management_Page::MANAGEMENT_SLUG ),
			array( 'Axustes', 'Configura o curso escolar, a estrutura do centro, o comedor e as opcións xerais.', self::SETTINGS_SLUG ),
			array( 'Documentación', 'Consulta as guías de uso, seguridade, copias e operación diaria.', self::DOCS_SLUG ),
		);

		echo '<div class="wrap anpa-overview">';
		echo '<h1>' . esc_html( ANPA_Socios_Config::menu_name() ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Este plugin centraliza a xestión de socios/as e actividades extraescolares da asociación.', 'anpa-socios' ) . '</p>';
		echo '<p>' . esc_html__( 'Escolle unha das seguintes áreas para continuar:', 'anpa-socios' ) . '</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;max-width:1100px">';
		foreach ( $destinations as $destination ) {
			echo '<section class="card" style="margin:0;max-width:none">';
			echo '<h2>' . esc_html__( $destination[0], 'anpa-socios' ) . '</h2>';
			echo '<p>' . esc_html__( $destination[1], 'anpa-socios' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . $destination[2] ) ) . '">' . sprintf( esc_html__( 'Ir a %s', 'anpa-socios' ), esc_html( $destination[0] ) ) . '</a></p>';
			echo '</section>';
		}
		echo '</div></div>';
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
	 * Value to prefill the wizard's email-signature field.
	 *
	 * Uses the saved signature if one already exists; otherwise a neutral
	 * template built from the association name option (no association-specific
	 * data is hardcoded, so the public plugin stays reusable).
	 *
	 * @return string
	 */
	private static function wizard_signature_prefill(): string {
		$saved = trim( ANPA_Socios_Config::email_signature() );
		if ( '' !== $saved ) {
			return $saved;
		}
		return "—\n" . ANPA_Socios_Config::association_name();
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
		echo '<h1>' . esc_html__( 'Axustes', 'anpa-socios' ) . '</h1>';

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

		echo '<tr><th scope="row">' . esc_html__( 'Matrículas', 'anpa-socios' ) . '</th><td><label><input type="checkbox" name="abrir_matriculas" value="1"> ' . esc_html__( 'Abrir as matrículas neste curso ao activalo', 'anpa-socios' ) . '</label></td></tr>';

		echo '</tbody></table>';

		// ── Identidade da asociación (Xeral) ──────────────────────────
		echo '<h2>' . esc_html__( 'Datos da asociación', 'anpa-socios' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="anpa-w-assoc">%s</label></th><td><input name="association_name" id="anpa-w-assoc" type="text" class="regular-text" value="%s"></td></tr>',
			esc_html__( 'Nome da asociación', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::association_name() )
		);
		printf(
			'<tr><th scope="row"><label for="anpa-w-fee">%s</label></th><td><input name="membership_fee" id="anpa-w-fee" type="text" class="small-text" value="%s"> €<p class="description">%s</p></td></tr>',
			esc_html__( 'Cota anual (por familia e curso)', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::membership_fee() ),
			esc_html__( 'Amósase no formulario de alta.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="anpa-w-contact">%s</label></th><td><input name="contact_email" id="anpa-w-contact" type="email" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Email de contacto para familias', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::contact_email() ),
			esc_html__( 'Enderezo público ao que escriben as familias.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="anpa-w-master">%s</label></th><td><input name="master_email" id="anpa-w-master" type="email" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Email do administrador raíz', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::master_email() ),
			esc_html__( 'Conta protexida que nunca se pode dar de baixa nin eliminar. Non controla o remitente dos correos (iso configúrase en WP Mail SMTP).', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="anpa-w-addr">%s</label></th><td><input name="association_address" id="anpa-w-addr" type="text" class="regular-text" placeholder="%s" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Enderezo (para o aviso RGPD)', 'anpa-socios' ),
			esc_attr__( 'Rúa Exemplo, 1', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::association_address() ),
			esc_html__( 'Amósase no aviso de protección de datos (RGPD) do formulario de alta. Opcional.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="anpa-w-sign">%s</label></th><td><textarea name="email_signature" id="anpa-w-sign" class="large-text" rows="6">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Firma dos correos', 'anpa-socios' ),
			esc_textarea( self::wizard_signature_prefill() ),
			esc_html__( 'Engádese ao final dos correos que envía o plugin. Podes editala. Admite ligazóns e negriña en HTML: <a href="https://…">Facebook</a>, <strong>texto</strong>; os saltos de liña respéctanse.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="anpa-w-menu">%s</label></th><td><input name="menu_name" id="anpa-w-menu" type="text" class="regular-text" maxlength="%d" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Nome do menú lateral', 'anpa-socios' ),
			(int) ANPA_Socios_Config::MENU_NAME_MAX_LENGTH,
			esc_attr( ANPA_Socios_Config::menu_name() ),
			esc_html__( 'Etiqueta do menú de administración (por defecto «Xestión ANPA»).', 'anpa-socios' )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Aprobación de altas', 'anpa-socios' ) . '</th><td><label><input type="checkbox" name="require_approval" value="1"' . checked( ANPA_Socios_Config::require_approval(), true, false ) . '> ' . esc_html__( 'Requirir aprobación da directiva antes de activar un/unha socio/a novo/a', 'anpa-socios' ) . '</label></td></tr>';
		echo '</tbody></table>';

		// ── Localización ──────────────────────────────────────────────
		echo '<h2>' . esc_html__( 'Localización', 'anpa-socios' ) . '</h2>';
		echo '<p class="description" style="max-width:720px">' . esc_html__( 'Valores por defecto que se prefillan no formulario de alta (a familia pode cambialos).', 'anpa-socios' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row"><label for="anpa-w-country">%s</label></th><td><input name="country" id="anpa-w-country" type="text" class="regular-text" value="%s"></td></tr>', esc_html__( 'País', 'anpa-socios' ), esc_attr( ANPA_Socios_Config::country() ) );
		printf( '<tr><th scope="row"><label for="anpa-w-prov">%s</label></th><td><input name="default_province" id="anpa-w-prov" type="text" class="regular-text" value="%s"></td></tr>', esc_html__( 'Provincia', 'anpa-socios' ), esc_attr( ANPA_Socios_Config::default_province() ) );
		printf( '<tr><th scope="row"><label for="anpa-w-town">%s</label></th><td><input name="default_town" id="anpa-w-town" type="text" class="regular-text" value="%s"></td></tr>', esc_html__( 'Poboación', 'anpa-socios' ), esc_attr( ANPA_Socios_Config::default_town() ) );
		printf( '<tr><th scope="row"><label for="anpa-w-cp">%s</label></th><td><input name="default_postal_code" id="anpa-w-cp" type="text" inputmode="numeric" maxlength="10" class="regular-text" placeholder="%s" value="%s"></td></tr>', esc_html__( 'Código postal', 'anpa-socios' ), esc_attr__( '00000', 'anpa-socios' ), esc_attr( ANPA_Socios_Config::default_postal_code() ) );
		echo '</tbody></table>';

		// ── Estrutura escolar por defecto (niveis + aulas) ────────────
		echo '<h2>' . esc_html__( 'Estrutura escolar', 'anpa-socios' ) . '</h2>';
		echo '<label><input type="checkbox" name="seed_structure" value="1" checked> ' . esc_html__( 'Crear a estrutura por defecto de niveis e aulas (podes editala despois en Axustes → Cursos → Estrutura escolar).', 'anpa-socios' ) . '</label>';
		echo '<table class="widefat striped" style="max-width:560px;margin-top:.5rem"><thead><tr>';
		echo '<th>' . esc_html__( 'Nivel', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Idade alumnado', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Última aula', 'anpa-socios' ) . '</th>';
		echo '</tr></thead><tbody>';
		$default_niveis = array(
			array( '1º', 8 ), array( '2º', 9 ), array( '3º', 10 ),
			array( '4º', 11 ), array( '5º', 12 ), array( '6º', 13 ),
		);
		$letras = range( 'A', 'H' );
		foreach ( $default_niveis as $i => $nv ) {
			echo '<tr>';
			printf( '<td><input type="text" name="niveis[%1$d][codigo]" value="%2$s" maxlength="30"></td>', (int) $i, esc_attr( $nv[0] ) );
			printf( '<td><input type="number" name="niveis[%1$d][orde]" value="%2$d" min="1" class="small-text"></td>', (int) $i, (int) $nv[1] );
			echo '<td><select name="niveis[' . (int) $i . '][ultima]">';
			foreach ( $letras as $l ) {
				printf( '<option value="%1$s"%2$s>A–%1$s</option>', esc_attr( $l ), selected( 'D', $l, false ) );
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Os horarios de comedor por nivel e curso configúranse en Axustes → Cursos → Estrutura escolar e comedor.', 'anpa-socios' ) . '</p>';

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

		// 2) Season config (dates + pendente); activation happens in 2c.
		self::upsert_course( $curso, $inicio, $peche );

		// 2b) Association identity + localization (Xeral). Only non-empty text
		// overwrites the neutral defaults; the rest are stored as given.
		$assoc = trim( sanitize_text_field( (string) wp_unslash( $_POST['association_name'] ?? '' ) ) );
		if ( '' !== $assoc ) {
			update_option( ANPA_Socios_Config::OPTION_ASSOCIATION, $assoc );
		}
		$fee = trim( sanitize_text_field( (string) wp_unslash( $_POST['membership_fee'] ?? '' ) ) );
		if ( '' !== $fee ) {
			update_option( ANPA_Socios_Config::OPTION_FEE, $fee );
		}
		$contact_email = sanitize_email( (string) wp_unslash( $_POST['contact_email'] ?? '' ) );
		if ( is_email( $contact_email ) ) {
			update_option( ANPA_Socios_Config::OPTION_CONTACT_EMAIL, $contact_email );
		}
		$master_email = sanitize_email( (string) wp_unslash( $_POST['master_email'] ?? '' ) );
		if ( is_email( $master_email ) ) {
			update_option( ANPA_Socios_Config::OPTION, strtolower( $master_email ) );
		}
		update_option( ANPA_Socios_Config::OPTION_ADDRESS, sanitize_text_field( (string) wp_unslash( $_POST['association_address'] ?? '' ) ) );
		if ( isset( $_POST['email_signature'] ) ) {
			update_option( ANPA_Socios_Config::OPTION_SIGNATURE, ANPA_Socios_Email::sanitize_signature( (string) wp_unslash( $_POST['email_signature'] ) ) );
		}
		$menu_name = trim( wp_strip_all_tags( (string) wp_unslash( $_POST['menu_name'] ?? '' ) ) );
		if ( '' !== $menu_name ) {
			update_option( ANPA_Socios_Config::OPTION_MENU_NAME, $menu_name );
		}
		update_option( ANPA_Socios_Config::OPTION_APPROVAL, ! empty( $_POST['require_approval'] ) ? '1' : '0' );
		update_option( ANPA_Socios_Config::OPTION_COUNTRY, sanitize_text_field( (string) wp_unslash( $_POST['country'] ?? '' ) ) );
		update_option( ANPA_Socios_Config::OPTION_PROVINCE, sanitize_text_field( (string) wp_unslash( $_POST['default_province'] ?? '' ) ) );
		update_option( ANPA_Socios_Config::OPTION_TOWN, sanitize_text_field( (string) wp_unslash( $_POST['default_town'] ?? '' ) ) );
		update_option( ANPA_Socios_Config::OPTION_POSTAL_CODE, sanitize_text_field( (string) wp_unslash( $_POST['default_postal_code'] ?? '' ) ) );

		// 2c) Activate the course via the canonical lifecycle writer, opening
		// matrículas if requested. replace_active is implicit (only one active).
		$activate = new WP_REST_Request( 'PUT', '/anpa-socios/v1/admin/curso' );
		$activate->set_body_params( array(
			'curso_escolar'      => $curso,
			'estado'             => ANPA_Socios_Season::ESTADO_ACTIVO,
			'replace_active'     => true,
		) );
		ANPA_Socios_Admin_Cursos_Handler::update_curso( $activate );
		// 1.51.0 (E3): «abrir matrículas» means opening the current trimester's
		// window — the only enrolment switch. Audited like a manual transition.
		if ( ! empty( $_POST['abrir_matriculas'] ) ) {
			$wizard_user  = wp_get_current_user();
			$wizard_actor = ( $wizard_user instanceof WP_User && is_email( $wizard_user->user_email ) ) ? strtolower( $wizard_user->user_email ) : 'admin';
			ANPA_Socios_Trimestre_Repo::ensure_seeded( $curso, ANPA_Socios_Trimestre_Repo::ORIXE_ACTIVACION, $wizard_actor );
			$wizard_gate = ANPA_Socios_Matricula_Gate_Repo::para_curso( $curso );
			if ( ! $wizard_gate['abertas'] && $wizard_gate['trimestre'] > 0 ) {
				ANPA_Socios_Trimestre_Repo::transicionar_ventana( $curso, $wizard_gate['trimestre'], ANPA_Socios_Ventana_Estado::ABERTA, $wizard_actor, ANPA_Socios_Trimestre_Repo::ORIXE_MANUAL, function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wz_', true ), 'Asistente de posta en marcha: abrir matrículas' );
			}
			ANPA_Socios_Matricula_Gate_Repo::sincronizar_flag( $curso );
		}

		// 2d) Seed the default school structure (levels + classrooms) if asked.
		if ( ! empty( $_POST['seed_structure'] ) && isset( $_POST['niveis'] ) && is_array( $_POST['niveis'] ) ) {
			$specs = array();
			foreach ( (array) wp_unslash( $_POST['niveis'] ) as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$specs[] = array(
					'codigo'      => sanitize_text_field( (string) ( $raw['codigo'] ?? '' ) ),
					'orde'        => (int) ( $raw['orde'] ?? 0 ),
					'ultima_aula' => sanitize_text_field( (string) ( $raw['ultima'] ?? 'D' ) ),
				);
			}
			ANPA_Socios_Admin_Estrutura_Handler::seed_default_structure( $specs );
		}

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
		// Stacked layout (label above a full-width code block) so long values wrap
		// naturally on mobile instead of being squeezed into a narrow table column.
		echo '<div style="max-width:760px">';
		printf(
			'<p style="margin:0 0 2px"><strong>%s</strong></p><p style="margin:0 0 12px"><code style="display:block;padding:8px;word-break:break-all;white-space:normal;line-height:1.5">%s</code></p>',
			esc_html__( 'Frase da clave bancaria', 'anpa-socios' ),
			esc_html( $passphrase )
		);
		if ( null !== $secret_key && '' !== $secret_key ) {
			printf(
				'<p style="margin:0 0 2px"><strong>%s</strong></p><p style="margin:0"><code style="display:block;padding:8px;word-break:break-all;white-space:normal;line-height:1.5">%s</code></p>',
				esc_html__( 'Clave privada (escrow)', 'anpa-socios' ),
				esc_html( $secret_key )
			);
		} else {
			echo '<p style="margin:0 0 2px"><strong>' . esc_html__( 'Clave privada', 'anpa-socios' ) . '</strong></p><p style="margin:0"><em>' . esc_html__( 'xa existía; non se rexenerou.', 'anpa-socios' ) . '</em></p>';
		}
		echo '</div>';
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

		// Backwards-compatible deep links from the old flat tabs / subsection.
		if ( 'verificacion' === $requested_tab ) {
			$requested_section = 'estado';
			$requested_tab     = 'xeral';
		} elseif ( 'xeral' === $requested_tab && 'actualizacions' === $requested_section ) {
			$requested_tab     = 'actualizacions';
			$requested_section = '';
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
			case 'actualizacions':
				self::render_subsection_actualizacions( esc_url( admin_url( 'admin-post.php' ) ) );
				break;
			case 'contenido':
				self::render_tab_contenido( $section );
				break;
			case 'cursos':
				self::render_tab_cursos( $section );
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

		if ( 'estado' === $section ) {
			echo '<h2>' . esc_html__( 'Estado', 'anpa-socios' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:680px"><tbody>';
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Versión do plugin', 'anpa-socios' ), esc_html( defined( 'ANPA_SOCIOS_VERSION' ) ? ANPA_SOCIOS_VERSION : '?' ) );
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Versión da base de datos', 'anpa-socios' ), esc_html( (string) get_option( 'anpa_socios_db_version', __( '(non instalada)', 'anpa-socios' ) ) ) );
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Clave bancaria', 'anpa-socios' ), ANPA_Socios_Banking_Key::is_configured() ? '✅ ' . esc_html__( 'configurada', 'anpa-socios' ) : '❌ ' . esc_html__( 'sen configurar', 'anpa-socios' ) );
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html__( 'Email do equipo administrador', 'anpa-socios' ), esc_html( ANPA_Socios_Config::master_email() ) );
			$active_course = ANPA_Socios_Curso_Activo::get();
			if ( null === $active_course ) {
				echo '<tr><td><strong>' . esc_html__( 'Curso activo', 'anpa-socios' ) . '</strong></td><td>⚠️ ' . esc_html__( 'ningún curso activo', 'anpa-socios' ) . '</td></tr>';
			} else {
				$course_gate = ANPA_Socios_Matricula_Gate_Repo::para_curso( $active_course );
				printf(
					'<tr><td><strong>%s</strong></td><td>%s · %s</td></tr>',
					esc_html__( 'Curso activo', 'anpa-socios' ),
					esc_html( $active_course ),
					esc_html( ANPA_Socios_Matricula_Gate::etiqueta( $course_gate ) )
				);
			}

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
			return;
		}

		// Mantemento section — copias, contrasinais, ferramentas.
		if ( 'mantemento' === $section ) {
			self::render_subsection_contrasinais( $post_url );
			self::render_subsection_copias( $post_url );
			self::render_subsection_ferramentas( $post_url );
			// 1.64.0/1.65.0: the fase35 «Rexistro de comunicacións» subsection was retired
			// and removed (see openspec 2026-09-15-retirada-cola-comunicacions).
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
			echo '<code>[anpa_extraescolares_ofertadas]</code> — ' . esc_html__( 'Tarxetas de actividades ofertadas no curso actual.', 'anpa-socios' ) . '<br>';
			echo '<code>[anpa_extraescolares_horario]</code> — ' . esc_html__( 'Grella semanal con horarios e grupos activos.', 'anpa-socios' ) . '<br>';
			echo '</td></tr>';
			echo '</tbody></table>';
			submit_button( __( 'Gardar páxinas', 'anpa-socios' ) );
			echo '</form>';
			return;
		}

		echo '<h2>' . esc_html__( 'Configuración', 'anpa-socios' ) . '</h2>';
		printf(
			'<tr><th scope="row"><label for="cfg-master">%s</label></th><td><input name="master_email" id="cfg-master" type="email" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Email do administrador raíz', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::master_email() ),
			esc_html__( 'Identifica a conta de administrador raíz protexida (nunca pode darse de baixa nin eliminarse). Non controla o remitente dos correos — iso configúrase en WP Mail SMTP.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="cfg-assoc">%s</label></th><td><input name="association_name" id="cfg-assoc" type="text" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Nome da asociación', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::association_name() ),
			esc_html__( 'Úsase en toda a app (correos, avisos) no canto dun valor fixo.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="cfg-menu-name">%s</label></th><td><input name="menu_name" id="cfg-menu-name" type="text" class="regular-text" maxlength="%d" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Nome do menú', 'anpa-socios' ),
			ANPA_Socios_Config::MENU_NAME_MAX_LENGTH,
			esc_attr( ANPA_Socios_Config::menu_name() ),
			esc_html__( 'Etiqueta visible na barra lateral de administración. Se a deixas baleira, usarase «Xestión ANPA».', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="cfg-sign">%s</label></th><td><textarea name="email_signature" id="cfg-sign" class="large-text" rows="8">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Firma dos correos', 'anpa-socios' ),
			esc_textarea( ANPA_Socios_Config::email_signature() ),
			esc_html__( 'Engádese ao final de todos os correos que envía a web. Texto normal cos seus saltos de liña; admite ligazóns e negriña en HTML: <a href="https://…">Facebook</a>, <strong>texto</strong>. O resto de etiquetas elimínanse ao gardar.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="require_approval" value="1" %s> %s</label></td></tr>',
			esc_html__( 'Aprobación de socios novos', 'anpa-socios' ),
			checked( ANPA_Socios_Config::require_approval(), true, false ),
			esc_html__( 'Os socios novos precisan aprobación do equipo administrador antes de acceder.', 'anpa-socios' )
		);
		// 1.56.0: canteen account.
		printf(
			'<tr><th scope="row"><label for="cfg-comedor">%s</label></th><td><input name="comedor_email" id="cfg-comedor" type="email" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Correo da persoa responsable do comedor', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::comedor_email() ),
			esc_html__( 'Con este correo pódese entrar en «Socios → Área persoal» como conta do comedor: ve o alumnado matriculado en todas as actividades do curso actual, clasificado por actividade e coas opcións e autorizacións das familias, e descarga o listado completo sen baixas. Non pode ser o correo dun socio/a nin dunha empresa. Déixao baleiro para desactivar a conta.', 'anpa-socios' )
		);

		// 1.58.0: Google account for the Gmail contacts list.
		printf(
			'<tr><th scope="row"><label for="cfg-google">%s</label></th><td><input name="google_contacts_email" id="cfg-google" type="email" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Conta de Google da xunta (Lista Gmail)', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::google_contacts_email() ),
			esc_html__( 'Opcional. Correo da conta de Gmail onde se mantén a etiqueta «Socios Web ANPA». Só serve para que o botón «Abrir Google Contactos» de Xestión → Socios → Lista Gmail abra esa conta cando hai varias sesións de Google iniciadas; a web nunca escribe en Google.', 'anpa-socios' )
		);

		// 1.63.0: links used by the start-of-year email (template «inicio_curso»).
		printf(
			'<tr><th scope="row"><label for="cfg-instrucions">%s</label></th><td><input name="instrucions_url" id="cfg-instrucions" type="url" class="regular-text" value="%s" placeholder="https://"><p class="description">%s</p></td></tr>',
			esc_html__( 'Entrada coas instrucións para as familias', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::instrucions_url() ),
			esc_html__( 'URL da entrada do blog coas instrucións de alta, acceso, modificación de datos, extraescolares e baixas. Vai no correo de inicio de curso (Xestión → Socios → Lista Gmail). Se queda baleira, o correo liga á área de socios.', 'anpa-socios' )
		);
		printf(
			'<tr><th scope="row"><label for="cfg-extraescolares">%s</label></th><td><input name="extraescolares_url" id="cfg-extraescolares" type="url" class="regular-text" value="%s" placeholder="https://"><p class="description">%s</p></td></tr>',
			esc_html__( 'Páxina pública de extraescolares', 'anpa-socios' ),
			esc_attr( ANPA_Socios_Config::extraescolares_url() ),
			esc_html__( 'URL da páxina coa oferta de actividades e o horario. Vai no correo de inicio de curso. Se queda baleira, búscase a páxina que leva o shortcode [anpa_extraescolares_ofertadas].', 'anpa-socios' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Gardar configuración', 'anpa-socios' ) );
		echo '</form>';
	}

	/**
	 * Tab "Cursos": course-season lifecycle plus integrated course creation.
	 * Saved via an isolated admin-post handler so a partial form never clears
	 * other options.
	 *
	 * @return void
	 */
	private static function render_tab_cursos( string $section = 'curso-escolar' ): void {
		// Estrutura escolar (PR-ES3, fase23) has its own dedicated renderer.
		// It must never fall through to the course lifecycle editor below.
		// the section was unreachable from the settings UI despite its route
		// and handler existing.
		if ( 'estrutura' === $section ) {
			echo '<h2>' . esc_html__( 'Estrutura escolar e comedor', 'anpa-socios' ) . '</h2>';
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Horario de comedor', 'anpa-socios' ) . ':</strong> ' . esc_html__( 'configurarase por nivel e curso neste mesmo bloque. Se deixas as dúas horas baleiras, borrarase o horario dese nivel.', 'anpa-socios' ) . '</p></div>';
			ANPA_Socios_Estrutura_Escolar_Page::render();
			return;
		}

		// Grupos curriculares (fase24) — dedicated renderer, must not fall

		global $wpdb;
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$self_url = esc_url( admin_url( 'admin.php' ) );
		$cursos_t = ANPA_Socios_DB::tabela_cursos();

		// All stored courses (current + past + any future already created).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only course list for the selector.
		$rows = $wpdb->get_results( "SELECT curso_escolar, matriculas_abertas, estado, data_inicio, data_peche, t1_peche_operativo, t2_peche_operativo FROM {$cursos_t}", ARRAY_A );
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
			't1_peche_operativo' => '',
			't2_peche_operativo' => '',
		);
		// Operative dates may be absent on stored rows created before 1.38.0.
		$srow['t1_peche_operativo'] = (string) ( $srow['t1_peche_operativo'] ?? '' );
		$srow['t2_peche_operativo'] = (string) ( $srow['t2_peche_operativo'] ?? '' );
		// MySQL DATE NULLs surface as '0000-00-00' on some stacks; treat as empty.
		if ( '0000-00-00' === $srow['t1_peche_operativo'] ) { $srow['t1_peche_operativo'] = ''; }
		if ( '0000-00-00' === $srow['t2_peche_operativo'] ) { $srow['t2_peche_operativo'] = ''; }
		$estados = array(
			ANPA_Socios_Season::ESTADO_PENDENTE => __( 'Pendente (pre-temporada)', 'anpa-socios' ),
			ANPA_Socios_Season::ESTADO_ACTIVO   => __( 'Activo', 'anpa-socios' ),
			ANPA_Socios_Season::ESTADO_PECHADO  => __( 'Pechado', 'anpa-socios' ),
		);

			echo '<h2>' . esc_html__( 'Curso escolar', 'anpa-socios' ) . '</h2>';

		// --- Course selector (GET, auto-submits so the editor reloads) ---
		echo '<form method="get" action="' . $self_url . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SETTINGS_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="cursos">';
			echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '">';
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

		// --- Editor for the selected course (POST) ---
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_save_cursos">';
		echo '<input type="hidden" name="tab" value="cursos">';
		echo '<input type="hidden" name="section" value="curso-escolar">';
		wp_nonce_field( 'anpa_socios_save_cursos' );
		echo '<input type="hidden" name="curso_escolar" value="' . esc_attr( $sel ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row">%s</th><td><strong>%s</strong></td></tr>', esc_html__( 'Curso seleccionado', 'anpa-socios' ), esc_html( $sel ) );

		echo '<tr><th scope="row"><label for="cfg-estado">' . esc_html__( 'Estado do curso', 'anpa-socios' ) . '</label></th><td><select name="estado" id="cfg-estado">';
		foreach ( $estados as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, (string) $srow['estado'], false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Só pode existir un curso activo: ao activar este curso, o curso activo anterior pecharase automaticamente (coas súas matrículas).', 'anpa-socios' ) . '</p></td></tr>';
		// 1.51.0 (E3): read-only. The only switch is the trimester window below.
		$sel_gate = ANPA_Socios_Matricula_Gate_Repo::para_curso( $sel );
		printf(
			'<tr><th scope="row">%s</th><td><strong>%s</strong><p class="description" style="max-width:720px">%s</p></td></tr>',
			esc_html__( 'Matrículas', 'anpa-socios' ),
			esc_html( ANPA_Socios_Matricula_Gate::etiqueta( $sel_gate ) ),
			esc_html__( 'Regra única: as matrículas están abertas cando o curso está activo e a ventá do trimestre actual (segundo as datas operativas de abaixo) está aberta. Ábrense e péchanse en «Estado dos trimestres», máis abaixo; xa non hai unha casilla independente.', 'anpa-socios' )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Substituír curso activo', 'anpa-socios' ) . '</th><td><div class="notice notice-warning inline" style="margin:0"><p>' . esc_html__( 'Ao activar este curso, se hai outro curso activo pecharase automaticamente coas súas matrículas. Non é opcional: só pode haber un curso activo á vez.', 'anpa-socios' ) . '</p></div></td></tr>';
		printf( '<tr><th scope="row"><label for="cfg-inicio">%s</label></th><td><input name="data_inicio" id="cfg-inicio" type="date" value="%s"></td></tr>', esc_html__( 'Comeza (data_inicio)', 'anpa-socios' ), esc_attr( (string) $srow['data_inicio'] ) );
		printf( '<tr><th scope="row"><label for="cfg-peche">%s</label></th><td><input name="data_peche" id="cfg-peche" type="date" value="%s"></td></tr>', esc_html__( 'Pecha (data_peche)', 'anpa-socios' ), esc_attr( (string) $srow['data_peche'] ) );

		echo '<tr><th scope="row" colspan="2" style="padding-bottom:0"><h3 style="margin:0">' . esc_html__( 'Datas operativas dos trimestres', 'anpa-socios' ) . '</h3></th></tr>';
		echo '<tr><td colspan="2" style="padding-top:0"><p class="description" style="max-width:720px">' . esc_html__( 'As datas de peche operativo son datas de xestión (recoméndase fixalas un pouco antes do remate lectivo de cada trimestre). Serven para derivar o trimestre a partir da data e para avisar cando chega o fin dun trimestre. Son opcionais: se as deixas baleiras, o cálculo do trimestre volve ao modelo mensual. Deben ir en orde: comeza < peche T1 < peche T2 < pecha.', 'anpa-socios' ) . '</p></td></tr>';
		printf( '<tr><th scope="row"><label for="cfg-t1">%s</label></th><td><input name="t1_peche_operativo" id="cfg-t1" type="date" value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Peche operativo do 1º trimestre', 'anpa-socios' ), esc_attr( (string) $srow['t1_peche_operativo'] ), esc_html__( 'Ata esta data (incluída) as datas contan como 1º trimestre.', 'anpa-socios' ) );
		printf( '<tr><th scope="row"><label for="cfg-t2">%s</label></th><td><input name="t2_peche_operativo" id="cfg-t2" type="date" value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Peche operativo do 2º trimestre', 'anpa-socios' ), esc_attr( (string) $srow['t2_peche_operativo'] ), esc_html__( 'Entre o peche do 1º e esta data contan como 2º trimestre; despois, 3º.', 'anpa-socios' ) );

		echo '</tbody></table>';
		submit_button( __( 'Gardar curso', 'anpa-socios' ) );
		echo '</form>';

		// --- "Copiar do curso anterior" (dates shifted +1 year) ---
		$prev = ANPA_Socios_Curso_Escolar::previous( $sel );
		echo '<form method="post" action="' . $post_url . '" style="margin-top:-12px">';
		echo '<input type="hidden" name="action" value="anpa_socios_copiar_datas_curso">';
		wp_nonce_field( 'anpa_socios_copiar_datas_curso' );
		echo '<input type="hidden" name="curso_escolar" value="' . esc_attr( $sel ) . '">';
		echo '<p class="description" style="max-width:720px">' . sprintf(
			/* translators: %s: previous course, e.g. 2024/2025 */
			esc_html__( 'Copia as datas do curso anterior (%s) desprazadas un ano ao curso seleccionado. Poderás editalas antes de gardar.', 'anpa-socios' ),
			esc_html( (string) $prev )
		) . '</p>';
		submit_button( __( 'Copiar datas do curso anterior', 'anpa-socios' ), 'secondary', 'submit', false );
		echo '</form>';

		// 1.68.0: the trimester/window switch lives in Xestión → Extraescolares → Matrículas.
		$xestion_url = admin_url( 'admin.php?page=anpa-socios-management&section=matriculas' );
		echo '<h2>' . esc_html__( 'Estado dos trimestres e matrículas', 'anpa-socios' ) . '</h2>';
		echo '<p class="description" style="max-width:720px">' . esc_html__( 'Dende a versión 1.68.0 o trimestre activo, a apertura e o peche das matrículas e os avisos ás familias (comezo do curso, prazo, fin de curso) xestiónanse nun único sitio:', 'anpa-socios' ) . ' <a href="' . esc_url( $xestion_url ) . '">' . esc_html__( 'Xestión → Extraescolares → Matrículas', 'anpa-socios' ) . '</a>. ' . esc_html__( 'Aquí só quedan as datas do curso.', 'anpa-socios' ) . '</p>';

		// --- Integrated course creation (same canonical section and writer). ---
		echo '<h2>' . esc_html__( 'Crear novo curso', 'anpa-socios' ) . '</h2>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_save_cursos">';
		echo '<input type="hidden" name="tab" value="cursos">';
		echo '<input type="hidden" name="section" value="crear-novo">';
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
		printf(
			'<tr><th scope="row"><label for="loc-postal">Código postal (por defecto)</label></th><td><input name="default_postal_code" id="loc-postal" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" class="regular-text" value="%s"></td></tr>',
			esc_attr( ANPA_Socios_Config::default_postal_code() )
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
		update_option( ANPA_Socios_Config::OPTION_POSTAL_CODE, sanitize_text_field( (string) wp_unslash( $_POST['default_postal_code'] ?? '' ) ) );

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
				? '⬆️ hai unha actualización dispoñible: <strong>' . esc_html( $new_ver ) . '</strong> (ver <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins</a>)'
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
	 * FASE37: admin-post handler for saving contenido administrativo.
	 *
	 * Follows the existing admin-post + PRG pattern:
	 * 1. Capability + nonce (category-specific).
	 * 2. Strict whitelist of 5 categories.
	 * 3. Sanitize + normalize.
	 * 4. Persist via ANPA_Socios_Config.
	 * 5. Redirect with notice.
	 *
	 * @return void
	 */
	public static function handle_save_contenido(): void {
		$categoria = isset( $_POST['categoria'] ) ? sanitize_key( wp_unslash( $_POST['categoria'] ) ) : '';
		if ( ! in_array( $categoria, ANPA_Socios_Config::CATEGORIAS_VALIDAS, true ) ) {
			wp_die( esc_html__( 'Categoría non válida.', 'anpa-socios' ) );
		}

		$nonce_action = "anpa_save_contenido_{$categoria}";
		self::guard( $nonce_action );

		$data = array(
			'activo'     => ! empty( $_POST['activo'] ),
			'titulo'     => isset( $_POST['titulo'] ) ? sanitize_text_field( wp_unslash( $_POST['titulo'] ) ) : '',
			'contido'    => isset( $_POST['contido'] ) ? wp_kses_post( wp_unslash( $_POST['contido'] ) ) : '',
			'icono'      => isset( $_POST['icono'] ) ? sanitize_key( wp_unslash( $_POST['icono'] ) ) : '',
			'orden'      => isset( $_POST['orden'] ) ? absint( wp_unslash( $_POST['orden'] ) ) : 1,
			'documentos' => array(),
			'enlaces'    => array(),
		);

		// Documents: array of [id, url, title]
		if ( isset( $_POST['documentos'] ) && is_array( $_POST['documentos'] ) ) {
			foreach ( $_POST['documentos'] as $doc ) {
				if ( ! is_array( $doc ) ) {
					continue;
				}
				$data['documentos'][] = array(
					'id'    => isset( $doc['id'] ) ? absint( $doc['id'] ) : 0,
					'url'   => isset( $doc['url'] ) ? esc_url_raw( wp_unslash( $doc['url'] ) ) : '',
					'title' => isset( $doc['title'] ) ? sanitize_text_field( wp_unslash( $doc['title'] ) ) : '',
				);
			}
		}

		// Links: array of [title, url]
		if ( isset( $_POST['enlaces'] ) && is_array( $_POST['enlaces'] ) ) {
			foreach ( $_POST['enlaces'] as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}
				$data['enlaces'][] = array(
					'title' => isset( $link['title'] ) ? sanitize_text_field( wp_unslash( $link['title'] ) ) : '',
					'url'   => isset( $link['url'] ) ? esc_url_raw( wp_unslash( $link['url'] ) ) : '',
				);
			}
		}

		// Structured items for libros/comedor
		if ( in_array( $categoria, array( 'libros', 'comedor' ), true ) && isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
			foreach ( $_POST['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$sanitized = array();
				foreach ( $item as $key => $val ) {
					$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $val ) );
				}
				$data['items'][] = $sanitized;
			}
		}

		ANPA_Socios_Config::update_contenido_admin( $categoria, $data );

		$redirect = add_query_arg(
			array(
				'tab'     => 'contenido',
				'section' => $categoria,
				'anpa_msg' => 'contenido_saved',
			),
			admin_url( 'admin.php?page=' . self::SETTINGS_SLUG )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * FASE37: render the "Contido" tab with vertical category navigation.
	 *
	 * @param string $section Active category section.
	 * @return void
	 */
	private static function render_tab_contenido( string $section = 'transporte' ): void {
		$post_url  = esc_url( admin_url( 'admin-post.php' ) );
		$sections  = ANPA_Socios_Admin_Nav::settings_sections( 'contenido' );
		$active    = in_array( $section, array_keys( $sections ), true ) ? $section : 'transporte';
		$config    = ANPA_Socios_Config::contenido_admin( $active );

		printf( '<h2>%s</h2>', esc_html__( 'Contido Administrativo', 'anpa-socios' ) );
		echo '<p class="description">' . esc_html__( 'Xestiona o contido das categorías públicas: transporte, libros e servizos.', 'anpa-socios' ) . '</p>';

		// The category row is already printed by render_tabs() → render_section_nav()
		// for every tab; printing it again here showed two identical rows.
		printf( '<h3>%s</h3>', esc_html( $sections[ $active ] ?? $active ) );

		// Form
		echo '<form method="post" action="' . $post_url . '" class="anpa-form">';
		echo '<input type="hidden" name="action" value="anpa_save_contenido">';
		echo '<input type="hidden" name="categoria" value="' . esc_attr( $active ) . '">';
		echo '<input type="hidden" name="tab" value="contenido">';
		echo '<input type="hidden" name="section" value="' . esc_attr( $active ) . '">';
		wp_nonce_field( "anpa_save_contenido_{$active}" );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Activo
		echo '<tr><th scope="row"><label for="contenido-activo">' . esc_html__( 'Activo', 'anpa-socios' ) . '</label></th><td>';
		printf(
			'<input type="checkbox" id="contenido-activo" name="activo" value="1"%s>',
			checked( ! empty( $config['activo'] ), true, false )
		);
		echo '</td></tr>';

		// Título
		echo '<tr><th scope="row"><label for="contenido-titulo">' . esc_html__( 'Título', 'anpa-socios' ) . '</label></th><td>';
		printf(
			'<input type="text" id="contenido-titulo" name="titulo" value="%s" class="regular-text">',
			esc_attr( $config['titulo'] ?? '' )
		);
		echo '</td></tr>';

		// Contido
		echo '<tr><th scope="row"><label for="contenido-contido">' . esc_html__( 'Contido', 'anpa-socios' ) . '</label></th><td>';
		printf(
			'<textarea id="contenido-contido" name="contido" rows="6" class="large-text">%s</textarea>',
			esc_textarea( $config['contido'] ?? '' )
		);
		echo '</td></tr>';

		// Icono
		echo '<tr><th scope="row"><label for="contenido-icono">' . esc_html__( 'Icono', 'anpa-socios' ) . '</label></th><td>';
		printf(
			'<input type="text" id="contenido-icono" name="icono" value="%s" class="regular-text">',
			esc_attr( $config['icono'] ?? '' )
		);
		echo '<p class="description">' . esc_html__( 'Clase Dashicon (ex: dashicons-car).', 'anpa-socios' ) . '</p>';
		echo '</td></tr>';

		// Orden
		echo '<tr><th scope="row"><label for="contenido-orden">' . esc_html__( 'Orden', 'anpa-socios' ) . '</label></th><td>';
		printf(
			'<input type="number" id="contenido-orden" name="orden" value="%d" min="1" max="5" class="small-text">',
			absint( $config['orden'] ?? 1 )
		);
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Gardar cambios', 'anpa-socios' ) );
		echo '</form>';

		// FASE37 M4/M5: Structured items for libros/comedor
		if ( 'libros' === $active ) {
			self::render_libros_items_form( $config['items'] ?? [], $post_url );
		} elseif ( 'comedor' === $active ) {
			self::render_comedor_items_form( $config['items'] ?? [], $post_url );
		}

		// FASE37 M6: Documents and links for all categories
		self::render_documentos_form( $config['documentos'] ?? [], $post_url );
		self::render_enlaces_form( $config['enlaces'] ?? [], $post_url );
	}

	/**
	 * FASE37 M6: Render the documents sub-form with Media Library.
	 *
	 * @param array  $documentos Current documents.
	 * @param string $post_url   Admin-post URL.
	 * @return void
	 */
	private static function render_documentos_form( array $documentos, string $post_url ): void {
		echo '<h3>' . esc_html__( 'Documentos', 'anpa-socios' ) . '</h3>';

		echo '<table class="widefat striped anpa-documentos-table" style="max-width:700px">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Lista de documentos', 'anpa-socios' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Título', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'URL', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Accións', 'anpa-socios' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if ( empty( $documentos ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Non hai documentos engadidos.', 'anpa-socios' ) . '</td></tr>';
		} else {
			foreach ( $documentos as $index => $doc ) {
				echo '<tr>';
				printf( '<td><input type="number" name="documentos[%d][id]" value="%d" class="small-text" min="1"></td>', $index, $doc['id'] );
				printf( '<td><input type="text" name="documentos[%d][title]" value="%s" class="regular-text"></td>', $index, esc_attr( $doc['title'] ?? '' ) );
				printf( '<td><input type="text" name="documentos[%d][url]" value="%s" class="regular-text"></td>', $index, esc_attr( $doc['url'] ?? '' ) );
				printf( '<td><button type="button" class="button anpa-remove-doc" data-index="%d">%s</button></td>', $index, esc_html__( 'Eliminar', 'anpa-socios' ) );
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		echo '<p><button type="button" class="button button-secondary anpa-add-doc">' . esc_html__( 'Engadir documento', 'anpa-socios' ) . '</button></p>';

		echo '<script>
		document.addEventListener("DOMContentLoaded", function() {
			var table = document.querySelector(".anpa-documentos-table tbody");
			var addBtn = document.querySelector(".anpa-add-doc");
			if (addBtn && table) {
				addBtn.addEventListener("click", function() {
					var index = table.querySelectorAll("tr").length;
					var row = document.createElement("tr");
					row.innerHTML = "<td><input type=\"number\" name=\"documentos[" + index + "][id]\" value=\"\" class=\"small-text\" min=\"1\"></td>" +
						"<td><input type=\"text\" name=\"documentos[" + index + "][title]\" value=\"\" class=\"regular-text\"></td>" +
						"<td><input type=\"text\" name=\"documentos[" + index + "][url]\" value=\"\" class=\"regular-text\"></td>" +
						"<td><button type=\"button\" class=\"button anpa-remove-doc\">' . esc_js( __( 'Eliminar', 'anpa-socios' ) ) . '</button></td>";
					table.appendChild(row);
				});
				table.addEventListener("click", function(e) {
					if (e.target.classList.contains("anpa-remove-doc")) {
						e.target.closest("tr").remove();
					}
				});
			}
		});
		</script>';
	}

	/**
	 * FASE37 M6: Render the links sub-form.
	 *
	 * @param array  $enlaces Current links.
	 * @param string $post_url Admin-post URL.
	 * @return void
	 */
	private static function render_enlaces_form( array $enlaces, string $post_url ): void {
		echo '<h3>' . esc_html__( 'Enlaces', 'anpa-socios' ) . '</h3>';

		echo '<table class="widefat striped anpa-enlaces-table" style="max-width:700px">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Lista de enlaces', 'anpa-socios' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Título', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'URL', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Accións', 'anpa-socios' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if ( empty( $enlaces ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'Non hai enlaces engadidos.', 'anpa-socios' ) . '</td></tr>';
		} else {
			foreach ( $enlaces as $index => $link ) {
				echo '<tr>';
				printf( '<td><input type="text" name="enlaces[%d][title]" value="%s" class="regular-text"></td>', $index, esc_attr( $link['title'] ?? '' ) );
				printf( '<td><input type="text" name="enlaces[%d][url]" value="%s" class="regular-text"></td>', $index, esc_attr( $link['url'] ?? '' ) );
				printf( '<td><button type="button" class="button anpa-remove-link" data-index="%d">%s</button></td>', $index, esc_html__( 'Eliminar', 'anpa-socios' ) );
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		echo '<p><button type="button" class="button button-secondary anpa-add-link">' . esc_html__( 'Engadir enlace', 'anpa-socios' ) . '</button></p>';

		echo '<script>
		document.addEventListener("DOMContentLoaded", function() {
			var table = document.querySelector(".anpa-enlaces-table tbody");
			var addBtn = document.querySelector(".anpa-add-link");
			if (addBtn && table) {
				addBtn.addEventListener("click", function() {
					var index = table.querySelectorAll("tr").length;
					var row = document.createElement("tr");
					row.innerHTML = "<td><input type=\"text\" name=\"enlaces[" + index + "][title]\" value=\"\" class=\"regular-text\"></td>" +
						"<td><input type=\"text\" name=\"enlaces[" + index + "][url]\" value=\"\" class=\"regular-text\"></td>" +
						"<td><button type=\"button\" class=\"button anpa-remove-link\">' . esc_js( __( 'Eliminar', 'anpa-socios' ) ) . '</button></td>";
					table.appendChild(row);
				});
				table.addEventListener("click", function(e) {
					if (e.target.classList.contains("anpa-remove-link")) {
						e.target.closest("tr").remove();
					}
				});
			}
		});
		</script>';
	}

	/**
	 * FASE37 M5: Render the comedor items sub-form (reuses M4 pattern).
	 *
	 * @param array  $items    Current comedor items.
	 * @param string $post_url Admin-post URL.
	 * @return void
	 */
	private static function render_comedor_items_form( array $items, string $post_url ): void {
		echo '<h3>' . esc_html__( 'Menús do Comedor', 'anpa-socios' ) . '</h3>';

		echo '<table class="widefat striped anpa-comedor-table" style="max-width:700px">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Lista de menús', 'anpa-socios' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Data', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Menú', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Alérxenos', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Accións', 'anpa-socios' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if ( empty( $items ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Non hai menús engadidos.', 'anpa-socios' ) . '</td></tr>';
		} else {
			foreach ( $items as $index => $item ) {
				echo '<tr>';
				printf( '<td><input type="text" name="items[%d][fecha]" value="%s" class="small-text"></td>', $index, esc_attr( $item['fecha'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][menu]" value="%s" class="regular-text"></td>', $index, esc_attr( $item['menu'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][alerxenos]" value="%s" class="regular-text"></td>', $index, esc_attr( $item['alerxenos'] ?? '' ) );
				printf( '<td><button type="button" class="button anpa-remove-item" data-index="%d">%s</button></td>', $index, esc_html__( 'Eliminar', 'anpa-socios' ) );
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		echo '<p><button type="button" class="button button-secondary anpa-add-menu">' . esc_html__( 'Engadir menú', 'anpa-socios' ) . '</button></p>';

		echo '<script>
		document.addEventListener("DOMContentLoaded", function() {
			var table = document.querySelector(".anpa-comedor-table tbody");
			var addBtn = document.querySelector(".anpa-add-menu");
			if (addBtn && table) {
				addBtn.addEventListener("click", function() {
					var index = table.querySelectorAll("tr").length;
					var cols = ["fecha","menu","alerxenos"];
					var row = document.createElement("tr");
					cols.forEach(function(col) {
						var td = document.createElement("td");
						var input = document.createElement("input");
						input.type = "text";
						input.name = "items[" + index + "][" + col + "]";
						input.className = (col === "menu" || col === "alerxenos") ? "regular-text" : "small-text";
						td.appendChild(input);
						row.appendChild(td);
					});
					var td = document.createElement("td");
					var btn = document.createElement("button");
					btn.type = "button";
					btn.className = "button anpa-remove-item";
					btn.textContent = "' . esc_js( __( 'Eliminar', 'anpa-socios' ) ) . '";
					td.appendChild(btn);
					row.appendChild(td);
					table.appendChild(row);
				});
				table.addEventListener("click", function(e) {
					if (e.target.classList.contains("anpa-remove-item")) {
						e.target.closest("tr").remove();
					}
				});
			}
		});
		</script>';
	}

	/**
	 * FASE37 M4: Render the books items sub-form.
	 *
	 * @param array  $items    Current books items.
	 * @param string $post_url Admin-post URL.
	 * @return void
	 */
	private static function render_libros_items_form( array $items, string $post_url ): void {
		echo '<h3>' . esc_html__( 'Libros', 'anpa-socios' ) . '</h3>';

		echo '<table class="widefat striped anpa-libros-table" style="max-width:900px">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Lista de libros', 'anpa-socios' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Curso', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Nivel', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Materia', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Título', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Editorial', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'ISBN', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Prezo', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Descarga', 'anpa-socios' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Accións', 'anpa-socios' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if ( empty( $items ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Non hai libros engadidos.', 'anpa-socios' ) . '</td></tr>';
		} else {
			foreach ( $items as $index => $item ) {
				echo '<tr>';
				printf( '<td><input type="text" name="items[%d][curso]" value="%s" class="small-text"></td>', $index, esc_attr( $item['curso'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][nivel]" value="%s" class="small-text"></td>', $index, esc_attr( $item['nivel'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][materia]" value="%s" class="small-text"></td>', $index, esc_attr( $item['materia'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][titulo]" value="%s" class="regular-text"></td>', $index, esc_attr( $item['titulo'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][editorial]" value="%s" class="small-text"></td>', $index, esc_attr( $item['editorial'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][isbn]" value="%s" class="small-text"></td>', $index, esc_attr( $item['isbn'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][prezo]" value="%s" class="small-text"></td>', $index, esc_attr( $item['prezo'] ?? '' ) );
				printf( '<td><input type="text" name="items[%d][descarga]" value="%s" class="regular-text"></td>', $index, esc_attr( $item['descarga'] ?? '' ) );
				printf( '<td><button type="button" class="button anpa-remove-item" data-index="%d">%s</button></td>', $index, esc_html__( 'Eliminar', 'anpa-socios' ) );
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		// Add book button
		echo '<p><button type="button" class="button button-secondary anpa-add-libro">' . esc_html__( 'Engadir libro', 'anpa-socios' ) . '</button></p>';

		// Minimal JS for add/remove
		echo '<script>
		document.addEventListener("DOMContentLoaded", function() {
			var table = document.querySelector(".anpa-libros-table tbody");
			var addBtn = document.querySelector(".anpa-add-libro");
			if (addBtn && table) {
				addBtn.addEventListener("click", function() {
					var index = table.querySelectorAll("tr").length;
					var cols = ["curso","nivel","materia","titulo","editorial","isbn","prezo","descarga"];
					var row = document.createElement("tr");
					cols.forEach(function(col) {
						var td = document.createElement("td");
						var input = document.createElement("input");
						input.type = "text";
						input.name = "items[" + index + "][" + col + "]";
						input.className = (col === "titulo" || col === "descarga") ? "regular-text" : "small-text";
						td.appendChild(input);
						row.appendChild(td);
					});
					var td = document.createElement("td");
					var btn = document.createElement("button");
					btn.type = "button";
					btn.className = "button anpa-remove-item";
					btn.textContent = "' . esc_js( __( 'Eliminar', 'anpa-socios' ) ) . '";
					td.appendChild(btn);
					row.appendChild(td);
					table.appendChild(row);
				});
				table.addEventListener("click", function(e) {
					if (e.target.classList.contains("anpa-remove-item")) {
						e.target.closest("tr").remove();
					}
				});
			}
		});
		</script>';
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
		echo '<p class="description">' . esc_html__( 'Comproba as datas dos cursos, pecha os que xa remataron e crea o curso seguinte como pendente e coas matrículas pechadas. Esta acción non activa automaticamente o curso seguinte.', 'anpa-socios' ) . '</p>';

		echo '<hr>';
		echo '<h3>' . esc_html__( 'Actualizar niveis dos fillos', 'anpa-socios' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Recalcula o nivel de cada fillo activo a partir da súa data de nacemento: asígnalle o nivel cuxa «Idade alumnado» (en Estrutura escolar) coincide coa idade que cumpre no ano final do curso activo. Quen pola idade xa superaría o último nivel mantense nel (6º) ata que a familia o dea de baixa. Aos fillos que cambian de nivel bórraselles a letra da aula: a familia terá que indicala na área antes de poder matricular. Non modifica cursos anteriores nin matrículas.', 'anpa-socios' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'Como funciona:', 'anpa-socios' ) . '</strong> ' . esc_html__( 'o botón calcula sempre primeiro sen gardar nada e amosa que fillos cambiarían de nivel e a que curso. Só despois, se estás de acordo, poderás aplicar exactamente eses cambios cun segundo botón. Se algún dato impide o cálculo con seguridade, a operación pararase sen cambios.', 'anpa-socios' ) . '</p>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_update_child_levels">';
		echo '<input type="hidden" name="tab" value="xeral">';
		echo '<input type="hidden" name="section" value="mantemento">';
		wp_nonce_field( 'anpa_socios_update_child_levels' );
		submit_button( __( 'Actualizar niveis dos fillos', 'anpa-socios' ), 'primary', 'submit', false );
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

		// Prerelease (beta) channel opt-in. Isolated form so it never clears
		// other options. Default OFF so production installs stay on stable.
		$use_pre = ANPA_Socios_Config::use_prereleases();
		echo '<hr>';
		echo '<h3>' . esc_html__( 'Canle de actualizacións', 'anpa-socios' ) . '</h3>';
		echo '<form method="post" action="' . $post_url . '">';
		echo '<input type="hidden" name="action" value="anpa_socios_save_settings">';
		echo '<input type="hidden" name="anpa_prerelease_form" value="1">';
		wp_nonce_field( 'anpa_socios_save_settings' );
		echo '<label><input type="checkbox" name="use_prereleases" value="1"' . checked( $use_pre, true, false ) . '> ' . esc_html__( 'Recibir versións de proba (prereleases)', 'anpa-socios' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Actívao só nun sitio de probas. Cando está activo, este sitio pode actualizar a versións beta (prerelease) antes de publicarse como estables. Un sitio en produción debe deixalo DESACTIVADO para non recibir cambios sen probar.', 'anpa-socios' ) . '</p>';
		submit_button( __( 'Gardar canle', 'anpa-socios' ), 'secondary', 'submit', false );
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
				border-radius: 6px; padding: .6em 1.4em; margin: .4em 0 1.2em; max-width: none;
				width: 100%; box-sizing: border-box; }
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
			if ( is_email( $email ) ) {
				update_option( 'anpa_socios_master_email', strtolower( $email ) );
			}
		}

		$msg = 'settings_saved';
		// 1.56.0: canteen account email — exclusive with socios and companies.
		if ( array_key_exists( 'comedor_email', $_POST ) ) {
			$comedor = strtolower( sanitize_email( (string) wp_unslash( $_POST['comedor_email'] ) ) );
			if ( '' === $comedor ) {
				delete_option( ANPA_Socios_Config::OPTION_COMEDOR_EMAIL );
			} elseif ( ! is_email( $comedor ) ) {
				$msg = 'comedor_email_invalid';
			} elseif ( null !== ANPA_Socios_Email_Ownership::socio_por_email( $comedor )
				|| ( null !== ANPA_Socios_Email_Ownership::empresa_por_email( $comedor ) && ! ANPA_Socios_Config::is_comedor_email( $comedor ) ) ) {
				$msg = 'comedor_email_conflict';
			} else {
				update_option( ANPA_Socios_Config::OPTION_COMEDOR_EMAIL, $comedor );
			}
		}

		// 1.58.0: Google account for the Gmail contacts list (optional, no exclusivity rule).
		if ( array_key_exists( 'google_contacts_email', $_POST ) ) {
			$google = strtolower( sanitize_email( (string) wp_unslash( $_POST['google_contacts_email'] ) ) );
			if ( '' === $google ) {
				delete_option( ANPA_Socios_Config::OPTION_GOOGLE_CONTACTS_EMAIL );
			} elseif ( ! is_email( $google ) ) {
				$msg = 'google_email_invalid';
			} else {
				update_option( ANPA_Socios_Config::OPTION_GOOGLE_CONTACTS_EMAIL, $google );
			}
		}

		// 1.63.0: links for the start-of-year email (optional; empty deletes the option).
		foreach ( array( 'instrucions_url' => ANPA_Socios_Config::OPTION_INSTRUCIONS_URL, 'extraescolares_url' => ANPA_Socios_Config::OPTION_EXTRAESCOLARES_URL ) as $field => $option ) {
			if ( ! array_key_exists( $field, $_POST ) ) {
				continue;
			}
			$url = esc_url_raw( trim( (string) wp_unslash( $_POST[ $field ] ) ) );
			if ( '' === $url ) {
				delete_option( $option );
			} elseif ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
				$msg = 'url_invalid';
			} else {
				update_option( $option, $url );
			}
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
		if ( array_key_exists( 'menu_name', $_POST ) ) {
			$menu_name = trim( wp_strip_all_tags( (string) wp_unslash( $_POST['menu_name'] ) ) );
			if ( function_exists( 'mb_substr' ) ) {
				$menu_name = mb_substr( $menu_name, 0, ANPA_Socios_Config::MENU_NAME_MAX_LENGTH );
			} else {
				$menu_name = substr( $menu_name, 0, ANPA_Socios_Config::MENU_NAME_MAX_LENGTH );
			}
			update_option( ANPA_Socios_Config::OPTION_MENU_NAME, trim( $menu_name ) );
		}
		if ( array_key_exists( 'email_signature', $_POST ) ) {
			update_option( ANPA_Socios_Config::OPTION_SIGNATURE, ANPA_Socios_Email::sanitize_signature( (string) wp_unslash( $_POST['email_signature'] ) ) );
		}
		if ( array_key_exists( 'require_approval', $_POST ) || array_key_exists( 'association_name', $_POST ) ) {
			update_option( ANPA_Socios_Config::OPTION_APPROVAL, ! empty( $_POST['require_approval'] ) ? '1' : '0' );
		}

		// Prerelease channel opt-in (isolated form marker so the checkbox is
		// only processed when its own form was submitted).
		if ( array_key_exists( 'anpa_prerelease_form', $_POST ) ) {
			update_option( ANPA_Socios_Config::OPTION_USE_PRERELEASES, ! empty( $_POST['use_prereleases'] ) ? '1' : '0' );
		}

		self::redirect_msg( $msg );
	}

	/**
	 * admin-post: save the "Cursos" tab — course season (curso escolar, estado,
	 * data_inicio, data_peche). Isolated so a
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
		$t1     = sanitize_text_field( (string) wp_unslash( $_POST['t1_peche_operativo'] ?? '' ) );
		$t2     = sanitize_text_field( (string) wp_unslash( $_POST['t2_peche_operativo'] ?? '' ) );
		$estado = sanitize_key( (string) wp_unslash( $_POST['estado'] ?? ANPA_Socios_Season::ESTADO_PENDENTE ) );
		// Mejora 1: replacing the active course on activation is NOT optional —
		// only one course can be active at a time, so always close the previous
		// active course (and its matrículas). The UI shows an informative note
		// instead of a checkbox. This flag only has effect when activating.
		$replace_active = true;

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
			$valid = array( ANPA_Socios_Season::ESTADO_PENDENTE, ANPA_Socios_Season::ESTADO_ACTIVO, ANPA_Socios_Season::ESTADO_PECHADO );
			if ( ! in_array( $estado, $valid, true ) ) {
				$estado = ANPA_Socios_Season::ESTADO_PENDENTE;
			}
			$inicio = self::valid_date( $inicio, ANPA_Socios_Season::default_data_inicio( $curso ) );
			$peche  = self::valid_date( $peche, ANPA_Socios_Season::default_data_peche( $curso ) );

			// Validate the operative calendar (dates optional; when present they
			// must be well-formed and strictly ordered inside the course range).
			// On error, do NOT persist anything — bounce back with a message.
			$cal_errors = ANPA_Socios_Calendario::validar( array(
				'inicio' => $inicio,
				't1'     => $t1,
				't2'     => $t2,
				'peche'  => $peche,
			) );
			if ( ! empty( $cal_errors ) ) {
				self::redirect_cursos( $curso, 'curso_datas_error' );
			}

			// Reuse the canonical transactional lifecycle writer used by REST.
			// The admin-post guard above already enforces manage_options + nonce;
			// this avoids a second, weaker implementation of the active-course lock.
			$request = new WP_REST_Request( 'PUT', '/anpa-socios/v1/admin/curso' );
			$request->set_body_params( array(
				'curso_escolar'      => $curso,
				'estado'             => $estado,
				'replace_active'      => $replace_active,
			) );
			$result = ANPA_Socios_Admin_Cursos_Handler::update_curso( $request );
			if ( is_wp_error( $result ) ) {
				self::redirect_cursos( $curso, 'curso_error' );
			}

			// Lifecycle preserves existing dates on update; save the edited dates
			// (including the operative trimester dates) only after the canonical
			// state transition succeeds.
			self::upsert_course( $curso, $inicio, $peche, $estado, $t1, $t2 );
		}

		self::redirect_cursos( $curso );
	}

	/**
	 * PRG redirect back to the Cursos tab keeping the selected course.
	 *
	 * @param  string $curso Selected course to keep in the URL.
	 * @return void
	 */
	private static function redirect_cursos( string $curso, string $message = 'settings_saved' ): void {
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : 'curso-escolar';
		if ( ! ANPA_Socios_Admin_Nav::is_settings_section( 'cursos', $section ) ) {
			$section = 'curso-escolar';
		}
		$args = array( 'page' => self::SETTINGS_SLUG, 'tab' => 'cursos', 'section' => $section, 'anpa_msg' => $message );
		if ( ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			$args['curso'] = $curso;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * admin-post: copy the operative calendar dates from the previous course,
	 * shifted +1 year, into the selected course (editable before final save).
	 *
	 * Only copies DATES (data_inicio, data_peche, t1/t2 operative closes). Never
	 * changes the course estado or matrículas. If the previous course has no
	 * stored row, bounces back with a notice and saves nothing.
	 *
	 * @return void
	 */
	public static function handle_copiar_datas_curso(): void {
		self::guard( 'anpa_socios_copiar_datas_curso' );

		$curso = sanitize_text_field( (string) wp_unslash( $_POST['curso_escolar'] ?? '' ) );
		if ( ! ANPA_Socios_Curso_Escolar::is_valid( $curso ) ) {
			self::redirect_cursos( $curso, 'curso_error' );
		}

		global $wpdb;
		$cursos = ANPA_Socios_DB::tabela_cursos();
		$prev   = ANPA_Socios_Curso_Escolar::previous( $curso );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only single-row lookup.
		$prow = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT data_inicio, data_peche, t1_peche_operativo, t2_peche_operativo, estado FROM {$cursos} WHERE curso_escolar = %s",
				$prev
			),
			ARRAY_A
		);
		if ( ! is_array( $prow ) ) {
			self::redirect_cursos( $curso, 'sen_curso_anterior' );
		}

		$inicio = self::shift_date_one_year( (string) ( $prow['data_inicio'] ?? '' ), ANPA_Socios_Season::default_data_inicio( $curso ) );
		$peche  = self::shift_date_one_year( (string) ( $prow['data_peche'] ?? '' ), ANPA_Socios_Season::default_data_peche( $curso ) );
		$t1     = self::shift_date_one_year( (string) ( $prow['t1_peche_operativo'] ?? '' ), '' );
		$t2     = self::shift_date_one_year( (string) ( $prow['t2_peche_operativo'] ?? '' ), '' );

		// Preserve the selected course's own estado (do not inherit the previous
		// course's). Fall back to pendente when the course has no row yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only single-row lookup.
		$estado = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT estado FROM {$cursos} WHERE curso_escolar = %s", $curso )
		);
		if ( ! in_array( $estado, array( ANPA_Socios_Season::ESTADO_PENDENTE, ANPA_Socios_Season::ESTADO_ACTIVO, ANPA_Socios_Season::ESTADO_PECHADO ), true ) ) {
			$estado = ANPA_Socios_Season::ESTADO_PENDENTE;
		}

		self::upsert_course( $curso, $inicio, $peche, $estado, $t1, $t2 );
		self::redirect_cursos( $curso, 'datas_copiadas' );
	}

	/**
	 * Shifts a Y-m-d date one year forward. Empty or malformed input returns
	 * the provided fallback (also possibly empty).
	 *
	 * @param  string $date     Y-m-d date or ''.
	 * @param  string $fallback Value to return when $date is empty/invalid.
	 * @return string
	 */
	private static function shift_date_one_year( string $date, string $fallback ): string {
		if ( '' === $date || '0000-00-00' === $date || ! ANPA_Socios_Calendario::valida_data( $date ) ) {
			return $fallback;
		}
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return false === $dt ? $fallback : $dt->modify( '+1 year' )->format( 'Y-m-d' );
	}

	/**
	 * admin-post: run the season check now.
	 *
	 * @return void
	 */
	public static function handle_run_season(): void {
		self::guard( 'anpa_socios_run_season' );
		$summary = ANPA_Socios_Season_Service::run_check();
		set_transient( self::season_result_key(), is_array( $summary ) ? $summary : array(), 5 * MINUTE_IN_SECONDS );
		self::redirect_msg( 'season_result' );
	}

	/**
	 * admin-post: step 1 of the level update. Always a simulation: the notice
	 * shows the plan and offers the «Aplicar» button bound to the plan fingerprint.
	 *
	 * @return void
	 */
	public static function handle_update_child_levels(): void {
		self::guard( 'anpa_socios_update_child_levels' );
		$result = ANPA_Socios_Nivel_Promotion_Service::run( true );
		set_transient(
			self::promotion_result_key(),
			is_wp_error( $result )
				? array( 'type' => 'error', 'message' => $result->get_error_message() )
				: array( 'type' => 'preview', 'result' => $result ),
			15 * MINUTE_IN_SECONDS
		);
		self::redirect_msg( 'child_levels_result' );
	}

	/**
	 * admin-post: step 2. Applies the plan only if it still matches the
	 * simulation the admin reviewed (fingerprint checked by the service).
	 *
	 * @return void
	 */
	public static function handle_apply_child_levels(): void {
		self::guard( 'anpa_socios_apply_child_levels' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$fingerprint = isset( $_POST['plan_fingerprint'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['plan_fingerprint'] ) ) : '';
		if ( 64 !== strlen( $fingerprint ) ) {
			set_transient( self::promotion_result_key(),
				array( 'type' => 'error', 'message' => __( 'Falta a simulación previa. Pulsa primeiro «Actualizar niveis dos fillos» para calcular os cambios.', 'anpa-socios' ) ),
				5 * MINUTE_IN_SECONDS
			);
			self::redirect_msg( 'child_levels_result' );
		}
		$result = ANPA_Socios_Nivel_Promotion_Service::run( false, $fingerprint );
		if ( is_wp_error( $result ) ) {
			set_transient( self::promotion_result_key(),
				array( 'type' => 'error', 'message' => $result->get_error_message() ),
				5 * MINUTE_IN_SECONDS
			);
			self::redirect_msg( 'child_levels_result' );
		}

		set_transient(
			self::promotion_result_key(),
			array( 'type' => 'success', 'result' => $result ),
			5 * MINUTE_IN_SECONDS
		);
		$user = wp_get_current_user();
		if ( $user instanceof WP_User && is_email( $user->user_email ) ) {
			ANPA_Socios_Admin_Shared::write_audit_actor(
				strtolower( $user->user_email ),
				'wordpress_admin',
				'niveis_fillos',
				(string) $result['curso_escolar'],
				'actualizar_niveis_fillos'
			);
		}
		self::redirect_msg( 'child_levels_result' );
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
		// Application-level size cap (defence in depth on top of PHP's
		// upload_max_filesize): a legit .anpabak for an ANPA is well under this.
		$max_bytes = (int) apply_filters( 'anpa_socios_restore_max_bytes', 50 * 1024 * 1024 );
		$size      = (int) ( $_FILES['backup_file']['size'] ?? 0 );
		if ( $size <= 0 || $size > $max_bytes ) {
			self::redirect_msg( 'restore_err' );
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
		$res = ANPA_Socios_Backup::wipe();
		if ( is_wp_error( $res ) ) {
			self::redirect_msg( 'wipe_err' );
		}
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
	private static function upsert_course( string $curso, string $inicio, string $peche, ?string $estado = null, string $t1 = '', string $t2 = '' ): void {
		global $wpdb;
		$cursos = ANPA_Socios_DB::tabela_cursos();
		if ( null === $estado ) {
			$estado = ANPA_Socios_Season::estado_for( date( 'Y-m-d' ), $inicio, $peche );
		}
		// Operative dates are DATE NULL — empty input persists as SQL NULL, not
		// '0000-00-00'. Build the value + placeholder pair per date.
		$t1_val = '' === $t1 ? null : $t1;
		$t2_val = '' === $t2 ? null : $t2;
		$t1_sql = null === $t1_val ? 'NULL' : '%s';
		$t2_sql = null === $t2_val ? 'NULL' : '%s';

		$args = array( $curso, $estado, $inicio, $peche );
		if ( null !== $t1_val ) { $args[] = $t1_val; }
		if ( null !== $t2_val ) { $args[] = $t2_val; }

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- idempotent course upsert; NULL literals are static.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$cursos} (curso_escolar, matriculas_abertas, estado, data_inicio, data_peche, t1_peche_operativo, t2_peche_operativo, creado_en, actualizado_en)
			 VALUES (%s, 0, %s, %s, %s, {$t1_sql}, {$t2_sql}, NOW(), NOW())
			 ON DUPLICATE KEY UPDATE estado = VALUES(estado), data_inicio = VALUES(data_inicio), data_peche = VALUES(data_peche), t1_peche_operativo = VALUES(t1_peche_operativo), t2_peche_operativo = VALUES(t2_peche_operativo), actualizado_en = NOW()",
			$args
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
		if ( 'child_levels_result' === $key ) {
			$notice = get_transient( self::promotion_result_key() );
			delete_transient( self::promotion_result_key() );
			if ( ! is_array( $notice ) ) {
				return;
			}
			if ( 'error' === ( $notice['type'] ?? '' ) ) {
				printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( (string) ( $notice['message'] ?? '' ) ) );
				return;
			}
			$result       = is_array( $notice['result'] ?? null ) ? $notice['result'] : array();
			$preview      = 'preview' === ( $notice['type'] ?? '' );
			$curso        = (string) ( $result['curso_escolar'] ?? '' );
			$actualizados = (int) ( $result['actualizados'] ?? 0 );
			$sen_cambios  = (int) ( $result['sen_cambios'] ?? 0 );
			$ultimo       = (int) ( $result['no_ultimo_nivel'] ?? 0 );
			$cambios      = is_array( $result['cambios'] ?? null ) ? $result['cambios'] : array();
			if ( $preview ) {
				printf(
					'<div class="notice notice-info"><p><strong>%s</strong> %s</p>',
					esc_html__( 'Simulación: aínda non se gardou ningún cambio.', 'anpa-socios' ),
					esc_html( sprintf( __( 'Curso %1$s: %2$d fillos cambiarían de nivel, %3$d xa están no nivel correcto e %4$d quedan no último nivel por idade.', 'anpa-socios' ), $curso, $actualizados, $sen_cambios, $ultimo ) )
				);
			} elseif ( 0 === $actualizados ) {
				printf(
					'<div class="notice notice-info"><p><strong>%s</strong> %s</p>',
					esc_html__( 'Non se modificou ningún rexistro.', 'anpa-socios' ),
					esc_html( sprintf( __( 'Curso %1$s: os %2$d fillos activos xa tiñan o nivel correcto (%3$d deles no último nivel por idade).', 'anpa-socios' ), $curso, $sen_cambios, $ultimo ) )
				);
			} else {
				printf(
					'<div class="notice notice-success"><p><strong>%s</strong> %s</p>',
					/* translators: %d: number of modified child records */
					esc_html( sprintf( _n( '%d rexistro modificado.', '%d rexistros modificados.', $actualizados, 'anpa-socios' ), $actualizados ) ),
					esc_html( sprintf( __( 'Curso %1$s: %2$d actualizados, %3$d xa correctos e %4$d no último nivel por idade.', 'anpa-socios' ), $curso, $actualizados, $sen_cambios, $ultimo ) )
				);
			}
			$por_curso = is_array( $result['por_curso'] ?? null ) ? $result['por_curso'] : array();
			if ( array() !== $por_curso ) {
				$parts = array();
				foreach ( $por_curso as $c => $n ) {
					$parts[] = sprintf( '%s: %d', '' === (string) $c ? __( '(sen nivel)', 'anpa-socios' ) : (string) $c, (int) $n );
				}
				echo '<p class="description">' . esc_html__( 'Distribución resultante por curso:', 'anpa-socios' ) . ' ' . esc_html( implode( ' · ', $parts ) ) . '</p>';
			}
			if ( array() !== $cambios ) {
				echo '<details' . ( $preview ? ' open' : '' ) . '><summary>' . esc_html( sprintf( _n( 'Ver o cambio', 'Ver os %d cambios', count( $cambios ), 'anpa-socios' ), count( $cambios ) ) ) . '</summary>';
				echo '<table class="widefat striped" style="max-width:720px;margin-top:8px"><thead><tr><th>' . esc_html__( 'Fillo (ID)', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Idade', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Nivel actual', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Nivel novo', 'anpa-socios' ) . '</th><th>' . esc_html__( 'Aula', 'anpa-socios' ) . '</th></tr></thead><tbody>';
				foreach ( array_slice( $cambios, 0, 500 ) as $ch ) {
					$nota = 'capped' === ( $ch['accion'] ?? '' ) ? ' (' . esc_html__( 'último nivel por idade', 'anpa-socios' ) . ')' : '';
					printf(
						'<tr><td>%d</td><td>%d</td><td>%s</td><td>%s%s</td><td>%s</td></tr>',
						(int) $ch['fillo_id'],
						(int) $ch['idade'],
						esc_html( '' === (string) $ch['curso_anterior'] ? '—' : (string) $ch['curso_anterior'] ),
						esc_html( (string) $ch['curso_novo'] ),
						$nota,
						esc_html( '' === (string) $ch['aula'] ? __( '— (a familia indicará a letra)', 'anpa-socios' ) : (string) $ch['aula'] )
					);
				}
				echo '</tbody></table></details>';
			}
			if ( $preview ) {
				$fingerprint = preg_replace( '/[^a-f0-9]/', '', (string) ( $result['fingerprint'] ?? '' ) );
				if ( 0 === $actualizados ) {
					echo '<p>' . esc_html__( 'Non hai nada que aplicar: todos os fillos xa están no nivel que lles corresponde.', 'anpa-socios' ) . '</p>';
				} elseif ( 64 === strlen( $fingerprint ) ) {
					/* translators: %d: number of children whose level would change */
					$confirm = sprintf( __( 'Aplicar agora os %d cambios amosados? Os niveis actualízanse nunha soa operación; se os datos cambiaron desde a simulación, pararase sen escribir nada.', 'anpa-socios' ), $actualizados );
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0" onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');">';
					echo '<input type="hidden" name="action" value="anpa_socios_apply_child_levels">';
					echo '<input type="hidden" name="tab" value="xeral">';
					echo '<input type="hidden" name="section" value="mantemento">';
					echo '<input type="hidden" name="plan_fingerprint" value="' . esc_attr( $fingerprint ) . '">';
					wp_nonce_field( 'anpa_socios_apply_child_levels' );
					/* translators: %d: number of children whose level would change */
					submit_button( sprintf( _n( 'Aplicar este cambio', 'Aplicar estes %d cambios', $actualizados, 'anpa-socios' ), $actualizados ), 'primary', 'submit', false );
					echo ' <span class="description">' . esc_html__( 'Se prefires non aplicalos, non pulses nada: non se gardou ningún cambio.', 'anpa-socios' ) . '</span>';
					echo '</form>';
				}
			}
			$emails = is_array( $result['emails_cco'] ?? null ) ? array_filter( array_map( 'sanitize_email', $result['emails_cco'] ) ) : array();
			if ( array() !== $emails ) {
				echo '<p><strong>' . esc_html__( 'Emails dos proxenitores principais dos alumnos que pola idade xa superarían o último nivel (mantidos en 6º):', 'anpa-socios' ) . '</strong></p>';
				echo '<p class="description">' . esc_html__( 'Copia esta lista no campo CCO do correo para comprobar coa familia se o alumno segue no centro ou se desexan solicitar a baixa como socios.', 'anpa-socios' ) . '</p>';
				printf( '<textarea class="large-text code" rows="3" readonly onclick="this.select();">%s</textarea>', esc_textarea( implode( ', ', $emails ) ) );
			}
			echo '</div>';
			return;
		}
		if ( 'season_result' === $key ) {
			$summary = get_transient( self::season_result_key() );
			delete_transient( self::season_result_key() );
			$summary   = is_array( $summary ) ? $summary : array();
			$closed    = is_array( $summary['closed'] ?? null ) ? $summary['closed'] : array();
			$created   = is_array( $summary['created'] ?? null ) ? $summary['created'] : array();
			$activated = is_array( $summary['activated'] ?? null ) ? $summary['activated'] : array();
			$total     = count( $closed ) + count( $created ) + count( $activated );
			if ( 0 === $total ) {
				printf(
					'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'Comprobación de temporada executada.', 'anpa-socios' ),
					esc_html__( 'Non se modificou nada: os cursos xa estaban ao día.', 'anpa-socios' )
				);
				return;
			}
			$parts = array();
			if ( array() !== $closed ) {
				/* translators: 1: number of closed courses, 2: comma-separated course list */
				$parts[] = sprintf( __( 'pechados: %1$d (%2$s)', 'anpa-socios' ), count( $closed ), implode( ', ', array_map( 'sanitize_text_field', $closed ) ) );
			}
			if ( array() !== $created ) {
				/* translators: 1: number of created courses, 2: comma-separated course list */
				$parts[] = sprintf( __( 'creados: %1$d (%2$s)', 'anpa-socios' ), count( $created ), implode( ', ', array_map( 'sanitize_text_field', $created ) ) );
			}
			if ( array() !== $activated ) {
				/* translators: 1: number of activated courses, 2: comma-separated course list */
				$parts[] = sprintf( __( 'activados: %1$d (%2$s)', 'anpa-socios' ), count( $activated ), implode( ', ', array_map( 'sanitize_text_field', $activated ) ) );
			}
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Comprobación de temporada executada.', 'anpa-socios' ),
				esc_html( sprintf( __( 'Cambios: %s.', 'anpa-socios' ), implode( '; ', $parts ) ) )
			);
			return;
		}
		$map = array(
			'settings_saved' => array( 'success', __( 'Configuración gardada.', 'anpa-socios' ) ),
			'comedor_email_invalid'  => array( 'error', __( 'O correo do comedor non é válido; o resto da configuración gardouse.', 'anpa-socios' ) ),
			'comedor_email_conflict' => array( 'error', __( 'O correo do comedor xa pertence a un socio/a ou a unha empresa e non se gardou; o resto da configuración gardouse. Un mesmo correo só pode ter un rol.', 'anpa-socios' ) ),
			'google_email_invalid'   => array( 'error', __( 'A conta de Google da xunta non é un correo válido; o resto da configuración gardouse.', 'anpa-socios' ) ),
			'url_invalid'            => array( 'error', __( 'Unha das URL (entrada de instrucións ou páxina de extraescolares) non é válida: ten que empezar por http:// ou https://. O resto da configuración gardouse.', 'anpa-socios' ) ),
			'pw_ok'          => array( 'success', __( 'Contrasinal de admin actualizado.', 'anpa-socios' ) ),
			'pw_bad'         => array( 'error', __( 'O contrasinal non cumpre os requisitos (mín. 8 caracteres, unha maiúscula e un símbolo).', 'anpa-socios' ) ),
			'updates_checked' => array( 'success', __( 'Comprobación de actualizacións executada. Se hai unha versión nova, aparecerá en Plugins.', 'anpa-socios' ) ),
			'curso_error'     => array( 'error', __( 'Non se puido gardar o ciclo do curso. Se estás activando outro curso, confirma primeiro a substitución do curso activo.', 'anpa-socios' ) ),
			'curso_datas_error' => array( 'error', __( 'As datas do calendario non son válidas. Comproba que van en orde (comeza < peche T1 < peche T2 < pecha) e dentro do curso. Non se gardou nada.', 'anpa-socios' ) ),
			'datas_copiadas'  => array( 'success', __( 'Datas copiadas do curso anterior (+1 ano). Revísaas e garda o curso para confirmar.', 'anpa-socios' ) ),
			'sen_curso_anterior' => array( 'error', __( 'O curso anterior non ten datas gardadas para copiar.', 'anpa-socios' ) ),
			'transicion_ok'   => array( 'success', __( 'Transición aplicada e rexistrada.', 'anpa-socios' ) ),
			'transicion_noop' => array( 'info', __( 'Sen cambios: o estado xa era o solicitado.', 'anpa-socios' ) ),
			'transicion_err'  => array( 'error', __( 'Non se puido aplicar a transición (non permitida ou erro interno).', 'anpa-socios' ) ),
			'transicion_sen_config' => array( 'error', __( 'Non se puido aplicar: o trimestre non está inicializado neste curso. Inicialízao primeiro.', 'anpa-socios' ) ),
			'trimestres_inicializados' => array( 'success', __( 'Trimestres inicializados para o curso (T1 activo, T2/T3 pendentes, ventás pechadas).', 'anpa-socios' ) ),
			'bak_bad_pw'     => array( 'error', __( 'Contrasinal de admin incorrecto.', 'anpa-socios' ) ),
			'bak_err'        => array( 'error', __( 'Non se puido xerar a copia (revisa a frase da clave bancaria).', 'anpa-socios' ) ),
			'restored'       => array( 'success', __( 'Copia recuperada correctamente.', 'anpa-socios' ) ),
			'restore_nofile' => array( 'error', __( 'Non se recibiu ningún ficheiro de copia.', 'anpa-socios' ) ),
			'restore_err'    => array( 'error', __( 'Non se puido recuperar a copia (ficheiro ou contrasinal incorrectos).', 'anpa-socios' ) ),
			'wiped'          => array( 'success', __( 'Base de datos borrada. Configura de novo o plugin.', 'anpa-socios' ) ),
			'wipe_noconfirm' => array( 'error', __( 'Debes confirmar a casa de verificación para borrar a base de datos.', 'anpa-socios' ) ),
			'wipe_err'       => array( 'error', __( 'Non se puido completar o borrado. Revisa a base de datos antes de continuar.', 'anpa-socios' ) ),
			'comms_delete_on'  => array( 'success', __( 'Gardado: o rexistro de comunicacións borrarase se se desinstala o plugin.', 'anpa-socios' ) ),
			'comms_delete_off' => array( 'success', __( 'Gardado: o rexistro de comunicacións consérvase ao desinstalar o plugin.', 'anpa-socios' ) ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $key ][0] ), esc_html( $map[ $key ][1] ) );
	}

	/** Per-admin transient key for the one-time promotion result. */
	private static function promotion_result_key(): string {
		return 'anpa_child_levels_result_' . get_current_user_id();
	}

	/** Per-admin transient key for the one-time season-check result. */
	private static function season_result_key(): string {
		return 'anpa_season_result_' . get_current_user_id();
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
		$li       = static function ( string $text ): void { echo '<li>' . esc_html( $text ) . '</li>'; };
		$li_html  = static function ( string $html ): void { echo '<li>' . wp_kses_post( $html ) . '</li>'; };
		$p        = static function ( string $text ): void { echo '<p>' . esc_html( $text ) . '</p>'; };
		$h3       = static function ( string $text ): void { echo '<h3>' . esc_html( $text ) . '</h3>'; };

		echo '<div class="wrap anpa-docs">';
		echo self::docs_styles();
		echo '<h1>' . esc_html__( 'Documentación', 'anpa-socios' ) . '</h1>';
		$p( __( 'Guía para a xunta directiva: posta en marcha, ciclo anual, socios, extraescolares, comunicacións, copias e privacidade. Ao final hai un manual para as familias e unha lista de comprobación para quen se incorpore á administración.', 'anpa-socios' ) );

		echo '<nav class="card anpa-docs-index" aria-label="' . esc_attr__( 'Índice de documentación', 'anpa-socios' ) . '">';
		echo '<h2>' . esc_html__( 'Índice', 'anpa-socios' ) . '</h2><ol>';
		foreach ( $sections as $slug => $label ) {
			printf( '<li><a href="#%s">%s</a></li>', esc_attr( $slug ), esc_html( $label ) );
		}
		echo '</ol></nav>';

		echo '<div class="anpa-docs-content">';

		// 1. Posta en marcha.
		echo '<section id="posta-en-marcha" class="card"><h2>' . esc_html( $sections['posta-en-marcha'] ) . '</h2><ol>';
		$li( __( 'Axustes → Xeral: nome do menú, correo do equipo administrador (recibe os avisos de altas, baixas e reactivacións), aprobación de altas, e páxinas públicas creadas automaticamente (área de socios, asociarse, extraescolares).', 'anpa-socios' ) );
		$li( __( 'Axustes → Localización e idioma: país, provincia, poboación e código postal por defecto que se prefillan nos formularios das familias.', 'anpa-socios' ) );
		$li( __( 'Clave bancaria: xérase unha soa vez desde Axustes. A frase gárdase fóra da web, accesible só á xunta; sen ela non se poden ler nin exportar os IBAN. Non a envíes por correo nin a pegues en chats.', 'anpa-socios' ) );
		$li( __( 'Axustes → Cursos → Curso escolar: crea o curso (formato AAAA/AAAA+1) e pon as datas de inicio e peche e os peches operativos do 1º e 2º trimestre. O trimestre activo, a apertura das matrículas e os avisos ás familias lévanse dende Xestión → Extraescolares → Matrículas (ver «Ciclo anual»).', 'anpa-socios' ) );
		$li( __( 'Axustes → Cursos → Estrutura escolar: niveis (1º a 6º, con «Idade alumnado» 7 a 12) e aulas (A, B…). Ao crear un curso novo pódese copiar a estrutura do anterior.', 'anpa-socios' ) );
		$li( __( 'Plantillas de Email: revisa os dez textos automáticos (por defecto en galego). «Restaurar» devolve o texto orixinal dunha plantilla.', 'anpa-socios' ) );
		$li( __( 'Axustes → Contido: textos, documentos e ligazóns de transporte, libros, bos días, comedor e tardes divertidas. Amósanse nas páxinas públicas co shortcode [anpa_contenido categoria="comedor"] (unha categoría por shortcode).', 'anpa-socios' ) );
		$li( __( 'Proba final: entra na área de socios cun correo teu, comproba que chega o código de seis díxitos e que a acción queda rexistrada en Xestión → Auditoría.', 'anpa-socios' ) );
		echo '</ol>';
		echo '<p>' . esc_html__( 'A área de socios amósase na páxina configurada en Axustes → Xeral co shortcode canónico:', 'anpa-socios' ) . ' <code>[anpa_socios_area]</code></p>';
		$h3( __( 'Shortcodes dispoñibles', 'anpa-socios' ) );
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><td><code>[anpa_socios_area]</code></td><td>' . esc_html__( 'Área de socios: identificación por código, datos, fillos/as, extraescolares e conta bancaria.', 'anpa-socios' ) . '</td></tr>';
		echo '<tr><td><code>[anpa_extraescolares_ofertadas]</code></td><td>' . esc_html__( 'Tarxetas coas actividades activas do curso actual que teñen grupos abertos.', 'anpa-socios' ) . '</td></tr>';
		echo '<tr><td><code>[anpa_extraescolares_horario]</code></td><td>' . esc_html__( 'Grella semanal de luns a venres cos grupos abertos.', 'anpa-socios' ) . '</td></tr>';
		echo '</tbody></table></section>';

		// 2. Ciclo anual.
		echo '<section id="ciclo-curso" class="card"><h2>' . esc_html( $sections['ciclo-curso'] ) . '</h2><ul>';
		$li_html( __( 'Cada curso vai do 1 de xullo ao 30 de xuño e usa o formato <code>AAAA/AAAA+1</code>. Só pode haber un curso activo.', 'anpa-socios' ) );
		$li( __( 'O 20 de xuño o sistema pecha o curso activo e crea o seguinte como pendente. Nunca activa un curso por si só: faino a xunta con «Notificar comezo do curso» en Xestión → Extraescolares → Matrículas.', 'anpa-socios' ) );
		$li_html( __( '<strong>Regra única de matrículas:</strong> as familias poden matricular, dar de baixa ou solicitar praza só se o curso está activo <strong>e</strong> a ventá do trimestre actual está aberta (Xestión → Extraescolares → Matrículas, combo «Matrículas abertas para»; como moito unha ventá aberta). O trimestre actual derívase das datas operativas. O «estado lectivo» de cada trimestre é informativo; a ventá é o interruptor. Co prazo pechado e o curso activo, a solicitude da familia non se rexeita: queda «pendente de aprobación» e a xunta decide en Socios → Aprobacións, ou aproba todas dunha vez ao activar o seguinte trimestre.', 'anpa-socios' ) );
		$li( __( 'Avisos ás familias dende o mesmo panel: «Notificar comezo do curso» (activa o curso, abre o 1º trimestre e envía «inicio_curso»), «Notificar prazo de matrículas» (data de peche e comezo das actividades), apertura e peche das matrículas con aviso opcional, e «Notificar fin de curso» (pecha trimestres, matrículas, grupos e curso). Todos os correos masivos van coa conta da xunta como destinatario visible e as familias en CCO, en lotes de 50, e quedan anotados en Operacións → Auditoría.', 'anpa-socios' ) );
		$li( __( 'En pretempada (curso pendente) só o equipo administrador pode iniciar sesión na área; as familias quedan protexidas ata a apertura.', 'anpa-socios' ) );
		echo '</ul>';
		$h3( __( 'Checklist de setembro', 'anpa-socios' ) );
		echo '<ol>';
		$li( __( 'Copia de seguridade con UpdraftPlus antes de empezar.', 'anpa-socios' ) );
		$li( __( 'Axustes → Cursos: comproba as datas do curso novo. Despois, en Xestión → Extraescolares → Matrículas, «Notificar comezo do curso» activa o curso, abre as matrículas do 1º trimestre e avisa ás familias por correo.', 'anpa-socios' ) );
		$li( __( 'Estrutura escolar: copia niveis e aulas do curso anterior e revisa a «Idade alumnado» de cada nivel.', 'anpa-socios' ) );
		$li( __( 'Axustes → Mantemento → «Actualizar niveis dos fillos»: o botón simula primeiro; revisa a lista e pulsa «Aplicar». Copia a lista CCO das familias de 6º que rematan e pregúntalles se seguen no centro ou queren a baixa. Os fillos que cambian de nivel quedan sen letra de aula: a área pídelle á familia que a indique antes de matricular.', 'anpa-socios' ) );
		$li( __( 'Extraescolares: revisa empresas, actividades e grupos do curso novo (niveis, horarios, prazas, comedor) e pon en «aberto» os que se ofertan.', 'anpa-socios' ) );
		$li( __( 'Comproba na páxina pública que aparecen a oferta e o horario. Uns días antes do peche usa «Notificar prazo de matrículas»; ao pechar o prazo, avisa ás familias inscritas de cada grupo con «Notificar comezo do trimestre» en Grupos e horarios, e pecha con «Pechar por non acadar o mínimo» os grupos que non saian.', 'anpa-socios' ) );
		$li( __( 'Plantillas de Email: le os textos de benvida e oferta de praza e fai un envío de proba.', 'anpa-socios' ) );
		$li( __( 'Anuncia ás familias (blog e WhatsApp) que revisen curso, aula e data de nacemento dos fillos/as antes de matricular.', 'anpa-socios' ) );
		echo '</ol>';
		$h3( __( 'Fin de curso', 'anpa-socios' ) );
		echo '<ol>';
		$li( __( 'En Xestión → Extraescolares → Matrículas, «Notificar fin de curso» pecha os trimestres, as matrículas, os grupos e o curso, e agradece ás familias a participación.', 'anpa-socios' ) );
		$li( __( 'Confirma as baixas pendentes de socios/as (Xestión → Socios → Baixas de socios) e de actividades (Xestión → Extraescolares → Matrículas) e exporta os listados que necesite a tesourería.', 'anpa-socios' ) );
		$li( __( 'Fai unha copia completa (UpdraftPlus e o ficheiro cifrado .anpabak) antes do 20 de xuño.', 'anpa-socios' ) );
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'Nota: «aula» (clase ordinaria, por exemplo 4ºB) non é o mesmo que «grupo de actividade» (agrupamento por niveis dunha extraescolar, por exemplo 1º-2º-3º). A estrutura escolar define aulas; os grupos configúranse na ficha de cada actividade.', 'anpa-socios' ) . '</p>';
		echo '</section>';

		// 3. Socios.
		echo '<section id="socios" class="card"><h2>' . esc_html( $sections['socios'] ) . '</h2>';
		$h3( __( 'Alta e aprobación', 'anpa-socios' ) );
		echo '<ul>';
		$li( __( 'A familia dáse de alta na páxina «Asociarse»: código por correo, datos do titular (e opcionalmente do segundo proxenitor/a), fillos/as con curso e aula, e conta bancaria (gárdase cifrada).', 'anpa-socios' ) );
		$li( __( 'Con «Aprobación de altas» activada en Axustes → Xeral a alta queda pendente e a xunta acéptaa ou rexéitaa en Xestión → Aprobacións; a familia recibe un correo en cada caso. Coa opción desactivada, a alta é activa ao momento e chega a benvida.', 'anpa-socios' ) );
		$li( __( 'En ambos os casos a alta queda na Auditoría (accións alta_activa ou alta_pendente, e alta_segundo_proxenitor). Así detéctanse altas non desexadas aínda coa aprobación desmarcada.', 'anpa-socios' ) );
		$li( __( 'O correo identifica a cada persoa: é único e é a chave de entrada. Un segundo proxenitor/a necesita un correo distinto do titular; sen correo non se pode dar de alta.', 'anpa-socios' ) );
		echo '</ul>';
		$h3( __( 'Baixas e reactivacións', 'anpa-socios' ) );
		echo '<ul>';
		$li( __( 'A familia solicita a baixa desde a área (queda «baixa solicitada» e pode anulala). A xunta confírmaa ou rexéitaa en Xestión → Socios → Baixas de socios (as baixas de actividades pedidas polas familias están en Xestión → Extraescolares → Matrículas); é efectiva a fin de curso e a cota do curso completo mantense.', 'anpa-socios' ) );
		$li( __( 'Unha familia dada de baixa pode pedir a reactivación desde a páxina pública; a xunta a aproba como unha alta pendente.', 'anpa-socios' ) );
		$li( __( 'A conta do equipo administrador non pode solicitar a baixa: perdería o acceso.', 'anpa-socios' ) );
		echo '</ul>';
		$h3( __( 'Lista de correo en Gmail («Socios Web ANPA»)', 'anpa-socios' ) );
		echo '<ul>';
		$li( __( 'Xestión → Socios → Lista Gmail exporta os socios/as activos nun CSV co formato de Google Contactos (etiqueta «Socios Web ANPA» incluída) e lembra a última exportación: amosa cantas altas e baixas houbo desde entón e se fai falta importar de novo.', 'anpa-socios' ) );
		$li( __( 'Pasos: confirmar as baixas pendentes en Baixas de socios (se non se confirman, eses correos seguen na lista) → Descargar CSV → Abrir Google Contactos coa conta da xunta → eliminar a etiqueta «Socios Web ANPA» cos seus contactos → Importar o CSV. A web non escribe en Google: é un proceso manual de tres pulsacións.', 'anpa-socios' ) );
		$li( __( 'Se desde a última exportación só houbo altas, «Descargar só as altas novas» dá un CSV cos correos novos para importalos SEN eliminar a etiqueta (súmanse á que xa existe); as baixas seguen en Google ata facer o proceso completo.', 'anpa-socios' ) );
		$li( __( 'Gmail limita os envíos a 500 destinatarios ao día (2.000 nunha conta de Google Workspace): usa a etiqueta en CCO. É o único camiño para avisos a toda a asociación; a web non fai envíos masivos.', 'anpa-socios' ) );
		$li( __( 'Correo de inicio de curso: na mesma pantalla, «Enviar o correo de inicio de curso á conta da xunta» manda UNHA soa vez a plantilla «inicio_curso» (como entrar como socio/a, darse de alta, modificar datos e inscribirse nas extraescolares) á conta da xunta; dende Gmail reenvíase á etiqueta «Socios Web ANPA» en CCO. O texto edítase en Axustes → Plantillas de email.', 'anpa-socios' ) );
		echo '</ul>';
		$h3( __( 'Correos automáticos ás familias nas baixas', 'anpa-socios' ) );
		echo '<ul>';
		$li( __( 'Ao solicitar a baixa desde a área (como socio/a ou dunha actividade), a familia recibe un acuse de recibo («baixa_socio_solicitada», «baixa_extraescolar_solicitada»): a baixa non é automática, confírmaa unha persoa da directiva e pode tardar uns días, e avisarase por correo ao confirmarse.', 'anpa-socios' ) );
		$li( __( 'Ao confirmar ou rexeitar unha solicitude en Xestión → Socios → Baixas de socios ou en Xestión → Extraescolares → Matrículas (baixas de actividades), a familia recibe un correo coa plantilla correspondente: «baixa_socio_confirmada», «baixa_socio_rexeitada», «baixa_extraescolar_confirmada» ou «baixa_extraescolar_rexeitada» (Axustes → Plantillas de email, coa sinatura de Axustes → Xeral).', 'anpa-socios' ) );
		$li( __( 'A baixa de socio/a confirmada aplícase a toda a unidade familiar (proxenitor/a principal e secundario/a): todos pasan a «baixa», perden o acceso á área e cada un recibe o correo coa lista dos enderezos dados de baixa. Se a familia ten dous correos, saen dous correos.', 'anpa-socios' ) );
		$li( __( 'Baixa dunha actividade: mentres a ventá de inscrición do trimestre en curso estea ABERTA (Xestión → Extraescolares → Matrículas), o correo di que a baixa é efectiva desde ese momento e sen ningún cobro, porque as clases aínda non están confirmadas; cando a ventá xa está PECHADA (listado enviado ás empresas, clases en marcha), di que é efectiva ao remate do trimestre, coa data de peche operativo, e que a cota se mantén ata entón. Vale para os tres trimestres.', 'anpa-socios' ) );
		$li( __( 'Os correos de rexeitamento indican á familia que, se cre que houbo un erro, escriba á directiva ao correo de contacto de Axustes → Xeral.', 'anpa-socios' ) );
		echo '</ul>';
		$h3( __( 'Auditoría', 'anpa-socios' ) );
		$p( __( 'Xestión → Auditoría rexistra o que fai cada socio/a (altas, baixas, fillos/as engadidos ou eliminados, cambios de IBAN sen os datos, matrículas, ofertas aceptadas) e o que fai a xunta (aprobacións, importacións, cambios de estrutura). Filtra polo correo para ver a historia dunha familia.', 'anpa-socios' ) );
		$h3( __( 'Importación CSV', 'anpa-socios' ) );
		echo '<ul>';
		$li( __( 'Xestión → Importar listados admite socios, fillos, socios_iban, actividades, grupos e matrículas, coas cabeceiras exactas que amosa a pantalla.', 'anpa-socios' ) );
		$li( __( 'Executa sempre primeiro a simulación (non escribe nada) e le os erros por fila. Os niveis van como 1º…6º e o correo de cada socio/a ten que ser único.', 'anpa-socios' ) );
		$li( __( 'Os IBAN impórtanse cifrados: hai que ter a clave bancaria configurada e a frase a man. Despois, comproba unha familia na área para ver que os datos bancarios están completos.', 'anpa-socios' ) );
		echo '</ul></section>';

		// 4. Extraescolares.
		echo '<section id="extraescolares" class="card"><h2>' . esc_html( $sections['extraescolares'] ) . '</h2>';
		$p( __( 'Cadea de configuración: Empresas → Actividades → Grupos (por curso escolar) → Matrículas. A páxina pública e o horario amosan só actividades activas con grupos abertos do curso activo.', 'anpa-socios' ) );
		$h3( __( 'Estados dos grupos (cores en Xestión)', 'anpa-socios' ) );
		echo '<ul>';
		$li( __( 'Aberto (verde): visible na páxina pública e no horario, ofertado na área; matrícula activa mentres haxa praza.', 'anpa-socios' ) );
		$li( __( 'Pechado (vermello): oculto da oferta pública e da área; as matrículas existentes seguen e unha nova iría a lista de espera.', 'anpa-socios' ) );
		$li( __( 'Deshabilitado (amarelo): oculto e sen matrículas; só se pode escoller cando o grupo non ten ningunha matrícula vixente. Consérvase para o histórico.', 'anpa-socios' ) );
		$li( __( '«Eliminar» só aparece nos grupos do curso activo sen ningunha matrícula nin histórico. Se un grupo xa non se usa pero ten historial, deshabilítao.', 'anpa-socios' ) );
		$li( __( 'Os horarios de comedor por nivel (Estrutura escolar) impiden abrir grupos que se solapen co comedor do seu nivel.', 'anpa-socios' ) );
		echo '</ul>';
		$h3( __( 'Matrículas, lista de espera e ofertas', 'anpa-socios' ) );
		echo '<ul>';
		$li( __( 'Só matriculan socios/as activos con datos bancarios completos e co curso activo. Coa ventá do trimestre aberta a matrícula é inmediata (praza ou lista de espera); coa ventá pechada queda pendente de aprobación pola directiva (Socios → Aprobacións). A área amosa só os grupos do nivel de cada fillo/a: se o curso do fillo/a está mal, non verán as actividades correctas.', 'anpa-socios' ) );
		$li( __( 'Grupos e horarios amosa a ocupación de cada grupo (inscritos, lista de espera, pendentes e mínimo) e, coas matrículas pechadas, botóns por grupo aberto (coas matrículas abertas non aparecen): «Notificar grupo creado (comezo do trimestre)» (correo ás familias inscritas e á empresa, e outro ás de lista de espera; a empresa pode formar o grupo aínda sen o mínimo; ao enviarse, o grupo queda en verde co trimestre da notificación e o botón non volve aparecer ata que se abran e pechen de novo as matrículas), e «Pechar por non acadar o mínimo», só cando non se acada (o grupo queda deshabilitado e desaparece da oferta e da área; as súas matrículas pasan a baixa e avísase por correo). Os grupos pechados ou deshabilitados vense sombreados na grella, só con «Editar». A marca de «notificado» de cada grupo pódese poñer ou quitar a man dende a ficha da actividade (columna «Aviso», sen enviar correo). O botón «Resumo» de cada actividade despliega debaixo o resumo do curso por grupo e trimestre (activos, en espera, baixas, prazas libres, pendentes e aviso).', 'anpa-socios' ) );
		$li( __( 'Con praza a matrícula queda activa; sen praza (ou grupo pechado) vai a lista de espera por orde de solicitude. Cando queda unha praza, o sistema ofrécella á primeira persoa por correo e na área; ten tres días para aceptala, se non pasa á seguinte.', 'anpa-socios' ) );
		$li( __( 'A baixa dunha matrícula sólicitaa a familia e confírmaa a xunta en Xestión → Matrículas. Desde a ficha do grupo pódese mover un alumno/a a outro grupo aberto da mesma actividade.', 'anpa-socios' ) );
		$li( __( 'O trimestre dunha matrícula derívase da data en que se fai, segundo as datas operativas do curso.', 'anpa-socios' ) );
		echo '</ul></section>';

		// 5. Comunicacións.
		echo '<section id="comunicacions" class="card"><h2>' . esc_html( $sections['comunicacions'] ) . '</h2><ul>';
		$li( __( 'Plantillas de Email: dez correos automáticos (código de verificación, benvida, alta pendente, aprobación, rexeitamento, baixa, oferta de praza, aviso á xunta…). Admiten variables, teñen vista previa e envíanse ao momento. «Restaurar» volve ao texto por defecto en galego.', 'anpa-socios' ) );
		$li( __( 'Envíos masivos ás familias: NON se fan desde a web. A cola de campañas da fase 35 («Rexistro de envíos») retirouse na 1.64.0 porque nunca chegou a ter unha pantalla para lanzar campañas e quedaba sempre baleira. O camiño é Xestión → Socios → Lista Gmail: exportar os socios/as á etiqueta de Google Contactos e enviar dende a conta da xunta (en CCO), onde ademais chegan as respostas das familias.', 'anpa-socios' ) );
		$li( __( 'O envío real dos correos automáticos depende de WP Mail SMTP: se algún non chega, revisa alí a autorización da conta.', 'anpa-socios' ) );
		echo '</ul></section>';

		// 6. Exportacións e copias.
		echo '<section id="exportacions-copias" class="card"><h2>' . esc_html( $sections['exportacions-copias'] ) . '</h2><ul>';
		$li( __( 'Cada táboa de Xestión ANPA ten a súa exportación CSV filtrada pola busca visible.', 'anpa-socios' ) );
		$li( __( 'A exportación sensible «Descargar Socios IBAN» vive en Socios/as e require a frase da clave bancaria; queda rexistrada na Auditoría. Descárgaa só cando a tesourería a necesite e bórraa despois.', 'anpa-socios' ) );
		$li( __( 'Fai copia con UpdraftPlus antes de importacións, actualizacións do plugin ou cambios de estrutura.', 'anpa-socios' ) );
		$li_html( __( 'O ficheiro cifrado <code>.anpabak</code> (Axustes → Copias) é o único transporte completo da estrutura escolar e dos horarios de comedor; tamén conserva o nome do menú. Os CSV operativos non transportan a configuración de comedor.', 'anpa-socios' ) );
		echo '</ul></section>';

		// 7. Privacidade e seguridade.
		echo '<section id="privacidade-seguridade" class="card"><h2>' . esc_html( $sections['privacidade-seguridade'] ) . '</h2><ul>';
		$li( __( 'Os datos bancarios e os NIF dos titulares gárdanse cifrados; só se descifran para a exportación de domiciliacións e sempre coa frase.', 'anpa-socios' ) );
		$li( __( 'Non compartas a frase da clave nin listados con datos persoais por correo, WhatsApp ou chats de asistentes; se o fas por erro, avisa á xunta.', 'anpa-socios' ) );
		$li( __( 'A Xestión ANPA só a ven as persoas con permiso de administración en WordPress. As sesións da área de socios caducan soas e pódense pechar desde a propia área.', 'anpa-socios' ) );
		$li( __( 'A Auditoría é a proba de quen fixo que e cando. Antes de importar ou borrar datos persoais, fai a simulación, revisa e só entón confirma.', 'anpa-socios' ) );
		$li( __( 'Actualiza o plugin cando Axustes → Actualizacións o avise; le o historial de cambios e fai copia antes.', 'anpa-socios' ) );
		echo '</ul></section>';

		// 8. Manual das familias.
		echo '<section id="manual-familias" class="card"><h2>' . esc_html( $sections['manual-familias'] ) . '</h2>';
		$p( __( 'Texto pensado para copiar nunha entrada do blog ou nun correo ás familias. Adáptao co nome da asociación e a cota.', 'anpa-socios' ) );
		$h3( __( 'Sen contrasinais', 'anpa-socios' ) );
		$p( __( 'Para entrar na área persoal só tes que escribir o teu correo e recibirás un código de seis díxitos. Escríbelo e xa estás dentro; cada vez repítese o mesmo paso.', 'anpa-socios' ) );
		$h3( __( 'Como facerse socio/a', 'anpa-socios' ) );
		echo '<ol>';
		$li( __( 'Ten a man: nome, apelidos, NIF/NIE e teléfono; un correo ao que teñas acceso; por cada fillo/a nome, apelidos, data de nacemento, curso e aula; e os datos da conta (IBAN, titular e o seu NIF/NIE, enderezo, poboación, código postal e banco).', 'anpa-socios' ) );
		$li( __( 'Entra en «Asociarse», escribe o correo e pulsa «Enviar código». Copia o código do correo (mira tamén o spam) e pulsa «Verificar».', 'anpa-socios' ) );
		$li( __( 'Cubre os teus datos e, se queres, os do outro proxenitor/a con un correo distinto para que tamén poida entrar.', 'anpa-socios' ) );
		$li( __( 'Engade cada fillo/a con curso e aula deste ano e indica se autorizas a toma de imaxes.', 'anpa-socios' ) );
		$li( __( 'Cubre a conta bancaria e marca «Autorizo a domiciliación». Os datos gárdanse cifrados.', 'anpa-socios' ) );
		$li( __( 'Acepta a política de protección de datos e pulsa «Completar alta». Se a xunta aproba as altas, recibirás un correo cando estea aprobada.', 'anpa-socios' ) );
		echo '</ol>';
		$h3( __( 'Xa eras socio/a? Revisa tres cousas', 'anpa-socios' ) );
		echo '<ol>';
		$li( __( 'Fillos/as → curso e aula: as extraescolares que verás dependen do curso do teu fillo/a. Corrixe e pulsa «Gardar».', 'anpa-socios' ) );
		$li( __( 'Fillos/as → data de nacemento: pon a data real se aparece unha aproximada.', 'anpa-socios' ) );
		$li( __( 'Conta / IBAN: comproba titular e enderezo; se cambiaches de banco, actualízao aí e marca «Autorizo a domiciliación».', 'anpa-socios' ) );
		echo '</ol>';
		$h3( __( 'Extraescolares', 'anpa-socios' ) );
		echo '<ol>';
		$li( __( 'A oferta e o horario semanal están na páxina pública de extraescolares, sen identificarse.', 'anpa-socios' ) );
		$li( __( 'Na área persoal → Extraescolares → Nova matrícula escolle fillo/a, actividade e grupo (só aparecen os do seu curso) e indica as autorizacións que se piden.', 'anpa-socios' ) );
		$li( __( 'Estados: «Activa» hai praza; «En lista de espera» o grupo está completo: se queda praza, recibirás unha oferta e terás tres días para pulsar «Aceptar praza».', 'anpa-socios' ) );
		$li( __( 'Para deixar unha actividade pulsa «Baixa»: queda «Baixa solicitada» ata que a xunta a tramite; podes anulala mentres tanto.', 'anpa-socios' ) );
		echo '</ol>';
		$p( __( 'Se ao entrar non recoñece o teu correo, escribe ao correo de contacto da asociación.', 'anpa-socios' ) );
		echo '</section>';

		// 9. Checklist para administradores/as novos.
		echo '<section id="checklist-admins" class="card"><h2>' . esc_html( $sections['checklist-admins'] ) . '</h2><ol>';
		$li( __( 'Pide unha conta de administración de WordPress propia (nunca compartida). A Xestión ANPA aparece no menú lateral co nome configurado.', 'anpa-socios' ) );
		$li( __( 'Le esta documentación enteira e o historial de cambios do plugin (Axustes → Actualizacións).', 'anpa-socios' ) );
		$li( __( 'Axustes → Xeral → Estado: comproba versión, base de datos, clave bancaria configurada, curso activo e estado das matrículas, e correo de saída.', 'anpa-socios' ) );
		$li( __( 'Localiza onde garda a xunta a frase da clave bancaria e quen ten acceso. Nunca a pidas nin a envíes por correo.', 'anpa-socios' ) );
		$li( __( 'Entra na área de socios cun correo de proba para ver o que ven as familias.', 'anpa-socios' ) );
		$li( __( 'Abre Xestión → Auditoría e mira as últimas accións: é o rexistro de quen fixo que.', 'anpa-socios' ) );
		$li( __( 'Antes de importar, borrar ou aplicar cambios masivos: copia de seguridade e simulación. Se hai unha web de probas, usa esa primeiro.', 'anpa-socios' ) );
		$li( __( 'Anota a data das accións do ciclo anual (setembro e xuño) no calendario da xunta.', 'anpa-socios' ) );
		echo '</ol></section>';

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
