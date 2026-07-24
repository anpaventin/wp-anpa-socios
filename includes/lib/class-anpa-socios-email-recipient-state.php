<?php
/**
 * Per-recipient email state value object + state machine (fase35).
 *
 * One record per recipient in a campaign. State is SEPARATE from the campaign
 * state. Reflects the transport lifecycle; "accepted" means wp_mail() returned
 * true (accepted by the local mail system), NOT delivered.
 *
 * Allowed transitions:
 *   pending    → processing | cancelled
 *   processing → accepted | failed | pending (orphan-lease recovery)
 *   failed     → pending (retry) | failed_permanent
 * Terminal: accepted, failed_permanent, cancelled.
 *
 * English identifiers and stored values. No WordPress dependency.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Recipient_State {

	const PENDING          = 'pending';
	const PROCESSING       = 'processing';
	const ACCEPTED         = 'accepted';
	const FAILED           = 'failed';
	const FAILED_PERMANENT = 'failed_permanent';
	const CANCELLED        = 'cancelled';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING, self::PROCESSING, self::ACCEPTED, self::FAILED, self::FAILED_PERMANENT, self::CANCELLED );
	}

	/**
	 * @param string $state Candidate.
	 * @return bool
	 */
	public static function valid( string $state ): bool {
		return in_array( $state, self::all(), true );
	}

	/**
	 * @param string $state Candidate.
	 * @return bool Whether the state is terminal.
	 */
	public static function terminal( string $state ): bool {
		return in_array( $state, array( self::ACCEPTED, self::FAILED_PERMANENT, self::CANCELLED ), true );
	}

	/**
	 * @param string $state Candidate.
	 * @return bool Whether this recipient may still be processed by a batch.
	 */
	public static function retryable( string $state ): bool {
		return in_array( $state, array( self::PENDING, self::FAILED ), true );
	}

	/**
	 * Whether a transition from → to is permitted.
	 *
	 * @param string $from Current state.
	 * @param string $to   Target state.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( ! self::valid( $from ) || ! self::valid( $to ) ) {
			return false;
		}
		$allowed = array(
			self::PENDING          => array( self::PROCESSING, self::CANCELLED ),
			self::PROCESSING       => array( self::ACCEPTED, self::FAILED, self::PENDING ),
			self::FAILED           => array( self::PENDING, self::FAILED_PERMANENT ),
			self::ACCEPTED         => array(),
			self::FAILED_PERMANENT => array(),
			self::CANCELLED        => array(),
		);
		return in_array( $to, $allowed[ $from ] ?? array(), true );
	}
}
