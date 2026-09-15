<?php
/**
 * 1.62.1: Enter in the login inputs (unified entry widget and area) acts like
 * the step's main button. The steps are not a <form> and the buttons are
 * type="button", so without an explicit key handler Enter did nothing.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Login_Enter extends TestCase {

	private function src( string $rel ): string {
		$path = dirname( __DIR__ ) . '/' . $rel;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_unified_widget_binds_enter_to_send_code_and_verify(): void {
		$js = $this->src( 'assets/js/unified.js' );
		$this->assertStringContainsString( 'function bindEnter(input, button) {', $js );
		$this->assertStringContainsString( "if (event.key !== 'Enter' || event.isComposing) { return; }", $js );
		$this->assertStringContainsString( 'if (!button.disabled) { button.click(); }', $js, 'a disabled button (request in flight) must not be re-triggered' );
		$this->assertStringContainsString( 'bindEnter(emailInput, requestBtn);', $js );
		$this->assertStringContainsString( 'bindEnter(codeInput, verifyBtn);', $js );
		// The markup really has no form to rely on.
		$tpl = $this->src( 'includes/class-anpa-socios-unified-page.php' );
		$this->assertStringNotContainsString( '<form', $tpl );
		$this->assertStringContainsString( 'data-action="request-code-alta"', $tpl );
		$this->assertStringContainsString( 'data-action="verify-code"', $tpl );
	}

	public function test_area_widget_binds_enter_on_its_three_login_inputs(): void {
		$js = $this->src( 'assets/js/area.js' );
		$this->assertStringContainsString( 'function enterClicks(inputSelector, buttonSelector) {', $js );
		$this->assertStringContainsString( "enterClicks('#anpa-area-email', '[data-action=\"request-code\"]');", $js );
		$this->assertStringContainsString( "enterClicks('#anpa-area-code', '[data-action=\"verify-code\"]');", $js );
		$this->assertStringContainsString( "enterClicks('#anpa-area-email-alta', '[data-action=\"request-code-alta\"]');", $js );
	}
}
