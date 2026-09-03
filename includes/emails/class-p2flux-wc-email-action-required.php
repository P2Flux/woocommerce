<?php
/**
 * "Action needed for your subscription payment."
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer email: a renewal needs them.
 */
class P2Flux_WC_Email_Action_Required extends WC_Email {

	/** @var P2Flux_WC_Native_Subscription|null */
	public $subscription;

	/** @var string balance | allowance | reauth | missed */
	public $reason = '';

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'p2flux_action_required';
		$this->customer_email = true;
		$this->title          = __( 'P2Flux subscription: action needed', 'p2flux-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when a P2Flux subscription payment could not be collected and they have to act: add USDC, restore an approval, or authorize again.', 'p2flux-for-woocommerce' );
		$this->template_base  = dirname( P2FLUX_WC_FILE ) . '/templates/';
		$this->template_html  = 'emails/p2flux-action-required.php';
		$this->template_plain = 'emails/plain/p2flux-action-required.php';
		$this->placeholders   = array( '{order_number}' => '', '{product}' => '' );

		add_action( 'p2flux_wc_native_action_required', array( $this, 'trigger' ), 10, 3 );

		parent::__construct();
	}

	/** @return string */
	public function get_default_subject() {
		return __( 'Action needed for your subscription payment', 'p2flux-for-woocommerce' );
	}

	/** @return string */
	public function get_default_heading() {
		return __( 'Your subscription payment needs your attention', 'p2flux-for-woocommerce' );
	}

	/**
	 * Send.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param WC_Order                      $order        Order.
	 * @param string                        $reason       Reason.
	 * @return void
	 */
	public function trigger( $subscription, $order, $reason ) {
		$this->setup_locale();

		$this->object       = $order;
		$this->subscription = $subscription;
		$this->reason       = (string) $reason;
		$this->recipient    = $order->get_billing_email();

		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{product}']      = (string) $subscription->get( 'product_name' );

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * The sentence for the reason.
	 *
	 * @return string
	 */
	public function reason_text() {
		switch ( $this->reason ) {
			case 'balance':
				return __( 'Your subscription payment could not be collected because your wallet did not hold enough USDC. Add USDC to your wallet, then retry the payment from your account.', 'p2flux-for-woocommerce' );
			case 'allowance':
				return __( 'Your wallet’s USDC approval no longer covers your subscription payment. Use “Restore USDC approval” in your account; the payment is collected right after.', 'p2flux-for-woocommerce' );
			case 'reauth':
				return __( 'A new wallet authorization is required before your subscription can continue. You can authorize it again from your account.', 'p2flux-for-woocommerce' );
			case 'missed':
				return __( 'This renewal was not collected and its billing period has passed. It will not be collected later. Your subscription is on hold until a future payment succeeds; you can also pay this renewal from the order page, or cancel from your account.', 'p2flux-for-woocommerce' );
		}

		return __( 'Your subscription payment needs your attention.', 'p2flux-for-woocommerce' );
	}

	/** @return string */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/** @return string */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/** @return array */
	private function template_args( $plain = false ) {
		return array(
			'order'              => $this->object,
			'subscription'       => $this->subscription,
			'reason_text'        => $this->reason_text(),
			'account_url'        => P2Flux_WC_Native_Emails::account_url(),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => (bool) $plain,
			'email'              => $this,
		);
	}
}
