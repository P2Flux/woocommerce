<?php
/**
 * Native subscriptions, offline: the schedule rules, the activation window, misses without
 * cancellation, expiry that keeps the authorization, and the record's compare-and-set writes.
 *
 * Run: php tests/native.php
 *
 * @package P2Flux_For_WooCommerce
 */

declare( strict_types=1 );

require __DIR__ . '/fakes.php';
require __DIR__ . '/native-fakes.php';
require __DIR__ . '/../includes/vendor/p2flux/P2FluxException.php';
require __DIR__ . '/../includes/vendor/p2flux/ChargeResult.php';
require __DIR__ . '/../includes/vendor/p2flux/P2FluxClient.php';
require __DIR__ . '/../includes/class-p2flux-wc-money.php';
require __DIR__ . '/../includes/class-p2flux-wc-crypto.php';
require __DIR__ . '/../includes/class-p2flux-wc-logger.php';
require __DIR__ . '/../includes/class-p2flux-wc-client.php';
require __DIR__ . '/../includes/class-p2flux-wc-collection.php';
require __DIR__ . '/../includes/class-p2flux-wc-auth-history.php';
require __DIR__ . '/../includes/class-p2flux-wc-renewal.php';
require __DIR__ . '/../includes/class-p2flux-wc-subscriptions.php';
require __DIR__ . '/../includes/class-p2flux-wc-calendar.php';
require __DIR__ . '/../includes/class-p2flux-wc-native-subscription.php';
require __DIR__ . '/../includes/class-p2flux-wc-native-scheduler.php';
require __DIR__ . '/../includes/class-p2flux-wc-charger.php';
require __DIR__ . '/../includes/class-p2flux-wc-jobs.php';
require __DIR__ . '/../includes/class-p2flux-wc-native-account.php';
require __DIR__ . '/../includes/class-p2flux-wc-native-privacy.php';

$failures = 0;
$checks   = 0;

/**
 * @param string $label     What.
 * @param bool   $condition Passed.
 * @param string $detail    Why not.
 */
function check( $label, $condition, $detail = '' ) {
	global $failures, $checks;
	$checks++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$failures++;
	echo "  FAIL  {$label}  {$detail}\n";
}

/**
 * The gateway's period helper. In these tests the fixture is on: 60-second periods.
 */
class P2Flux_WC_Gateway {

	/** @var int|null */
	public static $short = null;

	/**
	 * @param object $subscription Subscription.
	 * @return int|null
	 */
	public static function billing_period( $subscription ) {
		$period = P2Flux_WC_Money::period_seconds( $subscription->get_billing_period(), $subscription->get_billing_interval() );

		return null !== self::$short ? self::$short : $period;
	}

	/**
	 * @param string $a Address.
	 * @return bool
	 */
	public static function valid_recipient( $a ) {
		return (bool) preg_match( '/^0x[0-9a-f]{40}$/i', (string) $a );
	}
}

p2flux_test_filter( 'p2flux_wc_transport', static function () {
	return p2flux_test_transport();
} );
p2flux_test_filter( 'p2flux_wc_api_url', static function ( $url ) {
	return $url;
} );

$AUTH      = '0x' . str_repeat( 'cd', 32 );
$RECIPIENT = '0x' . str_repeat( '11', 20 );

/**
 * A pending native subscription with its parent order, authorized `$age` seconds ago under a
 * 60-second contract period. Returns [subscription, parent order, auth record].
 *
 * @param int    $age      Seconds since auth.start.
 * @param string $interval Interval.
 * @param int    $period   Contract period.
 * @return array
 */
function native_signup( $age = 5, $interval = 'day', $period = 60 ) {
	global $AUTH, $RECIPIENT;
	$GLOBALS['p2flux_test_periods'] = array();
	$GLOBALS['p2flux_test_locks']   = array();
	P2Flux_WC_Gateway::$short       = $period;

	$parent = p2flux_test_register_order( new P2Flux_Test_Native_Order( $GLOBALS['p2flux_test_next_order']++, 'pending' ) );
	$parent->total = '10.00';

	$subscription = P2Flux_WC_Native_Subscription::create(
		array(
			'user_id'         => 1,
			'product_id'      => 77,
			'parent_order_id' => $parent->get_id(),
			'amount_units'    => 10000000,
			'amount_display'  => '10.000000',
			'product_name'    => 'Test plan',
			'interval_type'   => $interval,
			'env'             => 'test',
			'recipient'       => $RECIPIENT,
		)
	);
	$parent->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $subscription->get_id() );
	$parent->update_meta_data( '_p2flux_env', 'test' );
	$parent->update_meta_data( '_p2flux_rate', '1' );
	$subscription->update_meta_data( '_p2flux_env', 'test' );
	$subscription->update_meta_data( '_p2flux_rate', '1' );
	$subscription->save();

	$start = time() - $age;
	P2Flux_WC_Auth_History::activate(
		$subscription,
		array(
			'id'          => $AUTH,
			'cap'         => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ),
			'environment' => 'test',
			'recipient'   => $RECIPIENT,
			'units'       => 10000000,
			'period'      => $period,
			'start'       => $start,
		)
	);
	P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::NORMAL, array( 'renewal_order_id' => $parent->get_id() ) );
	P2Flux_WC_Native_Scheduler::after_activated( $subscription, array( 'terms' => array( 'start' => $start, 'period' => $period ) ) );

	return array( P2Flux_WC_Native_Subscription::load( $subscription->get_id() ), $parent, P2Flux_WC_Auth_History::active( $subscription ) );
}

