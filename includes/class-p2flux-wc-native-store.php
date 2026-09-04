<?php
/**
 * The native subscription table, and nothing else: rows in, rows out, one compare-and-set.
 *
 * Kept apart from the record so the record's behaviour can be exercised without a database. Every
 * query is parameterized; the only non-trivial one is the update, which names the version it read
 * and changes nothing if that version is gone.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Row storage.
 */
class P2Flux_WC_Native_Store {

	/**
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public static function row( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', P2Flux_WC_Native_Subscription::table(), (int) $id ), ARRAY_A ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a ledger read live, never from cache.
	}

	/**
	 * @param array<string,mixed> $row Columns.
	 * @return int New id, or 0.
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$ok = $wpdb->insert( P2Flux_WC_Native_Subscription::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a ledger read live, never from cache.

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Write columns if the row still has the version that was read.
	 *
	 * @param int                 $id      Id.
	 * @param int                 $version Version read.
	 * @param array<string,mixed> $columns Columns to set (null = SQL NULL).
	 * @return bool One row changed.
	 */
	public static function update_cas( $id, $version, array $columns ) {
		global $wpdb;

		$table  = P2Flux_WC_Native_Subscription::table();
		$sets   = array( 'meta_version = meta_version + 1' );
		$values = array();
		foreach ( $columns as $column => $value ) {
			if ( ! preg_match( '/^[a-z_]+$/', $column ) ) {
				continue;
			}
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
				continue;
			}
			$sets[]   = "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
			$values[] = $value;
		}
		$values[] = (int) $id;
		$values[] = (int) $version;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sets holds whitelisted column names with placeholders and the table is $wpdb->prefix plus a constant; every value goes through prepare().
		$rows = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE id = %d AND meta_version = %d', array_merge( array( $table ), $values ) ) );

		return 1 === (int) $rows;
	}

	/**
	 * @param int $user_id User.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_for_user( $user_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC', P2Flux_WC_Native_Subscription::table(), (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a ledger read live, never from cache.

		return $rows ? $rows : array();
	}

	/**
	 * @param int $limit  Limit.
	 * @param int $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_all( $limit, $offset ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', P2Flux_WC_Native_Subscription::table(), (int) $limit, (int) $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a ledger read live, never from cache.

		return $rows ? $rows : array();
	}

	/**
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', P2Flux_WC_Native_Subscription::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a ledger read live, never from cache.
	}

	/**
	 * @param int $before Unix seconds.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_due_before( $before ) {
		global $wpdb;

		$at   = gmdate( 'Y-m-d H:i:s', (int) $before );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a ledger read live, never from cache.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( status IN ('active','on-hold') AND next_payment_at IS NOT NULL AND next_payment_at <= %s )
				 OR ( status = 'pending' AND activation_deadline IS NOT NULL AND activation_deadline <= %s )
				 OR ( status = 'pending' AND activation_deadline IS NULL AND created_at <= %s ) ORDER BY id ASC LIMIT 200",
				P2Flux_WC_Native_Subscription::table(),
				$at,
				$at,
				gmdate( 'Y-m-d H:i:s', (int) $before - P2Flux_WC_Native_Subscription::ACTIVATION_TTL )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}
}
