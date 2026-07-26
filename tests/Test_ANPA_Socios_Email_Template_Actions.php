<?php
/**
 * Tests for the pending-action catalogue (fase36, PR-36s1c).
 *
 * The point of this suite is the distinction between OBSERVABLE and OPERATIONAL. The catalogue is
 * a declared contract precisely so that rewording what a family reads cannot happen silently — but
 * a digest that also moved when somebody corrected a roadmap annotation would be noise, and a
 * digest nobody reads is worse than none. So both directions are asserted: visible change moves
 * the digest, internal note does not.
 *
 * The digests are computed over MUTATED COPIES via the documented seam. The real catalogue is
 * never edited to make a test pass.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Actions extends TestCase {

	/** @return array<string,array<string,mixed>> A working copy of the real catalogue. */
	private function catalogue(): array {
		return ANPA_Socios_Email_Template_Actions::TYPES;
	}

	// ── The declaration answers the five questions ───────────────────────

	public function test_every_type_answers_what_where_when_and_what_otherwise(): void {
		foreach ( ANPA_Socios_Email_Template_Actions::supported_types() as $type ) {
			$declaration = ANPA_Socios_Email_Template_Actions::declaration( $type );

			$this->assertNotSame( '', trim( (string) $declaration['label'] ), "{$type}: no label" );
			$this->assertNotSame( '', trim( (string) $declaration['description'] ), "{$type}: no description" );
			$this->assertNotSame( '', trim( (string) $declaration['consequence'] ), "{$type}: no consequence" );
			$this->assertNotSame( '', trim( (string) $declaration['entity'] ), "{$type}: no entity" );
			$this->assertIsBool( $declaration['requires_deadline'], "{$type}: requires_deadline not declared" );
			$this->assertIsBool( $declaration['requires_link'], "{$type}: requires_link not declared" );
			$this->assertNotSame( '', trim( (string) $declaration['phase'] ), "{$type}: no owning phase" );
		}
	}

	public function test_every_type_names_a_declared_entity(): void {
		$entities = array(
			ANPA_Socios_Email_Template_Actions::ENTITY_ENROLLMENT,
			ANPA_Socios_Email_Template_Actions::ENTITY_MEMBER,
			ANPA_Socios_Email_Template_Actions::ENTITY_WITHDRAWAL,
			ANPA_Socios_Email_Template_Actions::ENTITY_COMPANY,
		);

		foreach ( ANPA_Socios_Email_Template_Actions::supported_types() as $type ) {
			$this->assertContains(
				ANPA_Socios_Email_Template_Actions::declaration( $type )['entity'],
				$entities,
				"{$type}: undeclared entity"
			);
		}
	}

	public function test_no_type_accepts_free_prose(): void {
		// The whole reason the catalogue exists: a reminder whose body is emitter-supplied prose is
		// a reminder nobody can review, translate or keep consistent.
		$this->assertFalse( ANPA_Socios_Email_Template_Actions::supports( 'Confirma a praza antes do venres, por favor' ) );

		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'never be free text' );
		ANPA_Socios_Email_Template_Actions::description( 'Confirma a praza antes do venres, por favor' );
	}

	public function test_the_declaration_cannot_be_mutated_through_its_accessor(): void {
		$declaration                = ANPA_Socios_Email_Template_Actions::declaration( 'confirmar_praza' );
		$declaration['consequence'] = 'texto inventado';

		$this->assertNotSame(
			'texto inventado',
			ANPA_Socios_Email_Template_Actions::consequence( 'confirmar_praza' )
		);
	}

	// ── Requirements are enforced, not advisory ──────────────────────────

	public function test_a_type_that_requires_a_deadline_does_not_render_without_one(): void {
		foreach ( ANPA_Socios_Email_Template_Actions::supported_types() as $type ) {
			if ( ! ANPA_Socios_Email_Template_Actions::requires_deadline( $type ) ) {
				continue;
			}

			try {
				ANPA_Socios_Email_Template_Context::pending_action( $type, '', 'https://example.org/c/' );
				$this->fail( "{$type} requires a deadline and rendered without one" );
			} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
				$this->assertStringContainsString( 'data_limite', $e->getMessage() );
			}
		}
	}

	public function test_a_type_that_requires_a_link_does_not_render_without_one(): void {
		foreach ( ANPA_Socios_Email_Template_Actions::supported_types() as $type ) {
			if ( ! ANPA_Socios_Email_Template_Actions::requires_link( $type ) ) {
				continue;
			}

			$deadline = ANPA_Socios_Email_Template_Actions::requires_deadline( $type ) ? '2026-10-20' : '';

			try {
				ANPA_Socios_Email_Template_Context::pending_action( $type, $deadline, '' );
				$this->fail( "{$type} requires a link and rendered without one" );
			} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
				$this->assertStringContainsString( 'nowhere to do it', $e->getMessage() );
			}
		}
	}

	public function test_a_type_that_requires_a_link_refuses_an_http_link_in_production(): void {
		$this->expectException( ANPA_Socios_Email_Template_Registry_Error::class );
		$this->expectExceptionMessage( 'must use https' );

		ANPA_Socios_Email_Template_Context::pending_action( 'confirmar_praza', '2026-10-20', 'http://example.org/c/' );
	}

	// ── Negative controls on the digest: what MUST move it ───────────────

	public function test_a_reworded_visible_label_moves_the_digest(): void {
		$mutated                            = $this->catalogue();
		$mutated['confirmar_praza']['label'] = 'Confirmar a túa praza';

		$this->assertNotSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_flipping_requires_deadline_moves_the_digest(): void {
		// This one changes what the renderer REFUSES, which is contract even though no character of
		// the email changes.
		$mutated = $this->catalogue();
		$mutated['confirmar_praza']['requires_deadline'] = false;

		$this->assertNotSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_a_reworded_consequence_moves_the_digest(): void {
		$mutated = $this->catalogue();
		$mutated['responder_oferta']['consequence'] = 'A praza pérdese.';

		$this->assertNotSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_a_new_action_type_moves_the_digest(): void {
		$mutated                     = $this->catalogue();
		$mutated['renovar_acceso_empresa'] = array(
			'label'             => 'Renovar o acceso da empresa',
			'description'       => 'Renovar o acceso da empresa á área de xestión.',
			'entity'            => ANPA_Socios_Email_Template_Actions::ENTITY_COMPANY,
			'requires_deadline' => true,
			'requires_link'     => true,
			'consequence'       => 'Sen renovación, o acceso queda suspendido.',
			'phase'             => 'fase40',
		);

		$this->assertNotSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_removing_an_action_type_moves_the_digest(): void {
		$mutated = $this->catalogue();
		unset( $mutated['revisar_baixa'] );

		$this->assertNotSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_changing_the_named_entity_moves_the_digest(): void {
		// The entity decides which entity the reminder names, and therefore the wording.
		$mutated                              = $this->catalogue();
		$mutated['revisar_baixa']['entity'] = ANPA_Socios_Email_Template_Actions::ENTITY_MEMBER;

		$this->assertNotSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	// ── Negative controls on the digest: what must NOT move it ───────────

	public function test_an_internal_note_does_not_move_the_digest(): void {
		// A roadmap annotation is not a change to the email. If this moved the digest, every
		// authorisation would be reviewing noise, and a digest nobody reads is worse than none.
		$mutated = $this->catalogue();

		foreach ( array_keys( $mutated ) as $type ) {
			$mutated[ $type ]['note'] = 'nota reescrita durante a revisión';
		}

		$this->assertSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_a_change_of_owning_phase_does_not_move_the_digest(): void {
		$mutated                            = $this->catalogue();
		$mutated['revisar_baixa']['phase'] = 'fase41';

		$this->assertSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_reordering_the_catalogue_does_not_move_the_digest(): void {
		// The declaration order is displayed nowhere, so it is not contract. Keys are sorted.
		$mutated = array_reverse( $this->catalogue(), true );

		$this->assertSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_reordering_the_fields_inside_a_declaration_does_not_move_the_digest(): void {
		$mutated = $this->catalogue();

		foreach ( array_keys( $mutated ) as $type ) {
			$mutated[ $type ] = array_reverse( $mutated[ $type ], true );
		}

		$this->assertSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	public function test_an_unobservable_field_added_by_a_future_phase_does_not_move_the_digest(): void {
		$mutated = $this->catalogue();

		foreach ( array_keys( $mutated ) as $type ) {
			$mutated[ $type ]['owner_team'] = 'xunta';
		}

		$this->assertSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint_of( $mutated )
		);
	}

	// ── Scheme and shape ────────────────────────────────────────────────

	public function test_the_digest_declares_its_scheme(): void {
		$this->assertSame( 'actions-v1', ANPA_Socios_Email_Template_Actions::FINGERPRINT_SCHEME );
		$this->assertMatchesRegularExpression(
			'/^actions-v1:[0-9a-f]{64}$/',
			ANPA_Socios_Email_Template_Actions::fingerprint()
		);
	}

	public function test_the_digest_is_stable_across_calls(): void {
		$this->assertSame(
			ANPA_Socios_Email_Template_Actions::fingerprint(),
			ANPA_Socios_Email_Template_Actions::fingerprint()
		);
	}

	public function test_the_observable_view_excludes_operational_metadata(): void {
		$observable = ANPA_Socios_Email_Template_Actions::observable( 'confirmar_praza' );

		$this->assertArrayNotHasKey( 'phase', $observable );
		$this->assertArrayNotHasKey( 'note', $observable );
		$this->assertSame( ANPA_Socios_Email_Template_Actions::observable_fields(), array_keys( $observable ) );
	}

	public function test_every_type_carries_an_internal_note_so_the_exclusion_is_meaningful(): void {
		// If only one type had a note, "notes do not move the digest" would be a claim about one
		// entry rather than about the field.
		foreach ( ANPA_Socios_Email_Template_Actions::supported_types() as $type ) {
			$declaration = ANPA_Socios_Email_Template_Actions::declaration( $type );

			$this->assertArrayHasKey( 'note', $declaration, "{$type}: no internal note" );
			$this->assertNotSame( '', trim( (string) $declaration['note'] ), "{$type}: empty internal note" );
		}
	}

	public function test_the_catalogue_and_the_context_helper_agree_on_the_type_list(): void {
		$this->assertSame(
			ANPA_Socios_Email_Template_Actions::supported_types(),
			ANPA_Socios_Email_Template_Context::pending_action_types()
		);
	}

	public function test_no_visible_text_leaks_an_internal_identifier(): void {
		foreach ( ANPA_Socios_Email_Template_Actions::supported_types() as $type ) {
			$declaration = ANPA_Socios_Email_Template_Actions::declaration( $type );

			foreach ( array( 'label', 'description', 'consequence' ) as $field ) {
				$this->assertStringNotContainsString( '_', (string) $declaration[ $field ], "{$type}.{$field}" );
				$this->assertStringNotContainsString( 'fase', (string) $declaration[ $field ], "{$type}.{$field}" );
			}
		}
	}

	public function test_the_catalogue_does_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-actions.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}
}
