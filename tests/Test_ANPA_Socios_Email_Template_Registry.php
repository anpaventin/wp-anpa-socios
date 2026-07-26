<?php
/**
 * Tests for the template registry engine (fase36, PR-36s1b1).
 *
 * The engine is exercised against deliberately broken fixtures, not against the real
 * catalogue. That is the only way the error paths get covered: a registry that is
 * correct today would leave every validation branch untested, and an unexercised
 * validation is indistinguishable from a missing one.
 *
 * No test here asserts how many templates exist. A count assertion breaks the day a
 * legitimate template is added, which trains people to update the number instead of
 * reading the failure. The properties are asserted instead.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Registry extends TestCase {

	/**
	 * A minimal but valid dictionary.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function dictionary(): array {
		return array(
			'nome_anpa'       => array(
				'label'       => 'Nome da ANPA',
				'description' => 'Nome da asociación, tomado dos axustes.',
				'example'     => 'ANPA Exemplo',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_TEXT,
			),
			'sinatura'        => array(
				'label'       => 'Sinatura',
				'description' => 'Sinatura configurada para os correos.',
				'example'     => 'A Xunta Directiva',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_MULTILINE,
			),
			'nome_actividade' => array(
				'label'       => 'Nome da actividade',
				'description' => 'Actividade extraescolar á que se refire o correo.',
				'example'     => 'Robótica',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_TEXT,
			),
			'nome_campana'    => array(
				'label'       => 'Nome da campaña',
				'description' => 'Nome interno do envío masivo.',
				'example'     => 'Matrículas de outono',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_TEXT,
				'aliases'     => array( 'nome_campaña', 'nombre_campana' ),
			),
			'ligazon_enquisa' => array(
				'label'       => 'Ligazón á enquisa',
				'description' => 'Enderezo da enquisa; se está baleiro non se amosa o parágrafo.',
				'example'     => 'https://example.org/enquisa',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_URL,
			),
		);
	}

	/** @return string[] */
	private function globals(): array {
		return array( 'nome_anpa', 'sinatura' );
	}

	/**
	 * One valid event declaration, optionally overridden per test.
	 *
	 * @param  array<string,mixed> $overrides Fields to replace.
	 * @return array<string,mixed>
	 */
	private function event( array $overrides = array() ): array {
		return array_merge(
			array(
				'event_key'    => 'activity_change_notice',
				'display_name' => 'Cambio nunha actividade',
				'description'  => 'Avisa as familias dun cambio relevante nunha actividade.',
				'category'     => ANPA_Socios_Email_Template_Definition::CATEGORY_ACTIVITIES,
				'audience'     => ANPA_Socios_Email_Template_Definition::AUDIENCE_FAMILY,
				'phase'        => ANPA_Socios_Email_Template_Phase::FASE39,
				'variables'    => array( 'nome_actividade' => true ),
			),
			$overrides
		);
	}

	/**
	 * A valid LIVE event, which must also name the emitter it replaces.
	 *
	 * @param  array<string,mixed> $overrides Fields to replace.
	 * @return array<string,mixed>
	 */
	private function live_event( array $overrides = array() ): array {
		return $this->event(
			array_merge(
				array(
					'event_key'      => 'waitlist_place_offer',
					'phase'          => ANPA_Socios_Email_Template_Phase::LIVE,
					'legacy_emitter' => 'enviar_oferta_extraescolar',
				),
				$overrides
			)
		);
	}

	/**
	 * @param  array<int,array<string,mixed>>|null    $events     Event list.
	 * @param  array<string,array<string,mixed>>|null $dictionary Dictionary.
	 * @param  string[]|null                          $globals    Globals.
	 * @return ANPA_Socios_Email_Template_Set
	 */
	private function build( ?array $events = null, ?array $dictionary = null, ?array $globals = null ): ANPA_Socios_Email_Template_Set {
		return ANPA_Socios_Email_Template_Registry::build(
			null === $dictionary ? $this->dictionary() : $dictionary,
			null === $globals ? $this->globals() : $globals,
			null === $events ? array( $this->event() ) : $events
		);
	}

	/**
	 * @param string   $fragment Expected substring of the error message.
	 * @param callable $build    Builder that must throw.
	 */
	private function assert_rejected( string $fragment, callable $build ): void {
		try {
			$build();
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			$this->assertStringContainsString( $fragment, $e->getMessage() );
			return;
		}

		$this->fail( "expected the registry to be rejected for: {$fragment}" );
	}

	// ── The happy path ──────────────────────────────────────────────────

	public function test_a_valid_registry_builds_typed_definitions(): void {
		$set = $this->build();

		$this->assertTrue( $set->has( 'activity_change_notice' ) );
		$definition = $set->get( 'activity_change_notice' );
		$this->assertInstanceOf( ANPA_Socios_Email_Template_Definition::class, $definition );
		$this->assertSame( 'activity_change_notice', $definition->event_key() );
		$this->assertSame( ANPA_Socios_Email_Template_Phase::FASE39, $definition->phase()->id() );
		$this->assertNull( $definition->retired_in() );
		$this->assertFalse( $definition->is_live() );
		$this->assertTrue( $definition->is_emittable() );
	}

	public function test_globals_are_merged_into_every_event_and_are_optional(): void {
		$definition = $this->build()->get( 'activity_change_notice' );
		$variables  = $definition->variables();

		$this->assertTrue( $variables['nome_anpa']->is_global() );
		$this->assertFalse( $variables['nome_anpa']->is_required() );
		$this->assertFalse( $variables['nome_actividade']->is_global() );
		$this->assertTrue( $variables['nome_actividade']->is_required() );
		$this->assertSame( array( 'nome_actividade' ), $definition->required_tokens() );
	}

	public function test_default_template_falls_back_to_the_event_key(): void {
		$this->assertSame(
			'activity_change_notice',
			$this->build()->get( 'activity_change_notice' )->default_template()
		);
	}

	public function test_declared_tokens_match_what_the_renderer_expects(): void {
		$definition = $this->build()->get( 'activity_change_notice' );
		$declared   = $definition->declared_tokens();

		$this->assertSame( array( 'nome_anpa', 'sinatura', 'nome_actividade' ), array_keys( $declared ) );
		$this->assertSame( 'Robótica', $declared['nome_actividade']['example'] );

		$rendered = ANPA_Socios_Email_Template_Renderer::render(
			array(
				'subject'   => 'Cambio en {{nome_actividade}}',
				'body_html' => '<p>{{nome_actividade}} cambia. {{sinatura}}</p>',
				'body_text' => '{{nome_actividade}} cambia.',
			),
			$definition->sample_data(),
			$declared
		);
		$this->assertTrue( $rendered['ok'], 'the registry must feed the renderer directly' );
		$this->assertSame( 'Cambio en Robótica', $rendered['subject'] );
	}

	public function test_sample_data_is_derived_from_the_declared_examples(): void {
		$definition = $this->build()->get( 'activity_change_notice' );

		// Derived, not a second hand-maintained list: the preview can never show a value
		// the editor's variable panel does not promise.
		$sample = $definition->sample_data();
		foreach ( $definition->variables() as $token => $variable ) {
			$this->assertSame( $variable->example(), $sample[ $token ] );
		}
	}

	// ── The set is immutable and fingerprinted ──────────────────────────

	public function test_the_set_never_hands_out_its_backing_array(): void {
		$set = $this->build();

		// keys() returns scalars, so writing to the copy cannot reach the set.
		$keys   = $set->keys();
		$keys[] = 'injected';
		$this->assertNotContains( 'injected', $set->keys() );
		$this->assertFalse( $set->has( 'injected' ) );

		// all() is a generator, so there is no array for a caller to mutate, and each
		// call is independently traversable.
		$first  = iterator_to_array( $set->all() );
		$second = iterator_to_array( $set->all() );
		$this->assertSame( array_keys( $first ), array_keys( $second ) );
	}

	public function test_asking_for_an_unknown_event_throws_instead_of_returning_null(): void {
		// Returning null would push the failure to whatever tries to render the missing
		// template, which is much further from the mistake.
		$this->assert_rejected( "unknown template event 'nope'", fn() => $this->build()->get( 'nope' ) );
	}

	public function test_the_fingerprint_is_stable_for_identical_declarations(): void {
		$this->assertSame( $this->build()->fingerprint(), $this->build()->fingerprint() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $this->build()->fingerprint() );
	}

	public function test_any_change_to_a_declaration_moves_the_fingerprint(): void {
		$base = $this->build()->fingerprint();

		$reworded = $this->build( array( $this->event( array( 'description' => 'Outra descrición.' ) ) ) );
		$this->assertNotSame( $base, $reworded->fingerprint(), 'a reworded description must be visible' );

		$dictionary                            = $this->dictionary();
		$dictionary['nome_actividade']['label'] = 'Actividade';
		$this->assertNotSame( $base, $this->build( null, $dictionary )->fingerprint(), 'a relabelled variable must be visible' );
	}

	public function test_reordering_the_declarations_moves_the_fingerprint(): void {
		// Order is part of the input, not sorted away: the order events are declared in is
		// the order the editor shows them in, so a reorder is a real change.
		$a = $this->event( array( 'event_key' => 'first_event' ) );
		$b = $this->event( array( 'event_key' => 'second_event' ) );

		$this->assertNotSame(
			$this->build( array( $a, $b ) )->fingerprint(),
			$this->build( array( $b, $a ) )->fingerprint()
		);
	}

	public function test_the_key_order_is_the_declaration_order_and_is_deterministic(): void {
		$events = array(
			$this->event( array( 'event_key' => 'zulu_event' ) ),
			$this->event( array( 'event_key' => 'alpha_event' ) ),
		);

		$this->assertSame( array( 'zulu_event', 'alpha_event' ), $this->build( $events )->keys() );
		$this->assertSame( $this->build( $events )->keys(), $this->build( $events )->keys() );
	}

	// ── Live events and the join with the golden oracle ─────────────────

	public function test_a_live_event_names_the_emitter_it_replaces(): void {
		$set = $this->build( array( $this->live_event() ) );

		$this->assertSame( array( 'waitlist_place_offer' ), $set->live_keys() );
		$this->assertSame(
			array( 'enviar_oferta_extraescolar' => 'waitlist_place_offer' ),
			$set->legacy_emitters()
		);
	}

	public function test_a_live_event_without_a_legacy_emitter_is_rejected(): void {
		// Without it, a live email could exist with no counterpart in the golden oracle
		// and the bidirectional check would pass straight over it.
		$this->assert_rejected(
			'does not name the emitter it replaces',
			fn() => $this->build( array( $this->live_event( array( 'legacy_emitter' => '' ) ) ) )
		);
	}

	public function test_a_not_yet_live_event_may_not_name_a_legacy_emitter(): void {
		$this->assert_rejected(
			'is not live but names legacy emitter',
			fn() => $this->build( array( $this->event( array( 'legacy_emitter' => 'enviar_algo' ) ) ) )
		);
	}

	public function test_a_malformed_legacy_emitter_is_rejected(): void {
		$this->assert_rejected(
			'invalid legacy emitter',
			fn() => $this->build( array( $this->live_event( array( 'legacy_emitter' => 'sendSomething' ) ) ) )
		);
	}

	public function test_two_events_claiming_the_same_legacy_emitter_are_rejected(): void {
		$this->assert_rejected(
			'is claimed by both',
			fn() => $this->build(
				array(
					$this->live_event( array( 'event_key' => 'first_live' ) ),
					$this->live_event( array( 'event_key' => 'second_live' ) ),
				)
			)
		);
	}

	// ── Phases ──────────────────────────────────────────────────────────

	public function test_an_unknown_phase_is_rejected(): void {
		// A typed phase, so an invented phase name cannot exist at all.
		$this->assert_rejected(
			"unknown delivery phase 'fase99'",
			fn() => $this->build( array( $this->event( array( 'phase' => 'fase99' ) ) ) )
		);
	}

	public function test_an_event_without_a_phase_is_rejected(): void {
		$this->assert_rejected(
			'does not say which phase owns its emitter',
			fn() => $this->build( array( $this->event( array( 'phase' => '' ) ) ) )
		);
	}

	public function test_phases_are_interned_so_identity_comparison_is_safe(): void {
		$this->assertSame(
			ANPA_Socios_Email_Template_Phase::from( ANPA_Socios_Email_Template_Phase::FASE39 ),
			ANPA_Socios_Email_Template_Phase::from( ANPA_Socios_Email_Template_Phase::FASE39 )
		);
		$this->assertTrue(
			ANPA_Socios_Email_Template_Phase::from( ANPA_Socios_Email_Template_Phase::LIVE )->is_live()
		);
	}

	public function test_there_is_no_stored_emitter_status(): void {
		// It was declared once and removed as redundant: implemented/planned is "is the
		// owning phase live", deprecated is "a retirement phase is set", and internal was
		// already category=system plus audience=board. A stored copy of a derivable fact
		// is a copy that eventually disagrees with the fact.
		$this->assertFalse(
			method_exists( ANPA_Socios_Email_Template_Definition::class, 'emitter_statuses' ),
			'emitter_status must stay derived, not stored'
		);
		$this->assertArrayNotHasKey(
			'emitter_status',
			$this->build()->get( 'activity_change_notice' )->to_array()
		);
	}

	// ── Retirement ──────────────────────────────────────────────────────

	public function test_a_retired_event_is_neither_live_nor_emittable(): void {
		$set = $this->build(
			array(
				$this->event(
					array(
						'phase'      => ANPA_Socios_Email_Template_Phase::LIVE,
						'retired_in' => ANPA_Socios_Email_Template_Phase::FASE41,
					)
				),
			)
		);

		$definition = $set->get( 'activity_change_notice' );
		$this->assertFalse( $definition->is_live() );
		$this->assertFalse( $definition->is_emittable() );
		$this->assertTrue( $definition->is_retired() );
		$this->assertSame( ANPA_Socios_Email_Template_Phase::FASE41, $definition->retired_in()->id() );
		$this->assertSame( array(), $set->live_keys() );
	}

	public function test_an_event_cannot_be_retired_by_the_phase_that_introduced_it(): void {
		$this->assert_rejected(
			'retired by the same phase that introduced it',
			fn() => $this->build(
				array( $this->event( array( 'retired_in' => ANPA_Socios_Email_Template_Phase::FASE39 ) ) )
			)
		);
	}

	public function test_an_unknown_retirement_phase_is_rejected(): void {
		$this->assert_rejected(
			"unknown delivery phase 'someday'",
			fn() => $this->build( array( $this->event( array( 'retired_in' => 'someday' ) ) ) )
		);
	}

	// ── Aliases ─────────────────────────────────────────────────────────

	public function test_aliases_are_derived_from_the_variables_the_event_declares(): void {
		$set     = $this->build(
			array( $this->event( array( 'variables' => array( 'nome_campana' => true ) ) ) )
		);
		$aliases = $set->get( 'activity_change_notice' )->aliases();

		$this->assertSame(
			array(
				'nome_campaña'   => 'nome_campana',
				'nombre_campana' => 'nome_campana',
			),
			$aliases
		);
		$this->assertSame(
			'{{nome_campana}} {{nome_campana}}',
			ANPA_Socios_Email_Template_Renderer::canonicalise( '{{nome_campaña}} {{nombre_campana}}', $aliases )
		);
	}

	public function test_an_event_without_the_alias_owner_does_not_carry_the_alias(): void {
		$this->assertSame( array(), $this->build()->get( 'activity_change_notice' )->aliases() );
	}

	public function test_the_same_alias_is_shared_by_every_event_that_uses_its_variable(): void {
		// This is why aliases live on the variable and not on the event: campaign tokens
		// are needed by several events at once, so per-event alias tables would make
		// global uniqueness and reuse mutually exclusive.
		$set = $this->build(
			array(
				$this->event( array( 'event_key' => 'first_campaign', 'variables' => array( 'nome_campana' => true ) ) ),
				$this->event( array( 'event_key' => 'second_campaign', 'variables' => array( 'nome_campana' => true ) ) ),
			)
		);

		$this->assertSame(
			$set->get( 'first_campaign' )->aliases(),
			$set->get( 'second_campaign' )->aliases()
		);
	}

	public function test_resolving_an_alias_never_lands_on_another_alias(): void {
		// One hop, always. Structural today, but the invariant is what matters: an alias
		// chain would make "which variable did the operator mean" depend on iteration
		// order.
		$set = $this->build(
			array( $this->event( array( 'variables' => array( 'nome_campana' => true, 'ligazon_enquisa' => false ) ) ) )
		);

		$definition = $set->get( 'activity_change_notice' );
		$aliases    = $definition->aliases();
		$canonical  = array_keys( $definition->variables() );

		foreach ( $aliases as $alias => $target ) {
			$this->assertContains( $target, $canonical, "alias '{$alias}' must resolve to a declared variable" );
			$this->assertArrayNotHasKey( $target, $aliases, "alias '{$alias}' resolves to another alias" );
		}
	}

	public function test_canonicalisation_is_idempotent(): void {
		$definition = $this->build(
			array( $this->event( array( 'variables' => array( 'nome_campana' => true ) ) ) )
		)->get( 'activity_change_notice' );

		$once  = ANPA_Socios_Email_Template_Renderer::canonicalise(
			'{{nome_campaña}} {{#nombre_campana}}x{{/}}',
			$definition->aliases()
		);
		$twice = ANPA_Socios_Email_Template_Renderer::canonicalise( $once, $definition->aliases() );

		$this->assertSame( $once, $twice );
	}

	public function test_two_variables_claiming_the_same_alias_are_rejected(): void {
		$dictionary                               = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'nome_campaña' );

		$this->assert_rejected( 'is claimed by both', fn() => $this->build( null, $dictionary ) );
	}

	public function test_an_alias_shadowing_a_canonical_variable_is_rejected(): void {
		$dictionary                               = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'nome_anpa' );

		$this->assert_rejected( 'shadows the canonical variable', fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_variable_aliasing_itself_is_rejected(): void {
		$dictionary                               = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'nome_actividade' );

		$this->assert_rejected( 'lists itself as an alias', fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_repeated_alias_on_one_variable_is_rejected(): void {
		$dictionary                               = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'actividade', 'actividade' );

		$this->assert_rejected( "repeats the alias 'actividade'", fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_malformed_alias_is_rejected(): void {
		$dictionary                               = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'nome actividade' );

		$this->assert_rejected( "invalid alias 'nome actividade'", fn() => $this->build( null, $dictionary ) );
	}

	// ── Rejections: structure ───────────────────────────────────────────

	public function test_duplicate_event_keys_are_rejected(): void {
		$this->assert_rejected(
			"duplicate event key 'activity_change_notice'",
			fn() => $this->build( array( $this->event(), $this->event() ) )
		);
	}

	public function test_an_empty_registry_is_rejected(): void {
		$this->assert_rejected( 'declares no events', fn() => $this->build( array() ) );
	}

	public function test_an_empty_dictionary_is_rejected(): void {
		$this->assert_rejected( 'dictionary is empty', fn() => $this->build( null, array() ) );
	}

	public function test_a_non_ascii_event_key_is_rejected(): void {
		$this->assert_rejected(
			'invalid key',
			fn() => $this->build( array( $this->event( array( 'event_key' => 'cambio_actividadé' ) ) ) )
		);
	}

	public function test_an_invalid_default_template_stem_is_rejected(): void {
		$this->assert_rejected(
			'invalid default template stem',
			fn() => $this->build( array( $this->event( array( 'default_template' => '../etc/passwd' ) ) ) )
		);
	}

	// ── Rejections: variables ───────────────────────────────────────────

	public function test_a_variable_without_an_example_is_rejected(): void {
		$dictionary                               = $this->dictionary();
		$dictionary['nome_actividade']['example'] = '';

		$this->assert_rejected( 'has no example', fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_variable_without_a_description_is_rejected(): void {
		$dictionary                                   = $this->dictionary();
		$dictionary['nome_actividade']['description'] = '';

		$this->assert_rejected( "variable 'nome_actividade' has no description", fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_variable_without_a_label_is_rejected(): void {
		$dictionary                             = $this->dictionary();
		$dictionary['nome_actividade']['label'] = '';

		$this->assert_rejected( 'has no label', fn() => $this->build( null, $dictionary ) );
	}

	public function test_an_unknown_variable_type_is_rejected(): void {
		$dictionary                            = $this->dictionary();
		$dictionary['nome_actividade']['type'] = 'markdown';

		$this->assert_rejected( "unknown type 'markdown'", fn() => $this->build( null, $dictionary ) );
	}

	public function test_an_accented_canonical_variable_key_is_rejected(): void {
		$dictionary                 = $this->dictionary();
		$dictionary['nome_campaña'] = $dictionary['nome_campana'];
		unset( $dictionary['nome_campana'] );

		$this->assert_rejected( 'must be lowercase ASCII', fn() => $this->build( null, $dictionary ) );
	}

	public function test_an_event_declaring_an_unknown_variable_is_rejected(): void {
		$this->assert_rejected(
			"declares variable 'nome_do_can', which is not in the dictionary",
			fn() => $this->build( array( $this->event( array( 'variables' => array( 'nome_do_can' => true ) ) ) ) )
		);
	}

	public function test_an_event_redeclaring_a_global_is_rejected(): void {
		// Two declarations of one token can disagree about whether it is required.
		$this->assert_rejected(
			"redeclares global variable 'nome_anpa'",
			fn() => $this->build( array( $this->event( array( 'variables' => array( 'nome_anpa' => true ) ) ) ) )
		);
	}

	public function test_a_global_missing_from_the_dictionary_is_rejected(): void {
		$this->assert_rejected(
			"global variable 'nome_inexistente' is not in the dictionary",
			fn() => $this->build( null, null, array( 'nome_anpa', 'nome_inexistente' ) )
		);
	}

	public function test_an_event_with_no_variables_at_all_is_rejected(): void {
		$this->assert_rejected(
			'declares no variables',
			fn() => $this->build( array( $this->event( array( 'variables' => array() ) ) ), null, array() )
		);
	}

	// ── Rejections: descriptive metadata ────────────────────────────────

	public function test_an_event_without_a_display_name_is_rejected(): void {
		$this->assert_rejected(
			'has no display name',
			fn() => $this->build( array( $this->event( array( 'display_name' => '  ' ) ) ) )
		);
	}

	public function test_an_event_without_a_description_is_rejected(): void {
		// The editor would show a bare technical key, and nobody edits what they cannot
		// identify.
		$this->assert_rejected(
			'has no description',
			fn() => $this->build( array( $this->event( array( 'description' => '' ) ) ) )
		);
	}

	public function test_an_unknown_category_is_rejected(): void {
		$this->assert_rejected(
			"unknown category 'payments'",
			fn() => $this->build( array( $this->event( array( 'category' => 'payments' ) ) ) )
		);
	}

	public function test_there_is_no_payments_category(): void {
		// The plugin handles no charges and no shipped default may claim one, so a
		// category nothing may legitimately use must not exist to be filled later.
		$this->assertNotContains( 'payments', ANPA_Socios_Email_Template_Definition::categories() );
	}

	public function test_an_unknown_audience_is_rejected(): void {
		$this->assert_rejected(
			"unknown audience 'everyone'",
			fn() => $this->build( array( $this->event( array( 'audience' => 'everyone' ) ) ) )
		);
	}

	// ── The engine stays pure ───────────────────────────────────────────

	public function test_the_registry_classes_do_not_touch_wordpress(): void {
		$files = array(
			'class-anpa-socios-email-template-registry.php',
			'class-anpa-socios-email-template-definition.php',
			'class-anpa-socios-email-template-variable.php',
			'class-anpa-socios-email-template-set.php',
			'class-anpa-socios-email-template-phase.php',
		);

		foreach ( $files as $file ) {
			$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/' . $file );
			foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters', '__(' ) as $wp ) {
				$this->assertStringNotContainsString(
					$wp,
					$src,
					"{$file} must stay unit-testable without WordPress loaded"
				);
			}
		}
	}
}
