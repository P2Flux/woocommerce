<?php
/**
 * Whether a one-time payment can be offered with the network fee paid in USDC.
 *
 * A sponsored intent can only be paid the sponsored way - the mode is sealed into the intent when it
 * is created - so the plugin must know BEFORE it mints whether P2Flux supports it on this network.
 * `/v1/capabilities` answers that for the deployment. It says nothing about the relayer's health right
 * now; a create that is refused for that reason falls back to native (see P2Flux_WC_Payments).
 *
 * Everything here fails closed: an unreachable API, an answer in a shape this code does not recognise,
 * or an amount too small to carry the fixed fee all mean a native intent - the checkout that has
 * always worked - never a broken one.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sponsored-checkout availability.
 */
class P2Flux_WC_Sponsorship {

	/** Ledger mode of an intent whose network fee the buyer pays in ETH. Also: no mode recorded. */
	const NATIVE = 'native';
	/** Ledger mode of an intent whose network fee the buyer pays in USDC. */
	const SPONSORED = 'sponsored';

	/** How long a capabilities answer is trusted. */
	const TTL_OK   = HOUR_IN_SECONDS;
	const TTL_FAIL = 5 * MINUTE_IN_SECONDS;

	/** A checkout request also has to create the intent afterwards, inside the same PHP time limit. */
	const TIMEOUT = 5;

	/**
	 * Did the merchant switch it on? A settings array without the key - an install upgraded from
	 * 1.0.0 that has not been re-saved - is off.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = get_option( 'woocommerce_p2flux_settings', array() );

		return is_array( $settings ) && isset( $settings['sponsored'] ) && 'yes' === $settings['sponsored'];
	}

	/**
	 * Transient holding one environment's capabilities answer. Uninstall deletes it by this name.
	 *
	 * @param string $environment P2Flux_WC_Client::TEST | LIVE.
	 * @return string
	 */
	public static function cache_key( $environment ) {
		return 'p2flux_wc_caps_' . ( P2Flux_WC_Client::LIVE === $environment ? P2Flux_WC_Client::LIVE : P2Flux_WC_Client::TEST );
	}

	/**
	 * The fixed network fee, in micro-USDC, when sponsored one-time payments are supported on this
	 * environment - or null when they are not, or nobody can tell.
	 *
	 * @param string $environment P2Flux_WC_Client::TEST | LIVE.
	 * @return int|null
	 */
	public static function fixed_fee( $environment ) {
		$key    = self::cache_key( $environment );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return isset( $cached['fixed'] ) ? (int) $cached['fixed'] : null;
		}

		$fixed = null;
		try {
			$fixed = self::parse( P2Flux_WC_Client::for_environment( $environment, self::TIMEOUT )->capabilities() );
		} catch ( \Exception $e ) {
			P2Flux_WC_Logger::log( 'capabilities unavailable', array( 'error' => $e->getMessage() ) );
		}

		if ( null === $fixed ) {
			set_transient( $key, array(), self::TTL_FAIL );
		} else {
			set_transient( $key, array( 'fixed' => $fixed ), self::TTL_OK );
		}

		return $fixed;
	}

	/**
	 * Treat sponsorship as unavailable for a while, after the API refused a sponsored create. The
	 * cache is overwritten rather than deleted: deleting it would make the very next checkout ask
	 * again.
	 *
	 * @param string $environment P2Flux_WC_Client::TEST | LIVE.
	 * @return void
	 */
	public static function mark_unavailable( $environment ) {
		set_transient( self::cache_key( $environment ), array(), self::TTL_FAIL );
	}

	/**
	 * Can this amount be minted as a sponsored intent right now?
	 *
	 * The floor is checked here rather than trusted from the API: both the 1% fee and the fixed fee
	 * come out of the amount, so an amount that cannot cover them leaves the merchant nothing and is
	 * refused. With the fixed fee at 0.10 USDC, 0.101011 is the smallest sponsorable amount.
	 *
	 * @param string $environment P2Flux_WC_Client::TEST | LIVE.
	 * @param int    $units       Amount in micro-USDC.
	 * @return bool
	 */
	public static function allowed( $environment, $units ) {
		if ( ! self::enabled() ) {
			return false;
		}
		$fixed = self::fixed_fee( $environment );

		return null !== $fixed && self::covers( (int) $units, $fixed );
	}

	/**
	 * Does an amount leave the merchant something after the 1% fee and the fixed network fee?
	 *
	 * @param int $units Amount in micro-USDC.
	 * @param int $fixed Fixed network fee in micro-USDC.
	 * @return bool
	 */
	public static function covers( $units, $fixed ) {
		return $units > intdiv( $units, 100 ) + $fixed;
	}

	/**
	 * The fixed fee from a capabilities answer, or null unless it plainly says sponsored one-time
	 * USDC payments are supported. Keys this code does not know are ignored.
	 *
	 * @param mixed $caps Decoded /v1/capabilities body.
	 * @return int|null
	 */
	private static function parse( $caps ) {
		if ( ! is_array( $caps ) || true !== ( $caps['supported'] ?? null ) || ! isset( $caps['tokens'] ) || ! is_array( $caps['tokens'] ) ) {
			return null;
		}

		foreach ( $caps['tokens'] as $token ) {
			if ( ! is_array( $token )
				|| 'USDC' !== ( $token['symbol'] ?? null )
				|| ! is_array( $token['gas_payment_modes'] ?? null )
				|| ! in_array( 'payment_token', $token['gas_payment_modes'], true )
				|| true !== ( $token['operations']['one_time_payment'] ?? null )
				|| ! is_string( $token['fixed_network_fee_units'] ?? null )
				|| ! ctype_digit( $token['fixed_network_fee_units'] ) ) {
				continue;
			}

			return (int) $token['fixed_network_fee_units'];
		}

		return null;
	}
}
