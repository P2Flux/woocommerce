<?php
/**
 * The offline suite: the invariants, not the happy paths.
 *
 *   php tests/unit.php
 *
 * What these prove, in order: money is never a float and never rounds a customer's price into a
 * different one; a charge result maps to exactly one order outcome and a deterministic failure
 * schedules nothing; a stored capability is unreadable without the key and survives a rotation; an
 * authorization that funded an old order stays resolvable after the customer re-authorizes.
 *
 * @package P2Flux_For_WooCommerce
 */

require __DIR__ . '/shims.php';
require __DIR__ . '/../includes/class-p2flux-wc-money.php';
require __DIR__ . '/../includes/class-p2flux-wc-calendar.php';
require __DIR__ . '/../includes/class-p2flux-wc-crypto.php';
require __DIR__ . '/../includes/class-p2flux-wc-collection.php';
require __DIR__ . '/../includes/class-p2flux-wc-renewal.php';
require __DIR__ . '/../includes/class-p2flux-wc-auth-history.php';

$failures = 0;
$checks   = 0;

/**
 * Assert one thing.
 *
 * @param string $label     What is being proven.
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
 * A ChargeResult-shaped value object, without loading the SDK.
 *
 * @param string $status Status.
 * @param string $action Action.
 * @param array  $extra  txHash, nextPeriodAt.
 * @return object
 */
function charge_result( $status, $action, array $extra = array() ) {
	return (object) array_merge(
		array(
			'status'       => $status,
			'action'       => $action,
			'ok'           => 'SUCCESS' === $action,
			'txHash'       => null,
			'nextPeriodAt' => null,
		),
		$extra
	);
}

echo "\nmoney\n";

check( 'a dollar is a million micro-units', 1000000 === P2Flux_WC_Money::to_units( '1.00', '1' ) );
check( 'the one-time minimum is exact', 10000 === P2Flux_WC_Money::to_units( '0.01', '1' ) );
check( 'so is the recurring one', 102041 === P2Flux_WC_Money::to_units( '0.102041', '1' ) );
check( 'a USD price is never divided at all', 12990000 === P2Flux_WC_Money::to_units( '12.99', '1' ) );

// 12.99 EUR at 0.92 EUR per USDC is 14.119565... USDC. Rounded half up at six decimals, and
// computed without ever touching a float.
check( 'a converted price rounds half up at six decimals', 14119565 === P2Flux_WC_Money::to_units( '12.99', '0.92' ) );
check( 'an awkward rate stays exact', 3000003 === P2Flux_WC_Money::to_units( '1.00', '0.333333' ) );
check( 'a rate above one converts down', 5000000 === P2Flux_WC_Money::to_units( '10.00', '2' ) );

check( 'more precision than USDC has is refused, not rounded away', null === P2Flux_WC_Money::to_units( '1.0000001', '1' ) );
check( 'trailing zeros are not extra precision', 1000000 === P2Flux_WC_Money::to_units( '1.0000000', '1' ) );
check( 'a negative price is not money', null === P2Flux_WC_Money::to_units( '-1.00', '1' ) );
check( 'a zero rate is refused rather than dividing by it', null === P2Flux_WC_Money::to_units( '1.00', '0' ) );
check( 'text is not a price', null === P2Flux_WC_Money::to_units( 'free', '1' ) );

check( 'formatting round-trips', '12.990000' === P2Flux_WC_Money::format( 12990000 ) );
check( 'and pads the fraction', '0.010000' === P2Flux_WC_Money::format( 10000 ) );

check( 'a whole amount reads as two decimals', '1.00' === P2Flux_WC_Money::display( 1000000 ) );
check( 'a cent still reads as two', '0.01' === P2Flux_WC_Money::display( 10000 ) );
check( 'real precision is never hidden', '14.119565' === P2Flux_WC_Money::display( 14119565 ) );
check( 'and trailing zeros beyond two are dropped', '2.50' === P2Flux_WC_Money::display( 2500000 ) );
check( 'the wire format is untouched', '1.000000' === P2Flux_WC_Money::format( 1000000 ) );

