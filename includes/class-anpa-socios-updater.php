<?php
/**
 * Self-hosted update integration.
 *
 * anpa-socios is distributed from a PUBLIC GitHub repo
 * (anpaventin/wp-anpa-socios). GitHub is not a VCS provider natively wired
 * into plugin-update-checker's simple mode here, so we use PUC's host-agnostic
 * *self-hosted metadata* mode: the plugin fetches a plain `details.json` (raw
 * from the repo) and that JSON's `download_url` points at the release asset ZIP.
 *
 * The repo is public, so no authentication token is required. The metadata URL
 * can still be overridden per install via the ANPA_SOCIOS_UPDATE_URL constant
 * in wp-config.php (e.g. to point at a fork or mirror).
 *
 * @since  1.24.0
 * @package ANPA_Socios
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ANPA_Socios_Updater {

	/**
	 * Default metadata URL: raw details.json on the public repo's main branch.
	 * Overridable via the ANPA_SOCIOS_UPDATE_URL constant (wp-config.php).
	 *
	 * @var string
	 */
	const METADATA_URL = 'https://raw.githubusercontent.com/anpaventin/wp-anpa-socios/main/details.json';

	/**
	 * Prerelease (beta) channel metadata URL. Only used when the install opts
	 * in via Axustes → Actualizacións (ANPA_Socios_Config::use_prereleases()).
	 *
	 * @var string
	 */
	const PRERELEASE_METADATA_URL = 'https://raw.githubusercontent.com/anpaventin/wp-anpa-socios/main/details-prerelease.json';

	/**
	 * Public repository URL (for the admin "update source" link).
	 *
	 * @var string
	 */
	const REPO_URL = 'https://github.com/anpaventin/wp-anpa-socios';

	/**
	 * Plugin folder slug (must match the installed directory name).
	 *
	 * @var string
	 */
	const SLUG = 'anpa-socios';

	/**
	 * Builds the update checker. Safe to call once during bootstrap.
	 *
	 * @return void
	 */
	public static function init(): void {
		$inc = ANPA_SOCIOS_PLUGIN_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $inc ) ) {
			return; // Library not vendored — fail silently, never break the site.
		}
		require_once $inc;

		$factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
		if ( ! class_exists( $factory ) ) {
			$factory = '\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory';
			if ( ! class_exists( $factory ) ) {
				return;
			}
		}

		// Channel resolution (highest priority first):
		//   1. ANPA_SOCIOS_UPDATE_URL constant (wp-config.php) — full override.
		//   2. Prerelease channel when the admin opted in (Axustes → Actualizacións).
		//   3. Stable channel (default) — production installs stay here.
		if ( defined( 'ANPA_SOCIOS_UPDATE_URL' ) && ANPA_SOCIOS_UPDATE_URL ) {
			$url = (string) ANPA_SOCIOS_UPDATE_URL;
		} elseif ( class_exists( 'ANPA_Socios_Config' ) && ANPA_Socios_Config::use_prereleases() ) {
			$url = self::PRERELEASE_METADATA_URL;
		} else {
			$url = self::METADATA_URL;
		}

		call_user_func( array( $factory, 'buildUpdateChecker' ), $url, ANPA_SOCIOS_PLUGIN_FILE, self::SLUG );

		// 1.63.1: the library ships Spanish but no Galician, so the «Check for
		// updates» link and its result notice on the Plugins screen stayed in
		// English on a gl_ES site. Label them in the plugin's own language (the
		// es_ES catalogue translates them for Spanish sites).
		add_filter( 'puc_manual_check_link-' . self::SLUG, array( __CLASS__, 'manual_check_link_text' ) );
		add_filter( 'puc_manual_check_message-' . self::SLUG, array( __CLASS__, 'manual_check_message' ), 10, 2 );
	}

	/**
	 * Text of the manual «Check for updates» link in the plugin row.
	 *
	 * @since  1.63.1
	 * @return string
	 */
	public static function manual_check_link_text(): string {
		return __( 'Comprobar actualizacións', 'anpa-socios' );
	}

	/**
	 * Result notice after a manual check (statuses defined by the library).
	 *
	 * @since  1.63.1
	 * @param  string $message Library message (already escaped).
	 * @param  string $status  'no_update' | 'update_available' | 'error' | other.
	 * @return string
	 */
	public static function manual_check_message( $message, $status ): string {
		switch ( (string) $status ) {
			case 'no_update':
				return esc_html__( 'ANPA Socios está actualizado: non hai ningunha versión nova.', 'anpa-socios' );
			case 'update_available':
				return esc_html__( 'Hai unha nova versión de ANPA Socios dispoñible. Actualízao desde esta mesma páxina de Plugins.', 'anpa-socios' );
			case 'error':
				return esc_html__( 'Non se puido comprobar se hai actualizacións de ANPA Socios. Téntao de novo nuns minutos.', 'anpa-socios' );
			default:
				return (string) $message;
		}
	}
}
