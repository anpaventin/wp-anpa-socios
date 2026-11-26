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
	 * Only release assets under this prefix are accepted as update packages
	 * (unless ANPA_SOCIOS_UPDATE_URL overrides the metadata source).
	 *
	 * @since 1.102.0
	 * @var string
	 */
	const DOWNLOAD_PREFIX = 'https://github.com/anpaventin/wp-anpa-socios/releases/download/';

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
		$override = defined( 'ANPA_SOCIOS_UPDATE_URL' ) && ANPA_SOCIOS_UPDATE_URL;
		if ( $override ) {
			$url = (string) ANPA_SOCIOS_UPDATE_URL;
		} elseif ( class_exists( 'ANPA_Socios_Config' ) && ANPA_Socios_Config::use_prereleases() ) {
			$url = self::PRERELEASE_METADATA_URL;
		} else {
			$url = self::METADATA_URL;
		}

		call_user_func( array( $factory, 'buildUpdateChecker' ), $url, ANPA_SOCIOS_PLUGIN_FILE, self::SLUG );

		// Pin the download source: the metadata JSON is fetched from the repo, but
		// its download_url is only trusted when it points at this repo's GitHub
		// release assets. An operator-chosen ANPA_SOCIOS_UPDATE_URL (fork or
		// mirror) is an explicit decision, so the pin is skipped then.
		if ( ! $override ) {
			add_filter( 'puc_request_info_result-' . self::SLUG, array( __CLASS__, 'pin_download_url' ), 10, 2 );
		}

		// 1.63.1: the library ships Spanish but no Galician, so the «Check for
		// updates» link and its result notice on the Plugins screen stayed in
		// English on a gl_ES site. Label them in the plugin's own language (the
		// es_ES catalogue translates them for Spanish sites).
		add_filter( 'puc_manual_check_link-' . self::SLUG, array( __CLASS__, 'manual_check_link_text' ) );
		add_filter( 'puc_manual_check_message-' . self::SLUG, array( __CLASS__, 'manual_check_message' ), 10, 2 );
	}

	/**
	 * Drops the update info when its download_url is not an allowed release asset.
	 *
	 * Hooked on the library's `puc_request_info_result-{slug}` filter, which
	 * feeds both the update check and the «View details» popup. Returning null
	 * means «no update available».
	 *
	 * @since  1.102.0
	 * @param  object|null $info   Library PluginInfo, or null.
	 * @param  mixed       $result Raw HTTP result (unused).
	 * @return object|null
	 */
	public static function pin_download_url( $info, $result = null ) {
		unset( $result );
		if ( ! is_object( $info ) ) {
			return $info;
		}
		$download = isset( $info->download_url ) ? (string) $info->download_url : '';

		return self::is_allowed_download_url( $download ) ? $info : null;
	}

	/**
	 * Whether a download URL points at this repo's GitHub release assets.
	 *
	 * Pure: a strict prefix match plus rejection of path traversal, backslashes,
	 * whitespace/control characters, credentials, queries and fragments.
	 *
	 * @since  1.102.0
	 * @param  string $url Candidate download URL.
	 * @return bool
	 */
	public static function is_allowed_download_url( string $url ): bool {
		if ( 0 !== strpos( $url, self::DOWNLOAD_PREFIX ) || strlen( $url ) <= strlen( self::DOWNLOAD_PREFIX ) ) {
			return false;
		}
		if ( false !== strpos( $url, '..' ) || 1 === preg_match( '/[\\\\\s\x00-\x1f\x7f@?#%]/', $url ) ) {
			return false;
		}

		return true;
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
