<?php
/**
 * Self-hosted update integration (fase13c).
 *
 * anpa-socios is distributed from a public Gitea repo (nando/wp-anpa-socios).
 * Gitea is NOT a VCS provider supported by plugin-update-checker (which only
 * knows GitHub/GitLab/BitBucket), so we use PUC's host-agnostic *self-hosted
 * metadata* mode: the plugin fetches a plain `details.json` (a raw-file GET,
 * which passes the site's WAF), and that JSON's `download_url` points at the
 * release asset ZIP. Everything the plugin does is a GET on the public URL —
 * no secret is shipped.
 *
 * A token is only needed if the repo ever becomes private; in that case define
 * ANPA_SOCIOS_GITEA_TOKEN in wp-config.php (never hardcode it here).
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
	const METADATA_URL = 'https://gitea.casabetty.mywire.org/nando/wp-anpa-socios/raw/branch/main/details.json';

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

		$checker = call_user_func( array( $factory, 'buildUpdateChecker' ), $url, ANPA_SOCIOS_PLUGIN_FILE, self::SLUG );

		// Optional auth for a future PRIVATE repo. Public repo needs none.
		if ( defined( 'ANPA_SOCIOS_GITEA_TOKEN' ) && ANPA_SOCIOS_GITEA_TOKEN && is_object( $checker ) && method_exists( $checker, 'setAuthentication' ) ) {
			$checker->setAuthentication( (string) ANPA_SOCIOS_GITEA_TOKEN );
		}
	}
}
