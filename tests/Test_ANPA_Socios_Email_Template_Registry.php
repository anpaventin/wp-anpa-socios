<?php
/**
 * Tests for the template registry engine (fase36, PR-36s1b1).
 *
 * The engine is exercised against deliberately broken fixtures, not against the real
 * 28 events. That is the only way the error paths get covered: a registry that is
 * correct today would leave every validation branch untested, and an unexercised
 * validation is indistinguishable from a missing one.
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
			'nome_anpa'         => array(
				'label'       => 'Nome da ANPA',
				'description' => 'Nome da asociación, tomado dos axustes.',
				'example'     => 'ANPA Exemplo',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_TEXT,
			),
			'sinatura'          => array(
				'label'       => 'Sinatura',
				'description' => 'Sinatura configurada para os correos.',
				'example'     => 'A Xunta Directiva',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_MULTILINE,
			),
			'nome_actividade'   => array(
				'label'       => 'Nome da actividade',
				'description' => 'Actividade extraescolar á que se refire o correo.',
				'example'     => 'Robótica',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_TEXT,
			),
			'nome_campana'      => array(
				'label'       => 'Nome da campaña',
				'description' => 'Nome interno do envío masivo.',
				'example'     => 'Matrículas de outono',
				'type'        => ANPA_Socios_Email_Template_Variable::TYPE_TEXT,
				'aliases'     => array( 'nome_campaña', 'nombre_campana' ),
			),
			'ligazon_enquisa'   => array(
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
				'event_key'      => 'activity_change_notice',
				'display_name'   => 'Cambio nunha actividade',
				'description'    => 'Avisa as familias dun cambio relevante nunha actividade.',
				'category'       => ANPA_Socios_Email_Template_Definition::CATEGORY_ACTIVITIES,
				'audience'       => ANPA_Socios_Email_Template_Definition::AUDIENCE_FAMILY,
				'emitter_status' => ANPA_Socios_Email_Template_Definition::EMITTER_PLANNED,
				'introduced_in'  => 'fase39',
				'variables'      => array( 'nome_actividade' => true ),
			),
			$overrides
		);
	}

	/**
	 * @param  array<int,array<string,mixed>>|null   $events     Event list.
	 * @param  array<string,array<string,mixed>>|null $dictionary Dictionary.
	 * @param  string[]|null                          $globals    Globals.
	 * @return array<string,ANPA_Socios_Email_Template_Definition>
	 */
	private function build( ?array $events = null, ?array $dictionary = null, ?array $globals = null ): array {
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
		$registry = $this->build();

		$this->assertArrayHasKey( 'activity_change_notice', $registry );
		$definition = $registry['activity_change_notice'];
		$this->assertInstanceOf( ANPA_Socios_Email_Template_Definition::class, $definition );
		$this->assertSame( 'activity_change_notice', $definition->event_key() );
		$this->assertSame( 'fase39', $definition->introduced_in() );
		$this->assertSame( '', $definition->deprecated_in() );
		$this->assertFalse( $definition->is_live() );
		$this->assertTrue( $definition->is_emittable() );
	}

	public function test_globals_are_merged_into_every_event_and_are_optional(): void {
		$definition = $this->build()['activity_change_notice'];
		$variables  = $definition->variables();

		$this->assertArrayHasKey( 'nome_anpa', $variables );
		$this->assertArrayHasKey( 'sinatura', $variables );
		$this->assertTrue( $variables['nome_anpa']->is_global() );
		$this->assertFalse( $variables['nome_anpa']->is_required() );
		$this->assertFalse( $variables['nome_actividade']->is_global() );
		$this->assertTrue( $variables['nome_actividade']->is_required() );
		$this->assertSame( array( 'nome_actividade' ), $definition->required_tokens() );
	}

	public function test_default_template_falls_back_to_the_event_key(): void {
		$this->assertSame(
			'activity_change_notice',
			$this->build()['activity_change_notice']->default_template()
		);
	}

	public function test_declared_tokens_match_what_the_renderer_expects(): void {
		$definition = $this->build()['activity_change_notice'];
		$declared   = $definition->declared_tokens();

		// The renderer only asks "is this token declared?", so the map must be keyed
		// by token and carry a descriptor array.
		$this->assertSame(
			array( 'nome_anpa', 'sinatura', 'nome_actividade' ),
			array_keys( $declared )
		);
		$this->assertIsArray( $declared['nome_actividade'] );
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
		$definition = $this->build()['activity_change_notice'];

		// Derived, not a second hand-maintained list: the preview can never show a value
		// the editor's variable panel does not promise.
		$sample = $definition->sample_data();
		foreach ( $definition->variables() as $token => $variable ) {
			$this->assertSame( $variable->example(), $sample[ $token ] );
		}
	}

	public function test_aliases_are_derived_from_the_variables_the_event_declares(): void {
		$with_campaign = $this->event(
			array(
				'event_key' => 'email_campaign_summary_admin',
				'variables' => array( 'nome_campana' => true ),
			)
		);

		$registry = $this->build( array( $with_campaign ) );
		$aliases  = $registry['email_campaign_summary_admin']->aliases();

		$this->assertSame(
			array(
				'nome_campaña'   => 'nome_campana',
				'nombre_campana' => 'nome_campana',
			),
			$aliases
		);

		// And they are usable by the renderer's save-time canonicalisation.
		$this->assertSame(
			'{{nome_campana}} {{nome_campana}}',
			ANPA_Socios_Email_Template_Renderer::canonicalise( '{{nome_campaña}} {{nombre_campana}}', $aliases )
		);
	}

	public function test_an_event_without_the_alias_owner_does_not_carry_the_alias(): void {
		// The activity event does not declare nome_campana, so its alias must not leak
		// into it: a template could otherwise canonicalise to a token it cannot use.
		$this->assertSame( array(), $this->build()['activity_change_notice']->aliases() );
	}

	public function test_the_same_alias_is_shared_by_every_event_that_uses_its_variable(): void {
		// This is why aliases live on the variable and not on the event: campaign tokens
		// are needed by several events at once, so per-event alias tables would make
		// global uniqueness and reuse mutually exclusive.
		$registry = $this->build(
			array(
				$this->event( array( 'event_key' => 'first_campaign', 'variables' => array( 'nome_campana' => true ) ) ),
				$this->event( array( 'event_key' => 'second_campaign', 'variables' => array( 'nome_campana' => true ) ) ),
			)
		);

		$this->assertSame(
			$registry['first_campaign']->aliases(),
			$registry['second_campaign']->aliases()
		);
	}

	// ── Rejections: the registry itself ─────────────────────────────────

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

	// ── Rejections: variables ───────────────────────────────────────────

	public function test_a_variable_without_an_example_is_rejected(): void {
		$dictionary                       = $this->dictionary();
		$dictionary['nome_actividade']['example'] = '';

		$this->assert_rejected( 'has no example', fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_variable_without_a_description_is_rejected(): void {
		$dictionary                           = $this->dictionary();
		$dictionary['nome_actividade']['description'] = '';

		$this->assert_rejected( "variable 'nome_actividade' has no description", fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_variable_without_a_label_is_rejected(): void {
		$dictionary                     = $this->dictionary();
		$dictionary['nome_actividade']['label'] = '';

		$this->assert_rejected( 'has no label', fn() => $this->build( null, $dictionary ) );
	}

	public function test_an_unknown_variable_type_is_rejected(): void {
		$dictionary                    = $this->dictionary();
		$dictionary['nome_actividade']['type'] = 'markdown';

		$this->assert_rejected( "unknown type 'markdown'", fn() => $this->build( null, $dictionary ) );
	}

	public function test_an_accented_canonical_variable_key_is_rejected(): void {
		$dictionary = $this->dictionary();
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

	// ── Rejections: aliases ─────────────────────────────────────────────

	public function test_two_variables_claiming_the_same_alias_are_rejected(): void {
		$dictionary                          = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'nome_campaña' );

		$this->assert_rejected( "is claimed by both", fn() => $this->build( null, $dictionary ) );
	}

	public function test_an_alias_shadowing_a_canonical_variable_is_rejected(): void {
		$dictionary                          = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'nome_anpa' );

		$this->assert_rejected( 'shadows the canonical variable', fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_variable_aliasing_itself_is_rejected(): void {
		$dictionary                          = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'nome_actividade' );

		$this->assert_rejected( 'lists itself as an alias', fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_repeated_alias_on_one_variable_is_rejected(): void {
		$dictionary                          = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'actividade', 'actividade' );

		$this->assert_rejected( "repeats the alias 'actividade'", fn() => $this->build( null, $dictionary ) );
	}

	public function test_a_malformed_alias_is_rejected(): void {
		$dictionary                          = $this->dictionary();
		$dictionary['nome_actividade']['aliases'] = array( 'nome actividade' );

		$this->assert_rejected( "invalid alias 'nome actividade'", fn() => $this->build( null, $dictionary ) );
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

	public function test_an_unknown_emitter_status_is_rejected(): void {
		// An enum, not a boolean: "does an emitter exist" and "should anything emit it"
		// are different questions, and a boolean can only answer one.
		$this->assert_rejected(
			"unknown emitter status 'live'",
			fn() => $this->build( array( $this->event( array( 'emitter_status' => 'live' ) ) ) )
		);
	}

	public function test_an_event_without_an_introducing_phase_is_rejected(): void {
		$this->assert_rejected(
			'does not say which phase owns its emitter',
			fn() => $this->build( array( $this->event( array( 'introduced_in' => '' ) ) ) )
		);
	}

	public function test_a_deprecated_event_must_say_when_it_was_retired(): void {
		$this->assert_rejected(
			'does not say when it was retired',
			fn() => $this->build(
				array(
					$this->event(
						array( 'emitter_status' => ANPA_Socios_Email_Template_Definition::EMITTER_DEPRECATED )
					),
				)
			)
		);
	}

	public function test_a_live_event_may_not_declare_a_retirement_phase(): void {
		$this->assert_rejected(
			'is not deprecated but declares deprecated_in',
			fn() => $this->build( array( $this->event( array( 'deprecated_in' => 'fase40' ) ) ) )
		);
	}

	public function test_a_deprecated_event_is_not_emittable(): void {
		$registry = $this->build(
			array(
				$this->event(
					array(
						'emitter_status' => ANPA_Socios_Email_Template_Definition::EMITTER_DEPRECATED,
						'deprecated_in'  => 'fase40',
					)
				),
			)
		);

		$definition = $registry['activity_change_notice'];
		$this->assertFalse( $definition->is_emittable() );
		$this->assertFalse( $definition->is_live() );
		$this->assertSame( 'fase40', $definition->deprecated_in() );
	}

	public function test_an_invalid_default_template_stem_is_rejected(): void {
		$this->assert_rejected(
			'invalid default template stem',
			fn() => $this->build( array( $this->event( array( 'default_template' => '../etc/passwd' ) ) ) )
		);
	}

	public function test_an_event_with_no_variables_at_all_is_rejected(): void {
		$this->assert_rejected(
			'declares no variables',
			fn() => $this->build( array( $this->event( array( 'variables' => array() ) ) ), null, array() )
		);
	}

	// ── The engine stays pure ───────────────────────────────────────────

	public function test_the_registry_classes_do_not_touch_wordpress(): void {
		$files = array(
			'class-anpa-socios-email-template-registry.php',
			'class-anpa-socios-email-template-definition.php',
			'class-anpa-socios-email-template-variable.php',
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
