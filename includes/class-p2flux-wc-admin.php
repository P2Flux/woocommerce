<?php
/**
 * What a shop manager sees and can do about a P2Flux payment.
 *
 * The refund box exists because WooCommerce's own refund button cannot work here: a P2Flux refund is
 * a transfer from the merchant's own wallet, which no server-side call can make. The gateway
 * therefore does not declare refund support, and this box says plainly what to do instead - rather
 * than leaving a manager clicking a button that fails.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order screen and admin notices.
 */
class P2Flux_WC_Admin {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Add the box to the order screen, on either storage backend.
	 *
	 * @return void
	 */
	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box(
			'p2flux_wc_order',
			__( 'P2Flux', 'p2flux-for-woocommerce' ),
			array( __CLASS__, 'render' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * The box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Whichever the screen hands over.
	 * @return void
	 */
	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || 'p2flux' !== $order->get_payment_method() ) {
			echo '<div class="p2flux-box"><p class="p2flux-box__note">' . esc_html__( 'This order was not paid with P2Flux.', 'p2flux-for-woocommerce' ) . '</p></div>';
			return;
		}

		$environment = (string) $order->get_meta( '_p2flux_env' );
		$hash        = (string) $order->get_meta( '_p2flux_tx_hash' );
		$units       = (int) $order->get_meta( '_p2flux_paid_units' );
		$explorer    = P2Flux_WC_Client::explorer_url( $environment );
		$refund      = P2Flux_WC_Refunds::state( $order );
		$live        = P2Flux_WC_Client::LIVE === $environment;
		$period      = $order->get_meta( '_p2flux_period_index' );

		echo '<div class="p2flux-box">';
		echo '<dl class="p2flux-box__rows">';

		echo '<dt>' . esc_html__( 'Network', 'p2flux-for-woocommerce' ) . '</dt><dd>';
		printf(
			'<span class="p2flux-box__pill %s">%s</span>',
			$live ? 'p2flux-box__pill--live' : 'p2flux-box__pill--test',
			esc_html( $live ? __( 'Base Mainnet', 'p2flux-for-woocommerce' ) : __( 'Base Sepolia · test', 'p2flux-for-woocommerce' ) )
		);
		echo '</dd>';

		if ( $units > 0 ) {
			echo '<dt>' . esc_html__( 'Paid', 'p2flux-for-woocommerce' ) . '</dt><dd>' . esc_html( P2Flux_WC_Money::display( $units ) ) . ' USDC</dd>';
		}
		if ( '' !== (string) $period ) {
			echo '<dt>' . esc_html__( 'Billing period', 'p2flux-for-woocommerce' ) . '</dt><dd>' . esc_html( (string) (int) $period ) . '</dd>';
		}
		if ( P2Flux_WC_Refunds::REFUNDED === $refund['status'] ) {
			echo '<dt>' . esc_html__( 'Refund', 'p2flux-for-woocommerce' ) . '</dt><dd><span class="p2flux-box__pill p2flux-box__pill--refunded">' . esc_html__( 'Refunded in USDC', 'p2flux-for-woocommerce' ) . '</span></dd>';
		}

		echo '</dl>';

		if ( '' !== $hash ) {
			printf(
				'<a class="p2flux-box__link" href="%s" target="_blank" rel="noopener noreferrer">%s ↗</a>',
				esc_url( $explorer . '/tx/' . $hash ),
				esc_html__( 'View the transaction', 'p2flux-for-woocommerce' )
			);
		}

		if ( $order->get_meta( '_p2flux_reconciling' ) ) {
			// The period was collected; the settlement behind it is not known yet. Until it is, the
			// order cannot be refunded - a refund starts from the original transaction.
			echo '<div class="p2flux-box__section">';
			echo '<p class="p2flux-box__note">' . esc_html__( 'This billing period was collected, but its transaction has not been recovered yet. The order is marked paid once it has been.', 'p2flux-for-woocommerce' ) . '</p>';
			printf(
				'<p class="p2flux-box__actions"><button type="button" class="button" id="p2flux-recover" data-order="%d">%s</button></p>',
				(int) $order->get_id(),
				esc_html__( 'Recover transaction', 'p2flux-for-woocommerce' )
			);
			echo '</div>';
		}

		if ( $order->get_meta( '_p2flux_unexpected_payment' ) ) {
			echo '<p class="p2flux-box__warning">' . esc_html__( 'A payment arrived that does not settle this order. Review the order notes before fulfilling.', 'p2flux-for-woocommerce' ) . '</p>';
		}
		if ( $order->get_meta( '_p2flux_period_conflict' ) ) {
			echo '<p class="p2flux-box__warning">' . esc_html__( 'P2Flux answered about a billing period that belongs to another order. Review both orders before fulfilling.', 'p2flux-for-woocommerce' ) . '</p>';
		}

		self::render_refund( $order, $refund, $hash );
		echo '</div>';
	}

