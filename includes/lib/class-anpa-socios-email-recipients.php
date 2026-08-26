<?php
/**
 * Pure recipient normalization, deduplication and idempotency-key derivation
 * for the email queue (fase35).
 *
 * ## Email normalization (single canonical function `normalize()`)
 * - Trims outer whitespace.
 * - Lowercases the WHOLE address. This is a deliberate decision for this domain:
 *   real mail providers treat the local part case-insensitively, and dedup
 *   correctness matters here. The negligible RFC-theoretical risk of merging two
 *   case-distinct local parts is accepted and documented.
 * - Does NOT remove dots. Does NOT strip "+tags". Applies NO provider-specific
 *   rules (no Gmail-style canonicalization). Never turns two distinct mailboxes
 *   into the same one beyond the case decision above.
 * The SAME function is used everywhere: computing recipients, deduplication,
 * idempotency-key derivation, record lookups and validation messages.
 *
 * ## Deduplication scope (logical message identity)
 * Two recipients are the SAME send only when they share the full logical message
 * identity, NOT merely the email. The identity is:
 *   (normalized_email, recipient_type, message_key)
 * `message_key` is a STABLE functional identifier of the concrete communication
 * (e.g. "enrolment:123", "trimester:T2:group:45", "company:7:activity:9"). This
 * lets the same address receive DIFFERENT messages for different entities/events
 * while collapsing an accidental repeat of the same operation, and collapsing a
 * principal+secondary parent of the SAME family/message into one send (the
 * caller passes the same recipient_type + message_key for both).
 *
 * ## Idempotency key
 * key = sha256( canonical_json([ VERSION, campaign_uuid, normalized_email,
 *                                recipient_type, message_key ]) )
 * Canonical JSON (ordered array, `json_encode`) gives an UNAMBIGUOUS,
 * length-prefixed-by-structure serialization — components are never blindly
 * concatenated. Null/missing values normalize to '' before hashing.
 *
 * No WordPress dependency.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Recipients {

	/** Idempotency algorithm version — bump if the composition changes. */
	const IDEMPOTENCY_VERSION = 1;

	/** RFC 5321 practical maximum for an email address. */
	const EMAIL_MAX = 254;

	/**
	 * The single canonical email normalization: trim + lowercase. No dot/label
	 * stripping, no provider-specific rules. See class docblock.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Minimal, conservative email validity check (single @, non-empty local and
	 * domain with a dot, within the RFC length). The glue layer may additionally
	 * gate with WordPress is_email().
	 *
	 * @param string $email Normalized or raw email.
	 * @return bool
	 */
	public static function valid( string $email ): bool {
		$email = self::normalize( $email );
		if ( '' === $email || strlen( $email ) > self::EMAIL_MAX ) {
			return false;
		}
		return 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email );
	}

	/**
	 * Canonical serialization of the idempotency components (ordered array →
	 * JSON). Never concatenates raw values. Nulls become ''.
	 *
	 * @param string $campaign_uuid Campaign UUID.
	 * @param string $email         Normalized email.
	 * @param string $recipient_type Audience type (member|company|other…).
	 * @param string $message_key   Stable functional message identifier.
	 * @return string Canonical JSON.
	 */
	public static function canonical_identity( string $campaign_uuid, string $email, string $recipient_type, string $message_key ): string {
		$parts = array(
			'v'  => self::IDEMPOTENCY_VERSION,
			'c'  => (string) $campaign_uuid,
			'e'  => self::normalize( $email ),
			'rt' => (string) $recipient_type,
			'mk' => (string) $message_key,
		);
		return self::canonical_json( $parts );
	}

	/**
	 * Canonical JSON of an ordered array. Uses wp_json_encode when WordPress is
	 * loaded, else json_encode with stable flags (the pure lib runs without WP in
	 * unit tests). Ordered keys make the serialization deterministic.
	 *
	 * @param array<string,mixed> $data Ordered data.
	 * @return string
	 */
	private static function canonical_json( array $data ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$out = wp_json_encode( $data );
			return is_string( $out ) ? $out : '';
		}
		$out = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $out ) ? $out : '';
	}

	/**
	 * Deterministic idempotency key (sha256 hex) for a recipient message.
	 *
	 * @param string $campaign_uuid  Campaign UUID.
	 * @param string $email          Email (will be normalized).
	 * @param string $recipient_type Audience type.
	 * @param string $message_key    Stable functional message identifier.
	 * @return string 64-char hex.
	 */
	public static function idempotency_key( string $campaign_uuid, string $email, string $recipient_type, string $message_key ): string {
		return hash( 'sha256', self::canonical_identity( $campaign_uuid, $email, $recipient_type, $message_key ) );
	}

	/**
	 * Logical dedup identity WITHIN a single campaign build (no campaign uuid
	 * needed): distinct messages to the same address are kept; exact repeats and
	 * principal/secondary of the same message collapse.
	 *
	 * @param array<string,mixed> $entry Recipient entry.
	 * @return string
	 */
	private static function local_identity( array $entry ): string {
		return self::normalize( (string) ( $entry['email'] ?? '' ) )
			. "\x1f" . (string) ( $entry['recipient_type'] ?? 'other' )
			. "\x1f" . (string) ( $entry['message_key'] ?? '' );
	}

	/**
	 * Deduplicates and validates a raw recipient list by LOGICAL message identity
	 * (email + recipient_type + message_key), not by email alone.
	 *
	 * Each input entry: 'email' (required); optional 'recipient_type'
	 * (member|company|other…), 'message_key', 'entity_type', 'entity_id'.
	 *
	 * @param array<int,array<string,mixed>> $raw Raw recipients.
	 * @return array{valid:array<int,array<string,mixed>>,skipped:array<int,array<string,string>>}
	 */
	public static function prepare( array $raw ): array {
		$valid   = array();
		$skipped = array();
		$seen    = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				$skipped[] = array( 'email' => '', 'reason' => 'invalid' );
				continue;
			}
			$email_raw = (string) ( $entry['email'] ?? '' );
			$email     = self::normalize( $email_raw );

			if ( ! self::valid( $email ) ) {
				$skipped[] = array( 'email' => $email_raw, 'reason' => 'invalid' );
				continue;
			}

			$identity = self::local_identity( $entry );
			if ( isset( $seen[ $identity ] ) ) {
				$skipped[] = array( 'email' => $email, 'reason' => 'duplicate' );
				continue;
			}
			$seen[ $identity ] = true;

			$valid[] = array(
				'email'          => $email,
				'recipient_type' => isset( $entry['recipient_type'] ) ? (string) $entry['recipient_type'] : 'other',
				'message_key'    => isset( $entry['message_key'] ) ? (string) $entry['message_key'] : '',
				'entity_type'    => isset( $entry['entity_type'] ) ? (string) $entry['entity_type'] : 'general',
				'entity_id'      => isset( $entry['entity_id'] ) ? (int) $entry['entity_id'] : 0,
			);
		}

		return array( 'valid' => $valid, 'skipped' => $skipped );
	}
}
