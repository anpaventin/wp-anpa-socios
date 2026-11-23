<?php
/**
 * 1.96.1: live plugin pages are never served from a page cache.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Non_Cachear extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_detects_the_live_shortcodes_only(): void {
		$this->assertTrue( ANPA_Socios_Non_Cachear::contido_vivo( "<!-- wp:shortcode -->\n[anpa_socios_area]\n<!-- /wp:shortcode -->" ) );
		$this->assertTrue( ANPA_Socios_Non_Cachear::contido_vivo( '[anpa_extraescolares_ofertadas]' ) );
		$this->assertTrue( ANPA_Socios_Non_Cachear::contido_vivo( '[anpa_socios_asociarse]' ) );
		// Static blocks keep the page cacheable.
		$this->assertFalse( ANPA_Socios_Non_Cachear::contido_vivo( '[anpa_cifras] [anpa_contenido categoria="comedor"] [anpa_socios_hub]' ) );
		$this->assertFalse( ANPA_Socios_Non_Cachear::contido_vivo( '<p>Sen shortcodes</p>' ) );
	}

	public function test_every_live_render_marks_the_response(): void {
		$this->assertStringContainsString( 'ANPA_Socios_Non_Cachear::marcar();', $this->src( 'includes/class-anpa-socios-unified-page.php' ) );
		$this->assertStringContainsString( 'ANPA_Socios_Non_Cachear::marcar();', $this->src( 'includes/class-anpa-socios-area-page.php' ) );
		$this->assertStringContainsString( 'ANPA_Socios_Non_Cachear::marcar();', $this->src( 'includes/class-anpa-socios-page.php' ) );
		$this->assertSame( 2, substr_count( $this->src( 'includes/class-anpa-socios-extraescolares-page.php' ), 'ANPA_Socios_Non_Cachear::marcar();' ) );
	}

	public function test_marker_uses_donotcachepage_and_is_registered(): void {
		$lib = $this->src( 'includes/lib/class-anpa-socios-non-cachear.php' );
		$this->assertStringContainsString( "define( 'DONOTCACHEPAGE', true );", $lib );
		$this->assertStringContainsString( 'nocache_headers();', $lib );
		$this->assertStringContainsString( "add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 0 );", $lib );
		$main = $this->src( 'anpa-socios.php' );
		$this->assertStringContainsString( 'includes/lib/class-anpa-socios-non-cachear.php', $main );
		$this->assertStringContainsString( 'ANPA_Socios_Non_Cachear::register();', $main );
	}
}
