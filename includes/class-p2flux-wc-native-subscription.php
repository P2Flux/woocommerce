<?php
/**
 * A native subscription: the plugin's own recurring record, needing no other plugin.
 *
 * It is not a WooCommerce order and does not pretend to be one. It is a row in its own table with the
 * handful of methods the shared payment classes use on a subscription - status, meta, the billing
 * period, the customer, the related orders - so the charger, the authorization history, the collection
 * state, recovery, refunds and the account page all work on it unchanged.
 *
 * What the row is not: a place for a plaintext capability. The authorization history stored in `meta`
 * holds ciphertext, exactly as it does on a WooCommerce Subscriptions object.
 *
 * Writes are whole-row and compare-and-set: the row carries a version, the UPDATE names the version it
 * read, and a write that lost a race re-reads and re-applies. Every financial mutation also runs under
 * the per-subscription lock; the version protects the few that do not.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Native subscription record.
 */
class P2Flux_WC_Native_Subscription {

	/** Signup is inside its activation window, or reconciling a charge sent inside it. */
	const PENDING = 'pending';
	/** Initial settlement proven; the schedule is running. */
	const ACTIVE = 'active';
	/** Previously active; a renewal could not be collected. */
	const ON_HOLD = 'on-hold';
	/** Explicitly cancelled by the customer or the merchant. */
	const CANCELLED = 'cancelled';
	/** Never activated: the signup window closed without a proven first payment. */
	const EXPIRED = 'expired';

	/**
	 * How long a signup has to complete its first payment, in production.
	 *
	 * Bounded twice: by this, and by the end of the on-chain period the authorization was created in.
	 * A forgotten yearly signup must never collect months later because the wallet was finally funded.
	 */
	const ACTIVATION_TTL = DAY_IN_SECONDS;

	/** Meta keys that are mirrored into columns for listing. */
	const COLUMN_META = array(
		'_p2flux_env'            => 'env',
		'_p2flux_recipient'      => 'recipient',
		'_p2flux_units'          => 'amount_units',
		'_p2flux_active_auth_id' => 'active_auth_id',
	);

	/** @var array<string,mixed> The row. */
	private $row;

	/** @var array<string,mixed> Decoded meta. */
	private $meta;

	/** @var array<string,mixed> Column changes not yet written. */
	private $changes = array();

	/** @var bool Meta changed since load. */
	private $meta_dirty = false;

