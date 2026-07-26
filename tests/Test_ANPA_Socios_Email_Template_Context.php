<?php
/**
 * Tests for the exclusive members'-area context pair (fase36, PR-36s1c-2).
 *
 * The pair exists because the frozen renderer has optional blocks but no `else`, and two live
 * emails branch on whether a members'-area URL was supplied with different wording each way.
 * Reproducing that requires exactly one of two tokens to be set, always — which is an
 * invariant, and an invariant left to each caller is an invariant that eventually breaks.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Context extends TestCase {

	public function test_a_url_activates_only_the_with_link_branch(): void {
		$context = ANPA_Socios_Email_Template_Context::area_link( 'https://example.org/area-socios/' );

		$this->assertSame( 'https://example.org/area-socios/', $context[ ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK ] );
		$this->assertSame( '', $context[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] );
	}

	public function test_no_url_activates_only_the_without_link_branch(): void {
		$context = ANPA_Socios_Email_Template_Context::area_link( '' );

		$this->assertSame( '', $context[ ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK ] );
		$this->assertSame( ANPA_Socios_Email_Template_Context::FLAG, $context[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] );
	}

	public function test_a_whitespace_only_url_counts_as_no_url(): void {
		// "   " is the shape a misconfigured option actually takes, and it must not silently
		// produce an email with a dead link paragraph.
		$context = ANPA_Socios_Email_Template_Context::area_link( "  \n " );

		$this->assertSame( '', $context[ ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK ] );
		$this->assertSame( ANPA_Socios_Email_Template_Context::FLAG, $context[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] );
	}

	public function test_the_builder_always_satisfies_the_invariant(): void {
		foreach ( array( 'https://example.org/a/', '', '   ', 'https://example.org/b?x=1' ) as $url ) {
			ANPA_Socios_Email_Template_Context::assert_area_link_exclusive(
				ANPA_Socios_Email_Template_Context::area_link( $url )
			);
		}

		$this->assertTrue( true, 'no exception means the invariant held for every input' );
	}

	public function test_both_branches_active_is_refused(): void {
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'contradict itself' );

		ANPA_Socios_Email_Template_Context::assert_area_link_exclusive(
			array(
				ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK    => 'https://example.org/',
				ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK => '1',
			)
		);
	}

	public function test_neither_branch_active_is_refused(): void {
		// The worse of the two failures and the easier to introduce, because "no URL
		// configured" looks like a harmless empty value.
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'no access instructions' );

		ANPA_Socios_Email_Template_Context::assert_area_link_exclusive( array() );
	}

	public function test_the_pair_is_declared_by_the_events_that_need_it(): void {
		$set = ANPA_Socios_Email_Template_Events::set();

		foreach ( ANPA_Socios_Email_Template_Context::events_requiring_area_link() as $key ) {
			$variables = $set->get( $key )->variables();

			$this->assertArrayHasKey( ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK, $variables, "{$key}" );
			$this->assertArrayHasKey( ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK, $variables, "{$key}" );
		}
	}

	public function test_both_branches_render_through_the_frozen_renderer(): void {
		// Proof that option (a) reproduces an if/else without touching the renderer.
		$declared = ANPA_Socios_Email_Template_Events::set()->get( 'member_application_approved' )->declared_tokens();
		$template = array(
			'subject'   => 'Alta aprobada',
			'body_html' => '{{#ligazon_area_socios}}<p>Con ligazón: {{ligazon_area_socios}}</p>{{/}}'
				. '{{#sen_ligazon_area_socios}}<p>Sen ligazón.</p>{{/}}',
			'body_text' => '{{#ligazon_area_socios}}Con ligazón: {{ligazon_area_socios}}{{/}}'
				. '{{#sen_ligazon_area_socios}}Sen ligazón.{{/}}',
		);

		$with = ANPA_Socios_Email_Template_Renderer::render(
			$template,
			ANPA_Socios_Email_Template_Context::area_link( 'https://example.org/area/' ),
			$declared
		);
		$this->assertTrue( $with['ok'] );
		$this->assertSame( '<p>Con ligazón: https://example.org/area/</p>', $with['body_html'] );

		$without = ANPA_Socios_Email_Template_Renderer::render(
			$template,
			ANPA_Socios_Email_Template_Context::area_link( '' ),
			$declared
		);
		$this->assertTrue( $without['ok'] );
		$this->assertSame( '<p>Sen ligazón.</p>', $without['body_html'] );

		// Exactly one paragraph in each case: never both, never neither.
		$this->assertNotSame( $with['body_html'], $without['body_html'] );
	}

	public function test_the_flag_never_reaches_the_reader(): void {
		// It is a switch, not content. If it were ever substituted as text the family would
		// see a bare "1" in the middle of the email.
		$declared = ANPA_Socios_Email_Template_Events::set()->get( 'member_application_completed' )->declared_tokens();

		$rendered = ANPA_Socios_Email_Template_Renderer::render(
			array(
				'subject'   => 'Alta completada',
				'body_html' => '{{#sen_ligazon_area_socios}}<p>Sen ligazón.</p>{{/}}',
				'body_text' => '{{#sen_ligazon_area_socios}}Sen ligazón.{{/}}',
			),
			ANPA_Socios_Email_Template_Context::area_link( '' ),
			$declared
		);

		$this->assertTrue( $rendered['ok'] );
		$this->assertStringNotContainsString( ANPA_Socios_Email_Template_Context::FLAG, $rendered['body_html'] );
	}

	public function test_the_context_helper_does_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-context.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}
}
