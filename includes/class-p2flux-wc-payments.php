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
	 * recipient, same environment, same way of paying the network fee, and long enough left to be
	 * worth opening. Reuse matters - a fresh intent for every page load would leave a trail of live
	 * payment instructions for one order.
	 *
	 * The way the network fee is paid is sealed into the intent, so it is decided here. It is sticky:
	 * an order that already has an intent keeps that intent's mode, so a reload never undoes the
	 * customer's choice. Only a new order gets the default - USDC when the merchant enabled it, P2Flux
	 * supports it and the amount can carry the fixed fee - and a sponsored order drops to native once
	 * sponsorship is no longer allowed.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $context units, recipient, environment, rate; optionally mode, when the
	 *                          customer chose one (P2Flux_WC_Payments::switch_mode()).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function ensure_intent( $order, array $context ) {
		if ( ! isset( $context['mode'] ) ) {
			$active          = P2Flux_WC_Intents::active( $order );
			$context['mode'] = $active ? P2Flux_WC_Intents::mode( $active ) : P2Flux_WC_Sponsorship::SPONSORED;
		}
		if ( P2Flux_WC_Sponsorship::SPONSORED === $context['mode'] && ! P2Flux_WC_Sponsorship::allowed( $context['environment'], $context['units'] ) ) {
			$context['mode'] = P2Flux_WC_Sponsorship::NATIVE;
		}

		$active = P2Flux_WC_Intents::active( $order );
		if ( $active && self::describes( $active, $context ) ) {
			return $active;
		}

		/*
		 * Minting is a read-modify-write on the order's intent ledger. Two requests racing here - a
		 * double click, the pay page and a retry - could each append an intent and one write would
		 * drop the other's record: a payable instruction the order no longer knows about. So one
		 * request mints at a time, and it re-reads the ledger first in case the other just did.
		 */
		$minted = P2Flux_WC_Lock::with(
			'intent-' . $order->get_id(),
			static function () use ( $order, $context ) {
				if ( method_exists( $order, 'read_meta_data' ) ) {
					$order->read_meta_data( true );
				}
				$reusable = self::reusable( $order, $context );

				return $reusable ? $reusable : self::mint( $order, $context );
			}
		);

		if ( false === $minted ) {
			return new WP_Error( 'p2flux_intent_cooldown', __( 'A payment attempt for this order was just created. Please try again in a moment.', 'p2flux-for-woocommerce' ) );
		}

		return $minted;
	}

	/**
	 * The newest intent this order already has that still describes it, made active again if the
	 * customer had moved away from it. Callers hold the order's intent lock.
	 *
	 * Switching the way of paying back and forth therefore costs at most one intent of each kind,
	 * however often the customer toggles.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $context units, recipient, environment, mode.
	 * @return array<string,mixed>|null
	 */
	private static function reusable( $order, array $context ) {
		foreach ( array_reverse( P2Flux_WC_Intents::all( $order ) ) as $item ) {
			if ( ! in_array( $item['status'], array( P2Flux_WC_Intents::ACTIVE, P2Flux_WC_Intents::REPLACED ), true ) || ! self::describes( $item, $context ) ) {
				continue;
			}
			if ( P2Flux_WC_Intents::ACTIVE !== $item['status'] ) {
				P2Flux_WC_Intents::revive( $order, $item['intent'] );
				$order->update_meta_data( '_p2flux_expires_at', (int) $item['expires'] );
				$order->save();
				$item['status'] = P2Flux_WC_Intents::ACTIVE;
			}

			return $item;
		}

		return null;
	}

	/**
	 * Does this intent still describe the order, and is it worth opening?
	 *
	 * @param array<string,mixed> $item    Ledger record.
	 * @param array               $context units, recipient, environment, mode.
	 * @return bool
	 */
	private static function describes( array $item, array $context ) {
		return (int) $item['units'] === (int) $context['units']
			&& strtolower( $item['recipient'] ) === strtolower( $context['recipient'] )
			&& $item['environment'] === $context['environment']
			&& P2Flux_WC_Intents::mode( $item ) === $context['mode']
			&& (int) $item['expires'] > time() + MINUTE_IN_SECONDS;
	}

	/**
	 * Create a new intent and record it. Callers hold the order's intent lock.
	 *
	 * A sponsored create that P2Flux refuses because it cannot sponsor this payment - the network fee
	 * service is paused, the amount is under its floor - is retried once as native, and sponsorship
	 * is treated as unavailable for a few minutes. Nothing else is retried: a second slow call after a
	 * timeout would outlast the request, and a rate limit is not answered by asking again.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $context units, recipient, environment, rate, mode.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function mint( $order, array $context ) {
		$mode = $context['mode'];
		$may  = P2Flux_WC_Intents::may_mint( $order, $mode );
		if ( true !== $may ) {
			return new WP_Error(
				'p2flux_intent_' . $may,
				'ceiling' === $may
					? __( 'This order has too many unresolved P2Flux payment attempts. Please contact the store.', 'p2flux-for-woocommerce' )
					: __( 'A payment attempt for this order was just created. Please try again in a moment.', 'p2flux-for-woocommerce' )
			);
		}

		$client = P2Flux_WC_Client::for_environment( $context['environment'] );
		$terms  = array(
			'recipient' => $context['recipient'],
			'amount'    => P2Flux_WC_Money::format( (int) $context['units'] ),
		);

		try {
			try {
				// A native create is exactly what 1.0.0 sent: the mode field only when sponsored.
				$created = $client->createPayment( P2Flux_WC_Sponsorship::SPONSORED === $mode ? $terms + array( 'gas_payment_mode' => 'payment_token' ) : $terms );
			} catch ( \P2FluxWC\Vendor\P2Flux\P2FluxException $e ) {
				if ( P2Flux_WC_Sponsorship::SPONSORED !== $mode
					|| ( 0 !== strpos( $e->status, 'PAYMENT_TOKEN_GAS' ) && 'AMOUNT_OUT_OF_BOUNDS' !== $e->status ) ) {
					throw $e;
				}
				P2Flux_WC_Logger::log( 'sponsored intent refused, minting a native one', array( 'order' => $order->get_id(), 'error' => $e->status ) );
				P2Flux_WC_Sponsorship::mark_unavailable( $context['environment'] );
				$mode    = P2Flux_WC_Sponsorship::NATIVE;
				$created = $client->createPayment( $terms );
			}
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
			'mode'        => $mode,
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
	 * The customer chose the other way of paying the network fee.
	 *
	 * The intent they had may already be paid - they signed, then clicked the link - so that is asked
	 * first, and a payment found ends it: nothing new is minted. Otherwise the order gets an intent of
	 * the chosen mode, reusing one it already has.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $mode  P2Flux_WC_Sponsorship::NATIVE | SPONSORED.
	 * @return array<string,mixed>|WP_Error status paid|confirming (with redirect), or switched with
	 *                                      token and mode.
	 */
	public static function switch_mode( $order, $mode ) {
		if ( ! in_array( $mode, array( P2Flux_WC_Sponsorship::NATIVE, P2Flux_WC_Sponsorship::SPONSORED ), true )
			|| 'p2flux' !== $order->get_payment_method()
			|| P2Flux_WC_Subscriptions::for_order( $order, true ) ) {
			return new WP_Error( 'p2flux_mode', __( 'This order cannot change how it is paid.', 'p2flux-for-woocommerce' ) );
		}
		if ( $order->is_paid() ) {
			return array(
				'status'   => 'paid',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		$environment = (string) $order->get_meta( '_p2flux_env' );
		$units       = (int) $order->get_meta( '_p2flux_units' );
		$active      = P2Flux_WC_Intents::active( $order );
		if ( ! $active || '' === $environment || ! $units ) {
			return new WP_Error( 'p2flux_mode', __( 'This order cannot change how it is paid.', 'p2flux-for-woocommerce' ) );
		}

		try {
			$found = P2Flux_WC_Client::for_environment( $environment )->recoverPayment( $active['intent'] );
		} catch ( \Exception $e ) {
			// Not knowing is not the same as not paid: switching now could invite a second payment.
			return new WP_Error( 'p2flux_unavailable', __( 'P2Flux could not be reached. Please try again in a moment.', 'p2flux-for-woocommerce' ) );
		}
		if ( ! empty( $found['found'] ) ) {
			if ( ! empty( $found['valid'] ) ) {
				self::settle( $order, $active['intent'], $found );
			}

			return $order->is_paid()
				? array(
					'status'   => 'paid',
					'redirect' => $order->get_checkout_order_received_url(),
				)
				: array( 'status' => 'confirming' );
		}

		if ( P2Flux_WC_Sponsorship::SPONSORED === $mode && ! P2Flux_WC_Sponsorship::allowed( $environment, $units ) ) {
			return new WP_Error( 'p2flux_mode', __( 'Paying the network fee in USDC is not available right now.', 'p2flux-for-woocommerce' ) );
		}

		$intent = self::ensure_intent(
			$order,
			array(
				'units'       => $units,
				'recipient'   => (string) $order->get_meta( '_p2flux_recipient' ),
				'environment' => $environment,
				'rate'        => (string) $order->get_meta( '_p2flux_rate' ),
				'mode'        => $mode,
			)
		);
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		return array(
			'status' => 'switched',
			'token'  => $intent['intent'],
			'mode'   => P2Flux_WC_Intents::mode( $intent ),
		);
	}

	/**
	 * A renewal the customer paid by hand must never also be collected on chain.
	 *
	 * The fallback is real and worth having: a renewal that could not be collected can be paid from
	 * the order-pay screen like any one-off. But the recurring authorization still has that period
	 * uncollected, and a retry queued before the manual payment would happily take it - a second
	 * payment for one renewal.
	 *
	 * So the period is marked satisfied, every queued job for the order is dropped, and the charger
	 * refuses it from then on. The uncollected period costs nothing: there is no catch-up billing,
	 * and the next renewal falls in a later period.
	 *
	 * @param WC_Order $order Order that was just paid.
	 * @return void
	 */
	private static function stop_recurring_collection( $order ) {
		$subscription = P2Flux_WC_Subscriptions::for_order( $order );
		if ( ! $subscription ) {
			return;
		}
		// A parent order paid by hand is not a renewal: nothing recurring exists to stop yet.
		$parent = P2Flux_WC_Subscriptions::for_order( $order, true );
		if ( $parent ) {
			return;
		}

		$order->update_meta_data( '_p2flux_manual_paid', 1 );
		$order->save();

		foreach ( P2Flux_WC_Periods::for_order( $order->get_id() ) as $row ) {
			if ( in_array( $row['state'], array( P2Flux_WC_Periods::SETTLED, P2Flux_WC_Periods::MANUAL ), true ) ) {
				continue;
			}
			P2Flux_WC_Periods::set_state( $row['auth_id'], (int) $row['period_index'], P2Flux_WC_Periods::MANUAL );
		}

		P2Flux_WC_Jobs::unschedule_subscription( $subscription );
		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::NORMAL, array( 'renewal_order_id' => 0 ) );
		$order->add_order_note( __( 'P2Flux: this renewal was paid directly, so the recurring charge for it has been cancelled. The subscription continues normally.', 'p2flux-for-woocommerce' ) );
		$order->save();

		P2Flux_WC_Subscriptions::after_paid( $order );
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
			self::flag_duplicate( $order, $intent, $verdict );
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
		self::note_economics( $order, $verdict );
		$order->payment_complete( $hash );
		$order->save();

		self::stop_recurring_collection( $order );

		P2Flux_WC_Jobs::unschedule_order( $order->get_id() );
		P2Flux_WC_Jobs::schedule_sibling_check( $order );
	}

	/**
	 * How the network fee was paid, and what the merchant received. From the verified settlement
	 * only - never from anything the browser said.
	 *
	 * @param WC_Order $order   Order being paid.
	 * @param array    $verdict Verification or recovery response.
	 * @return void
	 */
	private static function note_economics( $order, array $verdict ) {
		$sponsored = isset( $verdict['gas_payment_mode'] ) && 'payment_token' === $verdict['gas_payment_mode'];
		$order->update_meta_data( '_p2flux_gas_mode', $sponsored ? P2Flux_WC_Sponsorship::SPONSORED : P2Flux_WC_Sponsorship::NATIVE );

		$accounting = isset( $verdict['accounting'] ) && is_array( $verdict['accounting'] ) ? $verdict['accounting'] : array();
		if ( ! isset( $accounting['merchant_net_units'] ) || ! ctype_digit( (string) $accounting['merchant_net_units'] ) ) {
			return;
		}
		$net = P2Flux_WC_Money::display( (int) $accounting['merchant_net_units'] );

		if ( $sponsored ) {
			$fee = isset( $accounting['network_fee_units'] ) && ctype_digit( (string) $accounting['network_fee_units'] ) ? (int) $accounting['network_fee_units'] : 0;
			$order->add_order_note(
				sprintf(
					/* translators: 1: USDC the merchant received, 2: network fee in USDC the customer paid. */
					__( 'P2Flux: the customer paid the network fee in USDC (%2$s USDC, on top of the price). You received %1$s USDC.', 'p2flux-for-woocommerce' ),
					$net,
					P2Flux_WC_Money::display( $fee )
				)
			);
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: USDC the merchant received. */
				__( 'P2Flux: the customer paid the network fee in ETH. You received %1$s USDC.', 'p2flux-for-woocommerce' ),
				$net
			)
		);
	}

	/**
	 * A verified settlement for an order that another intent has already paid.
	 *
	 * An order can hold more than one payable intent at once - the customer switched how to pay the
	 * network fee, or an older tab was still open - and both can be paid. The second payment is real
	 * money from the customer. It never completes the order a second time; it is recorded with what
	 * is needed to refund it, the intent stops being polled, and the merchant is told. The same
	 * settlement reported again (a repeated verify, a recovery that catches up) changes nothing.
	 *
	 * @param WC_Order $order   Order, already paid.
	 * @param string   $intent  Intent that settled.
	 * @param array    $verdict Verification or recovery response.
	 * @return void
	 */
	private static function flag_duplicate( $order, $intent, array $verdict ) {
		$hash = isset( $verdict['tx_hash'] ) ? (string) $verdict['tx_hash'] : '';
		if ( '' === $hash
			|| (string) $intent === (string) $order->get_meta( '_p2flux_settled_intent' )
			|| strtolower( $hash ) === strtolower( (string) $order->get_meta( '_p2flux_tx_hash' ) ) ) {
			return;
		}

		$known = json_decode( (string) $order->get_meta( '_p2flux_unexpected_payment' ), true );
		if ( is_array( $known ) && isset( $known['tx_hash'] ) && strtolower( (string) $known['tx_hash'] ) === strtolower( $hash ) ) {
			return;
		}

		$paid_amount = isset( $verdict['amount'] ) ? (string) $verdict['amount'] : '';
		$paid_units  = '' !== $paid_amount ? P2Flux_WC_Money::to_scaled( $paid_amount ) : null;
		$explorer    = P2Flux_WC_Client::explorer_url( (string) $order->get_meta( '_p2flux_env' ) );

		P2Flux_WC_Intents::set_status( $order, $intent, P2Flux_WC_Intents::DUPLICATE );
		$order->update_meta_data(
			'_p2flux_unexpected_payment',
			wp_json_encode(
				array(
					'intent'    => $intent,
					'tx_hash'   => $hash,
					'units'     => (int) $paid_units,
					'duplicate' => true,
				)
			)
		);
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount in USDC, 2: explorer URL. */
				__( 'P2Flux: a second payment of %1$s USDC arrived for this order after it was already paid. It has NOT been applied to the order - refund it to the customer: %2$s', 'p2flux-for-woocommerce' ),
				$paid_amount,
				$explorer . '/tx/' . $hash
			)
		);
		$order->save();
		P2Flux_WC_Logger::error( 'a second payment arrived for an already-paid order', array( 'order' => $order->get_id() ) );
	}
}
