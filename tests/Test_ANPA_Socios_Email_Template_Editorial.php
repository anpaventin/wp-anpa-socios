<?php
/**
 * Tests for the editorial state of the shipped wording (fase36, PR-36s1c).
 *
 * The point of these tests is the boundary, not the values: editorial state must not leak into
 * the render contract, and it must gate exactly one thing — activating an emitter for wording
 * nobody has read.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Template_Editorial extends TestCase {

	/** @return ANPA_Socios_Email_Template_Set */
	private function set(): ANPA_Socios_Email_Template_Set {
		return ANPA_Socios_Email_Template_Events::set();
	}

	public function test_an_unknown_event_is_pending_not_approved(): void {
		// The safe default: a new template must be pending until somebody says otherwise.
		// Defaulting to approved would let a draft ship the day it is written.
		$this->assertSame(
			ANPA_Socios_Email_Template_Editorial::STATUS_PENDING_REVIEW,
			ANPA_Socios_Email_Template_Editorial::status( 'evento_que_non_existe' )
		);
		$this->assertFalse( ANPA_Socios_Email_Template_Editorial::is_approved( 'evento_que_non_existe' ) );
	}

	public function test_exactly_the_live_events_are_approved(): void {
		// "Approved" here means one thing only: the wording is what families already receive.
		// Nothing else may claim it.
		$approved = array();
		foreach ( array_keys( ANPA_Socios_Email_Template_Editorial::STATUS ) as $key ) {
			if ( ANPA_Socios_Email_Template_Editorial::is_approved( (string) $key ) ) {
				$approved[] = (string) $key;
			}
		}
		sort( $approved, SORT_STRING );

		$live = $this->set()->live_keys();
		sort( $live, SORT_STRING );

		$this->assertSame( $live, $approved );
	}

	public function test_every_declared_status_belongs_to_a_registered_event(): void {
		foreach ( array_keys( ANPA_Socios_Email_Template_Editorial::STATUS ) as $key ) {
			$this->assertTrue( $this->set()->has( (string) $key ), "editorial status for unknown event '{$key}'" );
		}
	}

	public function test_every_status_value_is_one_of_the_two_constants(): void {
		$valid = array(
			ANPA_Socios_Email_Template_Editorial::STATUS_APPROVED,
			ANPA_Socios_Email_Template_Editorial::STATUS_PENDING_REVIEW,
		);

		foreach ( ANPA_Socios_Email_Template_Editorial::STATUS as $key => $status ) {
			$this->assertContains( $status, $valid, "{$key} has an unknown editorial status" );
		}
	}

	public function test_planned_events_are_pending_review(): void {
		$planned = array();
		foreach ( $this->set()->all() as $key => $definition ) {
			if ( ! $definition->is_live() ) {
				$planned[] = (string) $key;
			}
		}

		$this->assertSame(
			ANPA_Socios_Email_Template_Editorial::pending( $planned ),
			$this->sorted( $planned ),
			'no planned event may be approved before a human reads its wording'
		);
	}

	public function test_pending_review_blocks_activating_an_emitter_and_nothing_else(): void {
		// The single thing the editorial state gates. Installation, seeding and preview must
		// keep working, or the board could never read a draft in order to approve it.
		$this->assertFalse( ANPA_Socios_Email_Template_Editorial::may_activate_emitter( 'pending_action_reminder' ) );
		$this->assertTrue( ANPA_Socios_Email_Template_Editorial::may_activate_emitter( 'waitlist_place_offer' ) );
	}

	public function test_editorial_state_is_absent_from_the_render_contract(): void {
		// It must not have leaked into the frozen structures: a reviewer's opinion has no place
		// inside what the renderer and three later phases depend on.
		$definition = $this->set()->get( 'waitlist_place_offer' );

		$this->assertArrayNotHasKey( 'content_status', $definition->to_array() );
		$this->assertArrayNotHasKey( 'content_status', $definition->declared_tokens() );
		$this->assertFalse( method_exists( $definition, 'content_status' ) );
	}

	public function test_the_editorial_fingerprint_is_separate_from_the_registry_one(): void {
		// Approving a paragraph must not look like a contract change, and changing the contract
		// must not look like an approval.
		$editorial = ANPA_Socios_Email_Template_Editorial::fingerprint();

		$this->assertStringStartsWith( ANPA_Socios_Email_Template_Editorial::FINGERPRINT_SCHEME . ':', $editorial );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+:[0-9a-f]{64}$/', $editorial );
		$this->assertNotSame( $this->set()->fingerprint(), $editorial );
		$this->assertSame( $editorial, ANPA_Socios_Email_Template_Editorial::fingerprint(), 'must be stable' );
	}

	public function test_the_plain_text_channel_is_never_approved_by_parity(): void {
		// Production sends HTML only, so there is no plain-text output to compare against. Every
		// .text file is new writing, and the only thing that can approve it is somebody reading
		// it — including for the ten events whose HTML is approved because it is already live.
		foreach ( $this->set()->keys() as $key ) {
			$this->assertSame(
				ANPA_Socios_Email_Template_Editorial::STATUS_PENDING_REVIEW,
				ANPA_Socios_Email_Template_Editorial::plain_text_status( (string) $key ),
				"{$key}: the plain-text body cannot inherit the HTML approval"
			);
		}

		$this->assertTrue( ANPA_Socios_Email_Template_Editorial::is_approved( 'waitlist_place_offer' ) );
	}

	public function test_the_editorial_class_does_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-editorial.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}

	/**
	 * @param  string[] $values Values to sort.
	 * @return string[]
	 */
	private function sorted( array $values ): array {
		sort( $values, SORT_STRING );

		return $values;
	}
}
