<?php
/**
 * What the native engine needs from WooCommerce and the database, in memory.
 *
 * Loaded after fakes.php. The store keeps rows in an array and enforces the compare-and-set exactly
 * as the table does; orders are the existing test orders with a little more surface; products are
 * plain objects with the meta the engine reads.
 *
 * @package P2Flux_For_WooCommerce
 */

$GLOBALS['p2flux_test_native_rows']  = array();
$GLOBALS['p2flux_test_products']     = array();
$GLOBALS['p2flux_test_next_order']   = 5000;
$GLOBALS['p2flux_test_unscheduled']  = array();
$GLOBALS['p2flux_test_actions_done'] = array();

/**
 * The native table, in memory.
 */
class P2Flux_WC_Native_Store {

	/** @var int */
	private static $next = 1;

	/**
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function row( $id ) {
		return isset( $GLOBALS['p2flux_test_native_rows'][ $id ] ) ? $GLOBALS['p2flux_test_native_rows'][ $id ] : null;
	}

	/**
	 * @param array $row Row.
	 * @return int
	 */
	public static function insert( array $row ) {
		$id                                          = self::$next++;
		$row['id']                                   = $id;
		$GLOBALS['p2flux_test_native_rows'][ $id ]   = $row;

		return $id;
	}

	/**
	 * @param int   $id      Id.
	 * @param int   $version Version.
	 * @param array $columns Columns.
	 * @return bool
	 */
	public static function update_cas( $id, $version, array $columns ) {
		if ( ! isset( $GLOBALS['p2flux_test_native_rows'][ $id ] ) ) {
			return false;
		}
		if ( (int) $GLOBALS['p2flux_test_native_rows'][ $id ]['meta_version'] !== (int) $version ) {
			return false;
		}
		foreach ( $columns as $column => $value ) {
			$GLOBALS['p2flux_test_native_rows'][ $id ][ $column ] = $value;
		}
		$GLOBALS['p2flux_test_native_rows'][ $id ]['meta_version'] = (int) $version + 1;

		return true;
	}

	/**
	 * @param int $user_id User.
	 * @return array
	 */
	public static function rows_for_user( $user_id ) {
		$rows = array_filter( $GLOBALS['p2flux_test_native_rows'], static function ( $row ) use ( $user_id ) {
			return (int) $row['user_id'] === (int) $user_id;
		} );
		krsort( $rows );

		return array_values( $rows );
	}

	/**
	 * @param int $limit  Limit.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function rows_all( $limit, $offset ) {
		$rows = $GLOBALS['p2flux_test_native_rows'];
		krsort( $rows );

		return array_slice( array_values( $rows ), $offset, $limit );
	}

	/** @return int */
	public static function count_all() {
		return count( $GLOBALS['p2flux_test_native_rows'] );
	}

