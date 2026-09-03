<?php
/**
 * The WooCommerce side: settings, availability, checkout, and the renewal hook.
 *
 * This class is deliberately thin. It knows what WooCommerce wants and translates; the decisions
 * live in the classes it calls, where they can be tested without a store. What it does own is the
 * refusal to sell something this gateway cannot honour - a subscription with a free trial, a
 * sign-up fee, a currency it cannot convert - because every one of those is a checkout that would
 * succeed and a renewal that could never collect.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * P2Flux payment gateway.
 */
class P2Flux_WC_Gateway extends WC_Payment_Gateway {

	/**
	 * Wire it up.
	 */
	public function __construct() {
		$this->id                 = 'p2flux';
		/* The brand mark, so the method is recognisable in a list of payment options rather than being
		 * the one line of plain text among logos. WooCommerce prints it beside the title. */
		$this->icon               = plugins_url( 'assets/p2flux-mark.svg', P2FLUX_WC_FILE );
		$this->method_title       = __( 'P2Flux', 'p2flux-for-woocommerce' );
		$this->method_description = __( 'Accept USDC on Base directly to your own wallet. Non-custodial: payments go from the customer’s wallet to yours, and P2Flux never holds your money.', 'p2flux-for-woocommerce' );
		$this->has_fields         = false;

		/*
		 * `refunds` is deliberately absent. A P2Flux refund is a transfer from the merchant's OWN
		 * wallet, which no server-side call can make - so Woo's refund button would promise
		 * something this gateway cannot do. The order screen gets its own box instead.
		 *
		 * The four subscription flags are all load-bearing: WCS refuses the on-hold and active
		 * transitions its own renewal cycle needs unless suspension and reactivation are declared.
		 */
		$this->supports = array(
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'p2flux-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable P2Flux', 'p2flux-for-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'p2flux-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What customers see at checkout.', 'p2flux-for-woocommerce' ),
				'default'     => __( 'Pay with USDC', 'p2flux-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'   => __( 'Description', 'p2flux-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay in USDC from your own wallet on Base. No account needed.', 'p2flux-for-woocommerce' ),
			),
			'environment' => array(
				'title'       => __( 'Environment', 'p2flux-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					P2Flux_WC_Client::TEST => __( 'Test — Base Sepolia, faucet money', 'p2flux-for-woocommerce' ),
					P2Flux_WC_Client::LIVE => __( 'Live — Base Mainnet, real USDC', 'p2flux-for-woocommerce' ),
				),
				'default'     => P2Flux_WC_Client::TEST,
				'description' => __( 'Orders and subscriptions keep the environment they were created in, so switching this never affects existing ones.', 'p2flux-for-woocommerce' ),
			),
			'recipient'   => array(
				'title'       => __( 'Payout wallet', 'p2flux-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => '0x…',
				'description' => __( 'Your own wallet address on Base. Payments arrive here directly. Changing it affects new payments and new subscriptions only: existing subscriptions keep paying the wallet the customer authorized, including when they re-authorize.', 'p2flux-for-woocommerce' ),
			),
			'rate_mode'   => array(
				'title'       => __( 'Exchange rate', 'p2flux-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'auto'   => __( 'Fetch automatically (Coinbase)', 'p2flux-for-woocommerce' ),
					'manual' => __( 'Fixed rate I set below', 'p2flux-for-woocommerce' ),
				),
				'default'     => 'auto',
				'description' => __( 'Only used when your store currency is not USD. USD is always 1:1 with USDC.', 'p2flux-for-woocommerce' ),
			),
			'manual_rate' => array(
				'title'       => __( 'Store currency per 1 USDC', 'p2flux-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => '0.92',
				'description' => __( 'For a euro store where 1 USDC costs 0.92 EUR, enter 0.92.', 'p2flux-for-woocommerce' ),
			),
			'allowance'   => array(
				'title'       => __( 'USDC approval for subscriptions', 'p2flux-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'unlimited' => __( 'Unlimited — one approval, the wallet is never asked again (default)', 'p2flux-for-woocommerce' ),
					'12'        => __( '12 billing periods, then the customer approves again', 'p2flux-for-woocommerce' ),
					'24'        => __( '24 billing periods, then the customer approves again', 'p2flux-for-woocommerce' ),
					'36'        => __( '36 billing periods, then the customer approves again', 'p2flux-for-woocommerce' ),
				),
				'default'     => 'unlimited',
				'description' => __( 'How much USDC spending the customer’s wallet approves for the P2Flux recurring contract at signup. The approval can only ever be used for the terms the customer signed; a bounded one limits what a fault in that contract could reach, at the cost of a wallet prompt when it runs out. Existing subscriptions keep the approval they were set up with.', 'p2flux-for-woocommerce' ),
			),
			'debug'       => array(
				'title'   => __( 'Debug log', 'p2flux-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log P2Flux activity (WooCommerce → Status → Logs). Payment references are never written to the log.', 'p2flux-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Is this gateway usable right now, for this cart?
	 *
	 * Availability is the honest place to refuse. Hiding the method at checkout costs a sale;
	 * accepting a subscription this gateway can never renew costs the merchant a customer and a
	 * support thread.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return false;
		}
		if ( ! P2Flux_WC_Money::supported_platform() || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return false;
		}
		if ( ! self::valid_recipient( $this->get_option( 'recipient' ) ) ) {
			return false;
		}
		if ( null === $this->rate() ) {
			return false;
		}

		if ( is_admin() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return true;
		}

		if ( self::cart_has_subscription() ) {
			return true === $this->subscription_cart_supported();
		}

		return true;
	}

	/**
	 * The store-currency-per-USDC rate, or null when there is none to use.
	 *
	 * @return string|null
	 */
	public function rate() {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

		if ( 'USD' === $currency ) {
			return '1';
		}

		if ( 'manual' === $this->get_option( 'rate_mode' ) ) {
			$manual = trim( (string) $this->get_option( 'manual_rate' ) );

			return ( '' !== $manual && null !== P2Flux_WC_Money::to_scaled( $manual ) ) ? $manual : null;
		}

		return P2Flux_WC_Rates::fetch( $currency );
	}

	/**
	 * Can this gateway honour the subscription in the cart?
	 *
	 * @return true|string True, or a reason code.
	 */
	public function subscription_cart_supported() {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

		/*
		 * USD only, and this is a commercial limit rather than a technical one. A recurring
		 * authorization fixes ONE USDC amount for its whole life; a euro-priced subscription would
		 * drift away from its own price with the exchange rate, and neither the customer nor the
		 * merchant would have agreed to what it became.
		 */
		if ( 'USD' !== $currency ) {
			return 'currency';
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return true;
		}

		/*
		 * The cart's own contents, not `recurring_carts`.
		 *
		 * WooCommerce only fills `recurring_carts` once totals have been calculated, and this is asked
		 * in contexts where they have not been - the block checkout builds its payment method list
		 * early. Reading the items works everywhere and answers the same question.
		 */
		$subscriptions = 0;
		$one_offs      = 0;
		$recurring     = 0.0;

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product ) {
				continue;
			}

			if ( ! class_exists( 'WC_Subscriptions_Product' ) || ! WC_Subscriptions_Product::is_subscription( $product ) ) {
				$one_offs++;
				continue;
			}

			$subscriptions++;
			$recurring += (float) ( isset( $item['line_total'] ) ? $item['line_total'] : 0 );
			$recurring += (float) ( isset( $item['line_tax'] ) ? $item['line_tax'] : 0 );

			// A free trial or a sign-up fee makes the first payment differ from the recurring one,
			// and the signed authorization has room for exactly one amount and one start.
			if ( WC_Subscriptions_Product::get_trial_length( $product ) > 0 ) {
				return 'trial';
			}
			if ( (float) WC_Subscriptions_Product::get_sign_up_fee( $product ) > 0 ) {
				return 'signup_fee';
			}
		}

		if ( $subscriptions < 1 ) {
			return true;
		}
		// One authorization covers one subscription.
		if ( $subscriptions > 1 ) {
			return 'multiple';
		}
		/*
		 * Anything else in the cart makes the first payment differ from the renewals - a one-off
		 * product alongside the subscription, most often. The authorization carries ONE amount, so
		 * this cannot be honoured, and the honest place to say so is here: offering the method and
		 * then refusing at the last click of checkout is the worst of both.
		 */
		if ( $one_offs > 0 ) {
			return 'initial_differs';
		}

		$units = P2Flux_WC_Money::to_units( wc_format_decimal( $recurring, 6 ), '1' );
		if ( null === $units || true !== P2Flux_WC_Money::check_bounds( $units, true ) ) {
			return 'amount';
		}

		return true;
	}

	/**
	 * Start a payment.
	 *
	 * Both flows end the same way: the customer goes to the order-pay page, and the popup opens from
	 * a button there. That is not indirection for its own sake - a popup has to open from a real
	 * click or the browser blocks it, and a fragment token must never travel through a redirect.
	 *
	 * @param int $order_id Order.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$rate = $this->rate();
		if ( null === $rate ) {
			wc_add_notice( __( 'The USDC exchange rate is unavailable, so this payment method cannot be used right now.', 'p2flux-for-woocommerce' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$prepared = $this->is_subscription_order( $order )
			? $this->prepare_subscription( $order, $rate )
			: $this->prepare_one_time( $order, $rate );

		if ( is_wp_error( $prepared ) ) {
			wc_add_notice( $prepared->get_error_message(), 'error' );

			return array( 'result' => 'failure' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Mint (or reuse) a one-time intent for this order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $rate  Store currency per USDC.
	 * @return array|WP_Error
	 */
	private function prepare_one_time( $order, $rate ) {
		$units = P2Flux_WC_Money::to_units( $order->get_total(), $rate );
		if ( null === $units ) {
			return new WP_Error( 'p2flux_amount', __( 'This order total cannot be converted to USDC.', 'p2flux-for-woocommerce' ) );
		}

		$bounds = P2Flux_WC_Money::check_bounds( $units, false );
		if ( true !== $bounds ) {
			return new WP_Error(
				'p2flux_bounds',
				'too_small' === $bounds
					? __( 'This order is below the minimum P2Flux can settle (0.01 USDC).', 'p2flux-for-woocommerce' )
					: __( 'This order is above the maximum P2Flux can settle (10,000 USDC).', 'p2flux-for-woocommerce' )
			);
		}

		return P2Flux_WC_Payments::ensure_intent(
			$order,
			array(
				'units'       => $units,
				'recipient'   => strtolower( (string) $this->get_option( 'recipient' ) ),
				'environment' => P2Flux_WC_Client::current_environment(),
				'rate'        => $rate,
			)
		);
	}

	/**
	 * Create the setup a customer will authorize, and remember it on the subscription.
	 *
	 * @param WC_Order $order Parent order.
	 * @param string   $rate  Store currency per USDC.
	 * @return array|WP_Error
	 */
	private function prepare_subscription( $order, $rate ) {
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) && ! $order->get_meta( P2Flux_WC_Subscriptions::NATIVE_META ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
			if ( count( $subscriptions ) > 1 ) {
				return new WP_Error( 'p2flux_multiple', __( 'P2Flux can pay one subscription per order.', 'p2flux-for-woocommerce' ) );
			}
		}

		$subscription = P2Flux_WC_Subscriptions::for_order( $order, true );
		if ( ! $subscription ) {
			return new WP_Error( 'p2flux_multiple', __( 'P2Flux can pay one subscription per order.', 'p2flux-for-woocommerce' ) );
		}
		$units        = P2Flux_WC_Money::to_units( $subscription->get_total(), $rate );
		$period       = self::billing_period( $subscription );

		if ( null === $units || true !== P2Flux_WC_Money::check_bounds( $units, true ) ) {
			return new WP_Error( 'p2flux_amount', __( 'This subscription amount is outside what P2Flux can collect.', 'p2flux-for-woocommerce' ) );
		}
		if ( null === $period ) {
			return new WP_Error( 'p2flux_period', __( 'This billing schedule is outside what P2Flux supports.', 'p2flux-for-woocommerce' ) );
		}
		if ( P2Flux_WC_Money::to_units( $order->get_total(), $rate ) !== $units ) {
			// A different first payment means a different amount than the one being authorized, and
			// the authorization has room for exactly one.
			return new WP_Error( 'p2flux_initial', __( 'P2Flux cannot take a first payment that differs from the recurring amount.', 'p2flux-for-woocommerce' ) );
		}

		// Already authorized: nothing to set up, the first charge just has not been collected yet.
		if ( P2Flux_WC_Auth_History::active( $subscription ) ) {
			return array( 'setup_token' => '' );
		}

		$pending = P2Flux_WC_Auth_History::pending( $subscription );
		if ( $pending && (int) $pending['units'] === $units && (int) $pending['period'] === $period ) {
			return $pending;
		}

		$environment = P2Flux_WC_Client::current_environment();
		$recipient   = strtolower( (string) $this->get_option( 'recipient' ) );
		$client      = P2Flux_WC_Client::for_environment( $environment );

		try {
			$setup = $client->createSubscription(
				array(
					'recipient' => $recipient,
					'amount'    => P2Flux_WC_Money::format( $units ),
					'period'    => $period,
					'allowance' => P2Flux_WC_Money::allowance_term( (string) $this->get_option( 'allowance', 'unlimited' ) ),
				)
			);
		} catch ( \Exception $e ) {
			P2Flux_WC_Logger::error( 'could not create a subscription setup', array( 'order' => $order->get_id(), 'error' => $e->getMessage() ) );

			return new WP_Error( 'p2flux_unavailable', __( 'P2Flux could not be reached. Please try again in a moment.', 'p2flux-for-woocommerce' ) );
		}

		$record = array(
			'purpose'      => 'initial',
			'setup_token'  => (string) $setup['setup_token'],
			'salt'         => isset( $setup['salt'] ) ? (string) $setup['salt'] : '',
			'expires'      => isset( $setup['expires_at'] ) ? (int) $setup['expires_at'] : time() + DAY_IN_SECONDS,
			'units'        => $units,
			'period'       => $period,
			'recipient'    => $recipient,
			'environment'  => $environment,
			'order_id'     => $order->get_id(),
		);

		P2Flux_WC_Auth_History::set_pending( $subscription, $record );

		$subscription->update_meta_data( '_p2flux_env', $environment );
		$subscription->update_meta_data( '_p2flux_recipient', $recipient );
		$subscription->update_meta_data( '_p2flux_units', $units );
		$subscription->update_meta_data( '_p2flux_period', $period );
		$subscription->update_meta_data( '_p2flux_rate', $rate );
		$subscription->save();

		$order->update_meta_data( '_p2flux_env', $environment );
		$order->update_meta_data( '_p2flux_recipient', $recipient );
		$order->update_meta_data( '_p2flux_units', $units );
		$order->update_meta_data( '_p2flux_rate', $rate );
		$order->save();

		return $record;
	}

	/**
	 * The order-pay screen: one button, which opens the hosted checkout.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		P2Flux_WC_Checkout_Page::render( $order, $this );
	}

	/**
	 * WooCommerce Subscriptions says a renewal is due.
	 *
	 * Everything that makes this safe is inside the charger: the lock, the re-read, the period
	 * claim. This hook's only job is to find the subscription and hand over.
	 *
	 * @param float    $amount        What WCS expects to collect.
	 * @param WC_Order $renewal_order Renewal order.
	 * @return void
	 */
	public function scheduled_subscription_payment( $amount, $renewal_order ) {
		$subscription = P2Flux_WC_Subscriptions::for_order( $renewal_order );
		if ( ! $subscription || P2Flux_WC_Subscriptions::is_native( $subscription ) ) {
			return;
		}

		$outcome = P2Flux_WC_Charger::collect( P2Flux_WC_Subscriptions::ref( $subscription ), $renewal_order->get_id() );

		if ( 'busy' === $outcome['status'] ) {
			P2Flux_WC_Jobs::schedule( 'recharge', $renewal_order->get_id(), 60 );
		}
	}

	/**
	 * Woo's own refund button is not offered, and this says why if something calls it anyway.
	 *
	 * @param int    $order_id Order.
	 * @param float  $amount   Amount.
	 * @param string $reason   Reason.
	 * @return WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return new WP_Error(
			'p2flux_refund',
			__( 'A P2Flux refund is sent from your own wallet. Use the P2Flux box on this order.', 'p2flux-for-woocommerce' )
		);
	}

	/**
	 * The contract period for a subscription's billing schedule.
	 *
	 * The filter exists for one purpose: a developer proving multi-period behaviour on Base Sepolia
	 * without waiting a day between renewals. It is honoured only in test mode, and the development
	 * fixture that uses it never ships - the release check fails the build if it does.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return int|null
	 */
	public static function billing_period( $subscription ) {
		$period = P2Flux_WC_Money::period_seconds( $subscription->get_billing_period(), $subscription->get_billing_interval() );

		if ( null === $period || P2Flux_WC_Client::TEST !== P2Flux_WC_Client::current_environment() ) {
			return $period;
		}

		/**
		 * Filter the contract period. Test environment only.
		 *
		 * @param int             $period       Seconds.
		 * @param WC_Subscription $subscription Subscription.
		 */
		$filtered = (int) apply_filters( 'p2flux_wc_period_seconds', $period, $subscription );

		return ( $filtered >= 60 && $filtered <= P2Flux_WC_Money::MAX_PERIOD ) ? $filtered : $period;
	}

	/**
	 * Does this order carry a subscription?
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function is_subscription_order( $order ) {
		if ( $order->get_meta( P2Flux_WC_Subscriptions::NATIVE_META ) ) {
			return true;
		}

		return function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'parent' );
	}

	/**
	 * Is there a subscription in the cart?
	 *
	 * @return bool
	 */
	public static function cart_has_subscription() {
		return class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription();
	}

	/**
	 * A usable payout wallet: well-formed, and not the burn address.
	 *
	 * @param string $address Address.
	 * @return bool
	 */
	public static function valid_recipient( $address ) {
		$address = trim( (string) $address );

		if ( ! preg_match( '/^0x[0-9a-fA-F]{40}$/', $address ) ) {
			return false;
		}

		// Paying the zero address burns the money. It is a plausible typo and an unrecoverable one.
		return '0x0000000000000000000000000000000000000000' !== strtolower( $address );
	}
}
