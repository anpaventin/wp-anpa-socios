<?php
/**
 * The emitter inventory, made executable (fase36, PR-36s3).
 *
 * Three counts were being used interchangeably — nine methods, ten live events, twelve goldens — and
 * an emitter migration cannot start on an ambiguous count. The failure mode is silent: an emitter
 * nobody listed is an email that stops being sent, and nothing fails until a family does not receive
 * it.
 *
 * So the reconciliation lives here rather than in prose: the counts are DERIVED from the code and
 * the mapping between them is asserted in both directions. `enviar_codigo` sending two events is the
 * whole of the 9/10 difference, and it is asserted as such instead of explained in a comment.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_Email_Emitter_Inventory extends TestCase {

	private string $email_src;

	protected function setUp(): void {
		$this->email_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-email.php' );
	}

	/** @return array<string,string> Live event key => declared legacy emitter. */
	private function live_events(): array {
		$live = array();

		foreach ( ANPA_Socios_Email_Template_Events::set()->all() as $key => $definition ) {
			if ( ANPA_Socios_Email_Template_Phase::LIVE !== $definition->phase()->id() ) {
				continue;
			}

			$live[ (string) $key ] = $definition->legacy_emitter();
		}

		return $live;
	}

	/** @return string[] Declared enviar_* methods, in declaration order. */
	private function emitter_methods(): array {
		preg_match_all( '/function\s+(enviar_[a-z0-9_]+)\s*\(/i', $this->email_src, $matches );

		return array_values( array_unique( $matches[1] ) );
	}

	// ── The three counts, derived ────────────────────────────────────────

	public function test_all_public_emitters_delegate_to_send_templated(): void {
		// After fase36 migration, every public enviar_* delegates to send_templated, which is
		// the single point where wp_mail() is called. One call site, nine emitters.
		// Count actual calls, not references in comments/docblocks.
		$code_only = preg_replace( '/\/\*\*.*?\*\//s', '', $this->email_src ); // Remove docblocks.
		$code_only = preg_replace( '/\/\/[^\n]*/m', '', (string) $code_only );  // Remove line comments.

		$this->assertSame(
			1,
			(int) substr_count( (string) $code_only, 'wp_mail(' ),
			'wp_mail() must appear exactly once in executable code (inside send_templated)'
		);
		$this->assertSame(
			count( $this->emitter_methods() ),
			substr_count( $this->email_src, 'self::send_templated(' ),
			'every enviar_* method must call send_templated exactly once'
		);
	}

	public function test_every_live_event_names_an_emitter(): void {
		foreach ( $this->live_events() as $key => $emitter ) {
			$this->assertNotSame( '', $emitter, "{$key} is live but names no legacy emitter" );
		}
	}

	public function test_there_are_more_live_events_than_methods_and_the_difference_is_the_code_email(): void {
		// THE RECONCILIATION. `enviar_codigo` branches on its $context parameter and produces a
		// different subject and body for each branch: two events, one method. If that ever stops
		// being true — because somebody split the method, or added a second dual-purpose one — this
		// fails instead of the count quietly drifting.
		$live    = $this->live_events();
		$methods = $this->emitter_methods();

		$this->assertCount( count( $methods ) + 1, $live, 'the events/methods difference is no longer exactly one' );

		$this->assertArrayHasKey( 'auth_access_code', $live );
		$this->assertArrayHasKey( 'auth_access_code_signup', $live );
		$this->assertContains( 'enviar_codigo', $methods );

		// The two code events declare DIFFERENT emitter names even though one method serves both:
		// the declaration names the logical emitter, which is what the golden stems are keyed on.
		$this->assertNotSame( $live['auth_access_code'], $live['auth_access_code_signup'] );

		// And the method really does branch on its context argument.
		$this->assertMatchesRegularExpression(
			'/function\s+enviar_codigo\(\s*string \$email, string \$codigo, string \$context/',
			$this->email_src
		);
		$this->assertStringContainsString( "'verificacion' === \$context", $this->email_src );
	}

	public function test_every_golden_stem_belongs_to_a_live_event(): void {
		$live = $this->live_events();

		foreach ( ANPA_Socios_Golden_Manifest::events() as $event ) {
			$this->assertArrayHasKey( $event, $live, "the oracle pins '{$event}', which is not a live event" );
		}
	}

	public function test_every_live_event_is_pinned_by_at_least_one_golden(): void {
		// The other direction: a live emitter with no captured output has nothing proving that
		// migrating it changed nothing.
		$pinned = ANPA_Socios_Golden_Manifest::events();

		foreach ( array_keys( $this->live_events() ) as $key ) {
			$this->assertContains( $key, $pinned, "{$key} is live but the oracle pins no output for it" );
		}
	}

	public function test_the_two_branching_events_are_pinned_twice_and_the_rest_once(): void {
		// A variant is a context branch of one emitter, not a separate emitter. Stated as a property:
		// exactly the events whose templates declare the exclusive members'-area pair have more than
		// one golden.
		$set = ANPA_Socios_Email_Template_Events::set();

		foreach ( ANPA_Socios_Golden_Manifest::events() as $event ) {
			$branching = isset(
				$set->get( $event )->variables()[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ]
			);
			$variants  = ANPA_Socios_Golden_Manifest::variants( $event );

			if ( $branching ) {
				$this->assertGreaterThan( 1, count( $variants ), "{$event} branches but is pinned once" );
				continue;
			}

			$this->assertCount( 1, $variants, "{$event} does not branch but is pinned more than once" );
		}
	}

	public function test_every_live_default_has_a_consumer(): void {
		// No live default may exist without an emitter that will use it. The inventory's central
		// claim: zero exclusions.
		foreach ( array_keys( $this->live_events() ) as $key ) {
			$this->assertTrue(
				ANPA_Socios_Email_Template_Defaults::exists( $key ),
				"{$key} is live but ships no default"
			);
		}
	}

	// ── Scope boundaries 36s3 must respect ──────────────────────────────

	public function test_no_transactional_emitter_uses_the_queue(): void {
		// Which is why the queue's event_type width cannot be triggered by 36s3 (see the capacity
		// test below), and why 36s3 changes no scheduling behaviour.
		foreach ( array( 'Email_Queue', 'Email_Queue_Repo', 'enqueue_recipient', 'create_campaign' ) as $queue ) {
			$this->assertStringNotContainsString( $queue, $this->email_src, "a transactional emitter reaches the queue via {$queue}" );
		}
	}

	public function test_the_transactional_emitters_send_html_only(): void {
		// Production sends HTML, with the content type forced for the duration of each call. Shipping
		// a .text file does not authorise changing the historical MIME type, and 36s3 does not.
		// After migration, send_templated uses rendered['body_html'] and ignores body_text for MIME.
		$this->assertStringContainsString( "add_filter( 'wp_mail_content_type'", $this->email_src );
		$this->assertStringNotContainsString( 'multipart/alternative', $this->email_src );
		// wp_mail receives body_html, not body_text: the plain-text channel is loaded but not sent.
		$this->assertStringContainsString( "rendered['body_html']", $this->email_src );
		$this->assertStringNotContainsString( "rendered['body_text']", $this->email_src );
	}

	public function test_the_queue_column_still_fits_every_enqueueable_event_key(): void {
		// I15, resolved by migrate_to_1_41_0 which widens the queue column to varchar(64).
		// This test now verifies that the migration target width fits EVERY registered key,
		// not just the enqueueable ones.
		$db      = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-db.php' );
		$start   = strpos( $db, 'private static function migrate_to_1_41_0' );
		$this->assertIsInt( $start, 'migrate_to_1_41_0 must exist (I15 resolution)' );
		$body    = substr( $db, (int) $start, 2000 );

		// The migration targets varchar(64).
		preg_match( "/MODIFY COLUMN event_type varchar\((\d+)\)/", $body, $width );
		$this->assertNotEmpty( $width, 'migrate_to_1_41_0 must widen event_type' );
		$target_width = (int) $width[1];

		$longest = 0;
		$worst   = '';
		foreach ( ANPA_Socios_Email_Template_Events::set()->keys() as $key ) {
			if ( strlen( $key ) > $longest ) {
				$longest = strlen( $key );
				$worst   = $key;
			}
		}

		$this->assertGreaterThanOrEqual(
			$longest,
			$target_width,
			"'{$worst}' ({$longest} chars) does not fit the widened queue event_type column ({$target_width})"
		);

		// The non-enqueue guard (is_non_enqueueable_event) compares the full string argument BEFORE
		// any DB write, so it does not depend on a truncated column value. Confirm it is still a
		// pre-write check by inspecting create_campaign source order.
		$queue_repo = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-anpa-socios-email-queue-repo.php' );
		$guard_pos  = strpos( $queue_repo, 'is_non_enqueueable_event' );
		$insert_pos = strpos( $queue_repo, '$wpdb->insert', (int) $guard_pos );
		$this->assertIsInt( $guard_pos );
		$this->assertIsInt( $insert_pos );
		$this->assertLessThan( $insert_pos, $guard_pos, 'the non-enqueue guard must fire BEFORE the insert' );
	}
}
