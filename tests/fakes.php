<?php
/**
 * Enough of WooCommerce, WooCommerce Subscriptions and the database to run the money paths offline.
 *
 * The two classes replaced here - the lock and the period table - are thin adapters over `$wpdb`,
 * and faking a database well enough to test them would be testing the fake. What they GUARANTEE is
 * faked exactly: one holder per lock, one row per `(authorization, period)`. Everything above them
 * is the real code, including the charger, the renewal decisions and the reconciliation.
 *
 * The API is a stub transport, so a test can say "this charge answers ALREADY_CHARGED" and then
 * assert that no order was paid, that exactly one request was sent, and that the right job was
 * queued.
 *
 * @package P2Flux_For_WooCommerce
 */

require_once __DIR__ . '/shims.php';

$GLOBALS['p2flux_test_orders']        = array();
$GLOBALS['p2flux_test_subscriptions'] = array();
$GLOBALS['p2flux_test_scheduled']     = array();
$GLOBALS['p2flux_test_notes']         = array();
$GLOBALS['p2flux_test_locks']         = array();
$GLOBALS['p2flux_test_periods']       = array();
$GLOBALS['p2flux_test_calls']         = array();
$GLOBALS['p2flux_test_responses']     = array();
$GLOBALS['p2flux_test_filters']       = array();

/**
 * The lock, in memory, with the one property that matters: a single holder.
 */
class P2Flux_WC_Lock {

	const TTL = 120;

	/**
	 * @param int $subscription_id Subscription.
	 * @return string|false
	 */
	public static function acquire( $subscription_id ) {
		$held = isset( $GLOBALS['p2flux_test_locks'][ $subscription_id ] ) ? $GLOBALS['p2flux_test_locks'][ $subscription_id ] : null;
		if ( $held && $held['expires'] > time() ) {
			return false;
		}

		$token = 'token-' . wp_generate_password( 8 );
		$GLOBALS['p2flux_test_locks'][ $subscription_id ] = array(
			'token'   => $token,
			'expires' => time() + self::TTL,
		);

		return $token;
	}

	/**
	 * @param int    $subscription_id Subscription.
	 * @param string $token           Token.
	 * @return bool
	 */
	public static function still_ours( $subscription_id, $token ) {
		$held = isset( $GLOBALS['p2flux_test_locks'][ $subscription_id ] ) ? $GLOBALS['p2flux_test_locks'][ $subscription_id ] : null;

		return $held && $held['token'] === $token && $held['expires'] > time();
	}

	/**
	 * @param int    $subscription_id Subscription.
	 * @param string $token           Token.
	 * @return void
	 */
	public static function release( $subscription_id, $token ) {
		$held = isset( $GLOBALS['p2flux_test_locks'][ $subscription_id ] ) ? $GLOBALS['p2flux_test_locks'][ $subscription_id ] : null;
		if ( $held && $held['token'] === $token ) {
			unset( $GLOBALS['p2flux_test_locks'][ $subscription_id ] );
		}
	}

	/**
	 * @param int      $subscription_id Subscription.
	 * @param callable $work            Work.
	 * @return mixed
	 */
	public static function with( $subscription_id, $work ) {
		$token = self::acquire( $subscription_id );
		if ( false === $token ) {
			return false;
		}
		try {
			return call_user_func( $work, $token );
		} finally {
			self::release( $subscription_id, $token );
		}
	}

	/**
	 * Simulate a worker whose lease expired while it was waiting on the network.
	 *
	 * @param int $subscription_id Subscription.
	 * @return void
	 */
	public static function expire( $subscription_id ) {
		if ( isset( $GLOBALS['p2flux_test_locks'][ $subscription_id ] ) ) {
			$GLOBALS['p2flux_test_locks'][ $subscription_id ]['expires'] = time() - 1;
		}
	}
}

/**
 * The period ledger, in memory, with its unique key enforced.
 */
class P2Flux_WC_Periods {

	const CLAIMED     = 'claimed';
	const CHARGING    = 'charging';
	const RECONCILING = 'reconciling';
	const SETTLED     = 'settled';
	const MANUAL      = 'manually_satisfied';
	const CONFLICT    = 'conflict';

	/**
	 * @param string $auth_id      Authorization.
	 * @param int    $period_index Period.
	 * @return array|null
	 */
	public static function get( $auth_id, $period_index ) {
		$key = strtolower( $auth_id ) . ':' . (int) $period_index;

		return isset( $GLOBALS['p2flux_test_periods'][ $key ] ) ? $GLOBALS['p2flux_test_periods'][ $key ] : null;
	}

