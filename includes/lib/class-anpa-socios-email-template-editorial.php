<?php
/**
 * Editorial state of the shipped default wording (fase36, PR-36s1c).
 *
 * DELIBERATELY SEPARATE from the frozen registry. `content_status` is editorial metadata about
 * a text a human has or has not read; it is not part of the render contract. Adding it to
 * `Definition` would put a reviewer's opinion inside the structure the renderer, the queue and
 * three later phases depend on, and would move the registry fingerprint every time somebody
 * approved a paragraph.
 *
 * The boundaries, decided explicitly:
 *
 *   - **Not in the renderer.** Rendering a template never consults its editorial state.
 *   - **Not in the rendered content.** No reader ever sees it.
 *   - **Not in the registry fingerprint.** Approving wording is not a contract change.
 *   - **In its own editorial fingerprint**, so "has the shipped wording been reviewed?" is one
 *     hash comparison.
 *   - **Blocks automatic activation** of a future emitter: a phase may not start sending an
 *     event whose wording nobody has approved.
 *   - **Does not block** installation, seeding or preview. The board has to be able to read a
 *     draft in order to approve it.
 *   - Leaves `pending_manual_review` only by a deliberate, audited edit of this file — never as
 *     a side effect of a migration or an update.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Editorial {

	/** Wording a human has read and approved. */
	const STATUS_APPROVED = 'approved';

	/** Wording drafted by the implementation and not yet reviewed. */
	const STATUS_PENDING_REVIEW = 'pending_manual_review';

	/** Identifier of the editorial hashing scheme, for the same reason the registry has one. */
	const FINGERPRINT_SCHEME = 'editorial-v1';

	/**
	 * Event key => editorial status.
	 *
	 * `STATUS_APPROVED` here means one specific thing: the wording is **byte-identical to what
	 * the plugin already sends**, transcribed from the golden oracle. Nobody approved it in a
	 * meeting; it is approved because families already receive it. Everything else is a draft.
	 *
	 * @var array<string,string>
	 */
	const STATUS = array(
		// The ten live emails: their wording is the wording in production today.
		'auth_access_code'                    => self::STATUS_APPROVED,
		'auth_access_code_signup'             => self::STATUS_APPROVED,
		'member_application_admin_pending'    => self::STATUS_APPROVED,
		'member_application_approved'         => self::STATUS_APPROVED,
		'member_application_completed'        => self::STATUS_APPROVED,
		'member_application_changes_required' => self::STATUS_APPROVED,
		'member_cancellation_admin_notice'    => self::STATUS_APPROVED,
		'member_reactivation_admin_notice'    => self::STATUS_APPROVED,
		'activity_cancellation_admin_notice'  => self::STATUS_APPROVED,
		'waitlist_place_offer'                => self::STATUS_APPROVED,
	);

	/**
	 * Editorial status of one event. Unknown means "never reviewed", which is the safe default:
	 * a new template must be pending until somebody says otherwise, not the reverse.
	 *
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return string One of the STATUS_* constants.
	 */
	public static function status( string $event_key ): string {
		$status = self::STATUS;

		return $status[ $event_key ] ?? self::STATUS_PENDING_REVIEW;
	}

	/**
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return bool Whether the wording has been reviewed.
	 */
	public static function is_approved( string $event_key ): bool {
		return self::STATUS_APPROVED === self::status( $event_key );
	}

	/**
	 * Whether a future emitter may be activated for this event.
	 *
	 * The one thing the editorial state actually gates. A phase must not start sending wording
	 * nobody has read, and "it was in the repository" is not review.
	 *
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return bool
	 */
	public static function may_activate_emitter( string $event_key ): bool {
		return self::is_approved( $event_key );
	}

	/**
	 * Event keys still waiting for a human to read them.
	 *
	 * @since  1.40.0
	 * @param  string[] $event_keys Keys to filter.
	 * @return string[] Sorted.
	 */
	public static function pending( array $event_keys ): array {
		$pending = array();
		foreach ( $event_keys as $key ) {
			$key = (string) $key;
			if ( ! self::is_approved( $key ) ) {
				$pending[] = $key;
			}
		}

		sort( $pending, SORT_STRING );

		return $pending;
	}

	/**
	 * Fingerprint of the editorial state alone.
	 *
	 * Separate from the registry fingerprint on purpose: approving a paragraph must not look
	 * like a contract change, and changing the contract must not look like an approval. Keys are
	 * sorted here — unlike the registry, editorial order is not displayed anywhere.
	 *
	 * @since  1.40.0
	 * @return string `<scheme>:<sha256>`.
	 */
	public static function fingerprint(): string {
		$status = self::STATUS;
		ksort( $status, SORT_STRING );

		$canonical = (string) json_encode(
			array( self::FINGERPRINT_SCHEME, $status ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return self::FINGERPRINT_SCHEME . ':' . hash( 'sha256', $canonical );
	}
}
