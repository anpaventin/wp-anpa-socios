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
 * Renders the [anpa_socios_area] shortcode and loads its assets.
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

		// Admin endpoints (fase5 hub). All gated server-side by permission_master;
		// the area token doubles as the admin token (master role checked per call).
		$admin_base_url = rest_url( 'anpa-socios/v1/admin/' );

		// Initial master email (public junta inbox) — used by the UI to lock the
		// protected master account. The server is the real boundary.
		$master_email = ANPA_Socios_Config::master_email();

		// Master auth & admin password endpoints (1.21.0).
		$master_init_url       = rest_url( 'anpa-socios/v1/area/master/init-status' );
		$master_init_post_url  = rest_url( 'anpa-socios/v1/area/master/init' );
		$admin_auth_url        = rest_url( 'anpa-socios/v1/area/me/admin-auth' );
		$admin_password_url    = rest_url( 'anpa-socios/v1/area/me/admin-password' );

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
			data-admin-base-url="<?php echo esc_attr( $admin_base_url ); ?>"
			data-master-email="<?php echo esc_attr( $master_email ); ?>"
			data-master-init-url="<?php echo esc_attr( $master_init_url ); ?>"
			data-master-init-post-url="<?php echo esc_attr( $master_init_post_url ); ?>"
			data-admin-auth-url="<?php echo esc_attr( $admin_auth_url ); ?>"
			data-admin-password-url="<?php echo esc_attr( $admin_password_url ); ?>"
			data-login-url="<?php echo esc_attr( class_exists( 'ANPA_Socios_Admin_Settings' ) ? ANPA_Socios_Admin_Settings::landing_page_url() : '' ); ?>">
			<div class="anpa-area-notice" data-area-message hidden></div>
			<div class="anpa-area-idle" data-idle-warning hidden role="alertdialog" aria-live="assertive">
				<span data-idle-text></span>
				<button type="button" data-action="idle-stay">Seguir conectado</button>
			</div>
			<div class="anpa-area-busy" data-busy hidden aria-hidden="true"><span class="anpa-area-spinner" role="status" aria-label="Traballando"></span></div>
			<div class="anpa-area-session-header" data-session-header hidden>
				<details class="anpa-session-menu" data-session-menu>
					<summary class="anpa-session-summary"><span class="anpa-area-session-who">Conectada/o como <strong data-session-email></strong></span></summary>
					<div class="anpa-session-menu-body">
						<button type="button" class="anpa-area-secondary" data-action="header-area">Os meus datos</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-action="header-admin" data-admin-entry hidden>Xestión ANPA</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-action="header-exports" data-admin-entry hidden>Listados</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-action="header-fullexport" data-admin-entry hidden>Descargar Socios IBAN</button>
						<button type="button" class="anpa-area-secondary anpa-area-danger" data-action="header-logout">Pechar sesión</button>
					</div>
				</details>
			</div>
			<div class="anpa-area-card" data-step="email" hidden>
				<h2>Área persoal de socios/as</h2>
				<p>Introduce o email co que estás dado/a de alta na ANPA. Enviarémosche un código para acceder sen contrasinal.</p>
				<label for="anpa-area-email">Email</label>
				<input id="anpa-area-email" type="email" autocomplete="email" required>
				<div style="position:absolute;left:-9999px" aria-hidden="true">
					<input type="text" id="anpa-area-website" name="website" tabindex="-1" autocomplete="off" value="">
				</div>
				<input type="hidden" id="anpa-area-ts" name="_ts" value="<?php echo (int) time(); ?>">
				<button type="button" data-action="request-code">Enviar código</button>
			</div>

			<div class="anpa-area-card" data-step="code" hidden>
				<h2>Revisa o teu correo</h2>
				<p>Escribe o código recibido para abrir a túa área persoal.</p>
				<label for="anpa-area-code">Código de 6 díxitos</label>
				<input id="anpa-area-code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
				<button type="button" data-action="verify-code">Entrar</button>
				<button type="button" class="anpa-area-secondary" data-action="back-email">Cambiar email</button>
			</div>

			<div class="anpa-area-card" data-step="alta" hidden>
				<h2>Completa a túa alta</h2>
				<p>Se o email é válido, recibirás un código. Tras introducilo, pedirémosche os teus datos para completar a alta como socio/a.</p>
				<label for="anpa-area-email-alta">Email</label>
				<input id="anpa-area-email-alta" type="email" autocomplete="email" required>
				<button type="button" data-action="request-code-alta">Enviar código</button>
			</div>

			<div class="anpa-area-card" data-step="inactivo" hidden>
				<h2>A túa solicitude de baixa está procesada</h2>
				<p>Se queres volver a ser socio/a, podes solicitar a reactivación da túa conta. Un/unha administrador/a da ANPA deberá aprobala antes de poder acceder de novo.</p>
				<div class="anpa-area-actions">
					<button type="button" data-action="request-reactivar">Solicitar reactivación</button>
					<button type="button" class="anpa-area-secondary" data-action="back-email">Cambiar email</button>
				</div>
			</div>

			<!-- ── Profile step (fase8b: expanded with telefono, nif, email, proxenitor2, banking) ── -->
			<div class="anpa-area-card" data-step="profile" hidden>
				<h2>Os teus datos</h2>
				<p class="anpa-area-muted" data-profile-help></p>
				<p><strong>Email:</strong> <span data-profile-email></span></p>
				<!-- Profile edit fields -->
				<label for="anpa-area-nome">Nome</label>
				<input id="anpa-area-nome" type="text" autocomplete="given-name" required>
				<span class="anpa-field-error" data-error="nome" hidden></span>
				<label for="anpa-area-apelidos">Apelidos</label>
				<input id="anpa-area-apelidos" type="text" autocomplete="family-name" required>
				<span class="anpa-field-error" data-error="apelidos" hidden></span>
				<label for="anpa-area-telefono">Teléfono</label>
				<input id="anpa-area-telefono" type="tel" autocomplete="tel">
				<span class="anpa-field-error" data-error="telefono" hidden></span>
				<label for="anpa-area-nif">NIF / NIE</label>
				<input id="anpa-area-nif" type="text" autocomplete="off" required>
				<span class="anpa-field-error" data-error="nif" hidden></span>
				<label for="anpa-area-email-edit">Correo electrónico</label>
				<input id="anpa-area-email-edit" type="email" autocomplete="email">
				<span class="anpa-field-error" data-error="email" hidden></span>
				<p class="anpa-area-baixa-status" data-baixa-status hidden></p>
				<div class="anpa-area-actions">
					<button type="button" data-action="save-profile">Gardar cambios</button>
					<button type="button" class="anpa-area-secondary" data-action="manage-fillos">Xestionar fillos/as</button>
					<button type="button" class="anpa-area-secondary" data-action="manage-extraescolares">Extraescolares</button>
					<button type="button" class="anpa-area-secondary" data-action="toggle-proxenitor2">Engadir outro proxenitor/titor</button>
					<button type="button" class="anpa-area-secondary" data-action="toggle-banking">Modificación IBAN</button>
					<button type="button" class="anpa-area-secondary anpa-admin-action" data-action="open-admin" data-admin-entry hidden>Xestión ANPA</button>
					<button type="button" class="anpa-area-secondary anpa-area-danger" data-action="request-baixa" data-baixa-btn hidden>Solicitar baixa de socio/a</button>
					<button type="button" class="anpa-area-secondary" data-action="cancel-baixa" data-baixa-cancel-btn hidden>Anular solicitude de baixa</button>
					<button type="button" class="anpa-area-secondary" data-action="logout">Pechar sesión</button>

				</div>
			</div>

			<!-- ── Master init wizard (1.21.0): shown on first login when not initialized ── -->
			<div class="anpa-area-card" data-step="master-init" hidden>
				<div class="anpa-area-alert anpa-area-alert-warning" data-master-aviso role="alert"></div>
				<h2>Inicialización da base de datos</h2>
				<p>É a túa primeira vez como administrador/a. Para protexer os datos bancarios dos socios, xerase un par de claves asimétricas. O contrasinal de descifrado debe ter <strong>polo menos 5 palabras</strong> separadas por guións.</p>
				<p>Podes usar o suxerido ou escribir o teu propio.</p>
				<label for="anpa-master-passphrase">Contrasinal (5+ palabras separadas por guións)</label>
				<input id="anpa-master-passphrase" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" required>
				<p class="anpa-area-muted" data-passphrase-hint></p>
				<button type="button" data-action="regenerate-passphrase">Outra combinación</button>
				<div class="anpa-area-actions">
					<button type="button" data-action="master-init-confirm">Inicializar base de datos</button>
				</div>
			</div>

			<!-- ── Admin password gate (1.21.0): short password for panel access ── -->
			<div class="anpa-area-card" data-step="admin-auth" hidden>
				<h2>Contrasinal de administración</h2>
				<p>Para acceder ao panel de Xestión ANPA, introduce o contrasinal de administración.</p>
				<label for="anpa-admin-auth-pass">Contrasinal</label>
				<input id="anpa-admin-auth-pass" type="password" autocomplete="off" autocapitalize="off" spellcheck="false" required>
				<div class="anpa-area-actions">
					<button type="button" data-action="admin-auth-submit">Acceder</button>
					<button type="button" class="anpa-area-secondary" data-action="back-profile">Volver</button>
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
				<h2>Os teus fillos e fillas</h2>
				<p class="anpa-area-muted">Engade, edita ou da de baixa os datos básicos dos teus fillos/as. Só ti podes ver e xestionar os teus.</p>
				<div data-fillos-list></div>
				<h3 data-fillos-form-title>Engadir fillo/a</h3>
				<input type="hidden" data-fillo-edit-id value="">
				<label for="anpa-fillo-nome">Nome</label>
				<input id="anpa-fillo-nome" type="text" autocomplete="off" required>
				<label for="anpa-fillo-apelidos">Apelidos</label>
				<input id="anpa-fillo-apelidos" type="text" autocomplete="off" required>
				<label for="anpa-fillo-data">Data de nacemento</label>
				<input id="anpa-fillo-data" type="date" required>
				<label for="anpa-fillo-curso">Curso</label>
				<select id="anpa-fillo-curso" required>
					<option value="">-- Selecciona --</option>
					<option value="1">1º</option>
					<option value="2">2º</option>
					<option value="3">3º</option>
					<option value="4">4º</option>
					<option value="5">5º</option>
					<option value="6">6º</option>
				</select>
				<label for="anpa-fillo-aula">Grupo</label>
				<select id="anpa-fillo-aula" required>
					<option value="">-- Selecciona --</option>
					<option value="A">A</option>
					<option value="B">B</option>
					<option value="C">C</option>
					<option value="D">D</option>
				</select>
				<div class="anpa-area-actions">
					<button type="button" data-action="save-fillo">Gardar fillo/a</button>
					<button type="button" class="anpa-area-secondary" data-action="cancel-fillo-edit" hidden>Cancelar edición</button>
					<button type="button" class="anpa-area-secondary" data-action="back-profile">Volver aos meus datos</button>
				</div>
			</div>

			<div class="anpa-area-card" data-step="extraescolares" hidden>
				<h2>Actividades extraescolares</h2>
				<p class="anpa-area-muted">Anota os teus fillos/as nas actividades dispoñibles. Se un grupo está completo, entrarán na lista de espera e avisarémosvos por correo se queda praza.</p>
				<div data-extra-matriculas></div>
				<h3>Nova matrícula</h3>
				<div data-extra-enrol></div>
				<div class="anpa-area-actions">
					<button type="button" class="anpa-area-secondary" data-action="extra-back">Volver aos teus datos</button>
				</div>
			</div>

			<div class="anpa-area-card anpa-admin-card" data-step="admin" hidden>
				<h2>Xestión ANPA</h2>
				<p class="anpa-area-muted">Panel de administración. Só accesible para a directiva (rol master). Cada consulta valídase no servidor.</p>
				<div class="anpa-admin-toolbar">
					<nav class="anpa-admin-nav" aria-label="Seccións de xestión">
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="socios">Socios/as</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="approvals">Aprobacións</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="fillos">Fillos/as</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="empresas">Empresas</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="actividades">Actividades</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="cursos">Cursos</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="matriculas">Matrículas</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="admins">Administradores</button>
						<button type="button" class="anpa-area-secondary anpa-admin-action" data-admin-section="audit">Auditoría</button>
					</nav>
					<div class="anpa-admin-tools">
						<button type="button" class="anpa-area-secondary" data-action="toggle-exports" aria-expanded="false" aria-controls="anpa-admin-panel-exports">Listados ▾</button>
						<button type="button" class="anpa-area-secondary" data-action="toggle-fullexport" aria-expanded="false" aria-controls="anpa-admin-panel-fullexport">Descargar Socios IBAN ▾</button>
					</div>
				</div>
				<div class="anpa-admin-panel" id="anpa-admin-panel-exports" data-panel="exports" hidden>
					<h3>Listados</h3>
					<p class="anpa-area-muted">Preme unha sección para visualizar os datos como un listado. Cada listado ten a súa propia caixa de busca, ordenación por columnas e botón para exportar a CSV.</p>
					<div class="anpa-admin-exports">
						<button type="button" class="anpa-area-secondary" data-admin-export="socios">Socios/as</button>
						<button type="button" class="anpa-area-secondary" data-admin-export="fillos">Fillos/as</button>
						<button type="button" class="anpa-area-secondary" data-admin-export="empresas">Empresas</button>
						<button type="button" class="anpa-area-secondary" data-admin-export="actividades">Actividades</button>
						<button type="button" class="anpa-area-secondary" data-admin-export="matriculas">Matrículas</button>
						<button type="button" class="anpa-area-secondary" data-admin-export="alumnos">Alumnos (todas)</button>
					</div>
				</div>
				<div class="anpa-admin-panel" id="anpa-admin-panel-fullexport" data-panel="fullexport" hidden>
					<h3>Descargar Socios IBAN</h3>
					<p class="anpa-area-muted">Exporta todos os socios/as nun único CSV con datos para domiciliación bancaria. Require sempre o contrasinal de descifrado. Marca a caixa só se precisas incluír os datos bancarios descifrados (IBAN, NIF do titular).</p>
					<label for="anpa-admin-export-pass">Contrasinal de descifrado</label>
					<input id="anpa-admin-export-pass" type="password" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
					<label class="anpa-admin-check"><input type="checkbox" id="anpa-admin-export-banking"> Incluír datos bancarios descifrados</label>
					<button type="button" data-action="admin-export-full">Descargar Socios IBAN</button>
				</div>
				<div data-admin-content></div>
				<div class="anpa-area-actions">
					<button type="button" class="anpa-area-secondary" data-action="back-profile-admin">Volver aos meus datos</button>
				</div>
			</div>

			<div class="anpa-area-card" data-step="empresa" hidden>
				<h2>Panel da empresa</h2>
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
	 * Enqueues assets only for pages containing [anpa_socios_area].
	 *
	 * @since  1.1.0
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

		$js_path     = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/area.js';
		$css_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/area.css';
		$js_version  = file_exists( $js_path ) ? (int) filemtime( $js_path ) : ANPA_SOCIOS_VERSION;
		$css_version = file_exists( $css_path ) ? (int) filemtime( $css_path ) : ANPA_SOCIOS_VERSION;

		$table_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/admin-table.js';
		$table_version = file_exists( $table_path ) ? (int) filemtime( $table_path ) : ANPA_SOCIOS_VERSION;
		wp_enqueue_script(
			'anpa-socios-admin-table',
			plugins_url( 'assets/js/admin-table.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$table_version,
			true
		);

		// Normalize helpers (title_case, email, telefono, nif).
		// Loaded BEFORE area.js so AnpaNormalize is available at parse time.
		$norm_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/anpa-normalize.js';
		$norm_version = file_exists( $norm_path ) ? (int) filemtime( $norm_path ) : ANPA_SOCIOS_VERSION;
		wp_enqueue_script(
			'anpa-socios-normalize',
			plugins_url( 'assets/js/anpa-normalize.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$norm_version,
			true
		);

		// Pure utility functions (colLabel, filterRows, buildCsvString…).
		// Loaded BEFORE area.js so AnpaUtils is available at parse time.
		$utils_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/anpa-utils.js';
		$utils_version = file_exists( $utils_path ) ? (int) filemtime( $utils_path ) : ANPA_SOCIOS_VERSION;
		wp_enqueue_script(
			'anpa-socios-utils',
			plugins_url( 'assets/js/anpa-utils.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$utils_version,
			true
		);

		wp_enqueue_script(
			'anpa-socios-area',
			plugins_url( 'assets/js/area.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array( 'anpa-socios-admin-table', 'anpa-socios-normalize', 'anpa-socios-utils' ),
			$js_version,
			true
		);

		wp_enqueue_style(
			'anpa-socios-area',
			plugins_url( 'assets/css/area.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$css_version
		);

		// Compact admin UI (smaller buttons, gap between them, scroll
		// anchor for the edit form, copy-to-current button styling).
		$compact_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/admin-compact.css';
		$compact_version = file_exists( $compact_path ) ? (int) filemtime( $compact_path ) : ANPA_SOCIOS_VERSION;
		wp_enqueue_style(
			'anpa-socios-admin-compact',
			plugins_url( 'assets/css/admin-compact.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array( 'anpa-socios-area' ),
			$compact_version
		);
	}
}