check( 'a penny is too small for a subscription', 'too_small' === P2Flux_WC_Money::check_bounds( 10000, true ) );
check( 'but fine for a one-off', true === P2Flux_WC_Money::check_bounds( 10000, false ) );
check( 'the ceiling is enforced', 'too_large' === P2Flux_WC_Money::check_bounds( 10000000001, false ) );

// A contract period must never be LONGER than Woo's interval, or renewals arrive early and answer
// NOT_DUE forever. Hence 28 days for a month and 365 for a year.
// The allowance setting maps to the API's term: unlimited unless a sane number of periods.
check( 'unlimited stays unlimited', 'unlimited' === P2Flux_WC_Money::allowance_term( 'unlimited' ) );
check( 'an empty setting is unlimited', 'unlimited' === P2Flux_WC_Money::allowance_term( '' ) );
check( '12 periods', array( 'periods' => 12 ) === P2Flux_WC_Money::allowance_term( '12' ) );
check( 'nonsense is unlimited, never zero', 'unlimited' === P2Flux_WC_Money::allowance_term( '0' ) && 'unlimited' === P2Flux_WC_Money::allowance_term( '99999' ) );

check( 'a month is 28 days, deliberately short', 2419200 === P2Flux_WC_Money::period_seconds( 'month', 1 ) );
check( 'a year is 365 days', 31536000 === P2Flux_WC_Money::period_seconds( 'year', 1 ) );
check( 'a week is exact', 604800 === P2Flux_WC_Money::period_seconds( 'week', 1 ) );
check( 'intervals multiply', 4838400 === P2Flux_WC_Money::period_seconds( 'month', 2 ) );
check( 'anything under an hour is refused by the protocol', null === P2Flux_WC_Money::period_seconds( 'minute', 1 ) );
check( 'and anything over 366 days', null === P2Flux_WC_Money::period_seconds( 'year', 2 ) );

echo "\ncalendar: due dates from the anchor, never from the previous one\n";
$jan31 = gmmktime( 10, 30, 0, 1, 31, 2027 );
check( 'daily is exactly 86400 s later', $jan31 + DAY_IN_SECONDS === P2Flux_WC_Calendar::due( $jan31, 'day', 1 ) );
check( 'weekly is exactly 7 days later', $jan31 + 7 * DAY_IN_SECONDS === P2Flux_WC_Calendar::due( $jan31, 'week', 1 ) );
check( 'Jan 31 + 1 month is Feb 28 (2027 is not a leap year)', '2027-02-28 10:30:00' === gmdate( 'Y-m-d H:i:s', P2Flux_WC_Calendar::due( $jan31, 'month', 1 ) ) );
check( 'Jan 31 + 2 months is Mar 31, not Mar 28', '2027-03-31 10:30:00' === gmdate( 'Y-m-d H:i:s', P2Flux_WC_Calendar::due( $jan31, 'month', 2 ) ) );
check( 'Jan 31 + 3 months is Apr 30', '2027-04-30 10:30:00' === gmdate( 'Y-m-d H:i:s', P2Flux_WC_Calendar::due( $jan31, 'month', 3 ) ) );
check( 'Jan 31 + 13 months is Feb 29 2028 (leap year)', '2028-02-29 10:30:00' === gmdate( 'Y-m-d H:i:s', P2Flux_WC_Calendar::due( $jan31, 'month', 13 ) ) );
check( 'Jan 31 + 12 months is Jan 31 next year', '2028-01-31 10:30:00' === gmdate( 'Y-m-d H:i:s', P2Flux_WC_Calendar::due( $jan31, 'month', 12 ) ) );
$feb29 = gmmktime( 0, 0, 0, 2, 29, 2028 );
check( 'Feb 29 yearly maps to Feb 28 in a non-leap year', '2029-02-28' === gmdate( 'Y-m-d', P2Flux_WC_Calendar::due( $feb29, 'year', 1 ) ) );
check( 'and back to Feb 29 in the next leap year', '2032-02-29' === gmdate( 'Y-m-d', P2Flux_WC_Calendar::due( $feb29, 'year', 4 ) ) );
check( 'time of day survives the month clamp', '23:59:59' === gmdate( 'H:i:s', P2Flux_WC_Calendar::due( gmmktime( 23, 59, 59, 3, 31, 2027 ), 'month', 1 ) ) );
check( 'a month is never shorter than the 28-day contract period', P2Flux_WC_Calendar::due( $jan31, 'month', 1 ) - $jan31 >= 28 * DAY_IN_SECONDS && P2Flux_WC_Calendar::due( $jan31, 'month', 2 ) - P2Flux_WC_Calendar::due( $jan31, 'month', 1 ) >= 28 * DAY_IN_SECONDS );
check( 'a year is never shorter than the 365-day contract period', P2Flux_WC_Calendar::due( $feb29, 'year', 1 ) - $feb29 >= 365 * DAY_IN_SECONDS );

