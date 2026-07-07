<?php
/**
 * Self-hosted update integration (fase13c).
 *
 * anpa-socios is distributed from a public Gitea repo (nando/wp-anpa-socios).
 * Gitea is NOT a VCS provider supported by plugin-update-checker (which only
 * knows GitHub/GitLab/BitBucket), so we use PUC's host-agnostic *self-hosted
 * metadata* mode: the plugin fetches a plain `details.json` and that JSON's
 * `download_url` points at the release asset ZIP.
 *
 * AUTH: the Gitea instance has "require sign-in to view" enabled, so anonymous
 * GETs are rejected even on public repos. Therefore the update check AND the
 * package download must send an `Authorization: token <...>` header. The token
 * is NEVER hardcoded here: it is read from the ANPA_SOCIOS_GITEA_TOKEN constant
 * defined in wp-config.php (a read-only token). If the instance is later made
 * anonymously readable, remove the constant and everything keeps working.
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
	 * Host that update/download requests get the auth header injected for.
	 *
	 * @var string
	 */
	private static $auth_host = '';

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

		// If a token is configured, inject it as an Authorization header for
		// every request to the Gitea host — this covers BOTH the details.json
		// fetch (PUC via wp_remote_get) and the package ZIP download (WP core).
		$token = self::token();
		if ( '' !== $token ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				self::$auth_host = strtolower( $host );
				add_filter( 'http_request_args', array( __CLASS__, 'add_auth_header' ), 10, 2 );
			}
		}

		$checker = call_user_func( array( $factory, 'buildUpdateChecker' ), $url, ANPA_SOCIOS_PLUGIN_FILE, self::SLUG );

		// Also set PUC's own auth (used by VCS providers; harmless for self-hosted).
		if ( '' !== $token && is_object( $checker ) && method_exists( $checker, 'setAuthentication' ) ) {
			$checker->setAuthentication( $token );
		}
	}

	/**
	 * Resolves the optional read token from wp-config (never hardcoded).
	 *
	 * @return string Empty string when no token is configured.
	 */
	private static function token(): string {
		if ( defined( 'ANPA_SOCIOS_GITEA_TOKEN' ) && ANPA_SOCIOS_GITEA_TOKEN ) {
			return (string) ANPA_SOCIOS_GITEA_TOKEN;
		}

		return '';
	}

	/**
	 * http_request_args filter: adds the Gitea token to requests aimed at the
	 * configured Gitea host only. Scoped by host so no credentials leak to any
	 * other endpoint.
	 *
	 * @param  array<string,mixed> $args HTTP request args.
	 * @param  string              $url  Request URL.
	 * @return array<string,mixed>
	 */
	public static function add_auth_header( $args, $url ) {
		if ( '' === self::$auth_host ) {
			return $args;
		}
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || strtolower( $host ) !== self::$auth_host ) {
			return $args;
		}
		$token = self::token();
		if ( '' === $token ) {
			return $args;
		}
		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		$args['headers']['Authorization'] = 'token ' . $token;

		return $args;
	}
}