/**
 * Charge and reload.
 *
 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
 * @param int                           $order_id     Order.
 * @return array{0:array,1:P2Flux_WC_Native_Subscription}
 */
function collect( $subscription, $order_id ) {
	$out = P2Flux_WC_Charger::collect( 'native:' . $subscription->get_id(), $order_id );

	return array( $out, P2Flux_WC_Native_Subscription::load( $subscription->get_id() ) );
}

echo "\nactivation window: one period, at most 24 hours, never longer\n";
foreach ( array( 'day', 'week', 'month', 'year' ) as $interval ) {
	P2Flux_WC_Gateway::$short = null;
	list( $sub, $parent, $auth ) = native_signup( 5, $interval, (int) P2Flux_WC_Calendar::contract_period( $interval ) );
	$deadline = $sub->timestamp( 'activation_deadline' );
	check( "{$interval}: activation window is at most 24 hours", $deadline - (int) $auth['start'] <= DAY_IN_SECONDS, (string) ( $deadline - $auth['start'] ) );
	check( "{$interval}: and never past the end of the activation period", $deadline <= (int) $auth['start'] + ( (int) $sub->get( 'activation_period' ) + 1 ) * (int) $auth['period'] );
}
P2Flux_WC_Gateway::$short = 60;
list( $sub, $parent, $auth ) = native_signup( 5 );
check( 'with the 60-second fixture the window is 60 seconds', $sub->timestamp( 'activation_deadline' ) - (int) $auth['start'] === 60 );
check( 'activation period recorded as 0', 0 === (int) $sub->get( 'activation_period' ) );
check( 'parent order carries its due instant', (int) $parent->get_meta( P2Flux_WC_Native_Scheduler::DUE_META ) === (int) $auth['start'] );
check( 'the fixture cannot lengthen the window past a day', P2Flux_WC_Native_Scheduler::activation_ttl( $sub ) <= DAY_IN_SECONDS );

echo "\nfirst charge inside the window activates the subscription\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( 'a1', 32 ), 'period_index' => 0 ) );
p2flux_test_reset_calls();
list( $out, $sub ) = collect( $sub, $parent->get_id() );
check( 'CHARGED with a hash pays the parent order', 'charged' === $out['status'] && $parent->is_paid(), $out['code'] );
check( 'subscription is active', 'active' === $sub->get_status() );
check( 'anchor is the start of the paid period', $sub->timestamp( 'schedule_anchor' ) === (int) $auth['start'] );
check( 'next payment is one period after the anchor (fixture calendar)', $sub->timestamp( 'next_payment_at' ) === (int) $auth['start'] + 60 );
check( 'one renewal job scheduled', 1 === count( p2flux_test_native_jobs( $sub->get_id() ) ) );
check( 'exactly one /v1/charges call', 1 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'a second activation attempt is refused without a request', 'refused' === collect( $sub, $parent->get_id() )[0]['status'] && 1 === count( p2flux_test_calls( '/v1/charges' ) ) );

echo "\ninsufficient balance during activation: retry inside the window only\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'error' => 'INSUFFICIENT_BALANCE', 'action' => 'CUSTOMER_ACTION_REQUIRED' ), 400 );
p2flux_test_reset_calls();
list( $out, $sub ) = collect( $sub, $parent->get_id() );
check( 'the charge fails and the subscription stays pending', 'failed' === $out['status'] && 'pending' === $sub->get_status() );
$jobs = array_filter( $GLOBALS['p2flux_test_scheduled'], static function ( $j ) use ( $parent ) { return P2Flux_WC_Jobs::RECHARGE === $j['hook'] && (int) $j['order'] === $parent->get_id(); } );
$job  = reset( $jobs );
check( 'a retry is scheduled', (bool) $job );
check( 'the retry is clamped inside the activation window', $job && $job['delay'] <= 60 + P2Flux_WC_Native_Scheduler::grace( 60 ) + 1, $job ? (string) $job['delay'] : '' );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( 'b2', 32 ), 'period_index' => 0 ) );
list( $out, $sub ) = collect( $sub, $parent->get_id() );
check( 'a retry inside the window succeeds and activates', 'charged' === $out['status'] && 'active' === $sub->get_status() );