	/**
	 * Build from a row.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	private function __construct( array $row ) {
		$this->row  = $row;
		$decoded    = isset( $row['meta'] ) && is_string( $row['meta'] ) && '' !== $row['meta'] ? json_decode( $row['meta'], true ) : array();
		$this->meta = is_array( $decoded ) ? $decoded : array();
	}

	/*
	 * ---- Storage ----
	 */

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'p2flux_wc_subscriptions';
	}

	/**
	 * The schema, for dbDelta.
	 *
	 * @return string
	 */
	public static function schema() {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			parent_order_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL,
			collection_state VARCHAR(20) NOT NULL DEFAULT 'normal',
			currency CHAR(3) NOT NULL DEFAULT 'USD',
			amount_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
			amount_display VARCHAR(32) NOT NULL DEFAULT '',
			product_name VARCHAR(255) NOT NULL DEFAULT '',
			interval_type VARCHAR(10) NOT NULL,
			schedule_anchor DATETIME NULL,
			cycle INT UNSIGNED NOT NULL DEFAULT 0,
			next_payment_at DATETIME NULL,
			current_renewal_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			env VARCHAR(10) NOT NULL DEFAULT '',
			recipient CHAR(42) NOT NULL DEFAULT '',
			active_auth_id CHAR(66) NULL,
			activation_period BIGINT UNSIGNED NULL,
			activation_deadline DATETIME NULL,
			missed_cycles INT UNSIGNED NOT NULL DEFAULT 0,
			meta LONGTEXT NULL,
			meta_version INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			cancelled_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status_next (status, next_payment_at),
			KEY parent_order_id (parent_order_id)
		) {$collate};";
	}

	/**
	 * Load one.
	 *
	 * @param int $id Id.
	 * @return self|null
	 */
	public static function load( $id ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? new self( $row ) : null;
	}

	/**
	 * Create one, pending, for a signup.
	 *
	 * @param array<string,mixed> $fields user_id, product_id, parent_order_id, amount_units, amount_display, product_name, interval_type, env, recipient, currency.
	 * @return self|null
	 */
	public static function create( array $fields ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'user_id'         => (int) $fields['user_id'],
				'product_id'      => (int) $fields['product_id'],
				'parent_order_id' => (int) $fields['parent_order_id'],
				'status'          => self::PENDING,
				'currency'        => isset( $fields['currency'] ) ? substr( (string) $fields['currency'], 0, 3 ) : 'USD',
				'amount_units'    => (int) $fields['amount_units'],
				'amount_display'  => (string) $fields['amount_display'],
				'product_name'    => isset( $fields['product_name'] ) ? substr( (string) $fields['product_name'], 0, 255 ) : '',
				'interval_type'   => (string) $fields['interval_type'],
				'env'             => (string) $fields['env'],
				'recipient'       => strtolower( (string) $fields['recipient'] ),
				'meta'            => wp_json_encode( array() ),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return null;
		}

		return self::load( (int) $wpdb->insert_id );
	}

	/**
	 * Every subscription of a customer, newest first.
	 *
	 * @param int $user_id User.
	 * @return array<int,self>
	 */
	public static function for_user( $user_id ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC", (int) $user_id ), ARRAY_A );

		return array_map( static function ( $row ) { return new self( $row ); }, $rows ? $rows : array() );
	}

	/**
	 * Rows a sweep should look at: schedules that are due but have no pending job, and signups
	 * whose window is over.
	 *
	 * @param int $before Unix seconds: due at or before this.
	 * @return array<int,self>
	 */
	public static function due_before( $before ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ( status IN ('active','on-hold') AND next_payment_at IS NOT NULL AND next_payment_at <= %s )
				 OR ( status = 'pending' AND activation_deadline IS NOT NULL AND activation_deadline <= %s ) ORDER BY id ASC LIMIT 200",
				gmdate( 'Y-m-d H:i:s', (int) $before ),
				gmdate( 'Y-m-d H:i:s', (int) $before )
			),
			ARRAY_A
		);

		return array_map( static function ( $row ) { return new self( $row ); }, $rows ? $rows : array() );
	}

	/**
	 * Write the row: changed columns, the whole meta document, one compare-and-set.
	 *
	 * @return bool Written.
	 */
	public function save() {
		global $wpdb;

		if ( empty( $this->changes ) && ! $this->meta_dirty ) {
			return true;
		}

		foreach ( self::COLUMN_META as $key => $column ) {
			if ( array_key_exists( $key, $this->meta ) ) {
				$this->changes[ $column ] = 'amount_units' === $column ? (int) $this->meta[ $key ] : (string) $this->meta[ $key ];
			}
		}
		$collection = isset( $this->meta['_p2flux_collection'] ) ? json_decode( (string) $this->meta['_p2flux_collection'], true ) : null;
		if ( is_array( $collection ) && isset( $collection['state'] ) ) {
			$this->changes['collection_state'] = (string) $collection['state'];
		}

		$table  = self::table();
		$sets   = array( 'meta = %s', 'meta_version = meta_version + 1', 'updated_at = %s' );
		$values = array( wp_json_encode( $this->meta ), current_time( 'mysql', true ) );
		foreach ( $this->changes as $column => $value ) {
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
				continue;
			}
			$sets[]   = "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
			$values[] = $value;
		}
		$values[] = (int) $this->row['id'];
		$values[] = (int) $this->row['meta_version'];

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND meta_version = %d', $values ) );
			if ( 1 === (int) $rows ) {
				$fresh = self::load( (int) $this->row['id'] );
				if ( $fresh ) {
					$this->row  = $fresh->row;
					$this->meta = $fresh->meta;
				}
				$this->changes    = array();
				$this->meta_dirty = false;

				return true;
			}

			/*
			 * Somebody wrote in between. Re-read, re-apply this object's changes on top of what is
			 * there now, and try again: column changes win by key, meta keys this object touched win
			 * by key, everything else is theirs.
			 */
			$fresh = self::load( (int) $this->row['id'] );
			if ( ! $fresh ) {
				return false;
			}
			$mine                = $this->meta;
			$this->row           = $fresh->row;
			$this->meta          = array_merge( $fresh->meta, array_intersect_key( $mine, $this->touched ) );
			foreach ( $this->deleted as $key => $_ ) {
				unset( $this->meta[ $key ] );
			}
			$values[ count( $values ) - 1 ] = (int) $this->row['meta_version'];
			$values[0]                      = wp_json_encode( $this->meta );
		}

		P2Flux_WC_Logger::error( 'native subscription write lost three races', array( 'subscription' => (int) $this->row['id'] ) );

		return false;
	}

	/** @var array<string,bool> Meta keys this object set since load. */
	private $touched = array();

	/** @var array<string,bool> Meta keys this object deleted since load. */
	private $deleted = array();

	/*
	 * ---- The surface the shared classes use ----
	 */

	/** @return int */
	public function get_id() {
		return (int) $this->row['id'];
	}

	/** @return int */
	public function get_parent_id() {
		return (int) $this->row['parent_order_id'];
	}

	/** @return int */
	public function get_user_id() {
		return (int) $this->row['user_id'];
	}

	/** @return string */
	public function get_status() {
		return (string) $this->row['status'];
	}

	/**
	 * @param string|array $status Status or statuses.
	 * @return bool
	 */
	public function has_status( $status ) {
		return in_array( $this->get_status(), (array) $status, true );
	}

	/** @return string */
	public function get_payment_method() {
		return 'p2flux';
	}

	/** Amount as a decimal string, the way an order reports its total. */
	public function get_total() {
		return (string) $this->row['amount_display'];
	}

	/** @return string 'day' | 'week' | 'month' | 'year' */
	public function get_billing_period() {
		return (string) $this->row['interval_type'];
	}

	/** @return int */
	public function get_billing_interval() {
		return 1;
	}

	/**
	 * A column.
	 *
	 * @param string $column Column.
	 * @return mixed
	 */
	public function get( $column ) {
		return array_key_exists( $column, $this->row ) ? $this->row[ $column ] : null;
	}

	/**
	 * Set a column (written on save).
	 *
	 * @param string $column Column.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function set( $column, $value ) {
		$this->row[ $column ]     = $value;
		$this->changes[ $column ] = $value;
	}

	/**
	 * Meta, WC_Data style.
	 *
	 * @param string $key Key.
	 * @return mixed '' when absent, like WC_Data.
	 */
	public function get_meta( $key ) {
		if ( P2Flux_WC_Subscriptions::NATIVE_META === $key ) {
			return $this->get_id();
		}

		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ]    = $value;
		$this->touched[ $key ] = true;
		unset( $this->deleted[ $key ] );
		$this->meta_dirty = true;
	}

	/**
	 * @param string $key Key.
	 * @return void
	 */
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ], $this->touched[ $key ] );
		$this->deleted[ $key ] = true;
		$this->meta_dirty      = true;
	}

	/**
	 * A note: on the parent order, where a merchant reads history, and in the log.
	 *
	 * @param string $note Note.
	 * @return void
	 */
	public function add_order_note( $note ) {
		P2Flux_WC_Logger::log( 'native subscription note', array( 'subscription' => $this->get_id(), 'note' => $note ) );
		$parent = function_exists( 'wc_get_order' ) ? wc_get_order( $this->get_parent_id() ) : null;
		if ( $parent ) {
			$parent->add_order_note( sprintf( '[P2Flux subscription #%d] %s', $this->get_id(), $note ) );
		}
	}

	/**
	 * Which transitions are allowed. Small on purpose: no suspension, no reactivation of a cancelled or
	 * expired subscription, ever.
	 *
	 * @param string $status Target.
	 * @return bool
	 */
	public function can_be_updated_to( $status ) {
		$from = $this->get_status();
		if ( $from === $status ) {
			return false;
		}
		$allowed = array(
			self::PENDING   => array( self::ACTIVE, self::EXPIRED ),
			self::ACTIVE    => array( self::ON_HOLD, self::CANCELLED ),
			self::ON_HOLD   => array( self::ACTIVE, self::CANCELLED ),
			self::CANCELLED => array(),
			self::EXPIRED   => array(),
		);

		return isset( $allowed[ $from ] ) && in_array( $status, $allowed[ $from ], true );
	}

	/**
	 * Change status, with a note, and save.
	 *
	 * @param string $status Target.
	 * @param string $note   Why.
	 * @return bool
	 */
	public function update_status( $status, $note = '' ) {
		if ( ! $this->can_be_updated_to( $status ) ) {
			return false;
		}

		$this->set( 'status', $status );
		if ( self::CANCELLED === $status ) {
			$this->set( 'cancelled_at', current_time( 'mysql', true ) );
		}
		if ( '' !== $note ) {
			$this->add_order_note( $note );
		}

		return $this->save();
	}

	/**
	 * The parent order and every renewal order, ids.
	 *
	 * @param string $type Ignored; ids always.
	 * @return array<int,int>
	 */
	public function get_related_orders( $type = 'ids' ) {
		unset( $type );

		$ids = array( $this->get_parent_id() );
		if ( function_exists( 'wc_get_orders' ) ) {
			$found = wc_get_orders(
				array(
					'limit'      => 500,
					'return'     => 'ids',
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => P2Flux_WC_Subscriptions::NATIVE_META,
							'value' => (string) $this->get_id(),
						),
					),
				)
			);
			foreach ( (array) $found as $id ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/*
	 * ---- What the plugin's own engine adds. Delegated to the scheduler, which owns the rules. ----
	 */

	/**
	 * @param WC_Order $order    Order.
	 * @param int      $expected Period the clock names.
	 * @return true|array{code:string,retry_at:int}
	 */
	public function charge_gate( $order, $expected ) {
		return P2Flux_WC_Native_Scheduler::charge_gate( $this, $order, $expected );
	}

	/**
	 * @param WC_Order $order    Order.
	 * @param array    $decision Decision.
	 * @return int|null
	 */
	public function retry_delay( $order, array $decision ) {
		return P2Flux_WC_Native_Scheduler::retry_delay( $this, $order, $decision );
	}

	/**
	 * @param WC_Order $order Paid order.
	 * @return void
	 */
	public function after_paid( $order ) {
		P2Flux_WC_Native_Scheduler::after_paid( $this, $order );
	}

	/**
	 * @param WC_Order $order Order whose period passed.
	 * @return void
	 */
	public function after_missed( $order ) {
		P2Flux_WC_Native_Scheduler::after_missed( $this, $order );
	}

	/**
	 * @param array $status The status response the activation validated.
	 * @return void
	 */
	public function after_activated( array $status ) {
		P2Flux_WC_Native_Scheduler::after_activated( $this, $status );
	}

	/** @return void */
	public function unschedule() {
		P2Flux_WC_Native_Scheduler::unschedule( $this );
	}

	/*
	 * ---- Small helpers the scheduler and screens share ----
	 */

	/**
	 * A DATETIME column as unix seconds, or 0.
	 *
	 * @param string $column Column.
	 * @return int
	 */
	public function timestamp( $column ) {
		$value = $this->get( $column );
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}
		$ts = strtotime( $value . ' UTC' );

		return false === $ts ? 0 : (int) $ts;
	}

	/**
	 * Set a DATETIME column from unix seconds (0 = NULL).
	 *
	 * @param string $column Column.
	 * @param int    $ts     Unix seconds.
	 * @return void
	 */
	public function set_timestamp( $column, $ts ) {
		$this->set( $column, $ts > 0 ? gmdate( 'Y-m-d H:i:s', (int) $ts ) : null );
	}

	/**
	 * Interval label for people.
	 *
	 * @return string
	 */
	public function interval_label() {
		$labels = array(
			'day'   => __( 'day', 'p2flux-for-woocommerce' ),
			'week'  => __( 'week', 'p2flux-for-woocommerce' ),
			'month' => __( 'month', 'p2flux-for-woocommerce' ),
			'year'  => __( 'year', 'p2flux-for-woocommerce' ),
		);
		$key = $this->get_billing_period();

		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Status label for people.
	 *
	 * @return string
	 */
	public function status_label() {
		$labels = array(
			self::PENDING   => __( 'Pending activation', 'p2flux-for-woocommerce' ),
			self::ACTIVE    => __( 'Active', 'p2flux-for-woocommerce' ),
			self::ON_HOLD   => __( 'On hold', 'p2flux-for-woocommerce' ),
			self::CANCELLED => __( 'Cancelled', 'p2flux-for-woocommerce' ),
			self::EXPIRED   => __( 'Expired — never activated', 'p2flux-for-woocommerce' ),
		);

		return isset( $labels[ $this->get_status() ] ) ? $labels[ $this->get_status() ] : $this->get_status();
	}
}
