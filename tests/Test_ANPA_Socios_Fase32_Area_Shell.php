<?php
/**
 * Fase 32: the socio area shell gains a persistent navigation bar and a welcome
 * "panel" entry step (extraescolares-first), replacing the old <details>
 * dropdown. This locks the non-regression inventory (all previous steps still
 * exist) and the new nav/panel contract. Source-inspection style.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Fase32_Area_Shell extends TestCase {

	private string $php;
	private string $js;

	public function setUp(): void {
		parent::setUp();
		$root      = dirname( __DIR__ );
		$this->php = (string) file_get_contents( $root . '/includes/class-anpa-socios-area-page.php' );
		$this->js  = (string) file_get_contents( $root . '/assets/js/area.js' );
	}

	public function test_persistent_nav_replaces_dropdown(): void {
		$this->assertStringContainsString( 'data-area-nav', $this->php );
		// The old <details> dropdown menu is gone as the navigation mechanism.
		$this->assertStringNotContainsString( 'anpa-session-menu', $this->php );
		foreach ( array( 'panel', 'extraescolares', 'fillos', 'profile', 'banking' ) as $nav ) {
			$this->assertStringContainsString( 'data-nav="' . $nav . '"', $this->php );
		}
		// The session indicator + logout stay reachable in the nav.
		$this->assertStringContainsString( 'data-session-email', $this->php );
		$this->assertStringContainsString( 'data-action="header-logout"', $this->php );
	}

	public function test_welcome_panel_step_exists(): void {
		$this->assertStringContainsString( 'data-step="panel"', $this->php );
		$this->assertStringContainsString( 'data-panel-saudo', $this->php );
		$this->assertStringContainsString( 'data-panel-matriculas', $this->php );
	}

	public function test_all_previous_steps_preserved(): void {
		foreach ( array( 'email', 'code', 'alta', 'inactivo', 'profile', 'banking', 'fillos', 'extraescolares', 'empresa' ) as $step ) {
			$this->assertStringContainsString( 'data-step="' . $step . '"', $this->php );
		}
	}

	public function test_entry_shows_panel_and_js_wires_navigation(): void {
		// Entry (verify-code + session restore) lands on the panel, not profile.
		$this->assertStringContainsString( "showStep(root, 'panel')", $this->js );
		$this->assertStringContainsString( 'function renderPanel(', $this->js );
		$this->assertStringContainsString( 'function navigateArea(', $this->js );
		$this->assertStringContainsString( 'function setActiveNav(', $this->js );
		// Navigation is wired via data-nav.
		$this->assertStringContainsString( "querySelectorAll('[data-nav]')", $this->js );
	}

	public function test_redundant_back_buttons_removed(): void {
		// The persistent nav replaces the per-card "Volver" buttons; they are gone.
		$this->assertStringNotContainsString( 'data-action="back-profile"', $this->php );
		$this->assertStringNotContainsString( 'data-action="extra-back"', $this->php );
		$this->assertStringNotContainsString( 'Volver aos meus datos', $this->php );
		$this->assertStringNotContainsString( 'Volver aos teus datos', $this->php );
	}

	public function test_reparar_curso_removed(): void {
		// "Reparar curso" added nothing the socio couldn't do directly: fully removed
		// (button, JS handler, page wiring and REST route).
		$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-area-rest.php' );
		$this->assertStringNotContainsString( 'Reparar curso', $this->js );
		$this->assertStringNotContainsString( 'filloRepararUrl', $this->js );
		$this->assertStringNotContainsString( 'data-fillo-reparar-url', $this->php );
		$this->assertStringNotContainsString( 'reparar-curso', $rest );
		$this->assertStringNotContainsString( 'handle_reparar_curso', $rest );
	}

	public function test_banking_card_focused_on_iban(): void {
		// The IBAN card drops the unused SEPA fields and prefills only non-encrypted
		// data; encrypted IBAN/NIF are re-entered (masked reference shown).
		$this->assertStringContainsString( 'data-step="banking"', $this->php );
		$this->assertStringContainsString( 'data-nif-mask', $this->php );
		$this->assertStringContainsString( 'data-iban-mask', $this->php );
		// The provincia field (unused by validar_sepa_opcional) is gone.
		$this->assertStringNotContainsString( 'anpa-bank-provincia', $this->php );
	}

	public function test_banking_prefills_holder_from_profile(): void {
		// The account holder defaults to the socio's own known (non-encrypted)
		// data so the IBAN form is never blank for a socio without a saved
		// domiciliación; a saved banking record overrides it.
		$this->assertStringContainsString( 'var pNome', $this->js );
		$this->assertStringContainsString( 'var hasBanking', $this->js );
		$this->assertStringContainsString( "(hasBanking && banking.titular_nome) ? banking.titular_nome : pNome", $this->js );
		// The socio's own non-encrypted NIF is used as the holder default.
		$this->assertStringContainsString( 'var pNif', $this->js );
		$this->assertStringContainsString( "setVal('anpa-bank-titular-nif', pNif)", $this->js );
	}

	public function test_socios_profile_query_does_not_select_domiciliacion_only_columns(): void {
		// enderezo/poboacion/codigo_postal live ONLY on the domiciliacions table,
		// not on wp_anpa_socios. Selecting them in get_active_profile_by_email
		// caused an "Unknown column" DB error that broke every area login.
		$rest  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-area-rest.php' );
		$start = strpos( $rest, 'function get_active_profile_by_email' );
		$this->assertNotFalse( $start );
		$end    = strpos( $rest, 'LIMIT 1', $start );
		$select = substr( $rest, $start, $end - $start );
		$this->assertStringNotContainsString( 'enderezo', $select );
		$this->assertStringNotContainsString( 'poboacion', $select );
		$this->assertStringNotContainsString( 'codigo_postal', $select );
	}
}
