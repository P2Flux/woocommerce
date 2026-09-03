<?php
/**
 * The only place in this plugin that asks P2Flux to collect money.
 *
 * Everything that could want a charge - the first charge at signup, a scheduled renewal, a dunning
 * retry, a customer clicking "retry now", an allowance that was just restored - goes through here,
 * so the checks that make a charge safe exist once instead of five times.
 *
 * The shape of a charge:
 *
 *   take the subscription lock, or leave and come back later - never queue behind it
 *   re-read the subscription and the order from storage, because the caller's copies are old
 *   re-check everything: status, collection state, terms, who owns this period
 *   claim the period BEFORE sending, so a lost response cannot leave it unclaimed
 *   send exactly one charge
 *   re-read again, and apply only what the CURRENT state allows
 *
 * The last step is the one that is easy to skip and expensive to get wrong. A cancellation can
 * commit while the request is in flight; when the response comes back, the subscription this worker
 * remembers no longer exists. The settlement is still real and still belongs on the order - but the
 * lifecycle decision that came with it is stale, and writing it would undo the cancellation.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guarded charge execution.
 */
class P2Flux_WC_Charger {

	/**
	 * Collect one period for a renewal (or the first charge on a parent order).
	 *
	 * @param string|int $subscription_id Subscription reference ('wcs:<id>', 'native:<id>', or a WCS id).
	 * @param int        $order_id        Order the charge should pay.
	 * @return array<string,mixed> {
	 *     @type string $status  charged|reconciling|pending|failed|cancelled|refused|busy.
	 *     @type string $code    Protocol status or refusal reason.
	 *     @type string $tx_hash Settlement, when one is proven.
	 *     @type string $message For the customer-facing page, when there is one.
	 * }
	 */
	public static function collect( $subscription_id, $order_id ) {
		$key   = P2Flux_WC_Subscriptions::lock_key( $subscription_id );
		$token = P2Flux_WC_Lock::acquire( $key );
		if ( false === $token ) {
			/*
			 * Someone else is mid-charge on this subscription. Not an error and not something to
			 * wait for: the other worker will finish, and this one comes back to a decided state.
			 */
			return array(
				'status' => 'busy',
				'code'   => 'LOCKED',
			);
		}

		try {
			return self::guarded( $subscription_id, $order_id, $token );
		} finally {
			P2Flux_WC_Lock::release( $key, $token );
		}
	}

