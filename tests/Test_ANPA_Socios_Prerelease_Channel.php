<?php
/**
 * Contract tests for the opt-in prerelease update channel (fase31 tooling).
 *
 * Safety invariant: a production install must NEVER receive a prerelease
 * automatically. This is enforced by (a) the option defaulting to OFF and
 * (b) the updater only switching to the prerelease metadata URL when the
 * option is explicitly enabled. Source-inspection style (glue, no WP boot).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Prerelease_Channel extends TestCase {

	private string $config_file;
	private string $updater_file;
	private string $settings_file;

	public function setUp(): void {
		parent::setUp();
		$root                = dirname( __DIR__ );
		$this->config_file   = $root . '/includes/class-anpa-socios-config.php';
		$this->updater_file  = $root . '/includes/class-anpa-socios-updater.php';
		$this->settings_file = $root . '/includes/class-anpa-socios-admin-settings.php';
	}

	public function test_config_option_defaults_to_off(): void {
		$src = file_get_contents( $this->config_file );
		$this->assertStringContainsString( "OPTION_USE_PRERELEASES = 'anpa_socios_use_prereleases'", $src );
		$this->assertStringContainsString( 'public static function use_prereleases(): bool', $src );
		// Default must be '0' (stable) — a missing option means stable channel.
		$this->assertStringContainsString( "get_option( self::OPTION_USE_PRERELEASES, '0' )", $src );
	}

	public function test_updater_only_uses_prerelease_url_when_opted_in(): void {
		$src = file_get_contents( $this->updater_file );
		$this->assertStringContainsString( 'PRERELEASE_METADATA_URL', $src );
		$this->assertStringContainsString( 'details-prerelease.json', $src );
		$this->assertStringContainsString( 'ANPA_Socios_Config::use_prereleases()', $src );
		// The stable METADATA_URL remains the else/default branch.
		$this->assertStringContainsString( 'self::METADATA_URL', $src );
	}

	public function test_settings_expose_isolated_prerelease_optin_form(): void {
		$src = file_get_contents( $this->settings_file );
		$this->assertStringContainsString( 'anpa_prerelease_form', $src );
		$this->assertStringContainsString( "name=\"use_prereleases\"", $src );
		$this->assertStringContainsString( 'ANPA_Socios_Config::OPTION_USE_PRERELEASES', $src );
	}
}
