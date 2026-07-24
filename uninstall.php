<?php
/**
 * Uninstall cleanup for ANPA Socios.
 *
 * Runs ONLY when the plugin is deleted from WordPress (not on deactivate).
 * Removes every piece of data the plugin owns so a delete leaves no residue:
 *   - all custom tables  (wp_anpa_*)
 *   - all plugin options (anpa_*), including the schema-version marker
 *   - plugin transients  (anpa_*)
 *   - any scheduled cron events whose hook starts with "anpa"
 *
 * It intentionally does NOT rely on the plugin's classes: WordPress loads this
 * file in isolation, so everything is done directly with $wpdb + core helpers.
 *
 * COMMUNICATIONS EXCEPTION (fase35): the email communications tables
 * (wp_anpa_email_campaigns / _recipients / _attempts) are PRESERVED by default,
 * because campaign and attempt records may be needed to diagnose incidents and
 * to evidence administrative actions. They are removed ONLY when an admin
 * explicitly enabled the option `anpa_socios_delete_comms_on_uninstall` (value
 * exactly "1"). The option scope is COMMUNICATIONS ONLY — it is deliberately NOT
 * named "delete all data", since the rest of the plugin's tables follow the
 * existing destructive-by-default uninstall (take a backup first).
 *
 * DEFENSIVE: absent option → preserve; unexpected value → preserve; only the
 * exact authorized value "1" deletes the communications tables.
 *
 * MULTISITE: the plugin is designed for PER-SITE installation. On multisite this
 * cleanup runs per site (switch_to_blog loop below) and only ever touches the
 * CURRENT site's own tables and options; the delete-comms option is read in each
 * site's own context. It performs no cross-site/network-wide deletion beyond the
 * standard per-site uninstall that WordPress itself drives.
 *
 * WARNING: this is destructive by design (except the communications tables noted
 * above). Socios, fillos, matrículas, banking data, activities, groups and
 * school structure are removed. Take a backup (Axustes → Copias) first.
 *
 * @package ANPA_Socios
 */

// Guard: only run in the genuine WordPress uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes all ANPA data from a single site (tables, options, transients, cron).
 *
 * @return void
 */
function anpa_socios_uninstall_cleanup() {
	global $wpdb;

	// fase35: the email communications tables (campaigns/recipients/attempts) are
	// PRESERVED by default on uninstall — they may be needed to diagnose incidents
	// and to evidence administrative actions. They are removed ONLY when an admin
	// explicitly set `anpa_socios_delete_comms_on_uninstall` to exactly "1"
	// (COMMUNICATIONS-ONLY scope). Defensive: any other value, or its absence,
	// preserves. Read the flag BEFORE deleting options below.
	$delete_comms = ( '1' === (string) get_option( 'anpa_socios_delete_comms_on_uninstall', '0' ) );
	$preserve     = $delete_comms ? array() : array(
		$wpdb->prefix . 'anpa_email_campaigns',
		$wpdb->prefix . 'anpa_email_recipients',
		$wpdb->prefix . 'anpa_email_attempts',
	);

	// 1. Drop every custom table owned by the plugin (wp_anpa_*), except the
	//    communications tables when they must be preserved. Table names come from
	//    SHOW TABLES (never user input), so they are safe to inline.
	$like   = $wpdb->esc_like( $wpdb->prefix . 'anpa_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	if ( is_array( $tables ) ) {
		foreach ( $tables as $table ) {
			if ( in_array( $table, $preserve, true ) ) {
				continue; // Keep communications data unless explicit delete-all.
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' );
		}
	}

	// 2. Delete all plugin options (anpa_*), including anpa_socios_db_version
	//    and the banking key options.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'anpa_' ) . '%'
		)
	);

	// 3. Delete plugin transients (value + timeout rows).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_anpa_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_anpa_' ) . '%'
		)
	);

	// 4. Unschedule any cron event whose hook starts with "anpa".
	if ( function_exists( '_get_cron_array' ) ) {
		$crons = _get_cron_array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( array_keys( $events ) as $hook ) {
					if ( is_string( $hook ) && 0 === strpos( $hook, 'anpa' ) ) {
						wp_clear_scheduled_hook( $hook );
					}
				}
			}
		}
	}

	// Object cache may hold stale option groups.
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

// Multisite: clean each site; single site: clean the current one.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		anpa_socios_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	anpa_socios_uninstall_cleanup();
}
