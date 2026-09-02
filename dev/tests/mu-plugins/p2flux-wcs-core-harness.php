<?php
/**
 * Plugin Name: P2Flux — WooCommerce Subscriptions Core test harness (development only)
 *
 * Boots Automattic's public `woocommerce-subscriptions-core` library so the recurring integration can be
 * exercised on a controlled staging site without the commercial WooCommerce Subscriptions plugin. Interim
 * target only: passing here does not prove compatibility with the current commercial release, which must
 * still be validated before public release.
 *
 * What the core library lacks, and this harness supplies (each a replica of commercial behaviour, none a
 * change to the P2Flux plugin):
 *
 *   1. It never fires `woocommerce_scheduled_subscription_payment_{gateway}`. That bridge lives in the
 *      commercial plugin's `WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment`.
 *   2. It treats every gateway except WooCommerce Payments as manual renewal (`is_manual()` hard-coded),
 *      and strips every other gateway from a subscription checkout.
 *   3. It considers an unrecognised site URL a "duplicate site" until an admin visits, which also forces
 *      manual renewal and suppresses emails.
 *
 * Install by hand: the core checkout at wp-content/wcs-core (tag 8.2.0), this file in wp-content/mu-plugins.
 * Never in the plugin package - .distignore excludes dev/ and the release check refuses it.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'P2FLUX_WCS_CORE_DIR', WP_CONTENT_DIR . '/wcs-core' );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// The commercial plugin and WooCommerce Payments each bundle their own copy: loading a second one
		// is a fatal redeclaration, so this harness steps aside whenever either is present.
		if ( class_exists( 'WC_Subscriptions_Core_Plugin' ) || class_exists( 'WC_Subscriptions' ) ) {
			return;
		}
		if ( ! file_exists( P2FLUX_WCS_CORE_DIR . '/includes/class-wc-subscriptions-core-plugin.php' ) ) {
			return;
		}

		require_once P2FLUX_WCS_CORE_DIR . '/includes/class-wc-subscriptions-core-plugin.php';
		new WC_Subscriptions_Core_Plugin();
	},
	/*
	 * Before the default priority, not after it. The core plugin hooks its own second stage
	 * (`init_version_dependant_classes`: My Account endpoints, admin post types, meta boxes) onto
	 * `plugins_loaded` at priority 10 from its constructor - so constructing it at 11 registers a
	 * callback for a hook that has already fired, and the Subscriptions tab never appears.
	 * WooCommerce's classes exist from plugin load, so 5 is safe.
	 */
	5
);

/*
 * 1. The bridge. Core's `prepare_renewal` (priority 1) puts the subscription on hold and creates the renewal
 *    order; the commercial plugin then, at priority 10, hands that order to the gateway. Same shape here.
 */
add_action(
	'woocommerce_scheduled_subscription_payment',
	static function ( $subscription_id ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription || $subscription->is_manual() ) {
			return;
		}
		$order = $subscription->get_last_order( 'all', 'renewal' );
		if ( ! $order || ! $order->needs_payment() ) {
			return;
		}

		do_action( 'woocommerce_scheduled_subscription_payment_' . $order->get_payment_method(), $order->get_total(), $order );
	},
	10
);

// 2a. Automatic renewal for P2Flux, exactly as the commercial plugin decides it for any supporting gateway.
add_filter(
	'woocommerce_subscription_is_manual',
	static function ( $is_manual, $subscription ) {
		return 'p2flux' === $subscription->get_payment_method() ? false : $is_manual;
	},
	10,
	2
);

// 2b. Put P2Flux back into a subscription checkout after core's WooCommerce-Payments-only filter removed it.
add_filter(
	'woocommerce_available_payment_gateways',
	static function ( $gateways ) {
		if ( isset( $gateways['p2flux'] ) || is_admin() ) {
			return $gateways;
		}
		$all = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( isset( $all['p2flux'] ) && $all['p2flux']->is_available() ) {
			$gateways['p2flux'] = $all['p2flux'];
		}

		return $gateways;
	},
	20
);

// 3. This is the staging site, on purpose.
add_filter( 'woocommerce_subscriptions_is_duplicate_site', '__return_false' );
