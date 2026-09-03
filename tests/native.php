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
	$o = wc_create_order( array( 'status' => 'pending' ) );
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
$o = wc_create_order( array( 'status' => 'pending' ) );
$o->update_meta_data( P2Flux_WC_Subscriptions::NATIVE_META, $sub->get_id() );
$o->update_meta_data( P2Flux_WC_Native_Scheduler::DUE_META, time() );
p2flux_test_reset_calls();
list( $out, $sub ) = collect( $sub, $o->get_id() );
check( 'a charge against a cancelled subscription is refused with no request', 'refused' === $out['status'] && 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'and cancelled cannot become active again', ! $sub->can_be_updated_to( 'active' ) );

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

echo "\n{$checks} checks, {$failures} failures\n";
if ( $failures ) {
	exit( 1 );
}
echo "all {$checks} checks passed\n";