	/**
	 * The refund half of the box.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $refund Refund state.
	 * @param string   $hash   Settlement transaction.
	 * @return void
	 */
	private static function render_refund( $order, array $refund, $hash ) {
		if ( P2Flux_WC_Refunds::REFUNDED === $refund['status'] ) {
			return;
		}

		echo '<div class="p2flux-box__section">';

		if ( '' === $hash ) {
			echo '<p class="p2flux-box__note">' . esc_html__( 'A refund needs the original transaction, which is not known for this order yet.', 'p2flux-for-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<p class="p2flux-box__note">' . esc_html__( 'A P2Flux refund is sent from your own wallet, in full. WooCommerce records it once the transfer is confirmed on chain.', 'p2flux-for-woocommerce' ) . '</p>';

		if ( in_array( $refund['status'], array( P2Flux_WC_Refunds::SENT, P2Flux_WC_Refunds::MISMATCH ), true ) ) {
			// A transfer exists. From here the only safe action is asking about it again - never
			// offering to send another.
			printf(
				'<p class="p2flux-box__actions"><button type="button" class="button" id="p2flux-refund-recheck" data-order="%d">%s</button></p>',
				(int) $order->get_id(),
				esc_html__( 'Re-check refund', 'p2flux-for-woocommerce' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<p class="p2flux-box__actions"><button type="button" class="button button-primary" id="p2flux-refund" data-order="%d">%s</button></p>',
			(int) $order->get_id(),
			esc_html__( 'Refund in USDC', 'p2flux-for-woocommerce' )
		);
		echo '<p class="p2flux-box__status" id="p2flux-refund-status" role="status" aria-live="polite"></p>';
		echo '</div>';
	}

	/**
	 * Scripts for the order screen.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_order = $screen && ( 'shop_order' === $screen->id || ( function_exists( 'wc_get_page_screen_id' ) && wc_get_page_screen_id( 'shop-order' ) === $screen->id ) );

		if ( ! $is_order ) {
			return;
		}
		unset( $hook );

		wp_enqueue_style( 'p2flux-wc-admin', plugins_url( 'assets/admin.css', P2FLUX_WC_FILE ), array(), P2FLUX_WC_VERSION );
		wp_enqueue_script( 'p2flux-wc-admin', plugins_url( 'assets/admin.js', P2FLUX_WC_FILE ), array(), P2FLUX_WC_VERSION, true );
		wp_add_inline_script(
			'p2flux-wc-admin',
			'window.p2fluxWcAdmin = ' . wp_json_encode(
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'p2flux_wc_admin' ),
					'i18n'  => array(
						'blocked'    => __( 'Your browser blocked the wallet window. Allow pop-ups for this site and try again.', 'p2flux-for-woocommerce' ),
						'sending'    => __( 'Waiting for your wallet…', 'p2flux-for-woocommerce' ),
						'confirming' => __( 'The refund is confirming on chain…', 'p2flux-for-woocommerce' ),
						'refunded'   => __( 'Refunded and recorded.', 'p2flux-for-woocommerce' ),
						'recovering' => __( 'Looking for the settlement…', 'p2flux-for-woocommerce' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Configuration problems worth interrupting for.
	 *
	 * @return void
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = get_option( 'woocommerce_p2flux_settings', array() );
		if ( empty( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
			return;
		}

		if ( ! P2Flux_WC_Money::supported_platform() ) {
			self::notice( __( 'P2Flux needs 64-bit PHP to calculate amounts safely, and this server is running a 32-bit build. The payment method is disabled.', 'p2flux-for-woocommerce' ) );
			return;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			self::notice( __( 'P2Flux cannot encrypt stored subscription authorizations on this server, so the payment method is disabled.', 'p2flux-for-woocommerce' ) );
			return;
		}

		if ( ! P2Flux_WC_Gateway::valid_recipient( isset( $settings['recipient'] ) ? $settings['recipient'] : '' ) ) {
			self::notice( __( 'P2Flux is enabled but has no valid payout wallet, so it is not being offered at checkout.', 'p2flux-for-woocommerce' ) );
		}

		if ( P2Flux_WC_Client::TEST === P2Flux_WC_Client::current_environment() ) {
			self::notice( __( 'P2Flux is in test mode: payments settle on Base Sepolia and move no real money.', 'p2flux-for-woocommerce' ), 'info' );
		}
	}

	/**
	 * Print one notice.
	 *
	 * @param string $message Text.
	 * @param string $level   'error' | 'info'.
	 * @return void
	 */
	private static function notice( $message, $level = 'error' ) {
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			'info' === $level ? 'info' : 'error',
			esc_html( $message )
		);
	}
}
