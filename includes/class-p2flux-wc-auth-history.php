<?php
/**
 * Every authorization a subscription has ever had, not just the current one.
 *
 * A customer can re-authorize: the price changed, they revoked and came back, their allowance broke
 * in a way one approval could not fix. Each of those is a NEW on-chain subscription with its own id,
 * and the old one does not stop mattering the moment it stops being current - a renewal it paid may
 * need refunding next month, and a refund starts from the capability that collected it.
 *
 * So the current capability is a pointer into a list, never the list itself. Replacing an
 * authorization moves the pointer; it never overwrites the record that funded a past order.
 *
 * The capability ciphertext appears exactly once, here. Orders reference an authorization by id.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authorization records on a WooCommerce subscription.
 */
class P2Flux_WC_Auth_History {

	const META         = '_p2flux_authorizations';
	const ACTIVE_META  = '_p2flux_active_auth_id';
	const PENDING_META = '_p2flux_pending_setup';

	/** In use: this is what renewals charge. */
	const ACTIVE = 'active';
	/** Replaced by a newer authorization. May still be live on chain until the customer revokes it. */
	const SUPERSEDED = 'superseded';
	/** The customer revoked it on chain. Nothing can charge it again. */
	const REVOKED = 'revoked';
	/** Past its signed end date. */
	const EXPIRED = 'expired';

	/**
	 * All records, oldest first.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( $subscription ) {
		$stored = $subscription->get_meta( self::META );
		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = json_decode( $stored, true );
		}

		return ( is_array( $stored ) && isset( $stored['items'] ) && is_array( $stored['items'] ) ) ? $stored['items'] : array();
	}

	/**
	 * One record by id.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param string                   $auth_id      Subscription id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $subscription, $auth_id ) {
		foreach ( self::all( $subscription ) as $item ) {
			if ( isset( $item['id'] ) && strtolower( $item['id'] ) === strtolower( (string) $auth_id ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * The authorization renewals should charge, or null.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @return array<string,mixed>|null
	 */
	public static function active( $subscription ) {
		$id = $subscription->get_meta( self::ACTIVE_META );

		return $id ? self::get( $subscription, $id ) : null;
	}

	/**
	 * The decrypted capability for one record.
	 *
	 * Kept separate from `get()` so the plaintext only exists where it is about to be used: a record
	 * is read for its terms far more often than for its capability.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param string                   $auth_id      Subscription id.
	 * @return string|null
	 */
	public static function capability( $subscription, $auth_id ) {
		$record = self::get( $subscription, $auth_id );
		if ( ! $record || empty( $record['cap'] ) ) {
			return null;
		}

		return P2Flux_WC_Crypto::decrypt( $record['cap'] );
	}

	/**
	 * Store a new authorization and make it the active one.
	 *
	 * The whole switch is one write: the new record, the demotion of the old one and the pointer all
	 * land together, so no request can ever observe a subscription with two active authorizations or
	 * with a pointer to a record that does not exist. Correctness comes from the caller holding the
	 * subscription lock and re-reading state inside it; this just makes the persist atomic.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param array<string,mixed>      $record       id, cap (ciphertext), environment, recipient, units, period, start, end, salt.
	 * @param string|null              $replaces     Id of the authorization being replaced.
	 * @return bool
	 */
	public static function activate( $subscription, array $record, $replaces = null ) {
		if ( empty( $record['id'] ) || empty( $record['cap'] ) ) {
			return false;
		}

		$items    = self::all( $subscription );
		$id       = strtolower( (string) $record['id'] );
		$existing = false;

		$record['id']      = $id;
		$record['status']  = self::ACTIVE;
		$record['created'] = isset( $record['created'] ) ? $record['created'] : time();

		foreach ( $items as $index => $item ) {
			if ( strtolower( $item['id'] ) === $id ) {
				$items[ $index ] = array_merge( $item, $record );
				$existing        = true;
				continue;
			}
			if ( null !== $replaces && strtolower( $item['id'] ) === strtolower( (string) $replaces ) ) {
				$items[ $index ]['status']      = self::SUPERSEDED;
				$items[ $index ]['replaced_by'] = $id;
				$items[ $index ]['reason']      = isset( $record['reason'] ) ? $record['reason'] : 'reauthorized';
			}
		}

		if ( ! $existing ) {
			$items[] = $record;
		}

		$subscription->update_meta_data( self::META, wp_json_encode( array( 'v' => 1, 'items' => $items ) ) );
		$subscription->update_meta_data( self::ACTIVE_META, $id );
		$subscription->delete_meta_data( self::PENDING_META );
		$subscription->save();

		return true;
	}

	/**
	 * Mark a record dead, and clear the pointer if it was the active one.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param string                   $auth_id      Subscription id.
	 * @param string                   $status       REVOKED | EXPIRED | SUPERSEDED.
	 * @param string                   $reason       Free-text note for the admin screen.
	 * @return void
	 */
	public static function mark( $subscription, $auth_id, $status, $reason = '' ) {
		$items = self::all( $subscription );
		$id    = strtolower( (string) $auth_id );

		foreach ( $items as $index => $item ) {
			if ( strtolower( $item['id'] ) === $id ) {
				$items[ $index ]['status'] = $status;
				if ( '' !== $reason ) {
					$items[ $index ]['reason'] = $reason;
				}
			}
		}

		$subscription->update_meta_data( self::META, wp_json_encode( array( 'v' => 1, 'items' => $items ) ) );

		if ( strtolower( (string) $subscription->get_meta( self::ACTIVE_META ) ) === $id && self::SUPERSEDED !== $status ) {
			// Nothing to charge. Deliberately not "the previous one": an authorization the customer
			// killed does not promote its predecessor back into service.
			$subscription->update_meta_data( self::ACTIVE_META, '' );
		}

		$subscription->save();
	}

	/**
	 * Remember a setup the customer has not signed yet.
	 *
	 * Deliberately not part of the history: nothing is authorized until the customer signs, and a
	 * pending setup that expires unsigned should leave no trace of a subscription that never existed.
	 * It carries `replaces_auth_id` so activation can refuse to switch away from an authorization
	 * that changed underneath it while the customer was in the wallet.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @param array<string,mixed>      $setup        purpose, setup_token, salt, expires, units, period, recipient, environment, order_id, replaces_auth_id.
	 * @return void
	 */
	public static function set_pending( $subscription, array $setup ) {
		$subscription->update_meta_data( self::PENDING_META, wp_json_encode( $setup ) );
		$subscription->save();
	}

	/**
	 * The pending setup, if one is still live.
	 *
	 * @param WC_Subscription|WC_Order $subscription Subscription.
	 * @return array<string,mixed>|null
	 */
	public static function pending( $subscription ) {
		$stored = $subscription->get_meta( self::PENDING_META );
		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = json_decode( $stored, true );
		}
		if ( ! is_array( $stored ) || empty( $stored['setup_token'] ) ) {
			return null;
		}

		if ( isset( $stored['expires'] ) && (int) $stored['expires'] <= time() ) {
			return null;
		}

		return $stored;
	}
}
