<?php
/**
 * Pure tests for the fase36 template renderer (PR-36s1).
 *
 * These are behaviour tests, not inspection: the renderer is pure, so everything
 * that matters can be proven by calling it.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Renderer extends TestCase {

	/**
	 * @param string[] $tokens Declared token names.
	 * @return array<string,array<string,mixed>>
	 */
	private function declared( array $tokens ): array {
		$out = array();
		foreach ( $tokens as $t ) {
			$out[ $t ] = array( 'description' => 'proba', 'sample' => 'exemplo' );
		}

		return $out;
	}

	/**
	 * @param array<string,string> $over Template overrides.
	 * @return array<string,string>
	 */
	private function template( array $over = array() ): array {
		return array_merge(
			array(
				'subject'   => 'Asunto {{nome_socio}}',
				'body_html' => '<p>Ola {{nome_socio}}</p>',
				'body_text' => 'Ola {{nome_socio}}',
			),
			$over
		);
	}

	// ── Substitution ────────────────────────────────────────────────────

	public function test_a_declared_token_is_substituted_in_all_three_channels(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template(),
			array( 'nome_socio' => 'Ana' ),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'Asunto Ana', $out['subject'] );
		$this->assertSame( '<p>Ola Ana</p>', $out['body_html'] );
		$this->assertSame( 'Ola Ana', $out['body_text'] );
		$this->assertStringNotContainsString( '{{', $out['body_html'] );
	}

	public function test_a_declared_token_with_no_value_renders_empty_not_literal(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template(),
			array(),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'Ola', trim( $out['body_text'] ) );
		$this->assertStringNotContainsString( 'nome_socio', $out['body_html'] );
	}

	public function test_an_undeclared_token_refuses_to_render(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template( array( 'body_html' => '<p>{{nome_socio}} {{inventado}}</p>' ) ),
			array( 'nome_socio' => 'Ana' ),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertFalse( $out['ok'], 'a misspelled token must never reach a family' );
		$this->assertSame( ANPA_Socios_Email_Template_Renderer::ERR_UNDECLARED, $out['code'] );
		$this->assertSame( array( 'inventado' ), $out['undeclared'] );
		$this->assertSame( '', $out['body_html'] );
	}

	public function test_whitespace_inside_the_braces_is_tolerated(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template( array( 'body_text' => 'Ola {{  nome_socio  }}' ) ),
			array( 'nome_socio' => 'Ana' ),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'Ola Ana', $out['body_text'] );
	}

	// ── Escaping per channel ────────────────────────────────────────────

	public function test_a_value_can_never_inject_markup_into_the_html_body(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template(),
			array( 'nome_socio' => '<script>alert(1)</script>' ),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertTrue( $out['ok'] );
		$this->assertStringNotContainsString( '<script>', $out['body_html'] );
		$this->assertStringContainsString( '&lt;script&gt;', $out['body_html'] );
	}

	public function test_the_text_body_keeps_the_value_raw_and_adds_no_tags(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template(),
			array( 'nome_socio' => 'Ana & Xoán' ),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertSame( 'Ola Ana & Xoán', $out['body_text'] );
		$this->assertStringContainsString( 'Ana &amp; Xo', $out['body_html'] );
	}

	public function test_galician_accents_survive_rendering(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template( array( 'body_text' => 'Comunicacións de {{nome_socio}}: dúas veces' ) ),
			array( 'nome_socio' => 'María Ángela' ),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertSame( 'Comunicacións de María Ángela: dúas veces', $out['body_text'] );
	}

	// ── Optional blocks ─────────────────────────────────────────────────

	public function test_an_optional_block_disappears_whole_when_its_value_is_empty(): void {
		$tpl = $this->template(
			array(
				'body_text' => "Ola {{nome_socio}}.{{#ligazon_enquisa}}\nA túa opinión: {{ligazon_enquisa}}{{/}}\nGrazas.",
			)
		);

		$out = ANPA_Socios_Email_Template_Renderer::render(
			$tpl,
			array( 'nome_socio' => 'Ana' ),
			$this->declared( array( 'nome_socio', 'ligazon_enquisa' ) )
		);

		$this->assertTrue( $out['ok'] );
		$this->assertStringNotContainsString( 'A túa opinión', $out['body_text'], 'the heading must go with the value' );
		$this->assertStringContainsString( 'Grazas.', $out['body_text'] );
	}

	public function test_an_optional_block_is_kept_when_its_value_is_present(): void {
		$tpl = $this->template(
			array( 'body_text' => 'Ola.{{#ligazon_enquisa}} Enquisa: {{ligazon_enquisa}}{{/}}' )
		);

		$out = ANPA_Socios_Email_Template_Renderer::render(
			$tpl,
			array( 'ligazon_enquisa' => 'https://example.com/e' ),
			$this->declared( array( 'nome_socio', 'ligazon_enquisa' ) )
		);

		$this->assertTrue( $out['ok'] );
		$this->assertStringContainsString( 'Enquisa: https://example.com/e', $out['body_text'] );
	}

	public function test_a_block_that_only_contains_whitespace_counts_as_empty(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template( array( 'body_text' => 'A{{#motivo}} [{{motivo}}]{{/}}B' ) ),
			array( 'motivo' => '   ' ),
			$this->declared( array( 'nome_socio', 'motivo' ) )
		);

		$this->assertSame( 'AB', $out['body_text'] );
	}

	public function test_a_named_closing_tag_also_works(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template( array( 'body_text' => 'A{{#motivo}} {{motivo}}{{/motivo}}B' ) ),
			array( 'motivo' => 'X' ),
			$this->declared( array( 'nome_socio', 'motivo' ) )
		);

		$this->assertSame( 'A XB', $out['body_text'] );
	}

	public function test_an_unclosed_block_refuses_to_render(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template( array( 'body_text' => 'A{{#motivo}} {{motivo}}' ) ),
			array( 'motivo' => 'X' ),
			$this->declared( array( 'nome_socio', 'motivo' ) )
		);

		$this->assertFalse( $out['ok'] );
		$this->assertSame( ANPA_Socios_Email_Template_Renderer::ERR_UNCLOSED_BLOCK, $out['code'] );
	}

	public function test_a_block_token_must_also_be_declared(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template( array( 'body_text' => 'A{{#inventado}}x{{/}}B' ) ),
			array(),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertFalse( $out['ok'] );
		$this->assertSame( array( 'inventado' ), $out['undeclared'] );
	}

	// ── Aliases ─────────────────────────────────────────────────────────

	public function test_three_spellings_resolve_to_the_same_canonical_token(): void {
		$declared = $this->declared( array( 'nome_campana' ) );
		$aliases  = array(
			'nome_campaña'   => 'nome_campana',
			'nombre_campana' => 'nome_campana',
		);

		foreach ( array( 'nome_campana', 'nome_campaña', 'nombre_campana' ) as $spelling ) {
			$out = ANPA_Socios_Email_Template_Renderer::render(
				array(
					'subject'   => 'S',
					'body_text' => 'Campaña: {{' . $spelling . '}}',
					'body_html' => '<p>x</p>',
				),
				array( 'nome_campana' => 'Comezo curso' ),
				$declared,
				$aliases
			);

			$this->assertTrue( $out['ok'], "spelling $spelling must be accepted" );
			$this->assertSame( 'Campaña: Comezo curso', $out['body_text'] );
		}
	}

	public function test_the_context_may_also_use_an_alias(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			array( 'subject' => 'S', 'body_text' => '{{nome_campana}}', 'body_html' => '<p>x</p>' ),
			array( 'nombre_campana' => 'X' ),
			$this->declared( array( 'nome_campana' ) ),
			array( 'nombre_campana' => 'nome_campana' )
		);

		$this->assertSame( 'X', $out['body_text'] );
	}

	// ── Subject discipline ──────────────────────────────────────────────

	public function test_a_newline_in_the_subject_is_collapsed(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template( array( 'subject' => "Asunto\nBcc: x@y.com {{nome_socio}}" ) ),
			array( 'nome_socio' => 'Ana' ),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertTrue( $out['ok'] );
		$this->assertStringNotContainsString( "\n", $out['subject'], 'header injection prevention' );
		$this->assertSame( 'Asunto Bcc: x@y.com Ana', $out['subject'] );
	}

	public function test_a_value_carrying_a_newline_cannot_break_the_subject(): void {
		$out = ANPA_Socios_Email_Template_Renderer::render(
			$this->template(),
			array( 'nome_socio' => "Ana\r\nBcc: evil@example.com" ),
			$this->declared( array( 'nome_socio' ) )
		);

		$this->assertStringNotContainsString( "\n", $out['subject'] );
		$this->assertStringNotContainsString( "\r", $out['subject'] );
	}

	// ── Guards ──────────────────────────────────────────────────────────

	public function test_an_empty_template_refuses_to_render(): void {
		foreach ( array(
			array( 'subject' => '', 'body_html' => '<p>x</p>', 'body_text' => 'x' ),
			array( 'subject' => 'S', 'body_html' => '', 'body_text' => '' ),
		) as $tpl ) {
			$out = ANPA_Socios_Email_Template_Renderer::render( $tpl, array(), array() );
			$this->assertFalse( $out['ok'] );
			$this->assertSame( ANPA_Socios_Email_Template_Renderer::ERR_EMPTY_TEMPLATE, $out['code'] );
		}
	}

	public function test_rendering_is_deterministic(): void {
		$args = array(
			$this->template(),
			array( 'nome_socio' => 'Ana' ),
			$this->declared( array( 'nome_socio' ) ),
		);

		$first  = ANPA_Socios_Email_Template_Renderer::render( ...$args );
		$second = ANPA_Socios_Email_Template_Renderer::render( ...$args );

		$this->assertSame( $first, $second, 'the frozen payload of fase35 depends on this' );
	}

	public function test_the_renderer_has_no_wordpress_dependency(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-renderer.php' );
		$src = (string) preg_replace( '!/\*.*?\*/!s', '', $src );

		foreach ( array( 'esc_html(', 'esc_attr(', 'wp_kses', 'apply_filters', 'get_option', '$wpdb' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src, 'the pure renderer must not depend on WordPress' );
		}
	}

	public function test_token_discovery_finds_substitutions_and_block_markers(): void {
		$tokens = ANPA_Socios_Email_Template_Renderer::tokens_in( 'A {{um}} B {{#dous}} {{tres}} {{/}} C' );

		$this->assertContains( 'um', $tokens );
		$this->assertContains( 'dous', $tokens );
		$this->assertContains( 'tres', $tokens );
	}
}
