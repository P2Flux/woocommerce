<?php
/**
 * What a charge result means for a WooCommerce order - and nothing else.
 *
 * Pure decision logic: it reads a ChargeResult and returns what should happen. No database, no
 * network, no WooCommerce objects, so every branch is testable without a store, which matters
 * because the branches are the product. Getting `CONFIRMING` wrong emails a customer that their
 * payment failed while their money is on chain; getting `ALREADY_CHARGED` wrong marks an order paid
 * with no transaction to refund.
 *
 * Two rules run through all of it:
 *
 *   `action` classifies, `status` explains. The SDK's action is the semantic boundary the protocol
 *   guarantees, so a code this plugin has never heard of still lands in the right branch.
 *
 *   Payment proof is a transaction, not a status. CHARGED carries one. ALREADY_CHARGED and
 *   CONFIRMING do not, and both go to reconciliation instead of to payment_complete() - a period is
 *   only attributable to an order once the settlement behind it is known.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Charge outcome to order outcome.
 */
class P2Flux_WC_Renewal {

	/** Retry ladders. Bounded, because an unbounded retry is a scheduled outage. */
	const MAX_CONFIRMING  = 30;
	const MAX_TRANSIENT   = 8;
	const MAX_DUNNING     = 3;
	const MAX_RECONCILE   = 12;

	const CONFIRMING_DELAY = 60;
	const TRANSIENT_DELAY  = 900;
	const DUNNING_DELAY    = DAY_IN_SECONDS;
	const RECONCILE_DELAY  = 300;