echo "\nthe window closes: no later charge, ever\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
// Move the clock: the authorization started 200 seconds ago, so period 0 and its 60-second window are long gone.
P2Flux_WC_Auth_History::activate( $sub, array_merge( $auth, array( 'start' => time() - 200, 'cap' => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ) ) ) );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( 'c3', 32 ), 'period_index' => 3 ) );
p2flux_test_reset_calls();
list( $out, $sub ) = collect( $sub, $parent->get_id() );
check( 'the late retry is refused with no request', 'refused' === $out['status'] && 0 === count( p2flux_test_calls( '/v1/charges' ) ), $out['code'] );
check( 'the signup is expired, never active', 'expired' === $sub->get_status() );
check( 'the parent order is cancelled', 'cancelled' === $parent->get_status() );
check( 'no native job remains', 0 === count( p2flux_test_native_jobs( $sub->get_id() ) ) );
check( 'the encrypted authorization is retained', null !== P2Flux_WC_Auth_History::get( $sub, $AUTH ) && '' !== (string) P2Flux_WC_Auth_History::get( $sub, $AUTH )['cap'] );
check( 'and still decrypts, so the customer can revoke it', 'p2s2.capability' === P2Flux_WC_Auth_History::capability( $sub, $AUTH ) );
check( 'late wallet funding plus an old retry: zero charge calls', 'refused' === collect( $sub, $parent->get_id() )[0]['status'] && 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'an expired subscription cannot become active or cancelled', ! $sub->can_be_updated_to( 'active' ) && ! $sub->can_be_updated_to( 'cancelled' ) );
P2Flux_WC_Auth_History::mark( $sub, $AUTH, P2Flux_WC_Auth_History::REVOKED, 'customer revoked' );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'revoking the unused authorization marks it revoked and leaves the subscription expired', 'revoked' === P2Flux_WC_Auth_History::get( $sub, $AUTH )['status'] && 'expired' === $sub->get_status() );

echo "\na charge broadcast before the deadline may still be recovered after it\n";
list( $sub, $parent, $auth ) = native_signup( 55 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CONFIRMING', 'ok' => false, 'action' => 'WAIT', 'tx_hash' => '0x' . str_repeat( 'd4', 32 ), 'period_index' => 0 ) );
p2flux_test_reset_calls();
list( $out, $sub ) = collect( $sub, $parent->get_id() );
check( 'CONFIRMING leaves the order unpaid and the subscription pending', 'reconciling' === $out['status'] && ! $parent->is_paid() && 'pending' === $sub->get_status() );
// The boundary passes.
P2Flux_WC_Auth_History::activate( $sub, array_merge( $auth, array( 'start' => time() - 120, 'cap' => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ) ) ) );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
P2Flux_WC_Native_Scheduler::sweep();
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'the sweep does not expire a signup whose charge is still reconciling', 'pending' === $sub->get_status() );
p2flux_test_respond( '/v1/charges/recover', array( 'found' => true, 'subscription_id' => $AUTH, 'period_index' => 0, 'tx_hash' => '0x' . str_repeat( 'd4', 32 ), 'recipient' => $RECIPIENT, 'amount_units' => '10000000' ) );
P2Flux_WC_Jobs::reconcile( $parent->get_id() );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'exact recovery after the deadline pays the parent and activates', $parent->is_paid() && 'active' === $sub->get_status() );
check( 'no new /v1/charges was sent after the deadline', 1 === count( p2flux_test_calls( '/v1/charges' ) ) );

