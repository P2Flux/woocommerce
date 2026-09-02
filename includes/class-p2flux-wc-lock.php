<?php
/**
 * One financial operation per subscription at a time, across PHP processes.
 *
 * The races this exists for are ordinary, not exotic: Action Scheduler runs two workers, a customer
 * clicks "retry" while a scheduled retry is executing, an allowance-restored callback lands mid
 * renewal. WooCommerce and Action Scheduler expose no general-purpose named lock, MySQL's GET_LOCK
 * is unavailable on several hosted stacks, and a static PHP variable is invisible to the other
 * process - so this is a compare-and-set on a row in the options table, which every WordPress
 * install has and every process can see.
 *
 * What it is not: the last line of defence. The contract allows one charge per billing period no
 * matter how many processes ask, so a lock failure costs a duplicate REQUEST, never a duplicate
 * payment. This keeps WooCommerce's own state consistent - which is the part the chain cannot.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * A leased, self-expiring lock keyed by subscription.
 */
class P2Flux_WC_Lock {

	/**
	 * How long a lease lasts.
	 *
	 * Longer than the whole critical section - a charge is one HTTP request the SDK caps at 25
	 * seconds, plus WooCommerce writes - and short enough that a process killed mid-charge does not
	 * wedge a subscription until someone notices. Nothing renews a lease: a worker that overruns it
	 * finds out at the end, when it checks whether it still owns it, and declines to write
	 * lifecycle state it can no longer vouch for.
	 */
	const TTL = 120;

	/** The lock this process currently holds, per subscription, so release() knows its own token. */
	private static $held = array();

	/**
	 * Take the lock for one subscription, or fail immediately.
	 *
	 * Never blocks. A caller that cannot have it reschedules instead - holding a PHP worker on a
	 * spin is how a busy store turns one slow charge into an outage.
	 *
	 * @param int $subscription_id WooCommerce subscription id.
	 * @return string|false Owner token, or false when someone else holds it.
	 */
	public static function acquire( $subscription_id ) {
		global $wpdb;

		$name  = self::option_name( $subscription_id );
		$token = wp_generate_password( 20, false, false );
		$value = ( time() + self::TTL ) . '|' . $token;

		/*
		 * add_option() is an INSERT against a UNIQUE index, so exactly one concurrent caller can
		 * win. It is also the reason this is not a transient: transients can be object-cached, and
		 * a cache is precisely the layer that would tell two processes they both got the lock.
		 */
		if ( add_option( $name, $value, '', 'no' ) ) {
			self::$held[ $subscription_id ] = $token;
			return $token;
		}

		// Straight to the table: an object cache may still be serving the value we are racing.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( ! is_string( $current ) ) {
			return false;
		}

		$expires = (int) strtok( $current, '|' );
		if ( $expires > time() ) {
			return false;
		}

		/*
		 * The holder died. Take over only by replacing the EXACT value just read: if another process
		 * is doing the same thing a microsecond earlier, its write changes the value and this
		 * UPDATE matches nothing. One survivor, without a transaction.
		 */
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$name,
				$current
			)
		);

		if ( 1 !== (int) $rows ) {
			return false;
		}

		wp_cache_delete( $name, 'options' );
		self::$held[ $subscription_id ] = $token;

		return $token;
	}

	/**
	 * Do we still hold this lease?
	 *
	 * Asked again after the network call and before any lifecycle write. A worker that stalled past
	 * the TTL may find another process has taken over and already cancelled the subscription; its
	 * own view of the world is then stale, and writing it back would undo someone else's decision.
	 *
	 * @param int    $subscription_id WooCommerce subscription id.
	 * @param string $token           Token from acquire().
	 * @return bool
	 */
	public static function still_ours( $subscription_id, $token ) {
		global $wpdb;

		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $subscription_id ) )
		);
		if ( ! is_string( $current ) ) {
			return false;
		}

		$parts = explode( '|', $current, 2 );

		return isset( $parts[1] ) && hash_equals( $parts[1], (string) $token ) && (int) $parts[0] > time();
	}

	/**
	 * Release a lease, but only our own.
	 *
	 * @param int    $subscription_id WooCommerce subscription id.
	 * @param string $token           Token from acquire().
	 * @return void
	 */
	public static function release( $subscription_id, $token ) {
		global $wpdb;

		$name = self::option_name( $subscription_id );

		// Conditional on the token: a lease that already expired and was taken over belongs to
		// someone else now, and deleting it would hand a third process a lock two others think they
		// hold.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				$name,
				'%|' . $wpdb->esc_like( (string) $token )
			)
		);

		wp_cache_delete( $name, 'options' );
		unset( self::$held[ $subscription_id ] );
	}

	/**
	 * Run a callback while holding the lock, releasing it whatever happens.
	 *
	 * @param int      $subscription_id WooCommerce subscription id.
	 * @param callable $work            Receives the owner token.
	 * @return mixed|false The callback's return value, or false when the lock was not free.
	 */
	public static function with( $subscription_id, $work ) {
		$token = self::acquire( $subscription_id );
		if ( false === $token ) {
			return false;
		}

		try {
			return call_user_func( $work, $token );
		} finally {
			self::release( $subscription_id, $token );
		}
	}

	/**
	 * Option name for a subscription's lock.
	 *
	 * @param int $subscription_id WooCommerce subscription id.
	 * @return string
	 */
	private static function option_name( $subscription_id ) {
		return 'p2flux_wc_lock_' . (int) $subscription_id;
	}
}