	/**
	 * @param array $claim Claim.
	 * @return array|false
	 */
	public static function claim( array $claim ) {
		$key = strtolower( $claim['auth_id'] ) . ':' . (int) $claim['period_index'];

		if ( ! isset( $GLOBALS['p2flux_test_periods'][ $key ] ) ) {
			$GLOBALS['p2flux_test_periods'][ $key ] = array(
				'auth_id'      => strtolower( $claim['auth_id'] ),
				'period_index' => (int) $claim['period_index'],
				'order_id'     => (int) $claim['order_id'],
				'state'        => self::CLAIMED,
				'tx_hash'      => null,
			);
		}

		$row = $GLOBALS['p2flux_test_periods'][ $key ];

		return (int) $row['order_id'] === (int) $claim['order_id'] ? $row : false;
	}

	/**
	 * @param string $auth_id      Authorization.
	 * @param int    $period_index Period.
	 * @param string $state        State.
	 * @param array  $extra        tx_hash, order_id.
	 * @return void
	 */
	public static function set_state( $auth_id, $period_index, $state, array $extra = array() ) {
		$key = strtolower( $auth_id ) . ':' . (int) $period_index;
		if ( ! isset( $GLOBALS['p2flux_test_periods'][ $key ] ) ) {
			return;
		}

		$GLOBALS['p2flux_test_periods'][ $key ]['state'] = $state;
		if ( isset( $extra['tx_hash'] ) ) {
			$GLOBALS['p2flux_test_periods'][ $key ]['tx_hash'] = $extra['tx_hash'];
		}
	}

