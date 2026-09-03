<?php
/**
 * My Account → USDC subscriptions: the customer's native subscriptions, and what they may do.
 *
 * Its own endpoint, named so it never collides with WooCommerce Subscriptions' "Subscriptions" tab.
 * The list shows what a person wants to know at a glance; the detail view shows the orders and the
 * wallet-authorization box the WCS integration already draws, with one addition: a Cancel button.
 * Which actions appear follows the status - nothing that could lead to a charge is offered on a
 * cancelled or expired subscription, and the server refuses it anyway.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The account endpoint.
 */
class P2Flux_WC_Native_Account {

	const ENDPOINT = 'p2flux-subscriptions';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'wc_ajax_p2flux_native_cancel', array( __CLASS__, 'cancel' ) );
	}

	/**
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * After "Orders".
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu_item( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = __( 'USDC subscriptions', 'p2flux-for-woocommerce' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'USDC subscriptions', 'p2flux-for-woocommerce' );
		}

		return $out;
	}

	/**
	 * @return string
	 */
	public static function title() {
		return __( 'USDC subscriptions', 'p2flux-for-woocommerce' );
	}

	/**
	 * The page.
	 *
	 * @param string $value The id after the endpoint, or ''.
	 * @return void
	 */
	public static function content( $value ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$id = absint( $value );
		if ( $id ) {
			$subscription = P2Flux_WC_Native_Subscription::load( $id );
			if ( $subscription && $subscription->get_user_id() === $user_id ) {
				self::detail( $subscription );

				return;
			}
			echo '<p>' . esc_html__( 'That subscription was not found.', 'p2flux-for-woocommerce' ) . '</p>';

			return;
		}

		self::listing( P2Flux_WC_Native_Subscription::for_user( $user_id ) );
	}

	/**
	 * The list.
	 *
	 * @param array<int,P2Flux_WC_Native_Subscription> $subscriptions Subscriptions.
	 * @return void
	 */
	private static function listing( array $subscriptions ) {
		if ( empty( $subscriptions ) ) {
			echo '<p>' . esc_html__( 'You have no USDC subscriptions.', 'p2flux-for-woocommerce' ) . '</p>';

			return;
		}

		echo '<table class="woocommerce-orders-table shop_table shop_table_responsive"><thead><tr>';
		foreach ( array( __( 'Subscription', 'p2flux-for-woocommerce' ), __( 'Amount', 'p2flux-for-woocommerce' ), __( 'Status', 'p2flux-for-woocommerce' ), __( 'Next payment', 'p2flux-for-woocommerce' ), '' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $subscriptions as $subscription ) {
			$url = wc_get_account_endpoint_url( self::ENDPOINT ) . $subscription->get_id() . '/';
			if ( '' === get_option( 'permalink_structure' ) ) {
				$url = add_query_arg( self::ENDPOINT, $subscription->get_id(), wc_get_page_permalink( 'myaccount' ) );
			}
			echo '<tr>';
			echo '<td data-title="' . esc_attr__( 'Subscription', 'p2flux-for-woocommerce' ) . '"><a href="' . esc_url( $url ) . '">#' . (int) $subscription->get_id() . '</a> ' . esc_html( (string) $subscription->get( 'product_name' ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Amount', 'p2flux-for-woocommerce' ) . '">' . esc_html( self::amount( $subscription ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Status', 'p2flux-for-woocommerce' ) . '">' . esc_html( $subscription->status_label() ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Next payment', 'p2flux-for-woocommerce' ) . '">' . esc_html( self::next_payment( $subscription ) ) . '</td>';
			echo '<td><a class="woocommerce-button button" href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'p2flux-for-woocommerce' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * One subscription.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	private static function detail( $subscription ) {
		$rows = array(
			__( 'Product', 'p2flux-for-woocommerce' )      => (string) $subscription->get( 'product_name' ),
			__( 'Amount', 'p2flux-for-woocommerce' )       => self::amount( $subscription ),
			__( 'Status', 'p2flux-for-woocommerce' )       => $subscription->status_label(),
			__( 'Started', 'p2flux-for-woocommerce' )      => self::date( $subscription->timestamp( 'schedule_anchor' ) ?: $subscription->timestamp( 'created_at' ) ),
			__( 'Next payment', 'p2flux-for-woocommerce' ) => self::next_payment( $subscription ),
		);

		/* translators: %d: subscription id. */
		echo '<h2>' . esc_html( sprintf( __( 'Subscription #%d', 'p2flux-for-woocommerce' ), $subscription->get_id() ) ) . '</h2>';
		echo '<table class="woocommerce-table shop_table"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		self::status_note( $subscription );

		// Orders: the signup and every renewal.
		echo '<h3>' . esc_html__( 'Payments', 'p2flux-for-woocommerce' ) . '</h3>';
		echo '<table class="woocommerce-orders-table shop_table shop_table_responsive"><thead><tr><th>' . esc_html__( 'Order', 'p2flux-for-woocommerce' ) . '</th><th>' . esc_html__( 'Date', 'p2flux-for-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'p2flux-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_reverse( $subscription->get_related_orders( 'ids' ) ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			echo '<tr><td><a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ) . '</td>';
			echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
			echo '<td>';
			if ( $order->needs_payment() && ! $subscription->has_status( P2Flux_WC_Native_Subscription::EXPIRED ) ) {
				echo '<a class="woocommerce-button button" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay', 'p2flux-for-woocommerce' ) . '</a> ';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// The wallet-authorization box: retry, restore, re-authorize, revoke - each only when it applies.
		P2Flux_WC_Account::render( $subscription );

		if ( $subscription->has_status( array( P2Flux_WC_Native_Subscription::ACTIVE, P2Flux_WC_Native_Subscription::ON_HOLD, P2Flux_WC_Native_Subscription::PENDING ) ) ) {
			echo '<h2>' . esc_html__( 'Cancel', 'p2flux-for-woocommerce' ) . '</h2>';
			echo '<p>' . esc_html__( 'Cancelling stops this store from collecting any further payment. It does not refund the period you have already paid for. Your wallet authorization can be revoked afterwards.', 'p2flux-for-woocommerce' ) . '</p>';
			printf(
				'<p><button type="button" class="button" id="p2flux-native-cancel" data-subscription="%s" data-nonce="%s" data-url="%s">%s</button></p><p id="p2flux-native-cancel-status" role="status" aria-live="polite"></p>',
				esc_attr( P2Flux_WC_Subscriptions::ref( $subscription ) ),
				esc_attr( wp_create_nonce( 'p2flux_wc_account' ) ),
				esc_url( WC_AJAX::get_endpoint( 'p2flux_native_cancel' ) ),
				esc_html__( 'Cancel subscription', 'p2flux-for-woocommerce' )
			);
			wp_enqueue_script( 'p2flux-wc-native-account', plugins_url( 'assets/native-account.js', P2FLUX_WC_FILE ), array(), P2FLUX_WC_VERSION, true );
		}
	}

	/**
	 * What the status means, in the cases that need a sentence.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	private static function status_note( $subscription ) {
		switch ( $subscription->get_status() ) {
			case P2Flux_WC_Native_Subscription::EXPIRED:
				echo '<p>' . esc_html__( 'This subscription was never activated and will not be charged. If you authorized it in your wallet, you can revoke that unused authorization below.', 'p2flux-for-woocommerce' ) . '</p>';
				break;
			case P2Flux_WC_Native_Subscription::ON_HOLD:
				echo '<p>' . esc_html__( 'A payment could not be collected. The subscription stays on hold until a future payment succeeds or you cancel it; missed periods are not collected later.', 'p2flux-for-woocommerce' ) . '</p>';
				break;
			case P2Flux_WC_Native_Subscription::PENDING:
				echo '<p>' . esc_html__( 'Waiting for the first payment. It must complete shortly after authorization; otherwise the signup expires and nothing is charged.', 'p2flux-for-woocommerce' ) . '</p>';
				break;
			case P2Flux_WC_Native_Subscription::CANCELLED:
				echo '<p>' . esc_html__( 'This subscription is cancelled. No further payments will be collected by this store.', 'p2flux-for-woocommerce' ) . '</p>';
				break;
		}
	}

	/**
	 * Customer cancels. Immediate, local; the wallet authorization is theirs to revoke afterwards.
	 *
	 * @return void
	 */
	public static function cancel() {
		check_ajax_referer( 'p2flux_wc_account', 'nonce' );

		$ref          = isset( $_POST['subscription'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription'] ) ) : '';
		$subscription = P2Flux_WC_Subscriptions::load( $ref );
		if ( ! P2Flux_WC_Subscriptions::is_native( $subscription ) || ! get_current_user_id() || $subscription->get_user_id() !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'p2flux-for-woocommerce' ) ), 403 );
		}

		$done = self::cancel_subscription( $subscription, __( 'Cancelled by the customer from their account page.', 'p2flux-for-woocommerce' ) );
		if ( ! $done ) {
			wp_send_json_error( array( 'message' => __( 'This subscription cannot be cancelled.', 'p2flux-for-woocommerce' ) ), 400 );
		}

		wp_send_json_success( array( 'status' => 'cancelled' ) );
	}

	/**
	 * Cancel a native subscription, from the account page or the admin. One place, one set of effects.
	 *
	 * A pending signup is aborted rather than cancelled: it never activated, so it becomes expired.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param string                        $note         Why.
	 * @return bool
	 */
	public static function cancel_subscription( $subscription, $note ) {
		if ( $subscription->has_status( P2Flux_WC_Native_Subscription::PENDING ) ) {
			P2Flux_WC_Native_Scheduler::expire( $subscription, $note );

			return true;
		}
		if ( ! $subscription->can_be_updated_to( P2Flux_WC_Native_Subscription::CANCELLED ) ) {
			return false;
		}

		$subscription->update_status( P2Flux_WC_Native_Subscription::CANCELLED, $note );
		$subscription->set_timestamp( 'next_payment_at', 0 );
		$subscription->save();
		P2Flux_WC_Collection::set( $subscription, P2Flux_WC_Collection::CANCELLED, array( 'reason' => 'cancelled' ) );
		P2Flux_WC_Jobs::unschedule_subscription( $subscription );

		if ( class_exists( 'P2Flux_WC_Native_Emails' ) ) {
			P2Flux_WC_Native_Emails::cancelled( $subscription );
		}

		return true;
	}

	/*
	 * ---- Formatting ----
	 */

	/**
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return string
	 */
	public static function amount( $subscription ) {
		return sprintf( '%s USDC / %s', P2Flux_WC_Money::display( (int) $subscription->get( 'amount_units' ) ), $subscription->interval_label() );
	}

	/**
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return string
	 */
	public static function next_payment( $subscription ) {
		if ( ! $subscription->has_status( array( P2Flux_WC_Native_Subscription::ACTIVE, P2Flux_WC_Native_Subscription::ON_HOLD ) ) ) {
			return '—';
		}

		return self::date( $subscription->timestamp( 'next_payment_at' ) );
	}

	/**
	 * @param int $ts Unix seconds.
	 * @return string
	 */
	public static function date( $ts ) {
		return $ts > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
	}
}
