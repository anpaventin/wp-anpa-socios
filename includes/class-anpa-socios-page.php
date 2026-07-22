<?php
/**
 * Page renderer and asset loader for the anpa-socios plugin.
 *
 * Renders the [anpa_socios_asociarse] shortcode with the
 * 3-step form, and enqueues the JS+CSS assets only on pages
 * that use the shortcode.
 *
 * @since  1.0.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [anpa_socios_asociarse] shortcode and enqueues
 * the page assets.
 *
 * The class is named `ANPA_Socios_Socios_Page` (not just
 * `ANPA_Socios_Page`) to avoid name collisions with any
 * future `ANPA_Socios_<Other_Page>` page renderer.
 *
 * @since 1.0.0
 */
class ANPA_Socios_Socios_Page {

	/**
	 * Shortcode callback. Renders the 3-step form HTML.
	 *
	 * The form's `data-anpasocio-url` attribute carries the
	 * REST URL of the crear-socio endpoint; the JS reads it
	 * once at init time.
	 *
	 * @since  1.0.0
	 * @param  array $atts Shortcode attributes (none used).
	 * @return string     The form HTML.
	 */
	public static function render( $atts ): string {
		// The alta form now lives ON the unified socios page so that alta and
		// login share a single, simple flow. This shortcode renders only a CTA
		// button that sends the visitor to that page. The full form markup is
		// available via render_alta_form() (embedded by the unified page).
		$unified_url = ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area' );
		if ( '' === (string) $unified_url ) {
			$unified_url = ANPA_Socios_Admin_Settings::landing_page_url();
		}
		if ( '' === (string) $unified_url ) {
			$unified_url = home_url( '/socios/' );
		}

		ob_start();
		?>
		<div class="anpa-area-access anpa-alta-cta">
			<p class="anpa-area-access-title"><?php echo esc_html( sprintf( __( 'Facerte socio/a da %s', 'anpa-socios' ), ANPA_Socios_Config::association_name() ) ); ?></p>
			<p><?php esc_html_e( 'A alta e o inicio de sesión fanse desde a Área de socios. Preme o botón para comezar: introduces o teu correo, recibes un código de verificación e completas a alta na mesma páxina.', 'anpa-socios' ); ?></p>
			<a class="anpa-area-access-btn" href="<?php echo esc_url( $unified_url ); ?>"><?php esc_html_e( 'Ir á Área de socios', 'anpa-socios' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the full alta form markup (email → codigo → datos → ok).
	 *
	 * Embedded by the unified socios page so the entire alta happens on one
	 * page, driven by unified.js + asociarse.js (window.AnpaAlta.initAltaForm).
	 *
	 * @since  1.27.0
	 * @return string The form HTML.
	 */
	public static function render_alta_form(): string {
		$alta_url             = rest_url( 'anpa-socios/v1/alta' );
		$solicitar_codigo_url = rest_url( 'anpa-socios/v1/solicitar-codigo-alta' );
		$preflight_url        = rest_url( 'anpa-socios/v1/area/preflight' );
		$area_page_url        = ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area_persoal' );
		$unified_page_url     = ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area' );

		// Configurable association identity (generic, multi-tenant).
		$assoc        = esc_html( ANPA_Socios_Config::association_name() );
		$fee          = esc_html( ANPA_Socios_Config::membership_fee() );
		$assoc_addr   = trim( ANPA_Socios_Config::association_address() );

		ob_start();
		?>
		<form id="anpa-asociarse"
		      data-anpasocio-alta-url="<?php echo esc_attr( $alta_url ); ?>"
		      data-anpasocio-request-url="<?php echo esc_attr( $solicitar_codigo_url ); ?>"
		      data-anpasocio-preflight-url="<?php echo esc_attr( $preflight_url ); ?>"
		      data-anpasocio-area-url="<?php echo esc_attr( $area_page_url ); ?>"
		      data-anpasocio-unified-url="<?php echo esc_attr( $unified_page_url ); ?>"
		      data-anpasocio-referencias-url="<?php echo esc_attr( rest_url( 'anpa-socios/v1/area/referencias' ) ); ?>">
			<button type="button" class="wp-element-button anpa-action-danger anpa-alta-logout has-vivid-red-background-color has-background has-white-color has-text-color" data-alta-logout hidden><?php esc_html_e( 'Pechar sesión', 'anpa-socios' ); ?></button>
			<div data-step="email">
				<div class="anpa-alta-emailbox">
					<h2><?php esc_html_e( 'Facerme socio/a', 'anpa-socios' ); ?></h2>
					<p><?php esc_html_e( 'Introduce o teu email para comezar a alta na ANPA. Enviarémosche un código de verificación para continuar.', 'anpa-socios' ); ?></p>
					<label for="anpa-email"><?php esc_html_e( 'Email', 'anpa-socios' ); ?></label>
					<input id="anpa-email" type="email" name="email" required>
					<div style="position:absolute;left:-9999px" aria-hidden="true">
						<input type="text" name="website" tabindex="-1" autocomplete="off" value="">
					</div>
					<input type="hidden" name="_ts" value="<?php echo (int) time(); ?>">
					<button type="submit" class="wp-element-button"><?php esc_html_e( 'Enviar código', 'anpa-socios' ); ?></button>
					<p class="anpa-bridge" data-anpasocio-bridge hidden></p>
				</div>
			</div>
			<div data-step="codigo" hidden>
				<label for="anpa-codigo"><?php esc_html_e( 'Código de 6 díxitos', 'anpa-socios' ); ?></label>
				<input id="anpa-codigo" type="text" name="codigo" maxlength="6" required>
				<button type="submit" class="wp-element-button"><?php esc_html_e( 'Verificar', 'anpa-socios' ); ?></button>
			</div>
			<div data-step="datos" hidden>
				<fieldset class="anpa-fieldset">
					<legend><?php esc_html_e( 'Os teus datos', 'anpa-socios' ); ?></legend>
					<div class="anpa-parent-grupo">
						<div class="anpa-parent-field">
							<label for="anpa-nome"><?php esc_html_e( 'Nome', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
							<input id="anpa-nome" type="text" name="nome" autocomplete="given-name" required data-validate="nome" data-error-key="p1_nome">
							<span class="anpa-field-error" data-error="p1_nome" hidden></span>
						</div>
						<div class="anpa-parent-field">
							<label for="anpa-apelidos"><?php esc_html_e( 'Apelidos', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
							<input id="anpa-apelidos" type="text" name="apelidos" autocomplete="family-name" required data-validate="apelidos" data-error-key="p1_apelidos">
							<span class="anpa-field-error" data-error="p1_apelidos" hidden></span>
						</div>
					</div>
					<div class="anpa-parent-grupo">
						<div class="anpa-parent-field">
							<label for="anpa-telefono"><?php esc_html_e( 'Teléfono de contacto', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
							<input id="anpa-telefono" type="tel" name="telefono" autocomplete="tel" required data-validate="telefono" data-error-key="p1_telefono">
							<span class="anpa-field-error" data-error="p1_telefono" hidden></span>
						</div>
						<div class="anpa-parent-field">
							<label for="anpa-nif"><?php esc_html_e( 'NIF / NIE', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
							<input id="anpa-nif" type="text" name="nif" autocomplete="off" required data-validate="nif" data-error-key="p1_nif">
							<span class="anpa-field-error" data-error="p1_nif" hidden></span>
						</div>
					</div>
				</fieldset>

				<fieldset class="anpa-fieldset">
					<legend><?php esc_html_e( 'Outro/a proxenitor/a (opcional)', 'anpa-socios' ); ?></legend>
					<p class="anpa-muted"><?php esc_html_e( 'Se a outra persoa proxenitora tamén quere ser socia, completa os seus datos. Deixa baleiro se non aplica.', 'anpa-socios' ); ?></p>
					<div class="anpa-parent-grupo">
						<div class="anpa-parent-field">
							<label for="anpa-p2-nome"><?php esc_html_e( 'Nome', 'anpa-socios' ); ?></label>
							<input id="anpa-p2-nome" type="text" autocomplete="off" data-validate="p2_nome">
							<span class="anpa-field-error" data-error="p2_nome" hidden></span>
						</div>
						<div class="anpa-parent-field">
							<label for="anpa-p2-apelidos"><?php esc_html_e( 'Apelidos', 'anpa-socios' ); ?></label>
							<input id="anpa-p2-apelidos" type="text" autocomplete="off" data-validate="p2_apelidos">
							<span class="anpa-field-error" data-error="p2_apelidos" hidden></span>
						</div>
					</div>
					<div class="anpa-parent-grupo">
						<div class="anpa-parent-field">
							<label for="anpa-p2-telefono"><?php esc_html_e( 'Teléfono', 'anpa-socios' ); ?></label>
							<input id="anpa-p2-telefono" type="tel" autocomplete="off" data-validate="p2_telefono">
							<span class="anpa-field-error" data-error="p2_telefono" hidden></span>
						</div>
						<div class="anpa-parent-field">
							<label for="anpa-p2-nif"><?php esc_html_e( 'NIF / NIE', 'anpa-socios' ); ?></label>
							<input id="anpa-p2-nif" type="text" autocomplete="off" data-validate="p2_nif">
							<span class="anpa-field-error" data-error="p2_nif" hidden></span>
						</div>
					</div>
					<div class="anpa-parent-field anpa-parent-field-wide">
						<label for="anpa-p2-email"><?php esc_html_e( 'Email', 'anpa-socios' ); ?></label>
						<input id="anpa-p2-email" type="email" autocomplete="off" data-validate="p2_email">
						<span class="anpa-field-error" data-error="p2_email" hidden></span>
					</div>
				</fieldset>

				<fieldset class="anpa-fieldset">
					<legend><?php esc_html_e( 'Fillos/as', 'anpa-socios' ); ?></legend>
					<p class="anpa-muted"><?php esc_html_e( 'Engade os teus fillos/as. Completa os datos de cada un e preme «Novo fillo» para engadir outro, ou «Quitar» para eliminar un. O curso e o grupo escóllense das listas. Cando remates, preme «Completar alta».', 'anpa-socios' ); ?></p>
					<p class="anpa-consent-text"><?php echo sprintf( __( '<strong>AUTORIZO á %s á toma de imaxes</strong> (fotos ou vídeos) nas que apareza o meu fillo/a. En ningún caso a asociación divulgará as imaxes e vídeos de forma pública. Marca a casa de cada fillo/a para autorizalo.', 'anpa-socios' ), $assoc ); ?></p>
					<div data-fillos-container></div>
					<div class="anpa-fillo-toolbar">
						<button type="button" class="wp-element-button anpa-fillo-novo-unico" data-fillo-novo-unico><?php esc_html_e( 'Novo fillo', 'anpa-socios' ); ?></button>
					</div>
				</fieldset>

				<fieldset class="anpa-fieldset">
					<legend><?php esc_html_e( 'Datos bancarios (domiciliación)', 'anpa-socios' ); ?></legend>
					<p class="anpa-muted"><?php echo sprintf( esc_html__( 'A alta como socio/a implica domiciliación bancaria. A cota é de %s € por familia e curso. Os datos bancarios gárdanse cifrados e só os ve a directiva.', 'anpa-socios' ), $fee ); ?></p>
					<p class="anpa-muted"><?php esc_html_e( 'A baixa como socio/a debe solicitarse desde a área persoal e será efectiva a fin de curso, tras a confirmación da directiva. A cota anual do curso xa xerada non se devolve, aínda que se solicite a baixa durante o ano.', 'anpa-socios' ); ?></p>
					<div class="anpa-rgpd-text"><?php esc_html_e( 'Mediante a presente orde de domiciliación o debedor autoriza (A) ao acredor a enviar instrucións á entidade do debedor para cargar na súa conta e (B) á entidade para efectuar os cargos na súa conta seguindo as instrucións do acredor. Como parte dos seus dereitos, o debedor está lexitimado ao reembolso pola súa entidade nos termos e condicións do contrato subscrito con ela. A solicitude de reembolso deberá efectuarse dentro das oito semanas seguintes á data de cargo en conta. Pode obter información adicional sobre os seus dereitos na súa entidade bancaria.', 'anpa-socios' ); ?></div>
					<label for="anpa-sepa-titular-nome"><?php esc_html_e( 'Nome do titular da conta', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
					<input id="anpa-sepa-titular-nome" type="text" autocomplete="off" required data-validate="sepa_titular_nome" placeholder="<?php esc_attr_e( 'Copiarase do proxenitor/a 1', 'anpa-socios' ); ?>">
					<span class="anpa-field-error" data-error="sepa_titular_nome" hidden></span>
					<label for="anpa-sepa-titular-apelidos"><?php esc_html_e( 'Apelidos do titular', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
					<input id="anpa-sepa-titular-apelidos" type="text" autocomplete="off" required data-validate="sepa_titular_apelidos" placeholder="<?php esc_attr_e( 'Copiarase do proxenitor/a 1', 'anpa-socios' ); ?>">
					<span class="anpa-field-error" data-error="sepa_titular_apelidos" hidden></span>
					<label for="anpa-sepa-nif"><?php esc_html_e( 'NIF/NIE do titular', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
					<input id="anpa-sepa-nif" type="text" autocomplete="off" required data-validate="sepa_titular_nif" placeholder="<?php esc_attr_e( 'Copiarase do proxenitor/a 1', 'anpa-socios' ); ?>">
					<span class="anpa-field-error" data-error="sepa_titular_nif" hidden></span>
					<label for="anpa-sepa-enderezo"><?php esc_html_e( 'Enderezo', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
					<input id="anpa-sepa-enderezo" type="text" autocomplete="off" required data-validate="sepa_enderezo">
					<span class="anpa-field-error" data-error="sepa_enderezo" hidden></span>
					<label for="anpa-sepa-provincia"><?php esc_html_e( 'Provincia', 'anpa-socios' ); ?></label>
					<input id="anpa-sepa-provincia" type="text" autocomplete="address-level1" data-validate="sepa_provincia" value="<?php echo esc_attr( ANPA_Socios_Config::default_province() ); ?>">
					<span class="anpa-field-error" data-error="sepa_provincia" hidden></span>
					<label for="anpa-sepa-poboacion"><?php esc_html_e( 'Poboación', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
					<input id="anpa-sepa-poboacion" type="text" autocomplete="address-level2" required data-validate="sepa_poboacion" value="<?php echo esc_attr( ANPA_Socios_Config::default_town() ); ?>">
					<span class="anpa-field-error" data-error="sepa_poboacion" hidden></span>
					<label for="anpa-sepa-cp"><?php esc_html_e( 'Código postal', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
					<input id="anpa-sepa-cp" type="text" inputmode="numeric" maxlength="5" autocomplete="off" required data-validate="sepa_cp" value="<?php echo esc_attr( ANPA_Socios_Config::default_postal_code() ); ?>">
					<span class="anpa-field-error" data-error="sepa_cp" hidden></span>
					<label for="anpa-sepa-entidade"><?php esc_html_e( 'Nome da entidade bancaria', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
					<input id="anpa-sepa-entidade" type="text" autocomplete="off" required data-validate="sepa_entidade">
					<span class="anpa-field-error" data-error="sepa_entidade" hidden></span>
					<label for="anpa-sepa-iban"><?php esc_html_e( 'IBAN', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
					<input id="anpa-sepa-iban" type="text" autocomplete="off" required data-validate="sepa_iban">
					<span class="anpa-field-error" data-error="sepa_iban" hidden></span>
					<label for="anpa-sepa-lugar"><?php esc_html_e( 'Lugar e data', 'anpa-socios' ); ?></label>
					<input id="anpa-sepa-lugar" type="text" autocomplete="off" readonly>
					<label class="anpa-check"><input type="checkbox" id="anpa-sepa-autorizo" required> <?php esc_html_e( 'Autorizo a domiciliación bancaria', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
				</fieldset>

				<fieldset class="anpa-fieldset">
					<legend><?php esc_html_e( 'Protección de datos de carácter persoal', 'anpa-socios' ); ?></legend>
					<div class="anpa-rgpd-text"><?php echo sprintf( __( 'De conformidade co Reglamento (UE) 2016/679 de 27 de Abril (RGPD), os datos suministrados para a solicitude de alta quedarán incorporados nun ficheiro con titularidade da %1$s, sendo utilizados exclusivamente por esta asociación para a prestación dos seus servizos. Estes datos recolleranse a través dos correspondentes formularios, os cales só conterán os campos imprescindibles para poder prestar o servizo solicitado. Os datos de carácter persoal serán tratados co grao de protección adecuado para evitar a súa alteración, perda, tratamento ou acceso non autorizado por parte de terceiros que os poidan utilizar para finalidades distintas daquelas para as que foron recabados. Pode exercer os seus dereitos de acceso, rectificación, cancelación e oposición, en cumprimento co establecido na RGPD, ante a %1$s%2$s.', 'anpa-socios' ), $assoc, '' !== $assoc_addr ? ' na seguinte dirección: ' . esc_html( $assoc_addr ) : '' ); ?></div>
					<label class="anpa-check"><input type="checkbox" id="anpa-rgpd" required> <?php esc_html_e( 'Acepto a política de protección de datos', 'anpa-socios' ); ?> <span class="anpa-required has-vivid-red-color">*</span></label>
				</fieldset>

				<div class="anpa-alta-submit-actions">
					<button type="submit" class="wp-element-button"><?php esc_html_e( 'Completar alta', 'anpa-socios' ); ?></button>
				</div>
			</div>
			<div data-step="ok" hidden>
				<p><?php esc_html_e( 'Alta completada. Grazas.', 'anpa-socios' ); ?></p>
			</div>
			<div data-step="error" hidden>
				<p data-anpasocio-error></p>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueues the JS+CSS assets on pages that use the
	 * shortcode. The check is two-step: `is_singular()` and
	 * `has_shortcode($post->post_content, …)`.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		global $post;
		if ( ! ( $post instanceof WP_Post )
			|| ! has_shortcode( (string) $post->post_content, 'anpa_socios_asociarse' ) ) {
			return;
		}
		$js_path     = ANPA_SOCIOS_PLUGIN_DIR . 'assets/js/asociarse.js';
		$css_path    = ANPA_SOCIOS_PLUGIN_DIR . 'assets/css/asociarse.css';
		$js_version  = file_exists( $js_path ) ? (int) filemtime( $js_path ) : ANPA_SOCIOS_VERSION;
		$css_version = file_exists( $css_path ) ? (int) filemtime( $css_path ) : ANPA_SOCIOS_VERSION;

		wp_enqueue_script(
			'anpa-socios-asociarse',
			plugins_url( 'assets/js/asociarse.js', ANPA_SOCIOS_PLUGIN_FILE ),
			array( 'wp-i18n' ),
			$js_version,
			true
		);
		wp_set_script_translations( 'anpa-socios-asociarse', 'anpa-socios', ANPA_SOCIOS_PLUGIN_DIR . 'languages' );
		wp_enqueue_style(
			'anpa-socios-asociarse',
			plugins_url( 'assets/css/asociarse.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$css_version
		);
	}
}
