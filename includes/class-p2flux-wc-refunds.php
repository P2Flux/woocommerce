<?php
/**
 * Refunds: one per payment, sent from the merchant's own wallet.
 *
 * P2Flux never holds the money, so it cannot send a refund; the merchant's wallet does, and P2Flux
 * verifies that the transfer happened and matches the original settlement. Two consequences shape
 * this file.
 *
 * First, P2Flux keeps no refund history, so nothing on their side can stop a second refund. The
 * order row is the only record, which is why the refund is RESERVED before the terms are prepared:
 * a merchant who opens the refund window twice must find the second attempt already spoken for.
 *
 * Second, v1 refunds in full only. The protocol allows one partial refund - but exactly one, and a
 * merchant who refunds $5 of a $100 order to "start" has silently spent the only refund that order
 * will ever have. A full refund is the one shape that cannot surprise anyone.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The refund flow.
 */
class P2Flux_WC_Refunds {

	const META = '_p2flux_refund';

	/** Reserved, nothing sent. */
	const RESERVED = 'reserved';
	/** The wallet broadcast a transfer. */
	const SENT = 'sent';
	/** Verified on chain and recorded in WooCommerce. */
	const REFUNDED = 'refunded';
	/** A transfer was sent that P2Flux could not match. Needs a human. */
	const MISMATCH = 'mismatch';

	/** How long a reservation with no transaction stays claimed. */
	const RESERVATION_TTL = 1200;

