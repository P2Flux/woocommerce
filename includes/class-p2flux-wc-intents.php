<?php
/**
 * One-time payment intents, and why the old ones are never thrown away.
 *
 * An intent is a signed instruction to pay an exact amount to an exact wallet. The hosted checkout
 * refuses to START a payment against an expired one, but the CONTRACT has no notion of expiry: a
 * transaction prepared while the intent was live can be broadcast later - much later - and it will
 * settle. If the plugin has forgotten that intent by then, the merchant sees an unexplained USDC
 * transfer and an order nobody can connect it to.
 *
 * So there are two lists. The active one is what background recovery polls, and it goes quiet after
 * a few days because polling forever is a cost with no payoff. The ledger is a compact record of
 * every intent this order ever had, kept for the life of the order, never polled - it exists so that
 * a payment arriving out of nowhere can still be identified.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Intent bookkeeping for one order.
 */
class P2Flux_WC_Intents {

	const ACTIVE_META = '_p2flux_intents';
	const LEDGER_META = '_p2flux_intent_ledger';

	/** The intent a customer is being sent to pay right now. */
	const ACTIVE = 'active';
	/** Superseded because the order's terms changed. Still payable on chain. */
	const REPLACED = 'replaced';
	/** Settled - this is the one that paid the order. */
	const SETTLED = 'settled';
	/** Past its expiry with nothing settled, and past the window we still poll. */
	const EXPIRED = 'expired';

	/** How long after expiry an intent stays in the polled set. */
	const RECOVERY_WINDOW = 7 * DAY_IN_SECONDS;

	/** How many intents one order may generate before something is clearly wrong. */
	const MAX_LEDGER = 50;

	/** How often a new intent may be minted for the same order. */
	const MINT_COOLDOWN = 300;

	/**
	 * Every intent this order has had, newest last.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( $order ) {
		$stored = $order->get_meta( self::LEDGER_META );
		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = json_decode( $stored, true );
		}

		return ( is_array( $stored ) && isset( $stored['items'] ) && is_array( $stored['items'] ) ) ? $stored['items'] : array();
	}

	/**
	 * The intent the customer should be paying now, or null.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>|null
	 */
	public static function active( $order ) {
		foreach ( array_reverse( self::all( $order ) ) as $item ) {
			if ( self::ACTIVE === $item['status'] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * The intents background recovery should still ask about.
	 *
	 * Settled ones are done. Ones long past expiry are dropped from the poll - not from the ledger -
	 * because the chance of a settlement a week later does not justify a recurring API call per
	 * order forever.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recoverable( $order ) {
		$now  = time();
		$open = array();

		foreach ( self::all( $order ) as $item ) {
			if ( self::SETTLED === $item['status'] ) {
				continue;
			}
			if ( (int) $item['expires'] + self::RECOVERY_WINDOW < $now ) {
				continue;
			}
			$open[] = $item;
		}

		return $open;
	}

	/**
	 * Can this order mint a new intent?
	 *
	 * Two limits, both about a loop rather than about a customer: a cooldown so a reloaded checkout
	 * page cannot mint one per refresh, and a ceiling so a genuinely stuck order stops rather than
	 * growing its own history without bound. Hitting the ceiling refuses the payment and tells the
	 * merchant - it never drops an older record, because that record may be the only thing that can
	 * explain a late transfer.
	 *
	 * @param WC_Order $order Order.
	 * @return true|string True, or 'cooldown' | 'ceiling'.
	 */
	public static function may_mint( $order ) {
		$items = self::all( $order );

		if ( count( $items ) >= self::MAX_LEDGER ) {
			return 'ceiling';
		}

		$last = end( $items );
		if ( $last && ( time() - (int) $last['created'] ) < self::MINT_COOLDOWN && self::ACTIVE === $last['status'] ) {
			return 'cooldown';
		}

		return true;
	}

	/**
	 * Record a newly minted intent, retiring whatever it replaces.
	 *
	 * @param WC_Order            $order  Order.
	 * @param array<string,mixed> $intent intent, reference, units, recipient, environment, created, expires.
	 * @return void
	 */
	public static function add( $order, array $intent ) {
		$items = self::all( $order );

		foreach ( $items as $index => $item ) {
			if ( self::ACTIVE === $item['status'] ) {
				$items[ $index ]['status'] = self::REPLACED;
			}
		}

		$items[] = array_merge(
			array(
				'created' => time(),
				'status'  => self::ACTIVE,
			),
			$intent
		);

		self::save( $order, $items );
	}

	/**
	 * Move one intent to a new status.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $intent Intent token.
	 * @param string   $status New status.
	 * @return void
	 */
	public static function set_status( $order, $intent, $status ) {
		$items = self::all( $order );

		foreach ( $items as $index => $item ) {
			if ( $item['intent'] === $intent ) {
				$items[ $index ]['status'] = $status;
			}
		}

		self::save( $order, $items );
	}

	/**
	 * What a settlement of this intent means for this order.
	 *
	 * A settlement can arrive against an intent the order has moved on from - the customer had the
	 * old checkout open in another tab, or their wallet sat in a queue for a day. Whether that pays
	 * the order depends on one thing: whether it paid the right amount to the right wallet.
	 *
	 * @param WC_Order            $order  Order.
	 * @param string              $intent The intent that settled.
	 * @param int                 $units  What was paid, in micro-USDC.
	 * @return string 'pays' | 'unexpected' | 'unknown'
	 */
	public static function classify_settlement( $order, $intent, $units ) {
		foreach ( self::all( $order ) as $item ) {
			if ( $item['intent'] !== $intent ) {
				continue;
			}

			// The amount the order is actually owed, not the amount this intent happened to name:
			// an intent minted before a total changed is exactly the case this guards.
			$owed = (int) $order->get_meta( '_p2flux_units' );

			return ( (int) $units === $owed ) ? 'pays' : 'unexpected';
		}

		return 'unknown';
	}

	/**
	 * Persist the ledger.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $items Records.
	 * @return void
	 */
	private static function save( $order, array $items ) {
		$order->update_meta_data( self::LEDGER_META, wp_json_encode( array( 'v' => 1, 'items' => array_values( $items ) ) ) );
		$order->save();
	}
}