	/**
	 * The critical section.
	 *
	 * @param int    $subscription_id WooCommerce subscription id.
	 * @param int    $order_id        Order to pay.
	 * @param string $token           Lock token.
	 * @return array<string,mixed>
	 */
	private static function guarded( $subscription_id, $order_id, $token ) {
		$subscription = P2Flux_WC_Subscriptions::load( $subscription_id );
		$order        = wc_get_order( $order_id );

		if ( ! $subscription || ! $order ) {
			return self::refused( 'GONE', 'The subscription or order no longer exists.' );
		}

		// The pair has to be a pair: an order is charged against the subscription it belongs to,
		// whichever engine owns it, and never against one a caller merely named.
		$owner = P2Flux_WC_Subscriptions::for_order( $order );
		if ( ! $owner || P2Flux_WC_Subscriptions::ref( $owner ) !== P2Flux_WC_Subscriptions::ref( $subscription ) ) {
			P2Flux_WC_Logger::error( 'charge refused: order does not belong to the subscription', array( 'order' => $order_id, 'subscription' => P2Flux_WC_Subscriptions::ref( $subscription ) ) );

			return self::refused( 'ORDER_MISMATCH', 'This order does not belong to that subscription.' );
		}

		// Already paid, by us or by the customer through the manual fallback. Nothing to do, and
		// nothing to schedule: this is the ordinary end of a retry ladder, not a failure. A refunded
		// order is not "paid" to WooCommerce, but it was collected and given back; never again.
		if ( $order->is_paid() || $order->get_meta( '_p2flux_manual_paid' ) || 'refunded' === $order->get_status() || '' !== (string) $order->get_meta( '_p2flux_tx_hash' ) ) {
			return self::refused( 'ALREADY_PAID', 'This order is already paid.' );
		}

		$collection = P2Flux_WC_Collection::get( $subscription );
		$allowed    = P2Flux_WC_Collection::may_charge( $subscription->get_status(), $collection, $order_id );
		if ( true !== $allowed ) {
			P2Flux_WC_Logger::log( 'charge refused before sending', array( 'reason' => $allowed, 'order' => $order_id ) );

			return self::refused( $allowed, 'This subscription is not currently collecting.' );
		}

		$authorization = P2Flux_WC_Auth_History::active( $subscription );
		if ( ! $authorization ) {
			return self::refused( 'NO_AUTHORIZATION', 'This subscription has no active authorization.' );
		}

		$capability = P2Flux_WC_Auth_History::capability( $subscription, $authorization['id'] );
		if ( null === $capability ) {
			/*
			 * The stored capability will not decrypt: a rotated key without its predecessor, a site
			 * restored without its wp-config constant. Never a charge attempt - the reference would
			 * be garbage - and never a silent failure either.
			 */
			P2Flux_WC_Logger::error( 'stored authorization could not be decrypted', array( 'subscription' => $subscription_id ) );
			P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::REAUTH_REQUIRED, array( 'reason' => 'encryption_key' ) );

			return self::refused( 'ENCRYPTION_UNAVAILABLE', 'The stored authorization could not be read.' );
		}

		$terms = self::terms_mismatch( $subscription, $order, $authorization );
		if ( null !== $terms ) {
			// The customer signed for an amount, a period and a wallet. Woo now wants something
			// else. Charging the signed amount while marking the new invoice paid would be quietly
			// billing the wrong number, so this stops and asks for a new authorization.
			P2Flux_WC_Collection::set(
				$subscription,
				P2Flux_WC_Collection::REAUTH_REQUIRED,
				array(
					'reason'           => $terms,
					'renewal_order_id' => $order_id,
				)
			);
			$order->add_order_note( __( 'P2Flux: the subscription terms no longer match the customer’s authorization, so nothing was charged. The customer must authorize the new terms.', 'p2flux-for-woocommerce' ) );
			$order->save();

			return self::refused( 'TERMS_CHANGED', 'The subscription terms changed and must be authorized again.' );
		}

		$period = self::expected_period( $authorization );

		/*
		 * The engine's say on WHICH period this order may use. WooCommerce Subscriptions owns its own
		 * calendar and asks when a renewal is due, so whatever period is current is the right one. A
		 * native renewal is bound to the period its due date fell in, and a native signup to a short
		 * window: a period that has opened too early waits, one that has passed is never charged for
		 * an older invoice, and an expired signup never charges at all.
		 */
		$gate = P2Flux_WC_Subscriptions::charge_gate( $subscription, $order, $period );
		if ( true !== $gate ) {
			P2Flux_WC_Logger::log( 'charge refused by the engine gate', array( 'reason' => $gate['code'], 'order' => $order_id, 'period' => $period ) );
			if ( 'CYCLE_NOT_OPEN' === $gate['code'] && ! empty( $gate['retry_at'] ) ) {
				P2Flux_WC_Jobs::schedule( 'recharge', $order_id, max( 5, (int) $gate['retry_at'] - time() ) );
			}

			return self::refused( $gate['code'], 'This renewal cannot be collected in the current billing period.' );
		}

		$claim = P2Flux_WC_Periods::claim(
			array(
				'auth_id'         => $authorization['id'],
				'period_index'    => $period,
				'subscription_id' => $subscription->get_id(),
				'engine'          => P2Flux_WC_Subscriptions::engine( $subscription ),
				'order_id'        => $order_id,
				'units'           => isset( $authorization['units'] ) ? (int) $authorization['units'] : 0,
				'environment'     => isset( $authorization['environment'] ) ? $authorization['environment'] : '',
			)
		);

