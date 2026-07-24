<?php
/**
 * Pure recipient normalization + deduplication for the email queue (fase35).
 *
 * Turns a raw recipient list into a deduplicated list of valid recipients plus
 * a list of skipped ones (invalid or duplicate). Deduplication is by NORMALIZED
 * email (lowercase+trim), so a parent listed as both principal and secondary
 * with the same account is sent to only once.
 *
 * WordPress-independent; the glue layer may additionally gate with is_email().
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Recipients {

	/**
	 * Normalizes an email address (trim + lowercase). Does not validate.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Minimal email validity check (single @, non-empty local and domain with a
	 * dot). Conservative; the glue layer can additionally gate with is_email().
	 *
	 * @param string $email Normalized or raw email.
	 * @return bool
	 */
	public static function valid( string $email ): bool {
		$email = self::normalize( $email );
		if ( '' === $email || strlen( $email ) > 190 ) {
			return false;
		}
		return 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email );
	}

	/**
	 * Deduplicates and validates a raw recipient list.
	 *
	 * Each input entry is an array with at least 'email'; optional keys
	 * 'type' (principal|secondary|company|other), 'entity_type', 'entity_id'.
	 * The first occurrence of a normalized email wins.
	 *
	 * @param array<int,array<string,mixed>> $raw Raw recipients.
	 * @return array{valid:array<int,array<string,mixed>>,skipped:array<int,array<string,string>>}
	 *   valid: deduplicated valid recipients with a normalized 'email';
	 *   skipped: skipped entries with 'email' + 'reason' (invalid|duplicate).
	 */
	public static function prepare( array $raw ): array {
		$valid   = array();
		$skipped = array();
		$seen    = array();

		foreach ( $raw as $entry ) {
			$email_raw = is_array( $entry ) ? (string) ( $entry['email'] ?? '' ) : '';
			$email     = self::normalize( $email_raw );

			if ( ! self::valid( $email ) ) {
				$skipped[] = array( 'email' => $email_raw, 'reason' => 'invalid' );
				continue;
			}
			if ( isset( $seen[ $email ] ) ) {
				$skipped[] = array( 'email' => $email, 'reason' => 'duplicate' );
				continue;
			}
			$seen[ $email ] = true;

			$valid[] = array(
				'email'       => $email,
				'type'        => isset( $entry['type'] ) ? (string) $entry['type'] : 'other',
				'entity_type' => isset( $entry['entity_type'] ) ? (string) $entry['entity_type'] : 'general',
				'entity_id'   => isset( $entry['entity_id'] ) ? (int) $entry['entity_id'] : 0,
			);
		}

		return array( 'valid' => $valid, 'skipped' => $skipped );
	}
}
