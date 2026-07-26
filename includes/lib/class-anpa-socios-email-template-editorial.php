<?php
/**
 * Editorial state of the shipped default wording, PER EVENT AND PER CHANNEL (fase36, PR-36s1c).
 *
 * DELIBERATELY SEPARATE from the frozen registry. Editorial state is metadata about a text a
 * human has or has not read; it is not part of the render contract. Putting it in `Definition`
 * would place a reviewer's opinion inside the structure the renderer, the queue and three later
 * phases depend on, and would move the registry fingerprint every time somebody approved a
 * paragraph.
 *
 * **Three channels, three statuses.** A single aggregate status would hide that one of the three
 * parts is still pending — and that is exactly the case here: the ten live emails have a subject
 * and an HTML body that are byte-identical to production, but **no plain-text body exists in
 * production at all**, so every `.text` file is new writing that nothing can validate
 * automatically.
 *
 * Boundaries, decided explicitly:
 *
 *   - **Not in the renderer.** Rendering never consults editorial state.
 *   - **Not in the rendered content.** No reader ever sees it.
 *   - **Not in the registry fingerprint.** Approving wording is not a contract change.
 *   - **In its own editorial fingerprint**, pinned by its own golden file.
 *   - **Blocks automatic activation** of a future emitter while ANY channel is pending.
 *   - **Does not block** installation, seeding or preview: the board has to read a draft in
 *     order to approve it.
 *   - Leaves `pending_manual_review` only by a deliberate, audited edit of this file.
 *
 * Who approved and when are **audit data, not digest data**: they are excluded from the
 * fingerprint so it stays reproducible from the repository alone.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Editorial {

	/** Nobody has read this text yet. */
	const STATUS_PENDING_REVIEW = 'pending_manual_review';

	/** A human read it and accepted it. */
	const STATUS_APPROVED = 'approved';

	/**
	 * Byte-identical to what production already sends, proven by the golden oracle.
	 *
	 * A distinct status on purpose: it is a *stronger and narrower* claim than `approved`.
	 * Anything merely derived from an approved source, however faithfully, is NOT this.
	 */
	const STATUS_APPROVED_BY_PARITY = 'approved_by_parity';

	/** A human read it and asked for changes. */
	const STATUS_CHANGES_REQUESTED = 'changes_requested';

	/** The three reviewable channels of one template. */
	const CHANNEL_SUBJECT    = 'subject';
	const CHANNEL_HTML       = 'html';
	const CHANNEL_PLAIN_TEXT = 'plain_text';

	/** Identifier of the editorial hashing scheme. */
	const FINGERPRINT_SCHEME = 'editorial-v1';

	/**
	 * Event key => channel => status. Anything absent is pending.
	 *
	 * The ten entries below are the live emails. Their subject and HTML are
	 * `approved_by_parity` because a test proves them byte-identical to production; nobody
	 * approved them in a meeting. Their plain text is pending, like every other plain text in
	 * this plugin, because there is nothing to compare it against.
	 *
	 * @var array<string,array<string,string>>
	 */
	const STATUS = array(
		'auth_access_code'                    => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'auth_access_code_signup'             => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'member_application_admin_pending'    => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'member_application_approved'         => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'member_application_completed'        => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'member_application_changes_required' => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'member_cancellation_admin_notice'    => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'member_reactivation_admin_notice'    => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'activity_cancellation_admin_notice'  => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
		'waitlist_place_offer'                => array(
			self::CHANNEL_SUBJECT    => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_HTML       => self::STATUS_APPROVED_BY_PARITY,
			self::CHANNEL_PLAIN_TEXT => self::STATUS_PENDING_REVIEW,
		),
	);

	/** @since 1.40.0 @return string[] The three channels. */
	public static function channels(): array {
		return array( self::CHANNEL_SUBJECT, self::CHANNEL_HTML, self::CHANNEL_PLAIN_TEXT );
	}

	/** @since 1.40.0 @return string[] Every valid status. */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING_REVIEW,
			self::STATUS_APPROVED,
			self::STATUS_APPROVED_BY_PARITY,
			self::STATUS_CHANGES_REQUESTED,
		);
	}

	/**
	 * Editorial transitions that make sense.
	 *
	 * Data for now — no code enforces them yet — but declared so the admin screen inherits a
	 * decided model instead of inventing one. Note what is absent: nothing transitions INTO
	 * `approved_by_parity`. That status is earned by a passing parity test, never granted by a
	 * reviewer.
	 *
	 * @since  1.40.0
	 * @return array<string,string[]> From => allowed destinations.
	 */
	public static function transitions(): array {
		return array(
			self::STATUS_PENDING_REVIEW     => array( self::STATUS_APPROVED, self::STATUS_CHANGES_REQUESTED ),
			self::STATUS_CHANGES_REQUESTED  => array( self::STATUS_PENDING_REVIEW, self::STATUS_APPROVED ),
			self::STATUS_APPROVED           => array( self::STATUS_CHANGES_REQUESTED ),
			self::STATUS_APPROVED_BY_PARITY => array( self::STATUS_CHANGES_REQUESTED ),
		);
	}

	/**
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @param  string $channel   One of the CHANNEL_* constants.
	 * @return string One of the STATUS_* constants; pending when unknown.
	 */
	public static function status( string $event_key, string $channel ): string {
		$status = self::STATUS;

		return $status[ $event_key ][ $channel ] ?? self::STATUS_PENDING_REVIEW;
	}

	/**
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @param  string $channel   Channel.
	 * @return bool Whether that channel is settled.
	 */
	public static function is_approved( string $event_key, string $channel ): bool {
		return in_array(
			self::status( $event_key, $channel ),
			array( self::STATUS_APPROVED, self::STATUS_APPROVED_BY_PARITY ),
			true
		);
	}

	/**
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return bool Whether all three channels are settled.
	 */
	public static function is_fully_approved( string $event_key ): bool {
		foreach ( self::channels() as $channel ) {
			if ( ! self::is_approved( $event_key, $channel ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a future emitter may be activated for this event.
	 *
	 * The one thing editorial state gates, and it needs ALL channels: a family reading the
	 * plain-text alternative is still a family reading unreviewed wording.
	 *
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return bool
	 */
	public static function may_activate_emitter( string $event_key ): bool {
		return self::is_fully_approved( $event_key );
	}

	/**
	 * Channels of one event still waiting for a human.
	 *
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return string[]
	 */
	public static function pending_channels( string $event_key ): array {
		$pending = array();
		foreach ( self::channels() as $channel ) {
			if ( ! self::is_approved( $event_key, $channel ) ) {
				$pending[] = $channel;
			}
		}

		return $pending;
	}

	/**
	 * Fingerprint of the editorial state.
	 *
	 * Covers, per event: the key, the three channel statuses, the shipped default version and
	 * the three content hashes. Excludes timestamps, the reviewing user, review comments,
	 * environment data and absolute paths, so the digest is reproducible from the repository
	 * alone. Keys are sorted: editorial order is displayed nowhere.
	 *
	 * @since  1.40.0
	 * @param  string[] $event_keys Registered event keys.
	 * @return string `<scheme>:<sha256>`.
	 */
	public static function fingerprint( array $event_keys ): string {
		$keys = array_map( 'strval', $event_keys );
		sort( $keys, SORT_STRING );

		$rows = array();
		foreach ( $keys as $key ) {
			$hashes = ANPA_Socios_Email_Template_Defaults::part_hashes( $key );

			$rows[] = array(
				$key,
				self::status( $key, self::CHANNEL_SUBJECT ),
				self::status( $key, self::CHANNEL_HTML ),
				self::status( $key, self::CHANNEL_PLAIN_TEXT ),
				ANPA_Socios_Email_Template_Defaults::version( $key ),
				$hashes[ self::CHANNEL_SUBJECT ],
				$hashes[ self::CHANNEL_HTML ],
				$hashes[ self::CHANNEL_PLAIN_TEXT ],
			);
		}

		$canonical = (string) json_encode(
			array( self::FINGERPRINT_SCHEME, $rows ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return self::FINGERPRINT_SCHEME . ':' . hash( 'sha256', $canonical );
	}
}
