<?php
/**
 * Email campaign state value object + explicit state machine (fase35).
 *
 * A campaign groups one send to many recipients. Its state is SEPARATE from the
 * per-recipient state (ANPA_Socios_Email_Recipient_State).
 *
 * Allowed transitions:
 *   pending → running | cancelled
 *   running → paused | finished | cancelled
 *   paused  → running | cancelled
 * Terminal: finished, cancelled.
 *
 * All identifiers AND stored values are English and unambiguous; the Galician
 * labels ("Campañas"/"Comunicacións") live only in the UI as translatable
 * strings. No WordPress dependency.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Campaign_State {

	const PENDING   = 'pending';
	const RUNNING   = 'running';
	const PAUSED    = 'paused';
	const FINISHED  = 'finished';
	const CANCELLED = 'cancelled';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING, self::RUNNING, self::PAUSED, self::FINISHED, self::CANCELLED );
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
		return in_array( $state, array( self::FINISHED, self::CANCELLED ), true );
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
			self::PENDING   => array( self::RUNNING, self::CANCELLED ),
			self::RUNNING   => array( self::PAUSED, self::FINISHED, self::CANCELLED ),
			self::PAUSED    => array( self::RUNNING, self::CANCELLED ),
			self::FINISHED  => array(),
			self::CANCELLED => array(),
		);
		return in_array( $to, $allowed[ $from ] ?? array(), true );
	}
}
