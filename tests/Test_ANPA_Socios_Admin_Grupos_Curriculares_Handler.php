<?php
/**
 * Regression contracts for the revised fase24 architecture.
 *
 * @package ANPA_Socios
 */

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Admin_Grupos_Curriculares_Handler extends TestCase {

	private string $plugin;
	private string $nav;
	private string $settings;

	protected function setUp(): void {
		$root           = dirname( __DIR__ );
		$this->plugin   = (string) file_get_contents( $root . '/anpa-socios.php' );
		$this->nav      = (string) file_get_contents( $root . '/includes/lib/class-anpa-socios-admin-nav.php' );
		$this->settings = (string) file_get_contents( $root . '/includes/class-anpa-socios-admin-settings.php' );
	}

	public function test_global_curricular_groups_are_not_bootstrapped(): void {
		$this->assertStringNotContainsString( 'class-anpa-socios-grupos-curriculares-page.php', $this->plugin );
		$this->assertStringNotContainsString( 'class-anpa-socios-admin-grupos-curriculares-handler.php', $this->plugin );
		$this->assertStringNotContainsString( 'ANPA_Socios_Admin_Grupos_Curriculares_Handler', $this->plugin );
	}

	public function test_courses_settings_only_contains_school_structure(): void {
		$this->assertStringNotContainsString( "'grupos-curriculares' => 'Grupos curriculares'", $this->nav );
		$this->assertStringNotContainsString( "'grupos-curriculares' === \$section", $this->settings );
		$this->assertStringNotContainsString( 'ANPA_Socios_Grupos_Curriculares_Page::render()', $this->settings );
	}

	public function test_activity_group_series_domain_is_bootstrapped(): void {
		$this->assertStringContainsString( 'class-anpa-socios-grupo-serie.php', $this->plugin );
	}
}
