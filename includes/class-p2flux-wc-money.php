<?php
/**
 * Money, in integers only.
 *
 * Every amount here is micro-USDC: an integer count of the smallest unit the token has. Floats are
 * not used at any point, including the currency conversion, because `0.1 + 0.2` is the reason this
 * file exists. A store priced in euros converts once, at checkout, and the result is what the
 * customer's wallet signs; from then on the signed number is the only truth and this class never
 * re-derives it.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decimal arithmetic and the bounds the protocol enforces.
 */
class P2Flux_WC_Money {

	/** USDC has six decimals, on every chain P2Flux settles on. */
	const SCALE = 6;

	/** Smallest one-time payment the API will mint an intent for: 0.01 USDC. */
	const MIN_ONE_TIME_UNITS = 10000;

	/**
	 * Smallest recurring amount the CONTRACT accepts.
	 *
	 * Below this the 2% fee plus the fixed 0.10 network fee would leave the seller nothing, and
	 * `charge()` reverts with AmountTooSmall - so the gateway refuses the sale rather than selling a
	 * subscription that can never collect.
	 */
	const MIN_RECURRING_UNITS = 102041;

	/** The API's ceiling: 10,000 USDC per payment or per period. */
	const MAX_UNITS = 10000000000;

	/**
	 * Seconds in a WooCommerce billing period.
	 *
	 * A month is 28 days and a year 365, deliberately shorter than the calendar interval Woo will
	 * actually use. The contract's periods are fixed-length, so an authorization whose period is
	 * NEVER longer than Woo's interval guarantees each renewal lands in a fresh period - at worst
	 * skipping one, which costs nothing because there is no catch-up billing. The reverse - a
	 * contract period longer than Woo's interval - would make renewals arrive early and answer
	 * NOT_DUE, which is a subscription that silently stops collecting.
	 *
	 * @var array<string,int>
	 */
	const PERIOD_SECONDS = array(
		'day'   => DAY_IN_SECONDS,
		'week'  => WEEK_IN_SECONDS,
		'month' => 28 * DAY_IN_SECONDS,
		'year'  => 365 * DAY_IN_SECONDS,
	);

	/** The protocol's own bounds on a period, in seconds. */
	const MIN_PERIOD = HOUR_IN_SECONDS;
	const MAX_PERIOD = 366 * DAY_IN_SECONDS;

	/**
	 * Is this PHP build able to do the arithmetic below safely?
	 *
	 * The intermediate in `to_units()` reaches 1e18, which is comfortable on 64-bit and impossible
	 * on 32-bit. Rather than silently overflowing into a wrong amount, the gateway makes itself
	 * unavailable and says why.
	 *
	 * @return bool
	 */
	public static function supported_platform() {
		return PHP_INT_SIZE >= 8;
	}

