<?php
/**
 * Which WooCommerce order owns which billing period.
 *
 * The invariant this table exists for: one `{authorization, period}` may fund at most one Woo order,
 * for the life of the store. The chain enforces one CHARGE per period; it has no idea that
 * WooCommerce might, through a duplicated renewal or an operator's retry, end up with two orders
 * pointing at the same period - and `ALREADY_CHARGED` would happily tell the second one that its
 * period is paid. Two paid orders, one payment.
 *
 * So ownership is claimed here, under the subscription lock, BEFORE the charge goes out - a lost
 * response must not leave the period unclaimed - and the unique index makes the database itself the
 * final arbiter if two processes ever get past the lock.
 *
 * A row is not proof of payment. It records who is allowed to be paid by a settlement, never that
 * one happened: only a validated CHARGED response or an exact recovered settlement does that.
 *
 * Rows are never deleted. A period settled three years ago must still be able to refuse a second
 * claim, and to name the order a refund belongs to.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The period-ownership ledger.
 */
class P2Flux_WC_Periods {

	/** Claimed, nothing sent yet. */
	const CLAIMED = 'claimed';
	/** A charge request is in flight for this period right now. */
	const CHARGING = 'charging';
	/** Collected on chain, but the exact settlement is not yet known to us. */
	const RECONCILING = 'reconciling';
	/** Collected, settlement proven, order paid. */
	const SETTLED = 'settled';
	/** The customer paid this renewal by hand; the period stays uncollected on chain. */
	const MANUAL = 'manually_satisfied';
	/** Two orders wanted the same period, or a settlement contradicts our records. */
	const CONFLICT = 'conflict';

	const DB_VERSION_OPTION = 'p2flux_wc_db_version';
	const DB_VERSION        = 2;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'p2flux_wc_periods';
	}

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		/*
		 * The UNIQUE key is the load-bearing line. Everything else here is bookkeeping; that index
		 * is what makes "one period funds one order" true even if the application logic above it is
		 * wrong, which is the only kind of guarantee worth having about money.
		 */
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				auth_id CHAR(66) NOT NULL,
				period_index BIGINT UNSIGNED NOT NULL,
				subscription_id BIGINT UNSIGNED NOT NULL,
				order_id BIGINT UNSIGNED NOT NULL,
				state VARCHAR(20) NOT NULL,
				tx_hash CHAR(66) NULL,
				units BIGINT UNSIGNED NOT NULL DEFAULT 0,
				environment VARCHAR(10) NOT NULL DEFAULT '',
				engine VARCHAR(10) NOT NULL DEFAULT 'wcs',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				settled_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY auth_period (auth_id, period_index),
				KEY order_id (order_id),
				KEY subscription_id (subscription_id)
			) {$collate};"
		);

		// The plugin's own subscriptions, since schema version 2. Financial history; never dropped by an update.
		if ( class_exists( 'P2Flux_WC_Native_Subscription' ) ) {
			dbDelta( P2Flux_WC_Native_Subscription::schema() );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * The row for one period, or null.
	 *
	 * @param string $auth_id      Subscription id (the EIP-712 digest).
	 * @param int    $period_index Billing period.
	 * @return array<string,mixed>|null
	 */
	public static function get( $auth_id, $period_index ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE auth_id = %s AND period_index = %d",
				strtolower( $auth_id ),
				$period_index
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Claim a period for one order.
	 *
	 * Returns the row when this order owns the period afterwards - whether it just claimed it or
	 * already held it - and false when another order does. False is never "try again": it means a
	 * second Woo order is pointing at a period that is already spoken for, which is an integration
	 * inconsistency for a human to look at, not something to charge through.
	 *
	 * @param array<string,mixed> $claim auth_id, period_index, subscription_id, order_id, units, environment.
	 * @return array<string,mixed>|false
	 */
	public static function claim( array $claim ) {
		global $wpdb;

		$auth_id      = strtolower( (string) $claim['auth_id'] );
		$period_index = (int) $claim['period_index'];
		$order_id     = (int) $claim['order_id'];
		$now          = current_time( 'mysql', true );

		$table    = self::table();
		$inserted = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- the table name is $wpdb->prefix plus a constant.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (auth_id, period_index, subscription_id, order_id, state, units, environment, engine, created_at, updated_at)
				 VALUES (%s, %d, %d, %d, %s, %d, %s, %s, %s, %s)",
				$auth_id,
				$period_index,
				(int) $claim['subscription_id'],
				$order_id,
				self::CLAIMED,
				isset( $claim['units'] ) ? (int) $claim['units'] : 0,
				isset( $claim['environment'] ) ? (string) $claim['environment'] : '',
				isset( $claim['engine'] ) ? (string) $claim['engine'] : 'wcs',
				$now,
				$now
			)
		);

		$row = self::get( $auth_id, $period_index );
		if ( ! $row ) {
			return false;
		}

		if ( (int) $row['order_id'] !== $order_id ) {
			return false;
		}

		unset( $inserted );

		return $row;
	}

	/**
	 * Move a period to a new state.
	 *
	 * @param string              $auth_id      Subscription id.
	 * @param int                 $period_index Billing period.
	 * @param string              $state        One of the class constants.
	 * @param array<string,mixed> $extra        Optional tx_hash / order_id / units.
	 * @return void
	 */
	public static function set_state( $auth_id, $period_index, $state, array $extra = array() ) {
		global $wpdb;

		$data = array(
			'state'      => $state,
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( isset( $extra['tx_hash'] ) ) {
			$data['tx_hash'] = $extra['tx_hash'];
		}
		if ( isset( $extra['units'] ) ) {
			$data['units'] = (int) $extra['units'];
		}
		if ( isset( $extra['order_id'] ) ) {
			$data['order_id'] = (int) $extra['order_id'];
		}
		if ( self::SETTLED === $state ) {
			$data['settled_at'] = current_time( 'mysql', true );
		}

		$wpdb->update(
			self::table(),
			$data,
			array(
				'auth_id'      => strtolower( $auth_id ),
				'period_index' => (int) $period_index,
			)
		);
	}

	/**
	 * Periods still marked CHARGING since before a moment: requests whose answer never came back.
	 *
	 * @param int $before Unix seconds.
	 * @return array<int,array<string,mixed>>
	 */
	public static function stale_charging( $before ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE state = %s AND updated_at < %s ORDER BY id ASC LIMIT 100", self::CHARGING, gmdate( 'Y-m-d H:i:s', (int) $before ) ),
			ARRAY_A
		);
	}

	/**
	 * May this order be paid by a settlement of this period?
	 *
	 * @param string $auth_id      Subscription id.
	 * @param int    $period_index Billing period.
	 * @param int    $order_id     Woo order.
	 * @return bool
	 */
	public static function owned_by( $auth_id, $period_index, $order_id ) {
		$row = self::get( $auth_id, $period_index );

		return $row && (int) $row['order_id'] === (int) $order_id;
	}

	/**
	 * Every period an order owns, oldest first.
	 *
	 * @param int $order_id Woo order.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_order( $order_id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", (int) $order_id ),
			ARRAY_A
		);
	}
}