echo "\ncalendar: the latest cycle at a moment is exact\n";
check( 'before the anchor there is no cycle', -1 === P2Flux_WC_Calendar::latest_cycle_at( $jan31, 'month', $jan31 - 1 ) );
check( 'at the anchor it is cycle 0', 0 === P2Flux_WC_Calendar::latest_cycle_at( $jan31, 'month', $jan31 ) );
check( 'one second before Feb 28 10:30 it is still cycle 0', 0 === P2Flux_WC_Calendar::latest_cycle_at( $jan31, 'month', P2Flux_WC_Calendar::due( $jan31, 'month', 1 ) - 1 ) );
check( 'at Feb 28 10:30 it is cycle 1', 1 === P2Flux_WC_Calendar::latest_cycle_at( $jan31, 'month', P2Flux_WC_Calendar::due( $jan31, 'month', 1 ) ) );
check( 'three months later it is cycle 3', 3 === P2Flux_WC_Calendar::latest_cycle_at( $jan31, 'month', P2Flux_WC_Calendar::due( $jan31, 'month', 3 ) + 5 ) );
check( 'daily: 100 days later is cycle 100', 100 === P2Flux_WC_Calendar::latest_cycle_at( $jan31, 'day', $jan31 + 100 * DAY_IN_SECONDS + 1 ) );
check( 'weekly: 20 days later is cycle 2', 2 === P2Flux_WC_Calendar::latest_cycle_at( $jan31, 'week', $jan31 + 20 * DAY_IN_SECONDS ) );
check( 'yearly: the Feb 29 anchor after 4 years is cycle 4', 4 === P2Flux_WC_Calendar::latest_cycle_at( $feb29, 'year', P2Flux_WC_Calendar::due( $feb29, 'year', 4 ) ) );
check( 'yearly: one second before that is cycle 3', 3 === P2Flux_WC_Calendar::latest_cycle_at( $feb29, 'year', P2Flux_WC_Calendar::due( $feb29, 'year', 4 ) - 1 ) );
$ok = true;
foreach ( array( 'day', 'week', 'month', 'year' ) as $interval ) {
	for ( $n = 0; $n < 40; $n++ ) {
		$due = P2Flux_WC_Calendar::due( $jan31, $interval, $n );
		if ( P2Flux_WC_Calendar::latest_cycle_at( $jan31, $interval, $due ) !== $n || P2Flux_WC_Calendar::latest_cycle_at( $jan31, $interval, $due - 1 ) !== $n - 1 ) {
			$ok = false;
		}
	}
}
check( 'latest_cycle_at inverts due() for 40 cycles of every interval', $ok );
check( 'contract periods: day/week/28 d/365 d', 86400 === P2Flux_WC_Calendar::contract_period( 'day' ) && 604800 === P2Flux_WC_Calendar::contract_period( 'week' ) && 2419200 === P2Flux_WC_Calendar::contract_period( 'month' ) && 31536000 === P2Flux_WC_Calendar::contract_period( 'year' ) );

echo "\ncharge outcomes\n";

$paid = P2Flux_WC_Renewal::decide( charge_result( 'CHARGED', 'SUCCESS', array( 'txHash' => '0xabc' ) ) );
check( 'a charge with a transaction pays the order', 'paid' === $paid['outcome'] );
check( 'and schedules nothing', null === $paid['schedule'] );

// The bug this prevents: marking an order paid with an empty transaction hash, which leaves a
// settled period nobody can attribute, audit or refund.
$already = P2Flux_WC_Renewal::decide( charge_result( 'ALREADY_CHARGED', 'SUCCESS' ) );
check( 'ALREADY_CHARGED does not pay the order by itself', 'paid' !== $already['outcome'] );
check( 'it reconciles instead', 'reconcile' === $already['outcome'] && 'reconcile' === $already['schedule'] );
check( 'and does so immediately', 0 === $already['delay'] );

