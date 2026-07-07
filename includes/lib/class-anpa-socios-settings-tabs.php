<?php
/**
 * Pure helper for the native admin settings tabs (fase13a).
 *
 * Knows the ordered tab list and resolves the active tab from a requested
 * slug, without touching WordPress. Kept pure so it is unit-testable with
 * PHPUnit (no WP bootstrap).
 *
 * @since  1.25.0
 * @package ANPA_Socios
 */

declare(strict_types=1);

/**
 * Tab list + active-tab resolution for the settings screen.
 *
 * @since 1.25.0
 */
final class ANPA_Socios_Settings_Tabs {

	/**
	 * Ordered tab slug => label map. First entry is the default tab.
	 *
	 * @since 1.25.0
	 * @var array<string,string>
	 */
	const TABS = array(
		'xeral'          => 'Xeral',
		'verificacion'   => 'Verificación',
		'actualizacions' => 'Actualizacións',
		'mantemento'     => 'Copias & Mantemento',
	);

	/**
	 * Returns the ordered tab map.
	 *
	 * @since  1.25.0
	 * @return array<string,string>
	 */
	public static function all(): array {
		return self::TABS;
	}

	/**
	 * The default tab slug (first in the ordered list).
	 *
	 * @since  1.25.0
	 * @return string
	 */
	public static function default_tab(): string {
		$keys = array_keys( self::TABS );

		return (string) $keys[0];
	}

	/**
	 * Whether the given slug is a known tab.
	 *
	 * @since  1.25.0
	 * @param  mixed $slug Candidate slug.
	 * @return bool
	 */
	public static function is_valid( $slug ): bool {
		return is_string( $slug ) && array_key_exists( $slug, self::TABS );
	}

	/**
	 * Resolves the active tab: the requested slug if valid, else the default.
	 *
	 * @since  1.25.0
	 * @param  mixed $requested Raw requested slug (e.g. from $_GET['tab']).
	 * @return string
	 */
	public static function active( $requested ): string {
		return self::is_valid( $requested ) ? (string) $requested : self::default_tab();
	}

	/**
	 * Label for a tab slug (empty string when unknown).
	 *
	 * @since  1.25.0
	 * @param  string $slug Tab slug.
	 * @return string
	 */
	public static function label( string $slug ): string {
		return self::is_valid( $slug ) ? self::TABS[ $slug ] : '';
	}
}
