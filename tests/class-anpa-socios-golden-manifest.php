<?php
/**
 * Declared golden variants per live event (fase36).
 *
 * The oracle used to be one file per legacy method, so the relationship between events and
 * captures was expressed only by filenames. That stops working the moment one event needs two
 * captures: `member_application_approved` renders differently depending on whether a
 * members'-area URL was supplied, and both paths must be pinned.
 *
 * So the relationship is declared, not inferred:
 *
 *   - the REGISTRY holds one definition per event;
 *   - this MANIFEST holds the context variants that must be captured for it;
 *   - a golden file is `<event_key>.<variant>.txt`.
 *
 * The historical bijection is unchanged and still between `legacy_emitter` and live events. What
 * the manifest adds is the second dimension: which contexts of a live event must exist in the
 * oracle. Without it, "10 events, 12 files" would look like a counting error instead of a
 * deliberate statement.
 *
 * Test support, deliberately not shipped logic: it describes the oracle, not the plugin.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Golden_Manifest {

	/** Variant name for an event whose output does not branch. */
	const VARIANT_DEFAULT = 'default';

	/** Variant names for the members'-area branch. */
	const VARIANT_WITH_URL    = 'with-url';
	const VARIANT_WITHOUT_URL = 'without-url';

	/**
	 * Event key => the context variants that must be captured.
	 *
	 * @var array<string,string[]>
	 */
	const VARIANTS = array(
		'auth_access_code'                   => array( self::VARIANT_DEFAULT ),
		'auth_access_code_signup'            => array( self::VARIANT_DEFAULT ),
		'member_application_admin_pending'   => array( self::VARIANT_DEFAULT ),
		// These two branch on the members'-area URL with different wording each way, so both
		// paths are pinned. Capturing only one would leave the other free to change silently.
		'member_application_approved'        => array( self::VARIANT_WITH_URL, self::VARIANT_WITHOUT_URL ),
		'member_application_completed'       => array( self::VARIANT_WITH_URL, self::VARIANT_WITHOUT_URL ),
		'member_application_changes_required' => array( self::VARIANT_DEFAULT ),
		'member_cancellation_admin_notice'   => array( self::VARIANT_DEFAULT ),
		'member_reactivation_admin_notice'   => array( self::VARIANT_DEFAULT ),
		'activity_cancellation_admin_notice' => array( self::VARIANT_DEFAULT ),
		'waitlist_place_offer'               => array( self::VARIANT_DEFAULT ),
	);

	/**
	 * @return string[] Event keys with declared captures.
	 */
	public static function events(): array {
		return array_keys( self::VARIANTS );
	}

	/**
	 * @param  string $event_key Event key.
	 * @return string[] Declared variants, empty when the event declares none.
	 */
	public static function variants( string $event_key ): array {
		$variants = self::VARIANTS;

		return $variants[ $event_key ] ?? array();
	}

	/**
	 * Every golden stem the oracle must contain, sorted so diffs stay readable.
	 *
	 * @return string[] `<event_key>.<variant>`.
	 */
	public static function stems(): array {
		$stems = array();
		foreach ( self::VARIANTS as $event_key => $variants ) {
			foreach ( $variants as $variant ) {
				$stems[] = $event_key . '.' . $variant;
			}
		}

		sort( $stems, SORT_STRING );

		return $stems;
	}

	/**
	 * @param  string $event_key Event key.
	 * @param  string $variant   Variant name.
	 * @return string Golden stem.
	 */
	public static function stem( string $event_key, string $variant ): string {
		return $event_key . '.' . $variant;
	}
}
