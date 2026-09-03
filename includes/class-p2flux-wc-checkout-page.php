<?php
/**
 * The order-pay screen: one button, and what the browser needs to open the popup.
 *
 * Everything customer-facing about WHAT is being bought stays here, on the shop's own page. P2Flux
 * is told an amount, a wallet and a period; it never learns the product, the plan or the customer.
 *
 * The popup opens from a real click. Browsers block a window opened from a timer or an async
 * callback, and a token that rides in the URL fragment must not travel through a redirect - so a
 * button is not a nicety here, it is the mechanism.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the pay screen.
 */
class P2Flux_WC_Checkout_Page {

	/**
	 * Print the screen for an order.
	 *
	 * @param WC_Order          $order   Order.
	 * @param P2Flux_WC_Gateway $gateway Gateway, for settings and re-minting.
	 * @return void
	 */
	public static function render( $order, $gateway ) {
		$subscription = self::subscription_for( $order );
		$config       = $subscription ? self::subscription_config( $order, $subscription, $gateway ) : self::payment_config( $order, $gateway );

		if ( is_wp_error( $config ) ) {
			echo '<p class="woocommerce-error">' . esc_html( $config->get_error_message() ) . '</p>';
			return;
		}

		wp_enqueue_style(
			'p2flux-wc-checkout',
			plugins_url( 'assets/checkout.css', P2FLUX_WC_FILE ),
			array(),
			P2FLUX_WC_VERSION
		);
		wp_enqueue_script(
			'p2flux-wc-checkout',
			plugins_url( 'assets/checkout.js', P2FLUX_WC_FILE ),
			array(),
			P2FLUX_WC_VERSION,
			true
		);
		wp_add_inline_script(
			'p2flux-wc-checkout',
			'window.p2fluxWc = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		$environment = (string) $order->get_meta( '_p2flux_env' );
		$units       = (int) $order->get_meta( '_p2flux_units' );
		$rate        = (string) $order->get_meta( '_p2flux_rate' );

		?>
		<div class="p2flux-pay">
			<div class="p2flux-pay__head">
				<img src="<?php echo esc_url( plugins_url( 'assets/p2flux-mark.svg', P2FLUX_WC_FILE ) ); ?>" alt="" width="26" height="26" />
				<span class="p2flux-pay__name">P2Flux</span>
				<?php if ( P2Flux_WC_Client::TEST === $environment ) : ?>
					<span class="p2flux-pay__badge"><?php esc_html_e( 'Test mode', 'p2flux-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</div>

			<p class="p2flux-pay__amount">
				<?php echo esc_html( P2Flux_WC_Money::display( $units ) ); ?><span>USDC</span>
			</p>

			<?php if ( '' !== $rate && '1' !== $rate ) : ?>
				<p class="p2flux-pay__meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: exchange rate, 2: store currency code. */
							__( 'Converted at 1 USDC = %1$s %2$s.', 'p2flux-for-woocommerce' ),
							$rate,
							get_woocommerce_currency()
						)
					);
					?>
				</p>
			<?php endif; ?>

			<p class="p2flux-pay__meta">
				<?php
				echo esc_html(
					'subscribe' === $config['mode']
						? __( 'One wallet signature sets this up. Your wallet is not asked again for renewals.', 'p2flux-for-woocommerce' )
						: __( 'Paid from your own wallet on Base. The money goes straight to the store.', 'p2flux-for-woocommerce' )
				);
				?>
			</p>

			<div class="p2flux-pay__actions">
				<?php /* Disabled until the script binds it: a button that swallows the first click is
					worse than one that is visibly not ready yet. */ ?>
				<button type="button" class="button alt p2flux-pay__primary" id="p2flux-pay" disabled aria-disabled="true">
					<?php
					echo esc_html(
						'subscribe' === $config['mode']
							? __( 'Authorize with your wallet', 'p2flux-for-woocommerce' )
							: __( 'Pay with your wallet', 'p2flux-for-woocommerce' )
					);
					?>
				</button>

				<?php /* Shown only once a payment may already exist. Never a second way to pay. */ ?>
				<button type="button" class="p2flux-pay__secondary" id="p2flux-check" hidden>
					<?php esc_html_e( 'I already paid — check my payment', 'p2flux-for-woocommerce' ); ?>
				</button>
			</div>

			<p class="p2flux-pay__status" id="p2flux-status" role="status" aria-live="polite"></p>

			<?php if ( P2Flux_WC_Client::TEST === $environment ) : ?>
				<p class="p2flux-pay__note">
					<?php esc_html_e( 'Test mode: this payment settles on Base Sepolia and moves no real money.', 'p2flux-for-woocommerce' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * What the page needs for a one-time payment.
	 *
	 * @param WC_Order          $order   Order.
	 * @param P2Flux_WC_Gateway $gateway Gateway.
	 * @return array|WP_Error
	 */
	private static function payment_config( $order, $gateway ) {
		$rate = $gateway->rate();
		if ( null === $rate ) {
			return new WP_Error( 'p2flux_rate', __( 'The USDC exchange rate is unavailable. Please try again shortly.', 'p2flux-for-woocommerce' ) );
		}

		$units = P2Flux_WC_Money::to_units( $order->get_total(), $rate );
		if ( null === $units ) {
			return new WP_Error( 'p2flux_amount', __( 'This order total cannot be converted to USDC.', 'p2flux-for-woocommerce' ) );
		}

		/*
		 * The customer may arrive here long after checkout - a saved link, a failed first attempt -
		 * so the intent is refreshed if it has expired. Reuse is the normal path: minting one per
		 * page load would leave several live payment instructions for one order.
		 */
		$intent = P2Flux_WC_Payments::ensure_intent(
			$order,
			array(
				'units'       => $units,
				'recipient'   => strtolower( (string) $gateway->get_option( 'recipient' ) ),
				'environment' => (string) $order->get_meta( '_p2flux_env' ) ?: P2Flux_WC_Client::current_environment(),
				'rate'        => $rate,
			)
		);

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		return self::base_config( $order ) + array(
			'mode'  => 'pay',
			'token' => $intent['intent'],
		);
	}

	/**
	 * What the page needs for a subscription authorization.
	 *
	 * @param WC_Order          $order        Parent order.
	 * @param WC_Subscription   $subscription Subscription.
	 * @param P2Flux_WC_Gateway $gateway      Gateway.
	 * @return array|WP_Error
	 */
	private static function subscription_config( $order, $subscription, $gateway ) {
		// Already authorized: the customer signed, and what is left is collecting. The page opens no
		// wallet at all - it asks the server to try the charge again.
		if ( P2Flux_WC_Auth_History::active( $subscription ) ) {
			return self::base_config( $order ) + array(
				'mode'  => 'collect',
				'token' => '',
			);
		}

		$pending = P2Flux_WC_Auth_History::pending( $subscription );
		if ( ! $pending ) {
			return new WP_Error( 'p2flux_setup', __( 'This authorization link has expired. Please start the subscription again from the checkout.', 'p2flux-for-woocommerce' ) );
		}

		return self::base_config( $order ) + array(
			'mode'  => 'subscribe',
			'token' => $pending['setup_token'],
		);
	}

	/**
	 * The parts every mode needs.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private static function base_config( $order ) {
		$environment = (string) $order->get_meta( '_p2flux_env' ) ?: P2Flux_WC_Client::current_environment();

		return array(
			'checkout' => P2Flux_WC_Client::checkout_url( $environment ),
			'orderId'  => $order->get_id(),
			// The order key is what lets a guest act on their own order without logging in, and what
			// stops anyone else acting on it by guessing an id.
			'orderKey' => $order->get_order_key(),
			'nonce'    => wp_create_nonce( 'p2flux_wc' ),
			'ajax'     => array(
				'verify'   => WC_AJAX::get_endpoint( 'p2flux_verify' ),
				'check'    => WC_AJAX::get_endpoint( 'p2flux_check' ),
				'activate' => WC_AJAX::get_endpoint( 'p2flux_activate' ),
			),
			'redirect' => $order->get_checkout_order_received_url(),
			'i18n'     => array(
				'opening'    => __( 'Your wallet is opening in a new window. Confirm the payment there.', 'p2flux-for-woocommerce' ),
				'blocked'    => __( 'Your browser blocked the wallet window. Allow pop-ups for this site, then try again.', 'p2flux-for-woocommerce' ),
				'verifying'  => __( 'Confirming your payment on the blockchain. This takes a few seconds.', 'p2flux-for-woocommerce' ),
				/* Deliberately reads as good news: the money HAS moved, and a customer told
				 * "confirming" in a warning tone starts looking for a way to pay again. */
				'confirming' => __( 'Your payment has been sent and is being confirmed. This page updates itself — no need to pay again.', 'p2flux-for-woocommerce' ),
				'collecting' => __( 'Setting up your subscription and taking the first payment…', 'p2flux-for-woocommerce' ),
				'closed'     => __( 'The wallet window closed. If you confirmed the payment, use the link below — do not pay a second time.', 'p2flux-for-woocommerce' ),
				'checking'   => __( 'Looking for your payment on the blockchain…', 'p2flux-for-woocommerce' ),
				'notFound'   => __( 'No payment has arrived yet. If you have just confirmed one, wait a moment and check again.', 'p2flux-for-woocommerce' ),
				'failed'     => __( 'That payment could not be completed. Nothing was taken from your wallet.', 'p2flux-for-woocommerce' ),
				'retry'      => __( 'We could not reach the payment service just now. Your payment, if you made one, is safe — check again in a moment.', 'p2flux-for-woocommerce' ),
			),
		);
	}

	/**
	 * The subscription this order is the parent of, if any.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Subscription|null
	 */
	private static function subscription_for( $order ) {
		return P2Flux_WC_Subscriptions::for_order( $order, true );
	}
}
