<?php
/**
 * Pre-render validation of a template context (fase36, PR-36s1c).
 *
 * The gap this closes: until now, "this variable is mandatory" lived in whichever helper the
 * emitter happened to call. If the emitter called the optional variant, or built the context by
 * hand, the requirement evaporated — and the renderer would happily produce an email with a
 * paragraph missing, because an optional block removes itself silently.
 *
 * So the requirement is checked against the **event declaration**, not against the helper used:
 *
 *   - every token the registry declares as required must be present and non-empty;
 *   - mutually exclusive pairs must have exactly one side active;
 *   - a token the event does not declare must not be supplied at all, because a context key that
 *     nothing can render is either a typo or a leftover from another event.
 *
 * Runs BEFORE rendering. The renderer is frozen and is not reopened: this is a separate gate, and
 * it fails loudly where the renderer would have failed silently.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Validator {

	/**
	 * Validates a context against its event declaration.
	 *
	 * @since  1.40.0
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @param  array<string,mixed>                   $context    Context about to be rendered.
	 * @return void
	 * @throws ANPA_Socios_Email_Template_Registry_Error On any violation.
	 */
	public static function assert_context(
		ANPA_Socios_Email_Template_Definition $definition,
		array $context
	): void {
		$event = $definition->event_key();

		self::assert_no_unknown_tokens( $event, $definition, $context );
		self::assert_required_present( $event, $definition, $context );
		self::assert_exclusive_pairs( $event, $definition, $context );
	}

	/**
	 * @param  string                                $event      Event key.
	 * @param  ANPA_Socios_Email_Template_Definition $definition Declaration.
	 * @param  array<string,mixed>                   $context    Context.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On an undeclared key.
	 */
	private static function assert_no_unknown_tokens(
		string $event,
		ANPA_Socios_Email_Template_Definition $definition,
		array $context
	): void {
		$declared = $definition->variables();

		foreach ( array_keys( $context ) as $token ) {
			if ( ! isset( $declared[ (string) $token ] ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"event '{$event}' was given '{$token}', which it does not declare; a key nothing can render is a typo or a leftover"
				);
			}
		}
	}

	/**
	 * @param  string                                $event      Event key.
	 * @param  ANPA_Socios_Email_Template_Definition $definition Declaration.
	 * @param  array<string,mixed>                   $context    Context.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On a missing or empty required token.
	 */
	private static function assert_required_present(
		string $event,
		ANPA_Socios_Email_Template_Definition $definition,
		array $context
	): void {
		foreach ( $definition->required_tokens() as $token ) {
			if ( ! array_key_exists( $token, $context ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"event '{$event}' requires '{$token}' and the context does not supply it"
				);
			}

			// Empty is the dangerous case, not absent. An optional block would remove the whole
			// paragraph and the email would look finished.
			if ( '' === trim( (string) $context[ $token ] ) ) {
				throw new ANPA_Socios_Email_Template_Registry_Error(
					"event '{$event}' requires '{$token}' but it is empty; the paragraph would vanish and the email would look complete"
				);
			}
		}
	}

	/**
	 * @param  string                                $event      Event key.
	 * @param  ANPA_Socios_Email_Template_Definition $definition Declaration.
	 * @param  array<string,mixed>                   $context    Context.
	 * @throws ANPA_Socios_Email_Template_Registry_Error When an exclusive pair is not satisfied.
	 */
	private static function assert_exclusive_pairs(
		string $event,
		ANPA_Socios_Email_Template_Definition $definition,
		array $context
	): void {
		$declared = $definition->variables();

		$needs_area_pair = isset( $declared[ ANPA_Socios_Email_Template_Context::TOKEN_AREA_LINK ] )
			&& isset( $declared[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] );

		if ( ! $needs_area_pair ) {
			return;
		}

		try {
			ANPA_Socios_Email_Template_Context::assert_area_link_exclusive( $context );
		} catch ( ANPA_Socios_Email_Template_Registry_Error $e ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				"event '{$event}': " . $e->getMessage()
			);
		}
	}

	/**
	 * Validates and then renders, so no caller can skip the gate by accident.
	 *
	 * @since  1.40.0
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @param  array<string,string>                  $template   Subject and bodies.
	 * @param  array<string,mixed>                   $context    Context.
	 * @return array<string,mixed> The renderer's result.
	 * @throws ANPA_Socios_Email_Template_Registry_Error On any context violation.
	 */
	public static function render(
		ANPA_Socios_Email_Template_Definition $definition,
		array $template,
		array $context
	): array {
		self::assert_context( $definition, $context );

		return ANPA_Socios_Email_Template_Renderer::render(
			$template,
			$context,
			$definition->declared_tokens()
		);
	}
}