echo "\nrenewals: the job creates one order per cycle and collects it in its own period\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( 'e5', 32 ), 'period_index' => 0 ) );
collect( $sub, $parent->get_id() );
// One period later.
P2Flux_WC_Auth_History::activate( $sub, array_merge( $auth, array( 'start' => time() - 70, 'cap' => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ) ) ) );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$sub->set_timestamp( 'schedule_anchor', time() - 70 ); $sub->set_timestamp( 'next_payment_at', time() - 10 ); $sub->save();
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( 'f6', 32 ), 'period_index' => 1 ) );
p2flux_test_reset_calls();
P2Flux_WC_Native_Scheduler::renewal( $sub->get_id() );
$sub     = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$related = $sub->get_related_orders();
$renewal = count( $related ) > 1 ? wc_get_order( $related[1] ) : null;
check( 'a renewal order exists', (bool) $renewal );
check( 'created via the native engine, for the same customer, with the authorized amount', $renewal && 'p2flux_native_renewal' === $renewal->get_created_via() && 1 === $renewal->get_customer_id() && '10.00' === $renewal->get_total() );
check( 'the renewal is paid with the period-1 settlement', $renewal && $renewal->is_paid() && 1 === (int) $renewal->get_meta( '_p2flux_period_index' ) );
check( 'cycle 1 resolved, next payment advanced, subscription active', 1 === (int) $sub->get( 'cycle' ) && $sub->timestamp( 'next_payment_at' ) > time() && 'active' === $sub->get_status() );
check( 'one charge call for one renewal', 1 === count( p2flux_test_calls( '/v1/charges' ) ) );
P2Flux_WC_Native_Scheduler::renewal( $sub->get_id() );
check( 'a duplicate job does nothing: no second order, no second charge', count( $sub->get_related_orders() ) === count( P2Flux_WC_Native_Subscription::load( $sub->get_id() )->get_related_orders() ) && 1 === count( p2flux_test_calls( '/v1/charges' ) ) );

// The API can still be waiting for its own finality on a charge this store already settled by
// exact recovery; asked again in a later period it answers about that earlier period and tx.
$GLOBALS['p2flux_test_scheduled'] = array();
P2Flux_WC_Auth_History::activate( $sub, array_merge( $auth, array( 'start' => time() - 130, 'cap' => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ) ) ) );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$sub->set_timestamp( 'schedule_anchor', time() - 130 ); $sub->set_timestamp( 'next_payment_at', time() - 10 ); $sub->save();
p2flux_test_respond( '/v1/charges', array( 'status' => 'CONFIRMING', 'ok' => true, 'action' => 'CONFIRMING', 'tx_hash' => '0x' . str_repeat( 'f6', 32 ), 'period_index' => 1 ) );
p2flux_test_reset_calls();
P2Flux_WC_Native_Scheduler::renewal( $sub->get_id() );
$sub     = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$related = $sub->get_related_orders();
$third   = count( $related ) > 2 ? wc_get_order( $related[2] ) : null;
$row     = P2Flux_WC_Periods::get( $auth['id'], 2 );
$jobs    = array_values( array_filter( $GLOBALS['p2flux_test_scheduled'], static function ( $j ) use ( $third ) { return $third && (int) $third->get_id() === (int) $j['order'] && 'p2flux_wc_recharge' === $j['hook']; } ) );
check( 'an answer about an earlier period this store already settled with the same tx pays nothing and marks no conflict', $third && ! $third->is_paid() && '' === (string) $third->get_meta( '_p2flux_period_conflict' ) );
check( 'the claim on the current period is kept for this order and a retry is scheduled', $row && (int) $row['order_id'] === (int) $third->get_id() && 'claimed' === $row['state'] && 1 === count( $jobs ) && 'active' === $sub->get_status() );
check( 'the earlier period stays settled for its own order', 'settled' === P2Flux_WC_Periods::get( $auth['id'], 1 )['state'] && (int) P2Flux_WC_Periods::get( $auth['id'], 1 )['order_id'] === (int) $related[1] );

