<?php
/**
 * Tests for the per-channel editorial state (fase36, PR-36s1c).
 *
 * The point is the boundary, not the values: editorial state must not leak into the render
 * contract, it must gate exactly one thing, and it must never let one channel's approval stand in
 * for another's.
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

	/** @return string */
	private function golden_path(): string {
		return __DIR__ . '/golden/template_editorial.sha256';
	}

	// ── Defaults and per-channel independence ───────────────────────────

	public function test_an_unknown_event_is_pending_on_every_channel(): void {
		// The safe default. Defaulting to approved would ship a draft the day it is written.
		foreach ( ANPA_Socios_Email_Template_Editorial::channels() as $channel ) {
			$this->assertSame(
				ANPA_Socios_Email_Template_Editorial::STATUS_PENDING_REVIEW,
				ANPA_Socios_Email_Template_Editorial::status( 'evento_que_non_existe', $channel )
			);
		}
	}

	public function test_the_live_events_are_parity_approved_on_subject_and_html_only(): void {
		foreach ( $this->set()->live_keys() as $key ) {
			$this->assertSame(
				ANPA_Socios_Email_Template_Editorial::STATUS_APPROVED_BY_PARITY,
				ANPA_Socios_Email_Template_Editorial::status( $key, ANPA_Socios_Email_Template_Editorial::CHANNEL_SUBJECT ),
				"{$key}: subject"
			);
			$this->assertSame(
				ANPA_Socios_Email_Template_Editorial::STATUS_APPROVED_BY_PARITY,
				ANPA_Socios_Email_Template_Editorial::status( $key, ANPA_Socios_Email_Template_Editorial::CHANNEL_HTML ),
				"{$key}: html"
			);
			$this->assertSame(
				ANPA_Socios_Email_Template_Editorial::STATUS_PENDING_REVIEW,
				ANPA_Socios_Email_Template_Editorial::status( $key, ANPA_Socios_Email_Template_Editorial::CHANNEL_PLAIN_TEXT ),
				"{$key}: plain text cannot inherit the HTML approval"
			);
		}
	}

	public function test_no_plain_text_anywhere_is_approved(): void {
		// Production sends HTML only, so no plain-text body has anything to be compared against.
		foreach ( $this->set()->keys() as $key ) {
			$this->assertFalse(
				ANPA_Socios_Email_Template_Editorial::is_approved(
					(string) $key,
					ANPA_Socios_Email_Template_Editorial::CHANNEL_PLAIN_TEXT
				),
				"{$key}: plain text must stay pending until somebody reads it"
			);
		}
	}

	public function test_planned_events_are_pending_on_every_channel(): void {
		foreach ( $this->set()->all() as $key => $definition ) {
			if ( $definition->is_live() ) {
				continue;
			}
			$this->assertSame(
				ANPA_Socios_Email_Template_Editorial::channels(),
				ANPA_Socios_Email_Template_Editorial::pending_channels( (string) $key ),
				"{$key}: nothing may be approved before a human reads it"
			);
		}
	}

	public function test_no_event_is_fully_approved_yet(): void {
		// True today because every plain text is pending. Stated as a test so the day it stops
		// being true, somebody has deliberately approved a plain-text body.
		foreach ( $this->set()->keys() as $key ) {
			$this->assertFalse( ANPA_Socios_Email_Template_Editorial::is_fully_approved( (string) $key ) );
		}
	}

	// ── The one thing it gates ──────────────────────────────────────────

	public function test_activation_requires_every_channel(): void {
		// A family reading the plain-text alternative is still a family reading unreviewed
		// wording, so a partially approved event may not be activated.
		$this->assertFalse( ANPA_Socios_Email_Template_Editorial::may_activate_emitter( 'waitlist_place_offer' ) );
		$this->assertFalse( ANPA_Socios_Email_Template_Editorial::may_activate_emitter( 'pending_action_reminder' ) );
	}

	public function test_installation_and_preview_are_not_gated(): void {
		// Nothing here blocks seeding or rendering: the board has to read a draft to approve it.
		$pending = 'pending_action_reminder';

		$this->assertTrue( $this->set()->has( $pending ) );
		$this->assertNotSame( array(), $this->set()->get( $pending )->sample_data() );
		$this->assertNotSame( array(), $this->set()->get( $pending )->declared_tokens() );
	}

	// ── Model integrity ────────────────────────────────────────────────

	public function test_every_declared_status_belongs_to_a_registered_event_and_channel(): void {
		$channels = ANPA_Socios_Email_Template_Editorial::channels();
		$valid    = ANPA_Socios_Email_Template_Editorial::statuses();

		foreach ( ANPA_Socios_Email_Template_Editorial::STATUS as $key => $per_channel ) {
			$this->assertTrue( $this->set()->has( (string) $key ), "editorial status for unknown event '{$key}'" );
			$this->assertSame( $channels, array_keys( $per_channel ), "{$key}: channels must be complete and in order" );

			foreach ( $per_channel as $channel => $status ) {
				$this->assertContains( $status, $valid, "{$key}.{$channel} has an unknown status" );
			}
		}
	}

	public function test_nothing_transitions_into_parity_approval(): void {
		// It is earned by a passing parity test, never granted by a reviewer.
		foreach ( ANPA_Socios_Email_Template_Editorial::transitions() as $from => $destinations ) {
			$this->assertNotContains(
				ANPA_Socios_Email_Template_Editorial::STATUS_APPROVED_BY_PARITY,
				$destinations,
				"'{$from}' must not be able to become parity-approved"
			);
		}
	}

	public function test_every_transition_target_is_a_valid_status(): void {
		$valid = ANPA_Socios_Email_Template_Editorial::statuses();

		foreach ( ANPA_Socios_Email_Template_Editorial::transitions() as $from => $destinations ) {
			$this->assertContains( $from, $valid );
			foreach ( $destinations as $to ) {
				$this->assertContains( $to, $valid );
				$this->assertNotSame( $from, $to, "'{$from}' must not transition to itself" );
			}
		}
	}

	public function test_editorial_state_is_absent_from_the_render_contract(): void {
		$definition = $this->set()->get( 'waitlist_place_offer' );

		$this->assertArrayNotHasKey( 'content_status', $definition->to_array() );
		$this->assertArrayNotHasKey( 'subject_status', $definition->to_array() );
		$this->assertArrayNotHasKey( 'content_status', $definition->declared_tokens() );
	}

	// ── The editorial fingerprint and its golden ────────────────────────

	public function test_the_editorial_fingerprint_is_separate_and_stable(): void {
		$keys      = $this->set()->keys();
		$editorial = ANPA_Socios_Email_Template_Editorial::fingerprint( $keys );

		$this->assertStringStartsWith( ANPA_Socios_Email_Template_Editorial::FINGERPRINT_SCHEME . ':', $editorial );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+:[0-9a-f]{64}$/', $editorial );
		$this->assertNotSame( $this->set()->fingerprint(), $editorial );
		$this->assertSame( $editorial, ANPA_Socios_Email_Template_Editorial::fingerprint( $keys ) );
	}

	public function test_the_editorial_fingerprint_ignores_the_order_it_is_asked_about(): void {
		// Editorial order is displayed nowhere, so it must not affect the digest — unlike the
		// registry, where order is what the board sees.
		$keys = $this->set()->keys();
		$flipped = array_reverse( $keys );

		$this->assertSame(
			ANPA_Socios_Email_Template_Editorial::fingerprint( $keys ),
			ANPA_Socios_Email_Template_Editorial::fingerprint( $flipped )
		);
	}

	public function test_the_editorial_fingerprint_covers_the_shipped_content(): void {
		// Not only the statuses: if a shipped body changed while its status stayed "approved",
		// a status-only digest would report no change at all.
		$hashes = ANPA_Socios_Email_Template_Defaults::part_hashes( 'waitlist_place_offer' );

		foreach ( ANPA_Socios_Email_Template_Editorial::channels() as $channel ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+:[0-9a-f]{64}$/', $hashes[ $channel ] );
		}

		// The complementary property: no files, no hash. It used to be demonstrated with a real
		// event that had no wording yet; every declared event now ships one, so the stand-in has to
		// be a stem that will never exist. Asserting it with a real key would have quietly become a
		// test of nothing the moment the catalogue was completed.
		$unshipped = ANPA_Socios_Email_Template_Defaults::part_hashes( 'evento_que_non_existe' );
		foreach ( ANPA_Socios_Email_Template_Editorial::channels() as $channel ) {
			$this->assertSame( '', $unshipped[ $channel ], 'an unshipped default has no content hash' );
		}
	}

	public function test_the_three_channel_hashes_are_independent(): void {
		$hashes = ANPA_Socios_Email_Template_Defaults::part_hashes( 'waitlist_place_offer' );

		$this->assertNotSame( $hashes['subject'], $hashes['html'] );
		$this->assertNotSame( $hashes['html'], $hashes['plain_text'] );
		$this->assertNotSame( $hashes['subject'], $hashes['plain_text'] );
	}

	/**
	 * The editorial contract is pinned in its own golden, generated by
	 * `ANPA_EDITORIAL_GOLDEN_CAPTURE=1` and never hand-edited.
	 */
	public function test_the_editorial_fingerprint_matches_its_golden(): void {
		$fingerprint = ANPA_Socios_Email_Template_Editorial::fingerprint( $this->set()->keys() );
		$path        = $this->golden_path();

		if ( '1' === (string) getenv( 'ANPA_EDITORIAL_GOLDEN_CAPTURE' ) ) {
			file_put_contents( $path, $fingerprint . "\n" );
			$this->assertFileExists( $path );
			return;
		}

		$this->assertFileExists( $path, 'capture it with ANPA_EDITORIAL_GOLDEN_CAPTURE=1' );
		$this->assertSame(
			trim( (string) file_get_contents( $path ) ),
			$fingerprint,
			'the editorial contract changed; if that was intended, recapture the golden and say what was reviewed'
		);
	}

	public function test_the_editorial_golden_records_its_scheme(): void {
		if ( ! is_readable( $this->golden_path() ) ) {
			$this->markTestSkipped( 'editorial golden not captured yet.' );
		}

		$this->assertStringStartsWith(
			ANPA_Socios_Email_Template_Editorial::FINGERPRINT_SCHEME . ':',
			trim( (string) file_get_contents( $this->golden_path() ) )
		);
	}

	public function test_the_editorial_class_does_not_touch_wordpress(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/lib/class-anpa-socios-email-template-editorial.php' );

		foreach ( array( 'esc_html', 'get_option', 'update_option', '$wpdb', 'apply_filters' ) as $wp ) {
			$this->assertStringNotContainsString( $wp, $src );
		}
	}
}
