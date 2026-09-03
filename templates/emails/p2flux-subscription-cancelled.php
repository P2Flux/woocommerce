<?php
/**
 * Subscription cancelled email (HTML).
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
	<?php
	printf(
		/* translators: %s: product name. */
		esc_html__( 'Your subscription to %s has been cancelled. No future P2Flux charges will be initiated by this store.', 'p2flux-for-woocommerce' ),
		esc_html( (string) $subscription->get( 'product_name' ) )
	);
	?>
</p>
<?php if ( $auth_remains ) : ?>
<p><?php esc_html_e( 'The authorization in your wallet is still in place. You can revoke it from My Account at any time; this store will not use it again.', 'p2flux-for-woocommerce' ); ?></p>
<?php endif; ?>
<?php if ( '' !== $account_url ) : ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'My Account', 'p2flux-for-woocommerce' ); ?></a></p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