$confirming = P2Flux_WC_Renewal::decide( charge_result( 'CONFIRMING', 'WAIT', array( 'txHash' => '0xdef' ) ) );
check( 'CONFIRMING never pays the order', 'paid' !== $confirming['outcome'] );
check( 'never fails it', null === $confirming['order_status'] );
check( 'never emails the customer', false === $confirming['notify'] );
check( 'and reconciles rather than charging again', 'reconcile' === $confirming['schedule'] );

$stuck = P2Flux_WC_Renewal::decide(
	charge_result( 'CONFIRMING', 'WAIT' ),
	array( 'confirming' => P2Flux_WC_Renewal::MAX_CONFIRMING )
);
check( 'a charge that will not settle backs off but never fails', 'reconcile' === $stuck['outcome'] && null === $stuck['order_status'] );

$balance = P2Flux_WC_Renewal::decide( charge_result( 'INSUFFICIENT_BALANCE', 'CUSTOMER_ACTION_REQUIRED' ) );
check( 'an empty wallet fails the renewal', 'failed' === $balance['order_status'] );
check( 'puts the subscription in dunning', P2Flux_WC_Collection::DUNNING === $balance['collection'] );
check( 'retries daily', 'recharge' === $balance['schedule'] && DAY_IN_SECONDS === $balance['delay'] );
check( 'and tells the customer', true === $balance['notify'] );

$exhausted = P2Flux_WC_Renewal::decide(
	charge_result( 'INSUFFICIENT_BALANCE', 'CUSTOMER_ACTION_REQUIRED' ),
	array( 'dunning' => P2Flux_WC_Renewal::MAX_DUNNING )
);
check( 'dunning stops after its last attempt', null === $exhausted['schedule'] );

// Retrying cannot restore an allowance: only the customer can. A retry ladder here would just
// reproduce the same failure on a timer.
$allowance = P2Flux_WC_Renewal::decide( charge_result( 'INSUFFICIENT_ALLOWANCE', 'CUSTOMER_ACTION_REQUIRED' ) );
check( 'a broken allowance schedules no retry', null === $allowance['schedule'] );
check( 'but does tell the customer', true === $allowance['notify'] );

$revoked = P2Flux_WC_Renewal::decide( charge_result( 'PERMISSION_REVOKED', 'STOP_SUBSCRIPTION' ) );
check( 'a revoked authorization cancels', 'cancel' === $revoked['outcome'] );
check( 'and never retries', null === $revoked['schedule'] );

$invalid = P2Flux_WC_Renewal::decide( charge_result( 'INVALID_SUBSCRIPTION', 'INVALID_REQUEST' ) );
check( 'a deterministic refusal schedules ZERO retries', null === $invalid['schedule'] );
check( 'and asks a human to look', true === $invalid['admin'] );

$not_due = P2Flux_WC_Renewal::decide(
	charge_result( 'NOT_DUE', 'RETRY_LATER', array( 'nextPeriodAt' => gmdate( 'c', time() + 3600 ) ) )
);
check( 'NOT_DUE waits for the period boundary', 'recharge' === $not_due['schedule'] && $not_due['delay'] > 3000 );
check( 'and leaves the order alone', null === $not_due['order_status'] );

$transient = P2Flux_WC_Renewal::decide( charge_result( 'RPC_ERROR', 'RETRY_LATER' ) );
check( 'a provider hiccup retries quietly', 'recharge' === $transient['schedule'] && false === $transient['notify'] );
check( 'and does not fail the order yet', null === $transient['order_status'] );

$given_up = P2Flux_WC_Renewal::decide(
	charge_result( 'RPC_ERROR', 'RETRY_LATER' ),
	array( 'transient' => P2Flux_WC_Renewal::MAX_TRANSIENT )
);
check( 'after enough failures it fails the order for a human', 'failed' === $given_up['order_status'] && null === $given_up['schedule'] );

// An unknown code from a future API version must still land somewhere sane rather than in a branch
// that pays or cancels something.
$unknown = P2Flux_WC_Renewal::decide( charge_result( 'SOMETHING_NEW', 'RETRY_LATER' ) );
check( 'an unrecognised status is treated as retryable, not as success', 'pending' === $unknown['outcome'] );

