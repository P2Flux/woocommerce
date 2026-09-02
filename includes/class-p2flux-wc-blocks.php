<?php
/**
 * The block checkout's view of this gateway.
 *
 * Two things here are load-bearing rather than boilerplate. `get_supported_features()` must report
 * the gateway's real support list: WooCommerce Blocks hides any method that does not declare
 * `subscriptions` the moment a subscription is in the cart, and the base class defaults to
 * `products` only. And `canMakePayment` on the JS side reads what the PHP `is_available()` decided,
 * so the block checkout refuses exactly what the classic one refuses - a currency this gateway
 * cannot convert, a cart shape it cannot honour - instead of offering a payment that will fail.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Block checkout integration.
 */
final class P2Flux_WC_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method id, matching the gateway.
	 *
	 * @var string
	 */
	protected $name = 'p2flux';

	/**
	 * Read the gateway's settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_p2flux_settings', array() );
	}

	/**
	 * Is the gateway usable at all?
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->gateway();

		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Register the tiny script that puts the method in the list.
	 *
	 * @return array<int,string>
	 */
	public function get_payment_method_script_handles() {
		wp_enqueue_style(
			'p2flux-wc-checkout',
			plugins_url( 'assets/checkout.css', P2FLUX_WC_FILE ),
			array(),
			P2FLUX_WC_VERSION
		);
		wp_register_script(
			'p2flux-wc-blocks',
			plugins_url( 'assets/blocks.js', P2FLUX_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			P2FLUX_WC_VERSION,
			true
		);

		return array( 'p2flux-wc-blocks' );
	}

	/**
	 * What the script needs to know.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();

		return array(
			'title'       => $gateway ? $gateway->get_option( 'title' ) : __( 'Pay with USDC', 'p2flux-for-woocommerce' ),
			'description' => $gateway ? $gateway->get_option( 'description' ) : '',
			// The block checkout ignores the classic gateway icon, so the mark is handed to the script
			// and drawn in the label there.
			'icon'        => plugins_url( 'assets/p2flux-mark.svg', P2FLUX_WC_FILE ),
			'supports'    => $this->get_supported_features(),
			'available'   => $this->is_active(),
			// Whether a cart carrying a subscription may use this method. The block checkout asks
			// per cart, and the reasons a subscription is refused (a trial, a sign-up fee, a
			// currency that cannot be fixed for a year) are cart-shaped, not gateway-shaped.
			'recurring'   => $gateway ? ( true === $gateway->subscription_cart_supported() ) : false,
			'testMode'    => P2Flux_WC_Client::TEST === P2Flux_WC_Client::current_environment(),
		);
	}

	/**
	 * The gateway's real feature list.
	 *
	 * @return array<int,string>
	 */
	public function get_supported_features() {
		$gateway = $this->gateway();

		return $gateway ? $gateway->supports : array( 'products' );
	}

	/**
	 * The gateway instance, if WooCommerce has one.
	 *
	 * @return P2Flux_WC_Gateway|null
	 */
	private function gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		return isset( $gateways['p2flux'] ) ? $gateways['p2flux'] : null;
	}
}
