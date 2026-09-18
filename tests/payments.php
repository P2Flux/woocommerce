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
require __DIR__ . '/../includes/class-p2flux-wc-sponsorship.php';
require __DIR__ . '/../includes/class-p2flux-wc-checkout-page.php';
require __DIR__ . '/../includes/class-p2flux-wc-refunds.php';
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


// --- sponsored checkout: paying the network fee in USDC -----------------------------------------

use P2FluxWC\Vendor\P2Flux\P2FluxException;

function wp_create_nonce( $action ) {
	return 'nonce-' . $action;
}
class WC_AJAX {
	public static function get_endpoint( $endpoint ) {
		return '/?wc-ajax=' . $endpoint;
	}
}

/** What the pay page would hand the browser, without rendering it. */
function pay_config( $order ) {
	$gateway = new class() {
		public function rate() {
			return '1';
		}
		public function get_option( $key ) {
			return 'recipient' === $key ? MERCHANT : '';
		}
	};
	$method = new ReflectionMethod( 'P2Flux_WC_Checkout_Page', 'payment_config' );
	$method->setAccessible( true );
	return $method->invoke( null, $order, $gateway );
}

function sponsorship( $on ) {
	update_option( 'woocommerce_p2flux_settings', array( 'environment' => 'test', 'sponsored' => $on ? 'yes' : 'no' ) );
	$GLOBALS['p2flux_test_transients'] = array();
}

function caps( array $token = array(), array $top = array() ) {
	p2flux_test_respond(
		'/v1/capabilities',
		array_merge(
			array(
				'chain_id'  => 84532,
				'supported' => true,
				'tokens'    => array(
					array_merge(
						array(
							'symbol'                  => 'USDC',
							'gas_payment_modes'       => array( 'native', 'payment_token' ),
							'fixed_network_fee_units' => '100000',
							'operations'              => array( 'one_time_payment' => true ),
							'some_future_key'         => array( 'ignored' => true ),
						),
						$token
					),
				),
			),
			$top
		)
	);
}

function with_units( $units ) {
	return array_merge( context(), array( 'units' => $units ) );
}

function cached_caps() {
	return isset( $GLOBALS['p2flux_test_transients']['p2flux_wc_caps_test'] ) ? $GLOBALS['p2flux_test_transients']['p2flux_wc_caps_test'] : null;
}

function last_create() {
	$calls = p2flux_test_calls( '/v1/payments' );
	return end( $calls )['payload'];
}

echo "\nsponsorship available: the intent is minted with the network fee in USDC\n";
sponsorship( true );
caps();
respond_create();
p2flux_test_reset_calls();
$order  = new_order( 601 );
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
check( 'the intent is sponsored', 'sponsored' === $intent['mode'] );
check( 'the create request asks for payment_token', 'payment_token' === ( last_create()['gas_payment_mode'] ?? null ) );
check( 'capabilities were asked once', 1 === count( p2flux_test_calls( '/v1/capabilities' ) ) );
check( 'the answer is cached for an hour', HOUR_IN_SECONDS === cached_caps()['ttl'] );

echo "\nthe capabilities answer is cached\n";
p2flux_test_reset_calls();
$intent = P2Flux_WC_Payments::ensure_intent( new_order( 602 ), context() );
check( 'a second order is sponsored too', 'sponsored' === $intent['mode'] );
check( 'without asking capabilities again', 0 === count( p2flux_test_calls( '/v1/capabilities' ) ) );

