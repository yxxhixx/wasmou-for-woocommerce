<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
delete_option( 'wasmou_wc_settings' );
delete_option( 'wasmou_wc_catalog_cache' );
delete_option( 'wasmou_wc_last_sync' );
delete_transient( 'wasmou_wc_wallet' );
delete_transient( 'wasmou_wc_snapshot' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wasmou\_wc\_lock\_%' OR option_name LIKE '\_transient\_wasmou\_wc\_%' OR option_name LIKE '\_transient\_timeout\_wasmou\_wc\_%'" ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'wasmou_wc_sync' );
wp_clear_scheduled_hook( 'wasmou_wc_watch' );
wp_unschedule_hook( 'wasmou_wc_poll_order' ); // per-order status checks
// Imported products and delivered codes on orders are kept: they belong to the shop owner.