	/**
	 * Decide what one charge result means.
	 *
	 * @param object $result   ChargeResult from the SDK.
	 * @param array  $attempts Counters so far: confirming, transient, dunning, reconcile.
	 * @return array<string,mixed> {
	 *     @type string      $outcome      paid|reconcile|pending|failed|cancel|conflict.
	 *     @type string|null $order_status Woo status to set, or null to leave it alone.
	 *     @type string|null $collection   Collection state to move to, or null.
	 *     @type string|null $schedule     Job to schedule: recharge|reconcile, or null.
	 *     @type int         $delay        Seconds until that job.
	 *     @type string      $note         Order note. Never contains a capability.
	 *     @type bool        $notify       Whether the customer should hear about it.
	 *     @type string      $counter      Which attempt counter this outcome belongs to.
	 * }
	 */
	public static function decide( $result, array $attempts = array() ) {
		$status = isset( $result->status ) ? (string) $result->status : 'INTERNAL_ERROR';
		$action = isset( $result->action ) ? (string) $result->action : 'RETRY_LATER';
		$hash   = isset( $result->txHash ) ? (string) $result->txHash : '';

		$count = static function ( $kind ) use ( $attempts ) {
			return isset( $attempts[ $kind ] ) ? (int) $attempts[ $kind ] : 0;
		};

		if ( 'SUCCESS' === $action ) {
			/*
			 * CHARGED with a transaction is the only result that pays an order outright. Everything
			 * else that "succeeded" - a retry answered ALREADY_CHARGED, a charge that has not
			 * settled - proves the PERIOD was collected without naming the settlement, and an order
			 * paid without one cannot be attributed, audited or refunded. Those go to
			 * reconciliation, which recovers the exact transaction and pays the order then.
			 */
			if ( '' !== $hash && 'CHARGED' === $status ) {
				return self::outcome(
					array(
						'outcome' => 'paid',
						'note'    => 'P2Flux collected this period.',
					)
				);
			}

			return self::outcome(
				array(
					'outcome'  => 'reconcile',
					'schedule' => 'reconcile',
					'delay'    => 0,
					'counter'  => 'reconcile',
					'note'     => 'P2Flux reports this period as already collected. Recovering the transaction before marking the order paid.',
				)
			);
		}

		if ( 'WAIT' === $action ) {
			// On chain, not settled deep enough to act on. Never a failure, never a second charge,
			// and emphatically never a customer email.
			if ( $count( 'confirming' ) >= self::MAX_CONFIRMING ) {
				return self::outcome(
					array(
						'outcome' => 'reconcile',
						'schedule' => 'reconcile',
						'delay'    => self::TRANSIENT_DELAY,
						'counter'  => 'reconcile',
						'note'     => 'The charge is taking unusually long to settle. Still reconciling; no second charge will be sent.',
					)
				);
			}

			return self::outcome(
				array(
					'outcome'  => 'reconcile',
					'schedule' => 'reconcile',
					'delay'    => self::CONFIRMING_DELAY,
					'counter'  => 'confirming',
					'note'     => '' !== $hash
						? sprintf( 'P2Flux charge %s is confirming on chain.', $hash )
						: 'The P2Flux charge is confirming on chain.',
				)
			);
		}

		if ( 'CUSTOMER_ACTION_REQUIRED' === $action ) {
			if ( 'INSUFFICIENT_BALANCE' === $status ) {
				$tries = $count( 'dunning' );

				return self::outcome(
					array(
						'outcome'      => 'failed',
						'order_status' => 'failed',
						'collection'   => P2Flux_WC_Collection::DUNNING,
						'schedule'     => $tries < self::MAX_DUNNING ? 'recharge' : null,
						'delay'        => self::DUNNING_DELAY,
						'counter'      => 'dunning',
						'note'         => 'The wallet does not hold enough USDC for this renewal. Retrying daily while the customer tops up.',
						'notify'       => true,
					)
				);
			}

			if ( 'INSUFFICIENT_ALLOWANCE' === $status ) {
				// Retrying cannot fix this one: the customer has to approve again. Scheduling a
				// retry would only produce the same failure on a timer.
				return self::outcome(
					array(
						'outcome'      => 'failed',
						'order_status' => 'failed',
						'collection'   => P2Flux_WC_Collection::DUNNING,
						'note'         => 'The wallet’s USDC approval no longer covers this renewal. The customer must restore it before the charge can be collected.',
						'notify'       => true,
					)
				);
			}

			// Anything else the customer alone can fix - a smart-account validator too expensive to
			// run, say. The authorization has to be replaced, so no retry is scheduled.
			return self::outcome(
				array(
					'outcome'      => 'failed',
					'order_status' => 'failed',
					'collection'   => P2Flux_WC_Collection::REAUTH_REQUIRED,
					'note'         => sprintf( 'P2Flux could not collect (%s). The customer needs to authorize again.', $status ),
					'notify'       => true,
				)
			);
		}

		if ( 'STOP_SUBSCRIPTION' === $action ) {
			return self::outcome(
				array(
					'outcome'      => 'cancel',
					'order_status' => 'cancelled',
					'collection'   => P2Flux_WC_Collection::CANCELLED,
					'note'         => 'PERMISSION_REVOKED' === $status
						? 'The customer revoked this authorization on chain. No further charges are possible.'
						: 'The authorization has expired. No further charges are possible.',
					'notify'       => true,
				)
			);
		}

		if ( 'INVALID_REQUEST' === $action ) {
			/*
			 * Deterministic. The stored reference is wrong for this deployment, or the request was
			 * malformed - repeating it produces the same answer forever, so nothing is scheduled and
			 * a human is told instead.
			 */
			return self::outcome(
				array(
					'outcome'      => 'failed',
					'order_status' => 'failed',
					'collection'   => P2Flux_WC_Collection::REAUTH_REQUIRED,
					'note'         => sprintf( 'P2Flux refused this charge as invalid (%s). Retrying cannot fix it.', $status ),
					'admin'        => true,
				)
			);
		}

		// RETRY_LATER, and anything unknown, which the SDK maps here on purpose.
		if ( 'NOT_DUE' === $status ) {
			// The period this renewal belongs to has not opened yet: Woo's calendar month ran ahead
			// of the contract's fixed period. Wait for the boundary rather than hammering it.
			$next = isset( $result->nextPeriodAt ) ? strtotime( (string) $result->nextPeriodAt ) : false;
			$wait = $next ? max( 60, $next - time() + 60 ) : self::TRANSIENT_DELAY;

			return self::outcome(
				array(
					'outcome'  => 'pending',
					'schedule' => 'recharge',
					'delay'    => $wait,
					'counter'  => 'transient',
					'note'     => 'Not yet due on chain; the charge is scheduled for the start of the next billing period.',
				)
			);
		}

		if ( $count( 'transient' ) >= self::MAX_TRANSIENT ) {
			return self::outcome(
				array(
					'outcome'      => 'failed',
					'order_status' => 'failed',
					'note'         => sprintf( 'P2Flux could not be reached for this renewal (%s) after repeated attempts.', $status ),
					'admin'        => true,
				)
			);
		}

		return self::outcome(
			array(
				'outcome'  => 'pending',
				'schedule' => 'recharge',
				'delay'    => self::TRANSIENT_DELAY,
				'counter'  => 'transient',
				'note'     => sprintf( 'P2Flux could not collect right now (%s). Retrying; the customer has not been charged.', $status ),
			)
		);
	}

	/**
	 * Fill in the parts of a decision the branches do not care about.
	 *
	 * @param array<string,mixed> $decision Partial decision.
	 * @return array<string,mixed>
	 */
	private static function outcome( array $decision ) {
		return array_merge(
			array(
				'outcome'      => 'pending',
				'order_status' => null,
				'collection'   => null,
				'schedule'     => null,
				'delay'        => 0,
				'counter'      => '',
				'note'         => '',
				'notify'       => false,
				'admin'        => false,
			),
			$decision
		);
	}
}
