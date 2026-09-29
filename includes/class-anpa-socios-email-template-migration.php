<?php
/**
 * FASE36: Template migration / seed on activation.
 *
 * Ensures canonical defaults are available on new installs and upgrades
 * without overwriting existing personalizations.
 *
 * @since  1.39.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Email_Template_Migration {

	/** Option name. */
	const OPTION = 'anpa_socios_email_templates';

	/** 1.83.0: plugin version whose defaults the unedited templates already follow. */
	const REFRESH_OPTION = 'anpa_socios_email_templates_refrescadas';

	/**
	 * Seeds defaults if the option does not exist.
	 *
	 * Idempotent: never overwrites existing personalizations.
	 *
	 * @since  1.39.0
	 * @return bool True if seed was written, false if option already exists.
	 */
	public static function seed_if_needed(): bool {
		$existing = get_option( self::OPTION, null );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return false;
		}

		$defaults = ANPA_Socios_Email_Template_Store::get_all_defaults();
		$seed     = array();

		foreach ( $defaults as $id => $template ) {
			$seed[ $id ] = array(
				'subject'  => $template['subject'],
				'html'     => $template['html'],
				'text'     => $template['text'],
				'modified' => '',
			);
		}

		return (bool) update_option( self::OPTION, $seed );
	}

	/**
	 * Adds new templates from code that don't exist in the option yet.
	 *
	 * Preserves existing personalizations and adds only new template IDs.
	 *
	 * @since  1.39.0
	 * @return int Number of new templates added.
	 */
	public static function add_new_templates(): int {
		$existing = get_option( self::OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$defaults = ANPA_Socios_Email_Template_Store::get_all_defaults();
		$added    = 0;

		foreach ( $defaults as $id => $default ) {
			if ( ! isset( $existing[ $id ] ) ) {
				$existing[ $id ] = array(
					'subject'  => $default['subject'],
					'html'     => $default['html'],
					'text'     => $default['text'],
					'modified' => '',
				);
				++$added;
			}
		}

		if ( $added > 0 ) {
			update_option( self::OPTION, $existing );
		}

		return $added;
	}

	/**
	 * Runs the full migration: seed if needed + add new templates.
	 *
	 * @since  1.39.0
	 * @return array{seeded: bool, added: int} Migration result.
	 */
	/**
	 * 1.83.0: a stored template nobody edited (modified === '') follows the
	 * current default, so new wording (e.g. the data deletion date in
	 * «baixa_socio_confirmada») reaches installations seeded earlier. Templates
	 * the junta edited are never touched.
	 *
	 * @since  1.83.0
	 * @return int Templates refreshed.
	 */
	public static function refresh_unmodified(): int {
		$existing = get_option( self::OPTION, array() );
		if ( ! is_array( $existing ) || array() === $existing ) {
			return 0;
		}
		// Once per plugin version and in the SITE language (defaults come from __(),
		// and an admin page loads in the admin's own profile language).
		$version = defined( 'ANPA_SOCIOS_VERSION' ) ? (string) ANPA_SOCIOS_VERSION : '';
		if ( '' !== $version && get_option( self::REFRESH_OPTION, '' ) === $version ) {
			return 0;
		}
		$cambiou = function_exists( 'switch_to_locale' ) && function_exists( 'get_locale' ) && switch_to_locale( get_locale() );
		$defaults  = ANPA_Socios_Email_Template_Store::get_all_defaults();
		if ( $cambiou ) {
			restore_previous_locale();
		}
		if ( '' !== $version ) {
			update_option( self::REFRESH_OPTION, $version, false );
		}
		$refreshed = 0;
		foreach ( $defaults as $id => $default ) {
			if ( ! isset( $existing[ $id ] ) || ! is_array( $existing[ $id ] ) || '' !== (string) ( $existing[ $id ]['modified'] ?? '' ) ) {
				continue;
			}
			$current = $existing[ $id ];
			if ( ( $current['subject'] ?? null ) === $default['subject'] && ( $current['html'] ?? null ) === $default['html'] && ( $current['text'] ?? null ) === $default['text'] ) {
				continue;
			}
			$existing[ $id ] = array(
				'subject'  => $default['subject'],
				'html'     => $default['html'],
				'text'     => $default['text'],
				'modified' => '',
			);
			++$refreshed;
		}
		if ( $refreshed > 0 ) {
			update_option( self::OPTION, $existing );
		}
		return $refreshed;
	}

	public static function migrate(): array {
		$seeded = self::seed_if_needed();
		$added  = $seeded ? 0 : self::add_new_templates();

		return array(
			'seeded'    => $seeded,
			'added'     => $added,
			'refreshed' => $seeded ? 0 : self::refresh_unmodified(),
		);
	}
}