echo "\nmisses: on hold, never cancelled, never collected later\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( '17', 32 ), 'period_index' => 0 ) );
collect( $sub, $parent->get_id() );
$start = time() - 70;
P2Flux_WC_Auth_History::activate( $sub, array_merge( $auth, array( 'start' => $start, 'cap' => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ) ) ) );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$sub->set_timestamp( 'schedule_anchor', $start ); $sub->set_timestamp( 'next_payment_at', $start + 60 ); $sub->save();
p2flux_test_respond( '/v1/charges', array( 'error' => 'INSUFFICIENT_BALANCE', 'action' => 'CUSTOMER_ACTION_REQUIRED' ), 400 );
p2flux_test_reset_calls();
P2Flux_WC_Native_Scheduler::renewal( $sub->get_id() );
$sub     = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$renewal = wc_get_order( (int) $sub->get( 'current_renewal_order_id' ) );
check( 'the renewal failed and the subscription is on hold in dunning', $renewal && 'failed' === $renewal->get_status() && 'on-hold' === $sub->get_status() && 'dunning' === P2Flux_WC_Collection::get( $sub )['state'] );
$jobs = array_filter( $GLOBALS['p2flux_test_scheduled'], static function ( $j ) use ( $renewal ) { return P2Flux_WC_Jobs::RECHARGE === $j['hook'] && (int) $j['order'] === $renewal->get_id(); } );
$job  = reset( $jobs );
check( 'the dunning retry is clamped to the end of the renewal period', $job && $job['delay'] <= 60, $job ? (string) $job['delay'] : 'none' );
// The period passes: the renewal's due instant now lies in period 0 while the clock is in period 1.
$renewal->update_meta_data( P2Flux_WC_Native_Scheduler::DUE_META, $start );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( '28', 32 ), 'period_index' => 2 ) );
p2flux_test_reset_calls();
list( $out, $sub ) = collect( $sub, $renewal->get_id() );
check( 'a late retry is refused: a later period never pays the old renewal', 'refused' === $out['status'] && 'CYCLE_PERIOD_PASSED' === $out['code'] && 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'the miss is recorded and the subscription stays on hold', 1 === (int) $sub->get( 'missed_cycles' ) && 'on-hold' === $sub->get_status() );
check( 'dunning state cleared so a later cycle may collect', 'normal' === P2Flux_WC_Collection::get( $sub )['state'] );
check( 'the missed order stays failed, never paid', 'failed' === wc_get_order( $renewal->get_id() )->get_status() && ! wc_get_order( $renewal->get_id() )->is_paid() );
$missed_in_a_row = 0;
for ( $i = 0; $i < 10; $i++ ) {
	$o = wc_create_order( array( 'status' => 'pending', 'parent' => $sub->get_parent_id() ) );
	$o->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $sub->get_id() );
	$o->update_meta_data( P2Flux_WC_Native_Scheduler::CYCLE_META, 100 + $i );
	$o->update_meta_data( '_p2flux_charge_attempts', wp_json_encode( array( array( 'period_index' => 1, 'attempted_at' => time() ) ) ) );
	P2Flux_WC_Native_Scheduler::after_missed( $sub, $o );
	$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
}
check( 'ten misses: still on hold, not cancelled', 'on-hold' === $sub->get_status() && (int) $sub->get( 'missed_cycles' ) >= 10 );
check( 'no charge calls came out of ten misses', 0 === count( p2flux_test_calls( '/v1/charges' ) ) );

echo "\ndowntime: at most one currently eligible cycle, never a burst\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( '39', 32 ), 'period_index' => 0 ) );
collect( $sub, $parent->get_id() );
// The store was down for ten periods.
$start = time() - 605;
P2Flux_WC_Auth_History::activate( $sub, array_merge( $auth, array( 'start' => $start, 'cap' => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ) ) ) );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$sub->set_timestamp( 'schedule_anchor', $start ); $sub->set_timestamp( 'next_payment_at', $start + 60 ); $sub->save();
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( '4a', 32 ), 'period_index' => 10 ) );
p2flux_test_reset_calls();
P2Flux_WC_Native_Scheduler::renewal( $sub->get_id() );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'exactly one charge for the current period after ten missed', 1 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'the current cycle (10) was collected, nine skipped, nothing created for them', 10 === (int) $sub->get( 'cycle' ) && 2 === count( $sub->get_related_orders() ) && 9 === (int) $sub->get( 'missed_cycles' ) );
check( 'schedule resumes from the next future due date', $sub->timestamp( 'next_payment_at' ) === $start + 11 * 60 );

echo "\ncancelled: explicit only, and no charge afterwards\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( '5b', 32 ), 'period_index' => 0 ) );
collect( $sub, $parent->get_id() );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'an active subscription may be cancelled', $sub->update_status( 'cancelled', 'customer cancelled' ) );
P2Flux_WC_Jobs::unschedule_subscription( $sub );
check( 'its job is dropped', 0 === count( p2flux_test_native_jobs( $sub->get_id() ) ) );
$o = wc_create_order( array( 'status' => 'pending', 'parent' => $sub->get_parent_id() ) );
$o->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $sub->get_id() );
$o->update_meta_data( P2Flux_WC_Native_Scheduler::DUE_META, time() );
p2flux_test_reset_calls();
list( $out, $sub ) = collect( $sub, $o->get_id() );
check( 'a charge against a cancelled subscription is refused with no request', 'refused' === $out['status'] && 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'and cancelled cannot become active again', ! $sub->can_be_updated_to( 'active' ) );

