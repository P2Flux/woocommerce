<?php
/**
 * Plugin Name: P2Flux test fixture (development only)
 *
 * Shortens billing periods so a developer can watch two real renewals in two minutes on Base
 * Sepolia instead of waiting a day. The API allows 60-second periods on Sepolia and nowhere else,
 * and refuses them outright on mainnet.
 *
 * Three guards, because this file changes what customers are charged and when:
 *
 *   it lives in dev/ and is excluded from the plugin zip by .distignore
 *   it does nothing unless P2FLUX_WC_DEV_SHORT_PERIODS is defined
 *   it does nothing unless the gateway is in test mode
 *
 * dev/release-check.sh fails the build if it ever appears in a package.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'P2FLUX_WC_DEV_SHORT_PERIODS', true );

add_filter(
	'p2flux_wc_period_seconds',
	static function ( $seconds ) {
		if ( ! defined( 'P2FLUX_WC_DEV_SHORT_PERIODS' ) || ! P2FLUX_WC_DEV_SHORT_PERIODS ) {
			return $seconds;
		}
		if ( ! class_exists( 'P2Flux_WC_Client' ) || P2Flux_WC_Client::TEST !== P2Flux_WC_Client::current_environment() ) {
			return $seconds;
		}

		return 60;
	}
);
