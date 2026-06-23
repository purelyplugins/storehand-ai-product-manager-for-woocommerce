<?php
/**
 * Uninstall Pilot for WooCommerce — remove all plugin data.
 *
 * @package WPPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Options ───────────────────────────────────────────────────────────────────
$wppilot_options = [ 'wppilot_api_key' ];
foreach ( $wppilot_options as $wppilot_option ) {
	delete_option( $wppilot_option );
}

// ── Transients — session and pending data ─────────────────────────────────────
// WPPilot_Session uses keys prefixed with wppilot_session_ and wppilot_pending_.
// We delete them explicitly rather than relying on TTL expiry so no data is
// left behind for a user who uninstalls promptly after use.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		'_transient_wppilot_%',
		'_transient_timeout_wppilot_%'
	)
);
