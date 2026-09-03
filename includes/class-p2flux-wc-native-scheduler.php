<?php
/**
 * The native subscription schedule: when a renewal is due, which on-chain period it may use, and
 * what happens when it cannot be collected.
 *
 * The rules, all of them:
 *
 *   The calendar is ours. A renewal is due at `next_payment_at`, computed from the anchor by
 *   P2Flux_WC_Calendar. One Action Scheduler action per subscription carries no arguments but the id,
 *   and a job that fires late or twice re-reads the row and decides again.
 *
 *   The on-chain period is a duplicate gate, not a schedule. Each renewal is bound to the period its
 *   due date fell in. The charger may collect it only while the clock is in that period: earlier waits,
 *   later is a miss. A later period never pays an older invoice.
 *
 *   A signup has a short window: the period the authorization was created in, and at most 24 hours.
 *   Past it, the signup is expired - never active, never charged - and the customer starts again. Only a
 *   charge already sent inside the window may still be reconciled afterwards.
 *
 *   No catch-up, ever. However long the store was down, at most one charge is attempted: the cycle
 *   whose period is the current one, if there is such a cycle. Everything older is recorded as skipped.
 *
 *   No automatic cancellation. A missed renewal leaves the subscription on hold; a later eligible
 *   payment brings it back; only a person cancels it.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Native schedule rules and the renewal job.
 */
class P2Flux_WC_Native_Scheduler {

	const HOOK  = 'p2flux_wc_native_renewal';
	const GROUP = 'p2flux';

	/** Dunning retries after a short wallet, from the previous attempt; each clamped to the period. */
	const DUNNING_LADDER = array( 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS, 2 * DAY_IN_SECONDS );

	/** Order meta. */
	const CYCLE_META  = '_p2flux_native_cycle';
	const DUE_META    = '_p2flux_native_due_at';
	const MISSED_META = '_p2flux_native_missed';

	/**
	 * Register the job.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'renewal' ) );
	}

	/*
	 * ---- Timing helpers ----
	 */

	/**
	 * Seconds after a boundary before acting on it: server clocks and block timestamps disagree by
	 * seconds, and a charge sent a second early lands in the period just collected.
	 *
	 * @param int $period Contract period.
	 * @return int
	 */
	public static function grace( $period ) {
		return max( 5, min( 300, intdiv( max( 1, (int) $period ), 6 ) ) );
	}

	/**
	 * The contract period for this subscription: the interval's fixed length, or the shortened one in
	 * test mode with the development fixture.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return int
	 */
	public static function contract_period( $subscription ) {
		$period = P2Flux_WC_Gateway::billing_period( $subscription );

		return $period ? (int) $period : (int) P2Flux_WC_Calendar::contract_period( $subscription->get_billing_period() );
	}

	/**
	 * Is the calendar shortened to the contract period (development fixture only)?
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return bool
	 */
	private static function short_calendar( $subscription ) {
		return self::contract_period( $subscription ) !== (int) P2Flux_WC_Calendar::contract_period( $subscription->get_billing_period() );
	}

	/**
	 * The n-th due instant for a subscription.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param int                           $n            Cycle.
	 * @return int
	 */
	public static function due( $subscription, $n ) {
		$anchor = $subscription->timestamp( 'schedule_anchor' );
		if ( self::short_calendar( $subscription ) ) {
			return $anchor + (int) $n * self::contract_period( $subscription );
		}

		return P2Flux_WC_Calendar::due( $anchor, $subscription->get_billing_period(), $n );
	}

	/**
	 * The latest cycle due at or before a moment.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param int                           $now          Moment.
	 * @return int
	 */
	public static function latest_cycle_at( $subscription, $now ) {
		$anchor = $subscription->timestamp( 'schedule_anchor' );
		if ( $now < $anchor ) {
			return -1;
		}
		if ( self::short_calendar( $subscription ) ) {
			return intdiv( $now - $anchor, self::contract_period( $subscription ) );
		}

		return P2Flux_WC_Calendar::latest_cycle_at( $anchor, $subscription->get_billing_period(), $now );
	}

	/**
	 * How long a signup has to complete its first payment: 24 hours, or the shortened period in test
	 * mode with the fixture. Never longer than a day, anywhere.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return int
	 */
	public static function activation_ttl( $subscription ) {
		return min( P2Flux_WC_Native_Subscription::ACTIVATION_TTL, self::contract_period( $subscription ) );
	}

