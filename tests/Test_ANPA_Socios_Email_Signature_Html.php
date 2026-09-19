<?php
/**
 * 1.67.0: the email signature (Axustes → Xeral → «Firma dos correos») accepts limited HTML — links,
 * bold/italic and line breaks — so «Facebook», «Instagram» and «Telegram» can be clickable. Before, the
 * field was plain text (sanitize_textarea_field on save, esc_html on send) and any tag was lost.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Signature_Html extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_allowed_html_is_links_emphasis_and_line_breaks_only(): void {
		$allowed = ANPA_Socios_Email::signature_allowed_html();
		$this->assertSame( array( 'a', 'br', 'strong', 'b', 'em', 'i' ), array_keys( $allowed ) );
		$this->assertSame( array( 'href', 'title', 'target', 'rel' ), array_keys( $allowed['a'] ) );
		foreach ( array( 'script', 'img', 'style', 'iframe', 'div', 'span' ) as $tag ) {
			$this->assertArrayNotHasKey( $tag, $allowed );
		}
	}

	public function test_sanitize_keeps_links_and_bold_and_drops_everything_else(): void {
		$raw = '<a href="https://www.facebook.com/anpa.ventin" target="_blank">Facebook</a> · <b>ANPA</b> <script>alert(1)</script><img src="x">';
		$out = ANPA_Socios_Email::sanitize_signature( $raw );
		$this->assertStringContainsString( '<a href="https://www.facebook.com/anpa.ventin" target="_blank">Facebook</a>', $out );
		$this->assertStringContainsString( '<b>ANPA</b>', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( '<img', $out );
	}

	public function test_sanitize_keeps_plain_text_line_breaks_and_trims(): void {
		$out = ANPA_Socios_Email::sanitize_signature( "  ANPA As Brañas\n🌐 Web: https://anpaventin.es\n\n💡 Lembra  " );
		$this->assertSame( "ANPA As Brañas\n🌐 Web: https://anpaventin.es\n\n💡 Lembra", $out, 'existing plain-text signatures keep working unchanged' );
	}

	public function test_settings_save_through_the_signature_sanitizer_in_both_forms(): void {
		$s = $this->src( 'includes/class-anpa-socios-admin-settings.php' );
		$this->assertSame( 2, substr_count( $s, 'ANPA_Socios_Email::sanitize_signature( (string) wp_unslash( $_POST[\'email_signature\'] ) )' ), 'wizard + Axustes → Xeral' );
		$this->assertStringNotContainsString( "sanitize_textarea_field( (string) wp_unslash( \$_POST['email_signature'] ) )", $s );
	}

	public function test_emails_render_the_signature_with_kses_not_esc_html(): void {
		$e     = $this->src( 'includes/class-anpa-socios-email.php' );
		$start = strpos( $e, 'private static function signature_html()' );
		$this->assertNotFalse( $start );
		$body = substr( $e, $start, 600 );
		$this->assertStringContainsString( 'wp_kses( $sig, self::signature_allowed_html() )', $body );
		$this->assertStringNotContainsString( 'esc_html( $sig )', $body );
		$this->assertStringContainsString( 'white-space:pre-line', $body, 'plain-text line breaks still render' );
	}
}