echo "\nsponsorship unavailable: every doubtful answer means native\n";
$variants = array(
	'supported is false'         => static function () { caps( array(), array( 'supported' => false ) ); },
	'one_time_payment is false'  => static function () { caps( array( 'operations' => array( 'one_time_payment' => false ) ) ); },
	'no tokens'                  => static function () { caps( array(), array( 'tokens' => array() ) ); },
	'no payment_token mode'      => static function () { caps( array( 'gas_payment_modes' => array( 'native' ) ) ); },
	'a fixed fee that is not a number' => static function () { caps( array( 'fixed_network_fee_units' => 100000 ) ); },
	'HTTP 500'                   => static function () { p2flux_test_respond( '/v1/capabilities', array( 'error' => 'INTERNAL_ERROR' ), 500 ); },
	'the API is unreachable'     => static function () {
		p2flux_test_respond( '/v1/capabilities', static function () { throw new P2FluxException( 'NETWORK_ERROR', 'RETRY_LATER' ); } );
	},
);
$id = 610;
foreach ( $variants as $label => $arrange ) {
	sponsorship( true );
	$arrange();
	respond_create();
	p2flux_test_reset_calls();
	$intent = P2Flux_WC_Payments::ensure_intent( new_order( ++$id ), context() );
	check( "{$label}: native intent, no mode sent, failure cached for 5 minutes",
		'native' === $intent['mode'] && ! array_key_exists( 'gas_payment_mode', last_create() ) && array() === cached_caps()['value'] && 300 === cached_caps()['ttl'] );
}

echo "\nsponsorship switched off: exactly the 1.0.0 request\n";
sponsorship( false );
caps();
respond_create();
p2flux_test_reset_calls();
$intent = P2Flux_WC_Payments::ensure_intent( new_order( 620 ), context() );
check( 'no capabilities call', 0 === count( p2flux_test_calls( '/v1/capabilities' ) ) );
check( 'the create payload is recipient and amount only', array( 'recipient', 'amount' ) === array_keys( last_create() ) && 'native' === $intent['mode'] );
update_option( 'woocommerce_p2flux_settings', array( 'environment' => 'test' ) );
check( 'a settings array without the key (an upgraded 1.0.0 install) is off', ! P2Flux_WC_Sponsorship::enabled() );

echo "\nthe sponsored floor is checked locally\n";
sponsorship( true );
caps();
respond_create();
check( '101010 cannot carry the fees', ! P2Flux_WC_Sponsorship::covers( 101010, 100000 ) );
check( '101011 can', P2Flux_WC_Sponsorship::covers( 101011, 100000 ) );
check( '101010 units mints native', 'native' === P2Flux_WC_Payments::ensure_intent( new_order( 621 ), with_units( 101010 ) )['mode'] );
check( '101011 units mints sponsored', 'sponsored' === P2Flux_WC_Payments::ensure_intent( new_order( 622 ), with_units( 101011 ) )['mode'] );

echo "\na refused sponsored create falls back to native, once\n";
foreach ( array( 'PAYMENT_TOKEN_GAS_UNAVAILABLE' => 503, 'PAYMENT_TOKEN_GAS_UNSUPPORTED' => 400, 'AMOUNT_OUT_OF_BOUNDS' => 400 ) as $refusal => $http ) {
	sponsorship( true );
	caps();
	p2flux_test_respond(
		'/v1/payments',
		static function ( $payload ) use ( $refusal, $http ) {
			if ( isset( $payload['gas_payment_mode'] ) ) {
				return array( $http, array( 'error' => $refusal ) );
			}
			return array( 200, array( 'intent' => 'p2f1.k1.native-' . ( ++$GLOBALS['minted'] ) . '.mac', 'expires_at' => time() + 3600 ) );
		}
	);
	p2flux_test_reset_calls();
	$order  = new_order( 630 + $http + strlen( $refusal ) );
	$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
	$ledger = P2Flux_WC_Intents::all( $order );
	check( "{$refusal}: two creates, the second native",
		2 === count( p2flux_test_calls( '/v1/payments' ) ) && ! array_key_exists( 'gas_payment_mode', last_create() ) );
	check( "{$refusal}: one native ledger record", is_array( $intent ) && 1 === count( $ledger ) && 'native' === $ledger[0]['mode'] );
	check( "{$refusal}: sponsorship cached as unavailable for 5 minutes", array() === cached_caps()['value'] && 300 === cached_caps()['ttl'] );
}

