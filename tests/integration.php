<?php
/**
 * The invariants that involve more than one class.
 *
 *   php tests/integration.php
 *
 * These run the real charger, the real reconciliation and the real decision logic against a fake
 * store and a stub API, because what they prove is not "does this function work" but "can this
 * combination of events take money twice, or pay an order nobody can refund".
 *
 * @package P2Flux_For_WooCommerce
 */

require __DIR__ . '/fakes.php';
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
require __DIR__ . '/../includes/class-p2flux-wc-charger.php';
require __DIR__ . '/../includes/class-p2flux-wc-jobs.php';
require __DIR__ . '/../includes/class-p2flux-wc-lifecycle.php';

$failures = 0;
$checks   = 0;

/**
 * Assert one thing.
 *
 * @param string $label     What is proven.
 * @param bool   $condition The proof.
 * @param string $detail    Shown on failure.
 * @return void
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
 * The gateway's period helper, which the charger consults. The real class needs WooCommerce.
 */
class P2Flux_WC_Gateway {

	/**
	 * @param object $subscription Subscription.
	 * @return int|null
	 */
	public static function billing_period( $subscription ) {
		return P2Flux_WC_Money::period_seconds( $subscription->get_billing_period(), $subscription->get_billing_interval() );
	}
}

p2flux_test_filter( 'p2flux_wc_transport', static function () {
	return p2flux_test_transport();
} );
p2flux_test_filter( 'p2flux_wc_api_url', static function ( $url ) {
	return $url;
} );

$AUTH = '0x' . str_repeat( 'ab', 32 );

/**
 * A subscription, its parent order, and a renewal order, all authorized and ready to charge.
 *
 * @param int    $id     Base id.
 * @param string $auth   Authorization id.
 * @param int    $period Period seconds.
 * @return array{0:P2Flux_Test_Subscription,1:P2Flux_Test_Order}
 */
function scenario( $id, $auth, $period = 2419200 ) {
	$GLOBALS['p2flux_test_periods'] = array();
	$GLOBALS['p2flux_test_locks']   = array();

	$subscription = p2flux_test_register_subscription( new P2Flux_Test_Subscription( $id, 'active' ) );
	$renewal      = p2flux_test_register_order( new P2Flux_Test_Order( $id + 1000, 'pending' ) );

	$subscription->related = array( $renewal->get_id() );
	$subscription->update_meta_data( '_p2flux_env', 'test' );
	$subscription->update_meta_data( '_p2flux_rate', '1' );

	P2Flux_WC_Auth_History::activate(
		$subscription,
		array(
			'id'          => $auth,
			'cap'         => P2Flux_WC_Crypto::encrypt( 'p2s2.capability' ),
			'environment' => 'test',
			'recipient'   => '0x' . str_repeat( '11', 20 ),
			'units'       => 12990000,
			'period'      => $period,
			// Started one period ago, so the current period index is 1.
			'start'       => time() - $period,
		)
	);

	return array( $subscription, $renewal );
}

echo "\nthe browser is never the authority\n";

list( $subscription, $renewal ) = scenario( 10, $AUTH );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/charges', array( 'status' => 'CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'tx_hash' => '0x' . str_repeat( 'cd', 32 ), 'period_index' => 1 ) );

$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );
check( 'a charge with a transaction pays the renewal', $renewal->is_paid() && 'charged' === $outcome['status'] );
check( 'and records the settlement on the order', str_starts_with( (string) $renewal->get_meta( '_p2flux_tx_hash' ), '0xcdcd' ) );
check( 'exactly one charge request was sent', 1 === count( p2flux_test_calls( '/v1/charges' ) ) );

echo "\none blockchain payment cannot pay two orders\n";

list( $subscription, $renewal ) = scenario( 20, $AUTH );
$duplicate = p2flux_test_register_order( new P2Flux_Test_Order( 2222, 'pending' ) );
$subscription->related[] = $duplicate->get_id();

p2flux_test_reset_calls();
P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

// WooCommerce Subscriptions produced a second renewal order for the same period - an operator's
// retry, a plugin conflict. The protocol would answer ALREADY_CHARGED and the second order would
// look collected, so the period's owner refuses it before anything is sent.
p2flux_test_reset_calls();
$second = P2Flux_WC_Charger::collect( $subscription->get_id(), $duplicate->get_id() );

check( 'the second order is refused', 'refused' === $second['status'] && 'PERIOD_CONFLICT' === $second['code'] );
check( 'and no charge was sent for it', 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'the second order stays unpaid', ! $duplicate->is_paid() );
check( 'the first one is still the paid one', $renewal->is_paid() );

echo "\nALREADY_CHARGED never pays an order on its own\n";

list( $subscription, $renewal ) = scenario( 30, $AUTH );
p2flux_test_reset_calls();
// The classic lost response: the charge landed, the merchant never saw it, the retry says the
// period is collected - and names no transaction.
p2flux_test_respond( '/v1/charges', array( 'status' => 'ALREADY_CHARGED', 'ok' => true, 'already_paid' => true, 'action' => 'SUCCESS', 'period_index' => 1 ) );

$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

