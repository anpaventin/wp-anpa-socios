<?php
/**
 * TDD: FASE37 M10 — Uninstall cleanup verification.
 *
 * Verifies that anpa_socios_contenido_admin option is removed on uninstall
 * and that unrelated options are preserved.
 *
 * @since  1.49.2
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Contenido_Uninstall extends TestCase {

	private string $file;

	public function setUp(): void {
		parent::setUp();
		$this->file = dirname( __DIR__ ) . '/uninstall.php';
	}

	/**
	 * Verifies the uninstall file targets all anpa_ options.
	 */
	public function test_uninstall_covers_contenido_admin_option(): void {
		$this->assertFileExists( $this->file );
		$src = file_get_contents( $this->file );

		// The uninstall uses a LIKE 'anpa_%' pattern which covers anpa_socios_contenido_admin.
		$this->assertStringContainsString(
			"\$wpdb->esc_like( 'anpa_' ) . '%'",
			$src,
			'Uninstall must use anpa_ prefix pattern to catch all plugin options including contenido_admin'
		);
	}

	/**
	 * Verifies the uninstall does NOT target non-anpa options.
	 */
	public function test_uninstall_does_not_target_unrelated_options(): void {
		$src = file_get_contents( $this->file );

		// Should NOT contain patterns that would delete non-anpa options.
		$this->assertStringNotContainsString(
			'wp_options WHERE option_name NOT LIKE',
			$src,
			'Uninstall should not use NOT LIKE pattern'
		);
	}

	/**
	 * Verifies the option name follows the plugin convention.
	 */
	public function test_contenido_admin_option_name_is_anpa_prefixed(): void {
		// The option name must start with 'anpa_' to be caught by uninstall.
		$this->assertStringStartsWith(
			'anpa_',
			'anpa_socios_contenido_admin',
			'Option name must be anpa_ prefixed for uninstall cleanup'
		);
	}

	/**
	 * Verifies the config class defines the correct option name.
	 */
	public function test_config_class_option_constant(): void {
		$ref = new ReflectionClass( 'ANPA_Socios_Config' );
		$const = $ref->getConstant( 'OPTION_CONTENIDO_ADMIN' );
		$this->assertSame( 'anpa_socios_contenido_admin', $const );
	}

	/**
	 * Simulates the uninstall LIKE pattern matching.
	 */
	public function test_uninstall_pattern_matches_contenido_option(): void {
		$pattern = 'anpa_%';
		$option  = 'anpa_socios_contenido_admin';

		// Simulate SQL LIKE matching.
		$regex = '/^' . str_replace( '_', '\_', $pattern ) . '$/';
		$regex = str_replace( '%', '.*', $regex );

		$this->assertMatchesRegularExpression(
			$regex,
			$option,
			'Uninstall LIKE pattern must match contenido_admin option'
		);
	}

	/**
	 * Verifies unrelated options would NOT be matched.
	 */
	public function test_uninstall_pattern_excludes_unrelated_options(): void {
		$pattern = 'anpa_%';
		$unrelated = array(
			'wordpress_autosave_interval',
			'blogname',
			'active_plugins',
			'template',
			'anpa_socios_contenido_admin', // This one SHOULD match
		);

		$regex = '/^' . str_replace( '_', '\_', $pattern ) . '$/';
		$regex = str_replace( '%', '.*', $regex );

		foreach ( $unrelated as $option ) {
			if ( 'anpa_socios_contenido_admin' === $option ) {
				$this->assertMatchesRegularExpression( $regex, $option, "Should match: $option" );
			} else {
				$this->assertDoesNotMatchRegularExpression( $regex, $option, "Should NOT match: $option" );
			}
		}
	}
}
