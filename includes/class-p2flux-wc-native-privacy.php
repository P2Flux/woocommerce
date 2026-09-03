<?php
/**
 * What a native subscription knows about a person, and what happens to it when they ask.
 *
 * The record holds no name, address, email, wallet or IP: those are WooCommerce's, on the customer
 * and the orders, and WooCommerce's own exporter and eraser handle them. What the record holds is a
 * link to the customer (`user_id`) and the financial history of a subscription: amounts, periods,
 * public chain identifiers (an authorization digest, transaction hashes), and the encrypted
 * authorization the store needs to refund or the customer to revoke.
 *
 * So: the exporter hands the customer everything the record says about their subscriptions. The
 * eraser breaks the link - `user_id` becomes 0, and so does the parent order's customer id through
 * WooCommerce's own eraser - and keeps the financial rows, because a payment that happened on a
 * public chain is not made private by deleting the merchant's only record of it, and the encrypted
 * authorization is what still lets the money be refunded. Deleting the WordPress user does the same.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Personal-data export and erasure for native subscriptions.
 */
class P2Flux_WC_Native_Privacy {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'deleted_user', array( __CLASS__, 'user_deleted' ) );
	}

	/**
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['p2flux-native-subscriptions'] = array(
			'exporter_friendly_name' => __( 'P2Flux subscriptions', 'p2flux-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['p2flux-native-subscriptions'] = array(
			'eraser_friendly_name' => __( 'P2Flux subscriptions', 'p2flux-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Everything the record says about this customer's subscriptions.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (unused; the list is small).
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		unset( $page );
		$user = get_user_by( 'email', $email );
		$data = array();

		if ( $user ) {
			foreach ( P2Flux_WC_Native_Subscription::for_user( $user->ID ) as $subscription ) {
				$auth = P2Flux_WC_Auth_History::active( $subscription );
				$data[] = array(
					'group_id'    => 'p2flux_native_subscriptions',
					'group_label' => __( 'P2Flux subscriptions', 'p2flux-for-woocommerce' ),
					'item_id'     => 'p2flux-native-subscription-' . $subscription->get_id(),
					'data'        => array(
						array( 'name' => __( 'Subscription', 'p2flux-for-woocommerce' ), 'value' => '#' . $subscription->get_id() ),
						array( 'name' => __( 'Product', 'p2flux-for-woocommerce' ), 'value' => (string) $subscription->get( 'product_name' ) ),
						array( 'name' => __( 'Amount', 'p2flux-for-woocommerce' ), 'value' => P2Flux_WC_Money::display( (int) $subscription->get( 'amount_units' ) ) . ' USDC / ' . $subscription->interval_label() ),
						array( 'name' => __( 'Status', 'p2flux-for-woocommerce' ), 'value' => $subscription->status_label() ),
						array( 'name' => __( 'Network', 'p2flux-for-woocommerce' ), 'value' => (string) $subscription->get( 'env' ) ),
						array( 'name' => __( 'Payout wallet (store)', 'p2flux-for-woocommerce' ), 'value' => (string) $subscription->get( 'recipient' ) ),
						array( 'name' => __( 'Authorization id', 'p2flux-for-woocommerce' ), 'value' => $auth ? (string) $auth['id'] : '' ),
						array( 'name' => __( 'Created', 'p2flux-for-woocommerce' ), 'value' => (string) $subscription->get( 'created_at' ) ),
						array( 'name' => __( 'Next payment', 'p2flux-for-woocommerce' ), 'value' => (string) $subscription->get( 'next_payment_at' ) ),
						array( 'name' => __( 'Orders', 'p2flux-for-woocommerce' ), 'value' => implode( ', ', array_map( static function ( $id ) { return '#' . $id; }, $subscription->get_related_orders( 'ids' ) ) ) ),
					),
				);
			}
		}

		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Break the link to the person; keep the financial history.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (unused).
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$user     = get_user_by( 'email', $email );
		$removed  = false;
		$retained = false;
		$messages = array();

		if ( $user ) {
			foreach ( P2Flux_WC_Native_Subscription::for_user( $user->ID ) as $subscription ) {
				self::detach( $subscription, __( 'Personal data erasure requested; the subscription record is no longer linked to a customer.', 'p2flux-for-woocommerce' ) );
				$removed  = true;
				$retained = true;
			}
			if ( $retained ) {
				$messages[] = __( 'P2Flux subscription records were unlinked from the customer. The financial history (amounts, billing periods, public blockchain identifiers and the encrypted authorization needed for refunds) is retained.', 'p2flux-for-woocommerce' );
			}
		}

		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true );
	}

	/**
	 * A WordPress user was deleted: their subscriptions keep their history, not the link.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function user_deleted( $user_id ) {
		foreach ( P2Flux_WC_Native_Subscription::for_user( (int) $user_id ) as $subscription ) {
			self::detach( $subscription, __( 'The customer account was deleted; the subscription record is no longer linked to a customer.', 'p2flux-for-woocommerce' ) );
		}
	}

	/**
	 * Unlink and stop. A subscription nobody owns cannot be managed, so it is cancelled too - it must
	 * never charge a wallet whose owner the store no longer knows.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @param string                        $note         Why.
	 * @return void
	 */
	private static function detach( $subscription, $note ) {
		if ( $subscription->has_status( array( P2Flux_WC_Native_Subscription::ACTIVE, P2Flux_WC_Native_Subscription::ON_HOLD, P2Flux_WC_Native_Subscription::PENDING ) ) ) {
			P2Flux_WC_Native_Account::cancel_subscription( $subscription, $note );
			$subscription = P2Flux_WC_Native_Subscription::load( $subscription->get_id() );
		}
		if ( $subscription ) {
			$subscription->set( 'user_id', 0 );
			$subscription->save();
		}
	}
}
