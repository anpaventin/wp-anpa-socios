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

	/** Event key used in the activity-list error messages. */
	private const EVENT = 'school_year_enrollment_open';

	public function test_the_activity_list_is_plain_text_with_one_activity_per_line(): void {
		$list = ANPA_Socios_Email_Template_Context::activity_list(
			self::EVENT,
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
			self::EVENT,
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
				self::EVENT,
				array( array( 'nome' => '  ' ), array( 'nome' => 'Robótica' ) )
			)
		);
	}

	public function test_a_single_activity_is_a_valid_list(): void {
		$this->assertSame(
			'Robótica',
			ANPA_Socios_Email_Template_Context::activity_list( self::EVENT, array( array( 'nome' => 'Robótica' ) ) )
		);
	}

	public function test_the_activity_list_preserves_the_order_it_receives(): void {
		// The order a family reads is a product decision, not a side effect of a query.
		$list = ANPA_Socios_Email_Template_Context::activity_list(
			self::EVENT,
			array( array( 'nome' => 'Xadrez' ), array( 'nome' => 'Baile' ), array( 'nome' => 'Robótica' ) )
		);

		$this->assertSame( "Xadrez\nBaile\nRobótica", $list );
	}

	public function test_a_newline_or_tab_inside_an_entry_cannot_split_it_into_two_activities(): void {
		// A schedule pasted from a spreadsheet arrives with CRLF and tabs.
		$list = ANPA_Socios_Email_Template_Context::activity_list(
			self::EVENT,
			array( array( 'nome' => "Robótica", 'horario' => "martes\r\ne\txoves" ) )
		);

		$this->assertSame( 'Robótica — martes e xoves', $list );
		$this->assertSame( 1, count( explode( "\n", $list ) ) );
	}

	public function test_an_empty_activity_list_is_refused_and_names_the_event(): void {
		// An enrollment-opening email with a blank activity block is worse than no email: it
		// announces an opening and then shows nothing.
		try {
			ANPA_Socios_Email_Template_Context::activity_list( self::EVENT, array() );
			$this->fail( 'expected an empty list to be refused' );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( self::EVENT, $e->getMessage() );
			$this->assertStringContainsString( 'nothing to show', $e->getMessage() );
		}
	}

	public function test_duplicate_activities_are_an_error(): void {
		// Two identical lines almost always mean a join without DISTINCT or a context built
		// twice. Both are bugs worth failing on rather than emailing.
		try {
			ANPA_Socios_Email_Template_Context::activity_list(
				self::EVENT,
				array( array( 'nome' => 'Robótica' ), array( 'nome' => 'Robótica' ) )
			);
			$this->fail( 'expected duplicates to be refused' );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( 'without DISTINCT', $e->getMessage() );
			$this->assertStringContainsString( '2 entries', $e->getMessage() );
			// The names must not travel in an error message that may end up in a log.
			$this->assertStringNotContainsString( 'Robótica', $e->getMessage() );
		}
	}

	public function test_the_activity_guard_accepts_exactly_the_maximum(): void {
		$many = array();
		for ( $i = 1; $i <= ANPA_Socios_Email_Template_Context::MAX_ACTIVITY_LINES; $i++ ) {
			$many[] = array( 'nome' => 'Actividade ' . $i );
		}

		$list = ANPA_Socios_Email_Template_Context::activity_list( self::EVENT, $many );
		$this->assertCount( ANPA_Socios_Email_Template_Context::MAX_ACTIVITY_LINES, explode( "\n", $list ) );
	}

	public function test_one_activity_past_the_guard_is_refused_with_counts_and_no_names(): void {
		$many = array();
		for ( $i = 1; $i <= ANPA_Socios_Email_Template_Context::MAX_ACTIVITY_LINES + 1; $i++ ) {
			$many[] = array( 'nome' => 'Actividade ' . $i );
		}

		try {
			ANPA_Socios_Email_Template_Context::activity_list( self::EVENT, $many );
			$this->fail( 'expected the guard to refuse' );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			// A TECHNICAL guard, not a limit on how many activities the association may offer.
			$this->assertStringContainsString( 'technical guard', $e->getMessage() );
			$this->assertStringContainsString( self::EVENT, $e->getMessage() );
			$this->assertStringContainsString( (string) ( ANPA_Socios_Email_Template_Context::MAX_ACTIVITY_LINES + 1 ), $e->getMessage() );
			$this->assertStringNotContainsString( 'Actividade 1', $e->getMessage() );
		}
	}

	// ── Dates: one policy, in one place ─────────────────────────────────

	public function test_a_canonical_date_becomes_the_administrative_format(): void {
		$this->assertSame( '05/10/2026', ANPA_Socios_Email_Template_Context::format_optional_date( '2026-10-05' ) );
		$this->assertSame( '05/10/2026', ANPA_Socios_Email_Template_Context::format_required_date( '2026-10-05' ) );
	}

	public function test_an_absent_optional_date_renders_nothing(): void {
		// So the template's optional block removes the paragraph instead of printing a
		// date-shaped hole.
		$this->assertSame( '', ANPA_Socios_Email_Template_Context::format_optional_date( '' ) );
		$this->assertSame( '', ANPA_Socios_Email_Template_Context::format_optional_date( '   ' ) );
	}

	public function test_an_absent_required_date_is_an_error_not_a_missing_paragraph(): void {
		// The dangerous case. An optional block would hide the omission and produce an email that
		// looks complete and never mentions the deadline: it validates, it sends, and nobody
		// notices until a family misses a date they were never told about.
		try {
			ANPA_Socios_Email_Template_Context::format_required_date( '', 'data_limite' );
			$this->fail( 'expected a missing required date to be refused' );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( 'data_limite', $e->getMessage() );
			$this->assertStringContainsString( 'hide its absence', $e->getMessage() );
		}
	}

	public function test_a_non_canonical_date_is_refused_so_no_emitter_picks_a_format(): void {
		// Without this, one association sends 1/9/2026, 01-09-2026 and "1 de setembro".
		foreach ( array( '1/9/2026', '01-09-2026', '1 de setembro de 2026', '2026-9-5' ) as $bad ) {
			foreach ( array( 'format_optional_date', 'format_required_date' ) as $method ) {
				try {
					ANPA_Socios_Email_Template_Context::$method( $bad );
					$this->fail( "expected '{$bad}' to be refused by {$method}" );
				} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
					$this->assertStringContainsString( 'canonical', $e->getMessage() );
				}
			}
		}
	}

	public function test_a_date_that_does_not_exist_is_refused(): void {
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'not a date that exists' );

		ANPA_Socios_Email_Template_Context::format_optional_date( '2026-02-30' );
	}

	// ── URLs ────────────────────────────────────────────────────────────

	public function test_an_https_url_passes_untouched(): void {
		$this->assertSame(
			'https://example.org/area-socios/',
			ANPA_Socios_Email_Template_Context::format_url( ' https://example.org/area-socios/ ' )
		);
	}

	public function test_plain_http_is_refused_except_for_development_hosts(): void {
		// http in an email a family clicks is a downgrade nobody chose.
		$this->assertSame(
			'http://example.org/area/',
			ANPA_Socios_Email_Template_Context::format_url( 'http://example.org/area/' )
		);
		$this->assertSame(
			'http://localhost:8080/area/',
			ANPA_Socios_Email_Template_Context::format_url( 'http://localhost:8080/area/' )
		);

		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'must use https' );
		ANPA_Socios_Email_Template_Context::format_url( 'http://anpa-real.example-not-reserved.gal/area/' );
	}

	public function test_a_url_with_a_line_break_is_refused(): void {
		// A newline that reaches a header is a header-injection vector.
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'line break' );

		ANPA_Socios_Email_Template_Context::format_url( "https://example.org/a\r\nBcc: outro@example.org" );
	}

	public function test_an_overlong_url_is_refused(): void {
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'more than the' );

		ANPA_Socios_Email_Template_Context::format_url( 'https://example.org/' . str_repeat( 'a', 2100 ) );
	}

	public function test_an_absent_url_is_allowed_only_when_optional(): void {
		$this->assertSame( '', ANPA_Socios_Email_Template_Context::format_url( '', 'ligazon_enquisa', false ) );

		try {
			ANPA_Socios_Email_Template_Context::format_url( '', 'ligazon_confirmacion', true );
			$this->fail( 'expected a missing required URL to be refused' );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( 'nowhere to do it', $e->getMessage() );
		}
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

	public function test_an_action_requiring_a_deadline_cannot_render_without_one(): void {
		// requires_deadline is enforcement, not documentation.
		$this->assertTrue( ANPA_Socios_Email_Template_Actions::requires_deadline( 'confirmar_praza' ) );

		try {
			ANPA_Socios_Email_Template_Context::pending_action( 'confirmar_praza', '', 'https://example.org/c/' );
			$this->fail( 'expected a missing deadline to be refused' );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( 'data_limite', $e->getMessage() );
		}
	}

	public function test_an_action_requiring_a_link_cannot_render_without_one(): void {
		try {
			ANPA_Socios_Email_Template_Context::pending_action( 'confirmar_praza', '2026-10-20', '' );
			$this->fail( 'expected a missing link to be refused' );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( 'nowhere to do it', $e->getMessage() );
		}
	}

	public function test_a_complete_pending_action_context_answers_the_five_questions(): void {
		$context = ANPA_Socios_Email_Template_Context::pending_action(
			'confirmar_praza',
			'2026-10-20',
			'https://example.org/confirmar/exemplo/'
		);

		$this->assertNotSame( '', $context['accion_pendente'], 'what must be done' );
		$this->assertSame( '20/10/2026', $context['data_limite'], 'by when' );
		$this->assertSame( 'https://example.org/confirmar/exemplo/', $context['ligazon_confirmacion'], 'where' );
		$this->assertNotSame( '', $context['consecuencia'], 'what happens otherwise' );
	}

	public function test_an_action_without_a_deadline_requirement_may_omit_it(): void {
		$context = ANPA_Socios_Email_Template_Context::pending_action(
			'corrixir_solicitude_alta',
			'',
			'https://example.org/area-socios/'
		);

		$this->assertSame( '', $context['data_limite'] );
		$this->assertNotSame( '', $context['accion_pendente'] );
	}

	public function test_the_action_catalogue_is_a_declared_contract_with_its_own_fingerprint(): void {
		// It changes what a family reads, so it cannot change silently. Its own digest, not the
		// registry's: "we reworded a reminder" must not look like "the template contract changed".
		$fingerprint = ANPA_Socios_Email_Template_Actions::fingerprint();

		$this->assertStringStartsWith( ANPA_Socios_Email_Template_Actions::FINGERPRINT_SCHEME . ':', $fingerprint );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+:[0-9a-f]{64}$/', $fingerprint );
		$this->assertSame( $fingerprint, ANPA_Socios_Email_Template_Actions::fingerprint() );
		$this->assertNotSame( ANPA_Socios_Email_Template_Events::set()->fingerprint(), $fingerprint );
	}

	public function test_every_action_declaration_is_complete(): void {
		$required = array( 'label', 'description', 'entity', 'requires_deadline', 'requires_link', 'consequence', 'phase' );

		foreach ( ANPA_Socios_Email_Template_Actions::supported_types() as $type ) {
			$declaration = ANPA_Socios_Email_Template_Actions::declaration( $type );

			foreach ( $required as $field ) {
				$this->assertArrayHasKey( $field, $declaration, "{$type}: missing {$field}" );
			}
			$this->assertNotSame( '', trim( (string) $declaration['consequence'] ), "{$type}: no consequence" );
			$this->assertNotSame( '', trim( (string) $declaration['phase'] ), "{$type}: no owning phase" );
		}
	}

	public function test_an_unknown_action_type_never_renders(): void {
		$this->assertFalse( ANPA_Socios_Email_Template_Actions::supports( 'inventado' ) );

		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		ANPA_Socios_Email_Template_Context::pending_action( 'inventado', '2026-10-20', 'https://example.org/' );
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