echo "\na timeout or a rate limit is never retried\n";
foreach ( array(
	'NETWORK_ERROR' => static function () { throw new P2FluxException( 'NETWORK_ERROR', 'RETRY_LATER' ); },
	'RATE_LIMITED'  => static function () { return array( 429, array( 'error' => 'RATE_LIMITED' ) ); },
) as $label => $answer ) {
	sponsorship( true );
	caps();
	p2flux_test_respond( '/v1/payments', $answer );
	p2flux_test_reset_calls();
	$order  = new_order( 640 + strlen( $label ) );
	$result = P2Flux_WC_Payments::ensure_intent( $order, context() );
	check( "{$label}: one create request only", 1 === count( p2flux_test_calls( '/v1/payments' ) ) );
	check( "{$label}: an error, no intent, capabilities still trusted", is_wp_error( $result ) && 0 === count( P2Flux_WC_Intents::all( $order ) ) && isset( cached_caps()['value']['fixed'] ) );
}

echo "\nthe pay page carries the active intent, and a switch survives a reload\n";
sponsorship( true );
caps();
respond_create();
p2flux_test_reset_calls();
$order  = new_order( 650 );
$config = pay_config( $order );
$first  = P2Flux_WC_Intents::active( $order );
check( 'config token is the active intent', $first['intent'] === $config['token'] && 'pay' === $config['mode'] );
check( 'a just-minted intent is not re-checked', false === $config['recheck'] );
check( 'config says sponsored and offers ETH', 'sponsored' === $config['gas'] && 'native' === $config['switchTo'] );
check( 'the hosted checkout is the test one', 'https://pay-test.p2flux.com' === $config['checkout'] && '/?wc-ajax=p2flux_mode' === $config['ajax']['mode'] );

p2flux_test_respond( '/v1/payments/recover', array( 'found' => false, 'code' => 'PAYMENT_NOT_FOUND' ) );
$sent = ajax( 'mode', array( 'nonce' => 'nonce-p2flux_wc', 'order_id' => 650, 'order_key' => 'wc_order_key_650', 'mode' => 'native' ) );
check( 'switching to ETH inside the mint cooldown is allowed', true === $sent['success'] && 'switched' === $sent['data']['status'] && 'native' === $sent['data']['mode'] );
check( 'it asked whether the first intent was paid before minting', 1 === count( p2flux_test_calls( '/v1/payments/recover' ) ) );
check( 'the native create sent no mode', ! array_key_exists( 'gas_payment_mode', last_create() ) && 2 === count( p2flux_test_calls( '/v1/payments' ) ) );

$again = ajax( 'mode', array( 'nonce' => 'nonce-p2flux_wc', 'order_id' => 650, 'order_key' => 'wc_order_key_650', 'mode' => 'sponsored' ) );
check( 'a second switch within 10 seconds is refused', false === $again['success'] && 'COOLDOWN' === $again['data']['code'] );

$config = pay_config( $order );
check( 'a reload keeps native and mints nothing', 'native' === $config['gas'] && $sent['data']['token'] === $config['token'] && 2 === count( p2flux_test_calls( '/v1/payments' ) ) );
check( 'and offers the way back', 'sponsored' === $config['switchTo'] );
$ledger = P2Flux_WC_Intents::all( $order );
$ledger[ count( $ledger ) - 1 ]['created'] = time() - 600;
$order->update_meta_data( P2Flux_WC_Intents::LEDGER_META, wp_json_encode( array( 'v' => 1, 'items' => $ledger ) ) );
check( 'coming back to an older intent asks the page to check for a payment first', true === pay_config( $order )['recheck'] );

unset( $GLOBALS['p2flux_test_transients']['p2flux_wc_mode_650'] );
$back = ajax( 'mode', array( 'nonce' => 'nonce-p2flux_wc', 'order_id' => 650, 'order_key' => 'wc_order_key_650', 'mode' => 'sponsored' ) );
check( 'switching back reuses the first intent', true === $back['success'] && $first['intent'] === $back['data']['token'] && 'sponsored' === $back['data']['mode'] );
check( 'still two creates and two ledger rows', 2 === count( p2flux_test_calls( '/v1/payments' ) ) && 2 === count( P2Flux_WC_Intents::all( $order ) ) );
check( 'and the first intent is active again', $first['intent'] === P2Flux_WC_Intents::active( $order )['intent'] );

