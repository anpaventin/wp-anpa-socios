<?php
/**
 * Builders for template contexts that must satisfy an invariant (fase36, PR-36s1c-2).
 *
 * The frozen renderer has optional blocks but no `else`. Two live emails —
 * `member_application_approved` and `member_application_completed` — branch on whether a
 * members'-area URL was supplied, **with different wording in each branch**, so the
 * behaviour is reproduced with two complementary optional blocks:
 *
 *     {{#ligazon_area_socios}}…with the link…{{/}}
 *     {{#sen_ligazon_area_socios}}…without the link…{{/}}
 *
 * That only works if exactly one of the pair is ever set. Leaving that to each emitter
 * would mean the same invariant written twice and, eventually, once — so it is built here,
 * in one place, and asserted.
 *
 * Pure: no WordPress, no database, no clock.
 *
 * @since  1.40.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

final class ANPA_Socios_Email_Template_Context {

	/** Token carrying the members'-area URL when there is one. */
	const TOKEN_AREA_LINK = 'ligazon_area_socios';

	/** Complementary flag, truthy only when there is NO members'-area URL. */
	const TOKEN_NO_AREA_LINK = 'sen_ligazon_area_socios';

	/** Internal value of the complementary flag. Never shown to anybody. */
	const FLAG = '1';

	/**
	 * Builds the mutually exclusive members'-area pair.
	 *
	 * @since  1.40.0
	 * @param  string $url Members'-area URL, empty when there is none.
	 * @return array<string,string> Exactly one of the two tokens is non-empty.
	 */
	public static function area_link( string $url ): array {
		$url = trim( $url );

		if ( '' !== $url ) {
			return array(
				self::TOKEN_AREA_LINK    => $url,
				self::TOKEN_NO_AREA_LINK => '',
			);
		}

		return array(
			self::TOKEN_AREA_LINK    => '',
			self::TOKEN_NO_AREA_LINK => self::FLAG,
		);
	}

	/**
	 * Verifies the invariant on a context about to be rendered.
	 *
	 * Both set would print two contradictory paragraphs; both empty would print neither and
	 * leave the family with no instructions at all. The second is the worse failure and the
	 * easier one to introduce, because "no URL configured" looks like a harmless empty
	 * value.
	 *
	 * @since  1.40.0
	 * @param  array<string,mixed> $context Context to check.
	 * @return void
	 * @throws ANPA_Socios_Email_Template_Registry_Error When the pair is not exclusive.
	 */
	public static function assert_area_link_exclusive( array $context ): void {
		$with    = '' !== trim( (string) ( $context[ self::TOKEN_AREA_LINK ] ?? '' ) );
		$without = '' !== trim( (string) ( $context[ self::TOKEN_NO_AREA_LINK ] ?? '' ) );

		if ( $with && $without ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				'both members-area branches are active; the email would contradict itself'
			);
		}
		if ( ! $with && ! $without ) {
			throw new ANPA_Socios_Email_Template_Registry_Error(
				'neither members-area branch is active; the email would give no access instructions'
			);
		}
	}

	/**
	 * Whether an event's templates depend on the exclusive pair.
	 *
	 * Declared here rather than inferred from the template text, so an emitter can be
	 * checked before it renders anything.
	 *
	 * @since  1.40.0
	 * @return string[] Event keys.
	 */
	public static function events_requiring_area_link(): array {
		return array( 'member_application_approved', 'member_application_completed' );
	}
}
