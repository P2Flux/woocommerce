<?php
/**
 * Test harness only - never ship. Cancels the WooCommerce subscription in the middle of a charge:
 * after the plugin has decided to charge and before the request reaches P2Flux. The charge still
 * goes out for real. What the test checks is what the plugin does with a CHARGED answer for a
 * subscription that was cancelled while the request was in flight.
 *
 * Install into wp-content/mu-plugins/ with P2FLUX_RACE_SUBSCRIPTION defined; remove afterwards.
 */
defined( 'ABSPATH' ) || exit;

add_filter( 'p2flux_wc_transport', static function () {
	return static function ( $url, $payload, $timeout ) {
		if ( false !== strpos( $url, '/v1/charges' ) && false === strpos( $url, '/recover' ) && defined( 'P2FLUX_RACE_SUBSCRIPTION' ) ) {
			$subscription = wcs_get_subscription( P2FLUX_RACE_SUBSCRIPTION );
			if ( $subscription && ! $subscription->has_status( 'cancelled' ) ) {
				$subscription->update_status( 'cancelled', 'Cancelled by the race harness while a charge was in flight.' );
				file_put_contents( WP_CONTENT_DIR . '/p2flux-race.log', gmdate( 'c' ) . " cancelled during charge\n", FILE_APPEND );
			}
		}
		// Forward to the real transport, with this override out of the way for that one call.
		remove_all_filters( 'p2flux_wc_transport' );
		$real = P2Flux_WC_Client::transport();
		return $real( $url, $payload, $timeout );
	};
} );