$bad = ajax( 'mode', array( 'nonce' => 'nonce-p2flux_wc', 'order_id' => 650, 'order_key' => 'wc_order_key_650', 'mode' => 'free' ) );
check( 'an unknown mode is refused', false === $bad['success'] && 'INVALID_MODE' === $bad['data']['code'] );
$bad = ajax( 'mode', array( 'nonce' => 'nonce-p2flux_wc', 'order_id' => 650, 'order_key' => 'wrong', 'mode' => 'native' ) );
check( 'a wrong order key is refused', false === $bad['success'] && 'FORBIDDEN' === $bad['data']['code'] );

echo "\na switch is refused where it makes no sense, and never mints over a payment\n";
p2flux_test_reset_calls();
$order = new_order( 651 );
P2Flux_WC_Payments::ensure_intent( $order, context() );
$order->paid = true;
check( 'a paid order answers paid', 'paid' === P2Flux_WC_Payments::switch_mode( $order, 'native' )['status'] );

$order = new_order( 652 );
P2Flux_WC_Payments::ensure_intent( $order, context() );
$order->payment_method = 'bacs';
check( 'an order paid another way is refused', is_wp_error( P2Flux_WC_Payments::switch_mode( $order, 'native' ) ) );

$order        = new_order( 653 );
P2Flux_WC_Payments::ensure_intent( $order, context() );
$subscription = new P2Flux_Test_Subscription( 9653 );
$subscription->related = array( 653 );
p2flux_test_register_subscription( $subscription );
check( 'a subscription parent is refused', is_wp_error( P2Flux_WC_Payments::switch_mode( $order, 'native' ) ) );
$GLOBALS['p2flux_test_subscriptions'] = array();

$order = new_order( 654 );
$held  = P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/payments/recover', array_merge( array( 'found' => true, 'gas_payment_mode' => 'payment_token' ), verdict( 30 ) ) );
$result = P2Flux_WC_Payments::switch_mode( $order, 'native' );
check( 'a payment found by the pre-check answers paid', 'paid' === $result['status'] && $order->is_paid() && tx( 30 ) === $order->completed_with );
check( 'and mints nothing', 0 === count( p2flux_test_calls( '/v1/payments' ) ) && 1 === count( P2Flux_WC_Intents::all( $order ) ) );

$order = new_order( 655 );
P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/payments/recover', array( 'found' => true, 'valid' => false, 'code' => 'PAYMENT_CONFIRMING', 'tx_hash' => tx( 31 ) ) );
check( 'a payment still confirming answers confirming and mints nothing', 'confirming' === P2Flux_WC_Payments::switch_mode( $order, 'native' )['status'] && 0 === count( p2flux_test_calls( '/v1/payments' ) ) );

echo "\nsponsorship turned off while an order holds a sponsored intent\n";
$order = new_order( 656 );
P2Flux_WC_Payments::ensure_intent( $order, context() );
sponsorship( false );
respond_create();
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
check( 'the next load downgrades it to native', 'native' === $intent['mode'] && 2 === count( P2Flux_WC_Intents::all( $order ) ) );
sponsorship( true );
caps();

echo "\na verified sponsored payment\n";
respond_create();
$order  = new_order( 660 );
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
$payer  = '0x4e21000000000000000000000000000000000be2';
p2flux_test_respond(
	'/v1/payments/verify',
	array_merge(
		verdict( 40 ),
		array(
			'gas_payment_mode' => 'payment_token',
			'accounting'       => array(
				'payment_units'           => '12990000',
				'payment_fee_units'       => '129900',
				'network_fee_units'       => '4702',
				'fixed_network_fee_units' => '100000',
				'merchant_net_units'      => '12760100',
				'buyer_total_units'       => '12994702',
				'payer'                   => $payer,
			),
		)
	)
);
$result = P2Flux_WC_Payments::verify( $order, $intent['intent'], tx( 40 ) );
check( 'pays the order', 'paid' === $result['status'] && $order->is_paid() );
check( 'stores the gas mode', 'sponsored' === $order->get_meta( '_p2flux_gas_mode' ) );
check( 'notes what the merchant received and the network fee', 1 === count( array_filter( $order->notes, static function ( $n ) { return false !== strpos( $n, '12.7601 USDC' ) && false !== strpos( $n, '0.004702 USDC' ); } ) ) );
check( 'never stores the payer', false === strpos( serialize( array( $order->notes, $order ) ), $payer ) );

