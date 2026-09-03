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

		foreach ( array( 'restore', 'retry', 'revoke_session', 'revoked', 'reauth', 'reauthorized' ) as $endpoint ) {
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
					'subscription' => P2Flux_WC_Subscriptions::ref( $subscription ),
					'nonce'        => wp_create_nonce( 'p2flux_wc_account' ),
					'checkout'     => P2Flux_WC_Client::checkout_url( isset( $authorization['environment'] ) ? $authorization['environment'] : P2Flux_WC_Client::current_environment() ),
					'ajax'         => array(
						'restore' => WC_AJAX::get_endpoint( 'p2flux_restore' ),
						'reauth'  => WC_AJAX::get_endpoint( 'p2flux_reauth' ),
						'reauthorized' => WC_AJAX::get_endpoint( 'p2flux_reauthorized' ),
						'retry'   => WC_AJAX::get_endpoint( 'p2flux_retry' ),
						'session' => WC_AJAX::get_endpoint( 'p2flux_revoke_session' ),
						'revoked' => WC_AJAX::get_endpoint( 'p2flux_revoked' ),
					),
					'i18n'         => array(
						'blocked'  => __( 'Your browser blocked the wallet window. Allow pop-ups for this site and try again.', 'p2flux-for-woocommerce' ),
						'waiting'  => __( 'Waiting for your wallet…', 'p2flux-for-woocommerce' ),
						'restored' => __( 'Approval restored. We will collect the outstanding payment shortly.', 'p2flux-for-woocommerce' ),
						'reauthorized' => __( 'Thank you - the new terms are authorized.', 'p2flux-for-woocommerce' ),
						'retrying' => __( 'Trying the payment again…', 'p2flux-for-woocommerce' ),
						'revoked'  => __( 'Authorization revoked. This store can no longer collect from your wallet.', 'p2flux-for-woocommerce' ),
						'failed'   => __( 'That did not work. Please try again.', 'p2flux-for-woocommerce' ),
					),
				)
			) . ';',
			'before'
		);

		self::enqueue_style();

		$ended = in_array( $status, array( 'cancelled', 'pending-cancel', 'expired' ), true );
		if ( P2Flux_WC_Collection::REAUTH_REQUIRED === $collection['state'] ) {
			$badge = array( 'on-hold', __( 'Re-authorization needed', 'p2flux-for-woocommerce' ) );
		} elseif ( P2Flux_WC_Collection::DUNNING === $collection['state'] ) {
			$badge = array( 'on-hold', __( 'Payment needs attention', 'p2flux-for-woocommerce' ) );
		} elseif ( $ended ) {
			$badge = array( 'cancelled', __( 'Not collecting', 'p2flux-for-woocommerce' ) );
		} else {
			$badge = array( 'active', __( 'Authorized', 'p2flux-for-woocommerce' ) );
		}

		echo '<section class="p2flux-account"><div class="p2flux-card">';
		echo '<div class="p2flux-card__head"><h2>' . esc_html__( 'Wallet authorization', 'p2flux-for-woocommerce' ) . '</h2>' . self::badge( $badge[0], $badge[1] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the helper escapes.

		if ( P2Flux_WC_Collection::DUNNING === $collection['state'] ) {
			echo '<p class="p2flux-note">' . esc_html__( 'A payment could not be collected. If you have topped up your wallet or restored the approval, you can try it again now.', 'p2flux-for-woocommerce' ) . '</p>';
			echo '<div class="p2flux-actions"><button type="button" class="button p2flux-btn" id="p2flux-restore">' . esc_html__( 'Restore USDC approval', 'p2flux-for-woocommerce' ) . '</button>';
			echo '<button type="button" class="button p2flux-btn p2flux-btn--quiet" id="p2flux-retry">' . esc_html__( 'Try the payment again', 'p2flux-for-woocommerce' ) . '</button></div>';
		}

		if ( P2Flux_WC_Collection::REAUTH_REQUIRED === $collection['state'] ) {
			$units = P2Flux_WC_Money::to_units( $subscription->get_total(), '' !== (string) $subscription->get_meta( '_p2flux_rate' ) ? (string) $subscription->get_meta( '_p2flux_rate' ) : '1' );
			echo '<p class="p2flux-note">' . esc_html(
				sprintf(
					/* translators: %s: amount in USDC. */
					__( 'The terms of this subscription have changed, and your wallet has only authorized the old ones. To continue, authorize the new amount of %s USDC per period. Nothing is collected until you do.', 'p2flux-for-woocommerce' ),
					null !== $units ? P2Flux_WC_Money::display( $units ) : $subscription->get_total()
				)
			) . '</p>';
			echo '<div class="p2flux-actions"><button type="button" class="button p2flux-btn" id="p2flux-reauth">' . esc_html__( 'Re-authorize', 'p2flux-for-woocommerce' ) . '</button></div>';
		}

		if ( $ended ) {
			echo '<p class="p2flux-note">' . esc_html__( 'This subscription is cancelled and this store will not collect from it again. Your wallet still holds the standing permission you signed - revoking it removes that permission entirely.', 'p2flux-for-woocommerce' ) . '</p>';
		} else {
			echo '<p class="p2flux-note">' . esc_html__( 'Revoking ends this store’s permission to collect future payments from your wallet. It does not refund the period you have already paid for.', 'p2flux-for-woocommerce' ) . '</p>';
		}

		echo '<div class="p2flux-actions"><button type="button" class="button p2flux-btn p2flux-btn--danger" id="p2flux-revoke">' . esc_html__( 'Revoke wallet authorization', 'p2flux-for-woocommerce' ) . '</button></div>';
		echo '<p class="p2flux-status" id="p2flux-account-status" role="status" aria-live="polite"></p>';
		echo '</div></section>';
	}

	/**
	 * The account stylesheet, once per page.
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		wp_enqueue_style( 'p2flux-wc-account', plugins_url( 'assets/account.css', P2FLUX_WC_FILE ), array(), P2FLUX_WC_VERSION );
	}

	/**
	 * A status badge.
	 *
	 * @param string $kind  active | on-hold | pending | cancelled | expired | revoked | test.
	 * @param string $label Text.
	 * @return string HTML.
	 */
	public static function badge( $kind, $label ) {
		return '<span class="p2flux-badge p2flux-badge--' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Start an allowance repair: hand the browser the narrow session for it.
	 *
	 * @return void
	 */
	public static function restore() {
		$subscription = self::authorized_subscription( true );
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
	 * A setup for the subscription's CURRENT terms, replacing the authorization the customer holds.
	 *
	 * The payout wallet and the environment are the subscription's own, stored when it was created -
	 * never the store's current settings. The customer is authorizing the same arrangement at a new
	 * price, not a different one.
	 *
	 * @return void
	 */
	public static function reauth() {
		$subscription = self::authorized_subscription( true );
		$collection   = P2Flux_WC_Collection::get( $subscription );
		$active       = P2Flux_WC_Auth_History::active( $subscription );

		if ( ! $active || P2Flux_WC_Collection::REAUTH_REQUIRED !== $collection['state'] ) {
			wp_send_json_error( array( 'message' => __( 'This subscription does not need a new authorization.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		$rate   = (string) $subscription->get_meta( '_p2flux_rate' );
		$units  = P2Flux_WC_Money::to_units( $subscription->get_total(), '' !== $rate ? $rate : '1' );
		$period = P2Flux_WC_Gateway::billing_period( $subscription );

		if ( null === $units || null === $period || true !== P2Flux_WC_Money::check_bounds( $units, true ) ) {
			wp_send_json_error( array( 'message' => __( 'These subscription terms cannot be authorized through P2Flux. Please contact the store.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		$environment = (string) $subscription->get_meta( '_p2flux_env' );
		$recipient   = strtolower( (string) $subscription->get_meta( '_p2flux_recipient' ) );
		if ( '' === $environment || '' === $recipient ) {
			$environment = $active['environment'];
			$recipient   = strtolower( (string) $active['recipient'] );
		}

		// A setup already waiting for exactly these terms is reused: a second click is not a second setup.
		$pending = P2Flux_WC_Auth_History::pending( $subscription );
		if ( $pending && 'reauth' === $pending['purpose'] && (int) $pending['units'] === $units && (int) $pending['period'] === $period
			&& strtolower( (string) $pending['replaces_auth_id'] ) === strtolower( (string) $active['id'] ) && (int) $pending['expires'] > time() + MINUTE_IN_SECONDS ) {
			wp_send_json_success( array( 'token' => (string) $pending['setup_token'] ) );
		}

		try {
			$settings = get_option( 'woocommerce_p2flux_settings', array() );
			$setup    = P2Flux_WC_Client::for_environment( $environment )->createSubscription(
				array(
					'recipient' => $recipient,
					'amount'    => P2Flux_WC_Money::format( $units ),
					'period'    => $period,
					// The same allowance shape the customer signed up with: a re-authorization changes the
					// amount, not the terms around it.
					'allowance' => P2Flux_WC_Money::allowance_term( isset( $active['allowance'] ) ? (string) $active['allowance'] : ( isset( $settings['allowance'] ) ? (string) $settings['allowance'] : 'unlimited' ) ),
				)
			);
		} catch ( \Exception $e ) {
			P2Flux_WC_Logger::error( 'could not create a re-authorization setup', array( 'subscription' => $subscription->get_id(), 'error' => $e->getMessage() ) );
			wp_send_json_error( array( 'message' => __( 'P2Flux could not be reached. Please try again shortly.', 'p2flux-for-woocommerce' ) ), 502 );
		}

		P2Flux_WC_Auth_History::set_pending(
			$subscription,
			array(
				'purpose'          => 'reauth',
				'setup_token'      => (string) $setup['setup_token'],
				'salt'             => isset( $setup['salt'] ) ? (string) $setup['salt'] : '',
				'expires'          => isset( $setup['expires_at'] ) ? (int) $setup['expires_at'] : time() + DAY_IN_SECONDS,
				'units'            => $units,
				'period'           => $period,
				'recipient'        => $recipient,
				'environment'      => $environment,
				'order_id'         => (int) $collection['renewal_order_id'],
				'replaces_auth_id' => (string) $active['id'],
				'allowance'        => isset( $active['allowance'] ) ? (string) $active['allowance'] : 'unlimited',
			)
		);

		wp_send_json_success( array( 'token' => (string) $setup['setup_token'] ) );
	}

	/**
	 * The wallet signed the new terms: switch to the new authorization and collect what is owed.
	 *
	 * The capability in the request is a claim. Activation reads the subscription's terms from
	 * P2Flux and refuses anything that is not the setup this subscription created.
	 *
	 * @return void
	 */
	public static function reauthorized() {
		$subscription = self::authorized_subscription( true );
		$capability   = isset( $_POST['subscription_capability'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_capability'] ) ) : '';
		$collection   = P2Flux_WC_Collection::get( $subscription );
		$order_id     = (int) $collection['renewal_order_id'];
		$order        = $order_id ? wc_get_order( $order_id ) : null;

		if ( '' === $capability ) {
			wp_send_json_error( array( 'message' => __( 'No authorization was returned.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		$stored = P2Flux_WC_Activation::store( $subscription, $order ? $order : $subscription, $capability );
		unset( $capability );

		if ( is_wp_error( $stored ) ) {
			wp_send_json_success( array( 'status' => 'failed', 'code' => $stored->get_error_code() ) );
		}

		if ( ! $order ) {
			wp_send_json_success( array( 'status' => 'finalized', 'message' => __( 'Thank you - the new terms are authorized.', 'p2flux-for-woocommerce' ) ) );
		}

		$outcome = P2Flux_WC_Charger::collect( P2Flux_WC_Subscriptions::ref( $subscription ), $order_id );

		wp_send_json_success(
			array(
				'status'  => 'charged' === $outcome['status'] ? 'finalized' : $outcome['status'],
				'code'    => $outcome['code'],
				'tx_hash' => $outcome['tx_hash'],
				'message' => 'charged' === $outcome['status']
					? __( 'Thank you - the new terms are authorized and the outstanding payment went through.', 'p2flux-for-woocommerce' )
					: $outcome['message'],
			)
		);
	}

	/**
	 * Collect an outstanding payment now, at the customer's request.
	 *
	 * @return void
	 */
	public static function retry() {
		$subscription = self::authorized_subscription( true );
		$collection   = P2Flux_WC_Collection::get( $subscription );
		$order_id     = (int) $collection['renewal_order_id'];

		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'There is no outstanding payment for this subscription.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		$outcome = P2Flux_WC_Charger::collect( P2Flux_WC_Subscriptions::ref( $subscription ), $order_id );

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

		/*
		 * The browser says the wallet revoked. That is a claim; the chain is the fact. Ask P2Flux
		 * whether the authorization is revoked before writing an irreversible "revoked" on the
		 * record. If it is not (yet), the customer still gets what they asked for - collection
		 * stops - recorded as a cancellation, and the record can be marked revoked later.
		 */
		$on_chain = self::revoked_on_chain( $subscription, $authorization );
		if ( $authorization && $on_chain ) {
			P2Flux_WC_Auth_History::mark( $subscription, $authorization['id'], P2Flux_WC_Auth_History::REVOKED, 'customer revoked' );
		}

		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::CANCELLED, array( 'reason' => $on_chain ? 'revoked' : 'cancelled' ) );
		P2Flux_WC_Jobs::unschedule_subscription( $subscription );

		if ( $on_chain && '' !== $hash && preg_match( '/^0x[0-9a-fA-F]{64}$/', $hash ) ) {
			$subscription->update_meta_data( '_p2flux_revoked_tx', $hash );
		}

		if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
			$subscription->update_status( 'cancelled', __( 'The customer revoked the P2Flux authorization from their account page.', 'p2flux-for-woocommerce' ) );
		}
		$subscription->save();

		wp_send_json_success( array( 'status' => $on_chain ? 'revoked' : 'cancelled' ) );
	}

	/**
	 * Is this authorization revoked on chain, according to P2Flux?
	 *
	 * @param object     $subscription  Subscription.
	 * @param array|null $authorization Active authorization.
	 * @return bool
	 */
	private static function revoked_on_chain( $subscription, $authorization ) {
		if ( ! $authorization ) {
			return false;
		}
		$capability = P2Flux_WC_Auth_History::capability( $subscription, $authorization['id'] );
		if ( null === $capability ) {
			return false;
		}
		try {
			$status = P2Flux_WC_Client::for_environment( $authorization['environment'] )->status( $capability );
		} catch ( \Exception $e ) {
			return false;
		} finally {
			unset( $capability );
		}

		return ! empty( $status['revoked'] );
	}

	/**
	 * The subscription this request may act on, or die.
	 *
	 * @return WC_Subscription
	 */
	private static function authorized_subscription( $collecting = false ) {
		check_ajax_referer( 'p2flux_wc_account', 'nonce' );

		$ref          = isset( $_POST['subscription'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription'] ) ) : '';
		$subscription = '' !== $ref ? P2Flux_WC_Subscriptions::load( $ref ) : null;

		if ( ! $subscription || ! self::owned_by_current_user( $subscription ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'p2flux-for-woocommerce' ) ), 403 );
		}

		// Anything that could lead to a charge is closed to a cancelled or expired subscription, whoever asks.
		if ( $collecting && in_array( $subscription->get_status(), array( 'cancelled', 'pending-cancel', 'expired' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This subscription is no longer collecting payments.', 'p2flux-for-woocommerce' ) ), 400 );
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