		if ( false === $claim ) {
			/*
			 * Another Woo order already owns this billing period. One period funds one order, so
			 * this one must not be charged and must not be paid by whatever settles - the protocol
			 * would answer ALREADY_CHARGED and the second order would look collected.
			 */
			$order->update_meta_data( '_p2flux_period_conflict', $period );
			$order->add_order_note( __( 'P2Flux: this billing period is already assigned to another order, so no charge was sent. Please review both orders.', 'p2flux-for-woocommerce' ) );
			$order->save();
			P2Flux_WC_Logger::error( 'period already owned by another order', array( 'order' => $order_id, 'period' => $period ) );

			return self::refused( 'PERIOD_CONFLICT', 'This billing period belongs to another order.' );
		}

		if ( P2Flux_WC_Periods::MANUAL === $claim['state'] ) {
			return self::refused( 'MANUALLY_PAID', 'This renewal was already paid by hand.' );
		}
		// A period this order already collected, or is still collecting, is never sent again: the
		// protocol would answer ALREADY_CHARGED and reconciliation would re-pay - possibly an order
		// that was since refunded, which is not "paid" to WooCommerce but was very much collected.
		if ( in_array( $claim['state'], array( P2Flux_WC_Periods::SETTLED, P2Flux_WC_Periods::RECONCILING ), true ) ) {
			return self::refused( 'PERIOD_NOT_CHARGEABLE', 'This billing period was already collected.' );
		}

		// What we know before sending: the attempt time, which becomes the hint that lets a later
		// recovery find the settlement in one query instead of searching a whole period.
		self::record_attempt( $order, $authorization['id'], $period );
		P2Flux_WC_Periods::set_state( $authorization['id'], $period, P2Flux_WC_Periods::CHARGING, array( 'order_id' => $order_id ) );

		$client = P2Flux_WC_Client::for_environment( isset( $authorization['environment'] ) ? $authorization['environment'] : P2Flux_WC_Client::current_environment() );
		$result = $client->charge( $capability );
		unset( $capability );

