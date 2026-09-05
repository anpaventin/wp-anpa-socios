<?php
/**
 * TDD: FASE36 — Email templates language audit.
 *
 * Verifies all email templates are in Galician.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/lib/class-anpa-socios-email-template-store.php';

final class Test_ANPA_Socios_Email_Templates_Galego extends TestCase {

	private $english_words = array(
		'Hello',
		'Your',
		'This code',
		'expires',
		'cancellation',
		'reactivation',
		'activity',
		'waitlist',
		'Pending',
		'Congratulations',
		'approved',
		'Welcome',
		'We regret',
		'Review in the',
		'Requested by',
		'Student',
		'Activity:',
		'Membership',
		'has been',
	);

	protected function setUp(): void {
		parent::setUp();
		// Stub gettext to return the original string (simulating English locale).
		if ( ! function_exists( '__' ) ) {
			function __( $text, $domain = 'default' ) {
				return $text;
			}
		}
	}

	public function test_all_templates_in_galego(): void {
		$templates = ANPA_Socios_Email_Template_Store::get_all_defaults();

		$this->assertNotEmpty( $templates, 'Templates should not be empty' );

		foreach ( $templates as $id => $template ) {
			$this->assertArrayHasKey( 'subject', $template, "Template $id: subject missing" );
			$this->assertArrayHasKey( 'html', $template, "Template $id: html missing" );
			$this->assertArrayHasKey( 'text', $template, "Template $id: text missing" );

			// Subject must not contain English keywords.
			foreach ( $this->english_words as $word ) {
				$this->assertStringNotContainsString(
					$word,
					$template['subject'],
					"Template $id subject contains English: '$word'"
				);
				$this->assertStringNotContainsString(
					$word,
					$template['html'],
					"Template $id html contains English: '$word'"
				);
				$this->assertStringNotContainsString(
					$word,
					$template['text'],
					"Template $id text contains English: '$word'"
				);
			}
		}
	}

	public function test_placeholders_preserved(): void {
		$templates = ANPA_Socios_Email_Template_Store::get_all_defaults();

		// Each template should have %s placeholders.
		foreach ( $templates as $id => $template ) {
			if ( 'send_from_master' === $id ) {
				continue; // special template
			}

			$this->assertStringContainsString(
				'%s',
				$template['subject'],
				"Template $id subject should have %s placeholder"
			);
		}
	}

	public function test_html_structure_preserved(): void {
		$templates = ANPA_Socios_Email_Template_Store::get_all_defaults();

		foreach ( $templates as $id => $template ) {
			// Should have basic HTML structure.
			$this->assertStringContainsString( '<', $template['html'], "Template $id should have HTML" );
			$this->assertStringContainsString( '>', $template['html'], "Template $id should have HTML" );
		}
	}

	public function test_verification_code_galego(): void {
		$tpl = ANPA_Socios_Email_Template_Store::get_default( 'verification_code' );
		$this->assertStringContainsString( 'código de verificación', $tpl['subject'] );
		$this->assertStringContainsString( 'Ola', $tpl['html'] );
		$this->assertStringContainsString( '15 minutos', $tpl['html'] );
	}

	public function test_baixa_socio_galego(): void {
		$tpl = ANPA_Socios_Email_Template_Store::get_default( 'baixa_socio' );
		$this->assertStringContainsString( 'baixa', $tpl['subject'] );
		$this->assertStringContainsString( 'socio', $tpl['html'] );
	}

	public function test_aprobacion_galego(): void {
		$tpl = ANPA_Socios_Email_Template_Store::get_default( 'aprobacion' );
		$this->assertStringContainsString( 'aprobada', $tpl['subject'] );
		$this->assertStringContainsString( 'Parabéns', $tpl['html'] );
	}

	public function test_benvida_galego(): void {
		$tpl = ANPA_Socios_Email_Template_Store::get_default( 'benvida_alta' );
		$this->assertStringContainsString( 'Benvido', $tpl['subject'] );
		$this->assertStringContainsString( 'rexistro', $tpl['html'] );
	}

	public function test_rexeitamento_galego(): void {
		$tpl = ANPA_Socios_Email_Template_Store::get_default( 'rexeitamento' );
		$this->assertStringContainsString( 'solicitude', $tpl['subject'] );
		$this->assertStringContainsString( 'Sentimos', $tpl['html'] );
	}

	public function test_oferta_extraescolar_galego(): void {
		$tpl = ANPA_Socios_Email_Template_Store::get_default( 'oferta_extraescolar' );
		$this->assertStringContainsString( 'praza', $tpl['subject'] );
		$this->assertStringContainsString( 'oferta', $tpl['html'] );
	}

	public function test_pendente_aprobacion_galego(): void {
		$tpl = ANPA_Socios_Email_Template_Store::get_default( 'pendente_aprobacion' );
		$this->assertStringContainsString( 'aprobación', $tpl['subject'] );
		$this->assertStringContainsString( 'panel de administración', $tpl['html'] );
	}
}
