<?php
/**
 * 1.65.2: the area's nested field groups and every text-like control share one
 * responsive layout (the «Segundo proxenitor / titor» block used to render its
 * fields crammed together, with an unstyled phone input).
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Area_Layout extends TestCase {

	private function css(): string {
		$path = dirname( __DIR__ ) . '/assets/css/area.css';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_second_parent_block_is_a_column_with_gaps_like_the_card(): void {
		$css = $this->css();
		$this->assertMatchesRegularExpression( '/#anpa-area \.anpa-area-p2-inline \{[^}]*display: flex;[^}]*flex-direction: column;[^}]*gap: 0\.85rem;/s', $css );
		$this->assertStringContainsString( '#anpa-area .anpa-area-p2-inline[hidden] { display: none; }', $css, 'the block still hides until the user opens it' );
		$this->assertStringContainsString( "#anpa-area .anpa-area-card > label,\n#anpa-area .anpa-area-p2-inline > label { display: block;", $css );
	}

	public function test_phone_date_and_selects_share_the_text_field_sizing(): void {
		$css = $this->css();
		$this->assertMatchesRegularExpression( '/#anpa-area input\[type="tel"\],\n#anpa-area input\[type="date"\],\n#anpa-area input\[type="number"\],\n#anpa-area \.anpa-area-card select \{[^}]*width: 100%;[^}]*max-width: var\(--anpa-measure, 34rem\);[^}]*padding: 0\.7rem 0\.85rem;/s', $css );
		// iOS zoom guard for the new controls too.
		$this->assertMatchesRegularExpression( '/@media \(max-width: 768px\) \{\n\t#anpa-area input\[type="tel"\],[^}]*font-size: 16px;/s', $css );
		// The template really uses those control types in the area cards.
		$tpl = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-area-page.php' );
		$this->assertStringContainsString( 'id="anpa-area-p2-telefono" type="tel"', $tpl );
		$this->assertStringContainsString( 'id="anpa-fillo-data" type="date"', $tpl );
		$this->assertStringContainsString( '<select id="anpa-fillo-curso"', $tpl );
	}
}
