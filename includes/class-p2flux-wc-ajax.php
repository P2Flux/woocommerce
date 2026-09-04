<?php
/**
 * The endpoints the pay screen and the admin box call.
 *
 * Every one of them treats its input as a claim. A browser saying "I paid, here is a hash" is not
 * evidence; the server asks P2Flux, which reads the chain. A browser saying "here is the
 * subscription capability" is checked against the setup this order actually created before it is
 * stored. That is the whole trust model, and it lives here.
 *
 * Authorization is the order key, not a login: guests buy things, and an order key is unguessable
 * and specific to one order. Admin endpoints require the capability that manages orders.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers.
 */
class P2Flux_WC_Ajax {

	/**
	 * Register them.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( array( 'verify', 'check', 'activate' ) as $endpoint ) {
			add_action( 'wc_ajax_p2flux_' . $endpoint, array( __CLASS__, $endpoint ) );
			add_action( 'wc_ajax_nopriv_p2flux_' . $endpoint, array( __CLASS__, $endpoint ) );
		}

		add_action( 'wp_ajax_p2flux_refund_prepare', array( __CLASS__, 'refund_prepare' ) );
		add_action( 'wp_ajax_p2flux_refund_verify', array( __CLASS__, 'refund_verify' ) );
		add_action( 'wp_ajax_p2flux_recover_charge', array( __CLASS__, 'recover_charge' ) );
	}

	/**
	 * The browser reports a transaction. The server decides whether it paid this order.
	 *
	 * @return void
	 */
	public static function verify() {
		$order = self::authorized_order();
		if ( ! $order ) {
			wp_send_json_error( array( 'code' => 'FORBIDDEN' ), 403 );
		}

		$hash = isset( $_POST['tx_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['tx_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce was checked in authorized_order() before this runs.
		if ( ! preg_match( '/^0x[0-9a-fA-F]{64}$/', $hash ) ) {
			wp_send_json_error( array( 'code' => 'INVALID_TX' ), 400 );
		}

		// The receipt is a courier from the checkout: it lets the API answer without re-reading the
		// chain. A bad one costs nothing - verification falls back to the full check.
		$receipt = isset( $_POST['settlement_receipt'] ) ? sanitize_text_field( wp_unslash( $_POST['settlement_receipt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce was checked in authorized_order() before this runs.
		$intent  = P2Flux_WC_Intents::active( $order );
		if ( ! $intent ) {
			wp_send_json_error( array( 'code' => 'NO_INTENT' ), 400 );
		}

		$result = P2Flux_WC_Payments::verify( $order, $intent['intent'], $hash, $receipt );

		wp_send_json_success( $result );
	}

	/**
	 * "I closed the window and I am not sure whether I paid."
	 *
	 * Never offers to pay again. Once a payment may exist, the only safe thing a page can do is ask
	 * whether it does - which is what this does, against every intent the order has that could still
	 * settle.
	 *
	 * @return void
	 */
	public static function check() {
		$order = self::authorized_order();
		if ( ! $order ) {
			wp_send_json_error( array( 'code' => 'FORBIDDEN' ), 403 );
		}

		if ( $order->is_paid() ) {
			wp_send_json_success(
				array(
					'status'   => 'paid',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		$client = P2Flux_WC_Client::for_object( $order );

		// One recovery pass per order every few seconds, over the newest few intents: the order
		// key authorizes this call, and it should not be a lever on the merchant's API quota.
		$cooldown = 'p2flux_wc_check_' . $order->get_id();
		if ( get_transient( $cooldown ) ) {
			wp_send_json_success( array( 'status' => 'not_found' ) );
		}
		set_transient( $cooldown, 1, 10 );

		foreach ( array_slice( array_reverse( P2Flux_WC_Intents::recoverable( $order ) ), 0, 5 ) as $intent ) {
			try {
				$found = $client->recoverPayment( $intent['intent'] );
			} catch ( \Exception $e ) {
				continue;
			}

			if ( ! empty( $found['found'] ) && ! empty( $found['valid'] ) ) {
				P2Flux_WC_Payments::settle( $order, $intent['intent'], $found );

				$fresh = wc_get_order( $order->get_id() );
				if ( $fresh && $fresh->is_paid() ) {
					wp_send_json_success(
						array(
							'status'   => 'paid',
							'redirect' => $fresh->get_checkout_order_received_url(),
						)
					);
				}
			}

			// Found but not settled deep enough yet: the customer HAS paid. Never a "not found".
			if ( ! empty( $found['found'] ) ) {
				wp_send_json_success( array( 'status' => 'confirming' ) );
			}
		}

		wp_send_json_success( array( 'status' => 'not_found' ) );
	}

	/**
	 * Store a new subscription authorization and collect its first payment.
	 *
	 * Idempotent on purpose: the page calls it with the capability once and then polls it with
	 * nothing, and both land here. Whatever has already happened, calling again either finishes the
	 * job or reports where it got to.
	 *
	 * @return void
	 */
	public static function activate() {
		$order = self::authorized_order();
		if ( ! $order ) {
			wp_send_json_error( array( 'code' => 'FORBIDDEN' ), 403 );
		}

		$subscription = P2Flux_WC_Subscriptions::for_order( $order, true );
		if ( ! $subscription ) {
			wp_send_json_error( array( 'code' => 'NOT_A_SUBSCRIPTION' ), 400 );
		}

		$capability = isset( $_POST['subscription'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce was checked in authorized_order() before this runs.

		if ( '' !== $capability && ! P2Flux_WC_Auth_History::active( $subscription ) ) {
			$stored = P2Flux_WC_Activation::store( $subscription, $order, $capability );
			unset( $capability );

			if ( is_wp_error( $stored ) ) {
				// The checkout window is still open and waiting. It composes the customer-facing
				// sentence itself from this bare code - a merchant page names a failure, it never
				// writes on that screen.
				wp_send_json_success(
					array(
						'status' => 'failed',
						'code'   => $stored->get_error_code(),
					)
				);
			}
		}

		$outcome = P2Flux_WC_Charger::collect( P2Flux_WC_Subscriptions::ref( $subscription ), $order->get_id() );

		wp_send_json_success( P2Flux_WC_Activation::to_page_result( $outcome, $order ) );
	}

	/**
	 * Admin: reserve this order's one refund and get the checkout link for it.
	 *
	 * @return void
	 */
	public static function refund_prepare() {
		$order = self::admin_order();
		$units = isset( $_POST['units'] ) ? (int) $_POST['units'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce was checked in admin_order() before this runs.

		$prepared = P2Flux_WC_Refunds::prepare( $order, $units );
		if ( is_wp_error( $prepared ) ) {
			wp_send_json_error( array( 'message' => $prepared->get_error_message() ), 400 );
		}

		wp_send_json_success( $prepared );
	}

	/**
	 * Admin: the merchant's wallet sent a refund; confirm it on chain and record it.
	 *
	 * @return void
	 */
	public static function refund_verify() {
		$order = self::admin_order();
		$hash  = isset( $_POST['refund_tx_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['refund_tx_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce was checked in admin_order() before this runs.

		if ( '' !== $hash && ! preg_match( '/^0x[0-9a-fA-F]{64}$/', $hash ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not a transaction hash.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		$result = P2Flux_WC_Refunds::verify( $order, $hash );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Admin: find the settlement behind a period the contract says was collected.
	 *
	 * @return void
	 */
	public static function recover_charge() {
		$order = self::admin_order();

		P2Flux_WC_Jobs::reconcile( $order->get_id() );

		$fresh = wc_get_order( $order->get_id() );
		$hash  = $fresh ? (string) $fresh->get_meta( '_p2flux_tx_hash' ) : '';

		wp_send_json_success(
			array(
				'tx_hash' => $hash,
				'paid'    => $fresh ? $fresh->is_paid() : false,
			)
		);
	}

	/**
	 * The order this request is allowed to act on, or null.
	 *
	 * @return WC_Order|null
	 */
	private static function authorized_order() {
		check_ajax_referer( 'p2flux_wc', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$key      = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * The order an admin request is allowed to act on, or die trying.
	 *
	 * @return WC_Order
	 */
	private static function admin_order() {
		check_ajax_referer( 'p2flux_wc_admin', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'p2flux-for-woocommerce' ) ), 403 );
		}

		return $order;
	}
}