echo "\na settlement that does not match is never paid\n";
$order  = new_order( 661 );
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_respond( '/v1/payments/verify', array_merge( verdict( 41, '12.994702' ), array( 'gas_payment_mode' => 'payment_token' ) ) );
P2Flux_WC_Payments::verify( $order, $intent['intent'], tx( 41 ) );
check( 'the buyer total is not the price: not paid, flagged', ! $order->is_paid() && '' !== (string) $order->get_meta( '_p2flux_unexpected_payment' ) );

$order  = new_order( 662 );
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
$order->update_meta_data( '_p2flux_units', PRICE + 1000000 ); // the order total changed after the intent
p2flux_test_respond( '/v1/payments/verify', array_merge( verdict( 42 ), array( 'gas_payment_mode' => 'payment_token' ) ) );
P2Flux_WC_Payments::verify( $order, $intent['intent'], tx( 42 ) );
check( 'an order whose total changed: not paid, flagged', ! $order->is_paid() && '' !== (string) $order->get_meta( '_p2flux_unexpected_payment' ) );

echo "\na sponsored payment is recovered after the browser closed\n";
$order  = new_order( 663 );
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/payments/recover', array_merge( array( 'found' => true, 'gas_payment_mode' => 'payment_token' ), verdict( 43 ) ) );
P2Flux_WC_Jobs::recover_order( 663 );
check( 'recovery pays it without any verify call', $order->is_paid() && 0 === count( p2flux_test_calls( '/v1/payments/verify' ) ) );
check( 'with the gas mode stored', 'sponsored' === $order->get_meta( '_p2flux_gas_mode' ) );
check( 'and the recovery ladder dropped', 0 === count( scheduled( 663, P2Flux_WC_Jobs::RECOVER ) ) );


echo "\nscheduler health\n";
$GLOBALS['p2flux_test_scheduled'] = array();
as_schedule_recurring_action( time() + 3600, DAY_IN_SECONDS, P2Flux_WC_Jobs::SWEEP, array(), P2Flux_WC_Jobs::GROUP );
check( 'the daily sweep pending in the future: healthy', '' === P2Flux_WC_Jobs::health() );
as_schedule_single_action( time() - 50 * MINUTE_IN_SECONDS, P2Flux_WC_Jobs::RECOVER, array( 700 ), P2Flux_WC_Jobs::GROUP );
define( 'DISABLE_WP_CRON', true );
check( 'a job 50 minutes late under a server cron (WP-Cron disabled): no warning', '' === P2Flux_WC_Jobs::health() );
as_schedule_single_action( time() - 3 * HOUR_IN_SECONDS, 'some_other_plugin_job', array(), 'other' );
check( 'another plugin\'s overdue job is not ours', '' === P2Flux_WC_Jobs::health() );
as_schedule_single_action( time() - 2 * HOUR_IN_SECONDS - 60, P2Flux_WC_Jobs::RECOVER, array( 701 ), P2Flux_WC_Jobs::GROUP );
check( 'a P2Flux job more than two hours overdue: late', 'late' === P2Flux_WC_Jobs::health() );
$GLOBALS['p2flux_test_scheduled'][ count( $GLOBALS['p2flux_test_scheduled'] ) - 1 ]['status'] = 'complete';
check( 'once it has run, healthy again', '' === P2Flux_WC_Jobs::health() );
$missing = shell_exec(
	escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg(
		'define("ABSPATH", "/"); define("HOUR_IN_SECONDS", 3600); define("MINUTE_IN_SECONDS", 60); define("DAY_IN_SECONDS", 86400);'
		. ' require ' . var_export( __DIR__ . '/../includes/class-p2flux-wc-jobs.php', true ) . '; echo P2Flux_WC_Jobs::health();'
	)
);
check( 'no Action Scheduler at all: missing', 'missing' === $missing, (string) $missing );
$source = '';
foreach ( glob( __DIR__ . '/../includes/*.php' ) as $file ) {
	foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
		// Code only: the docblock that explains why it is not read may name it.
		$source .= ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) ? '' : ( is_array( $token ) ? $token[1] : $token );
	}
}
check( 'DISABLE_WP_CRON is never read by the plugin', false === strpos( $source, 'DISABLE_WP_CRON' ) );


