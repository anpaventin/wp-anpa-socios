<?php
/**
 * REAL sanitiser tests against a WordPress runtime (fase36, PR-36s2).
 *
 * `wp_kses` is the only thing that can settle what actually survives a save, so these run under the
 * WordPress test suite and self-skip locally. They carry the two assertions that matter:
 *
 *   1. Every dangerous construct in the declared catalogue is gone.
 *   2. Every one of the shipped defaults survives BYTE-IDENTICALLY. That is the expensive one: the
 *      ten live templates are pinned byte-exact against the golden oracle, and a sanitiser that
 *      reformatted a default would break the "nothing changed for families" proof at the storage
 *      layer, where nobody would look for it.
 *
 * @group integration
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Sanitizer_Integration extends TestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'wp_kses' ) ) {
			$this->markTestSkipped( 'wp_kses is unavailable: run under the WordPress test suite.' );
		}
	}

	// ── The property that is easy to lose ───────────────────────────────

	public function test_every_shipped_default_survives_sanitisation_byte_for_byte(): void {
		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$default = ANPA_Socios_Email_Template_Defaults::load( (string) $key );

			$this->assertSame(
				$default['body_html'],
				ANPA_Socios_Email_Template_Sanitizer::sanitize_html( $default['body_html'] ),
				"{$key}: the sanitiser modified a shipped default; the golden parity proof would silently stop holding"
			);
			$this->assertSame(
				$default['subject'],
				ANPA_Socios_Email_Template_Sanitizer::sanitize_subject( $default['subject'] ),
				"{$key}: the sanitiser modified a shipped subject"
			);
			$this->assertSame(
				$default['body_text'],
				ANPA_Socios_Email_Template_Sanitizer::sanitize_text( $default['body_text'] ),
				"{$key}: the sanitiser modified a shipped plain-text body"
			);
		}
	}

	public function test_sanitisation_is_idempotent(): void {
		// Storage will sanitise on every save, including a save of already-stored content. If the
		// operation were not idempotent, the content hash would drift and "has this been modified?"
		// would answer yes forever.
		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$html  = ANPA_Socios_Email_Template_Defaults::load( (string) $key )['body_html'];
			$once  = ANPA_Socios_Email_Template_Sanitizer::sanitize_html( $html );
			$twice = ANPA_Socios_Email_Template_Sanitizer::sanitize_html( $once );

			$this->assertSame( $once, $twice, "{$key}: sanitisation is not idempotent" );
		}
	}

	public function test_the_optional_block_markers_survive(): void {
		// The renderer is frozen and its block syntax is not HTML. If kses ate `{{#token}}` the
		// templates would keep validating and quietly stop branching.
		$html  = '<p>{{nome_socio}}</p>{{#sinatura}}<p>{{sinatura}}</p>{{/}}';
		$clean = ANPA_Socios_Email_Template_Sanitizer::sanitize_html( $html );

		$this->assertStringContainsString( '{{#sinatura}}', $clean );
		$this->assertStringContainsString( '{{/}}', $clean );
		$this->assertStringContainsString( '{{nome_socio}}', $clean );
	}

	// ── What must not survive ───────────────────────────────────────────

	public function test_no_declared_forbidden_construct_survives(): void {
		foreach ( ANPA_Socios_Email_Template_Html_Policy::forbidden_examples() as $label => $payload ) {
			$clean = ANPA_Socios_Email_Template_Sanitizer::sanitize_html( '<p>antes</p>' . $payload . '<p>despois</p>' );

			foreach ( array( '<script', '<iframe', '<object', '<embed', '<form', '<input', '<style', '<link', '<img', 'javascript:', 'onclick', 'onerror', 'background-image' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $clean, "{$label}: '{$needle}' survived sanitisation" );
			}

			// The legitimate content around the payload is not collateral damage.
			$this->assertStringContainsString( 'antes', $clean, "{$label}: legitimate content was destroyed" );
			$this->assertStringContainsString( 'despois', $clean, "{$label}: legitimate content was destroyed" );
		}
	}

	public function test_a_foreign_doctype_does_not_survive_while_the_legal_one_does(): void {
		$legal = ANPA_Socios_Email_Template_Sanitizer::sanitize_html(
			ANPA_Socios_Email_Template_Html_Policy::DOCTYPE . '<html><body><p>t</p></body></html>'
		);
		$this->assertStringStartsWith( ANPA_Socios_Email_Template_Html_Policy::DOCTYPE, $legal );

		$foreign = ANPA_Socios_Email_Template_Sanitizer::sanitize_html(
			'<!DOCTYPE html SYSTEM "about:legacy-compat"><html><body><p>t</p></body></html>'
		);
		$this->assertStringNotContainsString( 'legacy-compat', $foreign );
		$this->assertStringContainsString( '<p>t</p>', $foreign );
	}

	public function test_a_plain_http_link_does_not_survive(): void {
		$clean = ANPA_Socios_Email_Template_Sanitizer::sanitize_html( '<p><a href="http://example.org/">ligazón</a></p>' );

		$this->assertStringNotContainsString( 'http://example.org', $clean );
		$this->assertStringContainsString( 'ligazón', $clean, 'the link text is content and stays' );
	}

	public function test_an_https_link_and_a_mailto_survive(): void {
		$clean = ANPA_Socios_Email_Template_Sanitizer::sanitize_html(
			'<p><a href="https://example.org/area/">área</a> <a href="mailto:contacto@example.org">contacto</a></p>'
		);

		$this->assertStringContainsString( 'https://example.org/area/', $clean );
		$this->assertStringContainsString( 'mailto:contacto@example.org', $clean );
	}

	// ── Subjects: one line, always ──────────────────────────────────────

	public function test_a_subject_cannot_carry_a_newline(): void {
		$clean = ANPA_Socios_Email_Template_Sanitizer::sanitize_subject( "Alta aprobada\r\nBcc: outro@example.org" );

		$this->assertStringNotContainsString( "\n", $clean );
		$this->assertStringNotContainsString( "\r", $clean );
		$this->assertSame( 'Alta aprobada Bcc: outro@example.org', $clean );
	}

	public function test_a_newline_in_a_subject_becomes_a_separator_not_a_deletion(): void {
		// "Altaaprobada" would be a silent corruption; a space is the honest reading of an accident.
		$this->assertSame(
			'Alta aprobada',
			ANPA_Socios_Email_Template_Sanitizer::sanitize_subject( "Alta\naprobada" )
		);
	}

	public function test_a_control_byte_never_reaches_a_subject(): void {
		$this->assertSame(
			'Alta aprobada',
			ANPA_Socios_Email_Template_Sanitizer::sanitize_subject( "Alta\x00 aprobada" )
		);
	}

	public function test_a_subject_is_not_truncated(): void {
		$long  = str_repeat( 'a', 400 );
		$clean = ANPA_Socios_Email_Template_Sanitizer::sanitize_subject( $long );

		$this->assertSame( 400, mb_strlen( $clean ), 'truncation belongs to the repository, which refuses loudly' );
	}

	// ── Plain text is a channel, not a by-product ───────────────────────

	public function test_the_text_channel_keeps_its_paragraph_structure(): void {
		$clean = ANPA_Socios_Email_Template_Sanitizer::sanitize_text( "Ola,\r\n\r\nUnha liña.\r\nOutra liña." );

		$this->assertSame( "Ola,\n\nUnha liña.\nOutra liña.", $clean );
	}

	public function test_markup_pasted_into_the_text_channel_is_stripped_not_escaped(): void {
		// «&lt;p&gt;» reaching a family is worse than losing the tag.
		$clean = ANPA_Socios_Email_Template_Sanitizer::sanitize_text( '<p>Ola</p>' );

		$this->assertStringNotContainsString( '<p>', $clean );
		$this->assertStringNotContainsString( '&lt;', $clean );
		$this->assertStringContainsString( 'Ola', $clean );
	}
}
