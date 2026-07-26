<?php
/**
 * Validating builder for the email template registry (fase36, PR-36s1b).
 *
 * Takes a variable dictionary and a list of event declarations and returns typed
 * definitions, or throws. There is no partial success: an inconsistent registry means
 * the editor would offer a token nothing fills, or two spellings that disagree about
 * which variable they mean, and neither is something to discover from a family's
 * inbox.
 *
 * The declarations are kept OUT of this class on purpose. The engine is validated
 * against deliberately broken fixtures, which is how the error paths get covered at
 * all; the real 28 events are asserted separately.
 *
 * ALIASES ARE DECLARED ON THE VARIABLE, NOT ON THE EVENT. The brief asked for a
 * per-event alias table plus a test forbidding the same alias in two events, but the
 * campaign tokens (`nome_campana` and its accented and Castilian spellings) are shared
 * by every campaign event, so per-event tables would make global uniqueness and reuse
 * mutually exclusive. Declaring them once next to the canonical token makes uniqueness
 * structural — a dictionary is a map — and reduces the remaining failure mode to two
 * different variables claiming the same spelling, which this class rejects.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Registry {

	/** Event keys and canonical tokens are English ASCII identifiers. */
	const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

	/** Alias spellings may carry accents, because operators type them. */
	const ALIAS_PATTERN = '/^[\p{L}0-9_]+$/u';

	/** The pre-fase36 emitters are the `enviar_*` methods of ANPA_Socios_Email. */
	const LEGACY_PATTERN = '/^enviar_[a-z_]+$/';

	/**
	 * Builds and fully validates a registry.
	 *
	 * @since  1.40.0
	 * @param  array<string,array<string,mixed>> $dictionary Canonical token => spec
	 *                                                       (label, description, example,
	 *                                                       type, aliases).
	 * @param  string[]                          $globals    Tokens available to every event.
	 * @param  array<int,array<string,mixed>>    $events     LIST of event declarations. A list,
	 *                                                       not a map, so a duplicated event key
	 *                                                       is detectable instead of silently
	 *                                                       overwriting its twin.
	 * @return ANPA_Socios_Email_Template_Set Immutable, fingerprinted set.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On any inconsistency.
	 */
	public static function build( array $dictionary, array $globals, array $events ): ANPA_Socios_Email_Template_Set {
		$specs = self::validate_dictionary( $dictionary );
		self::validate_globals( $globals, $specs );

		$definitions = array();
		$seen_keys   = array();
		$seen_legacy = array();

		foreach ( $events as $index => $declaration ) {
			if ( ! is_array( $declaration ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error( "event declaration #{$index} is not an array" );
			}

			$key = trim( (string) ( $declaration['event_key'] ?? '' ) );
			if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"event declaration #{$index} has an invalid key '{$key}' (English lowercase ASCII expected)"
				);
			}
			if ( isset( $seen_keys[ $key ] ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"duplicate event key '{$key}' (declarations #{$seen_keys[$key]} and #{$index})"
				);
			}
			$seen_keys[ $key ] = $index;

			$definition          = self::build_one( $key, $declaration, $specs, $globals, $dictionary );
			$definitions[ $key ] = $definition;

			// One legacy emitter maps to exactly one event, or the golden oracle and the
			// registry could both look complete while disagreeing about which is which.
			$legacy = $definition->legacy_emitter();
			if ( '' !== $legacy ) {
				if ( isset( $seen_legacy[ $legacy ] ) ) {
					throw new ANPA_Socios_Email_Template_Registry_Error(
						"legacy emitter '{$legacy}' is claimed by both '{$seen_legacy[$legacy]}' and '{$key}'"
					);
				}
				$seen_legacy[ $legacy ] = $key;
			}
		}

		if ( array() === $definitions ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( 'the registry declares no events' );
		}

		return new ANPA_Socios_Email_Template_Set( $definitions );
	}

	/**
	 * Validates the variable dictionary and turns it into variable prototypes.
	 *
	 * @since  1.40.0
	 * @param  array<string,array<string,mixed>> $dictionary Canonical token => spec.
	 * @return array<string,ANPA_Socios_Email_Template_Variable>
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an invalid entry or alias clash.
	 */
	private static function validate_dictionary( array $dictionary ): array {
		if ( array() === $dictionary ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( 'the variable dictionary is empty' );
		}

		$specs      = array();
		$alias_owner = array();

		foreach ( $dictionary as $token => $spec ) {
			$token = (string) $token;
			if ( ! is_array( $spec ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error( "variable '{$token}' has a non-array spec" );
			}

			// from_spec() raises on a bad key, a missing label/description/example or an
			// unknown type. Required-ness is per event, so the prototype is optional.
			$specs[ $token ] = ANPA_Socios_Email_Template_Variable::from_spec( $token, $spec, false, false );

			foreach ( self::alias_list( $token, $spec ) as $alias ) {
				if ( isset( $dictionary[ $alias ] ) ) {
					throw new ANPA_Socios_Email_Template_Registry_Error(
						"alias '{$alias}' of '{$token}' shadows the canonical variable of the same name"
					);
				}
				if ( isset( $alias_owner[ $alias ] ) ) {
					throw new ANPA_Socios_Email_Template_Registry_Error(
						"alias '{$alias}' is claimed by both '{$alias_owner[$alias]}' and '{$token}'"
					);
				}
				$alias_owner[ $alias ] = $token;
			}
		}

		return $specs;
	}

	/**
	 * @since  1.40.0
	 * @param  string[]                                          $globals Global tokens.
	 * @param  array<string,ANPA_Socios_Email_Template_Variable>  $specs   Known variables.
	 * @throws ANPA_Socios_Email_Template_Registry_Error When a global is not in the dictionary.
	 */
	private static function validate_globals( array $globals, array $specs ): void {
		foreach ( $globals as $token ) {
			if ( ! isset( $specs[ (string) $token ] ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"global variable '{$token}' is not in the dictionary"
				);
			}
		}
	}

	/**
	 * Validates and builds a single event.
	 *
	 * @since  1.40.0
	 * @param  string                                           $key         Event key.
	 * @param  array<string,mixed>                              $declaration Raw declaration.
	 * @param  array<string,ANPA_Socios_Email_Template_Variable> $specs      Variable prototypes.
	 * @param  string[]                                         $globals     Global tokens.
	 * @param  array<string,array<string,mixed>>                $dictionary  Raw dictionary (aliases).
	 * @return ANPA_Socios_Email_Template_Definition
	 * @throws ANPA_Socios_Email_Template_Registry_Error On any invalid field.
	 */
	private static function build_one(
		string $key,
		array $declaration,
		array $specs,
		array $globals,
		array $dictionary
	): ANPA_Socios_Email_Template_Definition {
		$display_name = trim( (string) ( $declaration['display_name'] ?? '' ) );
		$description  = trim( (string) ( $declaration['description'] ?? '' ) );

		if ( '' === $display_name ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "event '{$key}' has no display name" );
		}
		if ( '' === $description ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"event '{$key}' has no description; the editor would show a bare technical key"
			);
		}

		$category = (string) ( $declaration['category'] ?? '' );
		if ( ! in_array( $category, ANPA_Socios_Email_Template_Definition::categories(), true ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "event '{$key}' has unknown category '{$category}'" );
		}

		$audience = (string) ( $declaration['audience'] ?? '' );
		if ( ! in_array( $audience, ANPA_Socios_Email_Template_Definition::audiences(), true ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "event '{$key}' has unknown audience '{$audience}'" );
		}

		// A typed phase, so a renamed phase is one constant rather than dozens of literals,
		// and an invented phase name cannot exist at all.
		$raw_phase = trim( (string) ( $declaration['phase'] ?? '' ) );
		if ( '' === $raw_phase ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"event '{$key}' does not say which phase owns its emitter"
			);
		}
		$phase = ANPA_Socios_Email_Template_Phase::from( $raw_phase );

		$raw_retired = trim( (string) ( $declaration['retired_in'] ?? '' ) );
		$retired_in  = '' === $raw_retired ? null : ANPA_Socios_Email_Template_Phase::from( $raw_retired );

		// Retirement must ship strictly after introduction. Asking the phase type rather
		// than comparing identifiers matters here: the delivery order is 34 → 35 → 36 →
		// 38 → 39 → 37 → 41 → 40, so a numeric or string comparison would accept a
		// retirement that actually happens first.
		if ( null !== $retired_in && ! $retired_in->is_after( $phase ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				sprintf(
					"event '%s' is retired in '%s', which does not ship after the phase that introduced it ('%s')",
					$key,
					$retired_in->id(),
					$phase->id()
				)
			);
		}

		$legacy_emitter = self::validate_legacy_emitter( $key, $declaration, $phase, null !== $retired_in );

		$default_template = trim( (string) ( $declaration['default_template'] ?? $key ) );
		if ( 1 !== preg_match( self::KEY_PATTERN, $default_template ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"event '{$key}' has an invalid default template stem '{$default_template}'"
			);
		}

		$variables = self::build_variables( $key, $declaration, $specs, $globals );
		$aliases   = self::build_aliases( $variables, $dictionary );

		return new ANPA_Socios_Email_Template_Definition(
			array(
				'event_key'        => $key,
				'display_name'     => $display_name,
				'description'      => $description,
				'category'         => $category,
				'audience'         => $audience,
				'phase'            => $phase,
				'retired_in'       => $retired_in,
				'default_template' => $default_template,
				'legacy_emitter'   => $legacy_emitter,
			),
			$variables,
			$aliases
		);
	}

	/**
	 * Validates the pre-fase36 emitter an event replaces.
	 *
	 * A live event MUST name it and a not-yet-live one MUST NOT, because this field is
	 * the join between the registry and the golden-file oracle. If it were optional, a
	 * live email could be declared with no counterpart in the oracle and the bidirectional
	 * check would silently pass over it.
	 *
	 * @since  1.40.0
	 * @param  string                            $key         Event key.
	 * @param  array<string,mixed>               $declaration Raw declaration.
	 * @param  ANPA_Socios_Email_Template_Phase  $phase       Owning phase.
	 * @param  bool                              $retired     Whether it has been retired.
	 * @return string
	 * @throws ANPA_Socios_Email_Template_Registry_Error On a missing, spurious or malformed value.
	 */
	private static function validate_legacy_emitter(
		string $key,
		array $declaration,
		ANPA_Socios_Email_Template_Phase $phase,
		bool $retired
	): string {
		$legacy = trim( (string) ( $declaration['legacy_emitter'] ?? '' ) );
		$live   = $phase->is_live() && ! $retired;

		if ( $live && '' === $legacy ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"live event '{$key}' does not name the emitter it replaces, so it cannot be matched to a golden file"
			);
		}
		if ( ! $live && '' !== $legacy ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"event '{$key}' is not live but names legacy emitter '{$legacy}'"
			);
		}
		if ( '' !== $legacy && 1 !== preg_match( self::LEGACY_PATTERN, $legacy ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"event '{$key}' has an invalid legacy emitter '{$legacy}'"
			);
		}

		return $legacy;
	}

	/**
	 * Merges the globals with the event's own variables.
	 *
	 * The event's `variables` entry is a map of token => required. A map, so the same
	 * token cannot appear twice within one event by construction; the duplicate that
	 * IS possible — an event redeclaring a global — is rejected here, because two
	 * declarations of one token can disagree about whether it is required.
	 *
	 * @since  1.40.0
	 * @param  string                                           $key         Event key.
	 * @param  array<string,mixed>                              $declaration Raw declaration.
	 * @param  array<string,ANPA_Socios_Email_Template_Variable> $specs      Variable prototypes.
	 * @param  string[]                                         $globals     Global tokens.
	 * @return array<string,ANPA_Socios_Email_Template_Variable>
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an unknown or duplicated token.
	 */
	private static function build_variables( string $key, array $declaration, array $specs, array $globals ): array {
		$own = $declaration['variables'] ?? array();
		if ( ! is_array( $own ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "event '{$key}' has a non-array variable list" );
		}

		$variables = array();
		foreach ( $globals as $token ) {
			$token               = (string) $token;
			$variables[ $token ] = $specs[ $token ]->with_scope( false, true );
		}

		foreach ( $own as $token => $required ) {
			$token = (string) $token;
			if ( ! isset( $specs[ $token ] ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"event '{$key}' declares variable '{$token}', which is not in the dictionary"
				);
			}
			if ( in_array( $token, $globals, true ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"event '{$key}' redeclares global variable '{$token}'"
				);
			}
			$variables[ $token ] = $specs[ $token ]->with_scope( (bool) $required, false );
		}

		if ( array() === $variables ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "event '{$key}' declares no variables" );
		}

		return $variables;
	}

	/**
	 * The alias map for an event: every alternative spelling of the variables it has.
	 *
	 * Derived, so an event can never carry an alias for a token it does not declare —
	 * the shape the brief asked to be tested for is impossible to express here.
	 *
	 * @since  1.40.0
	 * @param  array<string,ANPA_Socios_Email_Template_Variable> $variables  Event variables.
	 * @param  array<string,array<string,mixed>>                $dictionary Raw dictionary.
	 * @return array<string,string> Alias => canonical token.
	 */
	private static function build_aliases( array $variables, array $dictionary ): array {
		$aliases = array();
		foreach ( array_keys( $variables ) as $token ) {
			foreach ( self::alias_list( (string) $token, (array) ( $dictionary[ $token ] ?? array() ) ) as $alias ) {
				$aliases[ $alias ] = (string) $token;
			}
		}

		return $aliases;
	}

	/**
	 * Reads and validates the alias spellings declared on one variable.
	 *
	 * @since  1.40.0
	 * @param  string              $token Canonical token.
	 * @param  array<string,mixed> $spec  Dictionary entry.
	 * @return string[]
	 * @throws ANPA_Socios_Email_Template_Registry_Error On a malformed or repeated alias.
	 */
	private static function alias_list( string $token, array $spec ): array {
		$raw = $spec['aliases'] ?? array();
		if ( ! is_array( $raw ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error( "variable '{$token}' has a non-array alias list" );
		}

		$aliases = array();
		foreach ( $raw as $alias ) {
			$alias = trim( (string) $alias );
			if ( 1 !== preg_match( self::ALIAS_PATTERN, $alias ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"variable '{$token}' has an invalid alias '{$alias}'"
				);
			}
			if ( $alias === $token ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"variable '{$token}' lists itself as an alias"
				);
			}
			if ( in_array( $alias, $aliases, true ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"variable '{$token}' repeats the alias '{$alias}'"
				);
			}
			$aliases[] = $alias;
		}

		return $aliases;
	}
}
