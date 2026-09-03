<?php
/**
 * WooCommerce → P2Flux Subscriptions: what a merchant can see and do about native subscriptions.
 *
 * A list and a detail view, and two actions: cancel, and retry the current payment - which obeys
 * exactly the same period and window rules as every other charge, so a merchant cannot collect
 * off-cycle. Nothing here shows a capability; the row's meta is never printed.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
class P2Flux_WC_Native_Admin {

	const PAGE = 'p2flux-subscriptions';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_p2flux_native_admin', array( __CLASS__, 'action' ) );
	}

	/**
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'P2Flux Subscriptions', 'p2flux-for-woocommerce' ),
			__( 'P2Flux Subscriptions', 'p2flux-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @param int $id Subscription id, 0 for the list.
	 * @return string
	 */
	public static function url( $id = 0 ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );

		return $id ? add_query_arg( 'id', (int) $id, $url ) : $url;
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view.
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$notice = isset( $_GET['p2flux_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['p2flux_notice'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		echo '<div class="wrap"><h1>' . esc_html__( 'P2Flux Subscriptions', 'p2flux-for-woocommerce' ) . '</h1>';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		if ( $id ) {
			$subscription = P2Flux_WC_Native_Subscription::load( $id );
			if ( $subscription ) {
				self::detail( $subscription );
			} else {
				echo '<p>' . esc_html__( 'Not found.', 'p2flux-for-woocommerce' ) . '</p>';
			}
		} else {
			self::listing( $paged );
		}
		echo '</div>';
	}

	/**
	 * @param int $paged Page.
	 * @return void
	 */
	private static function listing( $paged ) {
		$per   = 30;
		$total = P2Flux_WC_Native_Store::count_all();
		$rows  = P2Flux_WC_Native_Subscription::all( $per, ( $paged - 1 ) * $per );

		echo '<p>' . esc_html__( 'Subscriptions sold through P2Flux Native Subscriptions. Each keeps the amount, wallet and network it was created with; changing the product later affects new customers only.', 'p2flux-for-woocommerce' ) . '</p>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( array( 'ID', __( 'Customer', 'p2flux-for-woocommerce' ), __( 'Product', 'p2flux-for-woocommerce' ), __( 'Amount', 'p2flux-for-woocommerce' ), __( 'Status', 'p2flux-for-woocommerce' ), __( 'Next payment', 'p2flux-for-woocommerce' ), __( 'Network', 'p2flux-for-woocommerce' ), __( 'Created', 'p2flux-for-woocommerce' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'None yet.', 'p2flux-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( $rows as $subscription ) {
			$user = get_user_by( 'id', $subscription->get_user_id() );
			echo '<tr>';
			echo '<td><a href="' . esc_url( self::url( $subscription->get_id() ) ) . '">#' . (int) $subscription->get_id() . '</a></td>';
			echo '<td>' . esc_html( $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $subscription->get_user_id() ) . '</td>';
			echo '<td>' . esc_html( (string) $subscription->get( 'product_name' ) ) . '</td>';
			echo '<td>' . esc_html( P2Flux_WC_Native_Account::amount( $subscription ) ) . '</td>';
			echo '<td>' . esc_html( $subscription->status_label() ) . '</td>';
			echo '<td>' . esc_html( P2Flux_WC_Native_Account::next_payment( $subscription ) ) . '</td>';
			echo '<td>' . esc_html( 'mainnet' === $subscription->get( 'env' ) ? __( 'Base Mainnet', 'p2flux-for-woocommerce' ) : __( 'Base Sepolia (test)', 'p2flux-for-woocommerce' ) ) . '</td>';
			echo '<td>' . esc_html( P2Flux_WC_Native_Account::date( $subscription->timestamp( 'created_at' ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $p = 1; $p <= $pages; $p++ ) {
				echo $p === $paged ? '<strong>' . (int) $p . '</strong> ' : '<a href="' . esc_url( add_query_arg( 'paged', $p, self::url() ) ) . '">' . (int) $p . '</a> ';
			}
			echo '</p>';
		}
	}

	/**
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return void
	 */
	private static function detail( $subscription ) {
		$user   = get_user_by( 'id', $subscription->get_user_id() );
		$active = P2Flux_WC_Auth_History::active( $subscription );
		$state  = P2Flux_WC_Collection::get( $subscription );

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All subscriptions', 'p2flux-for-woocommerce' ) . '</a></p>';
		/* translators: %d: subscription id. */
		echo '<h2>' . esc_html( sprintf( __( 'Subscription #%d', 'p2flux-for-woocommerce' ), $subscription->get_id() ) ) . '</h2>';

		$rows = array(
			__( 'Customer', 'p2flux-for-woocommerce' )         => $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $subscription->get_user_id(),
			__( 'Product', 'p2flux-for-woocommerce' )          => (string) $subscription->get( 'product_name' ) . ' (#' . (int) $subscription->get( 'product_id' ) . ')',
			__( 'Amount', 'p2flux-for-woocommerce' )           => P2Flux_WC_Native_Account::amount( $subscription ),
			__( 'Status', 'p2flux-for-woocommerce' )           => $subscription->status_label() . ' · ' . (string) $state['state'] . ( '' !== (string) $state['reason'] ? ' (' . $state['reason'] . ')' : '' ),
			__( 'Network', 'p2flux-for-woocommerce' )          => 'mainnet' === $subscription->get( 'env' ) ? __( 'Base Mainnet', 'p2flux-for-woocommerce' ) : __( 'Base Sepolia (test)', 'p2flux-for-woocommerce' ),
			__( 'Payout wallet', 'p2flux-for-woocommerce' )    => (string) $subscription->get( 'recipient' ),
			__( 'Started', 'p2flux-for-woocommerce' )          => P2Flux_WC_Native_Account::date( $subscription->timestamp( 'schedule_anchor' ) ),
			__( 'Next payment', 'p2flux-for-woocommerce' )     => P2Flux_WC_Native_Account::next_payment( $subscription ),
			__( 'Cycles resolved', 'p2flux-for-woocommerce' )  => (string) (int) $subscription->get( 'cycle' ),
			__( 'Missed cycles', 'p2flux-for-woocommerce' )    => (string) (int) $subscription->get( 'missed_cycles' ),
			/* translators: %1$d: on-chain period index, %2$s: date and time. */
			__( 'Activation window', 'p2flux-for-woocommerce' ) => $subscription->timestamp( 'activation_deadline' ) ? sprintf( __( 'period %1$d, until %2$s', 'p2flux-for-woocommerce' ), (int) $subscription->get( 'activation_period' ), P2Flux_WC_Native_Account::date( $subscription->timestamp( 'activation_deadline' ) ) ) : '—',
			__( 'Authorization', 'p2flux-for-woocommerce' )    => $active ? $active['id'] : __( 'none active', 'p2flux-for-woocommerce' ),
			__( 'Cancelled', 'p2flux-for-woocommerce' )        => P2Flux_WC_Native_Account::date( $subscription->timestamp( 'cancelled_at' ) ),
			__( 'Revoke transaction', 'p2flux-for-woocommerce' ) => (string) $subscription->get_meta( '_p2flux_revoked_tx' ) ?: '—',
		);
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		// Authorization history: ids and statuses, never the ciphertext.
		echo '<h3>' . esc_html__( 'Authorization history', 'p2flux-for-woocommerce' ) . '</h3><ul>';
		foreach ( P2Flux_WC_Auth_History::all( $subscription ) as $record ) {
			echo '<li><code>' . esc_html( $record['id'] ) . '</code> — ' . esc_html( $record['status'] . ( ! empty( $record['reason'] ) ? ' (' . $record['reason'] . ')' : '' ) ) . ' · ' . esc_html( P2Flux_WC_Money::display( (int) $record['units'] ) ) . ' USDC / ' . (int) $record['period'] . 's</li>';
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Orders', 'p2flux-for-woocommerce' ) . '</h3><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Order', 'p2flux-for-woocommerce' ) . '</th><th>' . esc_html__( 'Cycle', 'p2flux-for-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'p2flux-for-woocommerce' ) . '</th><th>' . esc_html__( 'Period', 'p2flux-for-woocommerce' ) . '</th><th>' . esc_html__( 'Transaction', 'p2flux-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $subscription->get_related_orders( 'ids' ) ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$edit = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id ) : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			$hash = (string) $order->get_meta( '_p2flux_tx_hash' );
			echo '<tr><td><a href="' . esc_url( $edit ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( (string) $order->get_meta( P2Flux_WC_Native_Scheduler::CYCLE_META ) ) . '</td>';
			echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ( $order->get_meta( '_p2flux_reconciling' ) ? ' · ' . esc_html__( 'reconciling', 'p2flux-for-woocommerce' ) : '' ) . '</td>';
			echo '<td>' . esc_html( (string) $order->get_meta( '_p2flux_period_index' ) ) . '</td>';
			echo '<td>' . ( '' !== $hash ? '<a href="' . esc_url( P2Flux_WC_Client::explorer_url( (string) $subscription->get( 'env' ) ) . '/tx/' . $hash ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( substr( $hash, 0, 14 ) . '…' ) . '</code></a>' : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Actions, by status.
		$retryable = self::retryable_order( $subscription );
		$can_cancel = $subscription->has_status( array( P2Flux_WC_Native_Subscription::ACTIVE, P2Flux_WC_Native_Subscription::ON_HOLD, P2Flux_WC_Native_Subscription::PENDING ) );
		if ( $retryable || $can_cancel ) {
			echo '<h3>' . esc_html__( 'Actions', 'p2flux-for-woocommerce' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'p2flux_native_admin_' . $subscription->get_id() );
			echo '<input type="hidden" name="action" value="p2flux_native_admin" /><input type="hidden" name="id" value="' . (int) $subscription->get_id() . '" />';
			if ( $retryable ) {
				/* translators: %d: renewal order id. */
				echo '<button class="button" name="do" value="retry">' . esc_html( sprintf( __( 'Retry current payment (order #%d)', 'p2flux-for-woocommerce' ), $retryable ) ) . '</button> ';
			}
			if ( $can_cancel ) {
				echo '<button class="button" name="do" value="cancel" onclick="return confirm(\'' . esc_js( __( 'Cancel this subscription? No further payments will be collected.', 'p2flux-for-woocommerce' ) ) . '\')">' . esc_html__( 'Cancel subscription', 'p2flux-for-woocommerce' ) . '</button>';
			}
			echo '</form>';
			echo '<p class="description">' . esc_html__( 'A retry obeys the same rules as a scheduled collection: only the current renewal, only inside its billing period. Missed periods are never collected later.', 'p2flux-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * The order a merchant may retry now, if any: the current renewal of an active or on-hold
	 * subscription, or the parent of a pending signup still inside its window.
	 *
	 * @param P2Flux_WC_Native_Subscription $subscription Subscription.
	 * @return int Order id or 0.
	 */
	private static function retryable_order( $subscription ) {
		if ( $subscription->has_status( P2Flux_WC_Native_Subscription::PENDING ) ) {
			return $subscription->timestamp( 'activation_deadline' ) > time() ? $subscription->get_parent_id() : 0;
		}
		if ( ! $subscription->has_status( array( P2Flux_WC_Native_Subscription::ACTIVE, P2Flux_WC_Native_Subscription::ON_HOLD ) ) ) {
			return 0;
		}
		$current = (int) $subscription->get( 'current_renewal_order_id' );
		$order   = $current ? wc_get_order( $current ) : null;

		return $order && ! $order->is_paid() ? $current : 0;
	}

	/**
	 * Handle the form.
	 *
	 * @return void
	 */
	public static function action() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $id || ! check_admin_referer( 'p2flux_native_admin_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'p2flux-for-woocommerce' ) );
		}
		$subscription = P2Flux_WC_Native_Subscription::load( $id );
		$do           = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$notice       = '';

		if ( $subscription && 'cancel' === $do ) {
			$notice = P2Flux_WC_Native_Account::cancel_subscription( $subscription, __( 'Cancelled by the store from the admin.', 'p2flux-for-woocommerce' ) )
				? __( 'Subscription cancelled. No further payments will be collected.', 'p2flux-for-woocommerce' )
				: __( 'This subscription cannot be cancelled.', 'p2flux-for-woocommerce' );
		} elseif ( $subscription && 'retry' === $do ) {
			$order_id = self::retryable_order( $subscription );
			if ( $order_id ) {
				$outcome = P2Flux_WC_Charger::collect( P2Flux_WC_Subscriptions::ref( $subscription ), $order_id );
				/* translators: %1$s: outcome status, %2$s: protocol code. */
				$notice  = sprintf( __( 'Retry: %1$s (%2$s).', 'p2flux-for-woocommerce' ), $outcome['status'], $outcome['code'] );
			} else {
				$notice = __( 'Nothing can be retried right now.', 'p2flux-for-woocommerce' );
			}
		}

		wp_safe_redirect( add_query_arg( 'p2flux_notice', rawurlencode( $notice ), self::url( $id ) ) );
		exit;
	}
}
