<?php
/**
 * May this plugin attempt a charge right now?
 *
 * WooCommerce Subscriptions puts a subscription on hold for two completely different reasons, and
 * the status alone cannot tell them apart. When a renewal is due, WCS itself sets on-hold and calls
 * the gateway - collecting is exactly what is wanted. When a customer or a shop manager suspends a
 * subscription, it also goes on hold - and collecting would then charge someone who asked not to be.
 *
 * "active or on-hold" is therefore not authority to charge. This class carries the missing half:
 * the plugin's own reason, alongside which renewal it applies to.
 *
 * It is not a second subscription engine. WCS owns the schedule, the statuses and the emails; this
 * answers one question about one renewal, and every charge path asks it after re-reading state
 * under the lock.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-subscription collection state.
 */
class P2Flux_WC_Collection {

	const META = '_p2flux_collection';

	/** Nothing unusual: charge when WCS says a renewal is due. */
	const NORMAL = 'normal';
	/** We put it on hold because a charge needs the customer to act. Bounded retries may run. */
	const DUNNING = 'dunning';
	/** A human suspended it. Never charge. */
	const SUSPENDED = 'suspended';
	/** The stored authorization cannot be used; the customer must sign a new one. */
	const REAUTH_REQUIRED = 'reauth_required';
	/** Cancelled, expired, or revoked on chain. Never charge. */
	const CANCELLED = 'cancelled';

	/**
	 * Read the state, defaulting to normal.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @return array<string,mixed>
	 */
	public static function get( $subscription ) {
		$stored = $subscription->get_meta( self::META );
		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = json_decode( $stored, true );
		}

		$state = is_array( $stored ) ? $stored : array();

		return array_merge(
			array(
				'state'            => self::NORMAL,
				'renewal_order_id' => 0,
				'reason'           => '',
				'attempts'         => array(),
				'since'            => 0,
			),
			$state
		);
	}

	/**
	 * Write a new state.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param string                   $state        One of the constants.
	 * @param array<string,mixed>      $extra        renewal_order_id, reason, attempts.
	 * @return void
	 */
	public static function set( $subscription, $state, array $extra = array() ) {
		$current = self::get( $subscription );
		$next    = array_merge(
			$current,
			$extra,
			array(
				'state' => $state,
				'since' => time(),
			)
		);

		// A change of state starts its own counters: dunning attempts have nothing to say about the
		// retries of a transient RPC failure.
		if ( $current['state'] !== $state && ! isset( $extra['attempts'] ) ) {
			$next['attempts'] = array();
		}

		$subscription->update_meta_data( self::META, wp_json_encode( $next ) );
		$subscription->save();
	}

	/**
	 * Count one attempt of a kind, and return the new total.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param string                   $kind         'retry' | 'dunning' | 'wait' | 'reconcile'.
	 * @return int
	 */
	public static function bump( $subscription, $kind ) {
		$state                      = self::get( $subscription );
		$attempts                   = is_array( $state['attempts'] ) ? $state['attempts'] : array();
		$attempts[ $kind ]          = isset( $attempts[ $kind ] ) ? (int) $attempts[ $kind ] + 1 : 1;
		$state['attempts']          = $attempts;

		$subscription->update_meta_data( self::META, wp_json_encode( $state ) );
		$subscription->save();

		return $attempts[ $kind ];
	}

	/**
	 * Forget one counter. A no-op (no write) when it is already zero.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param string                   $kind         Counter name.
	 * @return void
	 */
	public static function reset( $subscription, $kind ) {
		$state    = self::get( $subscription );
		$attempts = is_array( $state['attempts'] ) ? $state['attempts'] : array();
		if ( ! isset( $attempts[ $kind ] ) ) {
			return;
		}
		unset( $attempts[ $kind ] );
		$state['attempts'] = $attempts;
		$subscription->update_meta_data( self::META, wp_json_encode( $state ) );
		$subscription->save();
	}

	/**
	 * How many attempts of a kind have been made.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param string                   $kind         Counter name.
	 * @return int
	 */
	public static function attempts( $subscription, $kind ) {
		$state    = self::get( $subscription );
		$attempts = is_array( $state['attempts'] ) ? $state['attempts'] : array();

		return isset( $attempts[ $kind ] ) ? (int) $attempts[ $kind ] : 0;
	}

	/**
	 * May a charge be attempted for this renewal order?
	 *
	 * The only question this class exists to answer, and every charge path asks it after re-reading
	 * both objects under the lock.
	 *
	 * @param string $wcs_status       WooCommerce subscription status.
	 * @param array  $collection       State from get().
	 * @param int    $renewal_order_id The order a charge would pay, 0 for a first charge.
	 * @return true|string True, or a refusal code for the note and the log.
	 */
	public static function may_charge( $wcs_status, array $collection, $renewal_order_id = 0 ) {
		if ( in_array( $collection['state'], array( self::CANCELLED, self::SUSPENDED ), true ) ) {
			return $collection['state'];
		}
		if ( self::REAUTH_REQUIRED === $collection['state'] ) {
			return self::REAUTH_REQUIRED;
		}

		if ( in_array( $wcs_status, array( 'cancelled', 'pending-cancel', 'expired', 'switched' ), true ) ) {
			return 'wcs_' . $wcs_status;
		}

		if ( 'on-hold' === $wcs_status ) {
			/*
			 * On hold and normal is the ordinary renewal shape: WCS sets it just before calling the
			 * gateway. On hold and dunning is our own doing, and only the renewal it was set for may
			 * be retried - a later renewal reaching this state would be charging past a failure
			 * nobody has resolved.
			 */
			if ( self::DUNNING === $collection['state'] ) {
				$owner = (int) $collection['renewal_order_id'];
				if ( $owner && $renewal_order_id && $owner !== (int) $renewal_order_id ) {
					return 'dunning_other_renewal';
				}
			}

			return true;
		}

		if ( 'active' === $wcs_status ) {
			return true;
		}

		/*
		 * A brand-new subscription sits in `pending` until its parent order is paid - and paying that
		 * order is precisely the charge being asked about. Refusing here made every signup fail with
		 * an authorization the customer had just signed.
		 */
		if ( 'pending' === $wcs_status ) {
			return true;
		}

		// pending-cancel, expired, switched, or something a future WCS invents: nothing says collect.
		return 'wcs_' . $wcs_status;
	}
}
