<?php
/**
 * What a customer can do about their own subscription.
 *
 * Three things, and each exists because the alternative is worse. Restoring an allowance, because
 * an allowance that ran short is not a dead subscription and asking someone to re-subscribe over it
 * would be theatre. Retrying a charge, because a customer who has just topped up their wallet
 * should not wait a day for the next scheduled attempt. Revoking, because cancelling in WooCommerce
 * stops this store from collecting and does not touch the permission sitting in their wallet - only
 * they can remove that, and they should be told so rather than left assuming.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * My Account subscription controls.
 */
class P2Flux_WC_Account {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_subscription_details_after_subscription_table', array( __CLASS__, 'render' ) );

		foreach ( array( 'restore', 'retry', 'revoke_session', 'revoked' ) as $endpoint ) {
			add_action( 'wc_ajax_p2flux_' . $endpoint, array( __CLASS__, $endpoint ) );
		}
	}

	/**
	 * The controls, under the subscription table.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return void
	 */
	public static function render( $subscription ) {
		if ( ! self::owned_by_current_user( $subscription ) ) {
			return;
		}

		$authorization = P2Flux_WC_Auth_History::active( $subscription );
		if ( ! $authorization ) {
			return;
		}

		$collection = P2Flux_WC_Collection::get( $subscription );
		$status     = $subscription->get_status();

		wp_enqueue_script( 'p2flux-wc-account', plugins_url( 'assets/account.js', P2FLUX_WC_FILE ), array(), P2FLUX_WC_VERSION, true );
		wp_add_inline_script(
			'p2flux-wc-account',
			'window.p2fluxWcAccount = ' . wp_json_encode(
				array(
					'subscription' => $subscription->get_id(),
					'nonce'        => wp_create_nonce( 'p2flux_wc' ),
					'checkout'     => P2Flux_WC_Client::checkout_url( isset( $authorization['environment'] ) ? $authorization['environment'] : P2Flux_WC_Client::current_environment() ),
					'ajax'         => array(
						'restore' => WC_AJAX::get_endpoint( 'p2flux_restore' ),
						'retry'   => WC_AJAX::get_endpoint( 'p2flux_retry' ),
						'session' => WC_AJAX::get_endpoint( 'p2flux_revoke_session' ),
						'revoked' => WC_AJAX::get_endpoint( 'p2flux_revoked' ),
					),
					'i18n'         => array(
						'blocked'  => __( 'Your browser blocked the wallet window. Allow pop-ups for this site and try again.', 'p2flux-for-woocommerce' ),
						'waiting'  => __( 'Waiting for your wallet…', 'p2flux-for-woocommerce' ),
						'restored' => __( 'Approval restored. We will collect the outstanding payment shortly.', 'p2flux-for-woocommerce' ),
						'retrying' => __( 'Trying the payment again…', 'p2flux-for-woocommerce' ),
						'revoked'  => __( 'Authorization revoked. This store can no longer collect from your wallet.', 'p2flux-for-woocommerce' ),
						'failed'   => __( 'That did not work. Please try again.', 'p2flux-for-woocommerce' ),
					),
				)
			) . ';',
			'before'
		);

		echo '<h2>' . esc_html__( 'Wallet authorization', 'p2flux-for-woocommerce' ) . '</h2>';

		echo '<p id="p2flux-account-status" role="status" aria-live="polite"></p>';

		if ( P2Flux_WC_Collection::DUNNING === $collection['state'] ) {
			echo '<p>' . esc_html__( 'A payment could not be collected. If you have topped up your wallet or restored the approval, you can try it again now.', 'p2flux-for-woocommerce' ) . '</p>';
			echo '<p><button type="button" class="button" id="p2flux-restore">' . esc_html__( 'Restore USDC approval', 'p2flux-for-woocommerce' ) . '</button> ';
			echo '<button type="button" class="button" id="p2flux-retry">' . esc_html__( 'Try the payment again', 'p2flux-for-woocommerce' ) . '</button></p>';
		}

		if ( in_array( $status, array( 'cancelled', 'pending-cancel', 'expired' ), true ) ) {
			echo '<p>' . esc_html__( 'This subscription is cancelled and this store will not collect from it again. Your wallet still holds the standing permission you signed - revoking it removes that permission entirely.', 'p2flux-for-woocommerce' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Revoking ends this store’s permission to collect future payments from your wallet. It does not refund the period you have already paid for.', 'p2flux-for-woocommerce' ) . '</p>';
		}

		echo '<p><button type="button" class="button" id="p2flux-revoke">' . esc_html__( 'Revoke wallet authorization', 'p2flux-for-woocommerce' ) . '</button></p>';
	}

	/**
	 * Start an allowance repair: hand the browser the narrow session for it.
	 *
	 * @return void
	 */
	public static function restore() {
		$subscription = self::authorized_subscription();
		$authorization = P2Flux_WC_Auth_History::active( $subscription );
		$capability    = $authorization ? P2Flux_WC_Auth_History::capability( $subscription, $authorization['id'] ) : null;

		if ( null === $capability ) {
			wp_send_json_error( array( 'message' => __( 'This subscription has no authorization to restore.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		$client = P2Flux_WC_Client::for_environment( $authorization['environment'] );

		try {
			$session = $client->createAllowanceRestoreSession( $capability );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'P2Flux could not be reached. Please try again shortly.', 'p2flux-for-woocommerce' ) ), 502 );
		}
		unset( $capability );

		// The approve token can approve and nothing else: it cannot charge, revoke or refund. That
		// is the whole reason it exists rather than reusing a cancellation session.
		wp_send_json_success( array( 'token' => (string) $session['approve_token'] ) );
	}

	/**
	 * Collect an outstanding payment now, at the customer's request.
	 *
	 * @return void
	 */
	public static function retry() {
		$subscription = self::authorized_subscription();
		$collection   = P2Flux_WC_Collection::get( $subscription );
		$order_id     = (int) $collection['renewal_order_id'];

		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'There is no outstanding payment for this subscription.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		$outcome = P2Flux_WC_Charger::collect( $subscription->get_id(), $order_id );

		wp_send_json_success(
			array(
				'status'  => $outcome['status'],
				'message' => 'charged' === $outcome['status']
					? __( 'Thank you - the payment went through.', 'p2flux-for-woocommerce' )
					: $outcome['message'],
			)
		);
	}

	/**
	 * A cancel token for the hosted revoke screen.
	 *
	 * The capability itself never reaches the browser: it can charge. A cancel token can only build
	 * the customer's own revoke transaction, and their wallet still has to send it.
	 *
	 * @return void
	 */
	public static function revoke_session() {
		$subscription  = self::authorized_subscription();
		$authorization = P2Flux_WC_Auth_History::active( $subscription );
		$capability    = $authorization ? P2Flux_WC_Auth_History::capability( $subscription, $authorization['id'] ) : null;

		if ( null === $capability ) {
			wp_send_json_error( array( 'message' => __( 'This subscription has no authorization to revoke.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		$client = P2Flux_WC_Client::for_environment( $authorization['environment'] );

		try {
			$session = $client->createCancellationSession( $capability );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'P2Flux could not be reached. Please try again shortly.', 'p2flux-for-woocommerce' ) ), 502 );
		}
		unset( $capability );

		wp_send_json_success( array( 'token' => (string) $session['cancel_token'] ) );
	}

	/**
	 * The customer's wallet revoked the authorization.
	 *
	 * @return void
	 */
	public static function revoked() {
		$subscription  = self::authorized_subscription();
		$authorization = P2Flux_WC_Auth_History::active( $subscription );
		$hash          = isset( $_POST['tx_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['tx_hash'] ) ) : '';

		if ( $authorization ) {
			P2Flux_WC_Auth_History::mark( $subscription, $authorization['id'], P2Flux_WC_Auth_History::REVOKED, 'customer revoked' );
		}

		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::CANCELLED, array( 'reason' => 'revoked' ) );
		P2Flux_WC_Jobs::unschedule_subscription( $subscription );

		if ( '' !== $hash && preg_match( '/^0x[0-9a-fA-F]{64}$/', $hash ) ) {
			$subscription->update_meta_data( '_p2flux_revoked_tx', $hash );
		}

		if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
			$subscription->update_status( 'cancelled', __( 'The customer revoked the P2Flux authorization from their account page.', 'p2flux-for-woocommerce' ) );
		}
		$subscription->save();

		wp_send_json_success( array( 'status' => 'revoked' ) );
	}

	/**
	 * The subscription this request may act on, or die.
	 *
	 * @return WC_Subscription
	 */
	private static function authorized_subscription() {
		check_ajax_referer( 'p2flux_wc', 'nonce' );

		$id           = isset( $_POST['subscription'] ) ? absint( $_POST['subscription'] ) : 0;
		$subscription = $id && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : null;

		if ( ! $subscription || ! self::owned_by_current_user( $subscription ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'p2flux-for-woocommerce' ) ), 403 );
		}

		return $subscription;
	}

	/**
	 * Is this the logged-in customer's own subscription?
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return bool
	 */
	private static function owned_by_current_user( $subscription ) {
		return $subscription
			&& 'p2flux' === $subscription->get_payment_method()
			&& get_current_user_id()
			&& (int) $subscription->get_user_id() === get_current_user_id();
	}
}
