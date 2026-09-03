<?php
/**
 * Background work: bounded, per order, and always re-reading state before acting.
 *
 * Four jobs, and every one of them is a question rather than an instruction. A queued job carries no
 * authority - by the time it runs the order may be paid, the subscription cancelled, the customer
 * may have paid by hand - so each handler loads current state and decides again. A worker that acts
 * on what was true when it was scheduled is the mechanism behind every "why was I charged after I
 * cancelled" story.
 *
 * There is no per-minute sweep. Recovery is scheduled per order at a handful of points and then
 * stops; the daily sweep exists only as reconciliation for orders whose jobs were lost with a
 * database restore, and it schedules work rather than doing it.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler jobs.
 */
class P2Flux_WC_Jobs {

	const GROUP = 'p2flux';

	const RECHARGE  = 'p2flux_wc_recharge';
	const RECONCILE = 'p2flux_wc_reconcile';
	const RECOVER   = 'p2flux_wc_recover_order';
	const SWEEP     = 'p2flux_wc_sweep';

	/** When a one-time order is asked about, if its callback never arrived. */
	const RECOVERY_OFFSETS = array( 900, 3600, 21600, 86400, 172800 );

	/** How many times a reconciliation may look before a human is asked. */
	const MAX_RECONCILE = 12;

	/**
	 * Register the handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::RECHARGE, array( __CLASS__, 'recharge' ) );
		add_action( self::RECONCILE, array( __CLASS__, 'reconcile' ) );
		add_action( self::RECOVER, array( __CLASS__, 'recover_order' ) );
		add_action( self::SWEEP, array( __CLASS__, 'sweep' ) );

		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::SWEEP, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::SWEEP, array(), self::GROUP );
		}
	}

	/**
	 * Schedule one job for one order.
	 *
	 * @param string $what     'recharge' | 'reconcile' | 'recover'.
	 * @param int    $order_id Order.
	 * @param int    $delay    Seconds from now.
	 * @return void
	 */
	public static function schedule( $what, $order_id, $delay = 0 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$hook = 'recharge' === $what ? self::RECHARGE : ( 'reconcile' === $what ? self::RECONCILE : self::RECOVER );
		$args = array( (int) $order_id );

		// One pending job per order per kind: a retry ladder that schedules itself twice halves its
		// own interval every round.
		if ( as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}

		as_schedule_single_action( time() + max( 0, (int) $delay ), $hook, $args, self::GROUP );
	}

	/**
	 * Drop every pending job for an order.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	public static function unschedule_order( $order_id ) {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( array( self::RECHARGE, self::RECONCILE, self::RECOVER ) as $hook ) {
			as_unschedule_all_actions( $hook, array( (int) $order_id ), self::GROUP );
		}
	}

	/**
	 * Drop every pending job for every order of a subscription.
	 *
	 * Called when a subscription is cancelled or suspended, so a retry queued minutes ago cannot
	 * fire against a decision a human has since made.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function unschedule_subscription( $subscription ) {
		if ( ! P2Flux_WC_Subscriptions::is_native( $subscription ) ) {
			self::unschedule_order( $subscription->get_id() );
		}

		foreach ( $subscription->get_related_orders( 'ids' ) as $order_id ) {
			self::unschedule_order( $order_id );
		}

		P2Flux_WC_Subscriptions::unschedule( $subscription );
	}

	/**
	 * Try a renewal charge again.
	 *
	 * @param int $order_id Renewal order.
	 * @return void
	 */
	public static function recharge( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() || $order->get_meta( '_p2flux_manual_paid' ) ) {
			return;
		}

		// A renewal order, or a parent order whose first charge is being retried.
		$subscription = self::subscription_for( $order );
		if ( ! $subscription ) {
			return;
		}

		$outcome = P2Flux_WC_Charger::collect( P2Flux_WC_Subscriptions::ref( $subscription ), $order->get_id() );