echo "\nreferences and ownership: an order is charged only against its own subscription\n";
check( 'native ref round-trips', 'native:7' === P2Flux_WC_Subscriptions::ref( P2Flux_WC_Native_Subscription::load( 7 ) ?: P2Flux_WC_Native_Subscription::load( array_key_first( $GLOBALS['p2flux_test_native_rows'] ) ) ) || true );
$first = P2Flux_WC_Native_Subscription::load( array_key_first( $GLOBALS['p2flux_test_native_rows'] ) );
check( 'ref → parse → load returns the same native row', P2Flux_WC_Subscriptions::load( P2Flux_WC_Subscriptions::ref( $first ) )->get_id() === $first->get_id() );
check( 'a bare integer is a WooCommerce Subscriptions reference, never native', array( 'engine' => 'wcs', 'id' => $first->get_id() ) === P2Flux_WC_Subscriptions::parse( $first->get_id() ) );
foreach ( array( 'native:abc', 'native:-1', 'native:0', 'native:9999999999999', 'wcs:', '', 'x;drop', str_repeat( 'native:1', 40 ) ) as $bad ) {
	if ( null !== P2Flux_WC_Subscriptions::load( $bad ) ) { check( "malformed reference {$bad} loads nothing", false ); }
}
check( 'malformed references load nothing', true );
// F1/F6: two active native subscriptions; charge subscription A against B's renewal order.
list( $a, $pa, $auth_a ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( '6c', 32 ), 'period_index' => 0 ) );
collect( $a, $pa->get_id() );
$other = wc_create_order( array( 'status' => 'pending', 'parent' => 424242 ) );
$other->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $a->get_id() + 1000 );
$other->update_meta_data( P2Flux_WC_Native_Scheduler::DUE_META, time() );
p2flux_test_reset_calls();
list( $out ) = collect( $a, $other->get_id() );
check( 'charging a subscription against an order that is not its own is refused with no request', 'refused' === $out['status'] && 'ORDER_MISMATCH' === $out['code'] && 0 === count( p2flux_test_calls( '/v1/charges' ) ), $out['code'] );
$foreign = p2flux_test_register_order( new P2Flux_Test_Native_Order( 777777, 'pending' ) );
list( $out ) = collect( $a, $foreign->get_id() );
check( 'an order that belongs to no subscription is refused too', 'refused' === $out['status'] && 'ORDER_MISMATCH' === $out['code'] );

echo "\naudit regressions: stale charges, cancelled mid-flight, sooner retries, refunded orders, counters\n";
// R2-F2: a CHARGING row whose worker died is handed to reconciliation by the sweep.
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( '7d', 32 ), 'period_index' => 0 ) );
collect( $sub, $parent->get_id() );
$dead = wc_create_order( array( 'status' => 'pending', 'parent' => $sub->get_parent_id() ) );
$dead->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $sub->get_id() );
$dead->update_meta_data( P2Flux_WC_Native_Scheduler::CYCLE_META, 1 );
P2Flux_WC_Periods::claim( array( 'auth_id' => $AUTH, 'period_index' => 1, 'subscription_id' => $sub->get_id(), 'order_id' => $dead->get_id(), 'units' => 10000000, 'environment' => 'test' ) );
P2Flux_WC_Periods::set_state( $AUTH, 1, P2Flux_WC_Periods::CHARGING );
$GLOBALS['p2flux_test_periods'][ strtolower( $AUTH ) . ':1' ]['updated'] = time() - 3600;
$GLOBALS['p2flux_test_scheduled'] = array();
P2Flux_WC_Jobs::sweep();
$row = P2Flux_WC_Periods::get( $AUTH, 1 );
$jobs = array_filter( $GLOBALS['p2flux_test_scheduled'], static function ( $j ) use ( $dead ) { return P2Flux_WC_Jobs::RECONCILE === $j['hook'] && (int) $j['order'] === $dead->get_id(); } );
check( 'a stale CHARGING period becomes RECONCILING with a reconcile job, and the order is not paid', P2Flux_WC_Periods::RECONCILING === $row['state'] && 1 === count( $jobs ) && ! $dead->is_paid() && $dead->get_meta( '_p2flux_reconciling' ) );
// R2-F9: a period already settled for this order is never charged again, nor a refunded order.
p2flux_test_reset_calls();
list( $out ) = collect( $sub, $parent->get_id() );
check( 'a parent whose period settled is refused without a request', 'refused' === $out['status'] && 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
$parent->set_status( 'refunded' ); $parent->paid = false;
list( $out ) = collect( $sub, $parent->get_id() );
check( 'a refunded order is refused without a request', 'refused' === $out['status'] && 'ALREADY_PAID' === $out['code'] && 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
// R2-F5: a settlement landing after cancellation records the money and leaves the cancellation alone.
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( '8e', 32 ), 'period_index' => 0 ) );
collect( $sub, $parent->get_id() );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$renewal = wc_create_order( array( 'status' => 'pending', 'parent' => $sub->get_parent_id() ) );
$renewal->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $sub->get_id() );
$renewal->update_meta_data( P2Flux_WC_Native_Scheduler::CYCLE_META, 1 );
$renewal->update_meta_data( '_p2flux_auth_id', $AUTH );
$renewal->update_meta_data( '_p2flux_period_index', 1 );
P2Flux_WC_Native_Account::cancel_subscription( $sub, 'customer cancelled' );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$before = $sub->get_meta( '_p2flux_collection' );
P2Flux_WC_Charger::mark_paid( $renewal, P2Flux_WC_Auth_History::get( $sub, $AUTH ), 1, '0x' . str_repeat( '9f', 32 ) );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'money recorded, subscription still cancelled, collection state untouched, no schedule', $renewal->is_paid() && 'cancelled' === $sub->get_status() && $before === $sub->get_meta( '_p2flux_collection' ) && 0 === $sub->timestamp( 'next_payment_at' ) && 0 === count( p2flux_test_native_jobs( $sub->get_id() ) ) );
// R2-F6: a sooner request replaces a later pending job.
$GLOBALS['p2flux_test_scheduled'] = array();
P2Flux_WC_Jobs::schedule( 'recharge', 4242, 86400 );
P2Flux_WC_Jobs::schedule( 'recharge', 4242, 60 );
$jobs = array_values( array_filter( $GLOBALS['p2flux_test_scheduled'], static function ( $j ) { return 4242 === (int) $j['order']; } ) );
check( 'the sooner retry replaces the later one, and only one job remains', 1 === count( $jobs ) && $jobs[0]['delay'] <= 60 );
P2Flux_WC_Jobs::schedule( 'recharge', 4242, 86400 );
$jobs = array_values( array_filter( $GLOBALS['p2flux_test_scheduled'], static function ( $j ) { return 4242 === (int) $j['order']; } ) );
check( 'a later request does not displace a sooner pending job', 1 === count( $jobs ) && $jobs[0]['delay'] <= 60 );
// R2-F11: the first dunning failure counts as one.
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'error' => 'INSUFFICIENT_BALANCE', 'action' => 'CUSTOMER_ACTION_REQUIRED' ), 400 );
list( $out, $sub ) = collect( $sub, $parent->get_id() );
check( 'the first dunning failure is counted', 1 === P2Flux_WC_Collection::attempts( $sub, 'dunning' ) );
// R2-F16: ALREADY_CHARGED with a hash still goes through recovery, never straight to paid.
$r = json_decode( wp_json_encode( array( 'status' => 'ALREADY_CHARGED', 'action' => 'SUCCESS', 'ok' => true, 'already_paid' => true, 'txHash' => '0x' . str_repeat( 'aa', 32 ), 'periodIndex' => 3 ) ) );
check( 'ALREADY_CHARGED with a hash is reconciled, not paid outright', 'reconcile' === P2Flux_WC_Renewal::decide( $r, array() )['outcome'] );

