<?php
/**
 * Action required email (HTML).
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.
?>
<p><?php echo esc_html( $reason_text ); ?></p>
<p>
	<?php
	printf(
		/* translators: 1: product name, 2: amount, 3: interval. */
		esc_html__( 'Subscription: %1$s — %2$s USDC per %3$s.', 'p2flux-for-woocommerce' ),
		esc_html( (string) $subscription->get( 'product_name' ) ),
		esc_html( P2Flux_WC_Money::display( (int) $subscription->get( 'amount_units' ) ) ),
		esc_html( $subscription->interval_label() )
	);
	?>
</p>
<?php if ( '' !== $account_url ) : ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Manage your subscription in My Account', 'p2flux-for-woocommerce' ); ?></a></p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.