	/**
	 * Period index of an instant under an authorization.
	 *
	 * @param array $auth Authorization record (start, period).
	 * @param int   $at   Instant.
	 * @return int
	 */
	private static function period_at( array $auth, $at ) {
		$start  = isset( $auth['start'] ) ? (int) $auth['start'] : 0;
		$period = isset( $auth['period'] ) ? (int) $auth['period'] : 0;
		if ( $period < 1 || $at < $start ) {
			return 0;
		}

		return intdiv( $at - $start, $period );
	}

	/**
	 * When a period ends under an authorization.
	 *
	 * @param array $auth  Authorization record.
	 * @param int   $index Period index.
	 * @return int
	 */
	private static function period_end( array $auth, $index ) {
		return (int) $auth['start'] + ( (int) $index + 1 ) * (int) $auth['period'];
	}

	/*
	 * ---- Engine hooks the charger asks ----
	 */

	/**
	 * May a charge for this order use the period the clock names?
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param WC_Order                      $order        Order.
	 * @param int                           $expected     Period the clock names.
	 * @return true|array{code:string,retry_at:int}
	 */
	public static function charge_gate( $subscription, $order, $expected ) {
		if ( $subscription->has_status( array( P2Flux_WC_Native_Subscription::EXPIRED, P2Flux_WC_Native_Subscription::CANCELLED ) ) ) {
			return array( 'code' => 'SUBSCRIPTION_' . strtoupper( str_replace( '-', '_', $subscription->get_status() ) ), 'retry_at' => 0 );
		}

		$auth = P2Flux_WC_Auth_History::active( $subscription );
		if ( ! $auth ) {
			return array( 'code' => 'NO_AUTHORIZATION', 'retry_at' => 0 );
		}

		if ( (int) $order->get_id() === $subscription->get_parent_id() ) {
			return self::activation_gate( $subscription, $order, $auth, $expected );
		}

		$due = (int) $order->get_meta( self::DUE_META );
		if ( $due < 1 ) {
			return array( 'code' => 'NO_CYCLE', 'retry_at' => 0 );
		}

		$allowed = self::period_at( $auth, $due );
		if ( $expected < $allowed ) {
			return array( 'code' => 'CYCLE_NOT_OPEN', 'retry_at' => (int) $auth['start'] + $allowed * (int) $auth['period'] + self::grace( (int) $auth['period'] ) );
		}
		if ( $expected > $allowed ) {
			self::after_missed( $subscription, $order );

			return array( 'code' => 'CYCLE_PERIOD_PASSED', 'retry_at' => 0 );
		}

		return true;
	}

	/**
	 * The signup's window: the period the authorization was created in, and at most 24 hours.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param WC_Order                      $order        Parent order.
	 * @param array                         $auth         Active authorization.
	 * @param int                           $expected     Period the clock names.
	 * @return true|array{code:string,retry_at:int}
	 */
	private static function activation_gate( $subscription, $order, array $auth, $expected ) {
		if ( ! $subscription->has_status( P2Flux_WC_Native_Subscription::PENDING ) ) {
			// Active already: the parent order was paid, and a second first charge is not a thing.
			return array( 'code' => 'ALREADY_ACTIVATED', 'retry_at' => 0 );
		}

		$allowed  = $subscription->get( 'activation_period' );
		$deadline = $subscription->timestamp( 'activation_deadline' );
		if ( null === $allowed || '' === $allowed || $deadline < 1 ) {
			return array( 'code' => 'NOT_ACTIVATED', 'retry_at' => 0 );
		}
		$allowed = (int) $allowed;

		if ( time() > $deadline || $expected > $allowed ) {
			self::expire( $subscription, __( 'The signup window closed before the first payment could be collected. No payment will be attempted for this signup.', 'p2flux-for-woocommerce' ) );

			return array( 'code' => 'ACTIVATION_EXPIRED', 'retry_at' => 0 );
		}
		if ( $expected < $allowed ) {
			return array( 'code' => 'CYCLE_NOT_OPEN', 'retry_at' => (int) $auth['start'] + $allowed * (int) $auth['period'] + self::grace( (int) $auth['period'] ) );
		}

		return true;
	}

