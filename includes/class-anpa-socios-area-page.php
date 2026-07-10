<?php
/**
 * Page renderer and asset loader for the ANPA Socios personal area.
 *
 * @since  1.1.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [anpa_socios_area_persoal] shortcode and loads its assets.
 *
 * @since 1.1.0
 */
class ANPA_Socios_Area_Page {

	/**
	 * Shortcode callback for the socio personal area.
	 *
	 * @since  1.1.0
	 * @param  array $atts Shortcode attributes (none used).
	 * @return string
	 */
	public static function render( $atts ): string {
		$solicitar_codigo_url = rest_url( 'anpa/v1/solicitar-codigo' );
		$verificar_codigo_url  = rest_url( 'anpa/v1/verificar-codigo' );
		$preflight_url         = rest_url( 'anpa-socios/v1/area/preflight' );
		$session_url           = rest_url( 'anpa-socios/v1/area/session' );
		$session_status_url    = rest_url( 'anpa-socios/v1/area/session-status' );
		$profile_url           = rest_url( 'anpa-socios/v1/area/me' );
		$logout_url            = rest_url( 'anpa-socios/v1/area/me/session' );
		$baixa_url             = rest_url( 'anpa-socios/v1/area/me/baixa' );
		$baixa_cancel_url      = rest_url( 'anpa-socios/v1/area/me/baixa/cancel' );
		$reactivar_url         = rest_url( 'anpa-socios/v1/area/reactivar' );
		$fillos_url            = rest_url( 'anpa-socios/v1/fillos' );
		$fillo_url             = rest_url( 'anpa-socios/v1/fillo/' );
		$referencias_url       = rest_url( 'anpa-socios/v1/area/referencias' );
		$proxenitor2_search_url = rest_url( 'anpa-socios/v1/area/me/proxenitor2/search' );
		$proxenitor2_link_url   = rest_url( 'anpa-socios/v1/area/me/proxenitor2/link' );
		$proxenitor2_confirm_url = rest_url( 'anpa-socios/v1/area/me/proxenitor2/confirm' );
		$proxenitor2_add_url    = rest_url( 'anpa-socios/v1/area/me/proxenitor2/add' );

		// Extraescolares enrolment endpoints (fase7).
		$extra_oferta_url      = rest_url( 'anpa-socios/v1/area/extraescolares' );
		$extra_matriculas_url  = rest_url( 'anpa-socios/v1/area/me/matriculas' );
		$extra_fillo_base_url  = rest_url( 'anpa-socios/v1/area/fillo/' );
		$extra_matricula_base_url = rest_url( 'anpa-socios/v1/area/matricula/' );

		// Empresa endpoints (PR4b).
		$empresa_session_url      = rest_url( 'anpa-socios/v1/empresa/session' );
		$empresa_me_url           = rest_url( 'anpa-socios/v1/empresa/me' );
		$empresa_logout_url       = rest_url( 'anpa-socios/v1/empresa/me/session' );
		$empresa_export_url       = rest_url( 'anpa-socios/v1/empresa/me/export' );
		$empresa_request_code_url = rest_url( 'anpa-socios/v1/empresa/solicitar-codigo' );


		ob_start();
		?>
		<section id="anpa-area"
			data-preflight-url="<?php echo esc_attr( $preflight_url ); ?>"
			data-request-code-url="<?php echo esc_attr( $solicitar_codigo_url ); ?>"
			data-verify-code-url="<?php echo esc_attr( $verificar_codigo_url ); ?>"
			data-session-url="<?php echo esc_attr( $session_url ); ?>"
			data-session-status-url="<?php echo esc_attr( $session_status_url ); ?>"
			data-profile-url="<?php echo esc_attr( $profile_url ); ?>"
			data-logout-url="<?php echo esc_attr( $logout_url ); ?>"
			data-baixa-url="<?php echo esc_attr( $baixa_url ); ?>"
			data-baixa-cancel-url="<?php echo esc_attr( $baixa_cancel_url ); ?>"
			data-reactivar-url="<?php echo esc_attr( $reactivar_url ); ?>"
			data-fillos-url="<?php echo esc_attr( $fillos_url ); ?>"
			data-fillo-url="<?php echo esc_attr( $fillo_url ); ?>"
			data-referencias-url="<?php echo esc_attr( $referencias_url ); ?>"
			data-proxenitor2-search-url="<?php echo esc_attr( $proxenitor2_search_url ); ?>"
			data-proxenitor2-link-url="<?php echo esc_attr( $proxenitor2_link_url ); ?>"
			data-proxenitor2-confirm-url="<?php echo esc_attr( $proxenitor2_confirm_url ); ?>"
			data-proxenitor2-add-url="<?php echo esc_attr( $proxenitor2_add_url ); ?>"
			data-extra-oferta-url="<?php echo esc_attr( $extra_oferta_url ); ?>"
			data-extra-matriculas-url="<?php echo esc_attr( $extra_matriculas_url ); ?>"
			data-extra-fillo-base-url="<?php echo esc_attr( $extra_fillo_base_url ); ?>"
			data-extra-matricula-base-url="<?php echo esc_attr( $extra_matricula_base_url ); ?>"
			data-empresa-session-url="<?php echo esc_attr( $empresa_session_url ); ?>"
			data-empresa-request-code-url="<?php echo esc_attr( $empresa_request_code_url ); ?>"
			data-empresa-me-url="<?php echo esc_attr( $empresa_me_url ); ?>"
			data-empresa-logout-url="<?php echo esc_attr( $empresa_logout_url ); ?>"
			data-empresa-export-url="<?php echo esc_attr( $empresa_export_url ); ?>"
			data-aulas="<?php echo esc_attr( (string) wp_json_encode( ANPA_Socios_Config::aulas() ) ); ?>"
			data-login-url="<?php echo esc_attr( class_exists( 'ANPA_Socios_Admin_Settings' ) ? ANPA_Socios_Admin_Settings::landing_page_url() : '' ); ?>">
			<div class="anpa-area-notice" data-area-message hidden></div>
			<div class="anpa-area-idle" data-idle-warning hidden role="alertdialog" aria-live="assertive">
				<span data-idle-text></span>
				<button type="button" data-action="idle-stay"><?php esc_html_e( 'Seguir conectado', 'anpa-socios' ); ?></button>
			</div>
			<div class="anpa-area-busy" data-busy hidden aria-hidden="true"><span class="anpa-area-spinner" role="status" aria-label="<?php esc_attr_e( 'Traballando', 'anpa-socios' ); ?>"></span></div>
			<div class="anpa-area-session-header" data-session-header hidden>
				<details class="anpa-session-menu" data-session-menu>
					<summary class="anpa-session-summary"><span class="anpa-area-session-who"><?php esc_html_e( 'Conectada/o como', 'anpa-socios' ); ?> <strong data-session-email></strong></span></summary>
					<div class="anpa-session-menu-body">
						<button type="button" class="anpa-area-secondary" data-action="header-area"><?php esc_html_e( 'Os meus datos', 'anpa-socios' ); ?></button>

						<button type="button" class="anpa-area-secondary anpa-area-danger" data-action="header-logout"><?php esc_html_e( 'Pechar sesión', 'anpa-socios' ); ?></button>
					</div>
				</details>
			</div>
			<div class="anpa-area-card" data-step="email" hidden>
				<h2><?php esc_html_e( 'Área persoal de socios/as', 'anpa-socios' ); ?></h2>
				<p><?php esc_html_e( 'Introduce o email co que estás dado/a de alta na ANPA. Enviarémosche un código para acceder sen contrasinal.', 'anpa-socios' ); ?></p>
				<label for="anpa-area-email"><?php esc_html_e( 'Email', 'anpa-socios' ); ?></label>
				<input id="anpa-area-email" type="email" autocomplete="email" required>
				<div style="position:absolute;left:-9999px" aria-hidden="true">
					<input type="text" id="anpa-area-website" name="website" tabindex="-1" autocomplete="off" value="">
				</div>
				<input type="hidden" id="anpa-area-ts" name="_ts" value="<?php echo (int) time(); ?>">
				<button type="button" data-action="request-code"><?php esc_html_e( 'Enviar código', 'anpa-socios' ); ?></button>
			</div>

			<div class="anpa-area-card" data-step="code" hidden>
				<h2><?php esc_html_e( 'Revisa o teu correo', 'anpa-socios' ); ?></h2>
				<p><?php esc_html_e( 'Escribe o código recibido para abrir a túa área persoal.', 'anpa-socios' ); ?></p>
				<label for="anpa-area-code"><?php esc_html_e( 'Código de 6 díxitos', 'anpa-socios' ); ?></label>
				<input id="anpa-area-code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
				<button type="button" data-action="verify-code"><?php esc_html_e( 'Entrar', 'anpa-socios' ); ?></button>
				<button type="button" class="anpa-area-secondary" data-action="back-email"><?php esc_html_e( 'Cambiar email', 'anpa-socios' ); ?></button>
			</div>

			<div class="anpa-area-card" data-step="alta" hidden>
				<h2><?php esc_html_e( 'Completa a túa alta', 'anpa-socios' ); ?></h2>
				<p><?php esc_html_e( 'Se o email é válido, recibirás un código. Tras introducilo, pedirémosche os teus datos para completar a alta como socio/a.', 'anpa-socios' ); ?></p>
				<label for="anpa-area-email-alta"><?php esc_html_e( 'Email', 'anpa-socios' ); ?></label>
				<input id="anpa-area-email-alta" type="email" autocomplete="email" required>
				<button type="button" data-action="request-code-alta"><?php esc_html_e( 'Enviar código', 'anpa-socios' ); ?></button>
			</div>

			<div class="anpa-area-card" data-step="inactivo" hidden>
				<h2><?php esc_html_e( 'A túa solicitude de baixa está procesada', 'anpa-socios' ); ?></h2>
				<p><?php esc_html_e( 'Se queres volver a ser socio/a, podes solicitar a reactivación da túa conta. Un/unha administrador/a da ANPA deberá aprobala antes de poder acceder de novo.', 'anpa-socios' ); ?></p>
				<div class="anpa-area-actions">
					<button type="button" data-action="request-reactivar"><?php esc_html_e( 'Solicitar reactivación', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="back-email"><?php esc_html_e( 'Cambiar email', 'anpa-socios' ); ?></button>
				</div>
			</div>

			<!-- ── Profile step (fase8b: expanded with telefono, nif, email, proxenitor2, banking) ── -->
			<div class="anpa-area-card" data-step="profile" hidden>
				<h2><?php esc_html_e( 'Os teus datos', 'anpa-socios' ); ?></h2>
				<p class="anpa-area-muted" data-profile-help></p>
				<p><strong>Email:</strong> <span data-profile-email></span></p>
				<!-- Profile edit fields -->
				<label for="anpa-area-nome"><?php esc_html_e( 'Nome', 'anpa-socios' ); ?></label>
				<input id="anpa-area-nome" type="text" autocomplete="given-name" required>
				<span class="anpa-field-error" data-error="nome" hidden></span>
				<label for="anpa-area-apelidos"><?php esc_html_e( 'Apelidos', 'anpa-socios' ); ?></label>
				<input id="anpa-area-apelidos" type="text" autocomplete="family-name" required>
				<span class="anpa-field-error" data-error="apelidos" hidden></span>
				<label for="anpa-area-telefono"><?php esc_html_e( 'Teléfono', 'anpa-socios' ); ?></label>
				<input id="anpa-area-telefono" type="tel" autocomplete="tel">
				<span class="anpa-field-error" data-error="telefono" hidden></span>
				<label for="anpa-area-nif"><?php esc_html_e( 'NIF / NIE', 'anpa-socios' ); ?></label>
				<input id="anpa-area-nif" type="text" autocomplete="off" required>
				<span class="anpa-field-error" data-error="nif" hidden></span>
				<label for="anpa-area-email-edit"><?php esc_html_e( 'Correo electrónico', 'anpa-socios' ); ?></label>
				<input id="anpa-area-email-edit" type="email" autocomplete="email">
				<span class="anpa-field-error" data-error="email" hidden></span>
				<p class="anpa-area-baixa-status" data-baixa-status hidden></p>
				<div class="anpa-area-actions">
					<button type="button" data-action="save-profile"><?php esc_html_e( 'Gardar cambios', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="manage-fillos"><?php esc_html_e( 'Xestionar fillos/as', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="manage-extraescolares"><?php esc_html_e( 'Extraescolares', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="toggle-proxenitor2"><?php esc_html_e( 'Engadir outro proxenitor/titor', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="toggle-banking"><?php esc_html_e( 'Modificación IBAN', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary anpa-area-danger" data-action="request-baixa" data-baixa-btn hidden><?php esc_html_e( 'Solicitar baixa de socio/a', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="cancel-baixa" data-baixa-cancel-btn hidden><?php esc_html_e( 'Anular solicitude de baixa', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="logout"><?php esc_html_e( 'Pechar sesión', 'anpa-socios' ); ?></button>

				</div>
			</div>

			<!-- ── Proxenitor2 (fase 1.20.0) ── -->
			<div class="anpa-area-card" data-step="proxenitor2" hidden>
				<h2>Engadir outro proxenitor/titor</h2>
				<p class="anpa-area-muted">Cubre os datos do outro proxenitor/titor para vinculalo á túa conta. O correo é opcional (pode ser o mesmo que o teu). Se o correo ou o DNI xa está rexistrado, vincularase automaticamente como proxenitor secundario.</p>
				<div data-p2-error class="anpa-area-error" hidden></div>
				<label for="anpa-area-p2-nome">Nome</label>
				<input id="anpa-area-p2-nome" type="text" autocomplete="off">
				<label for="anpa-area-p2-apelidos">Apelidos</label>
				<input id="anpa-area-p2-apelidos" type="text" autocomplete="off">
				<label for="anpa-area-p2-email">Email (opcional)</label>
				<input id="anpa-area-p2-email" type="email" autocomplete="off" placeholder="Deixa baleiro se non quere recibir notificacións">
				<label for="anpa-area-p2-nif">NIF / NIE</label>
				<input id="anpa-area-p2-nif" type="text" autocomplete="off" placeholder="Ex: 12345678Z ou X1234567L">
				<label for="anpa-area-p2-telefono">Teléfono (opcional)</label>
				<input id="anpa-area-p2-telefono" type="tel" autocomplete="off" inputmode="numeric">
				<button type="button" data-action="proxenitor2-add">Engadir como proxenitor secundario</button>
				<div data-p2-result hidden></div>
				<div class="anpa-area-actions">
					<button type="button" class="anpa-area-secondary" data-action="back-profile">Volver aos meus datos</button>
				</div>
			</div>

			<!-- ── Banking / Modificación IBAN (fase 1.20.0) ── -->
			<div class="anpa-area-card" data-step="banking" hidden>
				<h2>Modificación IBAN</h2>
				<p class="anpa-area-muted">Aquí podes consultar e modificar os datos da túa conta para a domiciliación das cotas. O IBAN está protexido: se queres cambialo, introduce o novo IBAN (e o novo titular se corresponde outra persoa). Para o resto de campos modifica o valor e garda.</p>
				<label for="anpa-bank-titular-nome">Nome do titular</label>
				<input id="anpa-bank-titular-nome" type="text" required>
				<span class="anpa-field-error" data-error="titular_nome" hidden></span>
				<label for="anpa-bank-titular-apelidos">Apelidos do titular</label>
				<input id="anpa-bank-titular-apelidos" type="text" required>
				<span class="anpa-field-error" data-error="titular_apelidos" hidden></span>
				<label for="anpa-bank-titular-nif">NIF / NIE do titular</label>
				<input id="anpa-bank-titular-nif" type="text" required>
				<span class="anpa-field-error" data-error="titular_nif" hidden></span>
				<label for="anpa-bank-iban">IBAN (encriptado: só se mostra enmascarado. Para cambialo, introduce aquí o novo IBAN)</label>
				<input id="anpa-bank-iban" type="text" placeholder="ES00 0000 0000 0000 0000 0000">
				<span class="anpa-field-error" data-error="iban" hidden></span>
				<small class="anpa-area-muted" id="anpa-bank-iban-mask" data-iban-mask></small>
				<label for="anpa-bank-entidade">Entidade bancaria</label>
				<input id="anpa-bank-entidade" type="text" required>
				<span class="anpa-field-error" data-error="entidade_bancaria" hidden></span>
				<label for="anpa-bank-enderezo">Enderezo</label>
				<input id="anpa-bank-enderezo" type="text" required>
				<span class="anpa-field-error" data-error="enderezo" hidden></span>
				<label for="anpa-bank-provincia">Provincia</label>
				<input id="anpa-bank-provincia" type="text" autocomplete="address-level1" required value="<?php echo esc_attr( ANPA_Socios_Config::default_province() ); ?>">
				<span class="anpa-field-error" data-error="provincia" hidden></span>
				<label for="anpa-bank-poboacion">Poboación</label>
				<input id="anpa-bank-poboacion" type="text" autocomplete="address-level2" required value="<?php echo esc_attr( ANPA_Socios_Config::default_town() ); ?>">
				<span class="anpa-field-error" data-error="poboacion" hidden></span>
				<label for="anpa-bank-cp">Código postal</label>
				<input id="anpa-bank-cp" type="text" inputmode="numeric" maxlength="5" required>
				<span class="anpa-field-error" data-error="codigo_postal" hidden></span>
				<label for="anpa-bank-lugar-data">Lugar e data</label>
				<input id="anpa-bank-lugar-data" type="text" readonly>
				<span class="anpa-field-error" data-error="lugar_data" hidden></span>
				<label class="anpa-admin-check">
					<input type="checkbox" id="anpa-bank-autorizacion" required>
					Autorizo a ANPA a domiciliar os recibos das cotas
				</label>
				<span class="anpa-field-error" data-error="autorizacion" hidden></span>
				<div class="anpa-area-actions">
					<button type="button" data-action="save-banking">Gardar cambios</button>
					<button type="button" class="anpa-area-secondary" data-action="back-profile">Volver aos meus datos</button>
				</div>
			</div>

			<div class="anpa-area-card" data-step="fillos" hidden>
				<h2><?php esc_html_e( 'Os teus fillos e fillas', 'anpa-socios' ); ?></h2>
				<p class="anpa-area-muted"><?php esc_html_e( 'Engade, edita ou da de baixa os datos básicos dos teus fillos/as. Só ti podes ver e xestionar os teus.', 'anpa-socios' ); ?></p>
				<div data-fillos-list></div>
				<h3 data-fillos-form-title><?php esc_html_e( 'Engadir fillo/a', 'anpa-socios' ); ?></h3>
				<input type="hidden" data-fillo-edit-id value="">
				<label for="anpa-fillo-nome"><?php esc_html_e( 'Nome', 'anpa-socios' ); ?></label>
				<input id="anpa-fillo-nome" type="text" autocomplete="off" required>
				<label for="anpa-fillo-apelidos"><?php esc_html_e( 'Apelidos', 'anpa-socios' ); ?></label>
				<input id="anpa-fillo-apelidos" type="text" autocomplete="off" required>
				<label for="anpa-fillo-data"><?php esc_html_e( 'Data de nacemento', 'anpa-socios' ); ?></label>
				<input id="anpa-fillo-data" type="date" required>
				<label for="anpa-fillo-curso"><?php esc_html_e( 'Curso', 'anpa-socios' ); ?></label>
				<select id="anpa-fillo-curso" required>
					<option value=""><?php esc_html_e( '-- Selecciona --', 'anpa-socios' ); ?></option>
					<option value="1">1º</option>
					<option value="2">2º</option>
					<option value="3">3º</option>
					<option value="4">4º</option>
					<option value="5">5º</option>
					<option value="6">6º</option>
				</select>
				<label for="anpa-fillo-aula"><?php esc_html_e( 'Grupo', 'anpa-socios' ); ?></label>
				<select id="anpa-fillo-aula" required>
					<option value=""><?php esc_html_e( '-- Selecciona --', 'anpa-socios' ); ?></option>
					<?php foreach ( ANPA_Socios_Config::aulas() as $aula_option ) : ?>
						<option value="<?php echo esc_attr( $aula_option ); ?>"><?php echo esc_html( $aula_option ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="anpa-area-actions">
					<button type="button" data-action="save-fillo"><?php esc_html_e( 'Gardar fillo/a', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="cancel-fillo-edit" hidden><?php esc_html_e( 'Cancelar edición', 'anpa-socios' ); ?></button>
					<button type="button" class="anpa-area-secondary" data-action="back-profile"><?php esc_html_e( 'Volver aos meus datos', 'anpa-socios' ); ?></button>
				</div>
			</div>

			<div class="anpa-area-card" data-step="extraescolares" hidden>
				<h2><?php esc_html_e( 'Actividades extraescolares', 'anpa-socios' ); ?></h2>
				<p class="anpa-area-muted"><?php esc_html_e( 'Anota os teus fillos/as nas actividades dispoñibles. Se un grupo está completo, entrarán na lista de espera e avisarémosvos por correo se queda praza.', 'anpa-socios' ); ?></p>
				<div data-extra-matriculas></div>
				<h3><?php esc_html_e( 'Nova matrícula', 'anpa-socios' ); ?></h3>
				<div data-extra-enrol></div>
				<div class="anpa-area-actions">
					<button type="button" class="anpa-area-secondary" data-action="extra-back"><?php esc_html_e( 'Volver aos teus datos', 'anpa-socios' ); ?></button>
				</div>
			</div>

			<div class="anpa-area-card" data-step="empresa" hidden>
				<h2><?php esc_html_e( 'Panel da empresa', 'anpa-socios' ); ?></h2>
				<p class="anpa-area-muted"><strong>Empresa:</strong> <span data-empresa-nome></span></p>
				<p class="anpa-area-muted"><strong>Email:</strong> <span data-empresa-email></span></p>
				<div class="anpa-area-actions">
					<button type="button" data-action="empresa-export">Descargar CSV dos alumnos</button>
					<button type="button" class="anpa-area-secondary" data-action="empresa-logout">Pechar sesión</button>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueues assets only for pages containing [anpa_socios_area_persoal].
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'anpa_socios_area_persoal' ) ) {
			return;
		}

		$js_path     = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/area.js';
		$css_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/area.css';
		$js_version  = file_exists( $js_path ) ? (int) filemtime( $js_path ) : ANPA_SOCIOS_VERSION;
		$css_version = file_exists( $css_path ) ? (int) filemtime( $css_path ) : ANPA_SOCIOS_VERSION;

		wp_enqueue_script(
			'anpa-socios-area',
			plugins_url( 'assets/js/area.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array( 'wp-i18n' ),
			$js_version,
			true
		);
		wp_set_script_translations( 'anpa-socios-area', 'anpa-socios', ANPA_SOCIOS_PLUGIN_DIR . 'languages' );

		wp_enqueue_style(
			'anpa-socios-area',
			plugins_url( 'assets/css/area.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$css_version
		);
	}
}
