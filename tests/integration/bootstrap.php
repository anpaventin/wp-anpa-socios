<?php
/**
 * Integration test bootstrap for the fase35 email queue (runs ONLY in an
 * ephemeral, isolated MySQL/MariaDB test environment — never production).
 *
 * Hard production guards run BEFORE WordPress is loaded and abort immediately
 * if anything looks like a real/production target:
 *   - the DB name must contain "test" or "integration";
 *   - the table prefix must be the integration prefix;
 *   - host / DB / site URL must not match known production markers;
 *   - after WP loads, the DB must contain only WP core tables (plus our prefix).
 *
 * It then loads the WordPress test library, activates the plugin and defines
 * ANPA_SOCIOS_IT_DB so Test_ANPA_Socios_Email_Migration_Integration stops
 * self-skipping.
 *
 * @package ANPA_Socios
 */

declare(strict_types=1);

// ── Environment (provided by CI / the local ephemeral harness) ──────────────
$it_db     = getenv( 'WP_TESTS_DB_NAME' ) ?: '';
$it_host   = getenv( 'WP_TESTS_DB_HOST' ) ?: '';
$it_prefix = getenv( 'WP_TESTS_TABLE_PREFIX' ) ?: 'wpint_';
$it_url    = getenv( 'WP_TESTS_DOMAIN' ) ?: 'example.org';
$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

// Forbidden production markers (host/DB/URL must NOT contain any of these).
$forbidden = array( 'ionos', 'casabetty', 'mywire', 'anpaventin', 'migrationwp', 'prod', 'produc' );

function anpa_it_abort( string $why ): void {
	fwrite( STDERR, "[integration-guard] ABORT: {$why}\n" );
	exit( 1 );
}

$db_lc = strtolower( $it_db );
if ( '' === $db_lc || ( false === strpos( $db_lc, 'test' ) && false === strpos( $db_lc, 'integration' ) ) ) {
	anpa_it_abort( "DB name must contain 'test' or 'integration' (got: '{$it_db}')." );
}
if ( 0 !== strpos( $it_prefix, 'wpint_' ) ) {
	anpa_it_abort( "Table prefix must start with 'wpint_' (got: '{$it_prefix}')." );
}
foreach ( $forbidden as $needle ) {
	foreach ( array( $it_host, $it_db, $it_url ) as $hay ) {
		if ( '' !== $hay && false !== stripos( $hay, $needle ) ) {
			anpa_it_abort( "Production marker '{$needle}' detected in host/DB/URL — refusing to run." );
		}
	}
}

// ── Load the WordPress test library ─────────────────────────────────────────
// Yoast PHPUnit-Polyfills (required by the WordPress test suite). Installed by
// the harness in the ephemeral copy; the path is passed via env.
$polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ?: '';
if ( '' !== $polyfills && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills );
}

// Composer autoload of the ephemeral copy (PHPUnit + polyfills).
$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

$functions = $tests_dir . '/includes/functions.php';
if ( ! is_readable( $functions ) ) {
	anpa_it_abort( "WordPress test library not found at {$tests_dir} (run install-wp-tests.sh)." );
}
require_once $functions;

// Load our plugin inside the test WordPress.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/anpa-socios.php';
	}
);

// Test-support classes that describe the oracle (not shipped plugin logic).
require_once dirname( __DIR__ ) . '/class-anpa-socios-golden-manifest.php';

// Signal to the tests that a real integration DB is available.
if ( ! defined( 'ANPA_SOCIOS_IT_DB' ) ) {
	define( 'ANPA_SOCIOS_IT_DB', true );
}

// Force a WordPress timezone DIFFERENT from PHP's and from the DB session, so
// the UTC storage/compare convention is exercised (not just assumed).
$wp_tz = getenv( 'WP_TZ' ) ?: 'Pacific/Auckland';
tests_add_filter(
	'init',
	static function () use ( $wp_tz ) {
		update_option( 'timezone_string', $wp_tz );
	}
);

require $tests_dir . '/includes/bootstrap.php';

// ── Post-load guard: the DB must contain only WP core tables (+ our prefix) ──
global $wpdb;
$allowed_core = array(
	'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
	'term_relationships', 'term_taxonomy', 'termmeta', 'terms',
	'usermeta', 'users',
);
$prefix   = $wpdb->prefix;
$tables   = $wpdb->get_col( 'SHOW TABLES' );
foreach ( (array) $tables as $t ) {
	if ( 0 !== strpos( (string) $t, $prefix ) ) {
		anpa_it_abort( "Unexpected table without the test prefix: {$t}" );
	}
	$bare = substr( (string) $t, strlen( $prefix ) );
	// Allow WP core tables and any anpa_* plugin tables (created by our migration).
	if ( in_array( $bare, $allowed_core, true ) ) {
		continue;
	}
	if ( 0 === strpos( $bare, 'anpa_' ) ) {
		continue;
	}
	anpa_it_abort( "Unexpected non-harness table present before tests: {$t}" );
}

fwrite( STDOUT, "[integration] WordPress test env ready on {$prefix}* (DB {$it_db}).\n" );
