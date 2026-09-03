<?php
/**
 * Plugin Name:       P2Flux for WooCommerce
 * Plugin URI:        https://p2flux.com/docs/
 * Description:       Accept USDC on Base directly to your own wallet, including subscriptions. Non-custodial: payments go from the customer's wallet to yours.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            P2Flux
 * Author URI:        https://p2flux.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       p2flux-for-woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'P2FLUX_WC_VERSION', '1.1.0' );
define( 'P2FLUX_WC_FILE', __FILE__ );

/**
 * Load the plugin's own classes and the vendored SDK.
 *
 * The SDK lives under a plugin-specific namespace: another plugin on this site may bundle its own
 * copy, and without the rename whichever loaded first would win silently - a version skew nobody can
 * see, in the code that moves money.
 *
 * @return void
 */
function p2flux_wc_load() {
	$vendor = __DIR__ . '/includes/vendor/p2flux/';
	require_once $vendor . 'P2FluxException.php';
	require_once $vendor . 'ChargeResult.php';
	require_once $vendor . 'P2FluxClient.php';

	foreach (
		array(
			'money',
			'crypto',
			'logger',
			'client',
			'rates',
			'lock',
			'periods',
			'subscriptions',
			'calendar',
			'native-store',
			'native-subscription',
			'native-scheduler',
			'native-product',
			'native-emails',
			'native-account',
			'native-admin',
			'native-privacy',
			'collection',
			'auth-history',
			'intents',
			'renewal',
			'charger',
			'payments',
			'activation',
			'refunds',
			'jobs',
			'ajax',
			'checkout-page',
			'gateway',
			'admin',
			'account',
			'blocks',
			'lifecycle',
			'cli',
		) as $class
	) {
		require_once __DIR__ . '/includes/class-p2flux-wc-' . $class . '.php';
	}
}

/**
 * Declare what this plugin is compatible with, before WooCommerce decides.
 *
 * Both declarations are required rather than polite: without the HPOS one a store using the new
 * order tables hides the plugin, and without the Blocks one the gateway never appears in a block
 * checkout.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', P2FLUX_WC_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', P2FLUX_WC_FILE, true );
	}
);

/**
 * Start up, once WooCommerce itself has.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		p2flux_wc_load();

		P2Flux_WC_Periods::install();
		/* The block checkout asks the cart whether this subscription can be paid, so the answer has to
		 * be registered on the Store API - and registered here, where the classes exist, rather than
		 * on `woocommerce_blocks_loaded`, which has already fired by now. */
		P2Flux_WC_Blocks::register_cart_data();
		P2Flux_WC_Ajax::init();
		P2Flux_WC_Native_Product::init();
		P2Flux_WC_Native_Emails::init();
		P2Flux_WC_Native_Account::init();
		P2Flux_WC_Native_Admin::init();
		P2Flux_WC_Native_Privacy::init();
		P2Flux_WC_Admin::init();
		P2Flux_WC_Account::init();
		P2Flux_WC_Lifecycle::init();

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'P2Flux_WC_Gateway';

				return $gateways;
			}
		);
	},
	20
);

// Action Scheduler is ready by `init`, and so are the hooks its jobs fire.
add_action(
	'init',
	static function () {
		if ( class_exists( 'P2Flux_WC_Jobs' ) ) {
			P2Flux_WC_Jobs::init();
		}
		if ( class_exists( 'P2Flux_WC_Native_Scheduler' ) ) {
			P2Flux_WC_Native_Scheduler::init();
		}
	}
);

add_action(
	'woocommerce_blocks_payment_method_type_registration',
	static function ( $registry ) {
		if ( class_exists( 'P2Flux_WC_Blocks' ) ) {
			$registry->register( new P2Flux_WC_Blocks() );
		}
	}
);

/**
 * Create the period-ownership table on activation.
 *
 * It is created on load too, because a plugin updated by file copy never fires an activation hook -
 * but doing it here as well means a fresh install has it before the first request that needs it.
 */
register_activation_hook(
	__FILE__,
	static function () {
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			p2flux_wc_load();
			P2Flux_WC_Periods::install();
			// Schedules that lost their job while the plugin was inactive; at most one charge each.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				P2Flux_WC_Native_Scheduler::sweep();
			}
		}
	}
);