	/**
	 * Reserve this order's refund and get the terms for it.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $units Ignored: v1 refunds in full. Kept so the endpoint's shape can grow.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function prepare( $order, $units = 0 ) {
		unset( $units );

		$state = self::state( $order );
		if ( in_array( $state['status'], array( self::REFUNDED, self::SENT ), true ) ) {
			return new WP_Error( 'p2flux_refunded', __( 'This payment has already been refunded, or a refund is already on its way.', 'p2flux-for-woocommerce' ) );
		}
		if ( self::RESERVED === $state['status'] && ( time() - (int) $state['ts'] ) < self::RESERVATION_TTL ) {
			return new WP_Error( 'p2flux_reserved', __( 'A refund for this order was started a moment ago. Finish that one, or wait twenty minutes and try again.', 'p2flux-for-woocommerce' ) );
		}
		if ( $order->get_total_refunded() > 0 ) {
			return new WP_Error( 'p2flux_already_refunded', __( 'This order already has a refund recorded in WooCommerce.', 'p2flux-for-woocommerce' ) );
		}

		$original = self::original( $order );
		if ( is_wp_error( $original ) ) {
			return $original;
		}

		$amount = (int) $order->get_meta( '_p2flux_paid_units' );
		if ( $amount < 1 ) {
			return new WP_Error( 'p2flux_amount', __( 'The amount this order paid in USDC is not recorded, so it cannot be refunded automatically.', 'p2flux-for-woocommerce' ) );
		}

		// Reserve first. Preparing terms is a network call, and a second click during it would
		// otherwise produce a second perfectly valid refund token.
		self::set_state( $order, self::RESERVED, array( 'units' => $amount ) );

		$client = P2Flux_WC_Client::for_object( $order );

		try {
			$prepared = $client->prepareRefund( $original, (string) $amount );
		} catch ( \Exception $e ) {
			// Nothing was sent, so the reservation would only block a legitimate retry.
			self::clear( $order );
			P2Flux_WC_Logger::error( 'could not prepare a refund', array( 'order' => $order->get_id(), 'error' => $e->getMessage() ) );

			return new WP_Error( 'p2flux_unavailable', __( 'P2Flux could not prepare this refund. Nothing was sent; please try again.', 'p2flux-for-woocommerce' ) );
		}

		$environment = (string) $order->get_meta( '_p2flux_env' );

		return array(
			'url'   => P2Flux_WC_Client::checkout_url( $environment ) . '/#/refund/' . rawurlencode( (string) $prepared['refund_token'] ),
			'units' => $amount,
		);
	}

	/**
	 * Confirm a refund transfer and record it in WooCommerce.
	 *
	 * The order is deliberate: P2Flux verifies against the chain first, and only a verified refund
	 * becomes a WooCommerce refund. A store that books the refund first and verifies later has a
	 * refunded order and, sometimes, no refund.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $hash  Refund transaction, or '' to re-check the stored one.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function verify( $order, $hash = '' ) {
		$state = self::state( $order );
		$hash  = '' !== $hash ? $hash : (string) $state['refund_tx_hash'];

		if ( '' === $hash ) {
			return new WP_Error( 'p2flux_no_tx', __( 'No refund transaction has been recorded for this order yet.', 'p2flux-for-woocommerce' ) );
		}
		if ( self::REFUNDED === $state['status'] ) {
			return array(
				'status'  => self::REFUNDED,
				'tx_hash' => $hash,
			);
		}

		$original = self::original( $order );
		if ( is_wp_error( $original ) ) {
			return $original;
		}

		// Remember the hash before verifying: if this request dies, the "Re-check" button needs to
		// know which transfer to ask about rather than offering to send another.
		self::set_state( $order, self::SENT, array( 'refund_tx_hash' => $hash, 'units' => (int) $state['units'] ) );

		$client = P2Flux_WC_Client::for_object( $order );
		$units  = (int) $state['units'] > 0 ? (int) $state['units'] : (int) $order->get_meta( '_p2flux_paid_units' );

		try {
			$verdict = $client->verifyRefund( $original, (string) $units, $hash );
		} catch ( \Exception $e ) {
			return new WP_Error( 'p2flux_unavailable', __( 'P2Flux could not confirm this refund yet. The transfer has been recorded; use Re-check in a moment.', 'p2flux-for-woocommerce' ) );
		}

		$status = isset( $verdict['status'] ) ? (string) $verdict['status'] : '';
		$code   = isset( $verdict['error'] ) ? (string) $verdict['error'] : ( isset( $verdict['code'] ) ? (string) $verdict['code'] : '' );

		if ( 'REFUND_CONFIRMING' === $code ) {
			return array(
				'status'  => 'confirming',
				'tx_hash' => $hash,
			);
		}

		if ( 'REFUNDED' !== $status ) {
			self::set_state( $order, self::MISMATCH, array( 'refund_tx_hash' => $hash, 'units' => $units ) );
			$order->add_order_note(
				sprintf(
					/* translators: 1: protocol error code. */
					__( 'P2Flux could not match this refund transfer to the original payment (%s). It has NOT been recorded as a refund.', 'p2flux-for-woocommerce' ),
					'' !== $code ? $code : 'UNKNOWN'
				)
			);
			$order->save();

			return new WP_Error( 'p2flux_mismatch', __( 'That transfer does not match the original payment. Nothing was recorded.', 'p2flux-for-woocommerce' ) );
		}

		$explorer = P2Flux_WC_Client::explorer_url( (string) $order->get_meta( '_p2flux_env' ) );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $order->get_total(),
				'reason'   => sprintf(
					/* translators: 1: transaction URL. */
					__( 'Refunded in USDC via P2Flux: %s', 'p2flux-for-woocommerce' ),
					$explorer . '/tx/' . $hash
				),
				// The money already moved, from the merchant's own wallet. Asking the gateway to
				// send it would either fail or send it twice.
				'refund_payment' => false,
			)
		);

		self::set_state( $order, self::REFUNDED, array( 'refund_tx_hash' => $hash, 'units' => $units ) );

		return array(
			'status'  => self::REFUNDED,
			'tx_hash' => $hash,
		);
	}

	/**
	 * What P2Flux needs to identify the payment being refunded.
	 *
	 * For a subscription charge this is the authorization that collected it - the one recorded on
	 * the order, never whichever is current, because a customer may have re-authorized since.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function original( $order ) {
		$hash = (string) $order->get_meta( '_p2flux_tx_hash' );
		if ( '' === $hash ) {
			return new WP_Error(
				'p2flux_no_settlement',
				__( 'This order’s settlement transaction is not known yet, so there is nothing to refund against. Use “Recover transaction” first.', 'p2flux-for-woocommerce' )
			);
		}

		$auth_id = (string) $order->get_meta( '_p2flux_auth_id' );

		if ( '' !== $auth_id ) {
			$subscription = self::subscription_for( $order );
			if ( ! $subscription ) {
				return new WP_Error( 'p2flux_no_subscription', __( 'The subscription this payment belongs to could not be found.', 'p2flux-for-woocommerce' ) );
			}

			$capability = P2Flux_WC_Auth_History::capability( $subscription, $auth_id );
			if ( null === $capability ) {
				return new WP_Error( 'p2flux_no_capability', __( 'The authorization that collected this payment could not be read.', 'p2flux-for-woocommerce' ) );
			}

			return array(
				'subscription' => $capability,
				'tx_hash'      => $hash,
				'period_index' => (int) $order->get_meta( '_p2flux_period_index' ),
			);
		}

		$intent = (string) $order->get_meta( '_p2flux_settled_intent' );
		if ( '' === $intent ) {
			return new WP_Error( 'p2flux_no_intent', __( 'The payment intent that settled this order is not recorded.', 'p2flux-for-woocommerce' ) );
		}

		return array(
			'intent'  => $intent,
			'tx_hash' => $hash,
		);
	}

	/**
	 * Current refund state for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function state( $order ) {
		$stored = $order->get_meta( self::META );
		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = json_decode( $stored, true );
		}

		return array_merge(
			array(
				'status'         => '',
				'units'          => 0,
				'refund_tx_hash' => '',
				'ts'             => 0,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Write refund state.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status New status.
	 * @param array    $extra  units, refund_tx_hash.
	 * @return void
	 */
	private static function set_state( $order, $status, array $extra = array() ) {
		$state = array_merge(
			self::state( $order ),
			$extra,
			array(
				'status' => $status,
				'ts'     => time(),
			)
		);

		$order->update_meta_data( self::META, wp_json_encode( $state ) );
		$order->save();
	}

	/**
	 * Drop a reservation that never became a transfer.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function clear( $order ) {
		$order->delete_meta_data( self::META );
		$order->save();
	}

	/**
	 * The subscription an order belongs to.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Subscription|null
	 */
	private static function subscription_for( $order ) {
		return P2Flux_WC_Subscriptions::for_order( $order );
	}
}