check( 'the order is NOT paid yet', ! $renewal->is_paid() );
check( 'it is reconciling instead', 'reconciling' === $outcome['status'] );
check( 'a reconciliation job is queued', as_has_scheduled_action( P2Flux_WC_Jobs::RECONCILE, array( $renewal->get_id() ) ) );

// Reconciliation finds the exact settlement, and only then is the order paid - which is what makes
// it attributable and refundable.
p2flux_test_respond(
	'/v1/charges/recover',
	array(
		'found'           => true,
		'subscription_id' => $AUTH,
		'period_index'    => 1,
		'tx_hash'         => '0x' . str_repeat( 'ef', 32 ),
		'recipient'       => '0x' . str_repeat( '11', 20 ),
		'amount_units'    => '12990000',
	)
);
P2Flux_WC_Jobs::reconcile( $renewal->get_id() );

check( 'the recovered settlement pays the order', $renewal->is_paid() );
check( 'with the exact transaction', str_starts_with( (string) $renewal->get_meta( '_p2flux_tx_hash' ), '0xefef' ) );
check( 'and the period is recorded as settled', P2Flux_WC_Periods::SETTLED === P2Flux_WC_Periods::get( $AUTH, 1 )['state'] );

echo "\na settlement that does not match is never applied\n";

list( $subscription, $renewal ) = scenario( 40, $AUTH );
p2flux_test_respond( '/v1/charges', array( 'status' => 'ALREADY_CHARGED', 'ok' => true, 'action' => 'SUCCESS', 'period_index' => 1 ) );
P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

// The recovered settlement paid a different wallet than the customer authorized. Real money, wrong
// order: a human decides, and nothing is marked paid in the meantime.
p2flux_test_respond(
	'/v1/charges/recover',
	array(
		'found'           => true,
		'subscription_id' => $AUTH,
		'period_index'    => 1,
		'tx_hash'         => '0x' . str_repeat( 'ef', 32 ),
		'recipient'       => '0x' . str_repeat( '99', 20 ),
		'amount_units'    => '12990000',
	)
);
P2Flux_WC_Jobs::reconcile( $renewal->get_id() );

check( 'a mismatched settlement does not pay the order', ! $renewal->is_paid() );
check( 'it is flagged for review', 'recipient' === $renewal->get_meta( '_p2flux_recover_mismatch' ) );

echo "\nconfirming is not payment\n";

list( $subscription, $renewal ) = scenario( 50, $AUTH );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/charges', array( 'status' => 'CONFIRMING', 'ok' => false, 'action' => 'WAIT', 'tx_hash' => '0x' . str_repeat( '11', 32 ), 'period_index' => 1 ) );

$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

check( 'a confirming charge does not pay the order', ! $renewal->is_paid() );
check( 'and does not fail it either', 'pending' === $renewal->get_status() );
check( 'reconciliation is queued rather than another charge', as_has_scheduled_action( P2Flux_WC_Jobs::RECONCILE, array( $renewal->get_id() ) ) );

// The customer cancels while that transaction is still settling. The already-authorized period may
// still land - and when it does, it belongs on the order. The cancellation stands regardless.
$subscription->set_status( 'cancelled' );
P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::CANCELLED, array( 'reason' => 'customer' ) );

p2flux_test_respond(
	'/v1/charges/recover',
	array(
		'found'           => true,
		'subscription_id' => $AUTH,
		'period_index'    => 1,
		'tx_hash'         => '0x' . str_repeat( '11', 32 ),
		'recipient'       => '0x' . str_repeat( '11', 20 ),
		'amount_units'    => '12990000',
	)
);
P2Flux_WC_Jobs::reconcile( $renewal->get_id() );

check( 'a settlement that lands after cancellation is still recorded', $renewal->is_paid() );
check( 'and the subscription stays cancelled', 'cancelled' === $subscription->get_status() );

echo "\nnothing charges a subscription a human stopped\n";

list( $subscription, $renewal ) = scenario( 60, $AUTH );
$subscription->set_status( 'on-hold' );
P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::SUSPENDED, array( 'reason' => 'suspended' ) );

p2flux_test_reset_calls();
$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

check( 'a suspended subscription is refused', 'refused' === $outcome['status'] );
check( 'with zero charge requests', 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'and no claim on the period', null === P2Flux_WC_Periods::get( $AUTH, 1 ) );

echo "\nterms that changed are never charged at the old amount\n";

list( $subscription, $renewal ) = scenario( 70, $AUTH );
// The shop raised the price. The customer signed for the old one.
$renewal->total = '15.99';

p2flux_test_reset_calls();
$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

check( 'a changed price stops the charge', 'refused' === $outcome['status'] && 'TERMS_CHANGED' === $outcome['code'] );
check( 'nothing was sent', 0 === count( p2flux_test_calls( '/v1/charges' ) ) );
check( 'and the subscription asks for a new authorization', P2Flux_WC_Collection::REAUTH_REQUIRED === P2Flux_WC_Collection::get( $subscription )['state'] );

echo "\na renewal paid by hand is never collected again\n";

