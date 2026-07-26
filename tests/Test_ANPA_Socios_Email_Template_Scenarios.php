<?php
/**
 * Tests for the preview scenarios (fase36, PR-36s1c-4).
 *
 * The valuable test here is not "does one scenario work" but the CROSS PRODUCT: every event, under
 * every scenario that applies to it, rendered through the pre-render gate. That is what covers the
 * cases a human never previews — the ones where an optional block disappears.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Scenarios extends TestCase {

	/** @return array<int,array{0:string,1:ANPA_Socios_Email_Template_Definition,2:string}> event, definition, scenario. */
	private function cross_product(): array {
		$pairs = array();

		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			foreach ( ANPA_Socios_Email_Template_Scenarios::for_event( $definition ) as $scenario ) {
				$pairs[] = array( (string) $key, $definition, $scenario );
			}
		}

		return $pairs;
	}

	/**
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event.
	 * @param  string                                $scenario   Scenario id.
	 * @return array<string,mixed> Render result.
	 */
	private function render( ANPA_Socios_Email_Template_Definition $definition, string $scenario ): array {
		$default = ANPA_Socios_Email_Template_Defaults::load( $definition->default_template() );
		$context = ANPA_Socios_Email_Template_Scenarios::build( $definition, $scenario );

		return ANPA_Socios_Email_Template_Validator::render(
			$definition,
			array(
				'subject'   => $default['subject'],
				'body_html' => $default['body_html'],
				'body_text' => $default['body_text'],
			),
			$context
		);
	}

	public function test_the_cross_product_is_not_empty(): void {
		$pairs = $this->cross_product();

		$this->assertNotSame( array(), $pairs );
		$this->assertGreaterThan(
			count( ANPA_Socios_Email_Template_Scenarios::ids() ),
			count( $pairs ),
			'every event should contribute at least the universal scenarios'
		);
	}

	public function test_every_event_has_at_least_the_universal_scenarios(): void {
		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$ids = ANPA_Socios_Email_Template_Scenarios::for_event( $definition );

			$this->assertContains( ANPA_Socios_Email_Template_Scenarios::ID_DEFAULT, $ids, "{$key}" );
			$this->assertContains( ANPA_Socios_Email_Template_Scenarios::ID_MINIMAL, $ids, "{$key}" );
		}
	}

	// ── The global invariants, over the whole cross product ──────────────

	public function test_every_scenario_renders_through_the_gate(): void {
		// Through the VALIDATOR, not the renderer: a preview that could not pass the pre-render gate
		// is a preview of something the plugin would never send.
		foreach ( $this->cross_product() as list( $key, $definition, $scenario ) ) {
			$result = $this->render( $definition, $scenario );

			$this->assertTrue( $result['ok'], "{$key}/{$scenario}: {$result['code']}" );
		}
	}

	public function test_no_scenario_leaves_an_unresolved_token(): void {
		foreach ( $this->cross_product() as list( $key, $definition, $scenario ) ) {
			$result = $this->render( $definition, $scenario );

			foreach ( array( 'subject', 'body_html', 'body_text' ) as $channel ) {
				$this->assertStringNotContainsString( '{{', $result[ $channel ], "{$key}/{$scenario}/{$channel}" );
				$this->assertStringNotContainsString( '}}', $result[ $channel ], "{$key}/{$scenario}/{$channel}" );
			}
		}
	}

	public function test_no_scenario_shows_an_internal_flag(): void {
		// `sen_ligazon_area_socios` carries the value «1». If a template ever printed it instead of
		// using it as a block, the family would read a bare digit — and nothing else would fail.
		foreach ( $this->cross_product() as list( $key, $definition, $scenario ) ) {
			$result = $this->render( $definition, $scenario );

			foreach ( array( 'subject', 'body_html', 'body_text' ) as $channel ) {
				$this->assertDoesNotMatchRegularExpression(
					'/(^|\s|>)' . preg_quote( ANPA_Socios_Email_Template_Context::FLAG, '/' ) . '(\s|<|$)/u',
					$result[ $channel ],
					"{$key}/{$scenario}/{$channel}: an internal flag reached the output"
				);
			}
		}
	}

	public function test_every_scenario_keeps_the_subject_on_one_line(): void {
		foreach ( $this->cross_product() as list( $key, $definition, $scenario ) ) {
			$subject = $this->render( $definition, $scenario )['subject'];

			$this->assertNotSame( '', $subject, "{$key}/{$scenario}: empty subject" );
			$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $subject, "{$key}/{$scenario}: multi-line subject" );
			$this->assertSame( trim( $subject ), $subject, "{$key}/{$scenario}: padded subject" );
		}
	}

	public function test_every_scenario_produces_a_non_empty_plain_text_body(): void {
		// The channel a screen reader and a text-only client actually use. An empty one is an email
		// that arrived blank for those readers and looked fine to everyone else.
		foreach ( $this->cross_product() as list( $key, $definition, $scenario ) ) {
			$text = $this->render( $definition, $scenario )['body_text'];

			$this->assertNotSame( '', trim( $text ), "{$key}/{$scenario}: empty text body" );
			$this->assertGreaterThan( 40, mb_strlen( trim( $text ) ), "{$key}/{$scenario}: text body is a stub" );
		}
	}

	public function test_every_scenario_uses_only_reserved_domains(): void {
		// RFC 2606 only. A preview that can contain a real address is a data-protection incident
		// waiting for a screenshot, and a preview pointing at a real site invites a real click.
		foreach ( $this->cross_product() as list( $key, $definition, $scenario ) ) {
			$result   = $this->render( $definition, $scenario );
			$haystack = $result['subject'] . "\n" . $result['body_html'] . "\n" . $result['body_text'];

			preg_match_all( '/https?:\/\/([a-z0-9.-]+)/i', $haystack, $hosts );
			foreach ( $hosts[1] as $host ) {
				$this->assertMatchesRegularExpression(
					'/(^|\.)example\.(org|com|net)$/i',
					strtolower( $host ),
					"{$key}/{$scenario}: non-reserved host '{$host}'"
				);
			}

			preg_match_all( '/[a-z0-9._%+-]+@([a-z0-9.-]+)/i', $haystack, $domains );
			foreach ( $domains[1] as $domain ) {
				$this->assertMatchesRegularExpression(
					'/(^|\.)example\.(org|com|net)$/i',
					strtolower( rtrim( $domain, '.' ) ),
					"{$key}/{$scenario}: non-reserved mail domain '{$domain}'"
				);
			}
		}
	}

	public function test_no_scenario_contains_real_project_data(): void {
		// The fixture is fictitious on purpose. These are the strings that would appear if somebody
		// pasted production data into a preview to «see it properly».
		foreach ( $this->cross_product() as list( $key, $definition, $scenario ) ) {
			$result   = $this->render( $definition, $scenario );
			$haystack = mb_strtolower( $result['subject'] . ' ' . $result['body_html'] . ' ' . $result['body_text'] );

			foreach ( array( 'ventin', 'brañas', 'gmail.com', 'hotmail', 'ames', '@anpa' ) as $real ) {
				$this->assertStringNotContainsString( $real, $haystack, "{$key}/{$scenario}: real data '{$real}'" );
			}

			$this->assertDoesNotMatchRegularExpression(
				'/\b[A-Z]{2}\d{2}[ ]?\d{4}\b/',
				$result['body_text'],
				"{$key}/{$scenario}: something shaped like a bank account"
			);
		}
	}

	// ── The scenarios that exist to show a block disappearing ────────────

	public function test_the_two_area_link_branches_produce_different_bodies(): void {
		// If they came out identical, the exclusive pair would be decoration and the «no link»
		// wording would never have been read by anybody.
		$found = 0;

		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$ids = ANPA_Socios_Email_Template_Scenarios::for_event( $definition );
			if ( ! in_array( ANPA_Socios_Email_Template_Scenarios::ID_WITH_AREA_LINK, $ids, true ) ) {
				continue;
			}

			++$found;
			$with    = $this->render( $definition, ANPA_Socios_Email_Template_Scenarios::ID_WITH_AREA_LINK );
			$without = $this->render( $definition, ANPA_Socios_Email_Template_Scenarios::ID_WITHOUT_AREA_LINK );

			$this->assertNotSame( $with['body_html'], $without['body_html'], "{$key}: identical branches" );
			$this->assertStringContainsString( 'example.org', $with['body_html'], "{$key}: no link in the with-link branch" );
		}

		$this->assertGreaterThan( 0, $found, 'no event exercises the members-area pair' );
	}

	public function test_the_deadline_branches_differ_and_leave_no_hole(): void {
		$found = 0;

		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$ids = ANPA_Socios_Email_Template_Scenarios::for_event( $definition );
			if ( ! in_array( ANPA_Socios_Email_Template_Scenarios::ID_WITHOUT_DEADLINE, $ids, true ) ) {
				continue;
			}

			++$found;
			$with    = $this->render( $definition, ANPA_Socios_Email_Template_Scenarios::ID_WITH_DEADLINE );
			$without = $this->render( $definition, ANPA_Socios_Email_Template_Scenarios::ID_WITHOUT_DEADLINE );

			$this->assertNotSame( $with['body_text'], $without['body_text'], "{$key}: identical branches" );
			$this->assertDoesNotMatchRegularExpression( '/\n{3,}/', $without['body_text'], "{$key}: gap where the date was" );
		}

		$this->assertGreaterThan( 0, $found, 'no event exercises an optional deadline' );
	}

	public function test_an_event_with_a_required_deadline_has_no_without_deadline_scenario(): void {
		// There is no legitimate state in which such an email renders without a date, so previewing
		// one would document a bug as a feature.
		$definition = ANPA_Socios_Email_Template_Events::set()->get( 'waitlist_place_offer_reminder' );
		$ids        = ANPA_Socios_Email_Template_Scenarios::for_event( $definition );

		$this->assertContains( ANPA_Socios_Email_Template_Scenarios::ID_WITH_DEADLINE, $ids );
		$this->assertNotContains( ANPA_Socios_Email_Template_Scenarios::ID_WITHOUT_DEADLINE, $ids );

		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		ANPA_Socios_Email_Template_Scenarios::build(
			$definition,
			ANPA_Socios_Email_Template_Scenarios::ID_WITHOUT_DEADLINE
		);
	}

	public function test_the_secondary_contact_scenario_changes_who_is_addressed(): void {
		$found = 0;

		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$ids = ANPA_Socios_Email_Template_Scenarios::for_event( $definition );
			if ( ! in_array( ANPA_Socios_Email_Template_Scenarios::ID_SECONDARY_CONTACT, $ids, true ) ) {
				continue;
			}

			++$found;
			$primary   = $this->render( $definition, ANPA_Socios_Email_Template_Scenarios::ID_DEFAULT );
			$secondary = $this->render( $definition, ANPA_Socios_Email_Template_Scenarios::ID_SECONDARY_CONTACT );

			$this->assertStringContainsString(
				ANPA_Socios_Email_Template_Scenarios::PARENT,
				$secondary['body_text'],
				"{$key}: the second contact is not addressed"
			);
			$this->assertNotSame( $primary['body_text'], $secondary['body_text'], "{$key}: same body" );
		}

		$this->assertGreaterThan( 0, $found, 'no event addresses a second contact' );
	}

	public function test_the_minimal_scenario_omits_every_optional_non_global_token(): void {
		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			$context  = ANPA_Socios_Email_Template_Scenarios::build(
				$definition,
				ANPA_Socios_Email_Template_Scenarios::ID_MINIMAL
			);
			$globals  = ANPA_Socios_Email_Template_Events::globals();
			$required = $definition->required_tokens();

			foreach ( array_keys( $context ) as $token ) {
				$token = (string) $token;

				// The exclusive-pair flag is the one exception, and it is not a leak. It is declared
				// optional and is not configuration, but the pair invariant requires exactly one side
				// to be active: omitting it would make the minimal scenario the only case the
				// pre-render gate refuses. The invariant wins over the "required or global" rule.
				if ( ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK === $token ) {
					continue;
				}

				$this->assertTrue(
					in_array( $token, $required, true ) || in_array( $token, $globals, true ),
					"{$key}: minimal scenario supplied optional '{$token}'"
				);
			}

			// And the exception is only tolerated because the invariant is actually satisfied.
			if ( isset( $definition->variables()[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] ) ) {
				ANPA_Socios_Email_Template_Context::assert_area_link_exclusive( $context );
			}
		}
	}

	// ── Contract of the scenario catalogue itself ───────────────────────

	public function test_every_scenario_has_a_visible_label(): void {
		foreach ( ANPA_Socios_Email_Template_Scenarios::ids() as $id ) {
			$this->assertNotSame( '', trim( ANPA_Socios_Email_Template_Scenarios::label( $id ) ) );
		}
	}

	public function test_an_unknown_scenario_is_refused(): void {
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'unknown preview scenario' );

		ANPA_Socios_Email_Template_Scenarios::label( 'con-todo-cheo' );
	}

	public function test_the_scenario_ids_are_stable_identifiers(): void {
		// A screen, a test and the review document all refer to these. A label may be reworded; an
		// id may not, or the three stop talking about the same case.
		$this->assertSame(
			array(
				'default',
				'minimal',
				'with-area-link',
				'without-area-link',
				'with-deadline',
				'without-deadline',
				'secondary-contact',
			),
			ANPA_Socios_Email_Template_Scenarios::ids()
		);
	}

	public function test_the_scenario_catalogue_does_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-scenarios.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}
}