echo "\nnotes never leak the capability\n";

$capability = 'p2s2.k1.' . base64_encode( 'body' ) . '.mac';
foreach ( array( 'CHARGED' => 'SUCCESS', 'INSUFFICIENT_BALANCE' => 'CUSTOMER_ACTION_REQUIRED', 'PERMISSION_REVOKED' => 'STOP_SUBSCRIPTION', 'RPC_ERROR' => 'RETRY_LATER' ) as $status => $action ) {
	$decision = P2Flux_WC_Renewal::decide( charge_result( $status, $action, array( 'txHash' => '0xabc' ) ) );
	check( "the {$status} note carries no capability", false === strpos( $decision['note'], 'p2s2.' ) );
}
unset( $capability );

echo "\nmay we charge\n";

$normal    = array( 'state' => P2Flux_WC_Collection::NORMAL, 'renewal_order_id' => 0 );
$dunning   = array( 'state' => P2Flux_WC_Collection::DUNNING, 'renewal_order_id' => 55 );
$suspended = array( 'state' => P2Flux_WC_Collection::SUSPENDED, 'renewal_order_id' => 0 );

check( 'an active subscription may be charged', true === P2Flux_WC_Collection::may_charge( 'active', $normal ) );
check( 'so may the on-hold WCS sets during a renewal', true === P2Flux_WC_Collection::may_charge( 'on-hold', $normal ) );

// The race this closes: dunning schedules a retry, a human then suspends the subscription, the
// wallet is topped up, and the retry fires. It must not collect.
check( 'a suspended subscription may NEVER be charged', true !== P2Flux_WC_Collection::may_charge( 'on-hold', $suspended ) );
check( 'nor a cancelled one', true !== P2Flux_WC_Collection::may_charge( 'cancelled', $normal ) );
check( 'nor one awaiting re-authorization', true !== P2Flux_WC_Collection::may_charge( 'on-hold', array( 'state' => P2Flux_WC_Collection::REAUTH_REQUIRED, 'renewal_order_id' => 0 ) ) );

// A subscription awaiting its first payment is pending, and that first payment is the charge being
// asked about. Refusing it makes every signup fail with an authorization the customer just signed.
check( 'a pending subscription may take its first charge', true === P2Flux_WC_Collection::may_charge( 'pending', $normal ) );
check( 'but not once it is on its way out', true !== P2Flux_WC_Collection::may_charge( 'pending-cancel', $normal ) );
check( 'nor once expired', true !== P2Flux_WC_Collection::may_charge( 'expired', $normal ) );

check( 'a dunning retry may collect its own renewal', true === P2Flux_WC_Collection::may_charge( 'on-hold', $dunning, 55 ) );
check( 'but not a different one', true !== P2Flux_WC_Collection::may_charge( 'on-hold', $dunning, 77 ) );

echo "\ncapability at rest\n";

if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
	check( 'sodium is available', false, 'this PHP build cannot run the encryption tests' );
} else {
	$secret = 'p2s2.k1.' . base64_encode( '{"v":2}' ) . '.mac';

	$sealed = P2Flux_WC_Crypto::encrypt( $secret );
	check( 'a capability encrypts', is_string( $sealed ) && 0 === strpos( $sealed, 'p2fwc1.' ) );
	check( 'the plaintext is not in the ciphertext', false === strpos( $sealed, 'p2s2.' ) );
	check( 'and it decrypts back', $secret === P2Flux_WC_Crypto::decrypt( $sealed ) );

	check( 'a truncated value fails closed', null === P2Flux_WC_Crypto::decrypt( substr( $sealed, 0, -4 ) ) );
	check( 'so does an empty one', null === P2Flux_WC_Crypto::decrypt( '' ) );
	check( 'and something that is not ours at all', null === P2Flux_WC_Crypto::decrypt( 'not-a-ciphertext' ) );

	// Rotation: the value written under the old key must keep opening while the new key takes over
	// new writes. A merchant who changes the key without this has silently made every active
	// subscription uncollectable.
	$old_key = get_option( P2Flux_WC_Crypto::KEY_OPTION );
	define( 'P2FLUX_WC_ENCRYPTION_KEY_PREVIOUS', $old_key );
	define( 'P2FLUX_WC_ENCRYPTION_KEY', base64_encode( sodium_crypto_secretbox_keygen() ) );

	check( 'a value written under the previous key still opens', $secret === P2Flux_WC_Crypto::decrypt( $sealed ) );
	check( 'and is recognisable as not yet rotated', false === P2Flux_WC_Crypto::is_current( $sealed ) );

	$rewritten = P2Flux_WC_Crypto::encrypt( $secret );
	check( 'new writes use the new key', P2Flux_WC_Crypto::is_current( $rewritten ) );
	check( 'which still decrypts', $secret === P2Flux_WC_Crypto::decrypt( $rewritten ) );
	check( 'and the two ciphertexts differ', $sealed !== $rewritten );
}