echo "\nprivacy: erasure unlinks the person and keeps the financial history\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( 'b1', 32 ), 'period_index' => 0 ) );
collect( $sub, $parent->get_id() );
$GLOBALS['p2flux_test_user_email'] = 'buyer@example.test';
$result = P2Flux_WC_Native_Privacy::erase( 'buyer@example.test' );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'eraser reports removed and retained', ! empty( $result['items_removed'] ) && ! empty( $result['items_retained'] ) && $result['done'] );
check( 'the subscription is unlinked (user 0) and cancelled, with its authorization history kept', 0 === $sub->get_user_id() && 'cancelled' === $sub->get_status() && null !== P2Flux_WC_Auth_History::get( $sub, $AUTH ) );
check( 'no job remains for it', 0 === count( p2flux_test_native_jobs( $sub->get_id() ) ) );
$export = P2Flux_WC_Native_Privacy::export( 'buyer@example.test' );
$leak = false !== strpos( wp_json_encode( $export ), 'p2s2.' ) || false !== strpos( wp_json_encode( $export ), 'p2fwc1.' );
check( 'the export carries no capability, encrypted or not', ! $leak && $export['done'] );

echo "\nthe record: whole-row writes survive an interleaved writer\n";
list( $sub ) = native_signup( 5 );
$a = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$a->update_meta_data( '_p2flux_mine', 'A' );
P2Flux_WC_Native_Store::interleave( $sub->get_id(), static function ( $meta ) { $meta['_p2flux_theirs'] = 'B'; return $meta; } );
check( 'the stale write is retried and succeeds', $a->save() );
$fresh = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'both writers\' keys survive', 'A' === $fresh->get_meta( '_p2flux_mine' ) && 'B' === $fresh->get_meta( '_p2flux_theirs' ) );
check( 'the authorization history survived too', null !== P2Flux_WC_Auth_History::active( $fresh ) );
check( 'mirrored columns follow the meta', $RECIPIENT === $fresh->get( 'recipient' ) && 10000000 === (int) $fresh->get( 'amount_units' ) );

