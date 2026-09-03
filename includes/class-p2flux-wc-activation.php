<?php
/**
 * Turning a capability the browser hands back into a subscription this store can charge.
 *
 * The capability arrives from the checkout window, which means it arrives from the customer's
 * browser, which means it is a claim. A genuine capability for somebody else's cheaper plan is
 * still a genuine capability - so before anything is stored, the server reads the subscription's
 * own terms from the chain and checks them against the setup this order created. The salt is what
 * makes that check exact: two setups for the same price and period differ by nothing else.
 *
 * Storing comes before charging, always. A capability that is charged and then lost is a
 * subscription the customer is paying for and the store cannot see.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription activation.
 */
class P2Flux_WC_Activation {

	/**
	 * Validate and store a capability, under the subscription lock.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param WC_Order        $order        Parent order.
	 * @param string          $capability   The p2s2 reference from the checkout.
	 * @return true|WP_Error Error codes are the bare CODEs the hosted checkout knows how to phrase.
	 */
	public static function store( $subscription, $order, $capability ) {
		$outcome = P2Flux_WC_Lock::with(
			P2Flux_WC_Subscriptions::lock_key( $subscription ),
			static function () use ( $subscription, $order, $capability ) {
				// Re-read inside the lock: another request may have activated this already, in which
				// case there is nothing to do and saying so is not an error.
				$fresh = P2Flux_WC_Subscriptions::load( P2Flux_WC_Subscriptions::ref( $subscription ) );
				if ( ! $fresh ) {
					return new WP_Error( 'INVALID_SUBSCRIPTION' );
				}

				$active  = P2Flux_WC_Auth_History::active( $fresh );
				$pending = P2Flux_WC_Auth_History::pending( $fresh );

				/*
				 * An active authorization is only ever replaced by a setup that says, by id, which one
				 * it replaces - a re-authorization at new terms. Any other capability arriving while
				 * one is active is the same customer's double-click on the signup, and nothing to do.
				 */
				if ( $active ) {
					$replaces = $pending && isset( $pending['replaces_auth_id'] ) ? strtolower( (string) $pending['replaces_auth_id'] ) : '';
					if ( '' === $replaces || $replaces !== strtolower( (string) $active['id'] ) ) {
						return true;
					}
				}

				if ( ! $pending ) {
					return new WP_Error( 'SETUP_MISMATCH' );
				}

				$client = P2Flux_WC_Client::for_environment( $pending['environment'] );

				try {
					$status = $client->status( $capability );
				} catch ( \Exception $e ) {
					P2Flux_WC_Logger::log( 'could not read a returned subscription', array( 'order' => $order->get_id(), 'error' => $e->getMessage() ) );

					return new WP_Error( 'INVALID_SUBSCRIPTION' );
				}

				$mismatch = self::terms_mismatch( $status, $pending );
				if ( null !== $mismatch ) {
					P2Flux_WC_Logger::error( 'a returned capability did not match this checkout', array( 'order' => $order->get_id(), 'reason' => $mismatch ) );

					return new WP_Error( $mismatch );
				}

				$sealed = P2Flux_WC_Crypto::encrypt( $capability );
				if ( null === $sealed ) {
					// No key means no safe place to put it. Refusing loses the authorization; storing
					// it in the clear would put a standing payment permission in the database.
					return new WP_Error( 'ENCRYPTION_UNAVAILABLE' );
				}

				$terms = $status['terms'];

				P2Flux_WC_Auth_History::activate(
					$fresh,
					array(
						'id'          => (string) $status['subscription_id'],
						'cap'         => $sealed,
						'environment' => $pending['environment'],
						'recipient'   => strtolower( (string) $terms['recipient'] ),
						'units'       => (int) $terms['amount_units'],
						'period'      => (int) $terms['period'],
						'start'       => (int) $terms['start'],
						'end'         => (int) $terms['end'],
						'salt'        => (string) $terms['salt'],
						'activated_order' => $order->get_id(),
					),
					isset( $pending['replaces_auth_id'] ) ? $pending['replaces_auth_id'] : null
				);

				// What renewals are checked against from now on.
				$fresh->update_meta_data( '_p2flux_units', (int) $terms['amount_units'] );
				$fresh->update_meta_data( '_p2flux_period', (int) $terms['period'] );
				$fresh->save();

				P2Flux_WC_Collection::set( $fresh, P2Flux_WC_Collection::NORMAL, array( 'renewal_order_id' => $order->get_id() ) );
				P2Flux_WC_Subscriptions::after_activated( $fresh, $status );

				return true;
			}
		);

		if ( false === $outcome ) {
			// Another request holds the lock - almost certainly the same customer's double-click.
			return new WP_Error( 'INVALID_SUBSCRIPTION' );
		}

		return $outcome;
	}

