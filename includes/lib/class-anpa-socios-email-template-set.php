<?php
/**
 * An immutable, fingerprinted set of template definitions (fase36, PR-36s1b).
 *
 * The registry used to hand back its internal array. That works right up until someone
 * writes `$registry['foo'] = ...` or mutates a definition list in place, and then the
 * "validated at build time" guarantee is quietly false for the rest of the request.
 * This object never exposes the array: lookups go through `get()`, iteration through a
 * fresh generator, and `keys()` returns a copy of scalars that nothing can write back.
 *
 * It also carries a fingerprint over the whole declaration set. One hash answers
 * "is this installation running the templates I think it is?", which is the question
 * that actually gets asked during support, debugging and cache invalidation — and it is
 * nearly impossible to add convincingly after the fact.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Set implements Countable {

	/** @var array<string,ANPA_Socios_Email_Template_Definition> Event key => definition. */
	private array $definitions;

	/** @var string SHA-256 over the canonical serialisation of every declaration. */
	private string $fingerprint;

	/**
	 * @param array<string,ANPA_Socios_Email_Template_Definition> $definitions Event key => definition.
	 */
	public function __construct( array $definitions ) {
		$this->definitions = $definitions;
		$this->fingerprint = self::compute_fingerprint( $definitions );
	}

	/**
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return bool
	 */
	public function has( string $event_key ): bool {
		return isset( $this->definitions[ $event_key ] );
	}

	/**
	 * @since  1.40.0
	 * @param  string $event_key Event key.
	 * @return ANPA_Socios_Email_Template_Definition
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the key is not registered.
	 */
	public function get( string $event_key ): ANPA_Socios_Email_Template_Definition {
		if ( ! isset( $this->definitions[ $event_key ] ) ) {
			// Asking for a key that does not exist is a programming error, not a state an
			// operator can reach. Returning null here would push the failure to whatever
			// tries to render the missing template.
			throw new ANPA_Socios_Email_Template_Registry_Error( "unknown template event '{$event_key}'" );
		}

		return $this->definitions[ $event_key ];
	}

	/**
	 * Event keys in declaration order. Scalars only, so the caller holds a copy.
	 *
	 * @since  1.40.0
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->definitions );
	}

	/**
	 * A fresh generator each call, so no caller ever holds the backing array.
	 *
	 * @since  1.40.0
	 * @return iterable<string,ANPA_Socios_Email_Template_Definition>
	 */
	public function all(): iterable {
		foreach ( $this->definitions as $key => $definition ) {
			yield $key => $definition;
		}
	}

	/**
	 * @since  1.40.0
	 * @return int
	 */
	public function count(): int {
		return count( $this->definitions );
	}

	/**
	 * Keys whose emitter ships today.
	 *
	 * @since  1.40.0
	 * @return string[]
	 */
	public function live_keys(): array {
		$live = array();
		foreach ( $this->definitions as $key => $definition ) {
			if ( $definition->is_live() ) {
				$live[] = (string) $key;
			}
		}

		return $live;
	}

	/**
	 * The pre-fase36 emitters this registry replaces, mapped to their event.
	 *
	 * These are also the golden-file stems, which is what lets a test assert that the
	 * oracle and the registry describe exactly the same set of live emails, in both
	 * directions — a far more durable claim than counting templates.
	 *
	 * @since  1.40.0
	 * @return array<string,string> Legacy emitter => event key.
	 */
	public function legacy_emitters(): array {
		$map = array();
		foreach ( $this->definitions as $key => $definition ) {
			$emitter = $definition->legacy_emitter();
			if ( '' !== $emitter ) {
				$map[ $emitter ] = (string) $key;
			}
		}

		return $map;
	}

	/**
	 * @since  1.40.0
	 * @return string SHA-256 fingerprint of the whole declaration set.
	 */
	public function fingerprint(): string {
		return $this->fingerprint;
	}

	/**
	 * Hashes the canonical serialisation of every declaration.
	 *
	 * Declaration ORDER is part of the input, not sorted away: the order events and
	 * variables are declared in is the order the editor displays them in, so reordering
	 * is a real change and must move the fingerprint. Sorting first would hide exactly
	 * the kind of edit this is meant to reveal.
	 *
	 * @since  1.40.0
	 * @param  array<string,ANPA_Socios_Email_Template_Definition> $definitions Definitions.
	 * @return string
	 */
	private static function compute_fingerprint( array $definitions ): string {
		$canonical = array();
		foreach ( $definitions as $key => $definition ) {
			$canonical[] = array( (string) $key, $definition->to_array() );
		}

		return hash( 'sha256', self::encode( $canonical ) );
	}

	/**
	 * Deterministic JSON for hashing.
	 *
	 * Unicode and slashes are left unescaped so the bytes do not shift if a future PHP
	 * changes its default escaping, which would move every fingerprint without a single
	 * declaration having changed.
	 *
	 * @since  1.40.0
	 * @param  array<int,mixed> $value Value to encode.
	 * @return string
	 */
	private static function encode( array $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