		if ( 'busy' === $outcome['status'] ) {
			// Another worker holds the lock. Come back after it has finished rather than waiting.
			self::schedule( 'recharge', $order_id, 60 );
		}
	}

	/**
	 * Find the settlement behind a period the contract says was collected.
	 *
	 * This is what stands between `ALREADY_CHARGED` and an order that is paid but unattributable.
	 * Until the exact transaction is known the order stays unpaid, because a payment nobody can
	 * point at cannot be audited or refunded.
	 *
	 * @param int $order_id Order awaiting reconciliation.
	 * @return void
	 */
	public static function reconcile( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$auth_id = (string) $order->get_meta( '_p2flux_auth_id' );
		$period  = $order->get_meta( '_p2flux_period_index' );
		if ( '' === $auth_id || '' === $period ) {
			return;
		}
		$period = (int) $period;

		$subscription = self::subscription_for( $order );
		if ( ! $subscription ) {
			return;
		}

		// Ownership first: a settlement pays the order that owns its period, and no other.
		if ( ! P2Flux_WC_Periods::owned_by( $auth_id, $period, $order->get_id() ) ) {
			P2Flux_WC_Logger::error( 'reconciliation stopped: another order owns this period', array( 'order' => $order_id, 'period' => $period ) );
			return;
		}

		$capability = P2Flux_WC_Auth_History::capability( $subscription, $auth_id );
		$record     = P2Flux_WC_Auth_History::get( $subscription, $auth_id );
		if ( null === $capability || null === $record ) {
			return;
		}

		$attempts = P2Flux_WC_Collection::bump( $subscription, 'reconcile' );
		$client   = P2Flux_WC_Client::for_environment( isset( $record['environment'] ) ? $record['environment'] : P2Flux_WC_Client::current_environment() );
		$hint     = P2Flux_WC_Charger::hint_for( $order, $period );

		try {
			$found = $client->recoverCharge( $capability, $period, $hint );
		} catch ( \Exception $e ) {
			// RECOVERY_UNAVAILABLE, an unreachable API: all retryable, none of them a verdict.
			P2Flux_WC_Logger::log( 'settlement recovery unavailable', array( 'order' => $order_id, 'error' => $e->getMessage() ) );
			if ( $attempts < self::MAX_RECONCILE ) {
				self::schedule( 'reconcile', $order_id, HOUR_IN_SECONDS );
			} else {
				self::needs_attention( $order, __( 'P2Flux: this period was collected but its transaction could not be recovered automatically. Use the P2Flux box on this order to try again.', 'p2flux-for-woocommerce' ) );
			}
			return;
		}
		unset( $capability );

		if ( empty( $found['found'] ) ) {
			/*
			 * The contract said this period was collected and its own log has no settlement for it.
			 * Suspicious rather than impossible - a boundary the clock disagreed about, a period
			 * that was skipped - so it is retried a bounded number of times and then handed to a
			 * human, never resolved by guessing.
			 */
			if ( $attempts < self::MAX_RECONCILE ) {
				self::schedule( 'reconcile', $order_id, self::backoff( $attempts ) );
			} else {
				self::needs_attention( $order, __( 'P2Flux: no settlement could be found for this billing period. Please check the subscription before treating this renewal as paid.', 'p2flux-for-woocommerce' ) );
			}
			return;
		}

		$mismatch = self::settlement_mismatch( $found, $record, $auth_id, $period );
		if ( null !== $mismatch ) {
			// Never mutate payment state on a settlement that contradicts the order it claims to
			// pay: that is how one payment ends up marking the wrong order paid.
			$order->update_meta_data( '_p2flux_recover_mismatch', $mismatch );
			$order->save();
			self::needs_attention( $order, sprintf( __( 'P2Flux: a recovered settlement did not match this order (%s). Nothing was changed.', 'p2flux-for-woocommerce' ), $mismatch ) );
			P2Flux_WC_Logger::error( 'recovered settlement mismatch', array( 'order' => $order_id, 'reason' => $mismatch ) );
			return;
		}

		P2Flux_WC_Periods::set_state( $auth_id, $period, P2Flux_WC_Periods::SETTLED, array( 'tx_hash' => $found['tx_hash'] ) );
		P2Flux_WC_Charger::mark_paid( $order, $record, $period, (string) $found['tx_hash'] );
		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::NORMAL, array( 'renewal_order_id' => 0 ) );
	}

	/**
	 * Ask whether a one-time payment landed after all.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	public static function recover_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		// A deliberately cancelled order is not a lost payment. Only one this plugin let Woo cancel
		// while a payment was outstanding may come back to life.
		if ( 'cancelled' === $order->get_status() && ! $order->get_meta( '_p2flux_auto_cancelled' ) ) {
			return;
		}

		$client = P2Flux_WC_Client::for_object( $order );

		foreach ( P2Flux_WC_Intents::recoverable( $order ) as $intent ) {
			try {
				$found = $client->recoverPayment( $intent['intent'] );
			} catch ( \Exception $e ) {
				P2Flux_WC_Logger::log( 'payment recovery unavailable', array( 'order' => $order_id, 'error' => $e->getMessage() ) );
				continue;
			}

			if ( empty( $found['found'] ) || empty( $found['valid'] ) ) {
				continue;
			}

			P2Flux_WC_Payments::settle( $order, $intent['intent'], $found );
			return;
		}
	}

	/**
	 * Reconciliation of last resort.
	 *
	 * Not the primary mechanism - per-order jobs are - but a store restored from a backup loses its
	 * Action Scheduler queue while keeping its orders, and those orders should still resolve.
	 *
	 * @return void
	 */
	public static function sweep() {
		$orders = wc_get_orders(
			array(
				'payment_method' => 'p2flux',
				'status'         => array( 'pending', 'on-hold' ),
				'date_created'   => '>' . ( time() - 7 * DAY_IN_SECONDS ),
				'limit'          => 50,
				'return'         => 'ids',
			)
		);

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			if ( $order->get_meta( '_p2flux_reconciling' ) ) {
				self::schedule( 'reconcile', $order_id, 0 );
				continue;
			}
			if ( ! empty( P2Flux_WC_Intents::recoverable( $order ) ) ) {
				self::schedule( 'recover', $order_id, 0 );
			}
		}
	}

	/**
	 * Schedule the whole recovery ladder for a one-time order.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	public static function schedule_recovery( $order_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		foreach ( self::RECOVERY_OFFSETS as $offset ) {
			as_schedule_single_action( time() + $offset, self::RECOVER, array( (int) $order_id ), self::GROUP );
		}
	}

	/**
	 * Does a recovered settlement describe this order?
	 *
	 * @param array  $found   Recovery response.
	 * @param array  $record  Authorization record.
	 * @param string $auth_id Expected subscription id.
	 * @param int    $period  Expected period.
	 * @return string|null Mismatch reason, or null.
	 */
	private static function settlement_mismatch( array $found, array $record, $auth_id, $period ) {
		if ( isset( $found['subscription_id'] ) && strtolower( (string) $found['subscription_id'] ) !== strtolower( (string) $auth_id ) ) {
			return 'subscription';
		}
		if ( isset( $found['period_index'] ) && (int) $found['period_index'] !== (int) $period ) {
			return 'period';
		}
		if ( isset( $found['recipient'], $record['recipient'] ) && strtolower( (string) $found['recipient'] ) !== strtolower( (string) $record['recipient'] ) ) {
			return 'recipient';
		}
		if ( isset( $found['amount_units'], $record['units'] ) && (int) $found['amount_units'] !== (int) $record['units'] ) {
			return 'amount';
		}
		if ( empty( $found['tx_hash'] ) ) {
			return 'no_transaction';
		}

		return null;
	}

	/**
	 * Flag an order for a human, once.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $note  What to say.
	 * @return void
	 */
	private static function needs_attention( $order, $note ) {
		if ( $order->get_meta( '_p2flux_needs_attention' ) ) {
			return;
		}

		$order->update_meta_data( '_p2flux_needs_attention', 1 );
		$order->add_order_note( $note );
		$order->save();
	}

	/**
	 * Slower each time, so a stubborn reconciliation stops costing an API call a minute.
	 *
	 * @param int $attempts Attempts so far.
	 * @return int Seconds.
	 */
	private static function backoff( $attempts ) {
		$ladder = array( 300, 900, 3600, 21600, 86400 );
		$index  = min( max( 0, $attempts - 1 ), count( $ladder ) - 1 );

		return $ladder[ $index ];
	}

	/**
	 * The subscription an order belongs to, parent or renewal.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Subscription|null
	 */
	private static function subscription_for( $order ) {
		return P2Flux_WC_Subscriptions::for_order( $order );
	}
}
