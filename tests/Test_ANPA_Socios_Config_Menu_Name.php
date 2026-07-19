<?php
/**
 * Tests for the configurable admin sidebar label helper.
 *
 * @since  1.35.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal get_option stub for pure PHPUnit tests.
	 *
	 * @param  string $key     Option key.
	 * @param  mixed  $default  Default fallback.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		return $GLOBALS['anpa_socios_config_menu_name_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal wp_strip_all_tags stub for pure PHPUnit tests.
	 *
	 * @param  string $text Input text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

final class Test_ANPA_Socios_Config_Menu_Name extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		$GLOBALS['anpa_socios_config_menu_name_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['anpa_socios_config_menu_name_options'] );
	}

	public function test_menu_name_defaults_when_missing_or_blank(): void {
		$this->assertSame( 'Xestión ANPA', ANPA_Socios_Config::menu_name() );

		$GLOBALS['anpa_socios_config_menu_name_options'][ ANPA_Socios_Config::OPTION_MENU_NAME ] = '';
		$this->assertSame( 'Xestión ANPA', ANPA_Socios_Config::menu_name() );

		$GLOBALS['anpa_socios_config_menu_name_options'][ ANPA_Socios_Config::OPTION_MENU_NAME ] = '   ';
		$this->assertSame( 'Xestión ANPA', ANPA_Socios_Config::menu_name() );
	}

	public function test_menu_name_strips_tags_trims_and_caps_length(): void {
		$GLOBALS['anpa_socios_config_menu_name_options'][ ANPA_Socios_Config::OPTION_MENU_NAME ] = '   <strong>Meu menú</strong>   ';
		$this->assertSame( 'Meu menú', ANPA_Socios_Config::menu_name() );

		$GLOBALS['anpa_socios_config_menu_name_options'][ ANPA_Socios_Config::OPTION_MENU_NAME ] = '<em>' . str_repeat( 'x', 31 ) . '</em>';
		$this->assertSame( str_repeat( 'x', 30 ), ANPA_Socios_Config::menu_name() );
		$this->assertSame( 30, strlen( ANPA_Socios_Config::menu_name() ) );
	}
}