	/**
	 * Does this capability describe the subscription this order set up?
	 *
	 * @param array $status  Response from /v1/subscriptions/status.
	 * @param array $pending The setup this order created.
	 * @return string|null A bare CODE the checkout knows, or null when everything matches.
	 */
	private static function terms_mismatch( array $status, array $pending ) {
		if ( empty( $status['terms'] ) || empty( $status['subscription_id'] ) ) {
			return 'INVALID_SUBSCRIPTION';
		}

		$terms = $status['terms'];

		// The salt is the exact one: price and period can coincide between two setups, this cannot.
		if ( isset( $pending['salt'] ) && '' !== $pending['salt'] && (string) $terms['salt'] !== (string) $pending['salt'] ) {
			return 'SETUP_MISMATCH';
		}
		if ( (int) $terms['amount_units'] !== (int) $pending['units'] ) {
			return 'AMOUNT_MISMATCH';
		}
		if ( strtolower( (string) $terms['recipient'] ) !== strtolower( (string) $pending['recipient'] ) ) {
			return 'RECIPIENT_MISMATCH';
		}
		if ( (int) $terms['period'] !== (int) $pending['period'] ) {
			return 'PERIOD_MISMATCH';
		}

		return null;
	}

	/**
	 * Translate a charge outcome into what the pay screen should do next.
	 *
	 * The screen's job after this is to answer the still-open checkout window, which is waiting to
	 * be told whether the first charge worked. Only failures the store will not quietly recover from
	 * are reported: a transient error is our problem, and telling the buyer their payment failed
	 * while a retry is pending would be a lie with a refund request attached.
	 *
	 * @param array    $outcome From the charger.
	 * @param WC_Order $order   Parent order.
	 * @return array<string,mixed>
	 */
	public static function to_page_result( array $outcome, $order ) {
		$fresh = wc_get_order( $order->get_id() );

		switch ( $outcome['status'] ) {
			case 'charged':
				return array(
					'status'   => 'finalized',
					'tx_hash'  => $outcome['tx_hash'],
					'redirect' => $fresh ? $fresh->get_checkout_order_received_url() : '',
				);

			case 'reconciling':
				// Collected, settlement not yet known. Keep the buyer on the page; the order is paid
				// the moment reconciliation proves the transaction.
				return array( 'status' => 'confirming' );

			case 'failed':
			case 'cancelled':
				return array(
					'status' => 'failed',
					'code'   => $outcome['code'],
				);

			case 'refused':
				if ( 'ALREADY_PAID' === $outcome['code'] ) {
					return array(
						'status'   => 'finalized',
						'tx_hash'  => $fresh ? (string) $fresh->get_meta( '_p2flux_tx_hash' ) : '',
						'redirect' => $fresh ? $fresh->get_checkout_order_received_url() : '',
					);
				}

				return array(
					'status' => 'failed',
					'code'   => $outcome['code'],
				);

			default:
				// Pending or busy: we keep trying in the background and say so, without telling the
				// checkout window anything it would turn into a failure message.
				return array( 'status' => 'pending' );
		}
	}
}
