<?php
/**
 * 1.100.1: duplicate socios pages can be removed without breaking the plugin.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Paxinas_Duplicadas extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_area_url_prefers_the_configured_socios_page(): void {
		$hub = $this->src( 'includes/class-anpa-socios-hub-page.php' );
		$a   = strpos( $hub, "if ( 'anpa_socios_area' === \$shortcode ) {" );
		$b   = strpos( $hub, "\$key    = 'anpa_socios_pageurl_'" );
		$this->assertNotFalse( $a );
		$this->assertLessThan( $b, $a, 'checked before the cached search' );
		$this->assertStringContainsString( "get_option( 'anpa_socios_landing_page_id', 0 )", $hub );
		$this->assertStringContainsString( "'publish' === get_post_status( \$landing ) && has_shortcode(", $hub );
	}

	public function test_legacy_area_fallback_needs_a_published_page(): void {
		$this->assertStringContainsString( "if ( \$new_area && 'publish' === \$new_area->post_status ) {", $this->src( 'includes/class-anpa-socios-unified-page.php' ) );
	}

	public function test_setup_does_not_recreate_asociarse_when_the_area_exists(): void {
		$s = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertMatchesRegularExpression( "/if \( '' === ANPA_Socios_Hub_Page::find_page_url\( 'anpa_socios_area' \) \) \{\s*self::ensure_page_by_shortcode\( 'anpa_socios_asociarse'/", $s );
	}
}
