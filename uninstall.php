<?php
/**
 * Uninstalling: settings go, financial records stay.
 *
 * Order and subscription meta is deliberately left alone. It records real payments on a public
 * chain - which order a transaction settled, which authorization collected which period, what was
 * refunded - and a merchant who deactivates a plugin has not stopped needing their own accounts.
 * The period-ownership table and the native subscription table stay for the same reason, and the
 * first is what stops one on-chain payment ever being credited to two orders.
 *
 * A merchant who genuinely wants it all gone sets P2FLUX_WC_REMOVE_DATA and uninstalls again.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_p2flux_settings' );

global $wpdb;

// Cached exchange rates: derived data, worth nothing once the plugin is gone.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_p2flux_wc_rate_%' OR option_name LIKE '_transient_timeout_p2flux_wc_rate_%'" );
// Any lock left behind by a process that died mid-charge.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'p2flux_wc_lock_%'" );

if ( defined( 'P2FLUX_WC_REMOVE_DATA' ) && P2FLUX_WC_REMOVE_DATA ) {
	// The encryption key is destroyed here and nowhere else: without it every stored authorization
	// becomes permanently unreadable, which is exactly what this flag is asking for.
	delete_option( 'p2flux_wc_key' );
	delete_option( 'p2flux_wc_db_version' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}p2flux_wc_periods" );
	// Native subscriptions: the same financial history, the same flag, the same fate. Their
	// scheduled renewals go with them; a job for a subscription that no longer exists is noise.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}p2flux_wc_subscriptions" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'p2flux_wc_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'p2flux_wc_%'" );
}
