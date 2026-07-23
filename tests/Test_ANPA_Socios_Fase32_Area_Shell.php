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
}
