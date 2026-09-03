<?php
/**
 * The two emails a native subscription sends, through WooCommerce's own email system.
 *
 * One tells the customer they have to act - a short wallet, an approval that ran out, terms to sign
 * again, a renewal that was not collected - with the reason and a link to the place they can act.
 * The other confirms an explicit cancellation. Nothing else: confirming and reconciling states are
 * ours to resolve and never produce mail, and a signup that expired is shown on the account page,
 * not emailed as if something had been cancelled.
 *
 * Both are ordinary WC_Email classes, so a merchant can switch them off, change the subject or
 * restyle them like any other WooCommerce email. Neither ever carries a capability, a wallet secret
 * or a bare protocol code.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registration and the two triggers.
 */
class P2Flux_WC_Native_Emails {

	const NOTIFIED_META = '_p2flux_notified';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	/**
	 * Add the two classes.
	 *
	 * @param array $emails Emails.
	 * @return array
	 */
	public static function register( $emails ) {
		require_once __DIR__ . '/emails/class-p2flux-wc-email-action-required.php';
		require_once __DIR__ . '/emails/class-p2flux-wc-email-subscription-cancelled.php';

		$emails['P2Flux_WC_Email_Action_Required']        = new P2Flux_WC_Email_Action_Required();
		$emails['P2Flux_WC_Email_Subscription_Cancelled'] = new P2Flux_WC_Email_Subscription_Cancelled();

		return $emails;
	}

	/**
	 * The customer has to act. Once per order per reason.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param WC_Order                      $order        The renewal (or parent) order.
	 * @param string                        $reason       INSUFFICIENT_BALANCE | INSUFFICIENT_ALLOWANCE | reauth | missed | other code.
	 * @return void
	 */
	public static function action_required( $subscription, $order, $reason ) {
		$reason = self::normalize( $reason );
		if ( null === $reason ) {
			return;
		}

		/* The read-then-write below is what two workers finishing the same order in the same second
		 * would race on. A short lock on the order makes it one writer; a worker that cannot take it
		 * is behind one that is already sending, and sends nothing. */
		$lock_key = 'notify-' . (int) $order->get_id();
		$token    = P2Flux_WC_Lock::acquire( $lock_key );
		if ( false === $token ) {
			return;
		}
		try {
			$order = wc_get_order( $order->get_id() ) ?: $order;
			$sent  = $order->get_meta( self::NOTIFIED_META );
			$sent  = is_string( $sent ) && '' !== $sent ? json_decode( $sent, true ) : array();
			$sent  = is_array( $sent ) ? $sent : array();
			if ( isset( $sent[ $reason ] ) ) {
				return;
			}
			// A period that passes after the customer was already told why it could not be collected
			// is not news: one email per order for the cause, none for the consequence.
			if ( 'missed' === $reason && ! empty( $sent ) ) {
				return;
			}
			$sent[ $reason ] = time();
			$order->update_meta_data( self::NOTIFIED_META, wp_json_encode( $sent ) );
			$order->save();
		} finally {
			P2Flux_WC_Lock::release( $lock_key, $token );
		}

		/**
		 * Fires when a native subscription needs the customer's attention. The email class listens.
		 *
		 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
		 * @param WC_Order                      $order        Order.
		 * @param string                        $reason       balance | allowance | reauth | missed.
		 */
		do_action( 'p2flux_wc_native_action_required', $subscription, $order, $reason );
	}

	/**
	 * An explicit cancellation of a subscription that had been active.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function cancelled( $subscription ) {
		/**
		 * Fires when a customer or merchant cancels a native subscription. Never for an expired signup.
		 *
		 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
		 */
		do_action( 'p2flux_wc_native_subscription_cancelled', $subscription );
	}

	/**
	 * Map protocol codes to the four reasons the email knows how to explain.
	 *
	 * @param string $reason Code.
	 * @return string|null
	 */
	public static function normalize( $reason ) {
		switch ( $reason ) {
			case 'INSUFFICIENT_BALANCE':
			case 'balance':
				return 'balance';
			case 'INSUFFICIENT_ALLOWANCE':
			case 'allowance':
				return 'allowance';
			case 'reauth':
			case 'TERMS_CHANGED':
			case 'SIGNATURE_VALIDATION_TOO_EXPENSIVE':
				return 'reauth';
			case 'missed':
				return 'missed';
		}

		return null;
	}

	/**
	 * The link every email points to.
	 *
	 * @return string
	 */
	public static function account_url() {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'p2flux-subscriptions' ) : '';
	}
}