	/**
	 * Parse a decimal string into integer micro-units.
	 *
	 * Accepts what a shop realistically produces - '12', '12.5', '12.990000' - and refuses anything
	 * with more precision than USDC can hold rather than rounding money away silently.
	 *
	 * @param string|float|int $decimal Amount as a decimal.
	 * @param int              $scale   Decimal places to scale to.
	 * @return int|null Integer units, or null when the input is not a usable amount.
	 */
	public static function to_scaled( $decimal, $scale = self::SCALE ) {
		$text = trim( (string) $decimal );
		if ( '' === $text ) {
			return null;
		}
		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $text ) ) {
			return null;
		}

		$negative = str_starts_with( $text, '-' );
		$text     = ltrim( $text, '-' );
		$parts    = explode( '.', $text, 2 );
		$whole    = $parts[0];
		$fraction = isset( $parts[1] ) ? $parts[1] : '';

		if ( strlen( $fraction ) > $scale ) {
			// Trailing zeros are not extra precision; anything else is money we would be inventing.
			if ( '' !== rtrim( substr( $fraction, $scale ), '0' ) ) {
				return null;
			}
			$fraction = substr( $fraction, 0, $scale );
		}

		$fraction = str_pad( $fraction, $scale, '0' );
		$digits   = ltrim( $whole . $fraction, '0' );
		if ( '' === $digits ) {
			return 0;
		}
		// 19 digits is where a 64-bit integer stops being able to hold the answer.
		if ( strlen( $digits ) > 18 ) {
			return null;
		}

		$units = (int) $digits;
		return $negative ? -$units : $units;
	}

	/**
	 * Format integer micro-units back to the decimal string the API expects.
	 *
	 * @param int $units Micro-USDC.
	 * @return string
	 */
	public static function format( $units ) {
		$units    = (int) $units;
		$negative = $units < 0;
		$units    = abs( $units );
		$whole    = intdiv( $units, 1000000 );
		$fraction = str_pad( (string) ( $units % 1000000 ), self::SCALE, '0', STR_PAD_LEFT );

		return ( $negative ? '-' : '' ) . $whole . '.' . $fraction;
	}

	/**
	 * Convert a store-currency amount to micro-USDC at a given rate.
	 *
	 * `$rate` is how many units of the store's currency one USDC costs, as a decimal string - so USD
	 * is '1' and a euro store might be '0.92'. Both sides are scaled to integers before dividing,
	 * and the division rounds half up on the numerator rather than on a float.
	 *
	 * @param string|float $amount Store-currency amount.
	 * @param string       $rate   Store currency per 1 USDC.
	 * @return int|null Micro-USDC, or null when either input is unusable.
	 */
	public static function to_units( $amount, $rate ) {
		$amount_scaled = self::to_scaled( $amount );
		$rate_scaled   = self::to_scaled( $rate );

		if ( null === $amount_scaled || null === $rate_scaled ) {
			return null;
		}
		if ( $amount_scaled < 0 || $rate_scaled <= 0 ) {
			return null;
		}

		// USD, or any 1:1 rate: no division at all, so no rounding to reason about.
		if ( 1000000 === $rate_scaled ) {
			return $amount_scaled;
		}

		/*
		 * units = amount / rate, both already scaled by 1e6, so the scale cancels and has to be put
		 * back: (amount * 1e6) / rate. The numerator peaks at 1e12 * 1e6 = 1e18, which fits in a
		 * signed 64-bit integer with room to spare - and does not fit in a 32-bit one, which is why
		 * `supported_platform()` exists.
		 */
		$numerator = $amount_scaled * 1000000;
		if ( $numerator < 0 ) {
			return null;
		}

		return intdiv( 2 * $numerator + $rate_scaled, 2 * $rate_scaled );
	}

	/**
	 * The contract period for a WooCommerce billing interval.
	 *
	 * @param string $period   'day' | 'week' | 'month' | 'year'.
	 * @param int    $interval How many of them.
	 * @return int|null Seconds, or null when Woo names something this gateway cannot express.
	 */
	public static function period_seconds( $period, $interval = 1 ) {
		$interval = (int) $interval;
		if ( $interval < 1 || ! isset( self::PERIOD_SECONDS[ $period ] ) ) {
			return null;
		}

		$seconds = self::PERIOD_SECONDS[ $period ] * $interval;
		if ( $seconds < self::MIN_PERIOD || $seconds > self::MAX_PERIOD ) {
			return null;
		}

		return $seconds;
	}

	/**
	 * Is this amount sellable through P2Flux?
	 *
	 * @param int  $units     Micro-USDC.
	 * @param bool $recurring Whether this is a subscription amount.
	 * @return true|string True, or a reason code: 'too_small' | 'too_large'.
	 */
	public static function check_bounds( $units, $recurring = false ) {
		$minimum = $recurring ? self::MIN_RECURRING_UNITS : self::MIN_ONE_TIME_UNITS;

		if ( $units < $minimum ) {
			return 'too_small';
		}
		if ( $units > self::MAX_UNITS ) {
			return 'too_large';
		}

		return true;
	}
}
