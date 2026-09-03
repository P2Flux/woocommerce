<?php
/**
 * Where a subscription comes from, whatever engine owns it.
 *
 * Two engines can own a recurring relationship: WooCommerce Subscriptions, when it is installed, and
 * the plugin's own native subscriptions, which need no other plugin. Everything that charges, repairs,
 * refunds or displays a subscription only ever needs the same small surface - status, meta, the
 * billing period, the customer, the related orders - and both engines provide it. What differs is
 * how a subscription is FOUND: from an order, or from a reference a browser sent back.
 *
 * That lookup lives here, once. A reference names its engine ('wcs:12', 'native:7'), so a job or a
 * customer action can never be pointed at the wrong table by a colliding id. A bare integer is a
 * WooCommerce Subscriptions id, which is what every reference was before native subscriptions
 * existed.
 *
 * The native engine also has a few things to say about a charge that WCS does not - which on-chain
 * period a renewal may use, what to do after a payment lands, when a retry may run. Those are asked
 * through the small hooks at the bottom, and answer "nothing to add" for a WCS subscription.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription lookup and the engine hooks.
 */
class P2Flux_WC_Subscriptions {

	/** Order meta naming the native subscription an order belongs to. */
	const NATIVE_META = '_p2flux_native_subscription_id';

	/**
	 * The subscription an order belongs to.
	 *
	 * @param WC_Order $order       Order.
	 * @param bool     $parent_only Only when the order is the subscription's parent (signup) order.
	 * @return object|null WC_Subscription, P2Flux_WC_Native_Subscription, or null.
	 */
	public static function for_order( $order, $parent_only = false ) {
		if ( ! $order ) {
			return null;
		}

		$native_id = (int) $order->get_meta( self::NATIVE_META );
		if ( $native_id > 0 ) {
			$native = self::load_native( $native_id );
			if ( ! $native ) {
				return null;
			}
			if ( $parent_only && (int) $native->get_parent_id() !== (int) $order->get_id() ) {
				return null;
			}

			return $native;
		}

		if ( $parent_only ) {
			if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				return null;
			}
			$found = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );

