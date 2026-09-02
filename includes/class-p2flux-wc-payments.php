<?php
/**
 * One-time payments: minting an intent, and deciding what a settlement means.
 *
 * The rule the whole file turns on: the browser's message is a claim, and the server's verification
 * is what pays an order. A page can say anything; `/v1/payments/verify` reads the chain and answers
 * about the exact intent this order was given.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * One-time payment flow.
 */
class P2Flux_WC_Payments {

	/**
	 * Ensure the order has an intent the customer can pay, minting one if needed.
	 *
	 * An existing intent is reused whenever it still describes this order: same amount, same
	 * recipient, same environment, and long enough left to be worth opening. Reuse matters - a fresh
	 * intent for every page load would leave a trail of live payment instructions for one order.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $context units, recipient, environment, rate.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function ensure_intent( $order, array $context ) {
		$active = P2Flux_WC_Intents::active( $order );

		if ( $active
			&& (int) $active['units'] === (int) $context['units']
			&& strtolower( $active['recipient'] ) === strtolower( $context['recipient'] )
			&& $active['environment'] === $context['environment']
			&& (int) $active['expires'] > time() + MINUTE_IN_SECONDS ) {
			return $active;
		}

		$may = P2Flux_WC_Intents::may_mint( $order );
		if ( true !== $may ) {
			return new WP_Error(
				'p2flux_intent_' . $may,
				'ceiling' === $may
					? __( 'This order has too many unresolved P2Flux payment attempts. Please contact the store.', 'p2flux-for-woocommerce' )
					: __( 'A payment attempt for this order was just created. Please try again in a moment.', 'p2flux-for-woocommerce' )
			);
		}

		$client = P2Flux_WC_Client::for_environment( $context['environment'] );

		try {
			$created = $client->createPayment(
				array(
					'recipient' => $context['recipient'],
					'amount'    => P2Flux_WC_Money::format( (int) $context['units'] ),
				)
			);
		} catch ( \Exception $e ) {
			P2Flux_WC_Logger::error( 'could not create a payment intent', array( 'order' => $order->get_id(), 'error' => $e->getMessage() ) );

			return new WP_Error( 'p2flux_unavailable', __( 'P2Flux could not be reached. Please try again in a moment.', 'p2flux-for-woocommerce' ) );
		}

		$intent = array(
			'intent'      => (string) $created['intent'],
			'reference'   => isset( $created['reference'] ) ? (string) $created['reference'] : '',
			'units'       => (int) $context['units'],
			'recipient'   => strtolower( $context['recipient'] ),
			'environment' => $context['environment'],
			'expires'     => isset( $created['expires_at'] ) ? (int) $created['expires_at'] : time() + HOUR_IN_SECONDS,
		);

		P2Flux_WC_Intents::add( $order, $intent );

		$order->update_meta_data( '_p2flux_env', $context['environment'] );
		$order->update_meta_data( '_p2flux_recipient', strtolower( $context['recipient'] ) );
		$order->update_meta_data( '_p2flux_units', (int) $context['units'] );
		$order->update_meta_data( '_p2flux_rate', (string) $context['rate'] );
		$order->update_meta_data( '_p2flux_expires_at', (int) $intent['expires'] );
		$order->save();

		P2Flux_WC_Jobs::schedule_recovery( $order->get_id() );

		return $intent;
	}

	/**
	 * Verify a transaction the browser reported, and pay the order if it is real.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $intent  Intent the customer was paying.
	 * @param string   $tx_hash Transaction the browser named.
	 * @param string   $receipt Optional settlement receipt from the checkout.
	 * @return array<string,mixed> status: paid|confirming|rejected|error, plus code and redirect.
	 */
	public static function verify( $order, $intent, $tx_hash, $receipt = '' ) {
		$client = P2Flux_WC_Client::for_object( $order );

		try {
			$verdict = $client->verifyPayment( $intent, $tx_hash, '' !== $receipt ? $receipt : null );
		} catch ( \Exception $e ) {
			P2Flux_WC_Logger::log( 'verification could not be completed', array( 'order' => $order->get_id(), 'error' => $e->getMessage() ) );

			return array(
				'status' => 'error',
				'code'   => 'NETWORK_ERROR',
			);
		}

		if ( ! empty( $verdict['valid'] ) ) {
			self::settle( $order, $intent, $verdict );

			return array(
				'status'   => 'paid',
				'code'     => 'CONFIRMED',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		$code = isset( $verdict['code'] ) ? (string) $verdict['code'] : 'INVALID';

		if ( 'PAYMENT_CONFIRMING' === $code ) {
			// On chain, not settled deep enough yet. The customer has paid; the page keeps asking
			// about this same transaction and never offers to pay again.
			return array(
				'status' => 'confirming',
				'code'   => $code,
			);
		}

		return array(
			'status' => 'rejected',
			'code'   => $code,
		);
	}

	/**
	 * Record a verified settlement against an order.
	 *
	 * A settlement can belong to an intent the order has moved past - the customer left the old
	 * checkout open, their wallet queued the transaction for a day. Whether it pays the order is
	 * decided by amount, never by which intent was current: money that arrived for the wrong total
	 * is real money that needs a human, not a paid order.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $intent  Intent that settled.
	 * @param array    $verdict Verification or recovery response.
	 * @return void
	 */
	public static function settle( $order, $intent, array $verdict ) {
		if ( $order->is_paid() ) {
			return;
		}

		$hash        = isset( $verdict['tx_hash'] ) ? (string) $verdict['tx_hash'] : '';
		$paid_amount = isset( $verdict['amount'] ) ? (string) $verdict['amount'] : '';
		$paid_units  = '' !== $paid_amount ? P2Flux_WC_Money::to_scaled( $paid_amount ) : null;
		$environment = (string) $order->get_meta( '_p2flux_env' );
		$explorer    = P2Flux_WC_Client::explorer_url( $environment );

		$classification = null === $paid_units ? 'unknown' : P2Flux_WC_Intents::classify_settlement( $order, $intent, $paid_units );

		if ( 'pays' !== $classification ) {
			/*
			 * Real money arrived that this order cannot account for. Never a paid order and never a
			 * silent write-off: it is recorded with everything needed to refund it, and a human is
			 * told.
			 */
			$order->update_meta_data(
				'_p2flux_unexpected_payment',
				wp_json_encode(
					array(
						'intent'  => $intent,
						'tx_hash' => $hash,
						'units'   => (int) $paid_units,
					)
				)
			);
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount in USDC, 2: explorer URL. */
					__( 'P2Flux: a payment of %1$s USDC arrived for an earlier version of this order and does NOT settle it. Review before fulfilling: %2$s', 'p2flux-for-woocommerce' ),
					$paid_amount,
					$explorer . '/tx/' . $hash
				)
			);
			$order->save();
			P2Flux_WC_Logger::error( 'settlement did not match the order total', array( 'order' => $order->get_id() ) );

			return;
		}

		// A cancelled order only comes back if WE let Woo cancel it while a payment was outstanding.
		if ( 'cancelled' === $order->get_status() && ! $order->get_meta( '_p2flux_auto_cancelled' ) ) {
			$order->add_order_note( __( 'P2Flux: a payment settled for this cancelled order. It has NOT been reinstated automatically; refund it or restore the order by hand.', 'p2flux-for-woocommerce' ) );
			$order->save();

			return;
		}

		P2Flux_WC_Intents::set_status( $order, $intent, P2Flux_WC_Intents::SETTLED );

		$order->update_meta_data( '_p2flux_tx_hash', $hash );
		$order->update_meta_data( '_p2flux_settled_intent', $intent );
		$order->update_meta_data( '_p2flux_paid_units', (int) $paid_units );
		$order->add_order_note(
			sprintf(
				/* translators: 1: explorer URL. */
				__( 'P2Flux payment verified on chain. Transaction: %s', 'p2flux-for-woocommerce' ),
				$explorer . '/tx/' . $hash
			)
		);
		$order->payment_complete( $hash );
		$order->save();

		P2Flux_WC_Jobs::unschedule_order( $order->get_id() );
	}
}