	/**
	 * When to retry this decision: the dunning ladder, clamped to the end of the period the order may
	 * use. A retry that lands past the boundary still runs - it hits the gate, sends nothing, and
	 * resolves the miss on time.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param WC_Order                      $order        Order.
	 * @param array                         $decision     Decision.
	 * @return int|null
	 */
	public static function retry_delay( $subscription, $order, array $decision ) {
		$auth = P2Flux_WC_Auth_History::active( $subscription );
		if ( ! $auth ) {
			return null;
		}

		$delay = (int) $decision['delay'];
		if ( 'recharge' === $decision['schedule'] && P2Flux_WC_Collection::DUNNING === $decision['collection'] ) {
			$n     = max( 1, P2Flux_WC_Collection::attempts( $subscription, 'dunning' ) );
			$delay = self::DUNNING_LADDER[ min( $n, count( self::DUNNING_LADDER ) ) - 1 ];
		}

		if ( (int) $order->get_id() === $subscription->get_parent_id() ) {
			$end = $subscription->timestamp( 'activation_deadline' );
		} else {
			$due = (int) $order->get_meta( self::DUE_META );
			$end = $due > 0 ? self::period_end( $auth, self::period_at( $auth, $due ) ) : 0;
		}
		if ( $end > 0 ) {
			$delay = min( $delay, max( 0, $end + self::grace( (int) $auth['period'] ) - time() ) );
		}

		// Never faster than a minute in production; under the short test fixture, the floor follows
		// the period so a retry can still land inside it.
		$floor = (int) $auth['period'] < 300 ? self::grace( (int) $auth['period'] ) : 60;

		return max( $floor, $delay );
	}

	/**
	 * An authorization was stored: fix the signup window, once, immutably.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription (fresh, under the lock).
	 * @param array                         $status       Status response: terms.start, terms.period.
	 * @return void
	 */
	public static function after_activated( $subscription, array $status ) {
		if ( ! $subscription->has_status( P2Flux_WC_Native_Subscription::PENDING ) || '' !== (string) $subscription->get( 'activation_period' ) ) {
			return;
		}

		$terms  = isset( $status['terms'] ) && is_array( $status['terms'] ) ? $status['terms'] : array();
		$start  = isset( $terms['start'] ) ? (int) $terms['start'] : time();
		$period = isset( $terms['period'] ) ? (int) $terms['period'] : self::contract_period( $subscription );
		if ( $period < 1 ) {
			return;
		}

		$now      = time();
		$index    = $now < $start ? 0 : intdiv( $now - $start, $period );
		$deadline = min( $start + self::activation_ttl( $subscription ), $start + ( $index + 1 ) * $period );

		$subscription->set( 'activation_period', $index );
		$subscription->set_timestamp( 'activation_deadline', $deadline );
		$subscription->save();

		$parent = wc_get_order( $subscription->get_parent_id() );
		if ( $parent ) {
			$parent->update_meta_data( self::DUE_META, $start + $index * $period );
			$parent->update_meta_data( self::CYCLE_META, 0 );
			$parent->save();
		}
	}

	/**
	 * A settlement landed on an order: move the schedule on.
	 *
	 * Idempotent per cycle. Never rewinds `next_payment_at`.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param WC_Order                      $order        Paid order.
	 * @return void
	 */
	public static function after_paid( $subscription, $order ) {
		$auth = P2Flux_WC_Auth_History::get( $subscription, (string) $order->get_meta( '_p2flux_auth_id' ) );
		if ( ! $auth ) {
			$auth = P2Flux_WC_Auth_History::active( $subscription );
		}

		if ( (int) $order->get_id() === $subscription->get_parent_id() ) {
			if ( ! $subscription->has_status( P2Flux_WC_Native_Subscription::PENDING ) || ! $auth ) {
				return;
			}
			$paid_period = (int) $order->get_meta( '_p2flux_period_index' );
			$anchor      = (int) $auth['start'] + $paid_period * (int) $auth['period'];

			$subscription->set_timestamp( 'schedule_anchor', $anchor );
			$subscription->set( 'cycle', 0 );
			$subscription->set( 'current_renewal_order_id', 0 );
			$subscription->set( 'status', P2Flux_WC_Native_Subscription::ACTIVE );
			$subscription->set_timestamp( 'next_payment_at', self::due( $subscription, 1 ) );
			$subscription->save();
			P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::NORMAL, array( 'renewal_order_id' => 0 ) );
			$subscription->add_order_note( __( 'First payment settled; the subscription is active.', 'p2flux-for-woocommerce' ) );
			self::schedule_next( $subscription );

			return;
		}