	/**
	 * @param int $before Unix seconds.
	 * @return array
	 */
	public static function rows_due_before( $before ) {
		$at  = gmdate( 'Y-m-d H:i:s', (int) $before );
		$out = array();
		foreach ( $GLOBALS['p2flux_test_native_rows'] as $row ) {
			if ( in_array( $row['status'], array( 'active', 'on-hold' ), true ) && ! empty( $row['next_payment_at'] ) && $row['next_payment_at'] <= $at ) {
				$out[] = $row;
			} elseif ( 'pending' === $row['status'] && ! empty( $row['activation_deadline'] ) && $row['activation_deadline'] <= $at ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Simulate another writer touching a row's meta between a read and a write.
	 *
	 * @param int      $id  Id.
	 * @param callable $fn  Receives the decoded meta, returns it changed.
	 * @return void
	 */
	public static function interleave( $id, $fn ) {
		$row               = $GLOBALS['p2flux_test_native_rows'][ $id ];
		$meta              = json_decode( $row['meta'], true );
		$row['meta']       = wp_json_encode( $fn( is_array( $meta ) ? $meta : array() ) );
		$row['meta_version']++;
		$GLOBALS['p2flux_test_native_rows'][ $id ] = $row;
	}
}

/**
 * A product with the meta the engine reads.
 */
class P2Flux_Test_Product extends P2Flux_Test_Object {

	/** @var string */
	public $type = 'simple';
	/** @var bool */
	public $virtual = true;
	/** @var string */
	public $tax_status = 'none';
	/** @var string */
	public $price = '10';
	/** @var string */
	public $name = 'Test plan';

	/**
	 * @param string $type Type.
	 * @return bool
	 */
	public function is_type( $type ) {
		return $this->type === $type;
	}

	/** @return bool */
	public function is_virtual() {
		return $this->virtual;
	}

	/** @return string */
	public function get_tax_status() {
		return $this->tax_status;
	}

	/** @return string */
	public function get_price() {
		return $this->price;
	}

	/** @return string */
	public function get_name() {
		return $this->name;
	}
}

/**
 * @param int $id Product id.
 * @return P2Flux_Test_Product|null
 */
function wc_get_product( $id ) {
	return isset( $GLOBALS['p2flux_test_products'][ $id ] ) ? $GLOBALS['p2flux_test_products'][ $id ] : null;
}

/**
 * An order with the extra surface the native engine uses.
 */
class P2Flux_Test_Native_Order extends P2Flux_Test_Order {

	/** @var int */
	public $customer_id = 1;
	/** @var string */
	public $created_via = 'checkout';
	/** @var int */
	public $parent_id = 0;
	/** @var array */
	public $items = array();
	/** @var array */
	public $props = array();
	/** @var string */
	public $currency = 'USD';
	/** @var array */
	public $coupons = array();
	/** @var array */
	public $fees = array();

	/** @return int */
	public function get_customer_id() {
		return $this->customer_id; }
	/** @return string */
	public function get_created_via() {
		return $this->created_via; }
	/** @return string */
	public function get_currency() {
		return $this->currency; }
	/** @param string $c Currency. */
	public function set_currency( $c ) {
		$this->currency = $c; }
	/** @return array */
	public function get_items() {
		return $this->items; }
	/** @param object $item Item. */
	public function add_item( $item ) {
		$this->items[] = $item; }
	/** @param array $props Props. */
	public function set_props( $props ) {
		$this->props = array_merge( $this->props, $props ); }
	/** @param string $type Type. @return array */
	public function get_address( $type = 'billing' ) {
		return array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'buyer@example.test', 'country' => 'SI' ); }
	/** @param string $m Method. */
	public function set_payment_method( $m ) {
		$this->payment_method = $m; }
	/** @param string $t Title. */
	public function set_payment_method_title( $t ) {
		$this->props['payment_method_title'] = $t; }
	/** @param bool $taxes Taxes. */
	public function calculate_totals( $taxes = true ) {
		$sum = 0.0;
		foreach ( $this->items as $item ) {
			$sum += (float) $item->get_total();
		}
		$this->total = number_format( $sum, 2, '.', '' ); }
	/** @return string */
	public function get_billing_email() {
		return 'buyer@example.test'; }
	/** @return string */
	public function get_order_number() {
		return (string) $this->get_id(); }
	/** @return string */
	public function get_checkout_payment_url() {
		return 'https://shop.test/pay/' . $this->get_id(); }
	/** @return array */
	public function get_coupon_codes() {
		return $this->coupons; }
	/** @return array */
	public function get_fees() {
		return $this->fees; }
}

/**
 * Orders created by the renewal factory.
 *
 * @param array $args Args.
 * @return P2Flux_Test_Native_Order
 */
function wc_create_order( $args = array() ) {
	$id    = $GLOBALS['p2flux_test_next_order']++;
	$order = new P2Flux_Test_Native_Order( $id, isset( $args['status'] ) ? $args['status'] : 'pending' );
	$order->customer_id = isset( $args['customer_id'] ) ? (int) $args['customer_id'] : 0;
	$order->created_via = isset( $args['created_via'] ) ? (string) $args['created_via'] : '';
	$order->parent_id   = isset( $args['parent'] ) ? (int) $args['parent'] : 0;
	$order->paid        = false;

	return p2flux_test_register_order( $order );
}

/**
 * The renewal factory's line item.
 */
class WC_Order_Item_Product {

	/** @var array */
	public $props = array();

	/** @param mixed $p Product. */
	public function set_product( $p ) {
		$this->props['product'] = $p; }
	/** @param string $n Name. */
	public function set_name( $n ) {
		$this->props['name'] = $n; }
	/** @param int $q Quantity. */
	public function set_quantity( $q ) {
		$this->props['quantity'] = $q; }
	/** @param string $s Subtotal. */
	public function set_subtotal( $s ) {
		$this->props['subtotal'] = $s; }
	/** @param string $t Total. */
	public function set_total( $t ) {
		$this->props['total'] = $t; }
	/** @param string $c Class. */
	public function set_tax_class( $c ) {
		$this->props['tax_class'] = $c; }
	/** @return int */
	public function get_quantity() {
		return isset( $this->props['quantity'] ) ? (int) $this->props['quantity'] : 1; }
	/** @return string */
	public function get_total() {
		return isset( $this->props['total'] ) ? $this->props['total'] : '0'; }
}

/**
 * Orders by native subscription meta.
 *
 * @param array $args Args.
 * @return array<int,int>
 */
function wc_get_orders( $args ) {
	$out = array();
	if ( isset( $args['meta_query'][0]['key'] ) ) {
		$key   = $args['meta_query'][0]['key'];
		$value = (string) $args['meta_query'][0]['value'];
		foreach ( $GLOBALS['p2flux_test_orders'] as $order ) {
			if ( (string) $order->get_meta( $key ) === $value ) {
				$out[] = $order->get_id();
			}
		}
	}
	sort( $out );

	return $out;
}

/**
 * Pending native renewal jobs, by id.
 *
 * @param int $id Native id.
 * @return array
 */
function p2flux_test_native_jobs( $id ) {
	$out = array();
	foreach ( $GLOBALS['p2flux_test_scheduled'] as $job ) {
		if ( P2Flux_WC_Native_Scheduler::HOOK === $job['hook'] && (int) $job['order'] === (int) $id ) {
			$out[] = $job;
		}
	}

	return $out;
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $n      Count.
	 * @param string $domain Domain.
	 * @return string
	 */
	function _n( $single, $plural, $n, $domain = '' ) {
		unset( $domain );

		return 1 === (int) $n ? $single : $plural;
	}
}