			return ! empty( $found ) ? reset( $found ) : null;
		}

		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$found = wcs_get_subscriptions_for_renewal_order( $order );
			if ( ! empty( $found ) ) {
				return reset( $found );
			}
		}
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$found = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
			if ( ! empty( $found ) ) {
				return reset( $found );
			}
		}

		return null;
	}

	/**
	 * Load a subscription from a reference.
	 *
	 * @param string|int $ref 'wcs:<id>', 'native:<id>', or a bare WCS id.
	 * @return object|null
	 */
	public static function load( $ref ) {
		$parsed = self::parse( $ref );
		if ( null === $parsed ) {
			return null;
		}

		if ( 'native' === $parsed['engine'] ) {
			return self::load_native( $parsed['id'] );
		}

		return function_exists( 'wcs_get_subscription' ) ? ( wcs_get_subscription( $parsed['id'] ) ?: null ) : null;
	}

	/**
	 * The reference for a subscription object.
	 *
	 * @param object $subscription Subscription.
	 * @return string
	 */
	public static function ref( $subscription ) {
		return ( self::is_native( $subscription ) ? 'native:' : 'wcs:' ) . (int) $subscription->get_id();
	}

	/**
	 * Is this a native subscription?
	 *
	 * @param mixed $subscription Anything.
	 * @return bool
	 */
	public static function is_native( $subscription ) {
		return class_exists( 'P2Flux_WC_Native_Subscription' ) && $subscription instanceof P2Flux_WC_Native_Subscription;
	}

	/**
	 * The lock key for a subscription or a reference: one per engine, never a shared integer.
	 *
	 * @param object|string|int $subscription_or_ref Subscription or reference.
	 * @return string
	 */
	public static function lock_key( $subscription_or_ref ) {
		if ( is_object( $subscription_or_ref ) ) {
			return ( self::is_native( $subscription_or_ref ) ? 'native-' : 'wcs-' ) . (int) $subscription_or_ref->get_id();
		}

		$parsed = self::parse( $subscription_or_ref );

		return $parsed ? $parsed['engine'] . '-' . $parsed['id'] : 'none-0';
	}

	/**
	 * Split a reference into engine and id.
	 *
	 * @param string|int $ref Reference.
	 * @return array{engine:string,id:int}|null
	 */
	public static function parse( $ref ) {
		if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
			return (int) $ref > 0 ? array( 'engine' => 'wcs', 'id' => (int) $ref ) : null;
		}
		if ( ! is_string( $ref ) || ! preg_match( '/^(wcs|native):(\d{1,12})$/', $ref, $m ) ) {
			return null;
		}

		return array( 'engine' => $m[1], 'id' => (int) $m[2] );
	}

	/**
	 * The engine's name, for the period ledger.
	 *
	 * @param object $subscription Subscription.
	 * @return string
	 */
	public static function engine( $subscription ) {
		return self::is_native( $subscription ) ? 'native' : 'wcs';
	}

	/*
	 * ---- Engine hooks. Each answers "nothing to add" for a WCS subscription. ----
	 */

	/**
	 * May a charge for this order be sent for the period the clock names?
	 *
	 * WCS renewals may use whichever period is current: WCS owns the calendar and asks when it is
	 * due. A native renewal is bound to one period and a signup to one short window; the native
	 * object decides.
	 *
	 * @param object   $subscription Subscription.
	 * @param WC_Order $order        Order about to be charged.
	 * @param int      $expected     Period index the clock names.
	 * @return true|array{code:string,retry_at:int} True, or a refusal with when to come back (0 = never).
	 */
	public static function charge_gate( $subscription, $order, $expected ) {
		if ( self::is_native( $subscription ) ) {
			return $subscription->charge_gate( $order, (int) $expected );
		}

		return true;
	}

	/**
	 * How long before a retry of this decision, for this engine.
	 *
	 * @param object   $subscription Subscription.
	 * @param WC_Order $order        Order.
	 * @param array    $decision     From P2Flux_WC_Renewal::decide().
	 * @return int|null Seconds, or null to schedule nothing.
	 */
	public static function retry_delay( $subscription, $order, array $decision ) {
		if ( self::is_native( $subscription ) ) {
			return $subscription->retry_delay( $order, $decision );
		}

		return (int) $decision['delay'];
	}

	/**
	 * A settlement was recorded on an order: let the engine move its schedule on.
	 *
	 * The single funnel: the direct CHARGED path, a recovered ALREADY_CHARGED/CONFIRMING, and a manual
	 * payment all come through here.
	 *
	 * @param WC_Order $order Order that was just paid.
	 * @return void
	 */
	public static function after_paid( $order ) {
		$subscription = self::for_order( $order );
		if ( self::is_native( $subscription ) ) {
			$subscription->after_paid( $order );
		}
	}

	/**
	 * An authorization was just stored for this subscription.
	 *
	 * @param object $subscription Subscription (fresh, under the lock).
	 * @param array  $status       The /v1/subscriptions/status response the activation validated.
	 * @return void
	 */
	public static function after_activated( $subscription, array $status ) {
		if ( self::is_native( $subscription ) ) {
			$subscription->after_activated( $status );
		}
	}

	/**
	 * Drop the engine's own scheduled work for a subscription.
	 *
	 * @param object $subscription Subscription.
	 * @return void
	 */
	public static function unschedule( $subscription ) {
		if ( self::is_native( $subscription ) ) {
			$subscription->unschedule();
		}
	}

	/**
	 * Load a native subscription, when the engine is present.
	 *
	 * @param int $id Native id.
	 * @return P2Flux_WC_Native_Subscription|null
	 */
	private static function load_native( $id ) {
		if ( ! class_exists( 'P2Flux_WC_Native_Subscription' ) ) {
			return null;
		}

		return P2Flux_WC_Native_Subscription::load( (int) $id );
	}
}