echo "\nauthorization history\n";

$subscription = new P2Flux_Test_Object( 42 );
$auth_a       = '0x' . str_repeat( 'aa', 32 );
$auth_b       = '0x' . str_repeat( 'bb', 32 );

P2Flux_WC_Auth_History::activate(
	$subscription,
	array(
		'id'          => $auth_a,
		'cap'         => P2Flux_WC_Crypto::encrypt( 'p2s2.capability.a' ),
		'environment' => 'test',
		'recipient'   => '0x' . str_repeat( '11', 20 ),
		'units'       => 12990000,
		'period'      => 2419200,
	)
);

check( 'the first authorization becomes active', $auth_a === P2Flux_WC_Auth_History::active( $subscription )['id'] );
check( 'and its capability comes back', 'p2s2.capability.a' === P2Flux_WC_Auth_History::capability( $subscription, $auth_a ) );

// The customer re-authorizes at a new price. The old record must survive: an order it paid last
// month can still need refunding, and a refund starts from the capability that collected it.
P2Flux_WC_Auth_History::activate(
	$subscription,
	array(
		'id'          => $auth_b,
		'cap'         => P2Flux_WC_Crypto::encrypt( 'p2s2.capability.b' ),
		'environment' => 'test',
		'recipient'   => '0x' . str_repeat( '11', 20 ),
		'units'       => 15990000,
		'period'      => 2419200,
	),
	$auth_a
);

check( 'the replacement becomes active', $auth_b === P2Flux_WC_Auth_History::active( $subscription )['id'] );
check( 'the old authorization is kept, not deleted', null !== P2Flux_WC_Auth_History::get( $subscription, $auth_a ) );
check( 'marked superseded', P2Flux_WC_Auth_History::SUPERSEDED === P2Flux_WC_Auth_History::get( $subscription, $auth_a )['status'] );
check( 'pointing at what replaced it', $auth_b === P2Flux_WC_Auth_History::get( $subscription, $auth_a )['replaced_by'] );

// The invariant a historical refund depends on.
check( 'an old order can still resolve ITS capability', 'p2s2.capability.a' === P2Flux_WC_Auth_History::capability( $subscription, $auth_a ) );
check( 'and the new one resolves separately', 'p2s2.capability.b' === P2Flux_WC_Auth_History::capability( $subscription, $auth_b ) );

// Re-authorization must never move the payout wallet or the environment: the customer signed for a
// recipient, and the merchant changing a global setting cannot rewrite what they agreed to.
$records = P2Flux_WC_Auth_History::all( $subscription );
check( 'every record keeps its own recipient and environment', 2 === count( $records ) && 'test' === $records[0]['environment'] );

P2Flux_WC_Auth_History::mark( $subscription, $auth_b, P2Flux_WC_Auth_History::REVOKED, 'customer revoked on chain' );
check( 'a revoked authorization clears the active pointer', '' === $subscription->get_meta( P2Flux_WC_Auth_History::ACTIVE_META ) );
check( 'and does not promote the superseded one back', null === P2Flux_WC_Auth_History::active( $subscription ) );

$serialized = (string) $subscription->get_meta( P2Flux_WC_Auth_History::META );
check( 'no capability is ever stored in plaintext', false === strpos( $serialized, 'p2s2.capability' ) );

echo "\n";
echo 0 === $failures
	? "all {$checks} checks passed\n"
	: "{$failures} of {$checks} checks FAILED\n";

exit( 0 === $failures ? 0 : 1 );
