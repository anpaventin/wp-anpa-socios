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
		$unified_url = ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area_unified' );
		if ( '' === (string) $unified_url ) {
			$unified_url = ANPA_Socios_Admin_Settings::landing_page_url();
		}
		if ( '' === (string) $unified_url ) {
			$unified_url = home_url( '/socios/' );
		}

		ob_start();
		?>
		<div class="anpa-area-access anpa-alta-cta">
			<p class="anpa-area-access-title">Facerte socio/a da <?php echo esc_html( ANPA_Socios_Config::association_name() ); ?></p>
			<p>A alta e o inicio de sesión fanse desde a Área de socios. Preme o botón para comezar: introduces o teu correo, recibes un código de verificación e completas a alta na mesma páxina.</p>
			<a class="anpa-area-access-btn" href="<?php echo esc_url( $unified_url ); ?>">Ir á Área de socios</a>
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
		$area_page_url        = ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area' );
		$unified_page_url     = ANPA_Socios_Hub_Page::find_page_url( 'anpa_socios_area_unified' );

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
			<div data-step="email">
				<div class="anpa-alta-emailbox">
					<h2>Facerme socio/a</h2>
					<p>Introduce o teu email para comezar a alta na ANPA. Enviarémosche un código de verificación para continuar.</p>
					<label for="anpa-email">Email</label>
					<input id="anpa-email" type="email" name="email" required>
					<div style="position:absolute;left:-9999px" aria-hidden="true">
						<input type="text" name="website" tabindex="-1" autocomplete="off" value="">
					</div>
					<input type="hidden" name="_ts" value="<?php echo (int) time(); ?>">
					<button type="submit">Enviar código</button>
					<p class="anpa-bridge" data-anpasocio-bridge hidden></p>
				</div>
			</div>
			<div data-step="codigo" hidden>
				<label for="anpa-codigo">Código de 6 díxitos</label>
				<input id="anpa-codigo" type="text" name="codigo" maxlength="6" required>
				<button type="submit">Verificar</button>
			</div>
			<div data-step="datos" hidden>
				<fieldset class="anpa-fieldset">
					<legend>Os teus datos</legend>
					<label for="anpa-nome">Nome *</label>
					<input id="anpa-nome" type="text" name="nome" autocomplete="given-name" required data-validate="nome">
					<span class="anpa-field-error" data-error="p1_nome" hidden></span>
					<label for="anpa-apelidos">Apelidos *</label>
					<input id="anpa-apelidos" type="text" name="apelidos" autocomplete="family-name" required data-validate="apelidos">
					<span class="anpa-field-error" data-error="p1_apelidos" hidden></span>
					<label for="anpa-telefono">Teléfono de contacto *</label>
					<input id="anpa-telefono" type="tel" name="telefono" autocomplete="tel" required data-validate="telefono">
					<span class="anpa-field-error" data-error="p1_telefono" hidden></span>
					<label for="anpa-nif">NIF / NIE *</label>
					<input id="anpa-nif" type="text" name="nif" autocomplete="off" required data-validate="nif">
					<span class="anpa-field-error" data-error="p1_nif" hidden></span>
				</fieldset>

				<fieldset class="anpa-fieldset">
					<legend>Outro/a proxenitor/a (opcional)</legend>
					<p class="anpa-muted">Se a outra persoa proxenitora tamén quere ser socia, completa os seus datos. Deixa baleiro se non aplica.</p>
					<label for="anpa-p2-nome">Nome</label>
					<input id="anpa-p2-nome" type="text" autocomplete="off" data-validate="p2_nome">
					<span class="anpa-field-error" data-error="p2_nome" hidden></span>
					<label for="anpa-p2-apelidos">Apelidos</label>
					<input id="anpa-p2-apelidos" type="text" autocomplete="off" data-validate="p2_apelidos">
					<span class="anpa-field-error" data-error="p2_apelidos" hidden></span>
					<label for="anpa-p2-nif">NIF / NIE</label>
					<input id="anpa-p2-nif" type="text" autocomplete="off" data-validate="p2_nif">
					<span class="anpa-field-error" data-error="p2_nif" hidden></span>
					<label for="anpa-p2-email">Email</label>
					<input id="anpa-p2-email" type="email" autocomplete="off" data-validate="p2_email">
					<span class="anpa-field-error" data-error="p2_email" hidden></span>
					<label for="anpa-p2-telefono">Teléfono</label>
					<input id="anpa-p2-telefono" type="tel" autocomplete="off" data-validate="p2_telefono">
					<span class="anpa-field-error" data-error="p2_telefono" hidden></span>
				</fieldset>

				<fieldset class="anpa-fieldset">
					<legend>Fillos e fillas</legend>
					<p class="anpa-muted">Engade os teus fillos/as. Completa os datos e preme «Gardar fillo/a»; despois poderás engadir outro ou modificar/quitar os xa gardados. O curso e o grupo escóllense das listas.</p>
					<p class="anpa-consent-text">AUTORIZO á <?php echo $assoc; ?> á toma de imaxes (fotos ou vídeos) nas que apareza o meu fillo/a. En ningún caso a asociación divulgará as imaxes e vídeos de forma pública. Marca a casa de cada fillo/a para autorizalo.</p>
					<div data-fillos-container></div>
				</fieldset>

				<fieldset class="anpa-fieldset">
					<legend>Datos bancarios (domiciliación)</legend>
					<p class="anpa-muted">A alta como socio/a implica domiciliación bancaria. A cota é de <?php echo $fee; ?> € por familia e curso. Os datos bancarios gárdanse cifrados e só os ve a directiva.</p>
					<p class="anpa-muted">A baixa como socio/a debe solicitarse desde a área persoal e será efectiva a fin de curso, tras a confirmación da directiva. A cota anual do curso xa xerada non se devolve, aínda que se solicite a baixa durante o ano.</p>
					<div class="anpa-rgpd-text">Mediante a presente orde de domiciliación o debedor autoriza (A) ao acredor a enviar instrucións á entidade do debedor para cargar na súa conta e (B) á entidade para efectuar os cargos na súa conta seguindo as instrucións do acredor. Como parte dos seus dereitos, o debedor está lexitimado ao reembolso pola súa entidade nos termos e condicións do contrato subscrito con ela. A solicitude de reembolso deberá efectuarse dentro das oito semanas seguintes á data de cargo en conta. Pode obter información adicional sobre os seus dereitos na súa entidade bancaria.</div>
					<label for="anpa-sepa-titular-nome">Nome do titular da conta</label>
					<input id="anpa-sepa-titular-nome" type="text" autocomplete="off" data-validate="sepa_titular_nome" placeholder="Copiarase do proxenitor 1">
					<span class="anpa-field-error" data-error="sepa_titular_nome" hidden></span>
					<label for="anpa-sepa-titular-apelidos">Apelidos do titular</label>
					<input id="anpa-sepa-titular-apelidos" type="text" autocomplete="off" data-validate="sepa_titular_apelidos" placeholder="Copiarase do proxenitor 1">
					<span class="anpa-field-error" data-error="sepa_titular_apelidos" hidden></span>
					<label for="anpa-sepa-nif">NIF/NIE do titular</label>
					<input id="anpa-sepa-nif" type="text" autocomplete="off" data-validate="sepa_titular_nif" placeholder="Copiarase do proxenitor 1">
					<span class="anpa-field-error" data-error="sepa_titular_nif" hidden></span>
					<label for="anpa-sepa-enderezo">Enderezo</label>
					<input id="anpa-sepa-enderezo" type="text" autocomplete="off" data-validate="sepa_enderezo">
					<span class="anpa-field-error" data-error="sepa_enderezo" hidden></span>
					<label for="anpa-sepa-provincia">Provincia</label>
					<input id="anpa-sepa-provincia" type="text" autocomplete="address-level1" data-validate="sepa_provincia" value="<?php echo esc_attr( ANPA_Socios_Config::default_province() ); ?>">
					<span class="anpa-field-error" data-error="sepa_provincia" hidden></span>
					<label for="anpa-sepa-poboacion">Poboación</label>
					<input id="anpa-sepa-poboacion" type="text" autocomplete="address-level2" data-validate="sepa_poboacion" value="<?php echo esc_attr( ANPA_Socios_Config::default_town() ); ?>">
					<span class="anpa-field-error" data-error="sepa_poboacion" hidden></span>
					<label for="anpa-sepa-cp">Código postal</label>
					<input id="anpa-sepa-cp" type="text" inputmode="numeric" maxlength="5" autocomplete="off" data-validate="sepa_cp">
					<span class="anpa-field-error" data-error="sepa_cp" hidden></span>
					<label for="anpa-sepa-entidade">Nome da entidade bancaria</label>
					<input id="anpa-sepa-entidade" type="text" autocomplete="off" data-validate="sepa_entidade">
					<span class="anpa-field-error" data-error="sepa_entidade" hidden></span>
					<label for="anpa-sepa-iban">IBAN</label>
					<input id="anpa-sepa-iban" type="text" autocomplete="off" data-validate="sepa_iban">
					<span class="anpa-field-error" data-error="sepa_iban" hidden></span>
					<label for="anpa-sepa-lugar">Lugar e data</label>
					<input id="anpa-sepa-lugar" type="text" autocomplete="off" readonly>
					<label class="anpa-check"><input type="checkbox" id="anpa-sepa-autorizo"> Autorizo a domiciliación bancaria</label>
				</fieldset>

				<fieldset class="anpa-fieldset">
					<legend>Protección de datos de carácter persoal</legend>
					<div class="anpa-rgpd-text">De conformidade co Reglamento (UE) 2016/679 de 27 de Abril (RGPD), os datos suministrados para a solicitude de alta quedarán incorporados nun ficheiro con titularidade da <?php echo $assoc; ?>, sendo utilizados exclusivamente por esta asociación para a prestación dos seus servizos. Estes datos recolleranse a través dos correspondentes formularios, os cales só conterán os campos imprescindibles para poder prestar o servizo solicitado. Os datos de carácter persoal serán tratados co grao de protección adecuado para evitar a súa alteración, perda, tratamento ou acceso non autorizado por parte de terceiros que os poidan utilizar para finalidades distintas daquelas para as que foron recabados. Pode exercer os seus dereitos de acceso, rectificación, cancelación e oposición, en cumprimento co establecido na RGPD, ante a <?php echo $assoc; ?><?php echo '' !== $assoc_addr ? ' na seguinte dirección: ' . esc_html( $assoc_addr ) : ''; ?>.</div>
					<label class="anpa-check"><input type="checkbox" id="anpa-rgpd" required> Acepto a política de protección de datos *</label>
				</fieldset>

				<button type="submit">Completar alta</button>
			</div>
			<div data-step="ok" hidden>
				<p>Alta completada. Grazas.</p>
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
			array(),
			$js_version,
			true
		);
		wp_enqueue_style(
			'anpa-socios-asociarse',
			plugins_url( 'assets/css/asociarse.css', ANPA_SOCIOS_PLUGIN_FILE ),
			array(),
			$css_version
		);
	}
}