		$cycle = (int) $order->get_meta( self::CYCLE_META );
		if ( $cycle < 1 || $cycle <= (int) $subscription->get( 'cycle' ) ) {
			return;
		}
		if ( $subscription->has_status( array( P2Flux_WC_Native_Subscription::CANCELLED, P2Flux_WC_Native_Subscription::EXPIRED ) ) ) {
			// The money is recorded on the order; the subscription's decision stands. Nothing here
			// may reopen a schedule or overwrite the cancellation's own state.
			if ( (int) $subscription->get( 'current_renewal_order_id' ) === (int) $order->get_id() ) {
				$subscription->set( 'current_renewal_order_id', 0 );
				$subscription->save();
			}

			return;
		}

		$subscription->set( 'cycle', $cycle );
		$subscription->set( 'current_renewal_order_id', 0 );
		if ( $subscription->has_status( P2Flux_WC_Native_Subscription::ON_HOLD ) ) {
			$subscription->set( 'status', P2Flux_WC_Native_Subscription::ACTIVE );
		}
		self::advance( $subscription, time() );
		$subscription->save();
		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::NORMAL, array( 'renewal_order_id' => 0 ) );
		self::schedule_next( $subscription );
	}

	/**
	 * A renewal could not be collected for a reason the customer must fix: on hold, until an eligible
	 * payment succeeds or somebody cancels. A signup stays pending inside its window.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param WC_Order                      $order        Order.
	 * @return void
	 */
	public static function after_failed( $subscription, $order ) {
		unset( $order );
		if ( $subscription->has_status( P2Flux_WC_Native_Subscription::ACTIVE ) ) {
			$subscription->set( 'status', P2Flux_WC_Native_Subscription::ON_HOLD );
			$subscription->save();
		}
	}

	/**
	 * A renewal's period passed without settlement.
	 *
	 * Idempotent per order, and a no-op while a charge sent for it may still settle.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param WC_Order                      $order        Order.
	 * @return void
	 */
	public static function after_missed( $subscription, $order ) {
		if ( $order->is_paid() || $order->get_meta( self::MISSED_META ) || self::is_reconciling( $order ) ) {
			return;
		}

		if ( (int) $order->get_id() === $subscription->get_parent_id() ) {
			self::expire( $subscription, __( 'The signup window closed before the first payment could be collected. No payment will be attempted for this signup.', 'p2flux-for-woocommerce' ) );

			return;
		}

		$attempted = '' !== (string) $order->get_meta( '_p2flux_charge_attempts' );
		$order->update_meta_data( self::MISSED_META, 1 );
		$order->add_order_note(
			$attempted
				? __( 'P2Flux: this renewal was not collected and its billing period has passed. It will not be collected automatically later; it can still be paid from this order’s page.', 'p2flux-for-woocommerce' )
				: __( 'P2Flux: this renewal’s billing period passed before the store could attempt it. It will not be collected later.', 'p2flux-for-woocommerce' )
		);
		if ( in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
			$order->set_status( 'failed' );
		}
		$order->save();

		if ( $attempted ) {
			$subscription->set( 'missed_cycles', (int) $subscription->get( 'missed_cycles' ) + 1 );
		}
		if ( (int) $subscription->get( 'current_renewal_order_id' ) === (int) $order->get_id() ) {
			$subscription->set( 'current_renewal_order_id', 0 );
		}
		$cycle = (int) $order->get_meta( self::CYCLE_META );
		if ( $cycle > (int) $subscription->get( 'cycle' ) ) {
			$subscription->set( 'cycle', $cycle );
		}
		if ( $subscription->has_status( P2Flux_WC_Native_Subscription::ACTIVE ) ) {
			$subscription->set( 'status', P2Flux_WC_Native_Subscription::ON_HOLD );
		}
		$subscription->save();
		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::NORMAL, array( 'renewal_order_id' => 0 ) );

		if ( $attempted && class_exists( 'P2Flux_WC_Native_Emails' ) ) {
			P2Flux_WC_Native_Emails::action_required( $subscription, $order, 'missed' );
		}

		self::resume( $subscription );
	}

	/**
	 * Drop the subscription's job.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function unschedule( $subscription ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( $subscription->get_id() ), self::GROUP );
		}
	}

	/**
	 * Schedule the job for `next_payment_at`, once.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function schedule_next( $subscription ) {
		$at = $subscription->timestamp( 'next_payment_at' );
		if ( $at < 1 || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( ! $subscription->has_status( array( P2Flux_WC_Native_Subscription::ACTIVE, P2Flux_WC_Native_Subscription::ON_HOLD ) ) ) {
			return;
		}
		$args = array( $subscription->get_id() );
		if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( max( time(), $at + self::grace( self::contract_period( $subscription ) ) ), self::HOOK, $args, self::GROUP );
	}

	/*
	 * ---- The job ----
	 */

	/**
	 * A renewal is due, or was.
	 *
	 * @param int $id Native subscription id.
	 * @return void
	 */
	public static function renewal( $id ) {
		$key   = P2Flux_WC_Subscriptions::lock_key( 'native:' . (int) $id );
		$token = P2Flux_WC_Lock::acquire( $key );
		if ( false === $token ) {
			self::reschedule( (int) $id, 60 );

			return;
		}

		$order_id = 0;
		try {
			$order_id = self::prepare_due_renewal( (int) $id );
		} catch ( \Throwable $e ) {
			P2Flux_WC_Logger::error( 'native renewal job failed', array( 'subscription' => (int) $id, 'error' => $e->getMessage() ) );
		} finally {
			P2Flux_WC_Lock::release( $key, $token );
		}

		if ( $order_id > 0 ) {
			// The charger takes its own lock; a lock held across the network call is the charger's job.
			$outcome = P2Flux_WC_Charger::collect( 'native:' . (int) $id, $order_id );
			if ( 'busy' === $outcome['status'] ) {
				self::reschedule( (int) $id, 60 );
			}
		}
	}

	/**
	 * Under the lock: decide whether a renewal is due now, and which order it is.
	 *
	 * @param int $id Native id.
	 * @return int Order id to charge, or 0.
	 */
	private static function prepare_due_renewal( $id ) {
		$subscription = P2Flux_WC_Native_Subscription::load( $id );
		if ( ! $subscription || ! $subscription->has_status( array( P2Flux_WC_Native_Subscription::ACTIVE, P2Flux_WC_Native_Subscription::ON_HOLD ) ) ) {
			return 0;
		}

		$next = $subscription->timestamp( 'next_payment_at' );
		if ( $next < 1 || $next > time() ) {
			// Stale job: the schedule moved on since it was queued.
			self::schedule_next( $subscription );

			return 0;
		}

		$auth = P2Flux_WC_Auth_History::active( $subscription );
		if ( ! $auth ) {
			return 0;
		}

		// The cycle this due date belongs to, and whether its period is still the current one.
		$cycle = (int) $subscription->get( 'cycle' ) + 1;
		if ( self::period_at( $auth, $next ) < self::period_at( $auth, time() ) ) {
			// Late: that period is gone. Resolve the miss and look for a currently eligible cycle.
			$missed = self::renewal_order_for( $subscription, $cycle, $next, false );
			if ( $missed ) {
				self::after_missed( $subscription, $missed );
			} else {
				self::resume( $subscription );
			}
			$subscription = P2Flux_WC_Native_Subscription::load( $id );
			$next         = $subscription ? $subscription->timestamp( 'next_payment_at' ) : 0;
			if ( ! $subscription || $next < 1 || $next > time() ) {
				return 0;
			}
			$cycle = (int) $subscription->get( 'cycle' ) + 1;
		}

		$order = self::renewal_order_for( $subscription, $cycle, $next, true );

		return $order ? $order->get_id() : 0;
	}

	/**
	 * The renewal order for a cycle: the one already created, or a new one.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param int                           $cycle        Cycle.
	 * @param int                           $due          Due instant.
	 * @param bool                          $create       Create when missing.
	 * @return WC_Order|null
	 */
	private static function renewal_order_for( $subscription, $cycle, $due, $create ) {
		$current = (int) $subscription->get( 'current_renewal_order_id' );
		if ( $current > 0 ) {
			$existing = wc_get_order( $current );
			if ( $existing && (int) $existing->get_meta( self::CYCLE_META ) === (int) $cycle ) {
				return $existing;
			}
		}
		if ( ! $create ) {
			return null;
		}

		$order = self::create_renewal_order( $subscription, $cycle, $due );
		if ( ! $order ) {
			return null;
		}

		$subscription->set( 'current_renewal_order_id', $order->get_id() );
		$subscription->save();

		return $order;
	}

	/**
	 * An ordinary WooCommerce order for one renewal: same customer, same product, the authorized
	 * amount, nothing recalculated.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param int                           $cycle        Cycle.
	 * @param int                           $due          Due instant.
	 * @return WC_Order|null
	 */
	public static function create_renewal_order( $subscription, $cycle, $due ) {
		$parent = wc_get_order( $subscription->get_parent_id() );
		$order  = wc_create_order(
			array(
				'customer_id' => $subscription->get_user_id(),
				'created_via' => 'p2flux_native_renewal',
				'status'      => 'pending',
				'parent'      => $subscription->get_parent_id(),
			)
		);
		if ( ! $order || is_wp_error( $order ) ) {
			P2Flux_WC_Logger::error( 'could not create a renewal order', array( 'subscription' => $subscription->get_id() ) );

			return null;
		}

		$amount  = (string) $subscription->get_total();
		$product = wc_get_product( (int) $subscription->get( 'product_id' ) );
		$item    = new WC_Order_Item_Product();
		if ( $product ) {
			$item->set_product( $product );
		}
		$item->set_name( '' !== (string) $subscription->get( 'product_name' ) ? (string) $subscription->get( 'product_name' ) : ( $product ? $product->get_name() : __( 'Subscription', 'p2flux-for-woocommerce' ) ) );
		$item->set_quantity( 1 );
		$item->set_subtotal( $amount );
		$item->set_total( $amount );
		$item->set_tax_class( '' );
		$order->add_item( $item );

		if ( $parent ) {
			$order->set_props( array_combine( array_map( static function ( $k ) { return 'billing_' . $k; }, array_keys( $parent->get_address( 'billing' ) ) ), array_values( $parent->get_address( 'billing' ) ) ) );
			$order->set_currency( $parent->get_currency() );
		}
		$order->set_payment_method( 'p2flux' );
		$order->set_payment_method_title( __( 'Pay with USDC', 'p2flux-for-woocommerce' ) );
		$order->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $subscription->get_id() );
		$order->update_meta_data( self::CYCLE_META, (int) $cycle );
		$order->update_meta_data( self::DUE_META, (int) $due );
		$order->update_meta_data( '_p2flux_env', (string) $subscription->get( 'env' ) );
		$order->update_meta_data( '_p2flux_recipient', (string) $subscription->get( 'recipient' ) );
		$order->update_meta_data( '_p2flux_units', (int) $subscription->get( 'amount_units' ) );
		$order->update_meta_data( '_p2flux_rate', '1' );
		$order->calculate_totals( false );
		$order->save();

		$expected = (int) $subscription->get( 'amount_units' );
		if ( P2Flux_WC_Money::to_units( $order->get_total(), '1' ) !== $expected ) {
			$order->update_meta_data( '_p2flux_needs_attention', 1 );
			$order->add_order_note( __( 'P2Flux: this renewal order’s total does not equal the authorized amount, so it will not be charged. Please review.', 'p2flux-for-woocommerce' ) );
			$order->save();
			P2Flux_WC_Logger::error( 'renewal order total differs from the authorized amount', array( 'order' => $order->get_id() ) );

			return null;
		}

		$order->add_order_note( sprintf( __( 'P2Flux subscription #%1$d, renewal %2$d.', 'p2flux-for-woocommerce' ), $subscription->get_id(), (int) $cycle ) );
		$order->save();

		return $order;
	}

	/*
	 * ---- Resuming after a miss or downtime: at most one charge, never a burst ----
	 */

	/**
	 * Move `next_payment_at` to the first due instant after now, from the cycles resolved so far.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param int                           $now          Moment.
	 * @return void
	 */
	private static function advance( $subscription, $now ) {
		$cycle = (int) $subscription->get( 'cycle' );
		$next  = $cycle + 1;
		while ( self::due( $subscription, $next ) <= $now ) {
			$next++;
		}
		if ( $next - 1 > $cycle ) {
			$subscription->set( 'cycle', $next - 1 );
		}
		$subscription->set_timestamp( 'next_payment_at', self::due( $subscription, $next ) );
	}

	/**
	 * After a miss or downtime: exactly one currently eligible cycle may be attempted; every older
	 * one is recorded as skipped.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function resume( $subscription ) {
		if ( ! $subscription->has_status( array( P2Flux_WC_Native_Subscription::ACTIVE, P2Flux_WC_Native_Subscription::ON_HOLD ) ) ) {
			return;
		}
		$auth = P2Flux_WC_Auth_History::active( $subscription );
		if ( ! $auth ) {
			return;
		}

		$now      = time();
		$resolved = (int) $subscription->get( 'cycle' );
		$latest   = self::latest_cycle_at( $subscription, $now );

		if ( $latest > $resolved ) {
			$due      = self::due( $subscription, $latest );
			$eligible = self::period_at( $auth, $due ) === self::period_at( $auth, $now );
			$skipped  = $latest - $resolved - ( $eligible ? 1 : 0 );

			if ( $skipped > 0 ) {
				$subscription->add_order_note( sprintf( _n( '%d billing cycle passed while it could not be collected; it will not be collected later.', '%d billing cycles passed while they could not be collected; they will not be collected later.', $skipped, 'p2flux-for-woocommerce' ), $skipped ) );
				$subscription->set( 'missed_cycles', (int) $subscription->get( 'missed_cycles' ) + $skipped );
			}

			if ( $eligible ) {
				// The one cycle that may still be collected: due now, its period is the current one.
				$subscription->set( 'cycle', $latest - 1 );
				$subscription->set_timestamp( 'next_payment_at', $due );
				$subscription->save();
				self::schedule_now( $subscription );

				return;
			}

			$subscription->set( 'cycle', $latest );
		}

		self::advance( $subscription, $now );
		$subscription->save();
		self::schedule_next( $subscription );
	}

	/**
	 * Signups whose window closed and schedules that lost their job. Called from the daily sweep and
	 * on plugin (re)activation.
	 *
	 * @return void
	 */
	public static function sweep() {
		foreach ( P2Flux_WC_Native_Subscription::due_before( time() - HOUR_IN_SECONDS ) as $subscription ) {
			if ( $subscription->has_status( P2Flux_WC_Native_Subscription::PENDING ) ) {
				$parent = wc_get_order( $subscription->get_parent_id() );
				if ( $parent && ! $parent->is_paid() && ! self::is_reconciling( $parent ) ) {
					self::expire( $subscription, __( 'The signup window closed before the first payment could be collected. No payment will be attempted for this signup.', 'p2flux-for-woocommerce' ) );
				}
				continue;
			}

			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array( $subscription->get_id() ), self::GROUP ) ) {
				continue;
			}
			self::schedule_now( $subscription );
		}
	}

	/**
	 * A signup that will never activate.
	 *
	 * The authorization history stays: the customer may still revoke an unused authorization from
	 * their account, and support may need the id. Only the setup session is discarded.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param string                        $note         Why.
	 * @return void
	 */
	public static function expire( $subscription, $note ) {
		if ( ! $subscription->has_status( P2Flux_WC_Native_Subscription::PENDING ) ) {
			return;
		}

		$subscription->set( 'status', P2Flux_WC_Native_Subscription::EXPIRED );
		$subscription->set_timestamp( 'next_payment_at', 0 );
		$subscription->delete_meta_data( '_p2flux_pending_setup' );
		$subscription->save();
		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::CANCELLED, array( 'reason' => 'activation_expired' ) );
		$subscription->add_order_note( $note );
		P2Flux_WC_Jobs::unschedule_subscription( $subscription );

		$parent = wc_get_order( $subscription->get_parent_id() );
		if ( $parent && ! $parent->is_paid() && in_array( $parent->get_status(), array( 'pending', 'failed', 'on-hold' ), true ) ) {
			$parent->update_status( 'cancelled', __( 'P2Flux: the subscription signup expired before its first payment was collected.', 'p2flux-for-woocommerce' ) );
		}
	}

	/*
	 * ---- Small helpers ----
	 */

	/**
	 * A charge sent for this order may still settle.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_reconciling( $order ) {
		if ( $order->get_meta( '_p2flux_reconciling' ) ) {
			return true;
		}
		foreach ( P2Flux_WC_Periods::for_order( $order->get_id() ) as $row ) {
			if ( in_array( $row['state'], array( P2Flux_WC_Periods::CHARGING, P2Flux_WC_Periods::RECONCILING ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Run the job as soon as possible.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	private static function schedule_now( $subscription ) {
		self::reschedule( $subscription->get_id(), 0 );
	}

	/**
	 * @param int $id    Native id.
	 * @param int $delay Seconds.
	 * @return void
	 */
	private static function reschedule( $id, $delay ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = array( (int) $id );
		if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + max( 0, (int) $delay ), self::HOOK, $args, self::GROUP );
	}
}
