<?php
/**
 * Source contracts for Fase 19 single-page member area.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Single_Page extends TestCase {

	private string $unified_page;
	private string $area_page;
	private string $area_js;
	private string $unified_js;
	private string $asociarse_js;
	private string $asociarse_css;
	private string $alta_page;
	private string $rest;
	private string $readme;
	private string $admin_settings;
	private string $hub_page;

	protected function setUp(): void {
		$root               = dirname( __DIR__ );
		$this->unified_page = (string) file_get_contents( $root . '/includes/class-anpa-socios-unified-page.php' );
		$this->area_page    = (string) file_get_contents( $root . '/includes/class-anpa-socios-area-page.php' );
		$this->area_js      = (string) file_get_contents( $root . '/assets/js/area.js' );
		$this->unified_js   = (string) file_get_contents( $root . '/assets/js/unified.js' );
		$this->asociarse_js = (string) file_get_contents( $root . '/assets/js/asociarse.js' );
		$this->asociarse_css = (string) file_get_contents( $root . '/assets/css/asociarse.css' );
		$this->alta_page     = (string) file_get_contents( $root . '/includes/class-anpa-socios-page.php' );
		$this->rest         = (string) file_get_contents( $root . '/includes/class-anpa-socios-rest.php' );
		$this->readme       = (string) file_get_contents( $root . '/README.md' );
		$this->admin_settings = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-settings.php' );
		$this->hub_page       = (string) file_get_contents( $root . '/includes/class-anpa-socios-hub-page.php' );
	}

	public function test_unified_page_embeds_shared_area_shell(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Area_Page::render_embedded()', $this->unified_page );
		$this->assertStringContainsString( 'public static function render_embedded(): string', $this->area_page );
		$this->assertStringContainsString( 'data-auto-init="<?php echo $embedded ? \'0\' : \'1\'; ?>"', $this->area_page );
	}

	public function test_unified_page_loads_area_assets(): void {
		$this->assertStringContainsString( "assets/js/area.js", $this->unified_page );
		$this->assertStringContainsString( "'anpa-socios-area'", $this->unified_page );
		$this->assertStringContainsString( "assets/css/area.css", $this->unified_page );
	}

	public function test_area_exposes_idempotent_manual_init(): void {
		$this->assertStringContainsString( 'window.AnpaArea = {', $this->area_js );
		$this->assertStringContainsString( 'init: init', $this->area_js );
		$this->assertStringContainsString( 'new WeakSet()', $this->area_js );
		$this->assertStringContainsString( "root.dataset.autoInit === '0'", $this->area_js );
		$this->assertStringContainsString( 'return root.anpaRestoreSession()', $this->area_js );
		$this->assertStringContainsString( 'root.anpaInitPromise = null', $this->area_js );
	}

	public function test_unified_flow_opens_area_inline(): void {
		$this->assertStringContainsString( 'function openAreaInline(cfg)', $this->unified_js );
		$this->assertStringContainsString( 'window.AnpaArea.init(areaRoot)', $this->unified_js );
		$this->assertStringContainsString( 'await openAreaInline(cfg)', $this->unified_js );
		$this->assertStringContainsString( 'redirectToLegacyArea(cfg)', $this->unified_js );
		$this->assertStringContainsString( 'if (!cfg.areaPageUrl)', $this->unified_js );
		$this->assertStringContainsString( 'Legacy area unavailable', $this->unified_js );
	}

	public function test_immediate_alta_hands_a_canonical_session_to_inline_area(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Area_REST::issue_session(', $this->rest );
		$this->assertStringContainsString( '$response[\'session_token\'] =', $this->rest );
		$this->assertTrue(
			strpos( $this->rest, 'if ( $needs_approval )' ) < strpos( $this->rest, 'ANPA_Socios_Area_REST::issue_session(' ),
			'Pending approval must return before issuing an area session.'
		);
		$this->assertStringContainsString( 'window.AnpaUnified.openArea()', $this->asociarse_js );
		$this->assertStringContainsString( 'saveSessionToken(result.session_token', $this->asociarse_js );
		$this->assertStringContainsString( 'window.AnpaUnified = {', $this->unified_js );
	}

	public function test_documentation_only_advertises_the_canonical_socios_shortcode(): void {
		$this->assertStringContainsString( '`[anpa_socios_area]` (single canonical', $this->readme );
		$this->assertStringNotContainsString( 'anpa_socios_area_persoal', $this->readme );
		$this->assertStringNotContainsString( 'anpa_socios_asociarse', $this->readme );
		$this->assertStringNotContainsString( 'anpa_socios_area_unified', $this->readme );
		$this->assertStringNotContainsString( '<code>[anpa_socios_area_persoal]</code>', $this->admin_settings );
		$this->assertStringNotContainsString( '<code>[anpa_socios_asociarse]</code>', $this->admin_settings );
		$this->assertStringNotContainsString( '<code>[anpa_socios_area_link]</code>', $this->admin_settings );
		$this->assertSame( 3, substr_count( $this->admin_settings, '<code>[anpa_socios_area]</code>' ) );
	}

	public function test_public_hub_routes_signup_and_login_to_the_canonical_page(): void {
		$this->assertStringNotContainsString( "find_page_url( 'anpa_socios_asociarse' )", $this->hub_page );
		$this->assertStringContainsString( 'href="<?php echo esc_url( $area_url ); ?>"', $this->hub_page );
		$this->assertSame( 3, substr_count( $this->hub_page, 'href="<?php echo esc_url( $area_url ); ?>"' ) );
	}

	public function test_pending_cancellation_uses_verified_login_instead_of_skip_code(): void {
		$this->assertStringContainsString( "next === 'area' || next === 'baixa_pendente'", $this->unified_js );
		$this->assertStringNotContainsString( 'skip_code', $this->unified_js );
		$this->assertStringNotContainsString( 'function initBaixaSolicitada', $this->unified_js );
		$this->assertStringNotContainsString( 'data-step="baixa-solicitada"', $this->unified_page );
	}

	public function test_inline_logout_returns_to_the_canonical_entry_shell(): void {
		$this->assertStringContainsString( 'function showSocioLoggedOut', $this->area_js );
		$this->assertStringContainsString( 'window.AnpaUnified.showEntry', $this->area_js );
		$this->assertStringContainsString( "showEntry: function (message, isError)", $this->unified_js );
		$this->assertStringContainsString( "showSocioLoggedOut(__( 'Sesión pechada.'", $this->area_js );
	}

	public function test_alta_session_restore_and_logout_are_bound_once(): void {
		$this->assertSame( 1, substr_count( $this->asociarse_js, '// ── Restore persisted alta session and bind logout once ──' ) );
		$this->assertSame( 1, substr_count( $this->asociarse_js, "logoutBtn.addEventListener('click'" ) );
		$this->assertStringContainsString( "sessionStorage.setItem( 'anpa_alta_token', options.token )", $this->asociarse_js );
		$this->assertStringContainsString( "sessionStorage.setItem( 'anpa_alta_email', options.email )", $this->asociarse_js );
	}

	public function test_alta_uses_theme_button_classes_without_hardcoded_logout_colour(): void {
		$this->assertStringContainsString( 'wp-element-button anpa-action-danger anpa-alta-logout has-vivid-red-background-color has-background has-white-color has-text-color', $this->alta_page );
		$this->assertStringContainsString( "#anpa-asociarse .anpa-alta-logout {\n	margin-left: auto;", $this->asociarse_css );
		$this->assertStringNotContainsString( 'background: #b42318 !important', $this->asociarse_css );
		$this->assertStringNotContainsString( '!important', $this->asociarse_css );
	}

	public function test_fillo_actions_are_below_rows_and_right_aligned(): void {
		$this->assertTrue(
			strpos( $this->alta_page, '<div data-fillos-container></div>' )
			< strpos( $this->alta_page, 'data-fillo-novo-unico' )
		);
		$this->assertStringContainsString( 'justify-content: flex-end;', $this->asociarse_css );
	}

	public function test_form_layout_is_structural_and_theme_friendly(): void {
		$this->assertStringContainsString( 'flex-direction: column;', $this->asociarse_css );
		$this->assertStringContainsString( '#anpa-asociarse .anpa-fieldset label', $this->asociarse_css );
		$this->assertStringContainsString( '#anpa-asociarse .anpa-fieldset label.anpa-fillo-field', $this->asociarse_css );
		$this->assertStringNotContainsString( 'height: 2.6rem;', $this->asociarse_css );
	}

	public function test_first_empty_fillo_is_validated_with_friendly_field_names(): void {
		$this->assertStringNotContainsString( 'if (empty) { return; }', $this->asociarse_js );
		$this->assertStringContainsString( "nome: __( 'Nome', 'anpa-socios' )", $this->asociarse_js );
		$this->assertStringContainsString( "data_nacemento: __( 'Data de nacemento', 'anpa-socios' )", $this->asociarse_js );
	}

	public function test_required_sepa_fields_and_authorization_are_validated_before_submit(): void {
		foreach ( array( 'anpa-sepa-titular-nome', 'anpa-sepa-titular-apelidos', 'anpa-sepa-nif', 'anpa-sepa-enderezo', 'anpa-sepa-poboacion', 'anpa-sepa-cp', 'anpa-sepa-entidade', 'anpa-sepa-iban', 'anpa-sepa-autorizo' ) as $id ) {
			$this->assertMatchesRegularExpression( '/id="' . preg_quote( $id, '/' ) . '"[^>]*required/', $this->alta_page, $id );
		}
		$this->assertStringContainsString( "if (input.required && !value)", $this->asociarse_js );
		$this->assertStringContainsString( "Debes autorizar a domiciliación bancaria.", $this->asociarse_js );
	}

	public function test_parent1_inline_error_keys_match_the_rendered_spans(): void {
		$php = file_get_contents( __DIR__ . '/../includes/class-anpa-socios-page.php' );
		$js  = file_get_contents( __DIR__ . '/../assets/js/asociarse.js' );

		foreach ( array( 'nome', 'apelidos', 'telefono', 'nif' ) as $field ) {
			$this->assertMatchesRegularExpression(
				'/id="anpa-' . $field . '"[^>]*data-error-key="p1_' . $field . '"/',
				$php,
				$field
			);
			$this->assertDoesNotMatchRegularExpression(
				'/<input[^>]*id="anpa-' . $field . '"[^>]*data-error=/',
				$php,
				$field . ' must never masquerade as an error-message node'
			);
		}
		$this->assertStringContainsString( "input.getAttribute('data-error-key')", $js );
		$this->assertStringContainsString( "'.anpa-field-error[data-error=\"'", $js );
	}

	public function test_logout_purges_sensitive_form_state_and_rebuilds_one_empty_fillo(): void {
		$this->assertStringContainsString( 'form.reset();', $this->asociarse_js );
		$this->assertStringContainsString( "fillosContainer.textContent = '';", $this->asociarse_js );
		$this->assertStringContainsString( 'fillosContainer.appendChild(createFilloRow());', $this->asociarse_js );
		$this->assertStringContainsString( 'clearAllFieldErrors(form);', $this->asociarse_js );
	}

	public function test_parent2_email_does_not_grow_vertically_inside_column_fieldset(): void {
		$this->assertMatchesRegularExpression(
			'/\.anpa-parent-field-wide\s*\{[^}]*flex:\s*0 1 auto;/s',
			$this->asociarse_css
		);
	}

	public function test_complete_alta_action_is_below_and_right_aligned(): void {
		$this->assertStringContainsString( 'class="anpa-alta-submit-actions"', $this->alta_page );
		$this->assertMatchesRegularExpression(
			'/\.anpa-alta-submit-actions\s*\{[^}]*justify-content:\s*flex-end;/s',
			$this->asociarse_css
		);
	}

	public function test_pending_approval_message_uses_structured_emphasis(): void {
		$this->assertStringContainsString( 'function renderPendingApprovalMessage(okCard)', $this->asociarse_js );
		$this->assertStringContainsString( "strong.textContent = __( 'aprobala'", $this->asociarse_js );
		$this->assertStringContainsString( "emailStrong.textContent = __( 'Recibirás un correo'", $this->asociarse_js );
		$this->assertStringContainsString( "approvedStrong.textContent = __( 'aprobada'", $this->asociarse_js );
		$this->assertStringNotContainsString( 'p.textContent = result.message;', $this->asociarse_js );
	}

	public function test_verified_code_attempts_canonical_area_session_before_alta_routing(): void {
		$this->assertStringContainsString( 'async function exchangeVerifiedAreaSession(cfg, verificationToken)', $this->unified_js );
		$this->assertStringContainsString( 'if (!result || result.error || !result.token)', $this->unified_js );
		$exchange = strpos( $this->unified_js, 'await exchangeVerifiedAreaSession(cfg, result.token)' );
		$flow     = strpos( $this->unified_js, "if (flow === 'login')" );
		$this->assertNotFalse( $exchange );
		$this->assertNotFalse( $flow );
		$this->assertLessThan( $flow, $exchange );
	}

	/**
	 * Fase 28 regression: on first entry via the unified flow, restoreSession()
	 * fed fillProfile() the slim /area/session-status payload (email, nome,
	 * apelidos only), so teléfono, NIF, 2º proxenitor and fillos rendered
	 * empty. It must fetch the full profile from /area/me before filling.
	 */
	public function test_restore_session_fills_profile_from_full_area_me_payload(): void {
		$start   = strpos( $this->area_js, 'function restoreSession()' );
		$restore = substr( $this->area_js, $start, strpos( $this->area_js, 'root.anpaRestoreSession = restoreSession' ) - $start );

		$this->assertStringContainsString( 'root.dataset.profileUrl', $restore, 'restoreSession must fetch the full profile from /area/me' );
		$this->assertStringNotContainsString( 'fillProfile(root, status)', $restore, 'restoreSession must not fill the profile from the slim session-status payload' );
	}
}