		return self::reconcile( $subscription_id, $order_id, $authorization, $period, $result, $token );
	}

	/**
	 * Apply a charge result to whatever state the store is in NOW.
	 *
	 * @param int    $subscription_id Subscription.
	 * @param int    $order_id        Order.
	 * @param array  $authorization   Authorization record.
	 * @param int    $period          Period index charged.
	 * @param object $result          ChargeResult.
	 * @param string $token           Lock token held during the request.
	 * @return array<string,mixed>
	 */
	private static function reconcile( $subscription_id, $order_id, array $authorization, $period, $result, $token ) {
		// Re-read: both objects may have changed while the request was in flight.
		$subscription = P2Flux_WC_Subscriptions::load( $subscription_id );
		$order        = wc_get_order( $order_id );
		if ( ! $subscription || ! $order ) {
			return self::refused( 'GONE', 'The subscription or order no longer exists.' );
		}
		$lock_key = P2Flux_WC_Subscriptions::lock_key( $subscription );

		/*
		 * The response names the period it is talking about, and that is authoritative. The claim was
		 * made against the period derived from the clock a moment earlier, which can differ: a charge
		 * that crossed a period boundary, or - as happens whenever an earlier charge is still settling
		 * - an answer about that earlier period rather than this one. Paying an order against a period
		 * it does not own is the one outcome worth this much care.
		 */
		$reported = isset( $result->periodIndex ) && null !== $result->periodIndex ? (int) $result->periodIndex : null;
		if ( null !== $reported && $reported < (int) $period ) {
			/*
			 * An answer about an EARLIER period that another order is still settling: the previous
			 * renewal's charge has not reached finality, and the API will not open this period until
			 * it has. Nothing about this order is decided by that. Keep its claim, wait, ask again.
			 */
			$earlier = P2Flux_WC_Periods::get( $authorization['id'], $reported );
			if ( $earlier && (int) $earlier['order_id'] !== (int) $order_id
				&& in_array( $earlier['state'], array( P2Flux_WC_Periods::CHARGING, P2Flux_WC_Periods::RECONCILING ), true ) ) {
				P2Flux_WC_Periods::set_state( $authorization['id'], $period, P2Flux_WC_Periods::CLAIMED );
				P2Flux_WC_Jobs::schedule( 'recharge', $order_id, P2Flux_WC_Renewal::CONFIRMING_DELAY );

				return array(
					'status'  => 'pending',
					'code'    => 'PREVIOUS_PERIOD_SETTLING',
					'tx_hash' => '',
					'message' => '',
				);
			}
		}
		if ( null !== $reported && $reported !== (int) $period ) {
			$moved = P2Flux_WC_Periods::claim(
				array(
					'auth_id'         => $authorization['id'],
					'period_index'    => $reported,
					'subscription_id' => $subscription->get_id(),
					'engine'          => P2Flux_WC_Subscriptions::engine( $subscription ),
					'order_id'        => $order_id,
					'units'           => isset( $authorization['units'] ) ? (int) $authorization['units'] : 0,
					'environment'     => isset( $authorization['environment'] ) ? $authorization['environment'] : '',
				)
			);

			if ( false === $moved ) {
				// Another Woo order owns the period this settlement belongs to. Record nothing about
				// payment; a person decides which order the money was for.
				P2Flux_WC_Logger::error(
					'the charge answered about a period another order owns',
					array( 'order' => $order_id, 'claimed' => (int) $period, 'reported' => $reported )
				);
				// The period claimed from the clock was never collected by this attempt either.
				P2Flux_WC_Periods::set_state( $authorization['id'], $period, P2Flux_WC_Periods::CLAIMED );
				$order->update_meta_data( '_p2flux_period_conflict', $reported );
				$order->add_order_note(
					sprintf(
						/* translators: 1: billing period index. */
						__( 'P2Flux answered about billing period %d, which belongs to another order. Nothing was recorded; please review both orders.', 'p2flux-for-woocommerce' ),
						$reported
					)
				);
				$order->save();

				return self::refused( 'PERIOD_CONFLICT', 'That billing period belongs to another order.' );
			}

			// The period we claimed a moment ago was never collected by this attempt; leave it claimed
			// for this order so a later charge can still use it, and follow the settlement instead.
			P2Flux_WC_Periods::set_state( $authorization['id'], $period, P2Flux_WC_Periods::CLAIMED );
			$period = $reported;
		}

		$decision = P2Flux_WC_Renewal::decide( $result, P2Flux_WC_Collection::get( $subscription )['attempts'] );
		$hash     = isset( $result->txHash ) ? (string) $result->txHash : '';
		$proven   = 'paid' === $decision['outcome'] && '' !== $hash;

		$still_ours = P2Flux_WC_Lock::still_ours( $lock_key, $token );
		$status     = $subscription->get_status();
		$lifecycle  = in_array( $status, array( 'cancelled', 'pending-cancel', 'expired' ), true )
			|| P2Flux_WC_Collection::SUSPENDED === P2Flux_WC_Collection::get( $subscription )['state'];

		/*
		 * The settlement is a fact about money and belongs on the order whatever else changed. The
		 * lifecycle half of the decision is a judgement made from state that may now be stale, so it
		 * is applied only when this worker still holds the lease AND nobody has cancelled or
		 * suspended underneath it. A cancellation that commits mid-charge stands; the period it
		 * already paid for is still recorded, and no later period is ever collected.
		 */
		if ( $proven ) {
			P2Flux_WC_Periods::set_state( $authorization['id'], $period, P2Flux_WC_Periods::SETTLED, array( 'tx_hash' => $hash ) );
			self::mark_paid( $order, $authorization, $period, $hash );

			if ( $lifecycle ) {
				$order->add_order_note( __( 'P2Flux: this charge was already in flight when the subscription was cancelled or suspended. The payment stands; no further periods will be collected.', 'p2flux-for-woocommerce' ) );
				$order->save();
				self::preserve_lifecycle( $subscription, $status );
			}

			return array(
				'status'  => 'charged',
				'code'    => isset( $result->status ) ? (string) $result->status : 'CHARGED',
				'tx_hash' => $hash,
				'message' => '',
			);
		}

		if ( 'reconcile' === $decision['outcome'] ) {
			// Collected, settlement not yet known. The order stays unpaid until the exact
			// transaction is recovered - that is what makes it attributable and refundable.
			P2Flux_WC_Periods::set_state( $authorization['id'], $period, P2Flux_WC_Periods::RECONCILING, '' !== $hash ? array( 'tx_hash' => $hash ) : array() );
			$order->update_meta_data( '_p2flux_reconciling', 1 );
			$order->update_meta_data( '_p2flux_auth_id', $authorization['id'] );
			$order->update_meta_data( '_p2flux_period_index', $period );
			$order->add_order_note( $decision['note'] );
			// A retry after a failure that is now confirming is no longer failed: the customer paid,
			// and "Failed" next to money that has moved is the wrong word for it.
			if ( 'failed' === $order->get_status() ) {
				$order->set_status( 'pending' );
			}
			$order->save();

			if ( $still_ours ) {
				P2Flux_WC_Collection::bump( $subscription, '' !== $decision['counter'] ? $decision['counter'] : 'reconcile' );
			}
			P2Flux_WC_Jobs::schedule( 'reconcile', $order_id, (int) $decision['delay'] );
			self::notify( $subscription, $order, $decision, $result );

			return array(
				'status'  => 'reconciling',
				'code'    => isset( $result->status ) ? (string) $result->status : 'CONFIRMING',
				'tx_hash' => $hash,
				'message' => '',
			);
		}

		// Nothing was collected. The period stays claimed by this order so a retry keeps it.
		P2Flux_WC_Periods::set_state( $authorization['id'], $period, P2Flux_WC_Periods::CLAIMED );

		if ( '' !== $decision['note'] ) {
			$order->add_order_note( $decision['note'] );
			$order->save();
		}

		if ( ! $still_ours || $lifecycle ) {
			// Stale worker, or a cancellation that already committed. Record nothing about the
			// lifecycle; the state that exists now was decided with better information.
			P2Flux_WC_Logger::log( 'charge outcome not applied: state moved on', array( 'order' => $order_id, 'code' => $decision['note'] ) );

			return array(
				'status'  => 'pending',
				'code'    => isset( $result->status ) ? (string) $result->status : 'RETRY',
				'tx_hash' => '',
				'message' => '',
			);
		}

		// State first, then the attempt inside it: a state change resets the counters, and the
		// first dunning failure must count as one, not zero.
		if ( null !== $decision['collection'] ) {
			P2Flux_WC_Collection::set(
				$subscription,
				$decision['collection'],
				array(
					'renewal_order_id' => $order_id,
					'reason'           => isset( $result->status ) ? (string) $result->status : '',
				)
			);
		}
		if ( '' !== $decision['counter'] ) {
			P2Flux_WC_Collection::bump( $subscription, $decision['counter'] );
		}
		if ( 'cancel' === $decision['outcome'] ) {
			P2Flux_WC_Auth_History::mark(
				$subscription,
				$authorization['id'],
				'PERMISSION_REVOKED' === $result->status ? P2Flux_WC_Auth_History::REVOKED : P2Flux_WC_Auth_History::EXPIRED,
				(string) $result->status
			);
			if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
				$subscription->update_status( 'cancelled', $decision['note'] );
			}
		}
		if ( null !== $decision['order_status'] ) {
			// The note was added above; the status change does not repeat it.
			$order->update_status( $decision['order_status'] );
		}
		if ( 'failed' === $decision['outcome'] ) {
			P2Flux_WC_Subscriptions::after_failed( $subscription, $order );
		}
		if ( null !== $decision['schedule'] ) {
			$delay = P2Flux_WC_Subscriptions::retry_delay( $subscription, $order, $decision );
			if ( null !== $delay ) {
				P2Flux_WC_Jobs::schedule( $decision['schedule'], $order_id, (int) $delay );
			}
		}
		self::notify( $subscription, $order, $decision, $result );

		return array(
			'status'  => 'cancel' === $decision['outcome'] ? 'cancelled' : ( 'failed' === $decision['outcome'] ? 'failed' : 'pending' ),
			'code'    => isset( $result->status ) ? (string) $result->status : 'RETRY',
			'tx_hash' => '',
			'message' => $decision['note'],
		);
	}

	/**
	 * Record a proven settlement against an order.
	 *
	 * @param WC_Order $order         Order.
	 * @param array    $authorization Authorization record.
	 * @param int      $period        Period index.
	 * @param string   $hash          Settlement transaction.
	 * @return void
	 */
	public static function mark_paid( $order, array $authorization, $period, $hash ) {
		$order->update_meta_data( '_p2flux_auth_id', $authorization['id'] );
		$order->update_meta_data( '_p2flux_period_index', (int) $period );
		$order->update_meta_data( '_p2flux_tx_hash', $hash );
		$order->update_meta_data( '_p2flux_paid_units', isset( $authorization['units'] ) ? (int) $authorization['units'] : 0 );
		$order->delete_meta_data( '_p2flux_reconciling' );

		$explorer = P2Flux_WC_Client::explorer_url( isset( $authorization['environment'] ) ? $authorization['environment'] : P2Flux_WC_Client::current_environment() );
		$order->add_order_note(
			sprintf(
				/* translators: 1: block explorer transaction URL. */
				__( 'P2Flux collected this billing period. Transaction: %s', 'p2flux-for-woocommerce' ),
				$explorer . '/tx/' . $hash
			)
		);

		$order->payment_complete( $hash );
		$order->save();

		// The engine's schedule moves on from here - the one funnel every proven payment passes.
		P2Flux_WC_Subscriptions::after_paid( $order );
	}

	/**
	 * Tell the customer when, and only when, the decision says they have to act.
	 *
	 * Confirming, reconciling and transient failures are ours to resolve and never produce an email;
	 * a wallet that is short, an approval that ran out, or terms that need signing again do. The
	 * engine decides how (WooCommerce Subscriptions has its own emails; native subscriptions send one
	 * of theirs), and the order remembers what it already said so a retry ladder does not repeat it.
	 *
	 * @param object   $subscription Subscription.
	 * @param WC_Order $order        Order.
	 * @param array    $decision     Decision.
	 * @param object   $result       Charge result.
	 * @return void
	 */
	private static function notify( $subscription, $order, array $decision, $result ) {
		if ( empty( $decision['notify'] ) || 'cancel' === $decision['outcome'] ) {
			return;
		}
		if ( ! class_exists( 'P2Flux_WC_Native_Emails' ) || ! P2Flux_WC_Subscriptions::is_native( $subscription ) ) {
			return;
		}

		P2Flux_WC_Native_Emails::action_required( $subscription, $order, isset( $result->status ) ? (string) $result->status : 'UNKNOWN' );
	}

	/**
	 * Put a lifecycle status back after WooCommerce Subscriptions reacted to a payment.
	 *
	 * Recording a payment reactivates an on-hold subscription, which is right for a dunning
	 * recovery and wrong for one a human suspended. The suspension is restored immediately, with a
	 * flag so the plugin's own status hook knows this transition was ours and not a new suspension.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param string          $status       Status before the payment was recorded.
	 * @return void
	 */
	private static function preserve_lifecycle( $subscription, $status ) {
		if ( 'on-hold' !== $status || 'on-hold' === $subscription->get_status() ) {
			return;
		}

		$GLOBALS['p2flux_wc_own_transition'] = true;
		$subscription->update_status( 'on-hold', __( 'P2Flux: suspension preserved after an in-flight payment settled.', 'p2flux-for-woocommerce' ) );
		unset( $GLOBALS['p2flux_wc_own_transition'] );
	}

	/**
	 * Which period a charge sent now would collect.
	 *
	 * The same arithmetic the contract does: the index derives from the clock and the signed start,
	 * and nothing the caller passes can select it.
	 *
	 * @param array $authorization Authorization record.
	 * @return int
	 */
	public static function expected_period( array $authorization ) {
		$start  = isset( $authorization['start'] ) ? (int) $authorization['start'] : 0;
		$period = isset( $authorization['period'] ) ? (int) $authorization['period'] : 0;

		if ( $period < 1 || time() < $start ) {
			return 0;
		}

		return intdiv( time() - $start, $period );
	}

	/**
	 * Do Woo's current terms still match what the customer signed?
	 *
	 * @param WC_Subscription $subscription  Subscription.
	 * @param WC_Order        $order         Order being paid.
	 * @param array           $authorization Authorization record.
	 * @return string|null Mismatch reason, or null when they match.
	 */
	private static function terms_mismatch( $subscription, $order, array $authorization ) {
		$rate     = (string) $subscription->get_meta( '_p2flux_rate' );
		$expected = P2Flux_WC_Money::to_units( $order->get_total(), '' !== $rate ? $rate : '1' );

		if ( null === $expected ) {
			return 'amount_unreadable';
		}
		if ( (int) $authorization['units'] !== (int) $expected ) {
			return 'amount_changed';
		}

		$period = P2Flux_WC_Gateway::billing_period( $subscription );
		if ( null === $period || (int) $authorization['period'] !== (int) $period ) {
			return 'period_changed';
		}

		return null;
	}

	/**
	 * Remember that a charge was attempted, so a later recovery can start near it.
	 *
	 * Attempt times are not evidence of anything - the settlement is - but they turn a search over a
	 * whole billing period into a single log query.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $auth_id Authorization.
	 * @param int      $period  Period index.
	 * @return void
	 */
	private static function record_attempt( $order, $auth_id, $period ) {
		$stored = $order->get_meta( '_p2flux_charge_attempts' );
		$items  = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : array();
		$items  = is_array( $items ) ? $items : array();

		$items[] = array(
			'auth_id'      => $auth_id,
			'period_index' => (int) $period,
			'attempted_at' => time(),
		);

		$order->update_meta_data( '_p2flux_charge_attempts', wp_json_encode( array_slice( $items, -10 ) ) );
		$order->save();
	}

	/**
	 * The hint for a recovery of this order's period, if we have one.
	 *
	 * @param WC_Order $order  Order.
	 * @param int      $period Period index.
	 * @return array<string,int>|null
	 */
	public static function hint_for( $order, $period ) {
		$stored = $order->get_meta( '_p2flux_charge_attempts' );
		$items  = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : array();
		if ( ! is_array( $items ) ) {
			return null;
		}

		foreach ( array_reverse( $items ) as $item ) {
			if ( (int) $item['period_index'] === (int) $period ) {
				return array( 'attempted_at' => (int) $item['attempted_at'] );
			}
		}

		return null;
	}

	/**
	 * A refusal, in the shape every caller handles.
	 *
	 * @param string $code    Why.
	 * @param string $message For a customer-facing page.
	 * @return array<string,mixed>
	 */
	private static function refused( $code, $message ) {
		return array(
			'status'  => 'refused',
			'code'    => $code,
			'tx_hash' => '',
			'message' => $message,
		);
	}
}
