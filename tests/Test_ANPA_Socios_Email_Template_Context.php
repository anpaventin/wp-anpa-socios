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

	// ── Structured data: built in the context, never by an emitter ──────

	public function test_the_activity_list_is_plain_text_with_one_activity_per_line(): void {
		$list = ANPA_Socios_Email_Template_Context::activity_list(
			array(
				array( 'nome' => 'Robótica', 'horario' => 'martes e xoves, 16:30 a 17:30' ),
				array( 'nome' => 'Xadrez' ),
			)
		);

		$this->assertSame( "Robótica — martes e xoves, 16:30 a 17:30\nXadrez", $list );
	}

	public function test_the_activity_list_carries_no_markup(): void {
		// The renderer escapes every value in the HTML channel, so a token carrying <ul> would
		// reach the family as literal tags. That is a feature: no emitter can inject markup.
		// Presentation lives in the template, which wraps this in white-space: pre-line.
		$list = ANPA_Socios_Email_Template_Context::activity_list(
			array( array( 'nome' => 'Robótica & Xadrez', 'horario' => '<b>16:30</b>' ) )
		);

		$this->assertStringNotContainsString( '<ul>', $list );
		$this->assertStringNotContainsString( '<li>', $list );
		// The input is passed through as data; escaping is the renderer's job, not this one's.
		$this->assertSame( 'Robótica & Xadrez — <b>16:30</b>', $list );

		$rendered = ANPA_Socios_Email_Template_Renderer::render(
			array(
				'subject'   => 'Actividades',
				'body_html' => '<p style="white-space:pre-line">{{listado_actividades}}</p>',
				'body_text' => '{{listado_actividades}}',
			),
			array( 'listado_actividades' => $list ),
			array( 'listado_actividades' => array() )
		);

		$this->assertTrue( $rendered['ok'] );
		$this->assertStringContainsString( '&lt;b&gt;16:30&lt;/b&gt;', $rendered['body_html'] );
		$this->assertStringNotContainsString( '<b>', $rendered['body_html'] );
	}

	public function test_an_unnamed_activity_is_dropped_rather_than_rendered_as_a_gap(): void {
		$this->assertSame(
			'Robótica',
			ANPA_Socios_Email_Template_Context::activity_list(
				array( array( 'nome' => '  ' ), array( 'nome' => 'Robótica' ) )
			)
		);
	}

	public function test_the_activity_list_preserves_the_order_it_receives(): void {
		// The order a family reads is a product decision, not a side effect of a query.
		$list = ANPA_Socios_Email_Template_Context::activity_list(
			array( array( 'nome' => 'Xadrez' ), array( 'nome' => 'Baile' ), array( 'nome' => 'Robótica' ) )
		);

		$this->assertSame( "Xadrez\nBaile\nRobótica", $list );
	}

	public function test_a_newline_inside_an_entry_cannot_split_it_into_two_activities(): void {
		// A schedule pasted from a spreadsheet arrives with CRLF.
		$list = ANPA_Socios_Email_Template_Context::activity_list(
			array( array( 'nome' => "Robótica", 'horario' => "martes\r\ne xoves" ) )
		);

		$this->assertSame( 'Robótica — martes e xoves', $list );
		$this->assertSame( 1, count( explode( "\n", $list ) ) );
	}

	public function test_an_empty_activity_list_is_refused(): void {
		// An enrollment-opening email with a blank activity block is worse than no email: it
		// announces an opening and then shows nothing.
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'do not announce an opening with nothing to show' );

		ANPA_Socios_Email_Template_Context::activity_list( array() );
	}

	public function test_an_implausibly_long_activity_list_is_refused(): void {
		$many = array();
		for ( $i = 0; $i <= ANPA_Socios_Email_Template_Context::MAX_ACTIVITY_LINES; $i++ ) {
			$many[] = array( 'nome' => 'Actividade ' . $i );
		}

		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'more than the' );

		ANPA_Socios_Email_Template_Context::activity_list( $many );
	}

	// ── Dates: one policy, in one place ─────────────────────────────────

	public function test_a_canonical_date_becomes_the_administrative_format(): void {
		$this->assertSame( '05/10/2026', ANPA_Socios_Email_Template_Context::format_date( '2026-10-05' ) );
	}

	public function test_an_absent_date_renders_nothing_rather_than_a_gap(): void {
		// So the template's optional block removes the paragraph instead of printing a
		// date-shaped hole.
		$this->assertSame( '', ANPA_Socios_Email_Template_Context::format_date( '' ) );
		$this->assertSame( '', ANPA_Socios_Email_Template_Context::format_date( '   ' ) );
	}

	public function test_a_non_canonical_date_is_refused_so_no_emitter_picks_a_format(): void {
		// Without this, one association sends 1/9/2026, 01-09-2026 and "1 de setembro".
		foreach ( array( '1/9/2026', '01-09-2026', '1 de setembro de 2026', '2026-9-5' ) as $bad ) {
			try {
				ANPA_Socios_Email_Template_Context::format_date( $bad );
				$this->fail( "expected '{$bad}' to be refused" );
			} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
				$this->assertStringContainsString( 'canonical', $e->getMessage() );
			}
		}
	}

	public function test_a_date_that_does_not_exist_is_refused(): void {
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'does not exist' );

		ANPA_Socios_Email_Template_Context::format_date( '2026-02-30' );
	}

	// ── Pending actions: controlled, not prose ──────────────────────────

	public function test_a_pending_action_comes_from_a_controlled_type(): void {
		$this->assertSame(
			'Confirmar unha praza ofrecida nunha actividade extraescolar.',
			ANPA_Socios_Email_Template_Context::pending_action_label( 'confirmar_praza' )
		);
	}

	public function test_free_prose_as_a_pending_action_is_refused(): void {
		// A reminder whose body is emitter-supplied prose is a reminder nobody can review,
		// translate or keep consistent.
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'never be free text' );

		ANPA_Socios_Email_Template_Context::pending_action_label( 'Fai o que sexa antes do venres' );
	}

	public function test_every_roadmap_pending_action_type_has_a_description(): void {
		foreach ( ANPA_Socios_Email_Template_Context::pending_action_types() as $type ) {
			$label = ANPA_Socios_Email_Template_Context::pending_action_label( $type );
			$this->assertNotSame( '', $label );
			// Each description must actually say what to do, not restate the type.
			$this->assertGreaterThan( 20, mb_strlen( $label ), "{$type}: the description is too thin to be useful" );
		}
	}

	public function test_state_labels_come_from_a_validated_identifier(): void {
		$this->assertSame(
			'Confirmado excepcionalmente baixo mínimo',
			ANPA_Socios_Email_Template_Context::group_state_label( 'confirmado_baixo_minimo' )
		);
		$this->assertSame(
			'Lista de espera para o seguinte trimestre',
			ANPA_Socios_Email_Template_Context::enrollment_state_label( 'espera_seguinte_trimestre' )
		);
	}

	public function test_a_free_text_state_is_refused(): void {
		// In a web flow this text could come from a request. A state label reaching a family must
		// never be arbitrary input.
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'never be free text' );

		ANPA_Socios_Email_Template_Context::group_state_label( 'Confirmado <script>alert(1)</script>' );
	}

	public function test_every_declared_state_has_a_label(): void {
		foreach ( ANPA_Socios_Email_Template_Context::group_states() as $state ) {
			$this->assertNotSame( '', ANPA_Socios_Email_Template_Context::group_state_label( $state ) );
		}
		foreach ( ANPA_Socios_Email_Template_Context::enrollment_states() as $state ) {
			$this->assertNotSame( '', ANPA_Socios_Email_Template_Context::enrollment_state_label( $state ) );
		}
	}

	public function test_the_context_helper_does_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-context.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}
}
