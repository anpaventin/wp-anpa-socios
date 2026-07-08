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

		$url = defined( 'ANPA_SOCIOS_UPDATE_URL' ) && ANPA_SOCIOS_UPDATE_URL
			? (string) ANPA_SOCIOS_UPDATE_URL
			: self::METADATA_URL;

		call_user_func( array( $factory, 'buildUpdateChecker' ), $url, ANPA_SOCIOS_PLUGIN_FILE, self::SLUG );
	}
}
