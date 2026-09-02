<?php
/**
 * Reacting to what WooCommerce Subscriptions does to a subscription.
 *
 * The hard case is on-hold, because WCS uses it for two opposite things. It sets on-hold itself,
 * moments before calling the gateway, as part of collecting a renewal - that one means "collect
 * now". A human suspending a subscription also sets on-hold - and that one means "never collect".
 * Status alone cannot tell them apart, so this listens for the transition and records WHY, which is
 * the half the charger needs.
 *
 * Cancellation is simpler and stricter: stop, immediately, and drop every queued job. A retry
 * scheduled ten minutes ago must not fire against a decision a customer has since made.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription status hooks.
 */
class P2Flux_WC_Lifecycle {

	/**
	 * Listen.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_subscription_status_on-hold', array( __CLASS__, 'on_hold' ) );
		add_action( 'woocommerce_subscription_status_active', array( __CLASS__, 'active' ) );

		foreach ( array( 'cancelled', 'pending-cancel', 'expired' ) as $status ) {
			add_action( 'woocommerce_subscription_status_' . $status, array( __CLASS__, 'stopped' ) );
		}

		add_action( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'cancel_unpaid' ), 10, 2 );
	}

	/**
	 * Someone put a subscription on hold.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function on_hold( $subscription ) {
		if ( ! self::ours( $subscription ) ) {
			return;
		}

		/*
		 * Two transitions this must NOT read as a suspension: the one WCS makes at the start of a
		 * renewal (it is about to ask us to collect) and our own, when we put a suspension back
		 * after an in-flight payment settled.
		 */
		if ( ! empty( $GLOBALS['p2flux_wc_own_transition'] ) ) {
			return;
		}
		/*
		 * WCS's `prepare_renewal` sets on-hold at priority 1 of the PARENT hook, before the gateway-specific
		 * hook (priority 10) exists at all - so the renewal's own on-hold arrives while only the parent
		 * action is running. Checking the gateway hook alone would classify every scheduled renewal as a
		 * suspension and drop its jobs.
		 */
		if ( self::in_scheduled_renewal() ) {
			return;
		}

		$state = P2Flux_WC_Collection::get( $subscription );
		if ( P2Flux_WC_Collection::DUNNING === $state['state'] ) {
			// Our own dunning hold, already recorded, with its own bounded retries.
			return;
		}

		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::SUSPENDED, array( 'reason' => 'suspended' ) );
		P2Flux_WC_Jobs::unschedule_subscription( $subscription );
		P2Flux_WC_Logger::log( 'subscription suspended; all scheduled charges dropped', array( 'subscription' => $subscription->get_id() ) );
	}

	/**
	 * A subscription came back.
	 *
	 * Resuming means resuming the SCHEDULE: WCS decides when the next renewal is, and the plugin
	 * collects it then. Nothing is collected on reactivation itself, and no missed period is caught
	 * up - the contract has no catch-up billing and inventing one here would charge for time nobody
	 * had access to.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function active( $subscription ) {
		if ( ! self::ours( $subscription ) ) {
			return;
		}

		$state = P2Flux_WC_Collection::get( $subscription );
		if ( in_array( $state['state'], array( P2Flux_WC_Collection::SUSPENDED, P2Flux_WC_Collection::DUNNING ), true ) ) {
			P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::NORMAL, array( 'renewal_order_id' => 0 ) );
		}
	}

	/**
	 * A subscription ended.
	 *
	 * WooCommerce can stop billing on its own account; only the customer's wallet can revoke the
	 * on-chain authorization. So the plugin stops charging immediately - that part is entirely in
	 * our hands - and the account page offers the customer the revocation, which is theirs.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function stopped( $subscription ) {
		if ( ! self::ours( $subscription ) ) {
			return;
		}

		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::CANCELLED, array( 'reason' => $subscription->get_status() ) );
		P2Flux_WC_Jobs::unschedule_subscription( $subscription );

		$active = P2Flux_WC_Auth_History::active( $subscription );
		if ( $active && P2Flux_WC_Auth_History::REVOKED !== $active['status'] ) {
			$subscription->add_order_note(
				__( 'P2Flux: this store will not collect from this subscription again. The customer’s on-chain authorization stays valid until they revoke it from their account page.', 'p2flux-for-woocommerce' )
			);
			$subscription->save();
		}
	}

	/**
	 * Should WooCommerce cancel this unpaid order yet?
	 *
	 * Woo cancels unpaid orders after `woocommerce_hold_stock_minutes`. A P2Flux payment can still
	 * be in a customer's wallet at that point, so the cancellation waits until the intent itself is
	 * dead - and when it does happen, it is marked as ours, which is the only thing that lets
	 * recovery bring the order back if a payment lands afterwards.
	 *
	 * @param bool     $cancel Whether Woo intends to cancel.
	 * @param WC_Order $order  Order.
	 * @return bool
	 */
	public static function cancel_unpaid( $cancel, $order ) {
		if ( ! $cancel || 'p2flux' !== $order->get_payment_method() ) {
			return $cancel;
		}

		$expires = (int) $order->get_meta( '_p2flux_expires_at' );
		if ( $expires && time() < $expires + 15 * MINUTE_IN_SECONDS ) {
			return false;
		}

		$order->update_meta_data( '_p2flux_auto_cancelled', 1 );
		$order->save();

		return true;
	}

	/**
	 * Are we inside WCS's scheduled-renewal request?
	 *
	 * @return bool
	 */
	public static function in_scheduled_renewal() {
		if ( ! function_exists( 'doing_action' ) ) {
			return false;
		}

		return doing_action( 'woocommerce_scheduled_subscription_payment' )
			|| doing_action( 'woocommerce_scheduled_subscription_payment_p2flux' );
	}

	/**
	 * Is this subscription ours to care about?
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return bool
	 */
	private static function ours( $subscription ) {
		return $subscription && 'p2flux' === $subscription->get_payment_method();
	}
}