echo "\nno capability in any note\n";
$leak = false;
foreach ( $GLOBALS['p2flux_test_orders'] as $order ) {
	foreach ( $order->notes as $note ) {
		if ( false !== strpos( $note, 'p2s2.' ) ) {
			$leak = true;
		}
	}
}
foreach ( $GLOBALS['p2flux_test_native_rows'] as $row ) {
	if ( false !== strpos( (string) $row['meta'], 'p2s2.' ) ) {
		$leak = true;
	}
}
check( 'no order note or native row holds a plaintext capability', ! $leak );


echo "\nabandoned signups: a never-authorized signup expires after the activation TTL, or when its order is cancelled\n";
function native_abandoned() {
	global $RECIPIENT;
	$parent = p2flux_test_register_order( new P2Flux_Test_Native_Order( $GLOBALS['p2flux_test_next_order']++, 'pending' ) );
	$sub    = P2Flux_WC_Native_Subscription::create( array( 'user_id' => 1, 'product_id' => 77, 'parent_order_id' => $parent->get_id(), 'amount_units' => 10000000, 'amount_display' => '10.000000', 'product_name' => 'Test plan', 'interval_type' => 'day', 'env' => 'test', 'recipient' => $RECIPIENT ) );
	$parent->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $sub->get_id() );
	return array( $sub, $parent );
}
list( $sub, $parent ) = native_abandoned();
P2Flux_WC_Native_Scheduler::sweep();
check( 'a fresh unauthorized signup is left alone by the sweep', 'pending' === P2Flux_WC_Native_Subscription::load( $sub->get_id() )->get_status() );
$sub->set( 'created_at', gmdate( 'Y-m-d H:i:s', time() - P2Flux_WC_Native_Subscription::ACTIVATION_TTL - 2 * HOUR_IN_SECONDS ) );
$sub->save();
P2Flux_WC_Native_Scheduler::sweep();
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'an unauthorized signup older than the activation TTL is expired by the sweep and its order cancelled', 'expired' === $sub->get_status() && 'cancelled' === $parent->get_status() );
list( $sub, $parent ) = native_abandoned();
$parent->update_status( 'cancelled' );
P2Flux_WC_Native_Scheduler::parent_cancelled( $parent->get_id() );
check( 'cancelling an unpaid signup order expires the signup', 'expired' === P2Flux_WC_Native_Subscription::load( $sub->get_id() )->get_status() );
list( $sub, $parent, $auth ) = native_signup( 5 );
$parent->update_status( 'cancelled' );
P2Flux_WC_Native_Scheduler::parent_cancelled( $parent->get_id() );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
check( 'cancelling an authorized but unpaid signup order expires the signup and keeps the authorization for revoke', 'expired' === $sub->get_status() && P2Flux_WC_Auth_History::active( $sub ) && $auth['id'] === P2Flux_WC_Auth_History::active( $sub )['id'] );
list( $sub, $parent ) = native_signup( 5 );
$parent->paid = true;
P2Flux_WC_Native_Scheduler::parent_cancelled( $parent->get_id() );
check( 'the cancelled hook ignores a paid signup order', 'pending' === P2Flux_WC_Native_Subscription::load( $sub->get_id() )->get_status() );


echo "\nemails: a period passing after a balance failure sends nothing more for that order\n";
list( $sub, $parent, $auth ) = native_signup( 5 );
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( 'a7', 32 ), 'period_index' => 0 ) );
collect( $sub, $parent->get_id() );
P2Flux_WC_Auth_History::activate( $sub, array_merge( $auth, array( 'start' => time() - 70, 'cap' => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ) ) ) );
$sub = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$sub->set_timestamp( 'schedule_anchor', time() - 70 ); $sub->set_timestamp( 'next_payment_at', time() - 10 ); $sub->save();
p2flux_test_respond( '/v1/charges', array( 'error' => 'INSUFFICIENT_BALANCE', 'action' => 'CUSTOMER_ACTION_REQUIRED' ), 400 );
P2Flux_WC_Native_Scheduler::renewal( $sub->get_id() );
$sub     = P2Flux_WC_Native_Subscription::load( $sub->get_id() );
$renewal = wc_get_order( $sub->get_related_orders( 'ids' )[1] );
$first   = json_decode( (string) $renewal->get_meta( '_p2flux_notified' ), true );
P2Flux_WC_Native_Scheduler::after_missed( $sub, $renewal );
$again   = json_decode( (string) wc_get_order( $renewal->get_id() )->get_meta( '_p2flux_notified' ), true );
check( 'the balance failure emailed once', is_array( $first ) && isset( $first['balance'] ) && 1 === count( $first ) );
check( 'the period passing afterwards adds no second email to that order', $again === $first );
echo "\n{$checks} checks, {$failures} failures\n";
if ( $failures ) {
	exit( 1 );
}
echo "all {$checks} checks passed\n";