echo "\nrefunding a sponsored payment: in full, once\n";
$GLOBALS['wc_refunds'] = array();
function wc_create_refund( $args ) {
	$GLOBALS['wc_refunds'][] = $args;
	return (object) $args;
}
$order = wc_get_order( 660 ); // paid above, sponsored
p2flux_test_reset_calls();
p2flux_test_respond( '/v1/refunds/prepare', array( 'refund_token' => 'rt_1', 'refund_amount' => '12990000' ) );
$prepared = P2Flux_WC_Refunds::prepare( $order );
$asked    = p2flux_test_calls( '/v1/refunds/prepare' );
check( 'the refund is prepared for the price the order paid, not the buyer total', is_array( $prepared ) && '12990000' === $asked[0]['payload']['amount'] && $order->get_meta( '_p2flux_settled_intent' ) === $asked[0]['payload']['intent'] );
p2flux_test_respond( '/v1/refunds/verify', array( 'status' => 'REFUNDED', 'refund_tx_hash' => tx( 50 ) ) );
$done = P2Flux_WC_Refunds::verify( $order, tx( 50 ) );
check( 'a verified refund records one WooCommerce refund of the order total', 'refunded' === $done['status'] && 1 === count( $GLOBALS['wc_refunds'] ) && '12.99' === $GLOBALS['wc_refunds'][0]['amount'] && false === $GLOBALS['wc_refunds'][0]['refund_payment'] );
$again = P2Flux_WC_Refunds::verify( $order, tx( 50 ) );
check( 'verifying it again records nothing more', 'refunded' === $again['status'] && 1 === count( $GLOBALS['wc_refunds'] ) );
check( 'and a second refund cannot be prepared', is_wp_error( P2Flux_WC_Refunds::prepare( $order ) ) );

$order  = new_order( 664 );
$intent = P2Flux_WC_Payments::ensure_intent( $order, context() );
p2flux_test_respond( '/v1/payments/verify', array_merge( verdict( 51 ), array( 'gas_payment_mode' => 'payment_token' ) ) );
P2Flux_WC_Payments::verify( $order, $intent['intent'], tx( 51 ) );
P2Flux_WC_Refunds::prepare( $order );
p2flux_test_respond( '/v1/refunds/verify', array( 'error' => 'REFUND_AMOUNT_INVALID' ), 400 );
check( 'a refused refund is an error', is_wp_error( P2Flux_WC_Refunds::verify( $order, tx( 52 ) ) ) );
p2flux_test_respond( '/v1/refunds/verify', array( 'valid' => false, 'code' => 'REFUND_RECIPIENT_MISMATCH' ) );
check( 'an unmatched transfer is an error', is_wp_error( P2Flux_WC_Refunds::verify( $order, tx( 52 ) ) ) );
check( 'and neither records a WooCommerce refund', 1 === count( $GLOBALS['wc_refunds'] ) && P2Flux_WC_Refunds::REFUNDED !== P2Flux_WC_Refunds::state( $order )['status'] );

echo "\n";
echo 0 === $failures
	? "all {$checks} checks passed\n"
	: "{$failures} of {$checks} checks FAILED\n";

exit( 0 === $failures ? 0 : 1 );