	/**
	 * @param string $auth_id      Authorization.
	 * @param int    $period_index Period.
	 * @param int    $order_id     Order.
	 * @return bool
	 */
	/**
	 * @param int $before Unix seconds.
	 * @return array
	 */
	public static function stale_charging( $before ) {
		$out = array();
		foreach ( $GLOBALS['p2flux_test_periods'] as $row ) {
			if ( self::CHARGING === $row['state'] && (int) $row['updated'] < (int) $before ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	public static function owned_by( $auth_id, $period_index, $order_id ) {
		$row = self::get( $auth_id, $period_index );

		return $row && (int) $row['order_id'] === (int) $order_id;
	}

	/**
	 * @param int $order_id Order.
	 * @return array
	 */
	public static function for_order( $order_id ) {
		$rows = array();
		foreach ( $GLOBALS['p2flux_test_periods'] as $row ) {
			if ( (int) $row['order_id'] === (int) $order_id ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @return void
	 */
	public static function install() {
	}
}

/**
 * A WooCommerce order, as far as this plugin is concerned.
 */
class P2Flux_Test_Order extends P2Flux_Test_Object {

	/** @var string */
	public $total = '12.99';

	/** @var bool */
	public $paid = false;

	/** @var string */
	public $payment_method = 'p2flux';

	/** @var array<int,string> */
	public $notes = array();

	/** @var string */
	public $completed_with = '';

	/** @return string */
	public function get_total() {
		return $this->total;
	}

	/** @return bool */
	public function is_paid() {
		return $this->paid;
	}

	/** @return string */
	public function get_payment_method() {
		return $this->payment_method;
	}

	/**
	 * @param string $hash Transaction.
	 * @return bool
	 */
	public function payment_complete( $hash = '' ) {
		$this->paid           = true;
		$this->completed_with = $hash;
		$this->set_status( 'processing' );

		return true;
	}

	/**
	 * @param string $note Note.
	 * @return int
	 */
	public function add_order_note( $note ) {
		$this->notes[] = $note;

		return count( $this->notes );
	}

	/**
	 * @param string $status Status.
	 * @param string $note   Note.
	 * @return void
	 */
	public function update_status( $status, $note = '' ) {
		$this->set_status( $status );
		if ( '' !== $note ) {
			$this->add_order_note( $note );
		}
	}

	/** @return string */
	public function get_checkout_order_received_url() {
		return 'https://shop.test/order-received/' . $this->get_id();
	}

	/** @return float */
	public function get_total_refunded() {
		return 0.0;
	}
}

/**
 * A WooCommerce subscription.
 */
class P2Flux_Test_Subscription extends P2Flux_Test_Order {

	/** @var string */
	public $billing_period = 'month';

	/** @var int */
	public $billing_interval = 1;

	/** @var array<int,int> */
	public $related = array();

	/** @return string */
	public function get_billing_period() {
		return $this->billing_period;
	}

	/** @return int */
	public function get_billing_interval() {
		return $this->billing_interval;
	}

	/**
	 * @param string $type Return type.
	 * @return array<int,int>
	 */
	public function get_related_orders( $type = 'ids' ) {
		unset( $type );

		return $this->related;
	}

	/**
	 * @param string $status Status.
	 * @return bool
	 */
	public function can_be_updated_to( $status ) {
		unset( $status );

		return true;
	}

	/** @return int */
	public function get_user_id() {
		return 1;
	}
}

/**
 * Register an order with the fake store.
 *
 * @param P2Flux_Test_Order $order Order.
 * @return P2Flux_Test_Order
 */
function p2flux_test_register_order( $order ) {
	$GLOBALS['p2flux_test_orders'][ $order->get_id() ] = $order;

	return $order;
}

/**
 * Register a subscription.
 *
 * @param P2Flux_Test_Subscription $subscription Subscription.
 * @return P2Flux_Test_Subscription
 */
function p2flux_test_register_subscription( $subscription ) {
	$GLOBALS['p2flux_test_subscriptions'][ $subscription->get_id() ] = $subscription;

	return $subscription;
}

/**
 * Queue an API response for a path.
 *
 * @param string $path     Endpoint path.
 * @param array  $response Decoded body.
 * @param int    $status   HTTP status.
 * @return void
 */
function p2flux_test_respond( $path, array $response, $status = 200 ) {
	$GLOBALS['p2flux_test_responses'][ $path ] = array( $status, $response );
}

/**
 * Every request the plugin made.
 *
 * @param string $path Optional path filter.
 * @return array
 */
function p2flux_test_calls( $path = '' ) {
	if ( '' === $path ) {
		return $GLOBALS['p2flux_test_calls'];
	}

	return array_values(
		array_filter(
			$GLOBALS['p2flux_test_calls'],
			static function ( $call ) use ( $path ) {
				return $call['path'] === $path;
			}
		)
	);
}

/**
 * Forget requests and scheduled jobs, so one test measures one thing.
 *
 * @return void
 */
function p2flux_test_reset_calls() {
	$GLOBALS['p2flux_test_calls']     = array();
	$GLOBALS['p2flux_test_scheduled'] = array();
}

// --- WordPress and WooCommerce functions the plugin calls ---------------------------------------

/**
 * @param int $id Order id.
 * @return P2Flux_Test_Order|null
 */
function wc_get_order( $id ) {
	return isset( $GLOBALS['p2flux_test_orders'][ $id ] ) ? $GLOBALS['p2flux_test_orders'][ $id ] : null;
}

/**
 * @param int $id Subscription id.
 * @return P2Flux_Test_Subscription|null
 */
function wcs_get_subscription( $id ) {
	return isset( $GLOBALS['p2flux_test_subscriptions'][ $id ] ) ? $GLOBALS['p2flux_test_subscriptions'][ $id ] : null;
}

/**
 * @param P2Flux_Test_Order $order Order.
 * @return array<int,P2Flux_Test_Subscription>
 */
function wcs_get_subscriptions_for_renewal_order( $order ) {
	$found = array();
	foreach ( $GLOBALS['p2flux_test_subscriptions'] as $subscription ) {
		if ( in_array( (int) $order->get_id(), $subscription->related, true ) ) {
			$found[ $subscription->get_id() ] = $subscription;
		}
	}

	return $found;
}

/**
 * @param P2Flux_Test_Order $order Order.
 * @param array             $args  Args.
 * @return array
 */
function wcs_get_subscriptions_for_order( $order, $args = array() ) {
	unset( $args );

	return wcs_get_subscriptions_for_renewal_order( $order );
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain = '' ) {
	unset( $domain );

	return $text;
}

/**
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return void
 */
function esc_html_e( $text, $domain = '' ) {
	unset( $domain );
	echo $text;
}

/**
 * @param string $tag   Filter.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	if ( ! isset( $GLOBALS['p2flux_test_filters'][ $tag ] ) ) {
		return $value;
	}

	return call_user_func_array( $GLOBALS['p2flux_test_filters'][ $tag ], array_slice( $args, 1 ) );
}

/**
 * @param string   $tag      Filter.
 * @param callable $callback Callback.
 * @return void
 */
function p2flux_test_filter( $tag, $callback ) {
	$GLOBALS['p2flux_test_filters'][ $tag ] = $callback;
}

/**
 * The stub transport: canned responses, and a record of everything asked.
 *
 * @return callable
 */
function p2flux_test_transport() {
	return static function ( $url, $payload, $timeout ) {
		unset( $timeout );

		$path = wp_parse_url_path( $url );

		$GLOBALS['p2flux_test_calls'][] = array(
			'path'    => $path,
			'payload' => $payload,
		);

		if ( isset( $GLOBALS['p2flux_test_responses'][ $path ] ) ) {
			return $GLOBALS['p2flux_test_responses'][ $path ];
		}

		return array( 200, array() );
	};
}

/**
 * @param string $url URL.
 * @return string
 */
function wp_parse_url_path( $url ) {
	$parts = parse_url( $url );

	return isset( $parts['path'] ) ? $parts['path'] : $url;
}

/**
 * @param string $hook  Hook.
 * @param int    $order Order id.
 * @param int    $delay Delay.
 * @return void
 */
function p2flux_test_record_schedule( $hook, $order, $delay ) {
	$GLOBALS['p2flux_test_scheduled'][] = array(
		'hook'  => $hook,
		'order' => $order,
		'delay' => $delay,
	);
}

/**
 * Jobs that were scheduled since the last reset.
 *
 * @return array
 */
function p2flux_test_scheduled() {
	return $GLOBALS['p2flux_test_scheduled'];
}

/**
 * WP_Error, in miniature.
 */
class WP_Error {

	/** @var string */
	private $code;

	/** @var string */
	private $message;

	/**
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/** @return string */
	public function get_error_code() {
		return $this->code;
	}

	/** @return string */
	public function get_error_message() {
		return $this->message;
	}
}

/**
 * @param mixed $thing Value.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// --- Action Scheduler ---------------------------------------------------------------------------

/**
 * @param int    $timestamp When.
 * @param string $hook      Hook.
 * @param array  $args      Args.
 * @param string $group     Group.
 * @return int
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	unset( $group );
	$GLOBALS['p2flux_test_scheduled'][] = array(
		'hook'  => $hook,
		'order' => isset( $args[0] ) ? (int) $args[0] : 0,
		'delay' => max( 0, $timestamp - time() ),
		'time'  => (int) $timestamp,
	);

	return count( $GLOBALS['p2flux_test_scheduled'] );
}

/**
 * @param string $hook  Hook.
 * @param array  $args  Args.
 * @param string $group Group.
 * @return int|false Timestamp of the pending action, or false.
 */
function as_next_scheduled_action( $hook, $args = array(), $group = '' ) {
	unset( $group );
	$next = false;
	foreach ( $GLOBALS['p2flux_test_scheduled'] as $job ) {
		if ( $job['hook'] === $hook && ( empty( $args ) || (int) $job['order'] === (int) $args[0] ) ) {
			$next = false === $next ? $job['time'] : min( $next, $job['time'] );
		}
	}

	return $next;
}

/**
 * @param int    $timestamp When.
 * @param int    $interval  Interval.
 * @param string $hook      Hook.
 * @param array  $args      Args.
 * @param string $group     Group.
 * @return int
 */
function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '' ) {
	unset( $interval, $group );

	return as_schedule_single_action( $timestamp, $hook, $args );
}

/**
 * @param string $hook  Hook.
 * @param array  $args  Args.
 * @param string $group Group.
 * @return bool
 */
function as_has_scheduled_action( $hook, $args = array(), $group = '' ) {
	unset( $group );
	foreach ( $GLOBALS['p2flux_test_scheduled'] as $job ) {
		if ( $job['hook'] === $hook && ( empty( $args ) || (int) $job['order'] === (int) $args[0] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * @param string $hook  Hook.
 * @param array  $args  Args.
 * @param string $group Group.
 * @return void
 */
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	unset( $group );
	$GLOBALS['p2flux_test_scheduled'] = array_values(
		array_filter(
			$GLOBALS['p2flux_test_scheduled'],
			static function ( $job ) use ( $hook, $args ) {
				if ( $job['hook'] !== $hook ) {
					return true;
				}

				return ! empty( $args ) && (int) $job['order'] !== (int) $args[0];
			}
		)
	);
}

$GLOBALS['p2flux_test_doing'] = array();

/**
 * @param string $hook Hook.
 * @return bool
 */
function doing_action( $hook ) {
	return in_array( $hook, $GLOBALS['p2flux_test_doing'], true );
}

/**
 * @param string   $hook     Hook.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $args     Args.
 * @return void
 */
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	unset( $hook, $callback, $priority, $args );
}

/**
 * @param string   $hook     Hook.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $args     Args.
 * @return void
 */
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	unset( $hook, $callback, $priority, $args );
}
