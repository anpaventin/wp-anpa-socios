<?php
/**
 * Tests for the declared template events (fase36, PR-36s1b2).
 *
 * Properties, never counts. "There are 31 templates" is documentation; a test that asserts
 * it fails the day a legitimate template is added, which teaches people to edit the number
 * instead of reading the failure. What is asserted here is what must stay true no matter how
 * many templates exist — above all the **bijection** between the live events and the golden
 * oracle, in both directions.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Events extends TestCase {

	/**
	 * @return ANPA_Socios_Email_Template_Set
	 */
	private function set(): ANPA_Socios_Email_Template_Set {
		return ANPA_Socios_Email_Template_Events::set();
	}

	/** @return string Directory holding the golden files. */
	private function golden_dir(): string {
		return __DIR__ . '/golden';
	}

	// ── The declarations are internally consistent ──────────────────────

	public function test_the_declared_registry_builds_without_a_validation_error(): void {
		// Every rule the engine enforces — unique keys, known phases, declared variables,
		// alias uniqueness, live events naming their emitter — is exercised here against the
		// real data. If any declaration is inconsistent, this throws.
		$set = $this->set();

		$this->assertInstanceOf( ANPA_Socios_Email_Template_Set::class, $set );
		$this->assertGreaterThan( 0, $set->count() );
	}

	public function test_the_set_is_memoised_and_therefore_identical_across_calls(): void {
		$this->assertSame( ANPA_Socios_Email_Template_Events::set(), ANPA_Socios_Email_Template_Events::set() );
	}

	public function test_every_event_key_is_unique_and_an_english_ascii_identifier(): void {
		$keys = $this->set()->keys();

		$this->assertSame( array_unique( $keys ), $keys );
		foreach ( $keys as $key ) {
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*$/', $key );
		}
	}

	public function test_every_canonical_token_is_ascii_even_though_the_content_is_galician(): void {
		// The documented exception: token NAMES stay ASCII, token CONTENT and labels are
		// Galician. An accent inside a token depends on the encoding surviving every editor,
		// collation and regex in the path, and this project already had one such incident.
		foreach ( ANPA_Socios_Email_Template_Events::dictionary() as $token => $spec ) {
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*$/', (string) $token );
		}
	}

	public function test_every_event_offers_at_least_one_variable(): void {
		foreach ( $this->set()->all() as $key => $definition ) {
			$this->assertNotSame( array(), $definition->variables(), "{$key} offers no variables" );
		}
	}

	public function test_every_event_carries_the_globals(): void {
		$globals = ANPA_Socios_Email_Template_Events::globals();

		foreach ( $this->set()->all() as $key => $definition ) {
			foreach ( $globals as $token ) {
				$this->assertArrayHasKey( $token, $definition->variables(), "{$key} is missing {$token}" );
			}
		}
	}

	public function test_every_variable_the_registry_offers_is_previewable(): void {
		// A token with no sample renders an empty preview, and an operator who cannot see
		// what a token looks like picks the wrong one.
		foreach ( $this->set()->all() as $key => $definition ) {
			foreach ( $definition->sample_data() as $token => $sample ) {
				$this->assertNotSame( '', trim( $sample ), "{$key}.{$token} has no usable sample" );
			}
		}
	}

	public function test_no_sample_or_label_contains_real_personal_data(): void {
		// This registry ships in a public repository reused by other associations.
		$haystack = strtolower( (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-events.php' ) );

		foreach ( array( 'asbranas', 'as brañas', 'ventin', 'ventín', 'anpaventin', 'casabetty', 'ionos' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $haystack, "leaks '{$forbidden}'" );
		}

		foreach ( $this->set()->all() as $key => $definition ) {
			foreach ( $definition->sample_data() as $token => $sample ) {
				if ( 1 === preg_match( '/@/', $sample ) ) {
					$this->assertMatchesRegularExpression(
						'/@example\.(com|org)/',
						$sample,
						"{$key}.{$token} must use a fictitious address"
					);
				}
			}
		}
	}

	// ── The join with the golden oracle: a bijection, not a count ───────

	public function test_the_live_events_and_the_golden_files_are_a_bijection(): void {
		$declared = array_keys( $this->set()->legacy_emitters() );
		sort( $declared, SORT_STRING );

		$captured = array_map(
			static function ( $file ): string {
				return basename( (string) $file, '.txt' );
			},
			(array) glob( $this->golden_dir() . '/*.txt' )
		);
		sort( $captured, SORT_STRING );

		// Both directions at once. A live event with no golden file would be a template
		// whose wording nothing pins; a golden file with no live event would be an email the
		// registry has forgotten to describe. Neither can pass.
		$this->assertSame(
			$captured,
			$declared,
			'the live events and the captured emails must describe exactly the same set'
		);
	}

	public function test_every_live_event_is_live_and_every_other_event_is_not(): void {
		foreach ( $this->set()->all() as $key => $definition ) {
			if ( '' !== $definition->legacy_emitter() ) {
				$this->assertTrue( $definition->is_live(), "{$key} names an emitter but is not live" );
			} else {
				$this->assertFalse( $definition->is_live(), "{$key} is live but names no emitter" );
			}
		}

		$this->assertSame(
			array_values( $this->set()->legacy_emitters() ),
			$this->set()->live_keys()
		);
	}

	public function test_nothing_is_retired_yet(): void {
		foreach ( $this->set()->all() as $key => $definition ) {
			$this->assertTrue( $definition->is_emittable(), "{$key} is retired unexpectedly" );
		}
	}

	// ── Aliases ─────────────────────────────────────────────────────────

	public function test_the_campaign_spellings_resolve_to_the_ascii_canonical_token(): void {
		$definition = $this->set()->get( 'email_campaign_summary_admin' );
		$aliases    = $definition->aliases();

		$this->assertSame(
			'{{nome_campana}} {{nome_campana}} {{ligazon_campana}}',
			ANPA_Socios_Email_Template_Renderer::canonicalise(
				'{{nome_campaña}} {{nombre_campana}} {{ligazon_campaña}}',
				$aliases
			)
		);
	}

	public function test_no_alias_resolves_to_another_alias(): void {
		foreach ( $this->set()->all() as $key => $definition ) {
			$aliases   = $definition->aliases();
			$canonical = array_keys( $definition->variables() );

			foreach ( $aliases as $alias => $target ) {
				$this->assertContains( $target, $canonical, "{$key}: alias '{$alias}' is not declared" );
				$this->assertArrayNotHasKey( $target, $aliases, "{$key}: alias '{$alias}' points at an alias" );
			}
		}
	}

	// ── Determinism ─────────────────────────────────────────────────────

	public function test_the_declaration_order_is_deterministic(): void {
		$first  = ANPA_Socios_Email_Template_Registry::build(
			ANPA_Socios_Email_Template_Events::dictionary(),
			ANPA_Socios_Email_Template_Events::globals(),
			$this->declarations()
		);
		$second = ANPA_Socios_Email_Template_Registry::build(
			ANPA_Socios_Email_Template_Events::dictionary(),
			ANPA_Socios_Email_Template_Events::globals(),
			$this->declarations()
		);

		$this->assertSame( $first->keys(), $second->keys() );
		$this->assertSame( $first->fingerprint(), $second->fingerprint() );

		foreach ( $first->keys() as $key ) {
			$this->assertSame(
				array_keys( $first->get( $key )->variables() ),
				array_keys( $second->get( $key )->variables() )
			);
		}
	}

	/**
	 * The private declaration list, for the determinism check above.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function declarations(): array {
		$method = new ReflectionMethod( ANPA_Socios_Email_Template_Events::class, 'declarations' );
		$method->setAccessible( true );

		return (array) $method->invoke( null );
	}

	// ── The fingerprint golden ──────────────────────────────────────────

	/**
	 * The registry fingerprint is a compatibility contract, so it is pinned in a golden
	 * file that is GENERATED, never hand-edited: `ANPA_REGISTRY_GOLDEN_CAPTURE=1` writes it,
	 * any other run asserts it. A hash maintained by hand invites "fix the test" instead of
	 * "explain the change".
	 *
	 * Adding, removing or reordering a template is therefore a deliberate three-part diff:
	 * the declaration, the fingerprint, the golden.
	 */
	public function test_the_registry_fingerprint_matches_its_golden(): void {
		$path        = $this->golden_dir() . '/template_registry.sha256';
		$fingerprint = $this->set()->fingerprint();

		if ( '1' === (string) getenv( 'ANPA_REGISTRY_GOLDEN_CAPTURE' ) ) {
			if ( ! is_dir( $this->golden_dir() ) ) {
				mkdir( $this->golden_dir(), 0755, true );
			}
			file_put_contents( $path, $fingerprint . "\n" );
			$this->assertFileExists( $path );
			return;
		}

		$this->assertFileExists( $path, 'capture it with ANPA_REGISTRY_GOLDEN_CAPTURE=1' );
		$this->assertSame(
			trim( (string) file_get_contents( $path ) ),
			$fingerprint,
			'the template registry contract changed; if that was intended, recapture the golden and explain the change'
		);
	}

	public function test_the_golden_records_the_scheme_that_produced_it(): void {
		// A scheme bump must not be able to silently reuse an old digest.
		$path = $this->golden_dir() . '/template_registry.sha256';
		if ( ! is_readable( $path ) ) {
			$this->markTestSkipped( 'fingerprint golden not captured yet.' );
		}

		$recorded = trim( (string) file_get_contents( $path ) );
		$this->assertStringStartsWith(
			ANPA_Socios_Email_Template_Set::FINGERPRINT_SCHEME . ':',
			$recorded,
			'the golden was produced by a different fingerprint scheme'
		);
	}

	// ── Purity ──────────────────────────────────────────────────────────

	public function test_the_declarations_do_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-events.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters', '__(' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}
}
