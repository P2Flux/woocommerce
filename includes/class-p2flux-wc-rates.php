<?php
/**
 * What a store's currency is worth in USDC.
 *
 * Only used when the store is not priced in USD, and only for one-time payments - a recurring
 * authorization fixes one USDC amount for its whole life, so a subscription priced in another
 * currency would drift away from its own price and neither side would have agreed to the result.
 *
 * A missing rate is never guessed. The gateway simply becomes unavailable, which costs a sale;
 * inventing a number costs the merchant the difference on every order until someone notices.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exchange rates, cached.
 */
class P2Flux_WC_Rates {

	const ENDPOINT = 'https://api.coinbase.com/v2/exchange-rates?currency=USDC';
	const TTL      = HOUR_IN_SECONDS;

	/**
	 * Store currency per 1 USDC, as a decimal string.
	 *
	 * @param string $currency ISO code.
	 * @return string|null
	 */
	public static function fetch( $currency ) {
		$currency = strtoupper( $currency );
		if ( 'USD' === $currency || 'USDC' === $currency ) {
			return '1';
		}

		$key    = 'p2flux_wc_rate_' . $currency;
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( self::ENDPOINT, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			P2Flux_WC_Logger::log( 'exchange rate unavailable', array( 'currency' => $currency, 'error' => $response->get_error_message() ) );

			return null;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$rates = isset( $body['data']['rates'] ) ? $body['data']['rates'] : array();
		if ( ! isset( $rates[ $currency ] ) ) {
			return null;
		}

		// Six decimals, because that is all USDC has - and the conversion is integer arithmetic that
		// needs a bounded scale on both sides.
		$rate = self::round_to_six( (string) $rates[ $currency ] );
		if ( null === $rate ) {
			return null;
		}

		set_transient( $key, $rate, self::TTL );

		return $rate;
	}

	/**
	 * Round a decimal string to six places, without floats.
	 *
	 * @param string $value Rate.
	 * @return string|null
	 */
	private static function round_to_six( $value ) {
		$value = trim( $value );
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			return null;
		}

		$parts    = explode( '.', $value, 2 );
		$whole    = $parts[0];
		$fraction = isset( $parts[1] ) ? $parts[1] : '';

		if ( strlen( $fraction ) <= 6 ) {
			$rounded = $whole . '.' . str_pad( $fraction, 6, '0' );
		} else {
			$keep = substr( $fraction, 0, 6 );
			$next = (int) substr( $fraction, 6, 1 );
			$units = (int) ( $whole . $keep );
			if ( $next >= 5 ) {
				$units++;
			}
			$rounded = P2Flux_WC_Money::format( $units );
		}

		return ( 0 === (int) str_replace( array( '.', '0' ), '', $rounded ) ) ? null : $rounded;
	}
}
