<?php
/**
 * Builds preview contexts for events that branch on the members'-area pair.
 *
 * The two events `member_application_approved` and `member_application_completed` declare
 * BOTH `ligazon_area_socios` and `sen_ligazon_area_socios`. A naive preview that fills
 * both from their declared examples would show two contradictory paragraphs at once. This
 * builder produces BOTH variants (with-URL and without-URL) so the editor can display each
 * branch separately and the administrator sees exactly what a family receives in each case.
 *
 * Delegates to `ANPA_Socios_Email_Template_Context::area_link()` for the pair values and
 * to `ANPA_Socios_Email_Template_Scenarios` for the full context — so the preview cannot
 * show a format the emitters could not produce.
 *
 * PURE CLASS: no WordPress functions, no `esc_html`, no `get_option`, no `$wpdb`,
 * no `apply_filters`, no `__()`. Testable without any WP bootstrap.
 *
 * @since  1.48.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Template_Preview_Context {

	/**
	 * Whether a given event requires the branching preview (both variants).
	 *
	 * Derived from the declaration: any event declaring the complementary token
	 * `sen_ligazon_area_socios` has wording that branches on the pair.
	 *
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return bool
	 */
	public static function requires_branching_preview( ANPA_Socios_Email_Template_Definition $definition ): bool {
		$declared = $definition->variables();
		return isset( $declared[ ANPA_Socios_Email_Template_Context::TOKEN_NO_AREA_LINK ] );
	}

	/**
	 * Returns the list of events requiring a branching preview.
	 *
	 * Delegates to `Context::events_requiring_area_link()` for the canonical list.
	 *
	 * @return string[] Event keys.
	 */
	public static function branching_events(): array {
		return ANPA_Socios_Email_Template_Context::events_requiring_area_link();
	}

	/**
	 * Builds both preview variants for a branching event.
	 *
	 * Returns an associative array with exactly two entries:
	 * - `with_area_link`: context where the members'-area URL is set.
	 * - `without_area_link`: context where the URL is absent.
	 *
	 * Each context is ready to render and passes the pre-render gate.
	 *
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return array{with_area_link: array<string,string>, without_area_link: array<string,string>}
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the event does not branch.
	 */
	public static function build_variants( ANPA_Socios_Email_Template_Definition $definition ): array {
		if ( ! self::requires_branching_preview( $definition ) ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				sprintf(
					'Event "%s" does not declare the area-link pair; branching preview is not applicable.',
					$definition->event_key()
				)
			);
		}

		return array(
			'with_area_link'    => ANPA_Socios_Email_Template_Scenarios::build(
				$definition,
				ANPA_Socios_Email_Template_Scenarios::ID_WITH_AREA_LINK
			),
			'without_area_link' => ANPA_Socios_Email_Template_Scenarios::build(
				$definition,
				ANPA_Socios_Email_Template_Scenarios::ID_WITHOUT_AREA_LINK
			),
		);
	}

	/**
	 * Builds a single non-branching preview context using the default scenario.
	 *
	 * For events that do NOT branch on the area-link pair, this is the standard preview.
	 *
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return array<string,string> Context ready to render.
	 */
	public static function build_default( ANPA_Socios_Email_Template_Definition $definition ): array {
		return ANPA_Socios_Email_Template_Scenarios::build(
			$definition,
			ANPA_Socios_Email_Template_Scenarios::ID_DEFAULT
		);
	}

	/**
	 * Builds every applicable preview variant for a given event.
	 *
	 * For non-branching events: returns `array( 'default' => $context )`.
	 * For branching events: returns `array( 'with_area_link' => ..., 'without_area_link' => ... )`.
	 *
	 * The editor can iterate this result to show each variant with its scenario label.
	 *
	 * @param  ANPA_Socios_Email_Template_Definition $definition Event declaration.
	 * @return array<string, array<string,string>> Keyed by variant id.
	 */
	public static function all_variants( ANPA_Socios_Email_Template_Definition $definition ): array {
		if ( self::requires_branching_preview( $definition ) ) {
			return self::build_variants( $definition );
		}

		return array(
			'default' => self::build_default( $definition ),
		);
	}

	/**
	 * Scenario labels for the variant keys this builder produces.
	 *
	 * @return array<string,string> Variant key => Galician label.
	 */
	public static function variant_labels(): array {
		return array(
			'with_area_link'    => ANPA_Socios_Email_Template_Scenarios::label(
				ANPA_Socios_Email_Template_Scenarios::ID_WITH_AREA_LINK
			),
			'without_area_link' => ANPA_Socios_Email_Template_Scenarios::label(
				ANPA_Socios_Email_Template_Scenarios::ID_WITHOUT_AREA_LINK
			),
			'default'           => ANPA_Socios_Email_Template_Scenarios::label(
				ANPA_Socios_Email_Template_Scenarios::ID_DEFAULT
			),
		);
	}
}
