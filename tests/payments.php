<?php
/**
 * One-time payments: minting, verifying, settling and recovering, against a stub P2Flux API.
 *
 * Nothing here needs WordPress, a network or a chain. The real P2Flux SDK client runs behind the
 * plugin's transport filter, which answers from canned responses.
 *
 *   php tests/payments.php
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
require __DIR__ . '/../includes/class-p2flux-wc-intents.php';
require __DIR__ . '/../includes/class-p2flux-wc-payments.php';
require __DIR__ . '/../includes/class-p2flux-wc-ajax.php';

$failures = 0;
$checks   = 0;

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

// --- the few WordPress request functions the AJAX endpoint needs ---------------------------------

/** wp_send_json_* end the request; here they throw, so a test can read what was sent. */
class P2Flux_Test_Json extends Exception {
	/** @var bool */
	public $success;
	/** @var mixed */
	public $data;

	public function __construct( $success, $data ) {
		parent::__construct( 'json' );
		$this->success = $success;
		$this->data    = $data;
	}
}

function check_ajax_referer( $action, $field ) {
	if ( ! isset( $_POST[ $field ] ) || 'nonce-' . $action !== $_POST[ $field ] ) {
		throw new P2Flux_Test_Json( false, 'bad nonce' );
	}
	return 1;
}
function wp_send_json_success( $data = null ) {
	throw new P2Flux_Test_Json( true, $data );
}
function wp_send_json_error( $data = null, $status = null ) {
	unset( $status );
	throw new P2Flux_Test_Json( false, $data );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function wp_unslash( $value ) {
	return $value;
}
function absint( $value ) {
	return abs( (int) $value );
}

/** An order that also has a key, as the pay page's requests carry one. */
class P2Flux_Suite_Order extends P2Flux_Test_Order {
	public function get_order_key() {
		return 'wc_order_key_' . $this->get_id();
	}
}

/** Run one AJAX handler and return what it sent. */
function ajax( $handler, array $post ) {
	$_POST = $post;
	try {
		call_user_func( array( 'P2Flux_WC_Ajax', $handler ) );
	} catch ( P2Flux_Test_Json $sent ) {
		return array( 'success' => $sent->success, 'data' => $sent->data );
	}
	return array( 'success' => null, 'data' => null );
}

p2flux_test_filter( 'p2flux_wc_transport', static function () {
	return p2flux_test_transport();
} );

// --- fixtures -----------------------------------------------------------------------------------

const MERCHANT = '0x9b710c4cc6a63fc0728748af852e2183fb936262';
const PRICE    = 12990000; // 12.99 USDC

function context() {
	return array(
		'units'       => PRICE,
		'recipient'   => MERCHANT,
		'environment' => 'test',
		'rate'        => '1',
	);
}

function new_order( $id ) {
	$GLOBALS['p2flux_test_scheduled'] = array();
	return p2flux_test_register_order( new P2Flux_Suite_Order( $id, 'pending' ) );
}

$minted = 0;
function respond_create() {
	p2flux_test_respond(
		'/v1/payments',
		array(
			'intent'     => 'p2f1.k1.intent-' . ( ++$GLOBALS['minted'] ) . '.mac',
			'reference'  => '0x' . str_repeat( 'cd', 32 ),
			'amount'     => '12.99',
			'expires_at' => time() + 3600,
		)
	);
}

function tx( $n ) {
	return '0x' . str_pad( dechex( $n ), 64, '0', STR_PAD_LEFT );
}

function verdict( $n, $amount = '12.99' ) {
	return array(
		'valid'        => true,
		'tx_hash'      => tx( $n ),
		'reference'    => '0x' . str_repeat( 'cd', 32 ),
		'amount'       => $amount,
		'block_number' => 100,
	);
}

function scheduled( $order_id, $hook ) {
	return array_values(
		array_filter(
			$GLOBALS['p2flux_test_scheduled'],
			static function ( $job ) use ( $order_id, $hook ) {
				return $job['order'] === $order_id && $job['hook'] === $hook;
			}
		)
	);
}

// --- minting -------------------------------------------------------------------------------------

echo "\nan intent is minted once and reused\n";
$order = new_order( 501 );
respond_create();
p2flux_test_reset_calls();
$first  = P2Flux_WC_Payments::ensure_intent( $order, context() );
$second = P2Flux_WC_Payments::ensure_intent( $order, context() );
check( 'the first call mints', is_array( $first ) && 0 === strpos( $first['intent'], 'p2f1.' ) );
check( 'the second call reuses it', is_array( $second ) && $second['intent'] === $first['intent'] );
check( 'exactly one create request was made', 1 === count( p2flux_test_calls( '/v1/payments' ) ) );
check( 'the ledger holds exactly one intent', 1 === count( P2Flux_WC_Intents::all( $order ) ) );
check( 'the recovery ladder is scheduled', 5 === count( scheduled( 501, P2Flux_WC_Jobs::RECOVER ) ) );

echo "\nminting is serialised by the order's intent lock\n";
$order = new_order( 502 );
respond_create();
p2flux_test_reset_calls();
$token  = P2Flux_WC_Lock::acquire( 'intent-502' );
$busy   = P2Flux_WC_Payments::ensure_intent( $order, context() );
check( 'a concurrent mint is refused while the lock is held', is_wp_error( $busy ) && 'p2flux_intent_cooldown' === $busy->get_error_code() );
check( 'and makes no create request', 0 === count( p2flux_test_calls( '/v1/payments' ) ) );
P2Flux_WC_Lock::release( 'intent-502', $token );
$free = P2Flux_WC_Payments::ensure_intent( $order, context() );
check( 'once the lock is free the mint goes through', is_array( $free ) );

// --- verification ------------------------------------------------------------------------------

echo "\na verified payment pays the order\n";
$order  = new_order( 503 );
respond_create();
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_respond( '/v1/payments/verify', verdict( 1 ) );
$result = P2Flux_WC_Payments::verify( $order, $intent['intent'], tx( 1 ) );
check( 'status is paid', 'paid' === $result['status'] );
check( 'the order is paid with that transaction', $order->is_paid() && tx( 1 ) === $order->completed_with );
check( 'the recovery ladder is dropped', 0 === count( scheduled( 503, P2Flux_WC_Jobs::RECOVER ) ) );

echo "\na settlement for the wrong amount never pays the order\n";
$order  = new_order( 504 );
respond_create();
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_respond( '/v1/payments/verify', verdict( 2, '12.98' ) );
P2Flux_WC_Payments::verify( $order, $intent['intent'], tx( 2 ) );
$flag = json_decode( (string) $order->get_meta( '_p2flux_unexpected_payment' ), true );
check( 'the order is not paid', ! $order->is_paid() );
check( 'the payment is recorded for a human', is_array( $flag ) && tx( 2 ) === $flag['tx_hash'] );

// --- the posted intent ---------------------------------------------------------------------------

echo "\nverification uses the posted intent only when it is this order's\n";
$order  = new_order( 505 );
respond_create();
$old    = P2Flux_WC_Payments::ensure_intent( $order, context() );
// A second intent becomes the active one, as when the customer switches how to pay: age the first
// past the mint cooldown and leave it too little life to be reused.
$ledger = P2Flux_WC_Intents::all( $order );
$ledger[0]['created'] = time() - 600;
$ledger[0]['expires'] = time() + 30;
$order->update_meta_data( P2Flux_WC_Intents::LEDGER_META, wp_json_encode( array( 'v' => 1, 'items' => $ledger ) ) );
respond_create();
$new = P2Flux_WC_Payments::ensure_intent( $order, context() );
check( 'the order now has two intents, the first replaced', 2 === count( P2Flux_WC_Intents::all( $order ) ) && $new['intent'] !== $old['intent'] );
check( 'find() returns the order\'s own intent', null !== P2Flux_WC_Intents::find( $order, $old['intent'] ) );
check( 'find() refuses a token that is not in the ledger', null === P2Flux_WC_Intents::find( $order, 'p2f1.k1.someone-else.mac' ) );

p2flux_test_reset_calls();
p2flux_test_respond( '/v1/payments/verify', verdict( 3 ) );
$sent = ajax(
	'verify',
	array(
		'nonce'     => 'nonce-p2flux_wc',
		'order_id'  => 505,
		'order_key' => 'wc_order_key_505',
		'tx_hash'   => tx( 3 ),
		'intent'    => $old['intent'],
	)
);
$asked = p2flux_test_calls( '/v1/payments/verify' );
check( 'the endpoint answers paid', true === $sent['success'] && 'paid' === $sent['data']['status'] );
check( 'and it verified the intent the window was paying (the replaced one)', 1 === count( $asked ) && $old['intent'] === $asked[0]['payload']['intent'] );

$order = new_order( 506 );
respond_create();
$mine = P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/payments/verify', verdict( 4 ) );
ajax(
	'verify',
	array(
		'nonce'     => 'nonce-p2flux_wc',
		'order_id'  => 506,
		'order_key' => 'wc_order_key_506',
		'tx_hash'   => tx( 4 ),
		'intent'    => 'p2f1.k1.an-intent-from-another-order.mac',
	)
);
$asked = p2flux_test_calls( '/v1/payments/verify' );
check( 'a foreign posted intent is ignored: the order\'s own active intent is verified', 1 === count( $asked ) && $mine['intent'] === $asked[0]['payload']['intent'] );

$refused = ajax( 'verify', array( 'nonce' => 'nonce-p2flux_wc', 'order_id' => 506, 'order_key' => 'wrong', 'tx_hash' => tx( 4 ) ) );
check( 'a wrong order key is refused before anything is asked', false === $refused['success'] );
$refused = ajax( 'verify', array( 'nonce' => 'forged', 'order_id' => 506, 'order_key' => 'wc_order_key_506', 'tx_hash' => tx( 4 ) ) );
check( 'a bad nonce is refused', false === $refused['success'] );
$refused = ajax( 'verify', array( 'nonce' => 'nonce-p2flux_wc', 'order_id' => 506, 'order_key' => 'wc_order_key_506', 'tx_hash' => 'not-a-hash' ) );
check( 'a malformed transaction hash is refused', false === $refused['success'] );

// --- a second payment ----------------------------------------------------------------------------

echo "\na second real payment is flagged for a refund, never applied twice\n";
$order = new_order( 507 );
respond_create();
$a = P2Flux_WC_Payments::ensure_intent( $order, context() );
$ledger = P2Flux_WC_Intents::all( $order );
$ledger[0]['created'] = time() - 600; // past the mint cooldown
$ledger[0]['expires'] = time() + 30;  // too little life left to reuse
$order->update_meta_data( P2Flux_WC_Intents::LEDGER_META, wp_json_encode( array( 'v' => 1, 'items' => $ledger ) ) );
respond_create();
$b = P2Flux_WC_Payments::ensure_intent( $order, context() );
$GLOBALS['p2flux_test_scheduled'] = array();

P2Flux_WC_Payments::settle( $order, $b['intent'], verdict( 10 ) );
check( 'the first settlement pays the order', $order->is_paid() && tx( 10 ) === $order->completed_with );
$sibling = scheduled( 507, P2Flux_WC_Jobs::RECOVER );
check( 'one sibling check is scheduled because the other intent can still settle', 1 === count( $sibling ) );
check( 'after that intent can no longer start a payment', 1 === count( $sibling ) && $sibling[0]['time'] >= (int) $ledger[0]['expires'] + 900 );

$notes = count( $order->notes );
p2flux_test_respond( '/v1/payments/recover', array_merge( array( 'found' => true ), verdict( 11 ) ) );
P2Flux_WC_Jobs::recover_order( 507 );
$flag = json_decode( (string) $order->get_meta( '_p2flux_unexpected_payment' ), true );
check( 'the sibling check finds the second payment on the paid order', is_array( $flag ) && ! empty( $flag['duplicate'] ) && tx( 11 ) === $flag['tx_hash'] );
check( 'payment_complete is not called a second time', tx( 10 ) === $order->completed_with && tx( 10 ) === $order->get_meta( '_p2flux_tx_hash' ) );
check( 'the merchant is told to refund it', count( $order->notes ) === $notes + 1 && false !== strpos( end( $order->notes ), 'second payment' ) );
check( 'that intent stops being polled', 0 === count( P2Flux_WC_Intents::recoverable( $order ) ) );

P2Flux_WC_Payments::settle( $order, $a['intent'], verdict( 11 ) );
check( 'reporting the same second payment again changes nothing', count( $order->notes ) === $notes + 1 );
P2Flux_WC_Payments::settle( $order, $b['intent'], verdict( 10 ) );
check( 'reporting the original payment again changes nothing', count( $order->notes ) === $notes + 1 );

// --- the customer closed the browser -------------------------------------------------------------

echo "\na payment is recovered after the browser closed\n";
$order  = new_order( 508 );
respond_create();
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/payments/recover', array_merge( array( 'found' => true ), verdict( 20 ) ) );
P2Flux_WC_Jobs::recover_order( 508 );
check( 'no verify call was ever made', 0 === count( p2flux_test_calls( '/v1/payments/verify' ) ) );
check( 'recovery pays the order', $order->is_paid() && tx( 20 ) === $order->completed_with );
check( 'and drops the rest of the recovery ladder', 0 === count( scheduled( 508, P2Flux_WC_Jobs::RECOVER ) ) );

$order = new_order( 509 );
respond_create();
P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_respond( '/v1/payments/recover', array( 'found' => false, 'code' => 'PAYMENT_NOT_FOUND' ) );
P2Flux_WC_Jobs::recover_order( 509 );
check( 'nothing found leaves the order unpaid', ! $order->is_paid() );

echo "\n";
echo 0 === $failures
	? "all {$checks} checks passed\n"
	: "{$failures} of {$checks} checks FAILED\n";

exit( 0 === $failures ? 0 : 1 );