list( $subscription, $renewal ) = scenario( 80, $AUTH );
// The customer paid this renewal from the order-pay screen while a retry was queued.
$renewal->paid = true;
$renewal->update_meta_data( '_p2flux_manual_paid', 1 );

p2flux_test_reset_calls();
$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

check( 'the queued retry does nothing', 'refused' === $outcome['status'] );
check( 'and sends no charge', 0 === count( p2flux_test_calls( '/v1/charges' ) ) );

echo "\na stale worker never overwrites a newer decision\n";

list( $subscription, $renewal ) = scenario( 90, $AUTH );
p2flux_test_respond( '/v1/charges', array( 'status' => 'INSUFFICIENT_BALANCE', 'ok' => false, 'action' => 'CUSTOMER_ACTION_REQUIRED' ) );

// While this charge is in flight, another process takes over the expired lease and cancels the
// subscription. The failing result must not resurrect it as a dunning hold.
p2flux_test_filter( 'p2flux_wc_transport', static function () use ( $subscription ) {
	return static function ( $url, $payload, $timeout ) use ( $subscription ) {
		P2Flux_WC_Lock::expire( $subscription->get_id() );
		$subscription->set_status( 'cancelled' );

		return p2flux_test_transport()( $url, $payload, $timeout );
	};
} );

$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

check( 'the stale outcome is not applied', 'cancelled' === $subscription->get_status() );
check( 'the order is not failed by it', 'pending' === $renewal->get_status() );
check( 'and it is certainly not paid', ! $renewal->is_paid() );

p2flux_test_filter( 'p2flux_wc_transport', static function () {
	return p2flux_test_transport();
} );

echo "\na revoked authorization stops everything\n";

list( $subscription, $renewal ) = scenario( 100, $AUTH );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/charges', array( 'status' => 'PERMISSION_REVOKED', 'ok' => false, 'action' => 'STOP_SUBSCRIPTION' ), 400 );

$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

check( 'the subscription is cancelled', 'cancelled' === $subscription->get_status() );
check( 'the authorization is marked revoked', P2Flux_WC_Auth_History::REVOKED === P2Flux_WC_Auth_History::get( $subscription, $AUTH )['status'] );
check( 'and nothing is scheduled to try again', ! as_has_scheduled_action( P2Flux_WC_Jobs::RECHARGE, array( $renewal->get_id() ) ) );

echo "\ndeterministic refusals do not become retry ladders\n";

list( $subscription, $renewal ) = scenario( 110, $AUTH );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/charges', array( 'status' => 'INVALID_SUBSCRIPTION', 'ok' => false, 'action' => 'INVALID_REQUEST' ), 400 );

P2Flux_WC_Charger::collect( $subscription->get_id(), $renewal->get_id() );

check( 'no retry is queued for an invalid request', ! as_has_scheduled_action( P2Flux_WC_Jobs::RECHARGE, array( $renewal->get_id() ) ) );
check( 'the renewal is failed for a human', 'failed' === $renewal->get_status() );

echo "\nthe renewal on-hold is not a suspension\n";

// WCS puts a subscription on hold at priority 1 of the parent hook, before the gateway hook fires. That
// transition must not be read as a human suspending the subscription - it would drop the very renewal
// being collected.
list( $subscription, $renewal ) = scenario( 120, $AUTH );
p2flux_test_reset_calls();
P2Flux_WC_Jobs::schedule( 'recharge', $renewal->get_id(), 60 );
$GLOBALS['p2flux_test_doing'] = array( 'woocommerce_scheduled_subscription_payment' );
$subscription->set_status( 'on-hold' );
P2Flux_WC_Lifecycle::on_hold( $subscription );
$GLOBALS['p2flux_test_doing'] = array();

check( 'the scheduled renewal on-hold leaves the collection state alone', P2Flux_WC_Collection::NORMAL === P2Flux_WC_Collection::get( $subscription )['state'] );
check( 'and keeps the queued jobs', as_has_scheduled_action( P2Flux_WC_Jobs::RECHARGE, array( $renewal->get_id() ) ) );

// The same transition outside a renewal request IS a suspension.
P2Flux_WC_Lifecycle::on_hold( $subscription );
check( 'an on-hold outside a renewal is a suspension', P2Flux_WC_Collection::SUSPENDED === P2Flux_WC_Collection::get( $subscription )['state'] );
check( 'which drops the queued jobs', ! as_has_scheduled_action( P2Flux_WC_Jobs::RECHARGE, array( $renewal->get_id() ) ) );

echo "\nnotes and logs never carry a capability\n";

$leaked = false;
foreach ( $GLOBALS['p2flux_test_orders'] as $order ) {
	foreach ( $order->notes as $note ) {
		if ( false !== strpos( $note, 'p2s2.' ) ) {
			$leaked = true;
		}
	}
}
check( 'no order note contains a capability', ! $leaked );
check( 'and the redactor removes one if it ever appears', '[p2s2 redacted] arrived' === P2Flux_WC_Logger::redact( 'p2s2.k1.body.mac arrived' ) );

echo "\n";
echo 0 === $failures
	? "all {$checks} checks passed\n"
	: "{$failures} of {$checks} checks FAILED\n";

exit( 0 === $failures ? 0 : 1 );
