<?php
/**
 * A native subscription product, the cart that may hold one, and the one gateway that may pay it.
 *
 * A product becomes recurring with a checkbox on an ordinary simple product. Its price is the
 * recurring amount, fixed for every customer who buys it while that price stands. The rules that
 * keep the authorization honest are all here: what the product must be (simple, virtual,
 * non-taxable), what the cart must be (that product alone, once, in USD, no coupons or fees), who
 * may buy it (a customer with an account), and how it may be paid (P2Flux, and nothing else -
 * enforced in every layer WooCommerce offers, because a subscription paid through another gateway
 * would be a recurring record with no recurring authorization behind it).
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product, cart and gateway rules for native subscriptions.
 */
class P2Flux_WC_Native_Product {

	const RECURRING_META = '_p2flux_recurring';
	const INTERVAL_META  = '_p2flux_interval';

	/** The Store API payment requirement only the P2Flux gateway satisfies. */
	const REQUIREMENT = 'p2flux_native';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		// Product admin.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

		// Storefront.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 10, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 10, 2 );

		// Cart.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_cart_errors' ), 10, 2 );
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'registration_required' ) );

		// One gateway. UI filter first, then the hard layers.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'only_p2flux' ), 100 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout_gateway' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'guard_processed_order' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'store_api_gateway' ), 10, 2 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'before_pay' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'payment_complete_gate' ), 100, 2 );
		add_filter( 'wc_order_is_editable', array( __CLASS__, 'not_editable' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_failed_order', array( __CLASS__, 'suppress_failed_email' ), 10, 2 );

		// Registered now, not on `woocommerce_blocks_loaded`: that hook has already fired by the time this
		// plugin loads, and the Store API registry accepts registrations any time before a request builds
		// its schema.
		self::register_store_api();
	}

	/*
	 * ---- What a native product is ----
	 */

	/**
	 * Is this product sold as a native subscription?
	 *
	 * @param WC_Product|int $product Product or id.
	 * @return bool
	 */
	public static function is_native_product( $product ) {
		$product = is_object( $product ) ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( $product ) : null );
		if ( ! $product || 'yes' !== $product->get_meta( self::RECURRING_META ) ) {
			return false;
		}

		return null === self::product_problem( $product );
	}

	/**
	 * Why a product cannot be sold as a native subscription, or null when it can.
	 *
	 * @param WC_Product $product Product.
	 * @return string|null 'type' | 'virtual' | 'tax' | 'wcs' | 'interval' | 'price'.
	 */
	public static function product_problem( $product ) {
		if ( ! $product->is_type( 'simple' ) ) {
			return 'type';
		}
		if ( class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
			return 'wcs';
		}
		if ( ! $product->is_virtual() ) {
			return 'virtual';
		}
		if ( 'none' !== $product->get_tax_status() ) {
			return 'tax';
		}
		if ( ! in_array( self::interval( $product ), P2Flux_WC_Calendar::INTERVALS, true ) ) {
			return 'interval';
		}
		$units = P2Flux_WC_Money::to_units( (string) $product->get_price(), '1' );
		if ( null === $units || true !== P2Flux_WC_Money::check_bounds( $units, true ) ) {
			return 'price';
		}

		return null;
	}

	/**
	 * The product's interval.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function interval( $product ) {
		return (string) $product->get_meta( self::INTERVAL_META );
	}

	/**
	 * A problem, in words for a merchant.
	 *
	 * @param string $problem Code.
	 * @return string
	 */
	public static function describe_problem( $problem ) {
		$texts = array(
			'type'     => __( 'P2Flux Native Subscriptions need a simple product.', 'p2flux-for-woocommerce' ),
			'wcs'      => __( 'This product is managed by WooCommerce Subscriptions and cannot also be a P2Flux Native Subscription.', 'p2flux-for-woocommerce' ),
			'virtual'  => __( 'P2Flux Native Subscriptions v1 supports virtual products only.', 'p2flux-for-woocommerce' ),
			'tax'      => __( 'P2Flux Native Subscriptions v1 supports non-taxable products only. Set the tax status to “None”.', 'p2flux-for-woocommerce' ),
			'interval' => __( 'Choose a billing interval for the P2Flux subscription.', 'p2flux-for-woocommerce' ),
			'price'    => __( 'The price is outside what P2Flux can collect as a subscription.', 'p2flux-for-woocommerce' ),
		);

		return isset( $texts[ $problem ] ) ? $texts[ $problem ] : $problem;
	}

	/*
	 * ---- Product admin ----
	 */

	/**
	 * The two fields on the General tab.
	 *
	 * @return void
	 */
	public static function fields() {
		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( ! $product ) {
			return;
		}

		echo '<div class="options_group show_if_simple p2flux-native-fields">';
		woocommerce_wp_checkbox(
			array(
				'id'              => self::RECURRING_META,
				'label'           => __( 'P2Flux recurring subscription', 'p2flux-for-woocommerce' ),
				'description'     => __( 'Sell this product as a subscription paid in USDC through P2Flux. The regular price is the recurring amount. Requires a virtual, non-taxable simple product; paid through P2Flux only.', 'p2flux-for-woocommerce' ),
				'desc_tip'        => true,
				'value'           => 'yes' === $product->get_meta( self::RECURRING_META ) ? 'yes' : 'no',
				'unchecked_value' => 'no',
			),
			$product
		);
		woocommerce_wp_select(
			array(
				'id'      => self::INTERVAL_META,
				'label'   => __( 'Billing interval', 'p2flux-for-woocommerce' ),
				'options' => array(
					''      => __( '— choose —', 'p2flux-for-woocommerce' ),
					'day'   => __( 'Daily', 'p2flux-for-woocommerce' ),
					'week'  => __( 'Weekly', 'p2flux-for-woocommerce' ),
					'month' => __( 'Monthly', 'p2flux-for-woocommerce' ),
					'year'  => __( 'Yearly', 'p2flux-for-woocommerce' ),
				),
				'value'   => self::interval( $product ),
			),
			$product
		);
		echo '</div>';
	}

	/**
	 * Save the fields, refusing a combination the engine cannot honour. Never edits anything else
	 * about the product.
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public static function save( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the meta box nonce.
		$wanted   = isset( $_POST[ self::RECURRING_META ] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST[ self::RECURRING_META ] ) );
		$interval = isset( $_POST[ self::INTERVAL_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::INTERVAL_META ] ) ) : '';
		// phpcs:enable

		$product->update_meta_data( self::INTERVAL_META, in_array( $interval, P2Flux_WC_Calendar::INTERVALS, true ) ? $interval : '' );

		if ( ! $wanted ) {
			$product->update_meta_data( self::RECURRING_META, 'no' );

			return;
		}

		$problem = self::product_problem( $product );
		if ( null !== $problem ) {
			$product->update_meta_data( self::RECURRING_META, 'no' );
			set_transient( 'p2flux_wc_product_notice_' . get_current_user_id(), self::describe_problem( $problem ), MINUTE_IN_SECONDS );

			return;
		}

		$product->update_meta_data( self::RECURRING_META, 'yes' );
	}

	/**
	 * The refusal a merchant sees after saving.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		$key  = 'p2flux_wc_product_notice_' . get_current_user_id();
		$text = get_transient( $key );
		if ( ! $text ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( __( 'P2Flux recurring was not enabled: %s', 'p2flux-for-woocommerce' ), $text ) ) );
	}

	/*
	 * ---- Storefront ----
	 */

	/**
	 * "$29 / month".
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		if ( '' === $html || ! self::is_native_product( $product ) ) {
			return $html;
		}

		$labels = array(
			'day'   => __( '/ day', 'p2flux-for-woocommerce' ),
			'week'  => __( '/ week', 'p2flux-for-woocommerce' ),
			'month' => __( '/ month', 'p2flux-for-woocommerce' ),
			'year'  => __( '/ year', 'p2flux-for-woocommerce' ),
		);
		$interval = self::interval( $product );

		return $html . ' <span class="p2flux-interval">' . esc_html( isset( $labels[ $interval ] ) ? $labels[ $interval ] : '' ) . '</span>';
	}

	/**
	 * One per cart, always.
	 *
	 * @param bool       $sold    Sold individually.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function sold_individually( $sold, $product ) {
		return self::is_native_product( $product ) ? true : $sold;
	}

	/**
	 * A flagged product that no longer meets the rules is not for sale as a subscription, and never
	 * silently for sale as a one-off either.
	 *
	 * @param bool       $purchasable Purchasable.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public static function purchasable( $purchasable, $product ) {
		if ( 'yes' === $product->get_meta( self::RECURRING_META ) && null !== self::product_problem( $product ) ) {
			return false;
		}

		return $purchasable;
	}

	/*
	 * ---- The cart ----
	 */

	/**
	 * The native product in the cart, if any.
	 *
	 * @return WC_Product|null
	 */
	public static function cart_native_product() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] && 'yes' === $item['data']->get_meta( self::RECURRING_META ) ) {
				return $item['data'];
			}
		}

		return null;
	}

	/**
	 * Why the cart cannot be sold as a native subscription, or null when it can (or holds none).
	 *
	 * @return string|null
	 */
	public static function cart_problem() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$native = self::cart_native_product();
		if ( ! $native ) {
			return null;
		}

		$problem = self::product_problem( $native );
		if ( null !== $problem ) {
			return self::describe_problem( $problem );
		}
		if ( 'USD' !== get_woocommerce_currency() ) {
			return __( 'P2Flux Native Subscriptions are sold in US dollars only.', 'p2flux-for-woocommerce' );
		}

		$lines = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$lines++;
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product || $product->get_id() !== $native->get_id() ) {
				return __( 'A subscription is bought on its own. Please remove the other items, or buy the subscription separately.', 'p2flux-for-woocommerce' );
			}
			if ( (int) $item['quantity'] !== 1 ) {
				return __( 'A subscription is bought one at a time.', 'p2flux-for-woocommerce' );
			}
		}
		if ( 1 !== $lines ) {
			return __( 'A subscription is bought on its own.', 'p2flux-for-woocommerce' );
		}
		if ( WC()->cart->get_applied_coupons() ) {
			return __( 'Coupons cannot be applied to a P2Flux subscription.', 'p2flux-for-woocommerce' );
		}
		if ( WC()->cart->get_fees() ) {
			return __( 'Fees cannot be added to a P2Flux subscription.', 'p2flux-for-woocommerce' );
		}
		if ( ! is_user_logged_in() && 'yes' !== get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) && 'yes' === get_option( 'woocommerce_enable_guest_checkout' ) ) {
			return __( 'A customer account is required for a subscription. Please log in or create an account first.', 'p2flux-for-woocommerce' );
		}
		if ( ! self::gateway_available() ) {
			return __( 'P2Flux is required to purchase this subscription and is currently unavailable.', 'p2flux-for-woocommerce' );
		}

		return null;
	}

	/**
	 * Adding to the cart: a native product only into an empty cart, anything else only when no
	 * native product is there.
	 *
	 * @param bool $passed     Passed so far.
	 * @param int  $product_id Product.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function validate_add( $passed, $product_id, $quantity ) {
		if ( ! $passed ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $passed;
		}

		$adding_native = 'yes' === $product->get_meta( self::RECURRING_META );
		$in_cart       = self::cart_native_product();

		if ( $adding_native ) {
			$problem = self::product_problem( $product );
			if ( null !== $problem ) {
				wc_add_notice( self::describe_problem( $problem ), 'error' );

				return false;
			}
			if ( (int) $quantity > 1 ) {
				wc_add_notice( __( 'A subscription is bought one at a time.', 'p2flux-for-woocommerce' ), 'error' );

				return false;
			}
			if ( function_exists( 'WC' ) && WC()->cart && WC()->cart->get_cart_contents_count() > 0 && ( ! $in_cart || $in_cart->get_id() !== $product->get_id() ) ) {
				wc_add_notice( __( 'A subscription is bought on its own. Please empty your cart first, or buy the subscription separately.', 'p2flux-for-woocommerce' ), 'error' );

				return false;
			}

			return true;
		}

		if ( $in_cart ) {
			wc_add_notice( __( 'Your cart holds a subscription, which is bought on its own. Please complete it first, or remove it.', 'p2flux-for-woocommerce' ), 'error' );

			return false;
		}

		return $passed;
	}

	/**
	 * Cart and classic checkout pages.
	 *
	 * @return void
	 */
	public static function check_cart() {
		$problem = self::cart_problem();
		if ( null !== $problem ) {
			wc_add_notice( $problem, 'error' );
		}
	}

	/**
	 * Store API (block cart and checkout).
	 *
	 * @param WP_Error $errors Errors.
	 * @param WC_Cart  $cart   Cart.
	 * @return void
	 */
	public static function store_api_cart_errors( $errors, $cart ) {
		unset( $cart );
		$problem = self::cart_problem();
		if ( null !== $problem ) {
			$errors->add( 'p2flux_native_cart', $problem );
		}
	}

	/**
	 * A subscription belongs to an account.
	 *
	 * @param bool $required Required.
	 * @return bool
	 */
	public static function registration_required( $required ) {
		return self::cart_native_product() ? true : $required;
	}

	/*
	 * ---- One gateway ----
	 */

	/**
	 * Can the P2Flux gateway be used right now?
	 *
	 * @return bool
	 */
	public static function gateway_available() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( empty( $gateways['p2flux'] ) ) {
			return false;
		}
		$gateway = $gateways['p2flux'];

		return 'yes' === $gateway->get_option( 'enabled' )
			&& P2Flux_WC_Money::supported_platform()
			&& function_exists( 'sodium_crypto_secretbox' )
			&& P2Flux_WC_Gateway::valid_recipient( $gateway->get_option( 'recipient' ) );
	}

	/**
	 * Does an order belong to a native subscription (parent or renewal)?
	 *
	 * @param WC_Order|null $order Order.
	 * @return bool
	 */
	public static function is_native_order( $order ) {
		return $order && (int) $order->get_meta( P2Flux_WC_Subscriptions::NATIVE_META ) > 0;
	}

	/**
	 * Is the current context a native purchase: a native cart, or a native order on order-pay?
	 *
	 * @return bool
	 */
	public static function native_context() {
		if ( self::cart_native_product() ) {
			return true;
		}
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( $order_id ) {
			return self::is_native_order( wc_get_order( $order_id ) );
		}

		return false;
	}

	/**
	 * UI layer: for a native purchase, P2Flux is the only gateway offered.
	 *
	 * @param array $gateways Available gateways.
	 * @return array
	 */
	public static function only_p2flux( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( ! self::native_context() ) {
			return $gateways;
		}

		return isset( $gateways['p2flux'] ) ? array( 'p2flux' => $gateways['p2flux'] ) : array();
	}

	/**
	 * The one check every hard layer calls: is this a P2Flux payment for a native purchase?
	 *
	 * @param string $payment_method Submitted method.
	 * @return string|null The refusal, or null when fine.
	 */
	public static function assert_p2flux_payment( $payment_method ) {
		if ( 'p2flux' === $payment_method ) {
			return self::gateway_available() ? null : __( 'P2Flux is required to purchase this subscription and is currently unavailable.', 'p2flux-for-woocommerce' );
		}

		return __( 'This subscription can only be paid through P2Flux.', 'p2flux-for-woocommerce' );
	}

	/**
	 * Classic checkout: the submitted payment method, before an order exists.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 * @return void
	 */
	public static function validate_checkout_gateway( $data, $errors ) {
		if ( ! self::cart_native_product() ) {
			return;
		}
		$refusal = self::assert_p2flux_payment( isset( $data['payment_method'] ) ? (string) $data['payment_method'] : '' );
		if ( null !== $refusal ) {
			$errors->add( 'p2flux_native_gateway', $refusal );
		}
	}

	/**
	 * Classic checkout: after the order exists, before payment. A plugin that bypassed validation
	 * still cannot proceed.
	 *
	 * @param int      $order_id Order.
	 * @param array    $data     Posted data.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public static function guard_processed_order( $order_id, $data, $order ) {
		unset( $order_id, $data );
		if ( ! self::is_native_order( $order ) && ! self::cart_native_product() ) {
			return;
		}
		$refusal = self::assert_p2flux_payment( $order->get_payment_method() );
		if ( null !== $refusal ) {
			$order->add_order_note( __( 'P2Flux: this order holds a native subscription product but was submitted with another payment method. Refused.', 'p2flux-for-woocommerce' ) );
			$order->save();
			P2Flux_WC_Logger::error( 'native purchase submitted with another gateway', array( 'order' => $order->get_id(), 'method' => $order->get_payment_method() ) );
			throw new Exception( esc_html( $refusal ) );
		}
	}

	/**
	 * Store API checkout: the submitted payment method.
	 *
	 * @param WC_Order        $order   Order being built.
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public static function store_api_gateway( $order, $request ) {
		if ( ! self::cart_native_product() && ! self::is_native_order( $order ) ) {
			return;
		}
		$method  = (string) $request['payment_method'];
		$refusal = self::assert_p2flux_payment( '' !== $method ? $method : $order->get_payment_method() );
		if ( null !== $refusal ) {
			P2Flux_WC_Logger::error( 'native purchase submitted with another gateway (Store API)', array( 'order' => $order->get_id(), 'method' => $method ) );
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'p2flux_native_gateway', esc_html( $refusal ), 400 );
		}
	}

	/**
	 * Order-pay: the submitted payment method for a native parent or renewal order.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return void
	 */
	public static function before_pay( $order ) {
		if ( ! self::is_native_order( $order ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the pay form nonce before this action.
		$method  = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		$refusal = self::assert_p2flux_payment( $method );
		if ( null !== $refusal ) {
			wc_add_notice( $refusal, 'error' );
			P2Flux_WC_Logger::error( 'native order-pay submitted with another gateway', array( 'order' => $order->get_id(), 'method' => $method ) );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}
	}

	/**
	 * The last gate on money: a native order can only be completed by P2Flux.
	 *
	 * @param array    $statuses Statuses that may complete.
	 * @param WC_Order $order    Order.
	 * @return array
	 */
	public static function payment_complete_gate( $statuses, $order ) {
		if ( ! self::is_native_order( $order ) || 'p2flux' === $order->get_payment_method() ) {
			return $statuses;
		}
		P2Flux_WC_Logger::error( 'payment_complete refused: native order, non-P2Flux method', array( 'order' => $order->get_id(), 'method' => $order->get_payment_method() ) );

		return array();
	}

	/**
	 * Renewal orders keep the authorized amount: no line edits in admin.
	 *
	 * @param bool     $editable Editable.
	 * @param WC_Order $order    Order.
	 * @return bool
	 */
	public static function not_editable( $editable, $order ) {
		return self::is_native_order( $order ) && 'p2flux_native_renewal' === $order->get_created_via() ? false : $editable;
	}

	/**
	 * Woo's generic failed-order email says "try a different payment method": wrong for a renewal.
	 * The native engine sends its own, with the actual reason and a link to act.
	 *
	 * @param bool          $enabled Enabled.
	 * @param WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function suppress_failed_email( $enabled, $order ) {
		return $order && self::is_native_order( $order ) ? false : $enabled;
	}

	/**
	 * Store API: the payment requirement and the cart line's label.
	 *
	 * @return void
	 */
	public static function register_store_api() {
		if ( function_exists( 'woocommerce_store_api_register_payment_requirements' ) ) {
			woocommerce_store_api_register_payment_requirements(
				array(
					'data_callback' => static function () {
						return P2Flux_WC_Native_Product::cart_native_product() ? array( P2Flux_WC_Native_Product::REQUIREMENT ) : array();
					},
				)
			);
		}
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) && class_exists( 'Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema' ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
					'namespace'       => 'p2flux',
					'data_callback'   => static function ( $cart_item ) {
						$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
						if ( ! $product || ! P2Flux_WC_Native_Product::is_native_product( $product ) ) {
							return array( 'recurring' => '' );
						}
						$labels = array( 'day' => __( 'per day', 'p2flux-for-woocommerce' ), 'week' => __( 'per week', 'p2flux-for-woocommerce' ), 'month' => __( 'per month', 'p2flux-for-woocommerce' ), 'year' => __( 'per year', 'p2flux-for-woocommerce' ) );
						$interval = P2Flux_WC_Native_Product::interval( $product );

						return array( 'recurring' => isset( $labels[ $interval ] ) ? $labels[ $interval ] : '' );
					},
					'schema_callback' => static function () {
						return array( 'recurring' => array( 'description' => __( 'Billing interval, when this line is a P2Flux subscription.', 'p2flux-for-woocommerce' ), 'type' => 'string', 'readonly' => true ) );
					},
				)
			);
		}
	}
}
