<?php
/**
 * Calendar arithmetic for native subscriptions, in UTC, with no drift.
 *
 * Every due date is computed from the anchor - the instant the first payment settled - and the
 * cycle number, never from the previous due date. "Add a month" twelve times is not "add a year":
 * Jan 31 + 1 month is Feb 28, and Feb 28 + 1 month is Mar 28, and the customer who signed up on the
 * 31st drifts to the 28th forever. Counting from the anchor keeps them on the 31st, clamped only in
 * the months that do not have one.
 *
 * UTC throughout, so daylight-saving changes can never make a calendar day 23 hours long and land a
 * renewal an hour inside the on-chain period that was already collected.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Due-date arithmetic.
 */
class P2Flux_WC_Calendar {

	/** The intervals a native subscription may have. */
	const INTERVALS = array( 'day', 'week', 'month', 'year' );

	/**
	 * The n-th due instant after the anchor.
	 *
	 * @param int    $anchor   Unix seconds (UTC) of the anchor.
	 * @param string $interval 'day' | 'week' | 'month' | 'year'.
	 * @param int    $n        Cycle number, 0 = the anchor itself.
	 * @return int Unix seconds.
	 */
	public static function due( $anchor, $interval, $n ) {
		$anchor = (int) $anchor;
		$n      = (int) $n;

		switch ( $interval ) {
			case 'day':
				return $anchor + $n * DAY_IN_SECONDS;
			case 'week':
				return $anchor + $n * WEEK_IN_SECONDS;
			case 'month':
				return self::add_months( $anchor, $n );
			case 'year':
				return self::add_months( $anchor, 12 * $n );
		}

		return $anchor;
	}

	/**
	 * The largest cycle number whose due instant is at or before a moment.
	 *
	 * Exact, not searched: days and weeks by division, months and years by counting calendar months
	 * and stepping back once when the clamped day lands after the moment.
	 *
	 * @param int    $anchor   Anchor.
	 * @param string $interval Interval.
	 * @param int    $now      The moment.
	 * @return int Cycle number, 0 when the anchor is the only due instant so far; -1 before the anchor.
	 */
	public static function latest_cycle_at( $anchor, $interval, $now ) {
		$anchor = (int) $anchor;
		$now    = (int) $now;

		if ( $now < $anchor ) {
			return -1;
		}

		switch ( $interval ) {
			case 'day':
				return intdiv( $now - $anchor, DAY_IN_SECONDS );
			case 'week':
				return intdiv( $now - $anchor, WEEK_IN_SECONDS );
			case 'month':
			case 'year':
				$step   = 'month' === $interval ? 1 : 12;
				$months = self::months_between( $anchor, $now );
				$n      = intdiv( $months, $step );
				while ( $n > 0 && self::due( $anchor, $interval, $n ) > $now ) {
					$n--;
				}
				// The month count can be one short when the day-of-month clamps forward.
				while ( self::due( $anchor, $interval, $n + 1 ) <= $now ) {
					$n++;
				}

				return $n;
		}

		return 0;
	}

	/**
	 * Seconds in one contract period for an interval: the duplicate-charge gate, never the calendar.
	 *
	 * A month is 28 days and a year 365 - never longer than the calendar interval, so every calendar
	 * renewal lands in a fresh on-chain period (at worst skipping one, which costs nothing).
	 *
	 * @param string $interval Interval.
	 * @return int|null
	 */
	public static function contract_period( $interval ) {
		return P2Flux_WC_Money::period_seconds( $interval, 1 );
	}

	/**
	 * Add whole months to an instant, keeping the time of day and clamping the day of month.
	 *
	 * @param int $anchor Anchor.
	 * @param int $months Months to add.
	 * @return int
	 */
	private static function add_months( $anchor, $months ) {
		$year  = (int) gmdate( 'Y', $anchor );
		$month = (int) gmdate( 'n', $anchor );
		$day   = (int) gmdate( 'j', $anchor );
		$secs  = $anchor - gmmktime( 0, 0, 0, $month, $day, $year );

		$total = ( $year * 12 + ( $month - 1 ) ) + (int) $months;
		$ty    = intdiv( $total, 12 );
		$tm    = ( $total % 12 ) + 1;
		$tday  = min( $day, (int) gmdate( 't', gmmktime( 0, 0, 0, $tm, 1, $ty ) ) );

		return gmmktime( 0, 0, 0, $tm, $tday, $ty ) + $secs;
	}

	/**
	 * Whole calendar months from one instant to another (floor).
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return int
	 */
	private static function months_between( $from, $to ) {
		$a = (int) gmdate( 'Y', $from ) * 12 + (int) gmdate( 'n', $from );
		$b = (int) gmdate( 'Y', $to ) * 12 + (int) gmdate( 'n', $to );

		return max( 0, $b - $a );
	}
}
