<?php
/**
 * "Your subscription has been cancelled."
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer email: an explicit cancellation.
 */
class P2Flux_WC_Email_Subscription_Cancelled extends WC_Email {

	/** @var P2Flux_WC_Native_Subscription|null */
	public $subscription;

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'p2flux_subscription_cancelled';
		$this->customer_email = true;
		$this->title          = __( 'P2Flux subscription cancelled', 'p2flux-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when a P2Flux subscription is cancelled. Never sent for a signup that never activated.', 'p2flux-for-woocommerce' );
		$this->template_base  = dirname( P2FLUX_WC_FILE ) . '/templates/';
		$this->template_html  = 'emails/p2flux-subscription-cancelled.php';
		$this->template_plain = 'emails/plain/p2flux-subscription-cancelled.php';
		$this->placeholders   = array( '{product}' => '' );

		add_action( 'p2flux_wc_native_subscription_cancelled', array( $this, 'trigger' ) );

		parent::__construct();
	}

	/** @return string */
	public function get_default_subject() {
		return __( 'Your subscription has been cancelled', 'p2flux-for-woocommerce' );
	}

	/** @return string */
	public function get_default_heading() {
		return __( 'Subscription cancelled', 'p2flux-for-woocommerce' );
	}

	/**
	 * Send.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	public function trigger( $subscription ) {
		$this->setup_locale();

		$parent             = wc_get_order( $subscription->get_parent_id() );
		$this->object       = $parent;
		$this->subscription = $subscription;
		$this->recipient    = $parent ? $parent->get_billing_email() : '';

		$this->placeholders['{product}'] = (string) $subscription->get( 'product_name' );

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
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
			'auth_remains'       => (bool) P2Flux_WC_Auth_History::active( $this->subscription ),
			'account_url'        => P2Flux_WC_Native_Emails::account_url(),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => (bool) $plain,
			'email'              => $this,
		);
	}
}
